<?php
/**
 * Componente único del buscador. Misma apariencia en todas partes.
 * Donde lo importes (index, workspace, otra vista), se ve exactamente igual.
 * Solo cambian parámetros de integración: suffix para IDs, acordeonId/acordeonClass, ids de listas.
 *
 * Uso: definir $buscador antes del include:
 *   suffix: sufijo para IDs (ej. '' o 'Global') para poder tener varias instancias
 *   acordeonId: id del contenedor
 *   acordeonClass: clases del contenedor (ej. añadir 'ws-search-acordeon' en sidebar)
 *   idLstResultadosEtiq: id del list-group de resultados por etiqueta
 *   idTagsEtiquetasEmpty: id del div vacío de tags
 *   emptyTagsText: texto inicial cuando no hay tags (ej. '' o 'Cargando etiquetas…')
 */
$b = $buscador ?? [];
$suffix = (string)($b['suffix'] ?? '');
$acordeonId = (string)($b['acordeonId'] ?? 'buscadorAcordeon');
$acordeonClass = (string)($b['acordeonClass'] ?? 'buscador-acordeon expanded-carpetas');
$idLstResultadosEtiq = (string)($b['idLstResultadosEtiq'] ?? 'lstResultadosEtiq' . $suffix);
$idTagsEtiquetasEmpty = (string)($b['idTagsEtiquetasEmpty'] ?? 'tagsEtiquetasEmpty' . $suffix);
$emptyTagsText = (string)($b['emptyTagsText'] ?? '');

$id = function ($base) use ($suffix) { return $base . $suffix; };
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
?>
<div class="<?php echo $h($acordeonClass); ?>" id="<?php echo $h($acordeonId); ?>">
  <div class="acordeon-item" data-acordeon="carpetas">
    <div class="acordeon-header">
      <span><i class="fas fa-folder-open"></i> Buscar por carpeta</span>
    </div>
    <div class="acordeon-body">
      <div class="acordeon-body-inner">
        <div class="form-group mb-2">
          <div class="input-group">
            <input type="text" class="form-control" id="txtBuscarCarpeta<?php echo $h($suffix); ?>" placeholder="Escribe para buscar (mín. 3 caracteres)" autocomplete="off">
            <div class="input-group-append">
              <button type="button" class="btn btn-outline-secondary btn-buscar-carpetas" id="btnBuscarCarpeta<?php echo $h($suffix); ?>" title="Buscar"><i class="fas fa-search"></i> Buscar</button>
            </div>
          </div>
          <small class="form-text text-muted" id="stBuscarCarpeta<?php echo $h($suffix); ?>"></small>
        </div>
        <div class="buscador-results-wrap">
          <div class="list-group list-group-flex" id="lstCarpetas<?php echo $h($suffix); ?>"></div>
        </div>
      </div>
    </div>
  </div>
  <div class="acordeon-item" data-acordeon="moderacion">
    <div class="acordeon-header">
      <span><i class="fas fa-shield-alt"></i> Filtrar por moderación</span>
    </div>
    <div class="acordeon-body">
      <div class="acordeon-body-inner">
        <p class="moderacion-tags-intro small text-muted mb-3">Clic en una o más etiquetas para filtrar. Agrupadas por nivel.</p>
        <div id="lstEtiquetasModeracion<?php echo $h($suffix); ?>" class="moderacion-tags-wrap mb-2"></div>
        <small class="form-text text-muted" id="stModeracionBuscar<?php echo $h($suffix); ?>"></small>
        <div class="grid-resultados-moderacion-wrap mt-2">
          <div id="gridResultadosModeracion<?php echo $h($suffix); ?>" class="row grid-resultados-moderacion"></div>
        </div>
        <div id="paginacionModeracion<?php echo $h($suffix); ?>" class="paginacion-moderacion mt-2 d-none">
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span class="small text-muted" id="stPaginacionModeracion<?php echo $h($suffix); ?>">Página 1 de 1</span>
            <div>
              <button type="button" class="btn btn-sm btn-outline-secondary paginacion-prev" id="btnModeracionPrev<?php echo $h($suffix); ?>">Anterior</button>
              <button type="button" class="btn btn-sm btn-outline-secondary paginacion-next ml-1" id="btnModeracionNext<?php echo $h($suffix); ?>">Siguiente</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
