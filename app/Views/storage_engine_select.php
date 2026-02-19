<?php
$__title = 'Configuración inicial';
$__bodyClass = 'page-wizard';
?>
<nav class="topnav">
  <a href="?action=storage_engine_select" class="topnav-brand"><i class="fas fa-shield-alt"></i> PhotoClassifier</a>
  <ul class="topnav-links">
    <li><span class="text-muted small">Configuración inicial</span></li>
  </ul>
</nav>

<main class="main content">
  <div class="container py-5">
    <div class="row justify-content-center">
      <div class="col-md-8 col-lg-6">
        <div class="card shadow-sm">
          <div class="card-body p-4">
            <h1 class="h4 mb-3">Configuración inicial</h1>
            <p class="text-muted mb-4">Elige el motor de base de datos. Esta elección aplica a todos los workspaces (actuales y futuros).</p>

            <form method="post" action="?action=storage_engine_save">
              <div class="form-group">
                <label class="font-weight-bold">¿Qué motor de base de datos usar?</label>
                <div class="mt-2">
                  <div class="custom-control custom-radio mb-2">
                    <input type="radio" id="driver_sqlite" name="driver" value="sqlite" class="custom-control-input" checked>
                    <label class="custom-control-label" for="driver_sqlite">SQLite</label>
                  </div>
                  <p class="small text-muted ml-4">Un único archivo en <code>database/clasificador.sqlite</code>. Recomendado para empezar.</p>
                  <div class="custom-control custom-radio mb-2">
                    <input type="radio" id="driver_mysql" name="driver" value="mysql" class="custom-control-input">
                    <label class="custom-control-label" for="driver_mysql">MariaDB / MySQL</label>
                  </div>
                  <p class="small text-muted ml-4">Una base de datos en un servidor. Configuración en Parametrización global después.</p>
                </div>
              </div>
              <button type="submit" class="btn btn-primary">
                <i class="fas fa-arrow-right"></i> Continuar
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>
