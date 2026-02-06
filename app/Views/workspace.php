<?php
/** @var array $workspaces */
/** @var string|null $current */

$__title = 'Seleccionar workspace';
$__bodyClass = '';

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
  <a href="?action=workspace_select" class="topnav-brand"><i class="fas fa-shield-alt"></i> Clasificador</a>
  <ul class="topnav-links">
    <li><a href="?action=workspace_select" class="active"><i class="fas fa-layer-group"></i> Workspaces</a></li>
    <li>
      <button class="btn btn-primary btn-sm" type="button" data-toggle="modal" data-target="#createModal">
        <i class="fas fa-plus"></i> Crear workspace
      </button>
    </li>
  </ul>
</nav>

<main class="main content page-workspaces">
  <div class="container ws-container">
    <header class="ws-header">
      <h1 class="ws-header-title">Workspaces</h1>
      <p class="ws-header-desc">Ambientes aislados. Descargar o Clasificar para procesar varios a la vez aquí.</p>
    </header>

    <?php if (!empty($workspaces)): ?>
    <div id="workspaceProcessingPanel" class="ws-processing" style="display: none;">
      <div class="ws-processing-bar">
        <span class="ws-processing-label"><i class="fas fa-sync-alt fa-spin" aria-hidden="true"></i> Procesando</span>
        <div id="workspaceProcessingList" class="ws-processing-list"></div>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnStopAll" title="Detener todos">Detener todos</button>
      </div>
    </div>
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
              <button class="ws-card-delete btn btn-link btn-sm btnDeleteWs" type="button" data-ws="<?php echo htmlspecialchars($slug, ENT_QUOTES); ?>" title="Eliminar workspace" aria-label="Eliminar <?php echo htmlspecialchars($slug, ENT_QUOTES); ?>">
                <i class="fas fa-trash-alt"></i>
              </button>
            </div>
            <p class="ws-card-path">workspaces/<?php echo htmlspecialchars($slug, ENT_QUOTES); ?> <span class="ws-card-created">· Creado <?php echo htmlspecialchars($createdAt, ENT_QUOTES); ?></span></p>
            <div class="ws-card-badges">
              <span class="ws-badge ws-badge--<?php echo $configured ? 'ok' : 'warn'; ?>"><?php echo $configured ? 'Configurado' : 'Pendiente'; ?></span>
              <span class="ws-badge ws-badge--mode"><?php echo htmlspecialchars($modeLabel, ENT_QUOTES); ?></span>
            </div>
            <?php if ($imgTotal !== null || $imgPend !== null || $detTotal !== null): ?>
            <div class="ws-card-stats" data-ws="<?php echo htmlspecialchars($slug, ENT_QUOTES); ?>">
              <div class="ws-stat"><span class="ws-stat-num" data-stat="images"><?php echo $imgTotal !== null ? number_format($imgTotal) : '—'; ?></span><span class="ws-stat-lbl"><i class="fas fa-image"></i> Imágenes</span></div>
              <div class="ws-stat"><span class="ws-stat-num" data-stat="pending"><?php echo $imgPend !== null ? number_format($imgPend) : '—'; ?></span><span class="ws-stat-lbl"><i class="fas fa-hourglass-half"></i> Pendientes</span></div>
              <div class="ws-stat"><span class="ws-stat-num" data-stat="detections"><?php echo $detTotal !== null ? number_format($detTotal) : '—'; ?></span><span class="ws-stat-lbl"><i class="fas fa-robot"></i> Detecciones</span></div>
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
  const listEl = document.getElementById('workspaceProcessingList');
  const btnStopAll = document.getElementById('btnStopAll');

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
      await new Promise((r) => setTimeout(r, 50));
    }
  }
  async function runClassifyLoopMain(ws) {
    while (classifyQueue.has(ws) && !mainThreadStopRequested) {
      const url = apiUrlWs('procesar_imagenes', ws);
      const { ok, data } = await getJson(url);
      if (!ok || !data?.success || !classifyQueue.has(ws)) break;
      const pending = data?.pendientes ?? data?.pending ?? 0;
      const total = data?.total ?? 0;
      const procesadas = data?.procesadas ?? 0;
      if (pending === 0 || (total > 0 && procesadas >= total)) {
        classifyQueue.delete(ws);
        updateProcessingPanel();
        break;
      }
      await new Promise((r) => setTimeout(r, 50));
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

  function updateProcessingPanel() {
    if (!listEl) return;
    const items = [];
    downloadQueue.forEach((ws) => items.push({ ws, type: 'descargar', label: 'Descargar tablas' }));
    classifyQueue.forEach((ws) => items.push({ ws, type: 'clasificar', label: 'Clasificar' }));
    if (items.length === 0) {
      if (panelEl) panelEl.style.display = 'none';
      return;
    }
    if (panelEl) panelEl.style.display = 'block';
    listEl.innerHTML = items.map((it) =>
      `<div class="ws-processing-chip" data-ws="${it.ws}" data-type="${it.type}">
        <span class="ws-processing-ws">${it.ws}</span>
        <span class="ws-processing-type">${it.label}</span>
        <span class="ws-processing-metrics" data-ws="${it.ws}" data-type="${it.type}">—</span>
        <button type="button" class="ws-processing-remove btnStopOne" data-ws="${it.ws}" data-type="${it.type}" title="Quitar" aria-label="Quitar">×</button>
      </div>`
    ).join('');
    listEl.querySelectorAll('.ws-processing-remove').forEach((btn) => {
      btn.addEventListener('click', () => {
        const ws = btn.getAttribute('data-ws');
        const type = btn.getAttribute('data-type');
        if (type === 'descargar') { downloadQueue.delete(ws); if (worker) worker.postMessage({ type: 'remove', mode: 'download', ws }); }
        else { classifyQueue.delete(ws); if (worker) worker.postMessage({ type: 'remove', mode: 'classify', ws }); }
        updateProcessingPanel();
      });
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
    if (imgEl) imgEl.textContent = (statsClasificacion.total ?? 0).toLocaleString();
    if (pendEl) pendEl.textContent = (statsClasificacion.pendientes_deteccion ?? statsClasificacion.pendientes ?? 0).toLocaleString();
    if (detEl) detEl.textContent = (statsClasificacion.detections_total ?? 0).toLocaleString();
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

  async function refreshPanelStats() {
    const items = [];
    downloadQueue.forEach((ws) => items.push({ ws, type: 'descargar' }));
    classifyQueue.forEach((ws) => items.push({ ws, type: 'clasificar' }));
    for (const { ws, type } of items) {
      const el = listEl?.querySelector(`.ws-processing-metrics[data-ws="${ws}"][data-type="${type}"]`);
      if (type === 'descargar') {
        await fetchStatsDescarga(ws);
        const statsCard = await fetchStatsClasificacion(ws);
        updateCardStats(ws, statsCard);
        if (el) el.textContent = formatMetrics(type, null, { imagenes_totales: statsCard?.total ?? 0 });
      } else {
        const stats = await fetchStatsClasificacion(ws);
        updateCardStats(ws, stats);
        if (el) el.textContent = formatMetrics(type, stats);
      }
    }
  }

  let statsIntervalId = null;
  function startStatsPolling() {
    if (statsIntervalId) return;
    statsIntervalId = setInterval(refreshPanelStats, 4000);
  }
  function stopStatsPolling() {
    if (statsIntervalId) { clearInterval(statsIntervalId); statsIntervalId = null; }
  }

  if (worker) {
    worker.onmessage = function (e) {
      const msg = e.data;
      if (msg?.type === 'state') {
        syncQueuesFromState(msg);
        updateProcessingPanel();
        if (downloadQueue.size === 0 && classifyQueue.size === 0) stopStatsPolling();
      } else if (msg?.type === 'done') {
        if (msg.mode === 'download') downloadQueue.delete(msg.ws);
        else classifyQueue.delete(msg.ws);
        updateProcessingPanel();
        if (downloadQueue.size === 0 && classifyQueue.size === 0) stopStatsPolling();
      }
    };
  }

  document.querySelectorAll('.btnWsDescargar[data-ws]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const ws = btn.getAttribute('data-ws');
      if (!ws) return;
      downloadQueue.add(ws);
      updateProcessingPanel();
      startStatsPolling();
      if (worker) worker.postMessage({ type: 'add', mode: 'download', ws });
      else { mainThreadStopRequested = false; runDownloadLoopMain(ws); }
    });
  });
  document.querySelectorAll('.btnWsClasificar[data-ws]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const ws = btn.getAttribute('data-ws');
      if (!ws) return;
      classifyQueue.add(ws);
      updateProcessingPanel();
      startStatsPolling();
      if (worker) worker.postMessage({ type: 'add', mode: 'classify', ws });
      else { mainThreadStopRequested = false; runClassifyLoopMain(ws); }
    });
  });

  if (btnStopAll) {
    btnStopAll.addEventListener('click', () => {
      downloadQueue.clear();
      classifyQueue.clear();
      if (worker) worker.postMessage({ type: 'stopAll' });
      else mainThreadStopRequested = true;
      stopStatsPolling();
      updateProcessingPanel();
    });
  }

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
        const { ok, data } = await postJson('workspace_create', { name });
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
</script>

