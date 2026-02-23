<?php
/** @var array $workspaces */
/** @var string|null $current */

$__title = 'Seleccionar workspace';
$__bodyClass = 'page-workspaces';

$workspaces = is_array($workspaces ?? null) ? $workspaces : [];
$current = isset($current) && is_string($current) ? $current : null;

function fmtTs($ts): string {
  if (!$ts) return '—';
  $n = is_numeric($ts) ? (int)$ts : 0;
  if ($n <= 0) return '—';
  return date('Y-m-d H:i', $n);
}
?>

<nav class="topnav">
  <a href="?action=workspace_select" class="topnav-brand"><i class="fas fa-shield-alt"></i> PhotoClassifier</a>
  <ul class="topnav-links">
    <li><a href="?action=workspace_select" class="active"><i class="fas fa-layer-group"></i> Workspaces</a></li>
    <li><a href="?action=workspace_global_config"><i class="fas fa-database"></i> Parametrización global</a></li>
    <li>
      <button class="btn btn-primary btn-sm" type="button" data-toggle="modal" data-target="#createModal">
        <i class="fas fa-plus"></i> Crear workspace
      </button>
    </li>
  </ul>
</nav>

<main class="main content page-workspaces">
  <div class="container ws-container">
    <?php if (!empty($workspaces)): ?>
    <!-- Barra global oculta: el estado de procesamiento se muestra en cada botón de la card -->
    <div id="workspaceProcessingPanel" class="ws-processing" style="display: none;" aria-hidden="true"></div>
    <?php endif; ?>

    <?php if (empty($workspaces)): ?>
      <div class="ws-empty">
        <div class="ws-empty-icon" aria-hidden="true"><i class="fas fa-folder-open"></i></div>
        <h2 class="ws-empty-title">Sin workspaces</h2>
        <p class="ws-empty-desc">Crea el primero para empezar a clasificar imágenes.</p>
        <button class="btn btn-primary" type="button" data-toggle="modal" data-target="#createModal">
          <i class="fas fa-plus"></i> Crear workspace
        </button>
      </div>
    <?php else: ?>
    <div class="row ws-layout ws-layout--buscador-collapsed">
      <div class="col-lg-8 col-md-12 ws-grid-wrap">
    <div class="ws-grid">
      <?php foreach ($workspaces as $ws): ?>
        <?php
          $slug = (string)($ws['slug'] ?? '');
          $isCurrent = !empty($ws['is_current']);
          $configured = !empty($ws['configured']);
          $mode = (string)($ws['mode'] ?? '');
          $imgTotal = isset($ws['images_total']) ? (int)$ws['images_total'] : null;
          $imgPend = isset($ws['images_pending']) ? (int)$ws['images_pending'] : null;
          $detTotal = isset($ws['detections_total']) ? (int)$ws['detections_total'] : null;
          $registrosPendDescarga = isset($ws['registros_pendientes_descarga']) ? (int)$ws['registros_pendientes_descarga'] : null;
          $createdAt = fmtTs($ws['created_at'] ?? null);
          $updatedAt = (string)($ws['updated_at'] ?? '');
          $modeLabel = ($mode === 'db_and_images') ? 'DB + imágenes' : (($mode === 'images_only') ? 'Solo imágenes' : 'Sin configurar');
        ?>
        <article class="ws-card<?php echo $isCurrent ? ' ws-card--current' : ''; ?> <?php echo $configured ? 'ws-card--ok' : 'ws-card--pending'; ?>" data-ws="<?php echo htmlspecialchars($slug, ENT_QUOTES); ?>">
          <div class="ws-card-inner">
            <div class="ws-card-top">
              <div class="ws-card-name-wrap">
                <span class="ws-card-status" title="<?php echo $configured ? 'Configurado' : 'Pendiente'; ?>">
                  <i class="fas <?php echo $configured ? 'fa-check-circle' : 'fa-clock'; ?>"></i>
                </span>
                <h2 class="ws-card-name"><?php echo htmlspecialchars($slug, ENT_QUOTES); ?></h2>
                <?php if ($isCurrent): ?>
                  <span class="ws-card-badge-current">Actual</span>
                <?php endif; ?>
              </div>
              <div class="ws-card-top-right">
                <span class="ws-card-created">Creado <?php echo htmlspecialchars($createdAt, ENT_QUOTES); ?></span>
                <button class="ws-card-delete btn btn-link btn-sm btnDeleteWs" type="button" data-ws="<?php echo htmlspecialchars($slug, ENT_QUOTES); ?>" title="Eliminar workspace" aria-label="Eliminar <?php echo htmlspecialchars($slug, ENT_QUOTES); ?>">
                  <i class="fas fa-trash-alt"></i>
                </button>
              </div>
            </div>
            <div class="ws-card-badges">
              <span class="ws-badge ws-badge--<?php echo $configured ? 'ok' : 'warn'; ?>"><?php echo $configured ? 'Configurado' : 'Pendiente'; ?></span>
              <span class="ws-badge ws-badge--mode"><?php echo htmlspecialchars($modeLabel, ENT_QUOTES); ?></span>
            </div>
            <?php if ($imgTotal !== null || $imgPend !== null || $detTotal !== null || $registrosPendDescarga !== null): ?>
            <div class="ws-card-stats" data-ws="<?php echo htmlspecialchars($slug, ENT_QUOTES); ?>">
              <div class="ws-stat"><span class="ws-stat-num" data-stat="images"><?php echo $imgTotal !== null ? number_format($imgTotal) : '—'; ?></span><span class="ws-stat-lbl"><i class="fas fa-image"></i> Imágenes</span></div>
              <div class="ws-stat"><span class="ws-stat-num" data-stat="pending"><?php echo $imgPend !== null ? number_format($imgPend) : '—'; ?></span><span class="ws-stat-lbl"><i class="fas fa-hourglass-half"></i> Pendientes</span></div>
              <div class="ws-stat ws-stat-errores-hint" style="display:none"><span class="ws-stat-num" data-stat="errores">0</span><span class="ws-stat-lbl"><i class="fas fa-exclamation-circle"></i> Error</span></div>
              <div class="ws-stat"><span class="ws-stat-num" data-stat="detections"><?php echo $detTotal !== null ? number_format($detTotal) : '—'; ?></span><span class="ws-stat-lbl"><i class="fas fa-robot"></i> Detecciones</span></div>
              <?php if ($mode === 'db_and_images' && $registrosPendDescarga !== null): ?>
              <div class="ws-stat ws-stat-full"><span class="ws-stat-num" data-stat="registros-descarga"><?php echo number_format($registrosPendDescarga); ?></span><span class="ws-stat-lbl"><i class="fas fa-download"></i> Registros por descargar</span></div>
              <?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="ws-card-actions">
              <button class="btn btn-primary btn-sm btnEnterWs" type="button" data-ws="<?php echo htmlspecialchars($slug, ENT_QUOTES); ?>">
                <i class="fas fa-arrow-right"></i> Entrar
              </button>
              <button class="btn btn-outline-secondary btn-sm btnSetupWs" type="button" data-ws="<?php echo htmlspecialchars($slug, ENT_QUOTES); ?>" title="Parametrización">
                <i class="fas fa-cog"></i> Parametrización
              </button>
              <?php if ($configured): ?>
                <?php if ($mode === 'db_and_images'): ?>
                <button type="button" class="btn btn-outline-info btn-sm btn-worker btnWsDescargar" data-ws="<?php echo htmlspecialchars($slug, ENT_QUOTES); ?>" title="Descargar tablas aquí">
                  <i class="fas fa-download"></i> Descargar
                </button>
                <?php endif; ?>
                <button type="button" class="btn btn-outline-success btn-sm btn-worker btnWsClasificar" data-ws="<?php echo htmlspecialchars($slug, ENT_QUOTES); ?>" title="Clasificar aquí">
                  <i class="fas fa-robot"></i> Clasificar
                </button>
              <?php endif; ?>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
    <div class="ws-grid-spacer" aria-hidden="true"></div>
      </div>
      <div class="col-lg-4 col-md-12 ws-search-col ws-search-col--collapsed mb-3 mb-lg-0" id="wsSearchCol">
        <div class="ws-search-consolidado" id="wsSearchConsolidado">
          <div class="ws-search-consolidado-header">
            <button type="button" class="ws-search-toggle btn btn-sm btn-outline-secondary" id="wsSearchToggle" aria-expanded="false" aria-label="Expandir buscador" title="Expandir buscador">
              <i class="fas fa-chevron-right" aria-hidden="true"></i>
            </button>
            <h2 class="ws-search-consolidado-title"><i class="fas fa-search"></i> Buscar en todos los workspaces</h2>
          </div>
          <div class="ws-search-consolidado-body">
          <?php
          $buscador = [
            'suffix' => 'Global',
            'acordeonId' => 'wsSearchAcordeon',
            'acordeonClass' => 'buscador-acordeon ws-search-acordeon expanded-carpetas',
            'idLstResultadosEtiq' => 'lstEtiquetasGlobal',
            'idTagsEtiquetasEmpty' => 'tagsEtiquetasGlobalEmpty',
            'emptyTagsText' => 'Cargando etiquetas…',
          ];
          include __DIR__ . '/partials/buscador-acordeon.php';
          ?>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</main>

