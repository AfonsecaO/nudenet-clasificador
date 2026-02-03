<?php
$__title = 'Error de configuración';
$__bodyClass = '';
?>

<div class="error-page">
  <div class="error-card">
    <h1><i class="fas fa-shield-alt"></i> Clasificador</h1>
    <p class="text-muted mb-2">Error de configuración</p>

    <div class="error-msg">
      <h5><i class="fas fa-exclamation-triangle"></i> No se puede continuar</h5>
      <p class="mb-0"><?php echo htmlspecialchars($error ?? 'Error desconocido', ENT_QUOTES); ?></p>
    </div>

    <p class="text-muted small mb-3">
      Completa la parametrización del sistema en el workspace actual.
    </p>

    <div class="actions">
      <button class="btn btn-outline-secondary" id="btnGoWorkspace" type="button">
        <i class="fas fa-layer-group"></i> Workspaces
      </button>
      <button class="btn btn-danger" id="btnGoSetup" type="button">
        <i class="fas fa-cog"></i> Ir a parametrización
      </button>
    </div>
  </div>
</div>

<script>
  const btnGoSetup = document.getElementById('btnGoSetup');
  const btnGoWorkspace = document.getElementById('btnGoWorkspace');
  if (btnGoSetup) btnGoSetup.addEventListener('click', () => (window.location.href = '?action=setup'));
  if (btnGoWorkspace) btnGoWorkspace.addEventListener('click', () => (window.location.href = '?action=workspace_select'));
</script>
