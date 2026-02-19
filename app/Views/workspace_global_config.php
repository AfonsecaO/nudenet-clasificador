<?php
/** @var array $mysql */
/** @var string $storage_engine */

$__title = 'Parametrización global';
$__bodyClass = 'page-workspace-global-config';

$mysql = is_array($mysql ?? null) ? $mysql : [];
$host = (string) ($mysql['host'] ?? '');
$port = (int) ($mysql['port'] ?? 3306);
$user = (string) ($mysql['user'] ?? '');
$password = (string) ($mysql['password'] ?? '');
$database = (string) ($mysql['database'] ?? '');
$currentEngine = strtolower(trim((string)($storage_engine ?? 'sqlite')));
if ($currentEngine !== 'mysql') $currentEngine = 'sqlite';
$isWizard = !\App\Services\StorageEngineConfig::storageEngineFileExists();
?>

<nav class="topnav">
  <a href="?action=workspace_select" class="topnav-brand"><i class="fas fa-shield-alt"></i> PhotoClassifier</a>
  <ul class="topnav-links">
    <li><a href="?action=workspace_select"><i class="fas fa-layer-group"></i> Workspaces</a></li>
    <li><a href="?action=workspace_global_config" class="active"><i class="fas fa-database"></i> Parametrización global</a></li>
  </ul>
</nav>

<main class="main content page-global-config">
  <div class="container container--global-config">
    <div class="row mb-3 justify-content-center">
      <div class="col-12 col-md-10 col-lg-7">
        <div class="text-center mb-4">
          <h1 class="h3 mb-2"><?php echo $isWizard ? 'Configuración inicial' : 'Parametrización global'; ?></h1>
          <p class="text-muted mb-0"><?php echo $isWizard ? 'Elige el motor de base de datos para toda la aplicación. Luego podrás cambiarlo aquí cuando quieras.' : 'Motor de almacenamiento y, si usas MySQL, configuración del servidor.'; ?></p>
        </div>
        <form id="formGlobalConfig" class="global-config-form">
          <div class="card mb-3">
            <div class="card-header">
              <h2 class="h6 mb-0"><i class="fas fa-database"></i> Motor de almacenamiento</h2>
            </div>
            <div class="card-body">
              <p class="text-muted small mb-2">Se usa una sola base de datos para todos los workspaces.</p>
              <div class="custom-control custom-radio">
                <input class="custom-control-input" type="radio" id="driver_sqlite" name="driver" value="sqlite" <?php echo $currentEngine === 'sqlite' ? 'checked' : ''; ?>>
                <label for="driver_sqlite" class="custom-control-label">SQLite (archivo único en <code>database/</code>)</label>
              </div>
              <div class="custom-control custom-radio">
                <input class="custom-control-input" type="radio" id="driver_mysql" name="driver" value="mysql" <?php echo $currentEngine === 'mysql' ? 'checked' : ''; ?>>
                <label for="driver_mysql" class="custom-control-label">MariaDB/MySQL</label>
              </div>
            </div>
          </div>

          <div class="card mb-3" id="cardMysqlConfig" style="<?php echo $currentEngine === 'mysql' ? '' : 'display:none;'; ?>">
            <div class="card-header">
              <h2 class="h6 mb-0"><i class="fas fa-cog"></i> Configuración del servidor MySQL</h2>
            </div>
            <div class="card-body">
              <div class="form-group">
                <label for="mysql_host">Host</label>
                <input type="text" class="form-control" id="mysql_host" name="host" value="<?php echo htmlspecialchars($host, ENT_QUOTES); ?>" placeholder="127.0.0.1">
              </div>
              <div class="form-group">
                <label for="mysql_port">Puerto</label>
                <input type="number" class="form-control" id="mysql_port" name="port" value="<?php echo (int) $port; ?>" min="1" max="65535" placeholder="3306">
              </div>
              <div class="form-group">
                <label for="mysql_user">Usuario</label>
                <input type="text" class="form-control" id="mysql_user" name="user" value="<?php echo htmlspecialchars($user, ENT_QUOTES); ?>" placeholder="root">
              </div>
              <div class="form-group">
                <label for="mysql_password">Contraseña</label>
                <input type="password" class="form-control" id="mysql_password" name="password" value="" placeholder="" autocomplete="new-password">
                <small class="form-text text-muted">Dejar en blanco para no cambiar la actual.</small>
              </div>
              <div class="form-group">
                <label for="mysql_database">Base de datos</label>
                <input type="text" class="form-control" id="mysql_database" name="database" value="<?php echo htmlspecialchars($database, ENT_QUOTES); ?>" placeholder="clasificador">
              </div>
              <p class="text-muted small mb-0">Al guardar con MySQL se comprueba la conexión; solo se guarda si es correcta.</p>
            </div>
          </div>

          <div class="form-group mb-0 d-flex flex-wrap align-items-center gap-2">
            <button type="submit" class="btn btn-primary" id="btnSave">Guardar</button>
            <a href="?action=workspace_select" class="btn btn-outline-secondary">Volver a Workspaces</a>
            <span class="small text-muted" id="stStatus"></span>
          </div>
        </form>
      </div>
    </div>
  </div>