<!-- Crear workspace -->
<div class="modal fade" id="createModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-plus"></i> Crear workspace</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label for="wsCreateName">Nombre del workspace</label>
          <input type="text" class="form-control" id="wsCreateName" placeholder="ej: produccion, pruebas-1">
          <small class="form-text text-muted">Se convertirá a slug.</small>
        </div>
        <?php if (!empty($workspaces)): ?>
        <div class="form-group">
          <label for="wsCreateCopyConfig">Copiar configuración de</label>
          <select class="form-control" id="wsCreateCopyConfig">
            <option value="">No copiar (empezar sin parametrización)</option>
            <?php foreach ($workspaces as $w): $s = (string)($w['slug'] ?? ''); if ($s === '') continue; ?>
            <option value="<?php echo htmlspecialchars($s, ENT_QUOTES); ?>"><?php echo htmlspecialchars($s, ENT_QUOTES); ?></option>
            <?php endforeach; ?>
          </select>
          <small class="form-text text-muted">Opcional. Copia la parametrización (DB, clasificador, etc.) de otro workspace. Luego puedes editarla.</small>
        </div>
        <?php endif; ?>
        <div class="alert alert-light mb-0">
          Se creará: <span class="text-monospace">workspaces/&lt;workspace&gt;/db, images, logs, cache</span>
        </div>
        <div class="small mt-2" id="stCreateText"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-primary" id="btnCreate">Crear</button>
      </div>
    </div>
  </div>
</div>

<!-- Eliminar workspace -->
<div class="modal fade" id="deleteModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header bg-danger">
        <h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Eliminar workspace</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <div class="alert alert-danger">
          <strong>No hay marcha atrás.</strong> Esto eliminará por completo el workspace, incluyendo su base de datos, imágenes, logs y cache.
        </div>
        <div class="form-group">
          <label for="wsDeleteConfirm">Escribe el nombre del workspace para confirmar</label>
          <input type="text" class="form-control" id="wsDeleteConfirm" autocomplete="off">
          <small class="form-text text-muted">Debe coincidir exactamente con: <span class="text-monospace" id="wsDeleteTarget">—</span></small>
        </div>
        <div class="small" id="stDeleteText"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-danger" id="btnConfirmDelete" disabled>Eliminar definitivamente</button>
      </div>
    </div>
  </div>
</div>

<?php if (!empty($workspaces)): ?>
<!-- Modales para buscador consolidado (carpeta y visor) -->
<div class="modal fade" id="modalCarpeta" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-folder-open"></i> <span id="ttlCarpeta">Carpeta</span></h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body modal-carpeta-body">
        <div class="modal-carpeta-tags-wrap">
          <div class="d-flex flex-wrap" id="tagsCarpeta"></div>
        </div>
        <div class="row mt-3" id="gridThumbs"></div>
      </div>
    </div>
  </div>
</div>
<div class="modal fade" id="modalCarpetaStacked" tabindex="-1" role="dialog" aria-hidden="true" data-backdrop="true" data-keyboard="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-folder-open"></i> <span id="ttlCarpetaStacked">Carpeta</span></h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body modal-carpeta-body">
        <div class="row mt-2" id="gridThumbsStacked"></div>
      </div>
    </div>
  </div>
</div>
<div class="modal fade" id="modalVisor" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable modal-visor-dialog" role="document">
    <div class="modal-content modal-visor-content">
      <div class="modal-header modal-visor-header">
        <h5 class="modal-title modal-visor-title" title="" id="ttlImagenWrap">
          <i class="fas fa-image modal-visor-title-icon" aria-hidden="true"></i>
          <span id="ttlImagen" class="modal-visor-filename">Imagen</span>
        </h5>
        <button type="button" class="close modal-visor-close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body modal-visor-body">
        <div class="visor-toolbar" role="toolbar" aria-label="Acciones de la imagen">
          <div class="visor-toolbar-left">
            <div class="custom-control custom-switch">
              <input type="checkbox" class="custom-control-input" id="swBoxes" checked>
              <label class="custom-control-label" for="swBoxes">Bounding boxes</label>
            </div>
          </div>
          <span class="visor-toolbar-divider" aria-hidden="true"></span>
          <div class="visor-toolbar-buttons">
            <button type="button" class="btn btn-sm visor-btn visor-btn-folder" id="btnVisorAbrirCarpeta" title="Ir a la carpeta que contiene esta imagen" aria-label="Ir a carpeta">
              <i class="fas fa-folder-open" aria-hidden="true"></i>
              <span>Ir a carpeta</span>
            </button>
            <a class="btn btn-sm visor-btn visor-btn-original" id="lnkAbrirOriginal" href="#" target="_blank" rel="noopener" title="Abrir imagen original en nueva pestaña">
              <i class="fas fa-external-link-alt" aria-hidden="true"></i>
              <span>Abrir original</span>
            </a>
          </div>
          <span class="visor-toolbar-divider visor-toolbar-divider-badges" aria-hidden="true"></span>
          <div class="visor-badges-wrap" id="badgesDet" aria-label="Detecciones"></div>
        </div>
        <div class="visor-canvas-wrap" id="visorCanvasWrap">
          <canvas id="cnv"></canvas>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="js/buscador-modals.js"></script>
