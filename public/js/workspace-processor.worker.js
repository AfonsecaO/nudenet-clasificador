/**
 * Web Worker: descarga por workspaces y moderación (clasificar Rekognition).
 * Sin polling: la UI se actualiza con cada mensaje 'tick'.
 */
const downloadSet = new Set();
const classifySet = new Set();
let stopRequested = false;
let baseUrl = '';
const downloadRunning = new Set();
const classifyRunning = new Set();

function buildUrl(action, ws) {
  const params = ws != null ? `action=${encodeURIComponent(action)}&workspace=${encodeURIComponent(ws)}` : `action=${encodeURIComponent(action)}`;
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
  while (!stopRequested && downloadSet.has(ws)) {
    const url = buildUrl('procesar', ws);
    const t0 = Date.now();
    const { ok, data } = await fetchJson(url);
    const durationMs = Date.now() - t0;
    self.postMessage({ type: 'tick', mode: 'download', ws, ok, data, durationMs });
    if (!ok || !data?.success) break;
    const noMoreWork = data?.registro_procesado === false && !data?.faltan_registros;
    if (noMoreWork) {
      downloadSet.delete(ws);
      self.postMessage({ type: 'done', mode: 'download', ws });
      break;
    }
  }
}

async function runClassifyLoop(ws) {
  while (!stopRequested && classifySet.has(ws)) {
    const url = buildUrl('procesar_moderacion', ws);
    const t0 = Date.now();
    const { ok, data } = await fetchJson(url);
    const durationMs = Date.now() - t0;
    self.postMessage({ type: 'tick', mode: 'classify', ws, ok, data, durationMs });
    if (!ok || !data?.success) break;
    const noMoreWork = !data?.faltan_mas;
    if (noMoreWork) {
      classifySet.delete(ws);
      self.postMessage({ type: 'done', mode: 'classify', ws });
      break;
    }
  }
}

function tryStartDownload(ws) {
  if (downloadRunning.has(ws) || !downloadSet.has(ws)) return;
  downloadRunning.add(ws);
  runDownloadLoop(ws).finally(() => {
    downloadRunning.delete(ws);
    if (downloadSet.has(ws)) tryStartDownload(ws);
  });
}

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
      if (msg.mode === 'classify' && msg.ws) classifySet.delete(msg.ws);
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
