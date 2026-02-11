<?php
/** @var array $faltantes */
/** @var array $values */

$__title = 'Parametrización';
$__bodyClass = 'page-setup';

$faltantes = is_array($faltantes ?? null) ? $faltantes : [];
$values = is_array($values ?? null) ? $values : [];

function v($k, $values) {
    $val = $values[$k] ?? '';
    return htmlspecialchars((string)$val, ENT_QUOTES);
}

$__mode = strtolower(trim((string)($values['WORKSPACE_MODE'] ?? '')));
$__mode = ($__mode === 'db_and_images' || $__mode === 'db') ? 'db_and_images' : 'images_only';

$__ws = \App\Services\WorkspaceService::current();
$__imagesDir = $__ws ? (\App\Services\WorkspaceService::paths($__ws)['imagesDir'] ?? '') : '';
$__wsSlug = $__ws ? (string)$__ws : '';

$__ignoredCsv = (string)($values['DETECT_IGNORED_LABELS'] ?? '');
$__ignoredSet = [];
foreach (array_filter(array_map('trim', explode(',', $__ignoredCsv))) as $__l) {
    $__k = \App\Services\DetectionLabels::normalizeLabel($__l);
    if ($__k !== '') $__ignoredSet[$__k] = true;
}
$__officialLabels = \App\Services\DetectionLabels::officialLabels();
$__dictEs = \App\Services\DetectionLabels::dictionaryEs();