<script>
  const wsCreateName = document.getElementById('wsCreateName');
  const btnCreate = document.getElementById('btnCreate');
  const stCreateText = document.getElementById('stCreateText');

  const wsDeleteConfirm = document.getElementById('wsDeleteConfirm');
  const wsDeleteTarget = document.getElementById('wsDeleteTarget');
  const stDeleteText = document.getElementById('stDeleteText');
  const btnConfirmDelete = document.getElementById('btnConfirmDelete');

  let deleteTargetSlug = '';

  function setStatus(el, state, text) {
    if (!el) return;
    el.textContent = text || '';
    el.className = 'small';
    if (state === 'ok') el.classList.add('text-success');
    else if (state === 'bad') el.classList.add('text-danger');
    else el.classList.add('text-muted');
  }

  async function postJson(action, payload) {
    const resp = await fetch(`?action=${encodeURIComponent(action)}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'accept': 'application/json' },
      body: JSON.stringify(payload || {})
    });
    const data = await resp.json().catch(() => ({}));
    return { ok: resp.ok, data };
  }

  function apiUrl(action, params) {
    let url = `?action=${encodeURIComponent(action)}`;
    if (params && typeof params === 'object') {
      Object.keys(params).forEach((k) => {
        url += `&${encodeURIComponent(k)}=${encodeURIComponent(params[k])}`;
      });
    }
    return url;
  }

  async function getJson(url) {
    const resp = await fetch(url, { headers: { 'accept': 'application/json' } });
    const data = await resp.json().catch(() => ({}));
    return { ok: resp.ok, data };
  }

  // --- Procesamiento en la misma pantalla con Web Worker (ejecución paralela) ---
  const downloadQueue = new Set();
  const classifyQueue = new Set();
  const panelEl = document.getElementById('workspaceProcessingPanel');
  const listEl = null;
  const btnStopAll = null;

  let worker = null;
  try {
    const workerUrl = (typeof window !== 'undefined' && window.location?.pathname)
      ? window.location.pathname.replace(/\/[^/]*$/, '/') + 'js/workspace-processor.worker.js'
      : 'js/workspace-processor.worker.js';
    worker = new Worker(workerUrl);
    worker.postMessage({ type: 'init', baseUrl: window.location.origin + window.location.pathname });
  } catch (e) {
    console.warn('Web Worker no disponible:', e);
  }

  let mainThreadStopRequested = false;
  // Única condición: la siguiente petición solo se inicia cuando la anterior haya finalizado (éxito, error o complete).
  async function runDownloadLoopMain(ws) {
    while (downloadQueue.has(ws) && !mainThreadStopRequested) {
      const url = apiUrlWs('procesar', ws);
      const { ok, data } = await getJson(url);
      if (!ok || !data?.success || !downloadQueue.has(ws)) break;
      const msg = String(data?.mensaje || '');
      if (msg.includes('completada') || msg.includes('No se encontraron tablas')) {
        downloadQueue.delete(ws);
        updateProcessingPanel();
        break;
      }
    }
  }
  // Única condición: la siguiente petición solo se inicia cuando la anterior haya finalizado (éxito, error o complete).
  async function runClassifyLoopMain(ws) {
    while (classifyQueue.has(ws) && !mainThreadStopRequested) {
      const url = apiUrlWs('procesar_imagenes', ws);
      const { ok, data } = await getJson(url);
      if (!ok || !data?.success || !classifyQueue.has(ws)) break;
      // Si hubo error del clasificador, la imagen ya quedó marcada como error; continuar con la siguiente.
      if (data?.stopped_due_to_classifier_error) continue;
      const pending = data?.pendientes ?? data?.pending ?? 0;
      const total = data?.total ?? 0;
      const procesadas = data?.procesadas ?? 0;
      if (pending === 0 || (total > 0 && procesadas >= total)) {
        classifyQueue.delete(ws);
        updateProcessingPanel();
        break;
      }
    }
  }

  function apiUrlWs(action, workspace) {
    return apiUrl(action, { workspace });
  }

  function syncQueuesFromState(state) {
    if (!state) return;
    if (Array.isArray(state.download)) { downloadQueue.clear(); state.download.forEach((ws) => downloadQueue.add(ws)); }
    if (Array.isArray(state.classify)) { classifyQueue.clear(); state.classify.forEach((ws) => classifyQueue.add(ws)); }
  }

  const lastButtonMetrics = {}; // ws -> { descargar: string, clasificar: string }

  function setButtonProcessing(btn, type, metricsText) {
    if (!btn) return;
    btn.disabled = false;
    btn.classList.add('ws-btn-processing');
    const text = (metricsText && metricsText.trim()) ? metricsText.trim() : '—';
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1" aria-hidden="true"></i>' + text.replace(/</g, '&lt;');
    const stopWrap = btn.nextElementSibling;
    if (stopWrap && stopWrap.classList.contains('ws-btn-stop-wrap')) stopWrap.remove();
  }

  function setButtonIdle(btn, type) {
    if (!btn) return;
    btn.disabled = false;
    btn.classList.remove('ws-btn-processing');
    if (type === 'descargar') btn.innerHTML = '<i class="fas fa-download"></i> Descargar';
    else btn.innerHTML = '<i class="fas fa-robot"></i> Clasificar';
    const stopWrap = btn.nextElementSibling;
    if (stopWrap && stopWrap.classList.contains('ws-btn-stop-wrap')) stopWrap.remove();
  }

  function updateProcessingPanel() {
    document.querySelectorAll('.btnWsDescargar[data-ws]').forEach((btn) => {
      const ws = btn.getAttribute('data-ws');
      if (downloadQueue.has(ws)) {
        const metrics = (lastButtonMetrics[ws] && lastButtonMetrics[ws].descargar) || '';
        setButtonProcessing(btn, 'descargar', metrics);
      } else {
        setButtonIdle(btn, 'descargar');
      }
    });
    document.querySelectorAll('.btnWsClasificar[data-ws]').forEach((btn) => {
      const ws = btn.getAttribute('data-ws');
      if (classifyQueue.has(ws)) {
        const metrics = (lastButtonMetrics[ws] && lastButtonMetrics[ws].clasificar) || '';
        setButtonProcessing(btn, 'clasificar', metrics);
      } else {
        setButtonIdle(btn, 'clasificar');
      }
    });
  }

  async function fetchStatsDescarga(ws) {
    const { ok, data } = await getJson(apiUrlWs('estadisticas_descarga', ws));
    if (!ok || !data?.success || !data?.stats) return null;
    const s = data.stats;
    return {
      type: 'descargar',
      registros_procesados: Number(s.registros_procesados ?? 0),
      total_registros: Number(s.total_registros ?? 0),
      pendientes: Number(s.pendientes ?? 0),
      tablas_completadas: Number(s.tablas_completadas ?? 0),
      tablas_total: Number(s.tablas_total ?? 0)
    };
  }

  async function fetchStatsClasificacion(ws) {
    const { ok, data } = await getJson(apiUrlWs('estadisticas_clasificacion', ws));
    if (!ok || !data?.success || !data?.stats) return null;
    const s = data.stats;
    return {
      type: 'clasificar',
      total: Number(s.total ?? 0),
      procesadas: Number(s.procesadas ?? 0),
      pendientes: Number(s.pendientes ?? 0),
      pendientes_deteccion: Number(s.pendientes_deteccion ?? 0),
      detections_total: Number(s.detections_total ?? 0),
      safe: Number(s.safe ?? 0),
      unsafe: Number(s.unsafe ?? 0)
    };
  }

  function updateCardStats(ws, statsClasificacion) {
    if (!statsClasificacion) return;
    const container = document.querySelector(`.ws-card-stats[data-ws="${ws}"]`);
    if (!container) return;
    const imgEl = container.querySelector('.ws-stat-num[data-stat="images"]');
    const pendEl = container.querySelector('.ws-stat-num[data-stat="pending"]');
    const detEl = container.querySelector('.ws-stat-num[data-stat="detections"]');
    const erroresHintEl = container.querySelector('.ws-stat-errores-hint');
    const erroresNumEl = container.querySelector('.ws-stat-num[data-stat="errores"]');
    if (statsClasificacion.total !== undefined && imgEl) imgEl.textContent = Number(statsClasificacion.total).toLocaleString();
    if ((statsClasificacion.pendientes_deteccion !== undefined || statsClasificacion.pendientes !== undefined) && pendEl) pendEl.textContent = Number(statsClasificacion.pendientes_deteccion ?? statsClasificacion.pendientes ?? 0).toLocaleString();
    if (statsClasificacion.detections_total !== undefined && detEl) detEl.textContent = Number(statsClasificacion.detections_total).toLocaleString();
    if (erroresHintEl && erroresNumEl) {
      const errores = Number(statsClasificacion.errores ?? 0);
      erroresNumEl.textContent = errores.toLocaleString();
      erroresHintEl.style.display = errores > 0 ? '' : 'none';
    }
  }

  function formatMetrics(type, stats, extra) {
    if (type === 'descargar') {
      const n = extra?.imagenes_totales ?? 0;
      return `Imágenes: ${Number(n).toLocaleString()}`;
    }
    if (type === 'clasificar') {
      const n = stats?.pendientes_deteccion ?? stats?.pendientes ?? 0;
      return `Pendientes: ${Number(n).toLocaleString()}`;
    }
    return '—';
  }

  function updateButtonMetricsLabel(ws, type, metricsText) {
    lastButtonMetrics[ws] = lastButtonMetrics[ws] || {};
    lastButtonMetrics[ws][type] = metricsText;
    const sel = type === 'descargar' ? '.btnWsDescargar' : '.btnWsClasificar';
    const btn = document.querySelector(`${sel}[data-ws="${ws}"]`);
    if (!btn || !btn.classList.contains('ws-btn-processing')) return;
    const text = (metricsText && metricsText.trim()) ? metricsText.trim() : '—';
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1" aria-hidden="true"></i>' + text.replace(/</g, '&lt;');
  }

  function applyTickToPanel(msg) {
    const { mode, ws, data } = msg;
    if (!data || !ws) return;
    const type = mode === 'download' ? 'descargar' : 'clasificar';
    if (mode === 'download') {
      if (typeof data.pendientes === 'number') {
        const registrosEl = document.querySelector('.ws-card-stats[data-ws="' + CSS.escape(ws) + '"] .ws-stat-num[data-stat="registros-descarga"]');
        if (registrosEl) registrosEl.textContent = Number(data.pendientes).toLocaleString();
      }
      const stats = data?.clasificacion_stats;
      const imagenes = stats && (typeof stats.total === 'number') ? stats.total : null;
      const metricsText = formatMetrics(type, null, { imagenes_totales: imagenes ?? 0 });
      updateButtonMetricsLabel(ws, type, metricsText);
      if (stats) updateCardStats(ws, { total: stats.total, pendientes: stats.pendientes, pendientes_deteccion: stats.pendientes_deteccion ?? stats.pendientes, detections_total: stats.detections_total });
    } else {
      const pendientes = data?.pendientes ?? data?.pendientes_deteccion ?? data?.pending ?? 0;
      const metricsText = formatMetrics(type, { pendientes_deteccion: pendientes, pendientes });
      updateButtonMetricsLabel(ws, type, metricsText);
      updateCardStats(ws, {
        total: data?.stats?.total ?? data?.total,
        pendientes: data?.pendientes,
        pendientes_deteccion: data?.pendientes_deteccion ?? data?.pendientes,
        detections_total: data?.detections_total,
        errores: data?.stats_deteccion?.errores
      });
    }
  }

  if (worker) {
    worker.onmessage = function (e) {
      const msg = e.data;
      if (msg?.type === 'state') {
        syncQueuesFromState(msg);
        updateProcessingPanel();
      } else if (msg?.type === 'done') {
        if (msg.mode === 'download') downloadQueue.delete(msg.ws);
        else classifyQueue.delete(msg.ws);
        updateProcessingPanel();
      } else if (msg?.type === 'tick') {
        applyTickToPanel(msg);
      }
    };
  }

  document.querySelectorAll('.btnWsDescargar[data-ws]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const ws = btn.getAttribute('data-ws');
      if (!ws) return;
      if (downloadQueue.has(ws)) {
        downloadQueue.delete(ws);
        if (worker) worker.postMessage({ type: 'remove', mode: 'download', ws });
        updateProcessingPanel();
        return;
      }
      downloadQueue.add(ws);
      updateProcessingPanel();
      if (worker) worker.postMessage({ type: 'add', mode: 'download', ws });
      else { mainThreadStopRequested = false; runDownloadLoopMain(ws); }
    });
  });
  document.querySelectorAll('.btnWsClasificar[data-ws]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const ws = btn.getAttribute('data-ws');
      if (!ws) return;
      if (classifyQueue.has(ws)) {
        classifyQueue.delete(ws);
        if (worker) worker.postMessage({ type: 'remove', mode: 'classify', ws });
        updateProcessingPanel();
        return;
      }
      classifyQueue.add(ws);
      updateProcessingPanel();
      if (worker) worker.postMessage({ type: 'add', mode: 'classify', ws });
      else { mainThreadStopRequested = false; runClassifyLoopMain(ws); }
    });
  });

  // Detener todos: ya no hay barra global; cada botón tiene su "Detener"

  async function abrirWorkspace(slug) {
    const ws = String(slug || '').trim();
    if (!ws) return;
    const { ok, data } = await postJson('workspace_set', { workspace: ws });
    if (!ok || !data.success) throw new Error(data.error || 'Error');
    return ws;
  }

  document.querySelectorAll('.btnSetupWs[data-ws]').forEach((b) => {
    b.addEventListener('click', async () => {
      try {
        b.disabled = true;
        await abrirWorkspace(b.getAttribute('data-ws') || '');
        window.location.href = '?action=setup';
      } catch (e) {
        // no-op
      } finally {
        b.disabled = false;
      }
    });
  });

  document.querySelectorAll('.btnEnterWs[data-ws]').forEach((b) => {
    b.addEventListener('click', async () => {
      try {
        b.disabled = true;
        await abrirWorkspace(b.getAttribute('data-ws') || '');
        window.location.href = '?action=index';
      } catch (e) {
        // no-op
      } finally {
        b.disabled = false;
      }
    });
  });

  function syncDeleteUI() {
    const typed = String(wsDeleteConfirm?.value || '').trim();
    const ok = typed === deleteTargetSlug;
    if (btnConfirmDelete) btnConfirmDelete.disabled = !ok;
  }

  document.querySelectorAll('.btnDeleteWs[data-ws]').forEach((b) => {
    b.addEventListener('click', () => {
      deleteTargetSlug = String(b.getAttribute('data-ws') || '').trim();
      if (wsDeleteTarget) wsDeleteTarget.textContent = deleteTargetSlug;
      if (wsDeleteConfirm) wsDeleteConfirm.value = '';
      setStatus(stDeleteText, 'neutral', 'Pendiente');
      if (btnConfirmDelete) btnConfirmDelete.disabled = true;
      // Abrir modal Bootstrap
      if (window.jQuery) window.jQuery('#deleteModal').modal('show');
      try { wsDeleteConfirm?.focus(); } catch(e) {}
    });
  });

  if (wsDeleteConfirm) {
    wsDeleteConfirm.addEventListener('input', syncDeleteUI);
    wsDeleteConfirm.addEventListener('change', syncDeleteUI);
  }
  if (btnConfirmDelete) {
    btnConfirmDelete.addEventListener('click', async () => {
      const typed = String(wsDeleteConfirm?.value || '').trim();
      if (typed !== deleteTargetSlug) {
        setStatus(stDeleteText, 'bad', 'Confirmación no coincide');
        return;
      }
      setStatus(stDeleteText, 'neutral', 'Eliminando…');
      btnConfirmDelete.disabled = true;
      try {
        const { ok, data } = await postJson('workspace_delete', { workspace: deleteTargetSlug, confirm: typed });
        if (!ok || !data.success) {
          setStatus(stDeleteText, 'bad', data.error || 'Error');
          btnConfirmDelete.disabled = false;
          return;
        }
        setStatus(stDeleteText, 'ok', 'Eliminado');
        if (window.jQuery) window.jQuery('#deleteModal').modal('hide');
        window.location.href = '?action=workspace_select';
      } catch (e) {
        setStatus(stDeleteText, 'bad', e?.message ? String(e.message) : 'Error');
        btnConfirmDelete.disabled = false;
      }
    });
  }

  if (btnCreate) {
    btnCreate.addEventListener('click', async () => {
      const name = String(wsCreateName?.value || '').trim();
      if (!name) {
        setStatus(stCreateText, 'bad', 'Escribe un nombre');
        return;
      }
      setStatus(stCreateText, 'neutral', 'Creando…');
      btnCreate.disabled = true;
      try {
        const copyFrom = (document.getElementById('wsCreateCopyConfig') || {}).value || '';
        const payload = { name };
        if (copyFrom) payload.copy_config_from = copyFrom;
        const { ok, data } = await postJson('workspace_create', payload);
        if (!ok || !data.success) {
          setStatus(stCreateText, 'bad', data.error || 'Error');
          return;
        }
        setStatus(stCreateText, 'ok', `Creado: ${data.workspace || ''}`);
        if (window.jQuery) window.jQuery('#createModal').modal('hide');
        window.location.href = '?action=setup';
      } finally {
        btnCreate.disabled = false;
      }
    });
  }

  // --- Buscador consolidado (todos los workspaces) ---
  const wsSearchConsolidado = document.getElementById('wsSearchConsolidado');
  if (wsSearchConsolidado) {
    const txtBuscarCarpetaGlobal = document.getElementById('txtBuscarCarpetaGlobal');
    const btnBuscarCarpetaGlobal = document.getElementById('btnBuscarCarpetaGlobal');
    const stBuscarCarpetaGlobal = document.getElementById('stBuscarCarpetaGlobal');
    const lstCarpetasGlobal = document.getElementById('lstCarpetasGlobal');
    const rngUmbralGlobal = document.getElementById('rngUmbralGlobal');
    const lblUmbralGlobal = document.getElementById('lblUmbralGlobal');
    const tagsEtiquetasGlobal = document.getElementById('tagsEtiquetasGlobal');
    const tagsEtiquetasGlobalEmpty = document.getElementById('tagsEtiquetasGlobalEmpty');
    const stBuscarEtiquetasGlobal = document.getElementById('stBuscarEtiquetasGlobal');
    const lstEtiquetasGlobal = document.getElementById('lstEtiquetasGlobal');
    const wrapBtnAbrirResultadosGlobal = document.getElementById('wrapBtnAbrirResultadosGlobal');
    const txtCountResultadosEtiqGlobal = document.getElementById('txtCountResultadosEtiqGlobal');
    const btnAbrirResultadosEnModalGlobal = document.getElementById('btnAbrirResultadosEnModalGlobal');
    const wsSearchAcordeon = document.getElementById('wsSearchAcordeon');

    const MIN_CARACTERES_CARPETA = 3;
    let selectedEtiquetaGlobal = null;
    let buscarCarpetaGlobalTimer = null;
    let buscarEtiquetasGlobalTimer = null;
    let lastEtiquetasGlobalResultados = [];

    if (typeof window.BuscadorModals !== 'undefined') {
      const modalCarpeta = document.getElementById('modalCarpeta');
      if (modalCarpeta) {
        window.BuscadorModals.init({
          getJson: async function (url) {
            const resp = await fetch(url, { headers: { accept: 'application/json' } });
            const data = await resp.json().catch(function () { return {}; });
            return { ok: resp.ok, data: data };
          },
          buildUrlWithWorkspace: function (url, ws) {
            if (!ws) return url;
            var sep = url.indexOf('?') >= 0 ? '&' : '?';
            return url + sep + 'workspace=' + encodeURIComponent(ws);
          },
          setStatus: setStatus,
          refs: {
            modalCarpeta: modalCarpeta,
            ttlCarpeta: document.getElementById('ttlCarpeta'),
            tagsCarpeta: document.getElementById('tagsCarpeta'),
            gridThumbs: document.getElementById('gridThumbs'),
            modalCarpetaStacked: document.getElementById('modalCarpetaStacked'),
            ttlCarpetaStacked: document.getElementById('ttlCarpetaStacked'),
            gridThumbsStacked: document.getElementById('gridThumbsStacked'),
            modalVisor: document.getElementById('modalVisor'),
            ttlImagen: document.getElementById('ttlImagen'),
            ttlImagenWrap: document.getElementById('ttlImagenWrap'),
            lnkAbrirOriginal: document.getElementById('lnkAbrirOriginal'),
            btnVisorAbrirCarpeta: document.getElementById('btnVisorAbrirCarpeta'),
            swBoxes: document.getElementById('swBoxes'),
            badgesDet: document.getElementById('badgesDet'),
            cnv: document.getElementById('cnv'),
            stVisor: document.getElementById('stVisor'),
          },
        });
      }
    }

    function expandirBuscadorGlobal(quien) {
      if (!wsSearchAcordeon) return;
      wsSearchAcordeon.classList.remove('expanded-carpetas', 'expanded-etiquetas');
      if (quien === 'carpetas') wsSearchAcordeon.classList.add('expanded-carpetas');
      else if (quien === 'etiquetas') wsSearchAcordeon.classList.add('expanded-etiquetas');
    }

    // Buscar por carpeta
    async function buscarCarpetasGlobal(bypassMinLength) {
      const q = String(txtBuscarCarpetaGlobal?.value || '').trim();
      if (!bypassMinLength && q.length > 0 && q.length < MIN_CARACTERES_CARPETA) {
        setStatus(stBuscarCarpetaGlobal, 'neutral', 'Mín. ' + MIN_CARACTERES_CARPETA + ' caracteres o pulsa Buscar');
        return;
      }
      expandirBuscadorGlobal('carpetas');
      setStatus(stBuscarCarpetaGlobal, 'neutral', q ? 'Buscando…' : 'Cargando carpetas…');
      if (lstCarpetasGlobal) lstCarpetasGlobal.innerHTML = '<div class="text-muted py-2 text-center small"><i class="fas fa-spinner fa-spin mr-1"></i> Consultando…</div>';
      const { ok, data } = await getJson(apiUrl('buscar_carpetas_global', { q: q }));
      if (!ok || !data?.success) {
        setStatus(stBuscarCarpetaGlobal, 'bad', String(data?.error || 'Error'));
        if (lstCarpetasGlobal) lstCarpetasGlobal.innerHTML = '<div class="buscador-empty">Error</div>';
        return;
      }
      setStatus(stBuscarCarpetaGlobal, 'ok', 'Total: ' + (data.total || 0));
      const carpetas = Array.isArray(data.carpetas) ? data.carpetas : [];
      if (!lstCarpetasGlobal) return;
      lstCarpetasGlobal.innerHTML = '';
      if (!carpetas.length) {
        lstCarpetasGlobal.innerHTML = '<div class="buscador-empty">Sin resultados</div>';
        return;
      }
      const FOLDER_MAX_TAGS_VISIBLE = 8;
      for (const c of carpetas) {
        const nombre = String(c?.nombre || c?.ruta || '').trim();
        const ruta = String(c?.ruta || '').trim();
        const total = Number(c?.total_archivos ?? 0);
        const pend = Number(c?.pendientes ?? 0);
        const ws = String(c?.workspace || '').trim();
        const tags = Array.isArray(c?.tags) ? c.tags : [];
        const avatarUrl = c?.avatar_url || null;
        const showPath = ruta && ruta !== nombre;
        const tagBadges = tags.slice(0, FOLDER_MAX_TAGS_VISIBLE).map(function (t) {
          const lab = String(t?.label || '').trim();
          const cnt = Number(t?.count || 0);
          if (!lab) return '';
          const fullText = tagLabelToFullText(lab);
          return '<span class="folder-tag"><span class="folder-tag-label">' + fullText.replace(/</g, '&lt;') + '</span> <b class="folder-tag-count">' + cnt + '</b></span>';
        }).join('');
        const moreTags = tags.length > FOLDER_MAX_TAGS_VISIBLE ? '<span class="folder-tag folder-tag-more">+' + (tags.length - FOLDER_MAX_TAGS_VISIBLE) + ' más</span>' : '';
        const avatarHtml = avatarUrl
          ? '<img src="' + avatarUrl.replace(/"/g, '&quot;') + '" alt="" class="folder-item-avatar" loading="lazy">'
          : '<span class="folder-item-avatar folder-item-avatar-placeholder" aria-hidden="true"><i class="fas fa-folder"></i></span>';
        const statusHtml = pend === 0
          ? '<span class="folder-item-status folder-item-status-ok">Procesado</span>'
          : '<span class="folder-item-status folder-item-status-pend">' + pend + ' pendientes</span>';
        const wsBadge = ws ? '<span class="folder-item-workspace badge badge-secondary ml-1">' + ws.replace(/</g, '&lt;') + '</span>' : '';
        const linkHtml = '<a href="#" class="list-group-item list-group-item-action folder-list-item ws-search-result-item">' +
          '<div class="d-flex w-100 folder-item-inner">' +
          '<div class="folder-item-avatar-wrap">' + avatarHtml + '</div>' +
          '<div class="folder-item-body">' +
          '<div class="folder-item-head">' +
          '<h6 class="folder-item-title">' + nombre.replace(/</g, '&lt;') + '</h6>' +
          '<span class="folder-item-meta">' +
          '<span class="folder-item-count">' + total.toLocaleString() + ' imágenes</span>' +
          statusHtml + wsBadge +
          '</span></div>' +
          (showPath ? '<div class="folder-item-path">' + ruta.replace(/</g, '&lt;') + '</div>' : '') +
          (tagBadges || moreTags ? '<div class="folder-item-tags">' + tagBadges + moreTags + '</div>' : '') +
          '</div></div></a>';
        const wrap = document.createElement('div');
        wrap.innerHTML = linkHtml;
        const a = wrap.querySelector('a');
        a.addEventListener('click', async (e) => {
          e.preventDefault();
          try {
            if (window.BuscadorModals) await window.BuscadorModals.openFolder(nombre, ruta, null, ws);
            else { const { ok, data } = await postJson('workspace_set', { workspace: ws }); if (ok && data.success) window.location.href = '?action=index'; }
          } catch (err) { setStatus(stBuscarCarpetaGlobal, 'bad', err?.message || 'Error'); }
        });
        lstCarpetasGlobal.appendChild(a);
      }
    }

    if (txtBuscarCarpetaGlobal) {
      txtBuscarCarpetaGlobal.addEventListener('input', function () {
        if (buscarCarpetaGlobalTimer) clearTimeout(buscarCarpetaGlobalTimer);
        var q = String(txtBuscarCarpetaGlobal.value || '').trim();
        if (q.length >= MIN_CARACTERES_CARPETA) {
          buscarCarpetaGlobalTimer = setTimeout(function () { buscarCarpetasGlobal(false); buscarCarpetaGlobalTimer = null; }, 450);
        } else {
          buscarCarpetaGlobalTimer = null;
          if (lstCarpetasGlobal) lstCarpetasGlobal.innerHTML = '';
        }
      });
      txtBuscarCarpetaGlobal.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          if (buscarCarpetaGlobalTimer) clearTimeout(buscarCarpetaGlobalTimer);
          buscarCarpetaGlobalTimer = null;
          if (String(txtBuscarCarpetaGlobal?.value || '').trim().length >= MIN_CARACTERES_CARPETA) buscarCarpetasGlobal(false);
        }
      });
    }
    if (btnBuscarCarpetaGlobal) btnBuscarCarpetaGlobal.addEventListener('click', () => buscarCarpetasGlobal(true));

    // Buscar por etiquetas (misma validación por umbral que el buscador del index)
    let allEtiquetasGlobal = []; // { label, count, min, max } desde etiquetas_detectadas_global

    function tagLabelToFullText(label) {
      return String(label || '').replace(/_/g, ' ').toLowerCase().replace(/\b\w/g, function (c) { return c.toUpperCase(); });
    }

    /** Un tag se muestra si el umbral (0–100) está dentro del rango del tag: umbral <= max. */
    function etiquetaIncluyeUmbralGlobal(t, umbral) {
      const max = t.max != null ? Number(t.max) : 100;
      return umbral <= max;
    }

    function renderTagsFilteredGlobal(umbral) {
      if (!tagsEtiquetasGlobal || !tagsEtiquetasGlobalEmpty) return;
      const umbralNum = Number(umbral);
      const filtered = umbralNum === 0 ? allEtiquetasGlobal : allEtiquetasGlobal.filter(function (t) { return etiquetaIncluyeUmbralGlobal(t, umbralNum); });
      if (selectedEtiquetaGlobal && !filtered.some(function (t) { return String(t.label).trim() === selectedEtiquetaGlobal; })) {
        selectedEtiquetaGlobal = '';
        if (lstEtiquetasGlobal) lstEtiquetasGlobal.innerHTML = '<div class="buscador-empty">Ajusta el umbral o elige otra etiqueta</div>';
        setStatus(stBuscarEtiquetasGlobal, 'neutral', 'Ajusta el umbral o elige otra etiqueta');
      }
      tagsEtiquetasGlobal.innerHTML = '';
      tagsEtiquetasGlobalEmpty.style.display = filtered.length ? 'none' : 'block';
      tagsEtiquetasGlobalEmpty.textContent = (allEtiquetasGlobal.length && !filtered.length) ? 'Ninguna etiqueta cumple el umbral' : 'Cargando etiquetas…';
      for (let i = 0; i < filtered.length; i++) {
        const e = filtered[i];
        const label = String(e?.label || '').trim();
        if (!label) continue;
        const count = (e && e.count != null) ? Number(e.count) : null;
        const min = e.min != null ? Number(e.min) : null;
        const max = e.max != null ? Number(e.max) : null;
        const displayLabel = tagLabelToFullText(label);
        const rangeStr = (min != null && max != null && !Number.isNaN(min) && !Number.isNaN(max)) ? ' [' + min + '–' + max + '%]' : '';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-sm btn-outline-secondary tag-etiqueta-btn' + (selectedEtiquetaGlobal === label ? ' active' : '');
        btn.dataset.label = label;
        const mainText = count != null && !Number.isNaN(count) ? displayLabel + ' (' + count.toLocaleString() + ')' : displayLabel;
        btn.textContent = mainText + rangeStr;
        btn.addEventListener('click', function () {
          const yaSeleccionado = selectedEtiquetaGlobal === label;
          if (yaSeleccionado) {
            selectedEtiquetaGlobal = '';
            document.querySelectorAll('#tagsEtiquetasGlobal .tag-etiqueta-btn').forEach(function (b) { b.classList.remove('active'); });
            if (lstEtiquetasGlobal) lstEtiquetasGlobal.innerHTML = '<div class="buscador-empty">Sin resultados</div>';
            setStatus(stBuscarEtiquetasGlobal, 'neutral', 'Selecciona una etiqueta (clic en un tag)');
            return;
          }
          selectedEtiquetaGlobal = label;
          document.querySelectorAll('#tagsEtiquetasGlobal .tag-etiqueta-btn').forEach(function (b) { b.classList.remove('active'); });
          btn.classList.add('active');
          if (buscarEtiquetasGlobalTimer) clearTimeout(buscarEtiquetasGlobalTimer);
          buscarEtiquetasGlobalTimer = setTimeout(function () { buscarEtiquetasGlobal(); buscarEtiquetasGlobalTimer = null; }, 450);
        });
        tagsEtiquetasGlobal.appendChild(btn);
      }
    }

    function getUmbralGlobal() {
      const v = parseInt(rngUmbralGlobal?.value || '80', 10);
      return Math.max(0, Math.min(100, isNaN(v) ? 80 : v));
    }
    function syncUmbralGlobal() {
      if (!lblUmbralGlobal) return;
      const val = String(rngUmbralGlobal?.value ?? '80');
      lblUmbralGlobal.textContent = val + '%';
      lblUmbralGlobal.style.setProperty('--umbral-pct', val + '%');
      renderTagsFilteredGlobal(getUmbralGlobal());
    }
    if (rngUmbralGlobal && lblUmbralGlobal) {
      syncUmbralGlobal();
      rngUmbralGlobal.addEventListener('input', syncUmbralGlobal);
    }

    async function loadEtiquetasGlobal() {
      if (!tagsEtiquetasGlobal || !tagsEtiquetasGlobalEmpty) return;
      tagsEtiquetasGlobal.innerHTML = '';
      tagsEtiquetasGlobalEmpty.style.display = 'block';
      const { ok, data } = await getJson('?action=etiquetas_detectadas_global');
      tagsEtiquetasGlobalEmpty.style.display = 'none';
      if (!ok || !data?.success) return;
      allEtiquetasGlobal = Array.isArray(data.etiquetas) ? data.etiquetas : [];
      renderTagsFilteredGlobal(getUmbralGlobal());
    }

    async function buscarEtiquetasGlobal() {
      const label = selectedEtiquetaGlobal ? String(selectedEtiquetaGlobal).trim() : '';
      if (!label) {
        setStatus(stBuscarEtiquetasGlobal, 'bad', 'Selecciona una etiqueta (clic en un tag)');
        return;
      }
      expandirBuscadorGlobal('etiquetas');
      setStatus(stBuscarEtiquetasGlobal, 'neutral', 'Buscando…');
      if (lstEtiquetasGlobal) lstEtiquetasGlobal.innerHTML = '<div class="text-muted py-2 text-center small"><i class="fas fa-spinner fa-spin mr-1"></i> Consultando…</div>';
      // Si el slider está en 0%, usar umbral 0 para incluir todas las imágenes con esa etiqueta.
      // Si no, usar el mínimo de la etiqueta para incluir las del rango del tag, o el valor del slider.
      const sliderVal = getUmbralGlobal();
      const tag = allEtiquetasGlobal.find(function (t) { return String(t?.label || '').trim() === label; });
      const umbralTag = (tag && tag.min != null && !Number.isNaN(Number(tag.min))) ? Math.max(0, Math.min(100, Number(tag.min))) : null;
      const umbral = (sliderVal === 0) ? 0 : (umbralTag != null ? umbralTag : (label ? 0 : sliderVal));
      const { ok, data } = await getJson(apiUrl('buscar_imagenes_etiquetas_global', { labels: label, umbral: umbral }));
      if (!ok || !data?.success) {
        setStatus(stBuscarEtiquetasGlobal, 'bad', String(data?.error || 'Error'));
        lastEtiquetasGlobalResultados = [];
        if (wrapBtnAbrirResultadosGlobal) wrapBtnAbrirResultadosGlobal.style.display = 'none';
        if (lstEtiquetasGlobal) lstEtiquetasGlobal.innerHTML = '<div class="buscador-empty">Error</div>';
        return;
      }
      setStatus(stBuscarEtiquetasGlobal, 'ok', 'Resultados: ' + (data.total || 0));
      const imagenes = Array.isArray(data.imagenes) ? data.imagenes : [];
      lastEtiquetasGlobalResultados = imagenes;
      if (wrapBtnAbrirResultadosGlobal) wrapBtnAbrirResultadosGlobal.style.display = imagenes.length ? 'flex' : 'none';
      if (txtCountResultadosEtiqGlobal && imagenes.length) txtCountResultadosEtiqGlobal.textContent = imagenes.length + ' imagen' + (imagenes.length !== 1 ? 'es' : '') + ' en esta búsqueda';
      if (!lstEtiquetasGlobal) return;
      lstEtiquetasGlobal.innerHTML = '';
      if (!imagenes.length) {
        lstEtiquetasGlobal.innerHTML = '<div class="buscador-empty">Sin resultados</div>';
        return;
      }
      for (const it of imagenes) {
        const ruta = String(it?.ruta_relativa || it?.ruta || '').trim();
        const carpeta = String(it?.ruta_carpeta || '').trim();
        const archivo = String(it?.archivo || '').trim();
        const bestScore = (it?.best_score !== undefined && it?.best_score !== null) ? Number(it.best_score) : null;
        const ws = String(it?.workspace || '').trim();
        const scorePct = bestScore !== null && !Number.isNaN(bestScore) ? Math.round(bestScore * 100) : null;
        const a = document.createElement('a');
        a.href = '#';
        a.className = 'list-group-item list-group-item-action ws-search-result-item';
        a.innerHTML = '<span class="badge badge-secondary mr-2">' + ws.replace(/</g, '&lt;') + '</span>' +
          (ruta.replace(/</g, '&lt;')) +
          (scorePct !== null ? ' <span class="badge badge-pill ml-1">' + scorePct + '%</span>' : '');
        a.addEventListener('click', async (e) => {
          e.preventDefault();
          try {
            if (window.BuscadorModals && archivo) await window.BuscadorModals.openVisor(carpeta, archivo, ruta, ws);
            else if (window.BuscadorModals) await window.BuscadorModals.openFolder(carpeta, carpeta, ruta, ws);
            else { const { ok, data } = await postJson('workspace_set', { workspace: ws }); if (ok && data.success) window.location.href = '?action=index'; }
          } catch (err) { setStatus(stBuscarEtiquetasGlobal, 'bad', err?.message || 'Error'); }
        });
        lstEtiquetasGlobal.appendChild(a);
      }
    }

    if (btnAbrirResultadosEnModalGlobal) btnAbrirResultadosEnModalGlobal.addEventListener('click', function () {
      if (window.BuscadorModals && lastEtiquetasGlobalResultados.length) window.BuscadorModals.openGalleryResultados(lastEtiquetasGlobalResultados);
    });

    document.querySelectorAll('#wsSearchAcordeon .acordeon-item').forEach((item) => {
      const header = item.querySelector('.acordeon-header');
      const body = item.querySelector('.acordeon-body');
      const quien = item.getAttribute('data-acordeon');
      if (header && quien) header.addEventListener('click', () => expandirBuscadorGlobal(quien));
      if (body && quien) {
        body.addEventListener('click', () => {
          const acordeon = document.getElementById('wsSearchAcordeon');
          if (!acordeon) return;
          if (!acordeon.classList.contains('expanded-' + quien)) expandirBuscadorGlobal(quien);
        });
      }
    });

    loadEtiquetasGlobal();
  }

  // Toggle colapso del buscador (derecha)
  const STORAGE_KEY_BUSCADOR = 'photoClassifier.buscadorCollapsed';
  const wsSearchCol = document.getElementById('wsSearchCol');
  const wsSearchToggle = document.getElementById('wsSearchToggle');
  const wsLayout = document.querySelector('.ws-layout');
  if (wsSearchCol && wsSearchToggle) {
    function setBuscadorCollapsed(collapsed) {
      const isCollapsed = !!collapsed;
      wsSearchCol.classList.toggle('ws-search-col--collapsed', isCollapsed);
      if (wsLayout) wsLayout.classList.toggle('ws-layout--buscador-collapsed', isCollapsed);
      wsSearchToggle.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
      wsSearchToggle.setAttribute('aria-label', isCollapsed ? 'Expandir buscador' : 'Colapsar buscador');
      wsSearchToggle.setAttribute('title', isCollapsed ? 'Expandir buscador' : 'Colapsar buscador');
      try { localStorage.setItem(STORAGE_KEY_BUSCADOR, isCollapsed ? '1' : '0'); } catch (e) {}
    }
    wsSearchToggle.addEventListener('click', function () {
      setBuscadorCollapsed(wsSearchCol.classList.contains('ws-search-col--collapsed') ? false : true);
    });
    try {
      const saved = localStorage.getItem(STORAGE_KEY_BUSCADOR);
      // Por defecto colapsado; solo expandir si el usuario lo dejó abierto (saved === '0')
      if (saved === '0') setBuscadorCollapsed(false);
      else setBuscadorCollapsed(true);
    } catch (e) {
      setBuscadorCollapsed(true);
    }
  }
</script>

