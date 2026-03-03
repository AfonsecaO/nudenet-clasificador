<?php
/** @var string $mode */
/** @var string $pattern */
/** @var int $totalTablasEstado */
/** @var array $tablasDelEstado */
/** @var array $estadoProcesamiento */
/** @var string|null $app_workspace_slug */
/** @var string|null $auto_param */

$__title = 'PhotoClassifier';
$app_workspace_slug = $app_workspace_slug ?? null;
$auto_param = $auto_param ?? null;
$__bodyClass = '';

$mode = isset($mode) ? (string)$mode : 'images_only';
$isDbMode = ($mode === 'db_and_images');

$ws = \App\Services\WorkspaceService::current();
$wsSlug = $ws ? (string)$ws : '';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES); }
function pct($n, $d): int {
  $n = (float)$n; $d = (float)$d;
  if ($d <= 0) return 0;
  $p = (int)round(($n / $d) * 100);
  return max(0, min(100, $p));
}
?>

<nav class="topnav">
  <a href="?action=index" class="topnav-brand"><i class="fas fa-shield-alt"></i> PhotoClassifier</a>
  <ul class="topnav-links">
    <li><a href="?action=index" class="active"><i class="fas fa-layer-group"></i> <?php echo $wsSlug ? h(mb_strtoupper($wsSlug)) : '—'; ?></a></li>
    <li><a href="?action=setup"><i class="fas fa-cog"></i> Parametrización</a></li>
    <li><a href="?action=workspace_select"><i class="fas fa-layer-group"></i> Workspaces</a></li>
  </ul>
</nav>

<main class="main content">
  <div class="container-fluid" data-db-mode="<?php echo $isDbMode ? '1' : '0'; ?>">

    <div id="esperePorFavor" class="espere-por-favor-overlay" style="display: none;" aria-live="polite" aria-busy="true">
      <div class="espere-por-favor-box">
        <div class="espere-por-favor-spinner"></div>
        <p class="espere-por-favor-text">Espere por favor…</p>
      </div>
    </div>

    <!-- 3 columnas: 3 (acciones + clasificación con subir) | 6 (buscadores) | 3 (log) -->
    <div class="row dashboard-columns">
      <!-- Columna izquierda: Indicadores independientes + Acciones + Clasificación -->
      <div class="col-lg-3 col-md-12 mb-3 col-clasif">
        <!-- Indicadores (misma estructura y proporciones que en vista global workspaces) -->
        <div class="ws-card-stats mb-2" aria-label="Estadísticas del workspace">
          <div class="ws-stat">
            <span class="ws-stat-num" id="accionIndImagenesTotal">—</span>
            <span class="ws-stat-lbl"><i class="fas fa-image"></i> Imágenes en total</span>
          </div>
          <div class="ws-stat ws-stat-full" title="Imágenes aún no procesadas con Rekognition">
            <span class="ws-stat-num" id="accionIndPorModerar">—</span>
            <span class="ws-stat-lbl"><i class="fas fa-shield-alt"></i> Por moderar</span>
          </div>
          <?php if ($isDbMode): ?>
          <div class="ws-stat ws-stat-full ws-stat-col12">
            <span class="ws-stat-num" id="accionIndRegistrosDescarga">—</span>
            <span class="ws-stat-lbl"><i class="fas fa-download"></i> Registros por descargar</span>
          </div>
          <?php endif; ?>
        </div>
        <!-- Card Acciones (solo botones) -->
        <div class="card card-acciones mb-3">
          <div class="card-header card-header-normalized">
            <h3 class="card-title"><i class="fas fa-bolt" aria-hidden="true"></i> Acciones</h3>
          </div>
          <div class="card-body acciones-card-body">
            <div class="acciones-block acciones-block-descarga-moderacion row">
              <?php if ($isDbMode): ?>
              <div class="col-6 acciones-block-col">
                <div class="acciones-block-actions">
                  <button class="btn btn-accion-mant btn-accion-mant-secondary btn-block" id="btnDescargarRegistros" type="button" title="Descargar tablas del workspace">
                    <i class="fas fa-download btn-icon"></i> <span class="btn-accion-text">Descargar registros</span>
                  </button>
                </div>
                <small class="form-text text-muted" id="stTablas"></small>
              </div>
              <?php endif; ?>
              <div class="col-6 acciones-block-col">
                <div class="acciones-block-actions">
                  <button class="btn btn-accion-mant btn-accion-mant-secondary btn-block" id="btnClasificarModeracion" type="button" title="Clasificar imágenes pendientes con AWS Rekognition Content Moderation">
                    <i class="fas fa-shield-alt btn-icon"></i> <span class="btn-accion-text">Clasificar moderación</span>
                  </button>
                </div>
                <small class="form-text text-muted" id="stModeracion"></small>
              </div>
            </div>
            <div class="acciones-block acciones-block-mantenimiento">
              <div class="acciones-block-header">
                <span class="acciones-block-title"><i class="fas fa-tools"></i> Mantenimiento</span>
              </div>
              <div class="acciones-block-actions">
                <button class="btn btn-accion-mant btn-accion-mant-secondary" id="btnReindex" type="button">
                  <i class="fas fa-broom btn-icon"></i> Reindexar
                </button>
              </div>
            </div>
          </div>
        </div>

        <div class="card card-dashboard card-clasif card-clasif-fill">
          <div class="card-header card-header-normalized">
            <h3 class="card-title"><i class="fas fa-images" aria-hidden="true"></i> Imágenes</h3>
          </div>
          <div class="card-body clasif-card-body">
            <section class="clasif-upload-block" aria-label="Subir imágenes desde carpeta">
              <div class="upload-zone">
                <input type="file" class="upload-input-hidden" id="inpFolder" webkitdirectory directory multiple aria-label="Seleccionar carpeta con imágenes">
                <label for="inpFolder" class="upload-trigger">
                  <i class="fas fa-folder-open upload-zone-icon" aria-hidden="true"></i>
                  <span class="upload-zone-label">Elegir carpeta</span>
                  <span class="upload-zone-hint" id="uploadFolderName">Solo imágenes; estructura recursiva respetada. Videos y otros se descartan.</span>
                </label>
              </div>
              <div class="progress progress-soft" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                <div class="progress-bar progress-bar-soft" id="barUpload" data-width="0"></div>
              </div>
              <div id="uploadProcesando" class="upload-procesando small" aria-live="polite" style="display: none;"></div>
            </section>
          </div>
        </div>
      </div>

      <!-- Columna centro: Buscadores (componente compartido con workspace) -->
      <div class="col-lg-6 col-md-6 mb-3 col-buscadores">
        <div class="card card-buscadores">
          <?php
          $buscador = [
            'suffix' => '',
            'acordeonId' => 'buscadorAcordeon',
            'acordeonClass' => 'buscador-acordeon expanded-carpetas',
            'idLstResultadosEtiq' => 'lstResultadosEtiq',
            'idTagsEtiquetasEmpty' => 'tagsEtiquetasEmpty',
            'emptyTagsText' => '',
          ];
          include __DIR__ . '/partials/buscador-acordeon.php';
          ?>
        </div>
      </div>

      <!-- Columna derecha: Log -->
      <div class="col-lg-3 col-md-6 mb-3">
        <div class="card card-dashboard card-log h-100">
          <div class="card-header card-header-normalized d-flex justify-content-between align-items-center">
            <h3 class="card-title mb-0"><i class="fas fa-list-alt" aria-hidden="true"></i> Log de procesamiento</h3>
            <button class="btn btn-outline-secondary btn-sm" type="button" id="btnLogClear" title="Limpiar"><i class="fas fa-trash-alt"></i> Limpiar</button>
          </div>
          <div class="card-body log-panel-body d-flex flex-column min-h-0">
            <div id="logPanel" class="log-panel"></div>
          </div>
        </div>
      </div>
    </div>

    <?php if ($isDbMode): ?>
      <!-- Tablas (solo DB): row > card > header + collapse > body con grid -->
      <div class="row">
        <div class="col-12">
          <div class="card" id="cardTablas">
            <div class="card-header card-header-normalized d-flex justify-content-between align-items-center py-2 cursor-pointer" data-toggle="collapse" data-target="#collapseTablas" aria-expanded="true" aria-controls="collapseTablas" id="cardTablasToggle">
              <h3 class="card-title mb-0"><i class="fas fa-database" aria-hidden="true"></i> Tablas</h3>
              <i class="fas fa-chevron-down text-muted card-tablas-chevron" id="chevronTablas"></i>
            </div>
            <div id="collapseTablas" class="collapse show">
              <div class="card-body tablas-list-wrap">
                <div class="list-group" id="lstTablas">
                <?php foreach (($tablasDelEstado ?? []) as $t): ?>
                  <?php
                    $e = is_array($estadoProcesamiento ?? null) ? ($estadoProcesamiento[$t] ?? []) : [];
                    $ultimo = (int)($e['ultimo_id'] ?? 0);
                    $max = (int)($e['max_id'] ?? 0);
                    $faltan = (bool)($e['faltan_registros'] ?? true);
                    $p = pct($ultimo, $max);
                  ?>
                  <div class="list-group-item">
                    <div class="d-flex w-100 justify-content-between">
                      <h6 class="mb-1 text-monospace"><?php echo h($t); ?></h6>
                      <small>
                        <?php if ($faltan): ?>
                          <span class="badge badge-warning">Pendiente</span>
                        <?php else: ?>
                          <span class="badge badge-success">Completada</span>
                        <?php endif; ?>
                      </small>
                    </div>
                    <div class="small text-muted mb-1">
                      Último: <?php echo h($ultimo); ?> · Máx: <?php echo h($max); ?>
                    </div>
                    <div class="progress progress-sm">
                      <div class="progress-bar bg-info tblProgress" data-width="<?php echo (int)$p; ?>"></div>
                    </div>
                  </div>
                <?php endforeach; ?>
                <?php if (empty($tablasDelEstado ?? [])): ?>
                  <div class="list-group-item">
                    <div class="text-muted">No hay estado de tablas todavía. Revisa la parametrización y/o el patrón.</div>
                  </div>
                <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>


  </div>