</main>

<script>
(function () {
  const form = document.getElementById('formGlobalConfig');
  const btn = document.getElementById('btnSave');
  const st = document.getElementById('stStatus');
  const cardMysql = document.getElementById('cardMysqlConfig');
  const driverSqlite = document.getElementById('driver_sqlite');
  const driverMysql = document.getElementById('driver_mysql');

  function setStatus(text, type) {
    if (!st) return;
    st.textContent = text || '';
    st.className = 'ml-2 small ' + (type === 'ok' ? 'text-success' : type === 'bad' ? 'text-danger' : 'text-muted');
  }

  function getDriver() {
    const r = form && form.querySelector('input[name="driver"]:checked');
    return (r && r.value === 'mysql') ? 'mysql' : 'sqlite';
  }

  function getFormData() {
    return {
      driver: getDriver(),
      host: (document.getElementById('mysql_host') && document.getElementById('mysql_host').value) || '',
      port: parseInt(document.getElementById('mysql_port') && document.getElementById('mysql_port').value, 10) || 3306,
      user: (document.getElementById('mysql_user') && document.getElementById('mysql_user').value) || '',
      password: (document.getElementById('mysql_password') && document.getElementById('mysql_password').value) || '',
      database: (document.getElementById('mysql_database') && document.getElementById('mysql_database').value) || ''
    };
  }

  if (driverMysql) driverMysql.addEventListener('change', function () { if (cardMysql) cardMysql.style.display = this.checked ? '' : 'none'; });
  if (driverSqlite) driverSqlite.addEventListener('change', function () { if (cardMysql) cardMysql.style.display = this.checked ? 'none' : ''; });

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    const d = getFormData();

    if (d.driver === 'mysql') {
      if (!d.host || !d.database) {
        setStatus('Completa host y base de datos para MySQL.', 'bad');
        return;
      }
    }

    setStatus('Guardando…', '');
    if (btn) btn.disabled = true;
    try {
      const resp = await fetch('?action=workspace_global_config_save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ driver: d.driver, host: d.host, port: d.port, user: d.user, password: d.password, database: d.database })
      });
      const data = await resp.json().catch(function () { return {}; });
      if (resp.ok && data.success) {
        setStatus('Guardado correctamente', 'ok');
        if (d.driver === 'sqlite') {
          if (cardMysql) cardMysql.style.display = 'none';
        }
      } else {
        setStatus(data.error || 'Error al guardar', 'bad');
      }
    } catch (err) {
      setStatus(err && err.message ? err.message : 'Error', 'bad');
    } finally {
      if (btn) btn.disabled = false;
    }
  });
})();
</script>