$__imgColsCsv = (string)($values['COLUMNAS_IMAGEN'] ?? '');
$__imgColsPre = array_values(array_filter(array_map('trim', explode(',', $__imgColsCsv)), fn($x) => $x !== ''));
$__imgColsPreJson = json_encode($__imgColsPre, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$__colIdent = trim((string)($values['CAMPO_IDENTIFICADOR'] ?? ''));
$__colUsr = trim((string)($values['CAMPO_USR_ID'] ?? ''));
$__colRes = trim((string)($values['CAMPO_RESULTADO'] ?? ''));
$__colFecha = trim((string)($values['CAMPO_FECHA'] ?? ''));
$__patronDisplay = preg_replace('/\.ext\s*$/i', '', trim((string)($values['PATRON_MATERIALIZACION'] ?? '')));
?>

<nav class="topnav">
  <a href="?action=index" class="topnav-brand"><i class="fas fa-shield-alt"></i> Clasificador</a>
  <ul class="topnav-links">
    <li><a href="?action=index"><i class="fas fa-layer-group"></i> <?php echo $__wsSlug ? htmlspecialchars($__wsSlug, ENT_QUOTES) : '—'; ?></a></li>
    <li><a href="?action=setup" class="active"><i class="fas fa-cog"></i> Parametrización</a></li>
    <li><a href="?action=workspace_select"><i class="fas fa-layer-group"></i> Workspaces</a></li>
  </ul>
</nav>

<main class="main content">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-6">
        <h1 style="font-size:1.5rem;margin-bottom:0.25rem;">Parametrización</h1>
        <div class="text-muted small">Asistente por workspace: DB opcional, clasificador obligatorio.</div>
      </div>
    </div>

    <?php if (!empty($faltantes)): ?>
      <div class="notice notice-warning">
        <h5><i class="fas fa-exclamation-triangle"></i> Faltan parámetros requeridos</h5>
        <div class="text-monospace"><?php echo htmlspecialchars(implode(', ', $faltantes), ENT_QUOTES); ?></div>
      </div>
    <?php endif; ?>

    <div class="row">
      <div class="col-12 col-lg-6">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title"><i class="fas fa-layer-group"></i> Workspace</h3>
          </div>
              <div class="card-body">
                <div class="custom-control custom-radio">
                  <input class="custom-control-input" type="radio" id="modeImagesOnly" name="WORKSPACE_MODE" value="images_only" <?php echo $__mode === 'images_only' ? 'checked' : ''; ?>>
                  <label for="modeImagesOnly" class="custom-control-label">Solo imágenes</label>
                </div>
                <div class="custom-control custom-radio">
                  <input class="custom-control-input" type="radio" id="modeDbAndImages" name="WORKSPACE_MODE" value="db_and_images" <?php echo $__mode === 'db_and_images' ? 'checked' : ''; ?>>
                  <label for="modeDbAndImages" class="custom-control-label">DB + imágenes</label>
                </div>
                <?php if ($__imagesDir): ?>
                  <div class="mt-2 text-muted small">
                    Directorio fijo de imágenes: <span class="text-monospace"><?php echo htmlspecialchars($__imagesDir, ENT_QUOTES); ?></span>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <div class="col-12 col-lg-6">
            <div class="card card">
              <div class="card-header">
                <h3 class="card-title"><i class="fas fa-images"></i> Imágenes (directorio)</h3>
              </div>
              <div class="card-body">
                <div class="text-muted small">
                  Directorio base fijo: <span class="text-monospace">workspaces/&lt;ws&gt;/images</span>
                </div>
                <div class="alert alert-light mt-2 mb-0" id="patronImagesOnlyInfo">
                  <?php if ($__mode === 'images_only'): ?>
                    Se conserva la estructura de carpetas al subir. No se usa patrón de materialización.
                  <?php else: ?>
                    Las imágenes se materializan según el patrón configurado más abajo.
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="row <?php echo $__mode === 'images_only' ? 'd-none' : ''; ?>" id="setupDbSection">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><i class="fas fa-database"></i> Base de datos (MySQL)</h3>
                <span class="badge badge-secondary" id="stDbChip">Pendiente</span>
              </div>
              <div class="card-body">
                <div class="form-group">
                  <label for="DB_HOST">DB_HOST</label>
                  <input class="form-control" id="DB_HOST" value="<?php echo v('DB_HOST',$values); ?>">
                </div>
                <div class="form-row">
                  <div class="form-group col-md-3">
                    <label for="DB_PORT">DB_PORT</label>
                    <input class="form-control" id="DB_PORT" value="<?php echo v('DB_PORT',$values); ?>">
                  </div>
                  <div class="form-group col-md-9">
                    <label for="DB_NAME">DB_NAME</label>
                    <input class="form-control" id="DB_NAME" value="<?php echo v('DB_NAME',$values); ?>">
                  </div>
                </div>
                <div class="form-row">
                  <div class="form-group col-md-6">
                    <label for="DB_USER">DB_USER</label>
                    <input class="form-control" id="DB_USER" value="<?php echo v('DB_USER',$values); ?>">
                  </div>
                  <div class="form-group col-md-6">
                    <label for="DB_PASS">DB_PASS</label>
                    <input class="form-control" id="DB_PASS" type="password" value="<?php echo v('DB_PASS',$values); ?>">
                  </div>
                </div>
                <button class="btn btn-info" type="button" id="btnTestDb"><i class="fas fa-check-circle"></i> Probar DB</button>
              </div>
            </div>
          </div>
        </div>

        <div class="row <?php echo $__mode === 'images_only' ? 'd-none' : ''; ?>" id="setupSchemaSection">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><i class="fas fa-table"></i> Tablas y columnas</h3>
                <span class="badge badge-secondary" id="stSchemaChip">Pendiente</span>
              </div>
              <div class="card-body">
                <div class="form-row align-items-end">
                  <div class="form-group col-md-9 mb-0">
                    <label for="TABLE_PATTERN">TABLE_PATTERN</label>
                    <input class="form-control" id="TABLE_PATTERN" value="<?php echo v('TABLE_PATTERN',$values); ?>" placeholder="ej: ia_miner, ia_miner?, ia_miner*">
                  </div>
                  <div class="form-group col-md-3 mb-0">
                    <button class="btn btn-info btn-block" type="button" id="btnTestSchema"><i class="fas fa-search"></i> Buscar</button>
                  </div>
                </div>
                <div class="form-row mt-2 mb-3">
                  <div class="col-12">
                    <div class="table-pattern-hint">
                      <div class="table-pattern-hint-line"><strong>Sin comodines</strong> → una sola tabla exacta (ej: <code>ia_miner</code>).</div>
                      <div class="table-pattern-hint-line"><strong><kbd>?</kbd></strong> → un carácter (ej: <code>ia_miner?</code> = ia_miner_1, ia_minera).</div>
                      <div class="table-pattern-hint-line"><strong><kbd>*</kbd></strong> → cero o más caracteres (ej: <code>ia_miner*</code> = ia_miner, ia_miner_xxx; <code>*miner*</code> = cualquier nombre que contenga "miner").</div>
                    </div>
                  </div>
                </div>

                <div class="form-row">
                  <div class="form-group col-md-6">
                    <label for="PRIMARY_KEY">PRIMARY_KEY</label>
                    <select class="form-control" id="PRIMARY_KEY" data-pre="<?php echo v('PRIMARY_KEY',$values); ?>">
                      <option value=""></option>
                    </select>
                  </div>
                  <div class="form-group col-md-6">
                    <label for="CAMPO_IDENTIFICADOR">CAMPO_IDENTIFICADOR</label>
                    <select class="form-control" id="CAMPO_IDENTIFICADOR" data-pre="<?php echo v('CAMPO_IDENTIFICADOR',$values); ?>">
                      <option value=""></option>
                    </select>
                  </div>
                </div>

                <div class="form-row">
                  <div class="form-group col-md-6">
                    <label for="CAMPO_USR_ID">CAMPO_USR_ID</label>
                    <select class="form-control" id="CAMPO_USR_ID" data-pre="<?php echo v('CAMPO_USR_ID',$values); ?>">
                      <option value=""></option>
                    </select>
                  </div>
                  <div class="form-group col-md-6">
                    <label for="CAMPO_FECHA">CAMPO_FECHA</label>
                    <select class="form-control" id="CAMPO_FECHA" data-pre="<?php echo v('CAMPO_FECHA',$values); ?>">
                      <option value=""></option>
                    </select>
                  </div>
                </div>

                <div class="form-group">
                  <label for="CAMPO_RESULTADO">CAMPO_RESULTADO (opcional)</label>
                  <select class="form-control" id="CAMPO_RESULTADO" data-pre="<?php echo v('CAMPO_RESULTADO',$values); ?>">
                    <option value=""></option>
                  </select>
                </div>

                <div class="card card">
                  <div class="card-header">
                    <h3 class="card-title"><i class="far fa-image"></i> Campos de imágenes</h3>
                  </div>
                  <div class="card-body">
                    <div class="form-group">
                      <label for="filtroImgCols">Filtrar columnas</label>
                      <input class="form-control" id="filtroImgCols" type="search" placeholder="Escribe para filtrar...">
                    </div>
                    <div id="imgColsList" class="list-group"></div>
                    <div class="text-muted small mt-2">Se listan solo columnas comunes a todas las tablas encontradas.</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="row <?php echo $__mode === 'db_and_images' ? '' : 'd-none'; ?>" id="patronBlock">
          <div class="col-12">
            <div class="card card">
              <div class="card-header">
                <h3 class="card-title"><i class="fas fa-route"></i> Patrón de materialización</h3>
              </div>
              <div class="card-body">
                <div class="form-group">
                  <label for="PATRON_MATERIALIZACION">PATRON_MATERIALIZACION (opcional)</label>
                  <input class="form-control" id="PATRON_MATERIALIZACION" value="<?php echo htmlspecialchars($__patronDisplay, ENT_QUOTES); ?>">
                  <small class="form-text text-muted">Click en variables para concatenar. La extensión se agrega automáticamente al guardar (no incluyas .ext en el patrón).</small>
                </div>

                <div class="mb-2 text-muted small">
                  Variables disponibles: <span class="text-monospace"><?php echo htmlspecialchars('ID=' . ($__colIdent ?: '—') . ' · USR=' . ($__colUsr ?: '—') . ' · RES=' . ($__colRes ?: '—') . ' · FECHA=' . ($__colFecha ?: '—'), ENT_QUOTES); ?></span>
                </div>

                <div class="btn-group btn-group-sm mb-2" role="group" aria-label="Variables patrón">
                  <button type="button" class="btn btn-outline-secondary patronChip" data-token="{{CAMPO_IDENTIFICADOR}}">Identificador</button>
                  <button type="button" class="btn btn-outline-secondary patronChip" data-token="{{CAMPO_USR_ID}}">Usuario</button>
                  <button type="button" class="btn btn-outline-secondary patronChip" data-token="{{CAMPO_RESULTADO}}">Resultado</button>
                  <button type="button" class="btn btn-outline-secondary patronChip" data-token="{{CAMPO_FECHA}}">Fecha</button>
                </div>
                <div class="btn-group btn-group-sm mb-3" role="group" aria-label="Separadores patrón">
                  <button type="button" class="btn btn-outline-secondary patronChip" data-token="/">/</button>
                  <button type="button" class="btn btn-outline-secondary patronChip" data-token="_">_</button>
                  <button type="button" class="btn btn-outline-secondary patronChip" data-token="-">-</button>
                  <button type="button" class="btn btn-outline-danger" id="btnPatronClear"><i class="fas fa-backspace"></i> Limpiar</button>
                </div>

                <div class="callout callout-info">
                  <h5><i class="fas fa-eye"></i> Previsualización (ejemplo)</h5>
                  <pre class="mb-0" id="patronPreview"><?php echo htmlspecialchars('workspaces/' . ($__wsSlug ?: '<ws>') . '/images/<ruta>', ENT_QUOTES); ?></pre>
                  <div class="small text-muted mt-1" id="patronExtHint"></div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><i class="fas fa-shield-alt"></i> Clasificador</h3>
                <span class="badge badge-secondary" id="stClasChip">Pendiente</span>
              </div>
              <div class="card-body">
                <div class="form-group">
                  <label for="CLASIFICADOR_BASE_URL">CLASIFICADOR_BASE_URL</label>
                  <input class="form-control" id="CLASIFICADOR_BASE_URL" value="<?php echo v('CLASIFICADOR_BASE_URL',$values); ?>" placeholder="http://localhost:8001/">
                  <small class="form-text text-muted">Se marca <span class="text-monospace">unsafe</span> si el detector devuelve cualquier etiqueta no ignorada.</small>
                </div>

                <div class="card">
                  <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-ban"></i> Etiquetas ignoradas</h3>
                    <span class="badge badge-info" id="ignoredCountChip">Seleccionadas: 0</span>
                    <button class="btn btn-outline-secondary btn-sm ml-2" type="button" id="btnEditIgnored"><i class="fas fa-sliders-h"></i> Editar</button>
                  </div>
                  <div class="card-body">
                    <div class="text-muted small mb-2">
                      Selecciona etiquetas para <strong>ignorar</strong> del detector (lista negra).
                    </div>
                    <div id="ignoredSelectedPreview"></div>
                  </div>
                </div>

                <button class="btn btn-primary" type="button" id="btnTestClas"><i class="fas fa-heartbeat"></i> Probar clasificador</button>
              </div>
            </div>
          </div>
        </div>

        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-body d-flex align-items-center">
                <span class="badge badge-secondary mr-2" id="stSaveChip">Listo</span>
                <div class="ml-auto">
                  <button class="btn btn-success" type="button" id="btnSave"><i class="fas fa-save"></i> Guardar configuración</button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
</main>

<!-- Modal: etiquetas ignoradas -->
<div class="modal fade" id="ignoredModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-ban"></i> Etiquetas ignoradas</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label for="ignoredSearch">Buscar etiqueta</label>
          <input class="form-control" id="ignoredSearch" type="search" placeholder="Nombre o código...">
        </div>
        <div id="ignoredList" class="row">
          <?php foreach ($__officialLabels as $__lab): ?>
            <?php
              $__code = \App\Services\DetectionLabels::normalizeLabel($__lab);
              $__name = $__dictEs[$__code] ?? $__code;
              $__checked = isset($__ignoredSet[$__code]);
              $__search = strtolower($__name . ' ' . $__code);
            ?>
            <div class="col-12 col-md-6 ignoredItem" data-search="<?php echo htmlspecialchars($__search, ENT_QUOTES); ?>">
              <div class="custom-control custom-checkbox">
                <input class="custom-control-input ignoredCheck" type="checkbox" id="ig_<?php echo htmlspecialchars($__code, ENT_QUOTES); ?>" data-ignored-label="<?php echo htmlspecialchars($__code, ENT_QUOTES); ?>" <?php echo $__checked ? 'checked' : ''; ?>>
                <label class="custom-control-label" for="ig_<?php echo htmlspecialchars($__code, ENT_QUOTES); ?>">
                  <?php echo htmlspecialchars($__name, ENT_QUOTES); ?>
                  <?php if ($__name !== $__code): ?>
                    <div class="text-muted small text-monospace"><?php echo htmlspecialchars($__code, ENT_QUOTES); ?></div>
                  <?php endif; ?>
                </label>
              </div>
              <hr>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" type="button" id="btnIgnoredClear"><i class="fas fa-eraser"></i> Limpiar</button>
        <button class="btn btn-primary" type="button" data-dismiss="modal"><i class="fas fa-check"></i> Listo</button>
      </div>
    </div>
  </div>
</div>

<script>
  const PRE_IMG_COLS = <?php echo $__imgColsPreJson ?: '[]'; ?>;
  const WS_SLUG = <?php echo json_encode(($__wsSlug ?: '<ws>'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

  const btnTestDb = document.getElementById('btnTestDb');
  const btnTestSchema = document.getElementById('btnTestSchema');
  const btnTestClas = document.getElementById('btnTestClas');
  const btnSave = document.getElementById('btnSave');

  const stDbChip = document.getElementById('stDbChip');
  const stSchemaChip = document.getElementById('stSchemaChip');
  const stClasChip = document.getElementById('stClasChip');
  const stSaveChip = document.getElementById('stSaveChip');

  const modeImagesOnly = document.getElementById('modeImagesOnly');
  const modeDbAndImages = document.getElementById('modeDbAndImages');

  const setupDbSection = document.getElementById('setupDbSection');
  const setupSchemaSection = document.getElementById('setupSchemaSection');
  const patronBlock = document.getElementById('patronBlock');
  const patronImagesOnlyInfo = document.getElementById('patronImagesOnlyInfo');

  const patronField = document.getElementById('PATRON_MATERIALIZACION');
  const patronPreview = document.getElementById('patronPreview');
  const patronExtHint = document.getElementById('patronExtHint');
  const btnPatronClear = document.getElementById('btnPatronClear');

  const btnEditIgnored = document.getElementById('btnEditIgnored');
  const ignoredCountChip = document.getElementById('ignoredCountChip');
  const ignoredSelectedPreview = document.getElementById('ignoredSelectedPreview');
  const ignoredSearch = document.getElementById('ignoredSearch');
  const btnIgnoredClear = document.getElementById('btnIgnoredClear');

  const imgColsList = document.getElementById('imgColsList');
  const filtroImgCols = document.getElementById('filtroImgCols');

  const okState = { db:false, schema:false, clas:false };

  function getMode() {
    return (modeDbAndImages && modeDbAndImages.checked) ? 'db_and_images' : 'images_only';
  }

  function setBadge(el, state, label) {
    if (!el) return;
    el.textContent = label || '';
    el.classList.remove('badge-success','badge-danger','badge-secondary','badge-info','badge-warning');
    if (state === 'ok') el.classList.add('badge-success');
    else if (state === 'bad') el.classList.add('badge-danger');
    else el.classList.add('badge-secondary');
  }

  function el(id) { return document.getElementById(id); }
  function getText(id) { return String(el(id)?.value || '').trim(); }
  function setDisabled(ids, disabled) { ids.forEach(id => { const x = el(id); if (x) x.disabled = !!disabled; }); }

  function getIgnoredLabels() {
    const items = [];
    document.querySelectorAll('input.ignoredCheck[data-ignored-label]').forEach(cb => {
      if (cb.checked) items.push(String(cb.getAttribute('data-ignored-label') || ''));
    });
    return items.filter(Boolean);
  }

  function updateIgnoredSummary() {
    const selected = [];
    document.querySelectorAll('input.ignoredCheck[data-ignored-label]').forEach(cb => {
      if (cb.checked) {
        const code = String(cb.getAttribute('data-ignored-label') || '');
        const name = String(cb.parentElement?.querySelector('label')?.childNodes?.[0]?.textContent || '').trim() || code;
        selected.push({ code, name });
      }
    });
    if (ignoredCountChip) ignoredCountChip.textContent = `Seleccionadas: ${selected.length}`;

    if (!ignoredSelectedPreview) return;
    ignoredSelectedPreview.innerHTML = '';
    if (selected.length === 0) {
      const s = document.createElement('span');
      s.className = 'badge badge-light';
      s.textContent = 'Ninguna';
      ignoredSelectedPreview.appendChild(s);
      return;
    }
    const max = 12;
    selected.slice(0, max).forEach(it => {
      const b = document.createElement('span');
      b.className = 'badge badge-secondary mr-1 mb-1';
      b.title = it.code;
      b.textContent = it.name;
      ignoredSelectedPreview.appendChild(b);
    });
    if (selected.length > max) {
      const b = document.createElement('span');
      b.className = 'badge badge-light mr-1 mb-1';
      b.textContent = `+${selected.length - max} más`;
      ignoredSelectedPreview.appendChild(b);
    }
  }

  function getImgCols() {
    const items = [];
    imgColsList?.querySelectorAll('input[data-img-col]').forEach(cb => {
      if (cb.checked) items.push(String(cb.getAttribute('data-img-col') || ''));
    });
    return items.filter(Boolean);
  }

  function getPayload() {
    return {
      WORKSPACE_MODE: getMode(),
      DB_HOST: getText('DB_HOST'),
      DB_PORT: getText('DB_PORT'),
      DB_NAME: getText('DB_NAME'),
      DB_USER: getText('DB_USER'),
      DB_PASS: getText('DB_PASS'),
      TABLE_PATTERN: getText('TABLE_PATTERN'),
      PRIMARY_KEY: getText('PRIMARY_KEY'),
      CAMPO_IDENTIFICADOR: getText('CAMPO_IDENTIFICADOR'),
      CAMPO_USR_ID: getText('CAMPO_USR_ID'),
      CAMPO_FECHA: getText('CAMPO_FECHA'),
      CAMPO_RESULTADO: getText('CAMPO_RESULTADO'),
      COLUMNAS_IMAGEN: getImgCols(),
      PATRON_MATERIALIZACION: getText('PATRON_MATERIALIZACION'),
      CLASIFICADOR_BASE_URL: getText('CLASIFICADOR_BASE_URL'),
      DETECT_IGNORED_LABELS: getIgnoredLabels(),
    };
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

  async function autosaveSection(module) {
    const payload = getPayload();
    payload.module = module;
    return await postJson('setup_save_section', payload);
  }

  function syncModeUI() {
    const disableDb = (getMode() !== 'db_and_images');
    if (setupDbSection) setupDbSection.classList.toggle('d-none', disableDb);
    if (setupSchemaSection) setupSchemaSection.classList.toggle('d-none', disableDb);
    if (patronBlock) patronBlock.classList.toggle('d-none', disableDb);
    if (patronImagesOnlyInfo) {
      patronImagesOnlyInfo.textContent = disableDb
        ? 'Se conserva la estructura de carpetas al subir. No se usa patrón de materialización.'
        : 'Las imágenes se materializan según el patrón configurado más abajo.';
    }

    setDisabled(['DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_PASS','TABLE_PATTERN','PRIMARY_KEY','CAMPO_IDENTIFICADOR','CAMPO_USR_ID','CAMPO_FECHA','CAMPO_RESULTADO','filtroImgCols'], disableDb);
    if (btnTestDb) btnTestDb.disabled = disableDb;
    if (btnTestSchema) btnTestSchema.disabled = disableDb;
    imgColsList?.querySelectorAll('input[data-img-col]').forEach(cb => cb.disabled = disableDb);

    setBadge(stDbChip, disableDb ? 'neutral' : (okState.db ? 'ok' : 'neutral'), disableDb ? 'N/A' : (okState.db ? 'OK' : 'Pendiente'));
    setBadge(stSchemaChip, disableDb ? 'neutral' : (okState.schema ? 'ok' : 'neutral'), disableDb ? 'N/A' : (okState.schema ? 'OK' : 'Pendiente'));
    updatePatronPreview();
  }

  function getPatron() { return String(patronField?.value || '').trim(); }
  function setPatron(v) { if (patronField) patronField.value = String(v ?? ''); }
  function appendToken(token) {
    token = String(token || '');
    if (!token) return;
    let p = getPatron();
    const isSep = (token === '/' || token === '_' || token === '-' || token === '__');
    if (!p) p = token;
    else if (isSep) p = p + token;
    else {
      if (!/[\/_\-]$/.test(p)) p += '_';
      p += token;
    }
    setPatron(p);
    updatePatronPreview();
  }
  function renderPreview(p) {
    p = String(p || '').trim();
    if (!p) return { ok: true, text: `workspaces/${WS_SLUG}/images/<default>`, hint: 'Vacío: se usará el patrón default.' };
    const sample = { ident:'identificador_01', usr:'001', res:'sin_resultado', fecha:'2024_01_15', ext:'jpg' };
    let out = p;
    out = out.replace(/\{\{CAMPO_IDENTIFICADOR\}\}/g, sample.ident);
    out = out.replace(/\{\{CAMPO_USR_ID\}\}/g, sample.usr);
    out = out.replace(/\{\{CAMPO_RESULTADO\}\}/g, sample.res);
    out = out.replace(/\{\{CAMPO_FECHA\}\}/g, sample.fecha);
    out = out.replace(/\.ext\b/ig, '.' + sample.ext);
    if (!/\.(jpe?g|png|gif|webp|bmp|tiff?|avif|heic|heif|ico|svg)$/i.test(out)) out += '.' + sample.ext;
    return { ok: true, text: `workspaces/${WS_SLUG}/images/` + out, hint: 'La extensión se agregará al guardar.' };
  }
  function updatePatronPreview() {
    if (!patronPreview || !patronExtHint) return;
    const { ok, text, hint } = renderPreview(getPatron());
    patronPreview.textContent = text;
    patronExtHint.textContent = hint;
    patronExtHint.className = ok ? 'small text-muted' : 'small text-danger';
  }

  function fillSelectOptions(selectId, cols) {
    const s = el(selectId);
    if (!s) return;
    const pre = String(s.getAttribute('data-pre') || '').trim();
    s.innerHTML = '<option value=""></option>';
    (cols || []).forEach(c => {
      const opt = document.createElement('option');
      opt.value = c;
      opt.textContent = c;
      s.appendChild(opt);
    });
    if (pre) s.value = pre;
  }

  function renderImgCols(cols) {
    if (!imgColsList) return;
    imgColsList.innerHTML = '';
    const preSet = new Set((PRE_IMG_COLS || []).map(x => String(x).toLowerCase()));
    (cols || []).forEach((c) => {
      const id = 'imgcol_' + String(c).replace(/[^a-z0-9_]+/gi,'_');
      const item = document.createElement('div');
      item.className = 'list-group-item';
      item.innerHTML = `
        <div class="custom-control custom-checkbox">
          <input type="checkbox" class="custom-control-input" id="${id}" data-img-col="${String(c).replace(/"/g,'&quot;')}">
          <label class="custom-control-label" for="${id}">${String(c).replace(/</g,'&lt;')}</label>
        </div>
      `;
      const cb = item.querySelector('input[data-img-col]');
      if (cb) cb.checked = preSet.has(String(c).toLowerCase());
      imgColsList.appendChild(item);
    });
  }

  if (filtroImgCols) {
    filtroImgCols.addEventListener('input', () => {
      const q = String(filtroImgCols.value || '').toLowerCase().trim();
      imgColsList?.querySelectorAll('.list-group-item').forEach(item => {
        const txt = (item.textContent || '').toLowerCase();
        item.style.display = (!q || txt.includes(q)) ? '' : 'none';
      });
    });
  }

  document.querySelectorAll('.patronChip[data-token]').forEach(btn => {
    btn.addEventListener('click', () => appendToken(btn.getAttribute('data-token') || ''));
  });
  if (btnPatronClear) btnPatronClear.addEventListener('click', () => { setPatron(''); updatePatronPreview(); });
  if (patronField) {
    patronField.addEventListener('input', updatePatronPreview);
    patronField.addEventListener('blur', () => { const p = getPatron(); if (p) setPatron(ensureExt(p)); updatePatronPreview(); });
  }

  if (btnEditIgnored) btnEditIgnored.addEventListener('click', () => { if (window.jQuery) window.jQuery('#ignoredModal').modal('show'); });
  if (btnIgnoredClear) btnIgnoredClear.addEventListener('click', () => { document.querySelectorAll('input.ignoredCheck').forEach(cb => cb.checked = false); updateIgnoredSummary(); });
  document.querySelectorAll('input.ignoredCheck').forEach(cb => cb.addEventListener('change', updateIgnoredSummary));
  if (ignoredSearch) {
    ignoredSearch.addEventListener('input', () => {
      const q = String(ignoredSearch.value || '').toLowerCase().trim();
      document.querySelectorAll('.ignoredItem[data-search]').forEach(it => {
        const s = String(it.getAttribute('data-search') || '');
        it.style.display = (!q || s.includes(q)) ? '' : 'none';
      });
    });
  }

  if (modeImagesOnly) modeImagesOnly.addEventListener('change', syncModeUI);
  if (modeDbAndImages) modeDbAndImages.addEventListener('change', syncModeUI);

  if (btnTestDb) btnTestDb.addEventListener('click', async () => {
    setBadge(stDbChip, 'neutral', 'Probando...');
    okState.db = false;
    const { ok, data } = await postJson('setup_test_db', getPayload());
    if (ok && data.ok) { okState.db = true; await autosaveSection('db'); setBadge(stDbChip,'ok','OK'); }
    else setBadge(stDbChip,'bad', data.error || 'Error');
    syncModeUI();
  });

  if (btnTestSchema) btnTestSchema.addEventListener('click', async () => {
    setBadge(stSchemaChip, 'neutral', 'Buscando...');
    okState.schema = false;
    const { ok, data } = await postJson('setup_test_schema', getPayload());
    if (ok && data.ok) {
      okState.schema = true;
      const cols = Array.isArray(data.common_columns) ? data.common_columns : [];
      fillSelectOptions('PRIMARY_KEY', cols);
      fillSelectOptions('CAMPO_IDENTIFICADOR', cols);
      fillSelectOptions('CAMPO_USR_ID', cols);
      fillSelectOptions('CAMPO_FECHA', cols);
      fillSelectOptions('CAMPO_RESULTADO', cols);
      renderImgCols(cols);
      await autosaveSection('schema');
      setBadge(stSchemaChip,'ok','OK');
    } else {
      setBadge(stSchemaChip,'bad', data.error || 'Error');
    }
    syncModeUI();
  });

  if (btnTestClas) btnTestClas.addEventListener('click', async () => {
    setBadge(stClasChip, 'neutral', 'Probando...');
    okState.clas = false;
    const { ok, data } = await postJson('setup_test_clasificador', getPayload());
    if (ok && data.ok) { okState.clas = true; await autosaveSection('clasificador'); setBadge(stClasChip,'ok','OK'); }
    else setBadge(stClasChip,'bad', data.error || 'Error');
  });

  if (btnSave) btnSave.addEventListener('click', async () => {
    setBadge(stSaveChip, 'neutral', 'Guardando...');
    btnSave.disabled = true;
    try {
      const { ok, data } = await postJson('setup_save', getPayload());
      if (!ok || !data.ok) { setBadge(stSaveChip,'bad', data.error || 'Error'); return; }
      setBadge(stSaveChip,'ok', data.warning ? String(data.warning) : 'Guardado');
      setTimeout(() => (window.location.href = '?action=index'), 250);
    } finally { btnSave.disabled = false; }
  });

  // init
  try { if (!getText('DB_PORT')) el('DB_PORT').value = '3306'; } catch (e) {}
  syncModeUI();
  updatePatronPreview();
  updateIgnoredSummary();

  // Si ya está parametrizado (TABLE_PATTERN y modo DB), cargar columnas y pre-seleccionar opciones guardadas
  (async function loadSchemaIfConfigured() {
    if (getMode() !== 'db_and_images') return;
    if (!getText('TABLE_PATTERN')) return;
    const { ok, data } = await postJson('setup_test_schema', getPayload());
    if (ok && data.ok && Array.isArray(data.common_columns)) {
      const cols = data.common_columns;
      fillSelectOptions('PRIMARY_KEY', cols);
      fillSelectOptions('CAMPO_IDENTIFICADOR', cols);
      fillSelectOptions('CAMPO_USR_ID', cols);
      fillSelectOptions('CAMPO_FECHA', cols);
      fillSelectOptions('CAMPO_RESULTADO', cols);
      renderImgCols(cols);
      okState.schema = true;
      setBadge(stSchemaChip, 'ok', 'OK');
    }
  })();
</script>