</main>

<!-- Modal: carpeta -->
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

<!-- Modal: carpeta apilada (abierta desde visor u otro modal; se cierra y se vuelve al anterior) -->
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

<!-- Modal: visor -->
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
          <div class="visor-toolbar-left"></div>
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

<!-- Modal: confirmación (plantilla, no del explorador) -->
<div class="modal fade modal-confirm-app" id="modalConfirm" tabindex="-1" role="dialog" aria-labelledby="modalConfirmTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalConfirmTitle">Confirmar</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body" id="modalConfirmBody"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" id="modalConfirmCancel">Cancelar</button>
        <button type="button" class="btn btn-primary" id="modalConfirmOk">Aceptar</button>
      </div>
    </div>
  </div>
</div>

<script src="js/buscador-modals.js"></script>
<script>
  <?php if ($app_workspace_slug !== null && $app_workspace_slug !== ''): ?>window.APP_WORKSPACE = <?php echo json_encode($app_workspace_slug); ?>;<?php endif; ?>
  <?php if ($auto_param !== null && $auto_param !== ''): ?>window.APP_AUTO = <?php echo json_encode($auto_param); ?>;<?php endif; ?>

  function appendWorkspace(url) {
    if (typeof window.APP_WORKSPACE !== 'string' || !window.APP_WORKSPACE) return url;
    const sep = url.indexOf('?') >= 0 ? '&' : '?';
    return url + sep + 'workspace=' + encodeURIComponent(window.APP_WORKSPACE);
  }

  let indexWorker = null;
  try {
    const workerUrl = (typeof window !== 'undefined' && window.location?.pathname)
      ? window.location.pathname.replace(/\/[^/]*$/, '/') + 'js/workspace-processor.worker.js'
      : 'js/workspace-processor.worker.js';
    indexWorker = new Worker(workerUrl);
    indexWorker.postMessage({ type: 'init', baseUrl: window.location.origin + window.location.pathname });
  } catch (e) {
    console.warn('Web Worker no disponible en index:', e);
  }

  let clasificarModeracionRunning = false;
  let lastRefreshAccionesAt = 0;
  const REFRESH_ACCIONES_THROTTLE_MS = 2000;
  if (indexWorker && typeof window.APP_WORKSPACE === 'string' && window.APP_WORKSPACE) {
    indexWorker.onmessage = function (e) {
      const msg = e.data;
      if (msg?.ws !== window.APP_WORKSPACE) return;
      if (msg.mode === 'download') {
        if (msg.type === 'tick' && msg.data?.log_items) renderLogFromItems(msg.data.log_items);
        if (msg.type === 'tick') {
          const ind = msg.data?.indicadores;
          if (ind) {
            if (typeof ind.registros_pendientes_descarga === 'number') {
              const elReg = document.getElementById('accionIndRegistrosDescarga');
              if (elReg) elReg.textContent = Number(ind.registros_pendientes_descarga).toLocaleString();
            }
            if (typeof ind.imagenes_total === 'number') {
              const elTotal = document.getElementById('accionIndImagenesTotal');
              if (elTotal) elTotal.textContent = Number(ind.imagenes_total).toLocaleString();
            }
            if (typeof ind.imagenes_pendientes_moderacion === 'number') {
              const elMod = document.getElementById('accionIndPorModerar');
              if (elMod) elMod.textContent = Number(ind.imagenes_pendientes_moderacion).toLocaleString();
            }
            const stTablasEl = document.getElementById('stTablas');
            if (stTablasEl) setStatus(stTablasEl, 'neutral', 'Imágenes: ' + Number(ind.imagenes_total ?? 0).toLocaleString());
          } else if (typeof msg.data?.pendientes === 'number') {
            const elReg = document.getElementById('accionIndRegistrosDescarga');
            if (elReg) elReg.textContent = Number(msg.data.pendientes).toLocaleString();
            if (Date.now() - lastRefreshAccionesAt > REFRESH_ACCIONES_THROTTLE_MS) {
              lastRefreshAccionesAt = Date.now();
              refreshAccionesIndicadores();
            }
          }
        }
        if (msg.type === 'done') {
          autoTablasRunning = false;
          const btnDesc = document.getElementById('btnDescargarRegistros');
          if (btnDesc) { const t = btnDesc.querySelector('.btn-accion-text'); if (t) t.textContent = 'Descargar registros'; }
          stopIndexAutoStatsPolling();
          setStatus(stTablas, 'neutral', 'Detenido');
          appendLog('info', 'Descarga detenida.');
          refreshAccionesIndicadores();
          refreshStats().then(() => { if (msg.data?.log_items) renderLogFromItems(msg.data.log_items); else refreshLogPanel(); });
        }
        return;
      }
      if (msg.mode === 'classify') {
        if (msg.type === 'tick') {
          if (msg.data?.log_items) renderLogFromItems(msg.data.log_items);
          const ind = msg.data?.indicadores;
          const data = msg.data || {};
          if (ind) {
            if (typeof ind.registros_pendientes_descarga === 'number') {
              const elReg = document.getElementById('accionIndRegistrosDescarga');
              if (elReg) elReg.textContent = Number(ind.registros_pendientes_descarga).toLocaleString();
            }
            if (typeof ind.imagenes_total === 'number') {
              const elTotal = document.getElementById('accionIndImagenesTotal');
              if (elTotal) elTotal.textContent = Number(ind.imagenes_total).toLocaleString();
            }
            if (typeof ind.imagenes_pendientes_moderacion === 'number') {
              const elMod = document.getElementById('accionIndPorModerar');
              if (elMod) elMod.textContent = Number(ind.imagenes_pendientes_moderacion).toLocaleString();
            }
            const stModEl = document.getElementById('stModeracion');
            if (stModEl) setStatus(stModEl, 'neutral', Number(data.procesadas ?? 0).toLocaleString() + ' ok, ' + Number(data.pendientes ?? ind.imagenes_pendientes_moderacion ?? 0).toLocaleString() + ' pend.');
          } else if (typeof msg.data?.pendientes === 'number') {
            const elMod = document.getElementById('accionIndPorModerar');
            if (elMod) elMod.textContent = Number(msg.data.pendientes).toLocaleString();
            const stModEl = document.getElementById('stModeracion');
            if (stModEl) setStatus(stModEl, 'neutral', '0 ok, ' + Number(msg.data.pendientes).toLocaleString() + ' pend.');
            if (Date.now() - lastRefreshAccionesAt > REFRESH_ACCIONES_THROTTLE_MS) {
              lastRefreshAccionesAt = Date.now();
              refreshAccionesIndicadores();
            }
          }
        }
        if (msg.type === 'done') {
          clasificarModeracionRunning = false;
          const btnMod = document.getElementById('btnClasificarModeracion');
          if (btnMod) { const t = btnMod.querySelector('.btn-accion-text'); if (t) t.textContent = 'Clasificar moderación'; }
          const stMod = document.getElementById('stModeracion');
          if (stMod) stMod.textContent = 'Finalizado.';
          appendLog('info', 'Clasificación de moderación finalizada.');
          refreshAccionesIndicadores();
          refreshLogPanel();
        }
        return;
      }
    };
  }

  let indexAutoStatsIntervalId = null;
  function startIndexAutoStatsPolling() {
    if (indexAutoStatsIntervalId) return;
    indexAutoStatsIntervalId = setInterval(() => {
      refreshStats();
    }, 2500);
  }
  function stopIndexAutoStatsPolling() {
    if (indexAutoStatsIntervalId) { clearInterval(indexAutoStatsIntervalId); indexAutoStatsIntervalId = null; }
  }

  const el = (id) => document.getElementById(id);

  const txtProcesadas = el('txtProcesadas');
  const txtTotal = el('txtTotal');
  const txtUnsafe = el('txtUnsafe');
  const txtSafe = el('txtSafe');
  const barProcesadas = el('barProcesadas');

  const btnReindex = el('btnReindex');
  const stBuscarEtiq = el('stBuscarEtiq');

  const txtBuscarCarpeta = el('txtBuscarCarpeta');
  const stBuscarCarpeta = el('stBuscarCarpeta');
  const lstCarpetas = el('lstCarpetas');

  const inpFolder = el('inpFolder');
  const stUpload = el('stUpload');
  const barUpload = el('barUpload');
  const uploadProcesando = el('uploadProcesando');

  const modalCarpeta = el('modalCarpeta');
  const ttlCarpeta = el('ttlCarpeta');
  const tagsCarpeta = el('tagsCarpeta');
  const gridThumbs = el('gridThumbs');

  const modalCarpetaStacked = el('modalCarpetaStacked');
  const ttlCarpetaStacked = el('ttlCarpetaStacked');
  const gridThumbsStacked = el('gridThumbsStacked');

  const modalVisor = el('modalVisor');
  const ttlImagen = el('ttlImagen');
  const ttlImagenWrap = el('ttlImagenWrap');
  const lnkAbrirOriginal = el('lnkAbrirOriginal');
  const btnVisorAbrirCarpeta = el('btnVisorAbrirCarpeta');
  const badgesDet = el('badgesDet');
  const cnv = el('cnv');
  const stVisor = el('stVisor');

  async function getJsonRaw(url) {
    const resp = await fetch(url, { headers: { accept: 'application/json' } });
    const data = await resp.json().catch(() => ({}));
    return { ok: resp.ok, data };
  }

  if (typeof window.BuscadorModals !== 'undefined') {
    window.BuscadorModals.init({
      getJson: getJsonRaw,
      buildUrlWithWorkspace: function (url, ws) {
        const w = ws ?? window.APP_WORKSPACE;
        if (!w) return url;
        const sep = url.indexOf('?') >= 0 ? '&' : '?';
        return url + sep + 'workspace=' + encodeURIComponent(w);
      },
      setStatus: setStatus,
      refs: {
        modalCarpeta,
        ttlCarpeta,
        tagsCarpeta,
        gridThumbs,
        modalCarpetaStacked,
        ttlCarpetaStacked,
        gridThumbsStacked,
        modalVisor,
        ttlImagen,
        ttlImagenWrap,
        lnkAbrirOriginal,
        btnVisorAbrirCarpeta,
        badgesDet,
        cnv,
        stVisor,
      },
    });
  }

  let autoRunning = false;
  let autoTablasRunning = false;
  let buscarEtiqTimer = null;

  function setStatus(node, state, text) {
    if (!node) return;
    node.textContent = text || '';
    node.className = 'small';
    if (state === 'ok') node.classList.add('text-success');
    else if (state === 'bad') node.classList.add('text-danger');
    else node.classList.add('text-muted');
  }

  async function getJson(url) {
    url = appendWorkspace(url);
    const resp = await fetch(url, { headers: { 'accept': 'application/json' } });
    const data = await resp.json().catch(() => ({}));
    return { ok: resp.ok, data };
  }

  async function refreshAccionesIndicadores() {
    if (!window.APP_WORKSPACE) return;
    const elReg = document.getElementById('accionIndRegistrosDescarga');
    const elTotal = document.getElementById('accionIndImagenesTotal');
    const elMod = document.getElementById('accionIndPorModerar');
    const fmt = (n) => (typeof n === 'number' ? Number(n).toLocaleString() : '—');
    try {
      const [rDesc, rMod] = await Promise.all([
        getJson('?action=estadisticas_descarga'),
        getJson('?action=estadisticas_moderacion')
      ]);
      if (rDesc.ok && rDesc.data?.stats && typeof rDesc.data.stats.pendientes === 'number') {
        if (elReg) elReg.textContent = fmt(rDesc.data.stats.pendientes);
      }
      if (rMod.ok && rMod.data?.success) {
        const analizadas = Number(rMod.data.analizadas ?? 0);
        const pendientes = Number(rMod.data.pendientes ?? 0);
        if (elMod) elMod.textContent = fmt(pendientes);
        if (elTotal) elTotal.textContent = fmt(analizadas + pendientes);
      }
    } catch (e) {}
  }

  function pct(n, d) {
    n = Number(n || 0);
    d = Number(d || 0);
    if (d <= 0) return 0;
    const p = Math.round((n / d) * 100);
    return Math.max(0, Math.min(100, p));
  }

  function renderStats(stats) {
    const total = Number(stats?.total || 0);
    const procesadas = Number(stats?.procesadas || 0);
    const pendientes = Number(stats?.pendientes || 0);
    const safe = Number(stats?.safe || 0);
    const unsafe = Number(stats?.unsafe || 0);

    if (txtProcesadas) txtProcesadas.textContent = String(procesadas);
    if (txtTotal) txtTotal.textContent = String(total);

    const totalDual = safe + unsafe;
    if (totalDual > 0) {
      const unsafePct = Math.round((unsafe / totalDual) * 10000) / 100;
      const safePct = Math.round((safe / totalDual) * 10000) / 100;
      if (txtUnsafe) txtUnsafe.value = unsafePct + '%';
      if (txtSafe) txtSafe.value = safePct + '%';
    } else {
      if (txtUnsafe) txtUnsafe.value = '—';
      if (txtSafe) txtSafe.value = '—';
    }

    if (barProcesadas) {
      const w = pct(procesadas, total);
      barProcesadas.dataset.width = String(w);
      barProcesadas.style.width = w + '%';
      barProcesadas.setAttribute('aria-valuenow', w);
    }
  }

  async function refreshStats() {}

  const esperePorFavor = el('esperePorFavor');

  function showConfirm(title, body, primaryText, onConfirm) {
    const $modal = window.jQuery ? window.jQuery('#modalConfirm') : null;
    const titleEl = el('modalConfirmTitle');
    const bodyEl = el('modalConfirmBody');
    const btnOk = el('modalConfirmOk');
    const btnCancel = el('modalConfirmCancel');
    if (!bodyEl || !btnOk) return;
    if (titleEl) titleEl.textContent = title || 'Confirmar';
    bodyEl.innerHTML = '';
    if (typeof body === 'string') bodyEl.textContent = body; else if (body) bodyEl.appendChild(body);
    btnOk.textContent = primaryText || 'Aceptar';
    const handler = () => {
      if ($modal) $modal.modal('hide');
      if (typeof onConfirm === 'function') onConfirm();
    };
    btnOk.addEventListener('click', handler);
    if ($modal) {
      $modal.one('hidden.bs.modal', () => { btnOk.removeEventListener('click', handler); });
      $modal.modal('show');
    }
    if (btnCancel && $modal) btnCancel.onclick = () => $modal.modal('hide');
  }

  function reindexAll() {
    showConfirm(
      'Reindexar',
      '¿Reindexar desde el sistema de archivos? Se actualizará el índice de imágenes y carpetas.',
      'Reindexar',
      async () => {
        if (esperePorFavor) esperePorFavor.style.display = 'flex';
        setStatus(stBuscarCarpeta, 'neutral', 'Reindexando y limpiando…');
        try {
          const { ok, data } = await getJson('?action=reindex_imagenes');
          if (!ok || !data?.success) {
            setStatus(stBuscarCarpeta, 'bad', String(data?.error || 'Error'));
            return;
          }
          setStatus(stBuscarCarpeta, 'ok', 'Reindex completado');
          if (data?.stats) renderStats(data.stats);
          await refreshLogPanel();
          await buscarCarpetas(true);
        } finally {
          if (esperePorFavor) esperePorFavor.style.display = 'none';
        }
      }
    );
  }

  function normalizePathForTree(p) {
    return String(p ?? '')
      .trim()
      .replace(/\\/g, '/')
      .replace(/\/+/g, '/')
      .replace(/^\//, '')
      .replace(/\/$/, '');
  }

  /** Convierte lista plana de carpetas (ordenada por ruta) en árbol de nodos con children. */
  function buildFolderTree(items) {
    const normalized = (items || []).map((it) => ({
      ...it,
      ruta: normalizePathForTree(it?.ruta)
    }));
    const arr = [...normalized].sort((a, b) => (a.ruta || '').localeCompare(b.ruta || '', undefined, { sensitivity: 'base' }));
    const root = { children: [] };
    for (const item of arr) {
      const ruta = item.ruta;
      if (ruta === '') continue;
      const parts = ruta.split('/').filter(Boolean);
      let node = root;
      for (let i = 0; i < parts.length; i++) {
        const seg = parts[i];
        const pathSoFar = parts.slice(0, i + 1).join('/');
        let next = node.children.find((c) => normalizePathForTree(c.ruta) === pathSoFar);
        if (!next) {
          next = { name: seg, ruta: pathSoFar, data: null, children: [] };
          node.children.push(next);
          node.children.sort((a, b) => (a.ruta || '').localeCompare(b.ruta || '', undefined, { sensitivity: 'base' }));
        }
        node = next;
        if (i === parts.length - 1) node.data = item;
      }
    }
    return root.children;
  }

  let folderTreeCollapsed = new Set();

  function tagLabelToFullText(label) {
    return String(label || '')
      .replace(/_/g, ' ')
      .toLowerCase()
      .replace(/\b\w/g, (c) => c.toUpperCase());
  }

  const FOLDER_TREE_COL_WIDTH = 2.5;
  const FOLDER_TREE_MAX_TAGS_VISIBLE = 8;

  /** Genera el HTML de la columna de líneas (verticales + conector + toggle) para una fila del árbol. */
  function buildTreeRowLines(depth, hasMoreBelow, isLast, hasChildren, isExpanded) {
    const vlineCells = [];
    if (depth >= 1) {
      vlineCells.push('<div class="folder-tree-vline-cell"></div>');
    }
    for (let i = 0; i < depth; i++) {
      const show = hasMoreBelow[i] === true;
      vlineCells.push(`<div class="folder-tree-vline-cell">${show ? '<span class="folder-tree-vline"></span>' : ''}</div>`);
    }
    const connectorClass = isLast ? 'folder-tree-connector-last' : 'folder-tree-connector-mid';
    const toggleLabel = isExpanded ? 'Colapsar' : 'Expandir';
    const toggleHtml = hasChildren
      ? `<button type="button" class="folder-tree-toggle" aria-expanded="${isExpanded}" aria-label="${toggleLabel}" title="${toggleLabel}"><i class="fas fa-chevron-${isExpanded ? 'down' : 'right'}"></i></button>`
      : '<span class="folder-tree-toggle folder-tree-toggle-placeholder"></span>';
    const connectorCellHtml = `<div class="folder-tree-connector-cell">
      <div class="folder-tree-connector ${connectorClass}">
        <span class="folder-tree-connector-vertical"></span>
        <span class="folder-tree-connector-horizontal"></span>
        <span class="folder-tree-connector-dot" aria-hidden="true"></span>
      </div>
      ${toggleHtml}
    </div>`;
    return vlineCells.join('') + connectorCellHtml;
  }

  /** Renderiza un nodo del árbol (y recursivamente sus hijos si está expandido). */
  function renderFolderNode(node, depth, isLast, hasMoreBelow, setSize, posInSet, parentEl) {
    hasMoreBelow = Array.isArray(hasMoreBelow) ? hasMoreBelow : [];
    setSize = setSize != null ? setSize : 1;
    posInSet = posInSet != null ? posInSet : 1;
    parentEl = parentEl || lstCarpetas;
    const hasChildren = node.children && node.children.length > 0;
    const isExpanded = !hasChildren || !folderTreeCollapsed.has(node.ruta);
    const c = node.data;
    const nombre = c ? String(c?.nombre || c?.ruta || node.name || '').trim() : node.name;
    const ruta = node.ruta;
    const total = c ? Number(c?.total_archivos || 0) : 0;
    const pend = c ? Number(c?.pendientes || 0) : 0;
    const tags = c && Array.isArray(c?.tags) ? c.tags : [];
    const avatarUrl = c?.avatar_url || null;

    const tagBadges = tags.slice(0, FOLDER_TREE_MAX_TAGS_VISIBLE).map((t) => {
      const lab = String(t?.label || '').trim();
      const cnt = Number(t?.count || 0);
      if (!lab) return '';
      const fullText = tagLabelToFullText(lab);
      return `<span class="folder-tag"><span class="folder-tag-label">${fullText.replace(/</g, '&lt;')}</span> <b class="folder-tag-count">${cnt}</b></span>`;
    }).join('');
    const moreTags = tags.length > FOLDER_TREE_MAX_TAGS_VISIBLE ? `<span class="folder-tag folder-tag-more">+${tags.length - FOLDER_TREE_MAX_TAGS_VISIBLE} más</span>` : '';
    const showPath = ruta && ruta !== nombre && depth > 0;

    const avatarHtml = avatarUrl
      ? `<img src="${avatarUrl.replace(/"/g, '&quot;')}" alt="" class="folder-item-avatar" loading="lazy">`
      : '<span class="folder-item-avatar folder-item-avatar-placeholder" aria-hidden="true"><i class="fas fa-' + (hasChildren ? 'folder' : 'user-secret') + '"></i></span>';

    const indent = depth * FOLDER_TREE_COL_WIDTH;
    const row = document.createElement('div');
    row.className = 'folder-tree-row' + (isLast ? ' folder-tree-row-last' : '');
    row.setAttribute('data-depth', String(depth));
    row.setAttribute('data-ruta', ruta || '');
    row.style.setProperty('--folder-depth', indent + 'rem');
    row.setAttribute('role', 'treeitem');
    row.setAttribute('tabindex', '-1');
    row.setAttribute('aria-level', String(depth + 1));
    row.setAttribute('aria-setsize', String(setSize));
    row.setAttribute('aria-posinset', String(posInSet));
    if (hasChildren) {
      row.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
    }

    const linesHtml = buildTreeRowLines(depth, hasMoreBelow, isLast, hasChildren, isExpanded);

    const currentWs = (typeof window.APP_WORKSPACE !== 'undefined' && window.APP_WORKSPACE) ? String(window.APP_WORKSPACE).trim() : '';
    const wsBadge = currentWs ? '<span class="folder-item-workspace badge badge-secondary ml-1">' + currentWs.replace(/</g, '&lt;') + '</span>' : '';
    const linkHtml = c
      ? `<a href="#" class="list-group-item list-group-item-action folder-list-item folder-tree-item">
          <div class="d-flex w-100 folder-item-inner">
            <div class="folder-item-avatar-wrap">${avatarHtml}</div>
            <div class="folder-item-body">
              <div class="folder-item-head">
                <h6 class="folder-item-title">${nombre.replace(/</g, '&lt;')}</h6>
                <span class="folder-item-meta">
                  <span class="folder-item-count">${total} imágenes</span>
                  ${pend === 0 ? '<span class="folder-item-status folder-item-status-ok">Procesado</span>' : `<span class="folder-item-status folder-item-status-pend">${pend} pendientes</span>`}
                  ${wsBadge}
                </span>
              </div>
              ${showPath ? `<div class="folder-item-path">${ruta.replace(/</g, '&lt;')}</div>` : ''}
              ${tagBadges || moreTags ? `<div class="folder-item-tags">${tagBadges}${moreTags}</div>` : ''}
            </div>
          </div>
        </a>`
      : `<div class="folder-tree-item folder-tree-item-dir">
          <div class="d-flex w-100 folder-item-inner">
            <div class="folder-item-avatar-wrap"><span class="folder-item-avatar folder-item-avatar-placeholder"><i class="fas fa-folder"></i></span></div>
            <div class="folder-item-body">
              <div class="folder-item-head">
                <h6 class="folder-item-title">${nombre.replace(/</g, '&lt;')}</h6>
              </div>
            </div>
          </div>
        </div>`;

    row.innerHTML = `<div class="folder-tree-lines">${linesHtml}</div><div class="folder-tree-content">${linkHtml}</div>`;
    const toggleBtn = row.querySelector('.folder-tree-toggle');
    const link = row.querySelector('a');

    if (toggleBtn && hasChildren) {
      toggleBtn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (folderTreeCollapsed.has(node.ruta)) {
          folderTreeCollapsed.delete(node.ruta);
        } else {
          folderTreeCollapsed.add(node.ruta);
        }
        renderCarpetas(lastCarpetasData || []);
      });
    }
    if (link && c) {
      link.addEventListener('click', async (e) => {
        e.preventDefault();
        await abrirCarpeta(nombre, ruta);
      });
    }

    parentEl.appendChild(row);

    if (hasChildren && isExpanded) {
      const group = document.createElement('ul');
      group.setAttribute('role', 'group');
      group.className = 'folder-tree-group';
      row.appendChild(group);
      const children = node.children;
      const nextHasMoreBelow = [...hasMoreBelow, false];
      for (let i = 0; i < children.length; i++) {
        nextHasMoreBelow[depth] = i < children.length - 1;
        renderFolderNode(children[i], depth + 1, i === children.length - 1, nextHasMoreBelow, children.length, i + 1, group);
      }
    }
  }

  let lastCarpetasData = [];

  /** Renderiza la lista de carpetas como ítems planos (sin estructura de árbol ni líneas). */
  function renderCarpetas(items) {
    if (!lstCarpetas) return;
    lstCarpetas.innerHTML = '';
    lastCarpetasData = Array.isArray(items) ? items : [];
    const arr = lastCarpetasData;
    if (!arr.length) {
      lstCarpetas.removeAttribute('role');
      lstCarpetas.removeAttribute('aria-label');
      const div = document.createElement('div');
      div.className = 'buscador-empty';
      div.textContent = 'Sin resultados';
      lstCarpetas.appendChild(div);
      return;
    }
    lstCarpetas.removeAttribute('role');
    lstCarpetas.removeAttribute('aria-label');
    for (let i = 0; i < arr.length; i++) {
      const c = arr[i];
      const nombre = String(c?.nombre || c?.ruta || '').trim();
      const ruta = String(c?.ruta || '').trim();
      const total = Number(c?.total_archivos || 0);
      const pend = Number(c?.pendientes || 0);
      const tags = Array.isArray(c?.tags) ? c.tags : [];
      const avatarUrl = c?.avatar_url || null;
      const tagBadges = tags.slice(0, FOLDER_TREE_MAX_TAGS_VISIBLE).map((t) => {
        const lab = String(t?.label || '').trim();
        const cnt = Number(t?.count || 0);
        if (!lab) return '';
        const fullText = tagLabelToFullText(lab);
        return `<span class="folder-tag"><span class="folder-tag-label">${fullText.replace(/</g, '&lt;')}</span> <b class="folder-tag-count">${cnt}</b></span>`;
      }).join('');
      const moreTags = tags.length > FOLDER_TREE_MAX_TAGS_VISIBLE ? `<span class="folder-tag folder-tag-more">+${tags.length - FOLDER_TREE_MAX_TAGS_VISIBLE} más</span>` : '';
      const showPath = ruta && ruta !== nombre;
      const avatarHtml = avatarUrl
        ? `<img src="${avatarUrl.replace(/"/g, '&quot;')}" alt="" class="folder-item-avatar" loading="lazy">`
        : '<span class="folder-item-avatar folder-item-avatar-placeholder" aria-hidden="true"><i class="fas fa-folder"></i></span>';
      const currentWs = (typeof window.APP_WORKSPACE !== 'undefined' && window.APP_WORKSPACE) ? String(window.APP_WORKSPACE).trim() : '';
      const wsBadge = currentWs ? '<span class="folder-item-workspace badge badge-secondary ml-1">' + currentWs.replace(/</g, '&lt;') + '</span>' : '';
      const linkHtml = `<a href="#" class="list-group-item list-group-item-action folder-list-item">
          <div class="d-flex w-100 folder-item-inner">
            <div class="folder-item-avatar-wrap">${avatarHtml}</div>
            <div class="folder-item-body">
              <div class="folder-item-head">
                <h6 class="folder-item-title">${nombre.replace(/</g, '&lt;')}</h6>
                <span class="folder-item-meta">
                  <span class="folder-item-count">${total} imágenes</span>
                  ${pend === 0 ? '<span class="folder-item-status folder-item-status-ok">Procesado</span>' : `<span class="folder-item-status folder-item-status-pend">${pend} pendientes</span>`}
                  ${wsBadge}
                </span>
              </div>
              ${showPath ? `<div class="folder-item-path">${ruta.replace(/</g, '&lt;')}</div>` : ''}
              ${tagBadges || moreTags ? `<div class="folder-item-tags">${tagBadges}${moreTags}</div>` : ''}
            </div>
          </div>
        </a>`;
      const wrap = document.createElement('div');
      wrap.innerHTML = linkHtml;
      const link = wrap.querySelector('a');
      link.addEventListener('click', async (e) => {
        e.preventDefault();
        await abrirCarpeta(nombre, ruta);
      });
      lstCarpetas.appendChild(link);
    }
  }

  function handleFolderTreeKeydown(e) {
    if (!lstCarpetas || lstCarpetas.getAttribute('role') !== 'tree') return;
    const row = document.activeElement && document.activeElement.closest('[role="treeitem"]');
    if (!row || !lstCarpetas.contains(row)) return;
    const items = Array.from(lstCarpetas.querySelectorAll('[role="treeitem"]'));
    const idx = items.indexOf(row);
    if (idx < 0) return;
    const depth = parseInt(row.getAttribute('data-depth'), 10) || 0;
    const expanded = row.getAttribute('aria-expanded');
    const hasChildren = expanded !== null;

    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault();
        if (items[idx + 1]) items[idx + 1].focus();
        break;
      case 'ArrowUp':
        e.preventDefault();
        if (items[idx - 1]) items[idx - 1].focus();
        break;
      case 'ArrowRight':
        e.preventDefault();
        if (hasChildren && expanded === 'false') {
          const btn = row.querySelector('.folder-tree-toggle');
          if (btn) btn.click();
        } else if (hasChildren && expanded === 'true') {
          const nextDepth = depth + 1;
          for (let j = idx + 1; j < items.length; j++) {
            const d = parseInt(items[j].getAttribute('data-depth'), 10) || 0;
            if (d === nextDepth) { items[j].focus(); break; }
            if (d <= depth) break;
          }
        }
        break;
      case 'ArrowLeft':
        e.preventDefault();
        if (hasChildren && expanded === 'true') {
          const btn = row.querySelector('.folder-tree-toggle');
          if (btn) btn.click();
        } else {
          const parentDepth = depth - 1;
          for (let j = idx - 1; j >= 0; j--) {
            const d = parseInt(items[j].getAttribute('data-depth'), 10) || 0;
            if (d === parentDepth) { items[j].focus(); break; }
          }
        }
        break;
      case 'Enter':
        e.preventDefault();
        const link = row.querySelector('.folder-tree-content a[href="#"]');
        if (link) link.click();
        break;
      default:
        break;
    }
  }

  if (lstCarpetas) {
    lstCarpetas.addEventListener('keydown', handleFolderTreeKeydown);
  }

  function mostrarCarpetasConsultando() {
    if (!lstCarpetas) return;
    lstCarpetas.innerHTML = '<div class="text-muted py-3 text-center"><i class="fas fa-spinner fa-spin mr-1"></i> Consultando…</div>';
  }

  async function buscarCarpetas(bypassMinLength = false) {
    const q = String(txtBuscarCarpeta?.value || '').trim();
    const MIN_CARACTERES = 3;
    if (!bypassMinLength && q.length < MIN_CARACTERES) {
      setStatus(stBuscarCarpeta, 'neutral', 'Mín. 3 caracteres o pulsa Buscar');
      renderCarpetas([]);
      return;
    }
    if (typeof expandirBuscador === 'function') expandirBuscador('carpetas');
    setStatus(stBuscarCarpeta, 'neutral', q ? 'Buscando…' : 'Cargando carpetas…');
    mostrarCarpetasConsultando();
    const { ok, data } = await getJson(`?action=buscar_carpetas&q=${encodeURIComponent(q)}`);
    if (!ok || !data?.success) {
      setStatus(stBuscarCarpeta, 'bad', String(data?.error || 'Error'));
      renderCarpetas([]);
      return;
    }
    setStatus(stBuscarCarpeta, 'ok', `Total: ${data.total || 0}`);
    renderCarpetas(data.carpetas || []);
  }

  async function abrirCarpeta(nombre, ruta, selectedImageRutaRelativa) {
    return window.BuscadorModals && window.BuscadorModals.openFolder(nombre, ruta, selectedImageRutaRelativa || null, window.APP_WORKSPACE);
  }

  async function abrirVisor(ruta, archivo, rutaRelativa) {
    return window.BuscadorModals && window.BuscadorModals.openVisor(ruta, archivo, rutaRelativa, window.APP_WORKSPACE);
  }

  async function abrirCarpetaStacked(nombre, ruta, selectedImageRutaRelativa) {
    return window.BuscadorModals && window.BuscadorModals.openFolderStacked(nombre, ruta, selectedImageRutaRelativa || null, window.APP_WORKSPACE);
  }

  function renderGridSoloResultadosEtiq(items) {
    if (!gridThumbs) return;
    gridThumbs.innerHTML = '';
    const arr = Array.isArray(items) ? items : [];
    for (const it of arr) {
      const rutaRel = String(it?.ruta_relativa || '').trim();
      const rutaCarpeta = String(it?.ruta_carpeta || '').trim();
      const archivo = String(it?.archivo || '').trim();
      const matches = Array.isArray(it?.matches) ? it.matches : [];
      const tagLabels = matches.map((m) => String(m?.label || '').trim()).filter(Boolean);

      const col = document.createElement('div');
      col.className = 'col-6 col-sm-4 col-md-3 col-lg-2 mb-3';
      col.dataset.rutaRelativa = rutaRel;
      const card = document.createElement('div');
      card.className = 'thumb-card';
      const a = document.createElement('a');
      a.href = '#';
      const imgWrap = document.createElement('div');
      imgWrap.className = 'thumb-card-img';
      const img = document.createElement('img');
      img.alt = archivo;
      img.loading = 'lazy';
      const thumbUrlEtiq = appendWorkspace(`?action=ver_imagen&ruta=${encodeURIComponent(rutaCarpeta)}&archivo=${encodeURIComponent(archivo)}&thumb=1&w=240`);
      imgWrap.appendChild(img);
      a.appendChild(imgWrap);
      const body = document.createElement('div');
      body.className = 'thumb-card-body';
      const title = document.createElement('div');
      title.className = 'thumb-card-title';
      title.textContent = archivo || '—';
      title.title = archivo || '';
      body.appendChild(title);
      if (tagLabels.length > 0) {
        const tagRow = document.createElement('div');
        tagRow.className = 'thumb-card-tags';
        for (const lab of tagLabels.slice(0, 5)) {
          const chip = document.createElement('span');
          chip.className = 'tag-chip-inline';
          chip.textContent = tagLabelToFullText(lab).replace(/</g, '\u200b');
          tagRow.appendChild(chip);
        }
        if (tagLabels.length > 5) {
          const more = document.createElement('span');
          more.className = 'tag-chip-inline';
          more.textContent = '+' + (tagLabels.length - 5);
          tagRow.appendChild(more);
        }
        body.appendChild(tagRow);
      }
      a.appendChild(body);
      a.addEventListener('click', async (e) => {
        e.preventDefault();
        await abrirVisor(rutaCarpeta, archivo, rutaRel);
      });
      card.appendChild(a);
      col.appendChild(card);
      gridThumbs.appendChild(col);
      if (window.BuscadorModals && typeof window.BuscadorModals.scheduleThumbLoad === 'function') {
        window.BuscadorModals.scheduleThumbLoad(col, card, img, thumbUrlEtiq);
      } else if (window.BuscadorModals && typeof window.BuscadorModals.loadThumbWithNewBadge === 'function') {
        window.BuscadorModals.loadThumbWithNewBadge(img, thumbUrlEtiq, card);
      } else {
        img.src = thumbUrlEtiq;
      }
    }
  }

  function abrirModalSoloResultadosEtiq() {
    if (!lastResultadosEtiq.length) return;
    if (ttlCarpeta) ttlCarpeta.textContent = 'Resultados de búsqueda (' + lastResultadosEtiq.length + ')';
    if (tagsCarpeta) tagsCarpeta.innerHTML = '';
    if (gridThumbs) gridThumbs.innerHTML = '';
    if (window.jQuery) window.jQuery('#modalCarpeta').modal('show');
    renderGridSoloResultadosEtiq(lastResultadosEtiq);
  }

  const IMAGE_EXTENSIONS = /\.(jpe?g|png|gif|webp|bmp|tiff?|avif|heic|heif|ico|svg)$/i;

  async function uploadFolder() {
    const allFiles = inpFolder?.files ? Array.from(inpFolder.files) : [];
    if (!allFiles.length) {
      setStatus(stUpload, 'bad', 'Selecciona una carpeta');
      return;
    }
    // Solo subir imágenes; descartar videos y demás (respeta estructura recursiva de las imágenes)
    const files = allFiles.filter((f) => {
      const name = (f && f.name) ? String(f.name) : '';
      return IMAGE_EXTENSIONS.test(name);
    });
    const discarded = allFiles.length - files.length;

    // Nombre de la carpeta seleccionada: webkitRelativePath es relativo A esa carpeta (sin incluirla)
    let basePath = '';
    let rawPath = (inpFolder && inpFolder.value) ? String(inpFolder.value).replace(/\\/g, '/') : '';
    rawPath = rawPath.replace(/\/+$/, '').trim();
    if (rawPath) {
      const lastSlash = rawPath.lastIndexOf('/');
      const folderName = (lastSlash >= 0 ? rawPath.substring(lastSlash + 1) : rawPath).trim();
      const looksLikeFile = IMAGE_EXTENSIONS.test(folderName);
      if (folderName && folderName !== 'fakepath' && !looksLikeFile) basePath = folderName + '/';
    }

    if (!files.length) {
      setStatus(stUpload, 'bad', 'No hay imágenes en la carpeta (solo se suben imágenes; videos y otros se descartan)');
      return;
    }
    setStatus(stUpload, 'neutral', discarded > 0
      ? `Subiendo ${files.length} imágenes (${discarded} archivos no imagen descartados)…`
      : `Subiendo ${files.length} archivos…`);
    if (barUpload) { barUpload.dataset.width = '0'; barUpload.style.width = '0%'; }
    if (uploadProcesando) {
      uploadProcesando.style.display = '';
      uploadProcesando.textContent = 'Procesando…';
    }
    if (esperePorFavor) esperePorFavor.style.display = 'flex';

    const batch = 10;
    let done = 0;
    let uploaded = 0;
    let skipped = 0;
    let errors = 0;
    let aborted = false;

    for (let i = 0; i < files.length; i += batch) {
      const chunk = files.slice(i, i + batch);
      const fd = new FormData();
      for (const f of chunk) {
        const rel = (f && f.webkitRelativePath) ? String(f.webkitRelativePath).trim() : (f?.name || 'file');
        const name = basePath ? (basePath + rel) : rel;
        fd.append('files[]', f, name);
        fd.append('paths[]', name);
      }
      try {
        const resp = await fetch(appendWorkspace('?action=upload_imagenes'), {
          method: 'POST',
          headers: { 'accept': 'application/json' },
          body: fd
        });
        const text = await resp.text();
        const data = (() => { try { return JSON.parse(text || '{}'); } catch (e) { return {}; } })();

        if (!resp.ok || !data?.success) {
          const msg =
            (data && (data.error || data.message)) ? String(data.error || data.message) :
            `HTTP ${resp.status}`;
          // Error "duro" (por ejemplo 413 / 409) -> abortar para que el usuario lo vea.
          setStatus(stUpload, 'bad', `Error subiendo: ${msg}`);
          errors += chunk.length;
          aborted = true;
          break;
        }

        uploaded += Number(data.uploaded || 0);
        skipped += Number(data.skipped_md5 || 0) + Number(data.skipped_invalid || 0);
        errors += Number(data.errors || 0);
      } catch (e) {
        // Error de red (ej: Failed to fetch / conexión caída)
        setStatus(stUpload, 'bad', `Error subiendo: ${e?.message ? String(e.message) : 'Error de red'}`);
        errors += chunk.length;
        aborted = true;
        break;
      }
      done += chunk.length;
      const p = Math.round((done / files.length) * 100);
      if (barUpload) { barUpload.dataset.width = String(p); barUpload.style.width = p + '%'; }
      if (uploadProcesando) uploadProcesando.textContent = `Procesando… ${done}/${files.length}`;
      setStatus(stUpload, 'neutral', `Procesados: ${done}/${files.length} · subidos: ${uploaded} · omitidos: ${skipped} · errores: ${errors}`);
    }

    if (uploadProcesando) {
      uploadProcesando.style.display = 'none';
      uploadProcesando.textContent = '';
    }
    if (esperePorFavor) esperePorFavor.style.display = 'none';
    if (aborted) {
      // Mantener el último error mostrado en pantalla.
    } else if (errors > 0) {
      setStatus(stUpload, 'bad', `Finalizado con errores. Subidos: ${uploaded} · omitidos: ${skipped} · errores: ${errors}`);
    } else {
      setStatus(stUpload, 'ok', `Finalizado. Subidos: ${uploaded} · omitidos: ${skipped}`);
    }

    await refreshStats();
    await refreshLogPanel();
    await buscarCarpetas(true);
  }

  // Wire events
  if (btnReindex) btnReindex.addEventListener('click', reindexAll);

  const btnClasificarModeracion = document.getElementById('btnClasificarModeracion');
  const stModeracion = document.getElementById('stModeracion');
  function setStModeracion(msg) {
    if (stModeracion) stModeracion.textContent = msg || '';
  }
  if (btnClasificarModeracion) {
    btnClasificarModeracion.addEventListener('click', function () {
      if (!window.APP_WORKSPACE || !indexWorker) {
        if (stModeracion) {
          if (!window.APP_WORKSPACE) stModeracion.textContent = 'Entra desde una card de workspace (botón Entrar) para usar moderación.';
          else stModeracion.textContent = 'El worker de procesamiento no está disponible.';
        }
        return;
      }
      if (clasificarModeracionRunning) {
        clasificarModeracionRunning = false;
        const t = btnClasificarModeracion.querySelector('.btn-accion-text');
        if (t) t.textContent = 'Clasificar moderación';
        if (stModeracion) stModeracion.textContent = 'Detenido.';
        indexWorker.postMessage({ type: 'remove', mode: 'classify', ws: window.APP_WORKSPACE });
        return;
      }
      clasificarModeracionRunning = true;
      const t = btnClasificarModeracion.querySelector('.btn-accion-text');
      if (t) t.textContent = 'Detener moderación';
      if (stModeracion) stModeracion.textContent = 'Clasificando…';
      indexWorker.postMessage({ type: 'add', mode: 'classify', ws: window.APP_WORKSPACE });
    });
  }
  if (stModeracion && window.APP_WORKSPACE && indexWorker) setStModeracion('');

  // Búsqueda de carpetas: automática solo con mínimo 3 caracteres; botón "Buscar" para búsqueda bajo demanda (sin mínimo)
  const MIN_CARACTERES_AUTO = 3;
  let buscarCarpetaTimer = null;
  if (txtBuscarCarpeta) {
    txtBuscarCarpeta.addEventListener('input', () => {
      if (buscarCarpetaTimer) clearTimeout(buscarCarpetaTimer);
      const q = String(txtBuscarCarpeta.value || '').trim();
      if (q.length >= MIN_CARACTERES_AUTO) {
        buscarCarpetaTimer = setTimeout(() => buscarCarpetas(false), 450);
      } else {
        buscarCarpetaTimer = null;
        renderCarpetas([]);
      }
    });
    txtBuscarCarpeta.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        if (buscarCarpetaTimer) clearTimeout(buscarCarpetaTimer);
        buscarCarpetaTimer = null;
        const q = String(txtBuscarCarpeta.value || '').trim();
        if (q.length >= MIN_CARACTERES_AUTO) buscarCarpetas(false);
      }
    });
  }
  const btnBuscarCarpeta = document.getElementById('btnBuscarCarpeta');
  if (btnBuscarCarpeta) btnBuscarCarpeta.addEventListener('click', () => buscarCarpetas(true));

  // Expandir un buscador y colapsar el otro (solo uno expandido a la vez)
  const acordeon = document.getElementById('buscadorAcordeon');
  function expandirBuscador(quien) {
    if (!acordeon) return;
    acordeon.classList.remove('expanded-etiquetas', 'expanded-carpetas', 'expanded-moderacion');
    if (quien === 'etiquetas') acordeon.classList.add('expanded-etiquetas');
    if (quien === 'carpetas') acordeon.classList.add('expanded-carpetas');
    if (quien === 'moderacion') acordeon.classList.add('expanded-moderacion');
  }

  document.querySelectorAll('#buscadorAcordeon .acordeon-item').forEach((item) => {
    const quien = item.getAttribute('data-acordeon');
    const header = item.querySelector('.acordeon-header');
    if (header && quien) {
      header.addEventListener('click', () => {
        expandirBuscador(quien);
        if (quien === 'moderacion' && typeof loadEtiquetasModeracion === 'function') loadEtiquetasModeracion();
      });
    }
    item.addEventListener('focusin', () => { if (quien) expandirBuscador(quien); });
  });
  if (txtBuscarCarpeta) txtBuscarCarpeta.addEventListener('focus', () => expandirBuscador('carpetas'));

  // Filtrar por moderación: etiquetas clicables
  const lstEtiquetasModeracion = document.getElementById('lstEtiquetasModeracion');
  const stModeracionBuscar = document.getElementById('stModeracionBuscar');
  const gridResultadosModeracion = document.getElementById('gridResultadosModeracion');
  const selectedModTags = new Set();
  const paginacionModeracion = document.getElementById('paginacionModeracion');
  const stPaginacionModeracion = document.getElementById('stPaginacionModeracion');
  const btnModeracionPrev = document.getElementById('btnModeracionPrev');
  const btnModeracionNext = document.getElementById('btnModeracionNext');
  let modCurrentPage = 1;
  const PER_PAGE_MOD = 60;

  function renderModerationResultsInline(imagenes) {
    if (!gridResultadosModeracion) return;
    gridResultadosModeracion.innerHTML = '';
    if (!Array.isArray(imagenes) || imagenes.length === 0) return;
    const ws = typeof window.APP_WORKSPACE === 'string' ? window.APP_WORKSPACE : null;
    for (let i = 0; i < imagenes.length; i++) {
      const img = imagenes[i];
      const nombre = String(img.filename || img.archivo || '').trim() || '—';
      const rutaRel = String(img.relative_path || '').trim();
      const folderPath = String(img.folder_path || '').trim();
      const modLabels = Array.isArray(img.moderation_labels) ? img.moderation_labels : [];
      const tagLabels = modLabels.map(function (ml) {
        const mn = (ml && ml.name) ? String(ml.name).trim() : '';
        const conf = ml && ml.confidence != null ? Number(ml.confidence).toFixed(0) + '%' : '';
        return mn ? (mn + (conf ? ' ' + conf : '')) : '';
      }).filter(Boolean);

      const col = document.createElement('div');
      col.className = 'col-6 col-sm-4 col-md-3 col-lg-2 mb-3';
      const card = document.createElement('div');
      card.className = 'thumb-card';
      const a = document.createElement('a');
      a.href = '#';
      a.addEventListener('click', function (e) {
        e.preventDefault();
        if (window.BuscadorModals && window.BuscadorModals.openVisor) {
          window.BuscadorModals.openVisor(folderPath, nombre, rutaRel, ws);
        }
      });
      const imgWrap = document.createElement('div');
      imgWrap.className = 'thumb-card-img';
      const thumbImg = document.createElement('img');
      thumbImg.alt = nombre;
      thumbImg.loading = 'lazy';
      const thumbUrl = appendWorkspace('?action=ver_imagen&ruta=' + encodeURIComponent(folderPath) + '&archivo=' + encodeURIComponent(nombre) + '&thumb=1&w=240');
      thumbImg.src = thumbUrl;
      imgWrap.appendChild(thumbImg);
      a.appendChild(imgWrap);
      const body = document.createElement('div');
      body.className = 'thumb-card-body';
      const title = document.createElement('div');
      title.className = 'thumb-card-title';
      title.textContent = nombre.length > 28 ? nombre.substring(0, 25) + '…' : nombre;
      title.title = nombre;
      body.appendChild(title);
      if (tagLabels.length > 0) {
        const tagRow = document.createElement('div');
        tagRow.className = 'thumb-card-tags';
        for (let k = 0; k < Math.min(3, tagLabels.length); k++) {
          const chip = document.createElement('span');
          chip.className = 'tag-chip-inline';
          chip.textContent = tagLabels[k].replace(/</g, '\u200b');
          tagRow.appendChild(chip);
        }
        if (tagLabels.length > 3) {
          const more = document.createElement('span');
          more.className = 'tag-chip-inline';
          more.textContent = '+' + (tagLabels.length - 3);
          tagRow.appendChild(more);
        }
        body.appendChild(tagRow);
      }
      a.appendChild(body);
      card.appendChild(a);
      col.appendChild(card);
      gridResultadosModeracion.appendChild(col);
    }
  }

  async function runBuscarModeracion(page) {
    if (selectedModTags.size === 0) return;
    const tagsParam = Array.from(selectedModTags).join(',');
    const urlBuscar = appendWorkspace('?action=buscar_por_moderacion&tags=' + encodeURIComponent(tagsParam) + '&page=' + page + '&per_page=' + PER_PAGE_MOD);
    if (stModeracionBuscar) stModeracionBuscar.textContent = 'Buscando…';
    if (gridResultadosModeracion) gridResultadosModeracion.innerHTML = '';
    if (paginacionModeracion) paginacionModeracion.classList.add('d-none');
    const res = await getJsonRaw(urlBuscar);
    if (stModeracionBuscar) stModeracionBuscar.textContent = '';
    if (!res.ok || !res.data?.success) {
      if (stModeracionBuscar) stModeracionBuscar.textContent = res.data?.error || 'Error';
      return;
    }
    const imagenes = res.data.imagenes || [];
    const total = res.data.total ?? 0;
    const totalPages = res.data.total_pages ?? 1;
    const currentPage = res.data.page ?? 1;
    modCurrentPage = currentPage;
    if (stModeracionBuscar) stModeracionBuscar.textContent = total + ' imagen(es)' + (totalPages > 1 ? ' · Página ' + currentPage + ' de ' + totalPages : '');
    renderModerationResultsInline(imagenes);
    if (paginacionModeracion && stPaginacionModeracion && btnModeracionPrev && btnModeracionNext) {
      if (totalPages <= 1) {
        paginacionModeracion.classList.add('d-none');
      } else {
        paginacionModeracion.classList.remove('d-none');
        stPaginacionModeracion.textContent = 'Página ' + currentPage + ' de ' + totalPages;
        btnModeracionPrev.disabled = currentPage <= 1;
        btnModeracionNext.disabled = currentPage >= totalPages;
      }
    }
  }

  async function loadEtiquetasModeracion() {
    if (!lstEtiquetasModeracion) return;
    lstEtiquetasModeracion.innerHTML = '<span class="text-muted small">Cargando etiquetas…</span>';
    const url = appendWorkspace('?action=etiquetas_moderacion');
    const { ok, data } = await getJsonRaw(url);
    if (!ok || !data?.success) {
      const msg = (data?.error && (data.error.indexOf('workspace') !== -1 || data.error.indexOf('activo') !== -1)) ? 'Entra a un workspace (botón Entrar) para ver etiquetas de moderación.' : (data?.error || 'Sin etiquetas. Ejecuta "Clasificar moderación" primero.');
      lstEtiquetasModeracion.innerHTML = '<span class="text-muted small">' + String(msg).replace(/</g, '&lt;') + '</span>';
      return;
    }
    const etiquetasArray = Array.isArray(data.etiquetas) ? data.etiquetas : [];
    if (etiquetasArray.length === 0) {
      lstEtiquetasModeracion.innerHTML = '<span class="text-muted small">Sin etiquetas aún. Ejecuta "Clasificar moderación" primero.</span>';
      return;
    }
    lstEtiquetasModeracion.innerHTML = '';
    const etiquetas = etiquetasArray;
    for (let i = 0; i < etiquetas.length; i++) {
      const et = etiquetas[i];
      const name = String(et.label_name || '').trim();
      if (!name) continue;
      const level = Number(et.taxonomy_level);
      const count = Number(et.count) || 0;
      const chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'btn btn-sm mr-1 mb-1 tag-chip-moderation' + (selectedModTags.has(name) ? ' tag-chip-active' : '');
      chip.textContent = 'Nivel ' + level + ': ' + name + ' (' + count + ')';
      chip.dataset.labelName = name;
      chip.addEventListener('click', async () => {
        if (selectedModTags.has(name)) selectedModTags.delete(name);
        else selectedModTags.add(name);
        chip.classList.toggle('tag-chip-active', selectedModTags.has(name));
        if (selectedModTags.size === 0) {
          if (stModeracionBuscar) stModeracionBuscar.textContent = 'Selecciona una o más etiquetas para filtrar.';
          if (gridResultadosModeracion) gridResultadosModeracion.innerHTML = '';
          if (paginacionModeracion) paginacionModeracion.classList.add('d-none');
          return;
        }
        modCurrentPage = 1;
        runBuscarModeracion(1);
      });
      lstEtiquetasModeracion.appendChild(chip);
    }
    if (lstEtiquetasModeracion.children.length === 0) {
      lstEtiquetasModeracion.innerHTML = '<span class="text-muted small">Sin etiquetas. Clasifica primero.</span>';
    }
  }
  if (btnModeracionPrev) btnModeracionPrev.addEventListener('click', () => { if (modCurrentPage > 1) runBuscarModeracion(modCurrentPage - 1); });
  if (btnModeracionNext) btnModeracionNext.addEventListener('click', () => { runBuscarModeracion(modCurrentPage + 1); });
  if (lstEtiquetasModeracion) loadEtiquetasModeracion();

  const uploadFolderName = el('uploadFolderName');
  if (inpFolder && uploadFolderName) {
    inpFolder.addEventListener('change', () => {
      const allFiles = inpFolder.files ? Array.from(inpFolder.files) : [];
      const raw = (inpFolder.value || '').replace(/\\/g, '/');
      const files = allFiles.filter((f) => {
        const name = (f && f.name) ? String(f.name) : '';
        return IMAGE_EXTENSIONS.test(name);
      });
      let text = 'Solo imágenes; estructura recursiva respetada. Videos y otros se descartan.';
      if (allFiles.length > 0) {
        const lastSlash = raw.lastIndexOf('/');
        const folderName = (lastSlash >= 0 ? raw.substring(lastSlash + 1) : raw).trim() || 'carpeta';
        const displayName = folderName.replace(/^.*[\\/]/, '') || folderName;
        text = `${displayName} · ${files.length} imagen(es)`;
        uploadFolderName.textContent = text;
        if (files.length === 0) {
          setStatus(stUpload, 'bad', 'No hay imágenes en la carpeta (solo se suben imágenes)');
          return;
        }
        uploadFolder();
      } else {
        uploadFolderName.textContent = text;
      }
    });
  }

  // Visor "Ir a carpeta", canvas y resize están cableados en BuscadorModals.init()

  // Tablas auto (solo si existe)
  const btnDescargarRegistros = document.getElementById('btnDescargarRegistros');
  const stTablas = document.getElementById('stTablas');

  const lstTablas = document.getElementById('lstTablas');

  function renderTablasEstado(estado) {
    if (!lstTablas || !estado || typeof estado !== 'object') return;
    const tablas = Object.keys(estado);
    // Mismo orden que el servidor: pendientes primero, luego por max_id ascendente (tabla más pequeña primero), luego por nombre
    tablas.sort((a, b) => {
      const ea = estado[a] || {};
      const eb = estado[b] || {};
      const fa = !!ea.faltan_registros;
      const fb = !!eb.faltan_registros;
      if (fa !== fb) return fa ? -1 : 1;
      const ma = parseInt(ea.max_id, 10) || 0;
      const mb = parseInt(eb.max_id, 10) || 0;
      if (ma !== mb) return ma - mb;
      return String(a).localeCompare(String(b));
    });
    lstTablas.innerHTML = '';
    if (tablas.length === 0) {
      lstTablas.innerHTML = '<div class="list-group-item"><div class="text-muted">No hay estado de tablas todavía. Revisa la parametrización y/o el patrón.</div></div>';
      return;
    }
    tablas.forEach((t) => {
      const e = estado[t] || {};
      const ultimo = parseInt(e.ultimo_id, 10) || 0;
      const max = parseInt(e.max_id, 10) || 0;
      const faltan = !!e.faltan_registros;
      const p = max ? Math.round((ultimo / max) * 100) : 0;
      const item = document.createElement('div');
      item.className = 'list-group-item';
      item.dataset.tabla = t;
      item.innerHTML = `
        <div class="d-flex w-100 justify-content-between">
          <h6 class="mb-1 text-monospace">${String(t).replace(/</g, '&lt;')}</h6>
          <small><span class="badge ${faltan ? 'badge-warning' : 'badge-success'}">${faltan ? 'Pendiente' : 'Completada'}</span></small>
        </div>
        <div class="small text-muted mb-1">Último: ${ultimo} · Máx: ${max}</div>
        <div class="progress progress-sm"><div class="progress-bar bg-info tblProgress" data-width="${p}" style="width:${p}%"></div></div>
      `;
      lstTablas.appendChild(item);
    });
  }

  function marcarPrimeraTablaProcesando() {
    if (!lstTablas) return;
    // Quitar "Procesando" de cualquier fila que lo tenga (solo debe haber una a la vez)
    lstTablas.querySelectorAll('.list-group-item.table-row-processing').forEach((row) => {
      row.classList.remove('table-row-processing');
      const badge = row.querySelector('.badge-info');
      if (badge) {
        badge.classList.remove('badge-info');
        badge.classList.add('badge-warning');
        badge.textContent = 'Pendiente';
      }
    });
    // Marcar solo la primera fila pendiente
    const firstPending = lstTablas.querySelector('.list-group-item .badge-warning');
    if (firstPending) {
      const row = firstPending.closest('.list-group-item');
      if (row) {
        row.classList.add('table-row-processing');
        firstPending.classList.remove('badge-warning');
        firstPending.classList.add('badge-info');
        firstPending.innerHTML = '<i class="fas fa-spinner fa-spin btn-icon mr-1"></i> Procesando…';
      }
    }
  }

  async function procesarTablaUna() {
    marcarPrimeraTablaProcesando();
    if (stTablas) setStatus(stTablas, 'neutral', 'Procesando…');
    const { ok, data } = await getJson('?action=procesar');
    if (data?.log_items) renderLogFromItems(data.log_items);
    else { await refreshLogPanel(); }
    if (!ok || !data?.success) {
      if (stTablas) setStatus(stTablas, 'bad', String(data?.error || 'Error'));
      if (data?.estado) renderTablasEstado(data.estado);
      return;
    }
    const msg = String(data?.mensaje || 'OK');
    if (stTablas) setStatus(stTablas, 'ok', msg);
    if (data?.estado) renderTablasEstado(data.estado);
    if (autoTablasRunning && data?.success && !data?.registro_procesado && !data?.faltan_registros) {
      setAutoTablas(false);
    }
  }

  async function autoLoopTablas() {
    while (autoTablasRunning) {
      try { await procesarTablaUna(); } catch (e) {}
      if (!autoTablasRunning) break;
      await new Promise(r => setTimeout(r, 200));
    }
  }

  function setBtnDescargarLabel(running) {
    if (!btnDescargarRegistros) return;
    const t = btnDescargarRegistros.querySelector('.btn-accion-text');
    if (t) t.textContent = running ? 'Detener descarga' : 'Descargar registros';
  }

  async function setAutoTablas(on) {
    if (!on) {
      autoTablasRunning = false;
      setBtnDescargarLabel(false);
      if (indexWorker && window.APP_WORKSPACE) indexWorker.postMessage({ type: 'remove', mode: 'download', ws: window.APP_WORKSPACE });
      stopIndexAutoStatsPolling();
      setStatus(stTablas, 'neutral', 'Detenido');
      await appendLog('info', 'Descarga detenida.');
      await refreshLogPanel();
      return;
    }
    autoTablasRunning = true;
    setBtnDescargarLabel(true);
    setStatus(stTablas, 'neutral', 'Ejecutando…');
    await appendLog('info', 'Descarga iniciada.');
    await refreshLogPanel();
    if (indexWorker && window.APP_WORKSPACE) {
      startIndexAutoStatsPolling();
      indexWorker.postMessage({ type: 'add', mode: 'download', ws: window.APP_WORKSPACE });
    } else {
      autoLoopTablas();
    }
  }

  if (btnDescargarRegistros) {
    btnDescargarRegistros.addEventListener('click', function () {
      if (!window.APP_WORKSPACE) {
        if (stTablas) stTablas.textContent = 'Entra desde una card de workspace (botón Entrar) para descargar.';
        return;
      }
      setAutoTablas(!autoTablasRunning);
    });
  }

  // Log de procesamiento (actualización solo en momentos clave, sin polling)
  const logPanel = document.getElementById('logPanel');
  const btnLogClear = document.getElementById('btnLogClear');

  async function appendLog(type, message) {
    try {
      await fetch(appendWorkspace('?action=log_append'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ type: type || 'info', message: String(message || '').trim() })
      });
    } catch (e) {}
  }

  function renderLogFromItems(items) {
    if (!logPanel) return;
    const arr = Array.isArray(items) ? items : [];
    if (arr.length === 0) {
      logPanel.innerHTML = '<div class="text-muted small">Sin entradas</div>';
      return;
    }
    logPanel.innerHTML = arr.map((e) => {
      const time = String(e.time || e.ts || '').slice(0, 8);
      const msg = String(e.message || '').replace(/</g, '&lt;');
      const type = String(e.type || 'info').toLowerCase();
      const cls = type === 'error' ? 'log-error' : (type === 'warning' ? 'log-warning' : (type === 'success' ? 'log-success' : ''));
      return `<div class="log-entry ${cls}"><span class="log-time">${time}</span><span class="log-msg">${msg}</span></div>`;
    }).join('');
    logPanel.scrollTop = logPanel.scrollHeight;
  }

  async function refreshLogPanel() {
    if (!logPanel) return;
    const { ok, data } = await getJson('?action=log_tail&limit=20');
    if (!ok || !data?.items) {
      logPanel.innerHTML = '<div class="text-muted small">Sin entradas</div>';
      return;
    }
    renderLogFromItems(data.items);
  }

  if (btnLogClear) {
    btnLogClear.addEventListener('click', async () => {
      try {
        await fetch(appendWorkspace('?action=log_clear'), { method: 'POST', headers: { 'accept': 'application/json' } });
        await refreshLogPanel();
      } catch (e) {}
    });
  }

  // Initial load
  (async () => {
    // Cargar indicadores en cuanto haya workspace (prioritario)
    refreshAccionesIndicadores();
    // Inicializar barras de progreso server-side (tablas)
    document.querySelectorAll('.tblProgress[data-width]').forEach((b) => {
      const p = Math.max(0, Math.min(100, Number(b.getAttribute('data-width') || '0')));
      b.style.width = p + '%';
    });
    await refreshStats();
    await refreshAccionesIndicadores();
    await refreshLogPanel();
    if (window.APP_AUTO === 'descargar') {
      setAutoTablas(true);
    }
    // Reintentar indicadores por si la primera llamada fue antes de tener contexto
    setTimeout(function () { refreshAccionesIndicadores(); }, 400);
  })();
</script>

