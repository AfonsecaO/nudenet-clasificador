/**
 * Web Worker: descarga por workspaces.
 * Sin polling: la UI se actualiza con cada mensaje 'tick'.
 */
const downloadSet = new Set();
let stopRequested = false;
let baseUrl = '';
const downloadRunning = new Set();

function buildUrl(action, ws) {
  const params = `action=${encodeURIComponent(action)}&workspace=${encodeURIComponent(ws)}`;
  if (!baseUrl) return '?' + params;
  const sep = baseUrl.indexOf('?') >= 0 ? '&' : '?';
  return baseUrl + sep + params;
}

async function fetchJson(url) {
  const r = await fetch(url, { headers: { accept: 'application/json' } });
  const data = await r.json().catch(() => ({}));
  return { ok: r.ok, data };
}

async function runDownloadLoop(ws) {
  // Única condición: la siguiente petición solo se inicia cuando la anterior haya finalizado (éxito, error o complete).
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
        self.postMessage({ type: 'state', download: [...downloadSet], classify: [] });
      }
      break;
    case 'remove':
      if (msg.mode === 'download' && msg.ws) downloadSet.delete(msg.ws);
      self.postMessage({ type: 'state', download: [...downloadSet], classify: [] });
      break;
    case 'stopAll':
      stopRequested = true;
      downloadSet.clear();
      self.postMessage({ type: 'state', download: [], classify: [] });
      break;
    case 'getState':
      self.postMessage({ type: 'state', download: [...downloadSet], classify: [] });
      break;
    default:
      break;
  }
};
