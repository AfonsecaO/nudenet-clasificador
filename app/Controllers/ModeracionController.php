<?php

namespace App\Controllers;

use App\Models\EstadoTracker;
use App\Models\ImageModerationLabels;
use App\Services\AppConnection;
use App\Services\AwsRekognitionConfig;
use App\Services\ConfigService;
use App\Services\LogService;
use App\Services\ModeracionProcessorService;
use App\Services\WorkspaceService;

class ModeracionController extends BaseController
{
    /**
     * Procesa un lote de imágenes pendientes de moderación (moderation_analyzed_at IS NULL).
     * Query: workspace (opcional) = slug para procesar ese workspace.
     */
    public function procesar()
    {
        error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
        ini_set('display_errors', 0);
        ob_start();

        try {
            if (isset($_GET['workspace']) && trim((string) $_GET['workspace']) !== '') {
                $reqWs = WorkspaceService::slugify(trim((string) $_GET['workspace']));
                if ($reqWs !== '' && WorkspaceService::isValidSlug($reqWs) && WorkspaceService::exists($reqWs)) {
                    WorkspaceService::setCurrent($reqWs);
                }
            }

            if (!AwsRekognitionConfig::isConfigured()) {
                ob_clean();
                $this->jsonResponse([
                    'success' => false,
                    'error' => 'AWS Rekognition no está configurado. Configura Access Key en Parametrización global.',
                    'log_items' => LogService::tail(50),
                ], 400);
            }

            $processor = new ModeracionProcessorService();
            $processor->setBatchSize(\App\Services\StorageEngineConfig::getRegistrosModeracion());
            $result = $processor->processBatch();

            foreach ($result['errores'] as $err) {
                LogService::append(['type' => 'warning', 'message' => 'Moderación: ' . ($err['relative_path'] ?? '') . ' - ' . ($err['error'] ?? '')]);
            }
            if ($result['procesadas'] > 0) {
                LogService::append(['type' => 'info', 'message' => 'Moderación: ' . $result['procesadas'] . ' imagen(es) procesada(s).']);
            }

            $registrosPendientesDescarga = null;
            $imagenesTotal = null;
            try {
                $wsSlug = WorkspaceService::current();
                if ($wsSlug !== null && $wsSlug !== '') {
                    $pdo = AppConnection::get();
                    $tImages = AppConnection::table('images');
                    $st = $pdo->prepare("SELECT COUNT(*) FROM {$tImages} WHERE workspace_slug = ?");
                    $st->execute([$wsSlug]);
                    $imagenesTotal = (int) $st->fetchColumn();
                    if (ConfigService::getWorkspaceMode() === 'db_and_images') {
                        $estadoTracker = new EstadoTracker();
                        $estado = $estadoTracker->getEstado();
                        $totalRegistros = 0;
                        $registrosProcesados = 0;
                        foreach ($estado as $info) {
                            $totalRegistros += (int)($info['max_id'] ?? 0);
                            $registrosProcesados += (int)($info['ultimo_id'] ?? 0);
                        }
                        $registrosPendientesDescarga = max(0, $totalRegistros - $registrosProcesados);
                    }
                }
            } catch (\Throwable $e) {
                // no romper la respuesta
            }

            ob_clean();
            $this->jsonResponse([
                'success' => true,
                'procesadas' => $result['procesadas'],
                'errores' => $result['errores'],
                'faltan_mas' => $result['faltan_mas'],
                'pendientes' => $result['pendientes'],
                'labels_insertados' => $result['labels_insertados'],
                'log_items' => LogService::tail(50),
                'indicadores' => [
                    'registros_pendientes_descarga' => $registrosPendientesDescarga,
                    'imagenes_total' => $imagenesTotal,
                    'imagenes_pendientes_moderacion' => $result['pendientes'],
                ],
            ]);
        } catch (\Throwable $e) {
            ob_clean();
            LogService::append(['type' => 'error', 'message' => 'Moderación: ' . $e->getMessage()]);
            $this->jsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'log_items' => LogService::tail(50),
            ], 500);
        }
    }

    /**
     * Estadísticas de moderación: analizadas y pendientes por workspace (o actual).
     */
    public function estadisticasModeracion()
    {
        try {
            $workspace = isset($_GET['workspace']) ? WorkspaceService::slugify(trim((string) $_GET['workspace'])) : null;
            $wsSlug = $workspace !== '' ? $workspace : (WorkspaceService::current() ?? '');

            if ($wsSlug === '') {
                $this->jsonResponse(['success' => true, 'analizadas' => 0, 'pendientes' => 0, 'workspace' => null]);
                return;
            }

            $pdo = AppConnection::get();
            \App\Services\AppSchema::ensure($pdo);
            $tImages = AppConnection::table('images');
            $isMysql = AppConnection::getCurrentDriver() === 'mysql';
            $condAnalizadas = $isMysql ? 'moderation_analyzed_at IS NOT NULL' : '(moderation_analyzed_at IS NOT NULL AND moderation_analyzed_at != \'\')';
            $condPendientes = $isMysql ? 'moderation_analyzed_at IS NULL' : '(moderation_analyzed_at IS NULL OR moderation_analyzed_at = \'\')';

            $stmtA = $pdo->prepare("SELECT COUNT(*) FROM {$tImages} WHERE workspace_slug = ? AND {$condAnalizadas}");
            $stmtA->execute([$wsSlug]);
            $analizadas = (int) $stmtA->fetchColumn();

            $stmtP = $pdo->prepare("SELECT COUNT(*) FROM {$tImages} WHERE workspace_slug = ? AND {$condPendientes}");
            $stmtP->execute([$wsSlug]);
            $pendientes = (int) $stmtP->fetchColumn();

            $this->jsonResponse([
                'success' => true,
                'analizadas' => $analizadas,
                'pendientes' => $pendientes,
                'workspace' => $wsSlug,
            ]);
        } catch (\Throwable $e) {
            $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Lista etiquetas de moderación: de un workspace o de todos (buscador global).
     * Si global=1 o workspace=all → etiquetas de todos los workspaces; si no, del workspace actual o el pasado por workspace=.
     */
    public function etiquetasDisponibles()
    {
        try {
            $global = isset($_GET['global']) && (string) $_GET['global'] === '1'
                || isset($_GET['workspace']) && strtolower(trim((string) $_GET['workspace'])) === 'all';

            if ($global) {
                $model = new ImageModerationLabels();
                $etiquetas = $model->getDistinctLabelsGlobal();
                $this->jsonResponse(['success' => true, 'etiquetas' => $etiquetas, 'global' => true]);
                return;
            }

            if (isset($_GET['workspace']) && trim((string) $_GET['workspace']) !== '') {
                $reqWs = WorkspaceService::slugify(trim((string) $_GET['workspace']));
                if ($reqWs !== '' && WorkspaceService::isValidSlug($reqWs) && WorkspaceService::exists($reqWs)) {
                    WorkspaceService::setCurrent($reqWs);
                }
            }
            $ws = WorkspaceService::current();
            if ($ws === null || $ws === '') {
                $this->jsonResponse(['success' => false, 'error' => 'No hay workspace activo'], 409);
                return;
            }

            $model = new ImageModerationLabels();
            $etiquetas = $model->getDistinctLabelsForWorkspace($ws);
            $this->jsonResponse(['success' => true, 'etiquetas' => $etiquetas]);
        } catch (\Throwable $e) {
            $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Busca imágenes por nivel y/o etiquetas. En un workspace o en todos (buscador global).
     * Si global=1 o workspace=all → busca en todos los workspaces (cada imagen incluye workspace_slug).
     */
    public function buscarPorModeracion()
    {
        try {
            $global = isset($_GET['global']) && (string) $_GET['global'] === '1'
                || isset($_GET['workspace']) && strtolower(trim((string) $_GET['workspace'])) === 'all';

            $nivel = isset($_GET['nivel']) ? (string) $_GET['nivel'] : '';
            $tagsParam = isset($_GET['tags']) ? (string) $_GET['tags'] : '';
            $tags = [];
            if ($tagsParam !== '') {
                $tags = array_map('trim', explode(',', $tagsParam));
                $tags = array_filter($tags, function ($t) { return $t !== ''; });
            }
            $taxonomyLevel = null;
            if ($nivel !== '' && preg_match('/^[0-3]$/', $nivel)) {
                $taxonomyLevel = (int) $nivel;
            }

            if (empty($tags) && $taxonomyLevel === null) {
                $this->jsonResponse(['success' => true, 'imagenes' => [], 'total' => 0, 'page' => 1, 'per_page' => 60, 'total_pages' => 0]);
                return;
            }

            $perPage = isset($_GET['per_page']) ? max(1, min(500, (int) $_GET['per_page'])) : 60;
            $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
            $offset = ($page - 1) * $perPage;

            $model = new ImageModerationLabels();

            if ($global) {
                $total = $model->getCountByLabelsGlobal($taxonomyLevel, $tags);
                $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 0;
                $imagenes = $model->findImagesByLabelsGlobal($taxonomyLevel, $tags, $perPage, $offset);
                $this->jsonResponse([
                    'success' => true,
                    'imagenes' => $imagenes,
                    'total' => $total,
                    'page' => $page,
                    'per_page' => $perPage,
                    'total_pages' => $totalPages,
                    'global' => true,
                ]);
                return;
            }

            if (isset($_GET['workspace']) && trim((string) $_GET['workspace']) !== '') {
                $reqWs = WorkspaceService::slugify(trim((string) $_GET['workspace']));
                if ($reqWs !== '' && WorkspaceService::isValidSlug($reqWs) && WorkspaceService::exists($reqWs)) {
                    WorkspaceService::setCurrent($reqWs);
                }
            }
            $ws = WorkspaceService::current();
            if ($ws === null || $ws === '') {
                $this->jsonResponse(['success' => false, 'error' => 'No hay workspace activo'], 409);
                return;
            }

            $total = $model->getCountByLabels($ws, $taxonomyLevel, $tags);
            $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 0;
            $imagenes = $model->findImagesByLabels($ws, $taxonomyLevel, $tags, $perPage, $offset);
            $this->jsonResponse([
                'success' => true,
                'imagenes' => $imagenes,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => $totalPages,
            ]);
        } catch (\Throwable $e) {
            $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
