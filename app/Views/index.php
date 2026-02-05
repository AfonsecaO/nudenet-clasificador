<?php
/** @var string $mode */
/** @var string $pattern */
/** @var int $totalTablasEstado */
/** @var array $tablasDelEstado */
/** @var array $estadoProcesamiento */

$__title = 'Clasificador';
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
  <a href="?action=index" class="topnav-brand"><i class="fas fa-shield-alt"></i> Clasificador</a>
  <ul class="topnav-links">
    <li><a href="?action=index" class="active"><i class="fas fa-layer-group"></i> <?php echo $wsSlug ? h($wsSlug) : '—'; ?></a></li>
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
      <!-- Columna izquierda: Acciones + Clasificación -->
      <div class="col-lg-3 col-md-12 mb-3 col-clasif">
        <!-- Contenedor único con todos los botones organizados por título -->
        <div class="card card-acciones mb-3">
          <div class="card-header card-header-normalized">
            <h3 class="card-title"><i class="fas fa-bolt" aria-hidden="true"></i> Acciones</h3>
          </div>
          <div class="card-body acciones-card-body">
            <div class="acciones-block">
              <div class="acciones-block-header">
                <span class="acciones-block-title"><i class="fas fa-robot"></i> Clasificar imagenes</span>
                <div class="custom-control custom-switch toggle-auto" title="Auto clasificación">
                  <input type="checkbox" class="custom-control-input" id="switchAuto" autocomplete="off">
                  <label class="custom-control-label" for="switchAuto">Auto</label>
                </div>
              </div>
            </div>
            <div class="acciones-block acciones-section-tablas" id="accionesSectionTablas">
              <div class="acciones-block-header">
                <span class="acciones-block-title"><i class="fas fa-database"></i> Descargar registros</span>
                <div class="custom-control custom-switch toggle-auto" title="Auto tablas">
                  <input type="checkbox" class="custom-control-input" id="switchAutoTablas" autocomplete="off">
                  <label class="custom-control-label" for="switchAutoTablas">Auto</label>
                </div>
              </div>
            </div>
            <div class="acciones-block acciones-block-mantenimiento">
              <div class="acciones-block-header">
                <span class="acciones-block-title"><i class="fas fa-tools"></i> Mantenimiento</span>
              </div>
              <div class="acciones-block-actions">
                <button class="btn btn-accion-mant" id="btnReset" type="button">
                  <i class="fas fa-undo btn-icon"></i> Reset
                </button>
                <button class="btn btn-accion-mant btn-accion-mant-secondary" id="btnReindex" type="button">
                  <i class="fas fa-broom btn-icon"></i> Reindexar
                </button>
              </div>
            </div>
          </div>
        </div>

        <div class="card card-dashboard card-clasif card-clasif-fill">
          <div class="card-header card-header-normalized">
            <h3 class="card-title"><i class="fas fa-robot" aria-hidden="true"></i> Clasificación de imágenes</h3>
          </div>
          <div class="card-body clasif-card-body">
            <section class="clasif-progress-block" aria-label="Progreso de clasificación">
              <div class="clasif-progress-header">
                <span class="clasif-progress-title">Progreso</span>
                <span class="clasif-progress-count" aria-live="polite">
                  <b id="txtProcesadas">—</b><span class="clasif-progress-sep" aria-hidden="true">/</span><span id="txtTotal">—</span>
                </span>
              </div>
              <div class="clasif-bar-track" role="presentation">
                <div class="clasif-bar-fill" id="barProcesadas" data-width="0" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
              </div>
            </section>
            <section class="clasif-safe-unsafe" aria-label="Resultados Safe y Unsafe">
              <div class="clasif-safe-unsafe-inputs">
                <div class="clasif-input-wrap clasif-input-unsafe">
                  <input type="text" class="form-control form-control-sm clasif-input-pct" id="txtUnsafe" value="—" placeholder="Unsafe" readonly aria-label="Unsafe">
                </div>
                <div class="clasif-input-wrap clasif-input-safe">
                  <input type="text" class="form-control form-control-sm clasif-input-pct" id="txtSafe" value="—" placeholder="Safe" readonly aria-label="Safe">
                </div>
              </div>
            </section>
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

      <!-- Columna centro: Buscadores (ambos visibles; el que busca expande resultados automático) -->
      <div class="col-lg-6 col-md-6 mb-3 col-buscadores">
        <div class="card card-buscadores">
          <div class="buscador-acordeon expanded-carpetas" id="buscadorAcordeon">
            <div class="acordeon-item" data-acordeon="carpetas">
              <div class="acordeon-header">
                <span><i class="fas fa-folder-open"></i> Buscar carpetas</span>
              </div>
              <div class="acordeon-body">
                <div class="acordeon-body-inner">
                  <div class="form-group mb-2">
                    <div class="input-group">
                      <input type="text" class="form-control" id="txtBuscarCarpeta" placeholder="Escribe para buscar (mín. 3 caracteres)" autocomplete="off">
                      <div class="input-group-append">
                        <button type="button" class="btn btn-outline-secondary btn-buscar-carpetas" id="btnBuscarCarpetas" title="Buscar bajo demanda (sin mínimo de caracteres)"><i class="fas fa-search"></i> Buscar</button>
                      </div>
                    </div>
                  </div>
                  <div class="buscador-results-wrap">
                    <div class="list-group list-group-flex" id="lstCarpetas"></div>
                  </div>
                </div>
              </div>
            </div>
            <div class="acordeon-item" data-acordeon="etiquetas">
              <div class="acordeon-header">
                <span><i class="fas fa-tags"></i> Buscar imágenes por etiqueta</span>
              </div>
              <div class="acordeon-body">
                <div class="acordeon-body-inner buscador-etiquetas-inner">
                  <div class="buscador-umbral-wrap">
                    <div class="buscador-umbral-track-wrap">
                      <input type="range" class="custom-range buscador-umbral-slider" id="rngUmbral" min="0" max="100" step="1" value="80" aria-label="Umbral">
                      <span class="buscador-umbral-circle" id="lblUmbral" aria-hidden="true">80%</span>
                    </div>
                  </div>
                  <div class="buscador-tags-wrap">
                    <div id="tagsEtiquetas" class="tags-etiquetas d-flex flex-wrap"></div>
                    <div id="tagsEtiquetasEmpty" class="tags-etiquetas-empty" style="display: none;"></div>
                  </div>
                  <div class="buscador-results-wrap">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2 wrap-btn-abrir-resultados" id="wrapBtnAbrirResultados" style="display: none;">
                      <span class="small text-muted" id="txtCountResultadosEtiq"></span>
                      <button type="button" class="btn btn-sm btn-outline-primary" id="btnAbrirResultadosEnModal" title="Abrir el mismo modal de carpeta pero solo con estas imágenes">
                        <i class="fas fa-th-large mr-1"></i> Abrir en galería
                      </button>
                    </div>
                    <div class="list-group list-group-flex" id="lstResultadosEtiq"></div>
                  </div>
                </div>
              </div>
            </div>
          </div>
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

