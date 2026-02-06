/**
 * Web Worker: procesamiento paralelo de workspaces (descarga de tablas y clasificación).
 * Las URLs se resuelven contra la página (baseUrl), no contra el script del worker.
 * Por cada workspace (contenedor) solo hay una petición activa; la siguiente se envía
 * solo cuando termina la anterior. Cada contenedor es independiente.
 */
const downloadSet = new Set();
const classifySet = new Set();
let stopRequested = false;
let baseUrl = '';
/** Workspaces que tienen actualmente un loop en ejecución (solo uno por ws) */
const runningWorkspace = new Set();

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
    const { ok, data } = await fetchJson(url);
    self.postMessage({ type: 'tick', mode: 'download', ws, ok, data });
    if (!ok || !data?.success) break;
    const msg = String(data?.mensaje || '');
    const noMoreWork = data?.registro_procesado === false;
    const completedMsg = msg.includes('completada') || msg.includes('No se encontraron tablas');
    if (noMoreWork || completedMsg) {
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
    const { ok, data } = await fetchJson(url);
    self.postMessage({ type: 'tick', mode: 'classify', ws, ok, data });
    if (!ok || !data?.success) break;
    // Solo marcar "done" cuando el backend indica explícitamente que no hay más trabajo
    const noMoreWork = data?.procesada === false && (data?.pendientes ?? data?.pending ?? 0) === 0;
    if (noMoreWork) {
      classifySet.delete(ws);
      self.postMessage({ type: 'done', mode: 'classify', ws });
      break;
    }
    await sleep(200);
  }
}

/**
 * Inicia una sola tarea para este workspace (descarga o clasificación) si no hay ninguna en curso.
 * Cuando termina esa tarea, se llama de nuevo para procesar la siguiente en cola del mismo contenedor.
 */
function tryStartOne(ws) {
  if (runningWorkspace.has(ws)) return;
  if (downloadSet.has(ws)) {
    runningWorkspace.add(ws);
    runDownloadLoop(ws).finally(() => {
      runningWorkspace.delete(ws);
      tryStartOne(ws);
    });
    return;
  }
  if (classifySet.has(ws)) {
    runningWorkspace.add(ws);
    runClassifyLoop(ws).finally(() => {
      runningWorkspace.delete(ws);
      tryStartOne(ws);
    });
    return;
  }
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
        tryStartOne(msg.ws);
        self.postMessage({ type: 'state', download: [...downloadSet], classify: [...classifySet] });
      } else if (msg.mode === 'classify' && msg.ws) {
        classifySet.add(msg.ws);
        tryStartOne(msg.ws);
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
