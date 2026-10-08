// 05_TEST/frontend/visual_snapshot.js - Regresión visual: compara el estilo final (getComputedStyle) de cada elemento
// antes y después de un cambio de CSS. Se usó en v0.7.2 para unir las hojas de estilo con 0 diferencias en 24 vistas.
// Uso (en la consola del navegador, con sesión iniciada, en cada pantalla y tamaño):
//   1) ANTES del cambio:  await sgsSnapshot('save', 'usuarios|admin')
//   2) DESPUÉS:           await sgsSnapshot('diff', 'usuarios|admin')   -> { distintos: 0 } = idéntico
// La clave agrega |escritorio o |movil según el ancho. Los datos quedan en IndexedDB 'sgs-snap' del navegador.
// Pegar primero esta función completa en la consola.
async function sgsSnapshot(MODE, KEYBASE) {
  const KEY = KEYBASE + '|' + (innerWidth < 600 ? 'movil' : 'escritorio');
  await new Promise(r => setTimeout(r, 2500));
  const props = ['display','position','top','right','bottom','left','float','flex-direction','flex-wrap','flex-grow','flex-basis','justify-content','align-items','gap','grid-template-columns','margin-top','margin-right','margin-bottom','margin-left','padding-top','padding-right','padding-bottom','padding-left','border-top-width','border-right-width','border-bottom-width','border-left-width','border-top-style','border-top-color','border-right-color','border-bottom-color','border-left-color','border-top-left-radius','border-bottom-right-radius','color','background-color','background-image','background-position','background-size','box-shadow','font-family','font-size','font-weight','font-style','line-height','letter-spacing','text-transform','text-align','text-decoration-line','white-space','text-overflow','opacity','visibility','z-index','overflow-x','overflow-y','min-height','max-height','min-width','max-width','transform','outline-style','cursor','list-style-type','object-fit','filter','backdrop-filter','appearance','resize','vertical-align','table-layout','border-collapse'];
  const path = e => { const p = []; while (e && e !== document.body) { let i = 0, s = e; while ((s = s.previousElementSibling)) i++; p.unshift(e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + ':' + i); e = e.parentElement; } return p.join('>'); };
  const snap = {};
  for (const el of document.body.querySelectorAll('*')) {
    if (['SCRIPT', 'STYLE', 'svg', 'path', 'NOSCRIPT'].includes(el.tagName)) continue;
    if (el.closest('.leaflet-container')) continue;           // mapa: teselas cambian solas
    const k = path(el);
    for (const ps of [null, '::before', '::after', '::placeholder', '::file-selector-button']) {
      const cs = getComputedStyle(el, ps);
      if ((ps === '::before' || ps === '::after') && (cs.content === 'none' || cs.content === 'normal')) continue;
      if (ps === '::placeholder' && !('placeholder' in el && el.placeholder)) continue;
      if (ps === '::file-selector-button' && el.type !== 'file') continue;
      snap[k + (ps || '')] = props.map(p => cs.getPropertyValue(p)).join('¦') + (ps === '::before' || ps === '::after' ? '¦' + cs.content : '');
    }
  }
  const db = await new Promise((res, rej) => { const r = indexedDB.open('sgs-snap', 1); r.onupgradeneeded = () => r.result.createObjectStore('s'); r.onsuccess = () => res(r.result); r.onerror = rej; });
  const tx = (mode, fn) => new Promise((res, rej) => { const t = db.transaction('s', mode); const q = fn(t.objectStore('s')); q.onsuccess = () => res(q.result); q.onerror = rej; });
  if (MODE === 'save') {
    await tx('readwrite', s => s.put(snap, KEY));
    return ({ guardado: KEY, elementos: Object.keys(snap).length, url: location.search });
  } else {
    const old = await tx('readonly', s => s.get(KEY));
    if (!old) return ({ error: 'sin línea base para ' + KEY });
    else {
      const diffs = []; let changed = 0;
      for (const k of new Set([...Object.keys(old), ...Object.keys(snap)])) {
        if (old[k] === snap[k]) continue;
        changed++;
        if (diffs.length >= 12) continue;
        if (!old[k] || !snap[k]) { diffs.push((old[k] ? 'FALTA ' : 'NUEVO ') + k.slice(-90)); continue; }
        const a = old[k].split('¦'), b = snap[k].split('¦');
        const det = props.concat(['content']).map((p, i) => a[i] !== b[i] ? `${p}: ${a[i]} → ${b[i]}` : null).filter(Boolean).slice(0, 4);
        diffs.push(k.slice(-70) + ' ⇒ ' + det.join(' ; '));
      }
      return ({ clave: KEY, elementos: Object.keys(snap).length, distintos: changed, ejemplos: diffs });
    }
  }
}
