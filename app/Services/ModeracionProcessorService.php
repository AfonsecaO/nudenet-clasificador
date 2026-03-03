<?php

namespace App\Services;

use App\Models\ImageModerationLabels;
use App\Models\ImagenesIndex;
use PDO;

/**
 * Orquestador: obtiene imágenes pendientes de moderación, llama a Rekognition y persiste resultados.
 * Si el servicio falla para una imagen, no se marca como analizada (queda para reproceso).
 * Si no hay labels, se inserta etiqueta "safe" nivel 0.
 */
class ModeracionProcessorService
{
    private const LABEL_SAFE = 'safe';
    private const DEFAULT_BATCH_SIZE = 20;

    /** @var int */
    private $batchSize;
    /** @var string */
    private $workspaceSlug;
    /** @var RekognitionModerationService */
    private $rekognition;
    /** @var ImageModerationLabels */
    private $labelsModel;

    public function __construct(?string $workspaceSlug = null)
    {
        $this->workspaceSlug = $workspaceSlug ?? AppConnection::currentSlug() ?? 'default';
        $this->batchSize = self::DEFAULT_BATCH_SIZE;
        $this->rekognition = new RekognitionModerationService();
        $this->labelsModel = new ImageModerationLabels();
    }

    public function setBatchSize(int $n): self
    {
        $this->batchSize = max(1, min(100, $n));
        return $this;
    }

    /**
     * Procesa un lote de imágenes pendientes (moderation_analyzed_at IS NULL).
     * @return array{procesadas:int, errores:array, faltan_mas:bool, labels_insertados:int}
     */
    public function processBatch(): array
    {
        $pdo = AppConnection::get();
        AppSchema::ensure($pdo);
        $tImages = AppConnection::table('images');
        $condPendientes = AppConnection::getCurrentDriver() === 'mysql'
            ? 'moderation_analyzed_at IS NULL'
            : '(moderation_analyzed_at IS NULL OR moderation_analyzed_at = \'\')';

        $stmt = $pdo->prepare("
            SELECT relative_path, full_path
            FROM {$tImages}
            WHERE workspace_slug = ? AND {$condPendientes}
            ORDER BY relative_path
            LIMIT " . (int) $this->batchSize
        );
        $stmt->execute([$this->workspaceSlug]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $procesadas = 0;
        $errores = [];
        $labelsInsertados = 0;
        $minConfidence = AwsRekognitionConfig::getMinConfidence();

        foreach ($rows as $r) {
            $relativePath = (string) ($r['relative_path'] ?? '');
            $fullPath = isset($r['full_path']) ? (string) $r['full_path'] : '';
            if ($relativePath === '' || $fullPath === '' || !is_file($fullPath)) {
                $errores[] = ['relative_path' => $relativePath, 'error' => 'Archivo no encontrado'];
                continue;
            }

            try {
                $result = $this->rekognition->detectModerationLabels($fullPath, $minConfidence);
            } catch (\Throwable $e) {
                $errores[] = ['relative_path' => $relativePath, 'error' => $e->getMessage()];
                continue;
            }

            $rawLabels = $result['ModerationLabels'] ?? [];
            $modelVersion = $result['ModerationModelVersion'] ?? '7.0';
            $labels = $this->mapLabels($rawLabels);
            if (!empty($labels)) {
                $labels = $this->pickTopLabelsOnly($labels);
            }
            if (empty($labels)) {
                $labels = [['taxonomy_level' => 0, 'label_name' => self::LABEL_SAFE, 'parent_name' => null, 'confidence' => 100.0]];
            }

            $this->labelsModel->upsertForImage($this->workspaceSlug, $relativePath, $labels, $modelVersion);
            $procesadas++;
            $labelsInsertados += count($labels);
        }

        $stmtTotal = $pdo->prepare("
            SELECT COUNT(*) FROM {$tImages}
            WHERE workspace_slug = ? AND {$condPendientes}
        ");
        $stmtTotal->execute([$this->workspaceSlug]);
        $pendientes = (int) $stmtTotal->fetchColumn();
        $faltanMas = $pendientes > 0;

        return [
            'procesadas' => $procesadas,
            'errores' => $errores,
            'faltan_mas' => $faltanMas,
            'labels_insertados' => $labelsInsertados,
            'pendientes' => $pendientes,
        ];
    }

    /**
     * Deja solo el tag de mayor nivel (3 > 2 > 1 > 0) y, dentro de ese nivel, el/los de mayor confianza.
     * Si hay varios con el mismo nivel y misma confianza máxima, se conservan todos.
     * @param array $labels Array de filas con taxonomy_level, label_name, parent_name, confidence
     * @return array
     */
    private function pickTopLabelsOnly(array $labels): array
    {
        if (empty($labels)) {
            return [];
        }
        $maxLevel = max(array_map(function ($l) {
            return (int) ($l['taxonomy_level'] ?? 0);
        }, $labels));
        $atMaxLevel = array_values(array_filter($labels, function ($l) use ($maxLevel) {
            return (int) ($l['taxonomy_level'] ?? 0) === $maxLevel;
        }));
        if (empty($atMaxLevel)) {
            return $labels;
        }
        $maxConfidence = max(array_map(function ($l) {
            return isset($l['confidence']) ? (float) $l['confidence'] : -1.0;
        }, $atMaxLevel));
        $top = array_values(array_filter($atMaxLevel, function ($l) use ($maxConfidence) {
            $c = isset($l['confidence']) ? (float) $l['confidence'] : -1.0;
            return $c === $maxConfidence;
        }));
        return $top;
    }

    /**
     * Convierte la respuesta de Rekognition a array de filas para image_moderation_labels.
     * @param array $rawLabels Cada elemento: Name, ParentName?, Confidence?, TaxonomyLevel?
     * @return array<array{taxonomy_level:int, label_name:string, parent_name:string|null, confidence:float|null}>
     */
    private function mapLabels(array $rawLabels): array
    {
        $out = [];
        foreach ($rawLabels as $label) {
            $name = isset($label['Name']) ? (string) $label['Name'] : '';
            if ($name === '') {
                continue;
            }
            $level = isset($label['TaxonomyLevel']) ? (int) $label['TaxonomyLevel'] : 1;
            $out[] = [
                'taxonomy_level' => $level,
                'label_name' => $name,
                'parent_name' => isset($label['ParentName']) ? (string) $label['ParentName'] : null,
                'confidence' => isset($label['Confidence']) ? (float) $label['Confidence'] : null,
            ];
        }
        return $out;
    }
}