<!-- Modal: visor -->
<div class="modal fade" id="modalVisor" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-image"></i> <span id="ttlImagen">Imagen</span></h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="custom-control custom-switch">
            <input type="checkbox" class="custom-control-input" id="swBoxes" checked>
            <label class="custom-control-label" for="swBoxes">Bounding boxes</label>
          </div>
          <a class="btn btn-sm btn-outline-secondary" id="lnkAbrirOriginal" href="#" target="_blank" rel="noopener">
            <i class="fas fa-external-link-alt"></i> Abrir original
          </a>
        </div>
        <div class="mb-2" id="badgesDet"></div>
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

<script>
  const el = (id) => document.getElementById(id);

  const txtProcesadas = el('txtProcesadas');
  const txtTotal = el('txtTotal');
  const txtUnsafe = el('txtUnsafe');
  const txtSafe = el('txtSafe');
  const barProcesadas = el('barProcesadas');

  const stProcesar = el('stProcesar');
  const switchAuto = document.getElementById('switchAuto');
  const btnReset = el('btnReset');
  const btnReindex = el('btnReindex');

  const tagsEtiquetas = el('tagsEtiquetas');
  const rngUmbral = el('rngUmbral');
  const lblUmbral = el('lblUmbral');
  const stBuscarEtiq = el('stBuscarEtiq');
  const lstResultadosEtiq = el('lstResultadosEtiq');

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

  const modalVisor = el('modalVisor');
  const ttlImagen = el('ttlImagen');
  const lnkAbrirOriginal = el('lnkAbrirOriginal');
  const swBoxes = el('swBoxes');
  const badgesDet = el('badgesDet');
  const cnv = el('cnv');
  const stVisor = el('stVisor');

  let autoRunning = false;
  let autoTablasRunning = false;
  let buscarEtiqTimer = null;
  let lastFolder = null;
  let folderFiles = [];
  let folderFilter = 'ALL';
  let folderSelectedImageRuta = '';

  let visor = {
    ruta: '',
    archivo: '',
    rutaRelativa: '',
    img: null,
    detections: [],
  };

  function setStatus(node, state, text) {
    if (!node) return;
    node.textContent = text || '';
    node.className = 'small';
    if (state === 'ok') node.classList.add('text-success');
    else if (state === 'bad') node.classList.add('text-danger');
    else node.classList.add('text-muted');
  }

  async function getJson(url) {
    const resp = await fetch(url, { headers: { 'accept': 'application/json' } });
    const data = await resp.json().catch(() => ({}));
    return { ok: resp.ok, data };
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

  async function refreshStats() {
    const { ok, data } = await getJson('?action=estadisticas_clasificacion');
    if (ok && data?.success && data?.stats) {
      renderStats(data.stats);
    }
  }

  let selectedEtiquetaLabel = '';
  let allEtiquetas = []; // { label, count, min, max } desde el backend

  function getUmbralValue() {
    return Number(rngUmbral?.value ?? 80);
  }

  function aplicarRangoUmbral() {
    if (!rngUmbral) return;
    rngUmbral.min = 0;
    rngUmbral.max = 100;
  }

  /** Rango por tag: siempre desde 0 hasta max del tag. Se muestra si el umbral está en [0, max]. */
  function etiquetaIncluyeUmbral(t, umbral) {
    const max = t.max != null ? Number(t.max) : 100;
    return umbral <= max;
  }

  function renderTagsFiltered(umbral) {
    if (!tagsEtiquetas) return;
    const elEmpty = document.getElementById('tagsEtiquetasEmpty');
    const umbralNum = Number(umbral);
    const filtered = umbralNum === 0 ? allEtiquetas : allEtiquetas.filter((t) => etiquetaIncluyeUmbral(t, umbralNum));
    // Si el tag seleccionado ya no cumple el umbral, deseleccionar y ocultar resultados
    if (selectedEtiquetaLabel && !filtered.some((t) => String(t.label).trim() === selectedEtiquetaLabel)) {
      selectedEtiquetaLabel = '';
      renderResultadosEtiq([]);
      setStatus(stBuscarEtiq, 'neutral', 'Ajusta el umbral o elige otra etiqueta');
    }
    tagsEtiquetas.innerHTML = '';
    if (elEmpty) elEmpty.style.display = filtered.length ? 'none' : 'block';
    for (const t of filtered) {
      const label = (t && typeof t === 'object' && t.label != null) ? String(t.label).trim() : '';
      if (!label) continue;
      const count = (t && typeof t === 'object' && t.count != null) ? Number(t.count) : null;
      const min = t.min != null ? Number(t.min) : null;
      const max = t.max != null ? Number(t.max) : null;
      const displayLabel = tagLabelToFullText(label);
      const rangeStr = (min != null && max != null && !Number.isNaN(min) && !Number.isNaN(max)) ? ` [${min}–${max}%]` : '';
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn btn-sm btn-outline-primary tag-etiqueta mr-1 mb-1' + (selectedEtiquetaLabel === label ? ' active' : '');
      btn.dataset.label = label;
      const mainText = count != null && !Number.isNaN(count) ? `${displayLabel} (${count})` : displayLabel;
      if (rangeStr) {
        const spanRange = document.createElement('span');
        spanRange.className = 'tag-range';
        spanRange.textContent = rangeStr;
        btn.appendChild(document.createTextNode(mainText));
        btn.appendChild(spanRange);
      } else {
        btn.textContent = mainText;
      }
      btn.addEventListener('click', () => {
        const yaSeleccionado = selectedEtiquetaLabel === label;
        if (yaSeleccionado) {
          selectedEtiquetaLabel = '';
          document.querySelectorAll('#tagsEtiquetas .tag-etiqueta').forEach((b) => b.classList.remove('active'));
          renderResultadosEtiq([]);
          setStatus(stBuscarEtiq, 'neutral', 'Selecciona una etiqueta');
          return;
        }
        selectedEtiquetaLabel = label;
        document.querySelectorAll('#tagsEtiquetas .tag-etiqueta').forEach((b) => b.classList.remove('active'));
        btn.classList.add('active');
        buscarPorEtiqueta(label);
      });
      tagsEtiquetas.appendChild(btn);
    }
  }

  async function loadEtiquetas() {
    const { ok, data } = await getJson('?action=etiquetas_detectadas');
    if (!ok || !data?.success) return;
    allEtiquetas = Array.isArray(data.etiquetas) ? data.etiquetas : [];
    aplicarRangoUmbral();
    renderTagsFiltered(getUmbralValue());
  }

  let lastResultadosEtiq = [];

  function renderResultadosEtiq(items) {
    if (!lstResultadosEtiq) return;
    lstResultadosEtiq.innerHTML = '';
    const arr = Array.isArray(items) ? items : [];
    lastResultadosEtiq = arr;
    const wrapBtn = document.getElementById('wrapBtnAbrirResultados');
    const txtCount = document.getElementById('txtCountResultadosEtiq');
    if (wrapBtn) wrapBtn.style.display = arr.length ? 'flex' : 'none';
    if (txtCount && arr.length) txtCount.textContent = arr.length + ' imagen' + (arr.length !== 1 ? 'es' : '') + ' en esta búsqueda';
    if (!arr.length) {
      const div = document.createElement('div');
      div.className = 'buscador-empty';
      div.textContent = 'Sin resultados';
      lstResultadosEtiq.appendChild(div);
      return;
    }
    for (const it of arr) {
      const ruta = String(it?.ruta_relativa || it?.ruta || '').trim();
      const carpeta = String(it?.ruta_carpeta || '').trim();
      const bestScore = (it?.best_score !== undefined && it?.best_score !== null) ? Number(it.best_score) : null;
      const matches = Array.isArray(it?.matches) ? it.matches : [];
      const tagsHtml = matches.slice(0, 10).map(m => {
        const lab = String(m?.label || '').replace(/</g, '&lt;');
        const sc = (m?.score !== undefined && m?.score !== null) ? Math.round(Number(m.score) * 100) : '';
        return `<span class="badge badge-light mr-1 mb-1">${lab}${sc !== '' ? ' ' + sc + '%' : ''}</span>`;
      }).join('');

      const scorePct = bestScore !== null && !Number.isNaN(bestScore) ? Math.round(bestScore * 100) : null;
      const a = document.createElement('a');
      a.href = '#';
      a.className = 'list-group-item list-group-item-action resultado-etiqueta-item';
      a.innerHTML = `
        <div class="resultado-etiqueta-row">
          <span class="resultado-etiqueta-ruta text-monospace">${ruta.replace(/</g,'&lt;')}</span>
          ${scorePct !== null ? `<span class="badge badge-pill resultado-etiqueta-score">${scorePct}%</span>` : ''}
        </div>
        ${tagsHtml ? `<div class="resultado-etiqueta-tags">${tagsHtml}</div>` : ''}
      `;
      a.addEventListener('click', async (e) => {
        e.preventDefault();
        if (!carpeta) return;
        await abrirCarpeta(carpeta, carpeta, ruta);
      });
      lstResultadosEtiq.appendChild(a);
    }
  }

  async function buscarPorEtiqueta(labelOrForce) {
    const lab = typeof labelOrForce === 'string' ? labelOrForce.trim() : String(selectedEtiquetaLabel || '').trim();
    const umbralDisplay = Number(rngUmbral?.value || 0);
    const umbral = umbralDisplay > 0 ? umbralDisplay - 1 : 0;
    if (!lab) {
      setStatus(stBuscarEtiq, 'bad', 'Selecciona una etiqueta (click en un tag)');
      return;
    }
    if (typeof expandirBuscador === 'function') expandirBuscador('etiquetas');
    setStatus(stBuscarEtiq, 'neutral', 'Buscando…');
    const { ok, data } = await getJson(`?action=buscar_imagenes_etiquetas&labels=${encodeURIComponent(lab)}&umbral=${encodeURIComponent(String(umbral))}`);
    if (!ok || !data?.success) {
      setStatus(stBuscarEtiq, 'bad', String(data?.error || 'Error'));
      return;
    }
    setStatus(stBuscarEtiq, 'ok', `Resultados: ${data.total || 0}`);
    renderResultadosEtiq(data.imagenes || []);
  }

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

  async function procesarUna() {
    const enAuto = autoRunning;
    if (esperePorFavor && !enAuto) esperePorFavor.style.display = 'flex';
    setStatus(stProcesar, 'neutral', enAuto ? 'Auto en ejecución…' : 'Procesando…');
    try {
      const { ok, data } = await getJson('?action=procesar_imagenes');
      if (!ok || !data?.success) {
        setStatus(stProcesar, 'bad', String(data?.error || 'Error'));
        await refreshLogPanel();
        return;
      }
      if (data?.procesada) {
        setStatus(stProcesar, 'ok', `Procesada: ${data.ruta_relativa || ''} · resultado: ${data.resultado || ''}`);
        if (!autoRunning) await loadEtiquetas();
      } else {
        setStatus(stProcesar, 'neutral', String(data?.mensaje || 'Sin pendientes'));
        if (autoRunning && Number(data?.stats?.pendientes || 0) === 0) {
          setAuto(false);
        }
      }
      if (data?.stats) renderStats(data.stats);
      await refreshLogPanel();
    } finally {
      if (esperePorFavor && !enAuto) esperePorFavor.style.display = 'none';
    }
  }

  async function autoLoopImagenes() {
    while (autoRunning) {
      try { await procesarUna(); } catch (e) {}
      if (!autoRunning) break;
      await new Promise(r => setTimeout(r, 200));
    }
  }

  async function setAuto(on) {
    if (switchAuto) switchAuto.checked = !!on;
    if (!on) {
      autoRunning = false;
      setStatus(stProcesar, 'neutral', 'Auto detenido');
      await appendLog('info', 'Auto clasificación detenido.');
      await refreshLogPanel();
      await loadEtiquetas();
      return;
    }
    autoRunning = true;
    setStatus(stProcesar, 'neutral', 'Auto en ejecución…');
    await appendLog('info', 'Auto clasificación iniciado.');
    await refreshLogPanel();
    autoLoopImagenes();
  }

  async function resetAll() {
    showConfirm(
      'Resetear clasificación',
      '¿Resetear clasificación y detecciones? Los datos del índice se conservan.',
      'Resetear',
      async () => {
        setStatus(stProcesar, 'neutral', 'Reseteando…');
        const { ok, data } = await getJson('?action=reset_clasificacion');
        if (!ok || !data?.success) {
          setStatus(stProcesar, 'bad', String(data?.error || 'Error'));
          return;
        }
        setStatus(stProcesar, 'ok', 'Reset completado');
        if (data?.stats) renderStats(data.stats);
        await loadEtiquetas();
        await refreshLogPanel();
      }
    );
  }

  function reindexAll() {
    showConfirm(
      'Reindexar',
      '¿Reindexar desde el sistema de archivos? Se actualizará el índice de imágenes y carpetas.',
      'Reindexar',
      async () => {
        if (esperePorFavor) esperePorFavor.style.display = 'flex';
        setStatus(stProcesar, 'neutral', 'Reindexando y limpiando…');
        try {
          const { ok, data } = await getJson('?action=reindex_imagenes');
          if (!ok || !data?.success) {
            setStatus(stProcesar, 'bad', String(data?.error || 'Error'));
            return;
          }
          setStatus(stProcesar, 'ok', 'Reindex completado');
          if (data?.stats) renderStats(data.stats);
          await loadEtiquetas();
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
      const linkHtml = `<a href="#" class="list-group-item list-group-item-action folder-list-item">
          <div class="d-flex w-100 folder-item-inner">
            <div class="folder-item-avatar-wrap">${avatarHtml}</div>
            <div class="folder-item-body">
              <div class="folder-item-head">
                <h6 class="folder-item-title">${nombre.replace(/</g, '&lt;')}</h6>
                <span class="folder-item-meta">
                  <span class="folder-item-count">${total} imágenes</span>
                  ${pend === 0 ? '<span class="folder-item-status folder-item-status-ok">Procesado</span>' : `<span class="folder-item-status folder-item-status-pend">${pend} pendientes</span>`}
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

  function buildTagButton(label, active) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'tag-chip' + (active ? ' tag-chip-active' : '');
    b.textContent = label;
    return b;
  }

  function renderFolderTags() {
    if (!tagsCarpeta) return;
    tagsCarpeta.innerHTML = '';

    const tagsSet = {};
    for (const f of folderFiles) {
      if (f?.pendiente) tagsSet['PENDIENTE'] = true;
      const tt = Array.isArray(f?.tags) ? f.tags : [];
      for (const t of tt) {
        const s = String(t || '').trim().toUpperCase();
        if (s) tagsSet[s] = true;
      }
    }
    const tags = Object.keys(tagsSet).sort((a,b) => a.localeCompare(b));

    const allBtn = buildTagButton('Todas', folderFilter === 'ALL');
    allBtn.addEventListener('click', () => { folderFilter = 'ALL'; renderFolderTags(); renderFolderGrid(); });
    tagsCarpeta.appendChild(allBtn);

    for (const t of tags) {
      const displayLabel = t === 'PENDIENTE' ? 'Pendiente' : tagLabelToFullText(t);
      const b = buildTagButton(displayLabel, folderFilter === t);
      b.addEventListener('click', () => { folderFilter = t; renderFolderTags(); renderFolderGrid(); });
      tagsCarpeta.appendChild(b);
    }
  }

  function fileMatchesFilter(f) {
    if (folderFilter === 'ALL') return true;
    if (folderFilter === 'PENDIENTE') return !!f?.pendiente;
    const tt = Array.isArray(f?.tags) ? f.tags : [];
    for (const t of tt) {
      if (String(t || '').trim().toUpperCase() === folderFilter) return true;
    }
    return false;
  }

  function renderFolderGrid() {
    if (!gridThumbs) return;
    gridThumbs.innerHTML = '';
    const files = folderFiles.filter((f) => f?.es_imagen && fileMatchesFilter(f));
    if (!files.length) {
      const div = document.createElement('div');
      div.className = 'col-12 text-muted';
      div.textContent = 'No hay imágenes para el filtro seleccionado';
      gridThumbs.appendChild(div);
      return;
    }
    for (const f of files) {
      const nombre = String(f?.nombre || '').trim();
      const rutaRel = String(f?.ruta_relativa || '').trim();
      const tags = Array.isArray(f?.tags) ? f.tags : [];
      const tagLabels = tags.map(t => String(t || '').trim()).filter(Boolean);
      if (f?.pendiente) tagLabels.unshift('PENDIENTE');

      const col = document.createElement('div');
      col.className = 'col-6 col-sm-4 col-md-3 col-lg-2 mb-3';
      col.dataset.rutaRelativa = rutaRel;
      const card = document.createElement('div');
      card.className = 'thumb-card' + (rutaRel === folderSelectedImageRuta ? ' thumb-card-selected' : '');
      const a = document.createElement('a');
      a.href = '#';
      const imgWrap = document.createElement('div');
      imgWrap.className = 'thumb-card-img';
      const img = document.createElement('img');
      img.alt = nombre;
      img.loading = 'lazy';
      img.src = `?action=ver_imagen&ruta=${encodeURIComponent(lastFolder?.ruta || '')}&archivo=${encodeURIComponent(nombre)}&thumb=1&w=240`;
      imgWrap.appendChild(img);
      a.appendChild(imgWrap);
      const body = document.createElement('div');
      body.className = 'thumb-card-body';
      const title = document.createElement('div');
      title.className = 'thumb-card-title';
      title.textContent = nombre || '—';
      title.title = nombre || '';
      body.appendChild(title);
      if (tagLabels.length > 0) {
        const tagRow = document.createElement('div');
        tagRow.className = 'thumb-card-tags';
        for (const lab of tagLabels.slice(0, 5)) {
          const chip = document.createElement('span');
          chip.className = 'tag-chip-inline';
          const displayLab = lab === 'PENDIENTE' ? 'Pendiente' : tagLabelToFullText(lab);
          chip.textContent = displayLab.replace(/</g, '\u200b');
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
        await abrirVisor(lastFolder?.ruta || '', nombre, rutaRel);
      });
      card.appendChild(a);
      col.appendChild(card);
      gridThumbs.appendChild(col);
    }
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
      img.src = `?action=ver_imagen&ruta=${encodeURIComponent(rutaCarpeta)}&archivo=${encodeURIComponent(archivo)}&thumb=1&w=240`;
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
    }
  }

  function abrirModalSoloResultadosEtiq() {
    if (!lastResultadosEtiq.length) return;
    if (ttlCarpeta) ttlCarpeta.textContent = 'Resultados de búsqueda (' + lastResultadosEtiq.length + ')';
    if (tagsCarpeta) tagsCarpeta.innerHTML = '';
    if (gridThumbs) gridThumbs.innerHTML = '';
    lastFolder = null;
    folderFiles = [];
    folderFilter = 'ALL';
    if (window.jQuery) window.jQuery('#modalCarpeta').modal('show');
    renderGridSoloResultadosEtiq(lastResultadosEtiq);
  }

  async function abrirCarpeta(nombre, ruta, selectedImageRutaRelativa) {
    folderSelectedImageRuta = String(selectedImageRutaRelativa || '').trim();
    lastFolder = { nombre: String(nombre || ''), ruta: String(ruta || '') };
    folderFilter = 'ALL';
    folderFiles = [];
    if (ttlCarpeta) ttlCarpeta.textContent = lastFolder.nombre || 'Carpeta';
    if (tagsCarpeta) tagsCarpeta.innerHTML = '';
    if (gridThumbs) gridThumbs.innerHTML = '';

    if (window.jQuery) window.jQuery('#modalCarpeta').modal('show');
    const { ok, data } = await getJson(`?action=ver_carpeta&ruta=${encodeURIComponent(lastFolder.ruta)}`);
    if (!ok || !data?.success) {
      if (gridThumbs) {
        const div = document.createElement('div');
        div.className = 'col-12 text-danger';
        div.textContent = String(data?.error || 'Error');
        gridThumbs.appendChild(div);
      }
      folderSelectedImageRuta = '';
      return;
    }
    folderFiles = Array.isArray(data.archivos) ? data.archivos : [];
    renderFolderTags();
    renderFolderGrid();

    if (folderSelectedImageRuta && gridThumbs) {
      const col = gridThumbs.querySelector(`[data-ruta-relativa="${CSS.escape(folderSelectedImageRuta)}"]`);
      if (col) {
        col.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
        const card = col.querySelector('.thumb-card');
        if (card) {
          card.classList.add('thumb-card-selected');
          setTimeout(() => card.classList.remove('thumb-card-selected'), 1300);
        }
      }
      folderSelectedImageRuta = '';
    }
  }

  function renderDetBadges(dets, pending) {
    if (!badgesDet) return;
    badgesDet.innerHTML = '';
    if (pending) {
      const b = document.createElement('span');
      b.className = 'badge badge-warning mr-1';
      b.textContent = 'PENDIENTE';
      badgesDet.appendChild(b);
    }
    const arr = Array.isArray(dets) ? dets : [];
    const top = arr.slice(0, 20);
    for (const d of top) {
      const lab = String(d?.label || '').trim();
      const sc = Number(d?.score || 0);
      if (!lab) continue;
      const b = document.createElement('span');
      b.className = 'badge badge-light mr-1';
      b.textContent = `${lab} ${sc.toFixed(3)}`;
      badgesDet.appendChild(b);
    }
  }

  function drawCanvas() {
    if (!cnv) return;
    if (!visor.img) return;
    const img = visor.img;
    const show = !!(swBoxes && swBoxes.checked);
    const ctx = cnv.getContext('2d');
    if (!ctx) return;

    const naturalW = img.naturalWidth || img.width || 1;
    const naturalH = img.naturalHeight || img.height || 1;
    // Espacio disponible: un poco más pequeño que el viewport (márgenes visibles)
    const marginW = 160;
    const marginH = 280;
    const maxW = Math.max(320, (document.documentElement.clientWidth || window.innerWidth) - marginW);
    const maxH = Math.max(240, (window.innerHeight || document.documentElement.clientHeight) - marginH);
    const scale = Math.min(maxW / naturalW, maxH / naturalH);
    const displayW = Math.round(naturalW * scale);
    const displayH = Math.round(naturalH * scale);

    cnv.width = displayW;
    cnv.height = displayH;
    ctx.clearRect(0, 0, displayW, displayH);
    ctx.drawImage(img, 0, 0, naturalW, naturalH, 0, 0, displayW, displayH);

    if (!show) return;

    const dets = Array.isArray(visor.detections) ? visor.detections : [];
    const lineW = Math.max(1.5, displayW / 600);
    const fontSize = Math.max(12, displayW / 60);
    ctx.lineWidth = lineW;
    ctx.font = `${Math.round(fontSize)}px "Source Sans Pro", sans-serif`;
    for (const d of dets) {
      const box = Array.isArray(d?.box) ? d.box : null;
      if (!box || box.length !== 4) continue;
      const x1 = Number(box[0] || 0), y1 = Number(box[1] || 0), x2 = Number(box[2] || 0), y2 = Number(box[3] || 0);
      const w = Math.max(1, (x2 - x1) * scale);
      const h = Math.max(1, (y2 - y1) * scale);
      const sx1 = x1 * scale;
      const sy1 = y1 * scale;
      ctx.strokeStyle = '#dc3545';
      ctx.strokeRect(sx1, sy1, w, h);
      const label = String(d?.label || '').trim();
      const score = Number(d?.score || 0);
      const text = label ? `${label} ${score.toFixed(3)}` : score.toFixed(3);
      ctx.fillStyle = '#dc3545';
      ctx.fillText(text, sx1 + 4, Math.max(fontSize + 2, sy1 - 4));
    }
  }

  async function abrirVisor(ruta, archivo, rutaRelativa) {
    visor.ruta = String(ruta || '');
    visor.archivo = String(archivo || '');
    visor.rutaRelativa = String(rutaRelativa || '');
    visor.detections = [];
    visor.img = null;
    if (ttlImagen) ttlImagen.textContent = visor.archivo || 'Imagen';
    if (stVisor) stVisor.textContent = 'Cargando…';
    if (lnkAbrirOriginal) lnkAbrirOriginal.href = `?action=ver_imagen&ruta=${encodeURIComponent(visor.ruta)}&archivo=${encodeURIComponent(visor.archivo)}`;

    renderDetBadges([], true);
    if (window.jQuery) window.jQuery('#modalVisor').modal('show');

    const img = new Image();
    img.onload = async () => {
      visor.img = img;
      // Detecciones
      const { ok, data } = await getJson(`?action=imagen_detecciones&ruta_relativa=${encodeURIComponent(visor.rutaRelativa)}`);
      const pending = !!data?.pending;
      const dets = Array.isArray(data?.detections) ? data.detections : [];
      visor.detections = dets;
      renderDetBadges(dets, pending);
      drawCanvas();
      setStatus(stVisor, 'ok', pending ? 'Pendiente de procesamiento' : 'OK');
    };
    img.onerror = () => {
      setStatus(stVisor, 'bad', 'No se pudo cargar la imagen');
    };
    img.src = `?action=ver_imagen&ruta=${encodeURIComponent(visor.ruta)}&archivo=${encodeURIComponent(visor.archivo)}`;
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
        const resp = await fetch('?action=upload_imagenes', {
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
    await loadEtiquetas();
    await refreshLogPanel();
    await buscarCarpetas(true);
  }

  // Wire events
  if (switchAuto) switchAuto.addEventListener('change', () => setAuto(switchAuto.checked));
  if (btnReset) btnReset.addEventListener('click', resetAll);
  if (btnReindex) btnReindex.addEventListener('click', reindexAll);

  if (rngUmbral && lblUmbral) {
    const sync = () => {
      const val = String(rngUmbral.value || '0');
      lblUmbral.textContent = val + '%';
      lblUmbral.style.setProperty('--umbral-pct', val + '%');
    };
    sync();
    rngUmbral.addEventListener('input', () => {
      sync();
      const umbral = getUmbralValue();
      renderTagsFiltered(umbral);
      if (buscarEtiqTimer) clearTimeout(buscarEtiqTimer);
      buscarEtiqTimer = setTimeout(() => buscarPorEtiqueta(false), 450);
    });
  }
  const btnAbrirResultadosEnModal = document.getElementById('btnAbrirResultadosEnModal');
  if (btnAbrirResultadosEnModal) btnAbrirResultadosEnModal.addEventListener('click', abrirModalSoloResultadosEtiq);

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
  const btnBuscarCarpetas = document.getElementById('btnBuscarCarpetas');
  if (btnBuscarCarpetas) btnBuscarCarpetas.addEventListener('click', () => buscarCarpetas(true));

  // Expandir un buscador y colapsar el otro (solo uno expandido a la vez)
  const acordeon = document.getElementById('buscadorAcordeon');
  function expandirBuscador(quien) {
    if (!acordeon) return;
    acordeon.classList.remove('expanded-etiquetas', 'expanded-carpetas');
    if (quien === 'etiquetas') acordeon.classList.add('expanded-etiquetas');
    if (quien === 'carpetas') acordeon.classList.add('expanded-carpetas');
  }

  document.querySelectorAll('#buscadorAcordeon .acordeon-item').forEach((item) => {
    const quien = item.getAttribute('data-acordeon');
    const header = item.querySelector('.acordeon-header');
    if (header && quien) header.addEventListener('click', () => expandirBuscador(quien));
    item.addEventListener('focusin', () => { if (quien) expandirBuscador(quien); });
  });
  if (txtBuscarCarpeta) txtBuscarCarpeta.addEventListener('focus', () => expandirBuscador('carpetas'));

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

  if (swBoxes) swBoxes.addEventListener('change', drawCanvas);
  if (window.jQuery) {
    window.jQuery('#modalVisor').on('shown.bs.modal', () => drawCanvas());
  }
  window.addEventListener('resize', () => {
    if (modalVisor && modalVisor.classList.contains('show')) drawCanvas();
  });

  // Tablas auto (solo si existe)
  const switchAutoTablas = document.getElementById('switchAutoTablas');
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
    if (!ok || !data?.success) {
      if (stTablas) setStatus(stTablas, 'bad', String(data?.error || 'Error'));
      if (data?.estado) renderTablasEstado(data.estado);
      await refreshLogPanel();
      return;
    }
    const msg = String(data?.mensaje || 'OK');
    if (stTablas) setStatus(stTablas, 'ok', msg);
    if (data?.clasificacion_stats) renderStats(data.clasificacion_stats);
    if (data?.estado) renderTablasEstado(data.estado);
    await refreshLogPanel();
    if (autoTablasRunning && data?.success && !data?.registro_procesado) {
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

  async function setAutoTablas(on) {
    if (switchAutoTablas) switchAutoTablas.checked = !!on;
    if (!on) {
      autoTablasRunning = false;
      setStatus(stTablas, 'neutral', 'Auto detenido');
      await appendLog('info', 'Auto tablas detenido.');
      await refreshLogPanel();
      await loadEtiquetas();
      return;
    }
    autoTablasRunning = true;
    setStatus(stTablas, 'neutral', 'Auto en ejecución…');
    await appendLog('info', 'Auto tablas iniciado.');
    await refreshLogPanel();
    autoLoopTablas();
  }

  if (switchAutoTablas) switchAutoTablas.addEventListener('change', () => setAutoTablas(switchAutoTablas.checked));

  // Log de procesamiento (actualización solo en momentos clave, sin polling)
  const logPanel = document.getElementById('logPanel');
  const btnLogClear = document.getElementById('btnLogClear');

  async function appendLog(type, message) {
    try {
      await fetch('?action=log_append', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ type: type || 'info', message: String(message || '').trim() })
      });
    } catch (e) {}
  }

  async function refreshLogPanel() {
    if (!logPanel) return;
    const { ok, data } = await getJson('?action=log_tail&limit=20');
    if (!ok || !data?.items) {
      logPanel.innerHTML = '<div class="text-muted small">Sin entradas</div>';
      return;
    }
    const items = Array.isArray(data.items) ? data.items : [];
    if (items.length === 0) {
      logPanel.innerHTML = '<div class="text-muted small">Sin entradas</div>';
      return;
    }
    logPanel.innerHTML = items.map((e) => {
      const time = String(e.time || e.ts || '').slice(0, 8);
      const msg = String(e.message || '').replace(/</g, '&lt;');
      const type = String(e.type || 'info').toLowerCase();
      const cls = type === 'error' ? 'log-error' : (type === 'warning' ? 'log-warning' : (type === 'success' ? 'log-success' : ''));
      return `<div class="log-entry ${cls}"><span class="log-time">${time}</span><span class="log-msg">${msg}</span></div>`;
    }).join('');
    logPanel.scrollTop = logPanel.scrollHeight;
  }

  if (btnLogClear) {
    btnLogClear.addEventListener('click', async () => {
      try {
        await fetch('?action=log_clear', { method: 'POST', headers: { 'accept': 'application/json' } });
        await refreshLogPanel();
      } catch (e) {}
    });
  }

  // Initial load
  (async () => {
    // Inicializar barras de progreso server-side (tablas)
    document.querySelectorAll('.tblProgress[data-width]').forEach((b) => {
      const p = Math.max(0, Math.min(100, Number(b.getAttribute('data-width') || '0')));
      b.style.width = p + '%';
    });
    await refreshStats();
    await loadEtiquetas();
    await refreshLogPanel();
  })();
</script>

