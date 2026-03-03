<?php
/** @var array $mysql */
/** @var string $storage_engine */
/** @var array $aws */

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
$registrosDescarga = (int) ($registros_descarga ?? \App\Services\StorageEngineConfig::getRegistrosDescarga());
$registrosDescarga = max(1, min(1000, $registrosDescarga));
$registrosModeracion = (int) ($registros_moderacion ?? \App\Services\StorageEngineConfig::getRegistrosModeracion());
$registrosModeracion = max(1, min(100, $registrosModeracion));

$aws = is_array($aws ?? null) ? $aws : [];
$awsKey = (string) ($aws['key'] ?? '');
$awsRegion = (string) ($aws['region'] ?? 'us-east-1');
$awsVersion = (string) ($aws['version'] ?? 'latest');
$awsMinConfidence = isset($aws['min_confidence']) ? (float) $aws['min_confidence'] : 50.0;
$awsMinConfidence = max(0, min(100, $awsMinConfidence));
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
    <div class="row mb-4">
      <div class="col-12">
        <h1 class="h3 mb-2"><?php echo $isWizard ? 'Configuración inicial' : 'Parametrización global'; ?></h1>
        <p class="text-muted mb-0"><?php echo $isWizard ? 'Elige el motor de base de datos para toda la aplicación. Luego podrás cambiarlo aquí cuando quieras.' : 'Motor de almacenamiento y, si usas MySQL, configuración del servidor.'; ?></p>
      </div>
    </div>
    <form id="formGlobalConfig" class="global-config-form">
      <div class="row global-config-cards">
        <div class="col-12 col-lg-6 mb-3">
          <div class="card h-100 global-config-card">
            <div class="card-header">
              <h2 class="h6 mb-0"><i class="fas fa-database"></i> Motor de almacenamiento</h2>
            </div>
            <div class="card-body">
              <p class="text-muted small mb-3">Se usa una sola base de datos para todos los workspaces.</p>
              <div class="custom-control custom-radio mb-2">
                <input class="custom-control-input" type="radio" id="driver_sqlite" name="driver" value="sqlite" <?php echo $currentEngine === 'sqlite' ? 'checked' : ''; ?>>
                <label for="driver_sqlite" class="custom-control-label">SQLite (archivo único en <code>database/</code>)</label>
              </div>
              <div class="custom-control custom-radio">
                <input class="custom-control-input" type="radio" id="driver_mysql" name="driver" value="mysql" <?php echo $currentEngine === 'mysql' ? 'checked' : ''; ?>>
                <label for="driver_mysql" class="custom-control-label">MariaDB/MySQL</label>
              </div>
            </div>
          </div>
        </div>
        <div class="col-12 col-lg-6 mb-3">
          <div class="card h-100 global-config-card">
            <div class="card-header">
              <h2 class="h6 mb-0"><i class="fas fa-download"></i> Registros por descarga</h2>
            </div>
            <div class="card-body">
              <p class="text-muted small mb-3">Número de registros a procesar por petición al descargar imágenes (1–1000).</p>
              <div class="form-group">
                <label for="registros_descarga">Registros por petición</label>
                <input type="number" class="form-control form-control-lg" id="registros_descarga" name="registros_descarga" value="<?php echo (int) $registrosDescarga; ?>" min="1" max="1000" aria-describedby="registros_descarga_help">
                <small id="registros_descarga_help" class="form-text text-muted">Valor global para todos los workspaces.</small>
              </div>
              <div class="form-group mb-0">
                <label for="registros_moderacion">Registros por lote de moderación</label>
                <input type="number" class="form-control form-control-lg" id="registros_moderacion" name="registros_moderacion" value="<?php echo (int) $registrosModeracion; ?>" min="1" max="100" aria-describedby="registros_moderacion_help">
                <small id="registros_moderacion_help" class="form-text text-muted">Número de imágenes a procesar por petición al ejecutar "Clasificar moderación" (1–100).</small>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="row">
        <div class="col-12 mb-3">
          <div class="card global-config-card" id="cardMysqlConfig" style="<?php echo $currentEngine === 'mysql' ? '' : 'display:none;'; ?>">
            <div class="card-header">
              <h2 class="h6 mb-0"><i class="fas fa-cog"></i> Configuración del servidor MySQL</h2>
            </div>
            <div class="card-body">
              <div class="row">
                <div class="col-12 col-md-6 col-lg-4">
                  <div class="form-group">
                    <label for="mysql_host">Host</label>
                    <input type="text" class="form-control" id="mysql_host" name="host" value="<?php echo htmlspecialchars($host, ENT_QUOTES); ?>" placeholder="127.0.0.1">
                  </div>
                </div>
                <div class="col-12 col-md-6 col-lg-4">
                  <div class="form-group">
                    <label for="mysql_port">Puerto</label>
                    <input type="number" class="form-control" id="mysql_port" name="port" value="<?php echo (int) $port; ?>" min="1" max="65535" placeholder="3306">
                  </div>
                </div>
                <div class="col-12 col-md-6 col-lg-4">
                  <div class="form-group">
                    <label for="mysql_user">Usuario</label>
                    <input type="text" class="form-control" id="mysql_user" name="user" value="<?php echo htmlspecialchars($user, ENT_QUOTES); ?>" placeholder="root">
                  </div>
                </div>
                <div class="col-12 col-md-6 col-lg-4">
                  <div class="form-group">
                    <label for="mysql_password">Contraseña</label>
                    <input type="password" class="form-control" id="mysql_password" name="password" value="" placeholder="" autocomplete="new-password">
                    <small class="form-text text-muted">En blanco = no cambiar.</small>
                  </div>
                </div>
                <div class="col-12 col-md-6 col-lg-4">
                  <div class="form-group">
                    <label for="mysql_database">Base de datos</label>
                    <input type="text" class="form-control" id="mysql_database" name="database" value="<?php echo htmlspecialchars($database, ENT_QUOTES); ?>" placeholder="clasificador">
                  </div>
                </div>
              </div>
              <p class="text-muted small mb-0">Al guardar con MySQL se comprueba la conexión; solo se guarda si es correcta.</p>
            </div>
          </div>
        </div>
      </div>

      <div class="row">
        <div class="col-12 mb-3">
          <div class="card global-config-card">
            <div class="card-header">
              <h2 class="h6 mb-0"><i class="fas fa-robot"></i> AWS Rekognition (moderación de contenido)</h2>
            </div>
            <div class="card-body">
              <p class="text-muted small mb-3">Credenciales y opciones para clasificar imágenes con Content Moderation. La confianza mínima (MinConfidence) se envía a la API; por debajo de 50% suele haber más falsos positivos.</p>
              <div class="row">
                <div class="col-12 col-md-6 col-lg-4">
                  <div class="form-group">
                    <label for="aws_region">Región</label>
                    <input type="text" class="form-control" id="aws_region" name="aws_region" value="<?php echo htmlspecialchars($awsRegion, ENT_QUOTES); ?>" placeholder="us-east-1">
                  </div>
                </div>
                <div class="col-12 col-md-6 col-lg-4">
                  <div class="form-group">
                    <label for="aws_key">Access Key ID</label>
                    <input type="text" class="form-control" id="aws_key" name="aws_key" value="<?php echo htmlspecialchars($awsKey, ENT_QUOTES); ?>" placeholder="" autocomplete="off">
                  </div>
                </div>
                <div class="col-12 col-md-6 col-lg-4">
                  <div class="form-group">
                    <label for="aws_secret">Secret Access Key</label>
                    <input type="password" class="form-control" id="aws_secret" name="aws_secret" value="" placeholder="" autocomplete="new-password">
                    <small class="form-text text-muted">En blanco = no cambiar.</small>
                  </div>
                </div>
                <div class="col-12 col-md-6 col-lg-4">
                  <div class="form-group">
                    <label for="aws_version">Versión SDK</label>
                    <input type="text" class="form-control" id="aws_version" name="aws_version" value="<?php echo htmlspecialchars($awsVersion, ENT_QUOTES); ?>" placeholder="latest">
                  </div>
                </div>
                <div class="col-12 col-md-6 col-lg-4">
                  <div class="form-group">
                    <label for="aws_min_confidence">Confianza mínima (%)</label>
                    <input type="number" class="form-control" id="aws_min_confidence" name="aws_min_confidence" value="<?php echo (int) round($awsMinConfidence); ?>" min="0" max="100" step="1" aria-describedby="aws_min_confidence_help">
                    <small id="aws_min_confidence_help" class="form-text text-muted">MinConfidence (default 50).</small>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="row">
        <div class="col-12">
          <div class="global-config-actions">
            <button type="submit" class="btn btn-primary btn-lg" id="btnSave"><i class="fas fa-save mr-2"></i>Guardar</button>
            <a href="?action=workspace_select" class="btn btn-outline-secondary btn-lg">Volver a Workspaces</a>
            <span class="small text-muted ml-3 align-middle" id="stStatus"></span>
          </div>
        </div>
      </div>
    </form>
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
    const regEl = document.getElementById('registros_descarga');
    const reg = regEl ? parseInt(regEl.value, 10) : 1;
    const regModEl = document.getElementById('registros_moderacion');
    const regMod = regModEl ? parseInt(regModEl.value, 10) : 20;
    const minConfEl = document.getElementById('aws_min_confidence');
    const minConf = minConfEl ? parseFloat(minConfEl.value) : 50;
    return {
      driver: getDriver(),
      host: (document.getElementById('mysql_host') && document.getElementById('mysql_host').value) || '',
      port: parseInt(document.getElementById('mysql_port') && document.getElementById('mysql_port').value, 10) || 3306,
      user: (document.getElementById('mysql_user') && document.getElementById('mysql_user').value) || '',
      password: (document.getElementById('mysql_password') && document.getElementById('mysql_password').value) || '',
      database: (document.getElementById('mysql_database') && document.getElementById('mysql_database').value) || '',
      registros_descarga: isNaN(reg) || reg < 1 ? 1 : reg > 1000 ? 1000 : reg,
      registros_moderacion: isNaN(regMod) || regMod < 1 ? 20 : regMod > 100 ? 100 : regMod,
      aws: {
        key: (document.getElementById('aws_key') && document.getElementById('aws_key').value) || '',
        secret: (document.getElementById('aws_secret') && document.getElementById('aws_secret').value) || '',
        region: (document.getElementById('aws_region') && document.getElementById('aws_region').value) || 'us-east-1',
        version: (document.getElementById('aws_version') && document.getElementById('aws_version').value) || 'latest',
        min_confidence: isNaN(minConf) || minConf < 0 ? 50 : minConf > 100 ? 100 : minConf
      }
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
        body: JSON.stringify({ driver: d.driver, host: d.host, port: d.port, user: d.user, password: d.password, database: d.database, registros_descarga: d.registros_descarga, registros_moderacion: d.registros_moderacion, aws: d.aws })
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
