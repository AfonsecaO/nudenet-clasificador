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
  <div class="container">
    <header class="workspaces-header">
      <h1 class="workspaces-title"><i class="fas fa-layer-group"></i> Workspaces</h1>
      <p class="workspaces-subtitle">Gestiona y cambia entre ambientes aislados.</p>
    </header>

    <?php if (empty($workspaces)): ?>
      <div class="workspaces-empty">
        <div class="workspaces-empty-icon"><i class="fas fa-folder-open"></i></div>
        <h2 class="workspaces-empty-title">Sin workspaces</h2>
        <p class="workspaces-empty-text">Crea el primero para empezar a clasificar imágenes.</p>
        <button class="btn btn-primary" type="button" data-toggle="modal" data-target="#createModal">
          <i class="fas fa-plus"></i> Crear workspace
        </button>
      </div>
    <?php else: ?>
    <div class="row workspace-cards-row">
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
          $statusLabel = $configured ? 'Configurado' : 'Pendiente';
        ?>
        <div class="col-12 col-lg-6 mb-4">
          <div class="card workspace-card<?php echo $isCurrent ? ' workspace-card--current' : ''; ?>">
            <div class="card-header workspace-card-header d-flex align-items-center justify-content-between">
              <div class="d-flex align-items-center min-w-0">
                <span class="workspace-card-icon <?php echo $configured ? 'text-success' : 'text-muted'; ?>">
                  <i class="fas <?php echo $configured ? 'fa-check-circle' : 'fa-clock'; ?>"></i>
                </span>
                <h3 class="workspace-card-title mb-0"><?php echo htmlspecialchars($slug, ENT_QUOTES); ?></h3>
                <?php if ($isCurrent): ?>
                  <span class="badge badge-info ml-2 flex-shrink-0">Actual</span>
                <?php endif; ?>
              </div>
              <button class="btn btn-link btn-sm text-danger p-1 ml-2 btnDeleteWs" type="button" data-ws="<?php echo htmlspecialchars($slug, ENT_QUOTES); ?>" title="Eliminar workspace">
                <i class="fas fa-trash-alt"></i>
              </button>
            </div>
            <div class="card-body">
              <div class="workspace-card-path text-monospace text-muted small">workspaces/<?php echo htmlspecialchars($slug, ENT_QUOTES); ?></div>
              <div class="workspace-card-badges mt-2">
                <span class="badge badge-<?php echo $configured ? 'success' : 'warning'; ?>"><?php echo htmlspecialchars($statusLabel, ENT_QUOTES); ?></span>
                <span class="badge badge-secondary"><?php echo htmlspecialchars($modeLabel, ENT_QUOTES); ?></span>
              </div>
              <div class="workspace-card-stats mt-3">
                <?php if ($imgTotal !== null || $imgPend !== null || $detTotal !== null): ?>
                  <div class="workspace-stat"><span class="workspace-stat-value"><?php echo $imgTotal !== null ? number_format($imgTotal) : '—'; ?></span><span class="workspace-stat-label">Imágenes</span></div>
                  <div class="workspace-stat"><span class="workspace-stat-value"><?php echo $imgPend !== null ? number_format($imgPend) : '—'; ?></span><span class="workspace-stat-label">Pendientes</span></div>
                  <div class="workspace-stat"><span class="workspace-stat-value"><?php echo $detTotal !== null ? number_format($detTotal) : '—'; ?></span><span class="workspace-stat-label">Detecciones</span></div>
                <?php endif; ?>
              </div>
              <div class="workspace-card-meta text-muted small mt-3">
                Creado <?php echo htmlspecialchars($createdAt, ENT_QUOTES); ?>
                <?php if ($updatedAt): ?> · Actualizado <?php echo htmlspecialchars($updatedAt, ENT_QUOTES); ?><?php endif; ?>
              </div>
            </div>
            <div class="card-footer workspace-card-footer bg-transparent border-top d-flex flex-wrap">
              <button class="btn btn-primary btn-sm btnEnterWs mr-2" type="button" data-ws="<?php echo htmlspecialchars($slug, ENT_QUOTES); ?>">
                <i class="fas fa-arrow-right"></i> Entrar
              </button>
              <button class="btn btn-outline-secondary btn-sm btnSetupWs" type="button" data-ws="<?php echo htmlspecialchars($slug, ENT_QUOTES); ?>">
                <i class="fas fa-cog"></i> Parametrización
              </button>
            </div>
          </div>
        </div>
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

