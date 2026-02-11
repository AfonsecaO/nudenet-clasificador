/**
 * Módulo compartido para modales de carpeta y visor de imagen.
 * Usado por index (workspace desde cookie) y por workspace (workspace por resultado).
 */
(function () {
  'use strict';

  let cfg = null;
  let lastFolder = null;
  let folderFiles = [];
  let folderFilter = 'ALL';
  let folderSelectedImageRuta = '';
  let lastFolderStacked = null;
  let folderFilesStacked = [];
  let folderSelectedImageRutaStacked = '';
  let currentWorkspace = null;
  let visor = { ruta: '', archivo: '', rutaRelativa: '', img: null, detections: [] };

  function tagLabelToFullText(label) {
    return String(label || '')
      .replace(/_/g, ' ')
      .toLowerCase()
      .replace(/\b\w/g, function (c) { return c.toUpperCase(); });
  }

  function buildTagButton(label, active) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'tag-chip' + (active ? ' tag-chip-active' : '');
    b.textContent = label;
    return b;
  }

  function showModal(el) {
    if (cfg && typeof cfg.showModal === 'function') cfg.showModal(el);
    else if (window.jQuery && el) window.jQuery(el).modal('show');
  }

  function hideModal(el) {
    if (cfg && typeof cfg.hideModal === 'function') cfg.hideModal(el);
    else if (window.jQuery && el) window.jQuery(el).modal('hide');
  }

  function buildUrl(url, workspace) {
    return cfg && typeof cfg.buildUrlWithWorkspace === 'function'
      ? cfg.buildUrlWithWorkspace(url, workspace)
      : url;
  }

  function fileMatchesFilter(f) {
    if (folderFilter === 'ALL') return true;
    if (folderFilter === 'PENDIENTE') return !!f?.pendiente;
    const tt = Array.isArray(f?.tags) ? f.tags : [];
    for (let i = 0; i < tt.length; i++) {
      if (String(tt[i] || '').trim().toUpperCase() === folderFilter) return true;
    }
    return false;
  }

  function renderFolderTags() {
    const tagsCarpeta = cfg?.refs?.tagsCarpeta;
    if (!tagsCarpeta) return;
    tagsCarpeta.innerHTML = '';
    const tagsSet = {};
    for (let i = 0; i < folderFiles.length; i++) {
      const f = folderFiles[i];
      if (f?.pendiente) tagsSet['PENDIENTE'] = true;
      const tt = Array.isArray(f?.tags) ? f.tags : [];
      for (let j = 0; j < tt.length; j++) {
        const s = String(tt[j] || '').trim().toUpperCase();
        if (s) tagsSet[s] = true;
      }
    }
    const tags = Object.keys(tagsSet).sort(function (a, b) { return a.localeCompare(b); });
    const allBtn = buildTagButton('Todas', folderFilter === 'ALL');
    allBtn.addEventListener('click', function () {
      folderFilter = 'ALL';
      renderFolderTags();
      renderFolderGrid();
    });
    tagsCarpeta.appendChild(allBtn);
    for (let i = 0; i < tags.length; i++) {
      const t = tags[i];
      const displayLabel = t === 'PENDIENTE' ? 'Pendiente' : tagLabelToFullText(t);
      const b = buildTagButton(displayLabel, folderFilter === t);
      b.addEventListener('click', function () {
        folderFilter = t;
        renderFolderTags();
        renderFolderGrid();
      });
      tagsCarpeta.appendChild(b);
    }
  }

  function renderFolderGrid() {
    const gridThumbs = cfg?.refs?.gridThumbs;
    const r = cfg?.refs;
    if (!gridThumbs || !r) return;
    gridThumbs.innerHTML = '';
    const files = folderFiles.filter(function (f) { return f?.es_imagen && fileMatchesFilter(f); });
    if (!files.length) {
      const div = document.createElement('div');
      div.className = 'col-12 text-muted';
      div.textContent = 'No hay imágenes para el filtro seleccionado';
      gridThumbs.appendChild(div);
      return;
    }
    const ws = currentWorkspace;
    for (let i = 0; i < files.length; i++) {
      const f = files[i];
      const nombre = String(f?.nombre || '').trim();
      const rutaRel = String(f?.ruta_relativa || '').trim();
      const tags = Array.isArray(f?.tags) ? f.tags : [];
      const tagLabels = tags.map(function (t) { return String(t || '').trim(); }).filter(Boolean);
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
      img.src = buildUrl('?action=ver_imagen&ruta=' + encodeURIComponent(lastFolder?.ruta || '') + '&archivo=' + encodeURIComponent(nombre) + '&thumb=1&w=240', ws);
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
        for (let k = 0; k < Math.min(5, tagLabels.length); k++) {
          const chip = document.createElement('span');
          chip.className = 'tag-chip-inline';
          chip.textContent = (tagLabels[k] === 'PENDIENTE' ? 'Pendiente' : tagLabelToFullText(tagLabels[k])).replace(/</g, '\u200b');
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
      a.addEventListener('click', async function (e) {
        e.preventDefault();
        await BuscadorModals.openVisor(lastFolder?.ruta || '', nombre, rutaRel, ws);
      });
      card.appendChild(a);
      col.appendChild(card);
      gridThumbs.appendChild(col);
    }
  }

  function renderFolderGridStacked() {
    const gridThumbsStacked = cfg?.refs?.gridThumbsStacked;
    const r = cfg?.refs;
    if (!gridThumbsStacked || !r) return;
    gridThumbsStacked.innerHTML = '';
    const files = folderFilesStacked.filter(function (f) { return f?.es_imagen; });
    if (!files.length) {
      const div = document.createElement('div');
      div.className = 'col-12 text-muted';
      div.textContent = 'No hay imágenes';
      gridThumbsStacked.appendChild(div);
      return;
    }
    const ws = currentWorkspace;
    for (let i = 0; i < files.length; i++) {
      const f = files[i];
      const nombre = String(f?.nombre || '').trim();
      const rutaRel = String(f?.ruta_relativa || '').trim();
      const rutaCarpeta = String(lastFolderStacked?.ruta || '').trim();
      const tags = Array.isArray(f?.tags) ? f.tags : [];
      const tagLabels = tags.map(function (t) { return String(t || '').trim(); }).filter(Boolean);
      if (f?.pendiente) tagLabels.unshift('PENDIENTE');

      const col = document.createElement('div');
      col.className = 'col-6 col-sm-4 col-md-3 col-lg-2 mb-3';
      col.dataset.rutaRelativa = rutaRel;
      const card = document.createElement('div');
      card.className = 'thumb-card' + (rutaRel === folderSelectedImageRutaStacked ? ' thumb-card-selected' : '');
      const a = document.createElement('a');
      a.href = '#';
      const imgWrap = document.createElement('div');
      imgWrap.className = 'thumb-card-img';
      const img = document.createElement('img');
      img.alt = nombre;
      img.loading = 'lazy';
      img.src = buildUrl('?action=ver_imagen&ruta=' + encodeURIComponent(rutaCarpeta) + '&archivo=' + encodeURIComponent(nombre) + '&thumb=1&w=240', ws);
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
        for (let k = 0; k < Math.min(5, tagLabels.length); k++) {
          const chip = document.createElement('span');
          chip.className = 'tag-chip-inline';
          chip.textContent = (tagLabels[k] === 'PENDIENTE' ? 'Pendiente' : tagLabelToFullText(tagLabels[k])).replace(/</g, '\u200b');
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
      a.addEventListener('click', async function (e) {
        e.preventDefault();
        await BuscadorModals.openVisor(rutaCarpeta, nombre, rutaRel, ws);
      });
      card.appendChild(a);
      col.appendChild(card);
      gridThumbsStacked.appendChild(col);
    }
  }

  function renderDetBadges(dets, pending) {
    const badgesDet = cfg?.refs?.badgesDet;
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
    for (let i = 0; i < top.length; i++) {
      const d = top[i];
      const lab = String(d?.label || '').trim();
      const sc = Number(d?.score || 0);
      if (!lab) continue;
      const b = document.createElement('span');
      b.className = 'badge badge-light mr-1';
      b.textContent = lab + ' ' + sc.toFixed(3);
      badgesDet.appendChild(b);
    }
  }

  function drawCanvas() {
    const cnv = cfg?.refs?.cnv;
    const swBoxes = cfg?.refs?.swBoxes;
    if (!cnv) return;
    if (!visor.img) return;
    const img = visor.img;
    const show = !!(swBoxes && swBoxes.checked);
    const ctx = cnv.getContext('2d');
    if (!ctx) return;

    const naturalW = img.naturalWidth || img.width || 1;
    const naturalH = img.naturalHeight || img.height || 1;
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
    ctx.font = Math.round(fontSize) + 'px "Source Sans Pro", sans-serif';
    for (let i = 0; i < dets.length; i++) {
      const d = dets[i];
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
      const text = label ? label + ' ' + score.toFixed(3) : score.toFixed(3);
      ctx.fillStyle = '#dc3545';
      ctx.fillText(text, sx1 + 4, Math.max(fontSize + 2, sy1 - 4));
    }
  }

  async function openFolder(nombre, ruta, selectedImageRutaRelativa, workspace) {
    if (!cfg?.getJson || !cfg?.refs) return;
    currentWorkspace = workspace || null;
    folderSelectedImageRuta = String(selectedImageRutaRelativa || '').trim();
    lastFolder = { nombre: String(nombre || ''), ruta: String(ruta || '') };
    folderFilter = 'ALL';
    folderFiles = [];

    const ttlCarpeta = cfg.refs.ttlCarpeta;
    const tagsCarpeta = cfg.refs.tagsCarpeta;
    const gridThumbs = cfg.refs.gridThumbs;
    if (ttlCarpeta) ttlCarpeta.textContent = lastFolder.nombre || 'Carpeta';
    if (tagsCarpeta) tagsCarpeta.innerHTML = '';
    if (gridThumbs) gridThumbs.innerHTML = '';

    showModal(cfg.refs.modalCarpeta);

    const url = buildUrl('?action=ver_carpeta&ruta=' + encodeURIComponent(lastFolder.ruta), currentWorkspace);
    const result = await cfg.getJson(url);
    const ok = result?.ok;
    const data = result?.data;

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
      const col = gridThumbs.querySelector('[data-ruta-relativa="' + CSS.escape(folderSelectedImageRuta) + '"]');
      if (col) {
        col.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
        const card = col.querySelector('.thumb-card');
        if (card) {
          card.classList.add('thumb-card-selected');
          setTimeout(function () { card.classList.remove('thumb-card-selected'); }, 1300);
        }
      }
      folderSelectedImageRuta = '';
    }
  }

  async function openVisor(ruta, archivo, rutaRelativa, workspace) {
    if (!cfg?.getJson || !cfg?.refs) return;
    currentWorkspace = workspace || null;
    visor.ruta = String(ruta || '');
    visor.archivo = String(archivo || '');
    visor.rutaRelativa = String(rutaRelativa || '');
    visor.detections = [];
    visor.img = null;

    const ttlImagen = cfg.refs.ttlImagen;
    const ttlImagenWrap = cfg.refs.ttlImagenWrap;
    const lnkAbrirOriginal = cfg.refs.lnkAbrirOriginal;
    const stVisor = cfg.refs.stVisor;

    if (ttlImagen) {
      ttlImagen.textContent = visor.archivo || 'Imagen';
      if (ttlImagenWrap) ttlImagenWrap.title = visor.archivo || '';
    }
    if (stVisor) stVisor.textContent = 'Cargando…';
    if (lnkAbrirOriginal) lnkAbrirOriginal.href = buildUrl('?action=ver_imagen&ruta=' + encodeURIComponent(visor.ruta) + '&archivo=' + encodeURIComponent(visor.archivo), currentWorkspace);

    renderDetBadges([], true);
    showModal(cfg.refs.modalVisor);

    const img = new Image();
    img.onload = async function () {
      visor.img = img;
      const detUrl = buildUrl('?action=imagen_detecciones&ruta_relativa=' + encodeURIComponent(visor.rutaRelativa), currentWorkspace);
      const res = await cfg.getJson(detUrl);
      const pending = !!res?.data?.pending;
      const dets = Array.isArray(res?.data?.detections) ? res.data.detections : [];
      visor.detections = dets;
      renderDetBadges(dets, pending);
      drawCanvas();
      if (typeof cfg.setStatus === 'function' && stVisor) cfg.setStatus(stVisor, 'ok', pending ? 'Pendiente de procesamiento' : 'OK');
    };
    img.onerror = function () {
      if (typeof cfg.setStatus === 'function' && stVisor) cfg.setStatus(stVisor, 'bad', 'No se pudo cargar la imagen');
    };
    img.src = buildUrl('?action=ver_imagen&ruta=' + encodeURIComponent(visor.ruta) + '&archivo=' + encodeURIComponent(visor.archivo), currentWorkspace);
  }

  async function openFolderStacked(nombre, ruta, selectedImageRutaRelativa, workspace) {
    if (!cfg?.getJson || !cfg?.refs) return;
    currentWorkspace = workspace || null;
    folderSelectedImageRutaStacked = String(selectedImageRutaRelativa || '').trim();
    lastFolderStacked = { nombre: String(nombre || ''), ruta: String(ruta || '') };
    folderFilesStacked = [];

    const ttlCarpetaStacked = cfg.refs.ttlCarpetaStacked;
    const gridThumbsStacked = cfg.refs.gridThumbsStacked;
    if (ttlCarpetaStacked) ttlCarpetaStacked.textContent = lastFolderStacked.nombre || 'Carpeta';
    if (gridThumbsStacked) gridThumbsStacked.innerHTML = '';

    showModal(cfg.refs.modalCarpetaStacked);

    const url = buildUrl('?action=ver_carpeta&ruta=' + encodeURIComponent(lastFolderStacked.ruta), currentWorkspace);
    const result = await cfg.getJson(url);
    const ok = result?.ok;
    const data = result?.data;

    if (!ok || !data?.success) {
      if (gridThumbsStacked) {
        const div = document.createElement('div');
        div.className = 'col-12 text-danger';
        div.textContent = String(data?.error || 'Error');
        gridThumbsStacked.appendChild(div);
      }
      folderSelectedImageRutaStacked = '';
      return;
    }
    folderFilesStacked = Array.isArray(data.archivos) ? data.archivos : [];
    renderFolderGridStacked();

    if (folderSelectedImageRutaStacked && gridThumbsStacked) {
      const col = gridThumbsStacked.querySelector('[data-ruta-relativa="' + CSS.escape(folderSelectedImageRutaStacked) + '"]');
      if (col) {
        col.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
        const card = col.querySelector('.thumb-card');
        if (card) {
          card.classList.add('thumb-card-selected');
          setTimeout(function () { card.classList.remove('thumb-card-selected'); }, 1300);
        }
      }
      folderSelectedImageRutaStacked = '';
    }
  }

  function wireVisorIrACarpeta() {
    const btnVisorAbrirCarpeta = cfg?.refs?.btnVisorAbrirCarpeta;
    const modalVisor = cfg?.refs?.modalVisor;
    if (!btnVisorAbrirCarpeta) return;
    btnVisorAbrirCarpeta.addEventListener('click', function (e) {
      e.preventDefault();
      const folderPath = (visor.ruta || '').trim() || (visor.rutaRelativa || '').replace(/\/[^/]+$/, '').trim();
      if (!folderPath) return;
      const parts = folderPath.split('/').filter(Boolean);
      const folderName = parts.length ? parts[parts.length - 1] : folderPath;
      const rutaRelativa = visor.rutaRelativa;
      const ws = currentWorkspace;
      if (window.jQuery && modalVisor) {
        window.jQuery(modalVisor).one('hidden.bs.modal', function () {
          openFolderStacked(folderName, folderPath, rutaRelativa, ws);
        });
        window.jQuery(modalVisor).modal('hide');
      } else {
        openFolderStacked(folderName, folderPath, rutaRelativa, ws);
      }
    });
  }

  function wireVisorShownAndResize() {
    const modalVisor = cfg?.refs?.modalVisor;
    const swBoxes = cfg?.refs?.swBoxes;
    if (window.jQuery && modalVisor) {
      window.jQuery(modalVisor).on('shown.bs.modal', drawCanvas);
    }
    window.addEventListener('resize', function () {
      if (modalVisor && modalVisor.classList.contains('show')) drawCanvas();
    });
    if (swBoxes) swBoxes.addEventListener('change', drawCanvas);
  }

  /** Abre el modal de carpeta con una lista de resultados (ej. búsqueda por etiqueta global). items: [{ workspace, ruta_carpeta, archivo, ruta_relativa }] */
  function openGalleryResultados(items) {
    const gridThumbs = cfg?.refs?.gridThumbs;
    const ttlCarpeta = cfg?.refs?.ttlCarpeta;
    const tagsCarpeta = cfg?.refs?.tagsCarpeta;
    if (!gridThumbs) return;
    const arr = Array.isArray(items) ? items : [];
    if (ttlCarpeta) ttlCarpeta.textContent = 'Resultados de búsqueda (' + arr.length + ')';
    if (tagsCarpeta) tagsCarpeta.innerHTML = '';
    gridThumbs.innerHTML = '';
    if (!arr.length) {
      const div = document.createElement('div');
      div.className = 'col-12 text-muted';
      div.textContent = 'Sin resultados';
      gridThumbs.appendChild(div);
      showModal(cfg.refs.modalCarpeta);
      return;
    }
    for (let i = 0; i < arr.length; i++) {
      const it = arr[i];
      const ws = String(it?.workspace || '').trim();
      const rutaCarpeta = String(it?.ruta_carpeta || it?.ruta || '').trim();
      const archivo = String(it?.archivo || '').trim();
      const rutaRel = String(it?.ruta_relativa || it?.ruta || '').trim();
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
      img.src = buildUrl('?action=ver_imagen&ruta=' + encodeURIComponent(rutaCarpeta) + '&archivo=' + encodeURIComponent(archivo) + '&thumb=1&w=240', ws);
      imgWrap.appendChild(img);
      a.appendChild(imgWrap);
      const body = document.createElement('div');
      body.className = 'thumb-card-body';
      const title = document.createElement('div');
      title.className = 'thumb-card-title';
      title.textContent = archivo || '—';
      title.title = archivo || '';
      body.appendChild(title);
      a.appendChild(body);
      a.addEventListener('click', async function (e) {
        e.preventDefault();
        await BuscadorModals.openVisor(rutaCarpeta, archivo, rutaRel, ws);
      });
      card.appendChild(a);
      col.appendChild(card);
      gridThumbs.appendChild(col);
    }
    showModal(cfg.refs.modalCarpeta);
  }

  const BuscadorModals = {
    init: function (config) {
      cfg = config;
      if (!cfg?.refs) return;
      wireVisorIrACarpeta();
      wireVisorShownAndResize();
    },
    openFolder: openFolder,
    openVisor: openVisor,
    openFolderStacked: openFolderStacked,
    openGalleryResultados: openGalleryResultados,
    drawCanvas: drawCanvas,
    getVisor: function () { return visor; }
  };

  if (typeof window !== 'undefined') window.BuscadorModals = BuscadorModals;
})();
