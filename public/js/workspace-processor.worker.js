/**
 * Web Worker: descarga y clasificación por workspaces.
 * - Cada contenedor es independiente: descarga y clasificación inician en seguida al activarlos.
 * - Dentro de un mismo contenedor, descarga y clasificación son independientes: pueden correr las dos a la vez (una petición a la vez por tipo).
 * - Varios contenedores pueden estar en descarga y/o clasificación en paralelo.
 * Sin polling: la UI se actualiza con cada mensaje 'tick'.
 */
const downloadSet = new Set();
const classifySet = new Set();
let stopRequested = false;
let baseUrl = '';
/** Workspaces que tienen un loop de descarga activo */
const downloadRunning = new Set();
/** Workspaces que tienen un loop de clasificación activo */
const classifyRunning = new Set();

function buildUrl(action, ws) {
  const params = `action=${encodeURIComponent(action)}&workspace=${encodeURIComponent(ws)}`;
  if (!baseUrl) return '?' + params;
  const sep = baseUrl.indexOf('?') >= 0 ? '&' : '?';
  return baseUrl + sep + params;
}

function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

async function fetchJson(url) {
  const r = await fetch(url, { headers: { accept: 'application/json' } });
  const data = await r.json().catch(() => ({}));
  return { ok: r.ok, data };
}

async function runDownloadLoop(ws) {
  while (!stopRequested && downloadSet.has(ws)) {
    const url = buildUrl('procesar', ws);
    const t0 = Date.now();
    const { ok, data } = await fetchJson(url);
    const durationMs = Date.now() - t0;
    self.postMessage({ type: 'tick', mode: 'download', ws, ok, data, durationMs });
    if (!ok || !data?.success) break;
    // Solo terminar cuando no se procesó registro Y ya no faltan registros (id <= max_id); si hay huecos, registro_procesado puede ser false pero faltan_registros true
    const noMoreWork = data?.registro_procesado === false && !data?.faltan_registros;
    if (noMoreWork) {
      downloadSet.delete(ws);
      self.postMessage({ type: 'done', mode: 'download', ws });
      break;
    }
    await sleep(200);
  }
}

async function runClassifyLoop(ws) {
  while (!stopRequested && classifySet.has(ws)) {
    const url = buildUrl('procesar_imagenes', ws);
    const t0 = Date.now();
    const { ok, data } = await fetchJson(url);
    const durationMs = Date.now() - t0;
    self.postMessage({ type: 'tick', mode: 'classify', ws, ok, data, durationMs });
    if (!ok || !data?.success) break;
    if (data?.stopped_due_to_classifier_error) {
      classifySet.delete(ws);
      self.postMessage({ type: 'done', mode: 'classify', ws, stoppedDueToError: true });
      break;
    }
    const noMoreWork = data?.procesada === false && (data?.pendientes ?? data?.pending ?? 0) === 0;
    if (noMoreWork) {
      classifySet.delete(ws);
      self.postMessage({ type: 'done', mode: 'classify', ws });
      break;
    }
    await sleep(200);
  }
}

/** Inicia descarga para este workspace si está en cola y no tiene descarga activa. */
function tryStartDownload(ws) {
  if (downloadRunning.has(ws) || !downloadSet.has(ws)) return;
  downloadRunning.add(ws);
  runDownloadLoop(ws).finally(() => {
    downloadRunning.delete(ws);
    if (downloadSet.has(ws)) tryStartDownload(ws);
  });
}

/** Inicia clasificación para este workspace si está en cola y no tiene clasificación activa. */
function tryStartClassify(ws) {
  if (classifyRunning.has(ws) || !classifySet.has(ws)) return;
  classifyRunning.add(ws);
  runClassifyLoop(ws).finally(() => {
    classifyRunning.delete(ws);
    if (classifySet.has(ws)) tryStartClassify(ws);
  });
}

self.onmessage = function (e) {
  const msg = e.data;
  if (!msg || typeof msg.type !== 'string') return;
  switch (msg.type) {
    case 'init':
      if (typeof msg.baseUrl === 'string') baseUrl = msg.baseUrl;
      break;
    case 'add':
      stopRequested = false;
      if (msg.mode === 'download' && msg.ws) {
        downloadSet.add(msg.ws);
        tryStartDownload(msg.ws);
        self.postMessage({ type: 'state', download: [...downloadSet], classify: [...classifySet] });
      } else if (msg.mode === 'classify' && msg.ws) {
        classifySet.add(msg.ws);
        tryStartClassify(msg.ws);
        self.postMessage({ type: 'state', download: [...downloadSet], classify: [...classifySet] });
      }
      break;
    case 'remove':
      if (msg.mode === 'download' && msg.ws) downloadSet.delete(msg.ws);
      else if (msg.mode === 'classify' && msg.ws) classifySet.delete(msg.ws);
      self.postMessage({ type: 'state', download: [...downloadSet], classify: [...classifySet] });
      break;
    case 'stopAll':
      stopRequested = true;
      downloadSet.clear();
      classifySet.clear();
      self.postMessage({ type: 'state', download: [], classify: [] });
      break;
    case 'getState':
      self.postMessage({ type: 'state', download: [...downloadSet], classify: [...classifySet] });
      break;
    default:
      break;
  }
};
