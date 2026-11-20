// 03_FRONTEND/src/assets/js/ordenes_cercanas.js - Ventana "Órdenes cercanas" de Supervisiones (2026-11-08).
// Sin sondeos: solo consulta al abrir la ventana y al escanear. Pide las páginas de a 3 y se detiene al cerrar.
(() => {
  const modal = document.getElementById('modalOrdenesCercanas');
  if (!modal) return;
  const $ = id => document.getElementById(id);
  const esc = t => String(t).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmt = n => Number(n).toLocaleString('es-PE');
  const HILOS = 3, GPS_SEGUNDOS = 12, GPS_BUENA = 30, GPS_ACEPTABLE = 150;
  const A = { fecha: '', yo: null, precision: Infinity, puntos: [], vistos: new Set(), escaneando: false, preparando: false, turno: 0 };

  const estado = (id, tipo, texto) => { $(id).dataset.estado = tipo; $(id).textContent = texto; };
  const botones = () => {
    const listo = !!(A.fecha && A.yo);
    $('ocEscanear').classList.toggle('d-none', !listo);
    $('ocEscanear').disabled = A.escaneando;
    $('ocReintentar').classList.toggle('d-none', listo || A.preparando);
  };

  // Con el vínculo con Phoenix (2026-11-13) el servidor no espera: responde "consultando" y aquí se repite la misma petición
  // cada 2 s hasta tener las órdenes (máximo 90 s), sin dejar procesos del hosting ocupados.
  const REPETIR_MS = 2000, MAX_MS = 90000;
  async function api(q) {
    const inicio = Date.now();
    for (;;) {
      let r;
      try { r = await fetch('api/ordenes_cercanas.php?' + q, { headers: { Accept: 'application/json' } }); }
      catch { throw new Error('No se pudo conectar con el servidor.'); }
      const d = await r.json().catch(() => ({}));
      if (!r.ok || d.status !== 'success') throw new Error(d.message || 'No se pudo completar la consulta.');
      if (!d.consultando) return d;
      if (Date.now() - inicio > MAX_MS) throw new Error('Phoenix está tardando en responder. Inténtalo en unos minutos.');
      await new Promise(ok => setTimeout(ok, REPETIR_MS));
    }
  }

  // Escucha el GPS unos segundos y se queda con la lectura más precisa.
  const ubicar = () => new Promise((ok, mal) => {
    if (!navigator.geolocation) return mal(new Error('Este navegador no permite detectar la ubicación.'));
    let mejor = null, id = null, fin = null;
    const cerrar = () => {
      navigator.geolocation.clearWatch(id); clearTimeout(fin);
      mejor ? ok(mejor) : mal(new Error('No se pudo detectar tu ubicación. Permítela y pulsa Reintentar.'));
    };
    id = navigator.geolocation.watchPosition(p => {
      if (!mejor || p.coords.accuracy < mejor.precision) {
        mejor = { lat: p.coords.latitude, lon: p.coords.longitude, precision: p.coords.accuracy };
        estado('ocUbicacion', 'buscando', `Afinando tu ubicación… ±${Math.round(mejor.precision)} m`);
      }
      if (mejor.precision <= GPS_BUENA) cerrar();
    }, e => { if (e.code === e.PERMISSION_DENIED) cerrar(); }, { enableHighAccuracy: true, timeout: GPS_SEGUNDOS * 1000, maximumAge: 0 });
    fin = setTimeout(cerrar, GPS_SEGUNDOS * 1000);
  });

  // Ubicación al día (2026-11-20, pedido del usuario): main.js guarda la última ubicación buena del usuario mientras tiene abierta
  // CUALQUIER pantalla del SGS. Si es reciente (menos de 2 min) y precisa, se usa al instante y el GPS sigue afinando por detrás
  // (si se movió más de 50 m o mejora la precisión, la lista se reordena sola). La fecha y el GPS se buscan a la vez.
  const guardada = () => {
    const m = document.querySelector('meta[name="sgs-ubicacion"]');
    if (!m) return null;
    try {
      const v = JSON.parse(sessionStorage.getItem(`sgs-ubicacion-${m.content}`));
      return v && Date.now() - v.t < 120000 && v.acc <= GPS_ACEPTABLE ? v : null;
    } catch { return null; }
  };
  async function preparar() {
    if (A.preparando) return;
    A.preparando = true; A.fecha = ''; A.yo = null; botones();
    estado('ocFecha', 'buscando', 'Detectando la fecha de hoy desde internet…');
    const g = guardada();
    if (g) {
      A.yo = { lat: g.lat, lon: g.lon }; A.precision = g.acc;
      estado('ocUbicacion', 'ok', `Tu ubicación de hace ${Math.max(1, Math.round((Date.now() - g.t) / 1000))} s (±${Math.round(g.acc)} m)`);
    } else estado('ocUbicacion', 'buscando', 'Detectando tu ubicación…');
    const gps = ubicar().then(p => {
      if (A.yo && distancia(A.yo, p) < 0.05 && p.precision >= A.precision) {   // no cambió: se deja la que ya estaba
        estado('ocUbicacion', 'ok', `Ubicación detectada (±${Math.round(A.precision)} m)`);
        return false;
      }
      A.yo = { lat: p.lat, lon: p.lon }; A.precision = p.precision;
      const buena = p.precision <= GPS_ACEPTABLE;
      estado('ocUbicacion', buena ? 'ok' : 'baja', `Ubicación detectada (±${Math.round(p.precision)} m)${buena ? '' : ': poco precisa'}`);
      return true;
    }, x => { if (!A.yo) estado('ocUbicacion', 'error', x.message); return false; });
    try {
      const r = await api('action=fecha');
      A.fecha = r.fecha;
      // Grupo de órdenes según el usuario (provincia, Lima instalaciones, Lima visitas técnicas): selector solo si tiene más de uno.
      $('ocGrupo').innerHTML = r.grupos.map(gr => `<option value="${esc(gr.codigo)}">${esc(gr.nombre)}</option>`).join('');
      $('ocGrupoFiltro').classList.toggle('d-none', r.grupos.length < 2);
      estado('ocFecha', 'ok', `Fecha de hoy: ${A.fecha}`);
    } catch (x) { estado('ocFecha', 'error', x.message); }
    if (!A.yo) await gps;                 // sin ubicación guardada se espera al GPS; con ella, el GPS sigue afinando por detrás
    A.preparando = false; botones(); pintar();
    gps.then(cambio => { if (cambio) { pintar(); buscarCasas(); } });
  }

  const distancia = (a, b) => {
    const R = 6371, rad = x => x * Math.PI / 180, dLat = rad(b.lat - a.lat), dLon = rad(b.lon - a.lon);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLon / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(h));
  };
  // Con ubicación imprecisa no se manda el origen: Google Maps usa la del equipo.
  const ruta = p => `https://www.google.com/maps/dir/?api=1${A.precision <= GPS_ACEPTABLE ? `&origin=${A.yo.lat},${A.yo.lon}` : ''}&destination=${p.lat},${p.lon}&travelmode=driving`;

  const vacio = texto => `<p class="text-muted text-center py-4 mb-0"><i class="bi bi-inbox d-block mb-1"></i>${texto}</p>`;
  const estadoTexto = () => $('ocEstado').selectedOptions[0].textContent.toLowerCase();   // "iniciadas", "canceladas"…
  function pintar() {
    if (!A.yo) { $('ocLista').innerHTML = ''; return; }
    const radio = +$('ocRadio').value;
    let l = A.puntos.map(p => ({ ...p, km: distancia(A.yo, p) }));
    if (radio) l = l.filter(p => p.km <= radio);
    l.sort((a, b) => a.km - b.km);
    if (!l.length) {
      $('ocLista').innerHTML = vacio(A.escaneando ? 'Buscando órdenes…' : A.puntos.length ? 'Ninguna orden en ese radio. Prueba uno mayor.' : A.leido ? `No hay órdenes ${estadoTexto()} hoy.` : 'Pulsa «Escanear órdenes cercanas».');
      return;
    }
    $('ocLista').innerHTML = `<div class="table-responsive"><table class="table table-hover mb-0 align-middle sgs-tabla-tarjetas"><thead><tr>
      <th>Tipo de trabajo</th><th>Ubicación</th><th>N° de orden</th><th>Cuadrilla</th><th>Fecha</th><th>Dirección</th></tr></thead><tbody>${
      l.map(p => `<tr><td data-label="Tipo de trabajo">${esc(p.tipo || '—')}</td>
        <td data-label="Ubicación"><a class="text-nowrap" href="${ruta(p)}" target="_blank" rel="noopener" title="Ver la ruta en Google Maps"><i class="bi bi-sign-turn-right me-1"></i>${p.km.toFixed(2)} km</a>${ORIGEN[p.origen] || ''}</td>
        <td data-label="N° de orden">${esc(p.id || '—')}</td><td data-label="Cuadrilla">${esc(p.cuadrilla || '—')}</td>
        <td data-label="Fecha" class="text-nowrap">${esc(p.fecha || '—')}</td><td data-label="Dirección">${esc(p.direccion || '—')}</td></tr>`).join('')
    }</tbody></table></div>`;
  }

  // Ubicación de la casa (2026-11-20, pedido del usuario): para las órdenes que se muestran (las más cercanas) se piden en Phoenix
  // las coordenadas de la foto de la fachada, de a 6 y hasta 18. Con foto, la distancia y la ruta usan esa ubicación; sin foto,
  // la de la orden. Un ícono chico en Ubicación dice cuál es (casa verde / punto rojo; la leyenda va arriba, en «Ten en cuenta»).
  const ORIGEN = {
    casa: '<i class="bi bi-house-fill text-success ms-1" title="Ubicación de la foto de la fachada" aria-label="Ubicación de la foto de la fachada"></i>',
    orden: '<i class="bi bi-geo-alt-fill text-danger ms-1" title="Ubicación de la orden: no hay foto de la fachada" aria-label="Ubicación de la orden"></i>',
  };
  const CASAS_MAX = 18, CASAS_LOTE = 6;
  let buscandoCasas = false;
  async function buscarCasas() {
    if (buscandoCasas || !A.yo || !A.leido) return;
    const turno = A.turno, radio = +$('ocRadio').value;
    let l = A.puntos.map(p => ({ p, km: distancia(A.yo, p) }));
    if (radio) l = l.filter(x => x.km <= radio);
    const faltan = l.sort((a, b) => a.km - b.km).slice(0, CASAS_MAX).map(x => x.p).filter(p => p.id && !p.origen);
    if (!faltan.length) return;
    buscandoCasas = true;
    try {
      for (let i = 0; i < faltan.length && turno === A.turno; i += CASAS_LOTE) {
        const lote = faltan.slice(i, i + CASAS_LOTE);
        let r;
        try { r = await api(`action=casas&ids=${lote.map(p => encodeURIComponent(p.id)).join(',')}`); } catch { break; }
        if (turno !== A.turno) return;
        lote.forEach(p => { const c = r.casas && r.casas[p.id]; if (c) { p.lat = c.lat; p.lon = c.lon; p.origen = 'casa'; } else p.origen = 'orden'; });
        pintar();
      }
    } finally { buscandoCasas = false; }
  }
  const agregar = puntos => puntos.forEach(p => {
    const k = p.id || `${p.lat}|${p.lon}|${p.direccion}`;
    if (!A.vistos.has(k)) { A.vistos.add(k); A.puntos.push(p); }
  });

  async function escanear() {
    const turno = ++A.turno, vigente = () => turno === A.turno;
    A.escaneando = true; A.leido = false; A.puntos = []; A.vistos = new Set(); botones(); pintar();
    $('ocAvance').textContent = 'Conectando con Winbo…';
    try {
      const filtro = `grupo=${encodeURIComponent($('ocGrupo').value)}&estado=${encodeURIComponent($('ocEstado').value)}`;
      const r = await api(`action=inicio&${filtro}`);
      if (!vigente()) return;
      agregar(r.puntos); pintar();
      let sig = 2, leidas = 1, fallos = 0;
      const avance = () => { $('ocAvance').textContent = `Escaneando… página ${fmt(leidas)} de ${fmt(r.paginas)}`; };
      avance();
      const trabajador = async () => {
        while (sig <= r.paginas && vigente()) {
          const n = sig++;
          try { const d = await api(`action=pagina&n=${n}&fecha=${encodeURIComponent(r.fecha)}&${filtro}`); if (vigente()) { agregar(d.puntos); pintar(); } }
          catch { fallos++; }
          leidas++; if (vigente()) avance();
        }
      };
      await Promise.all(Array.from({ length: HILOS }, trabajador));
      if (!vigente()) return;
      A.leido = true;
      $('ocAvance').textContent = `${fmt(A.puntos.length)} órdenes ${estadoTexto()} el ${r.fecha}${fallos ? ` · ${fmt(fallos)} páginas no se pudieron leer` : ''}`;
    } catch (x) {
      if (vigente()) $('ocAvance').textContent = x.message;
    }
    if (vigente()) { A.escaneando = false; botones(); pintar(); buscarCasas(); }
  }

  $('ocEscanear').addEventListener('click', escanear);
  $('ocReintentar').addEventListener('click', preparar);
  $('ocRadio').addEventListener('change', () => { pintar(); buscarCasas(); });
  // Otro grupo u otro estado: lo escaneado ya no vale; se detiene lo que esté en curso y se vuelve a escanear a pedido.
  const reiniciar = () => {
    A.turno++; A.escaneando = false; A.leido = false; A.puntos = []; A.vistos = new Set();
    $('ocAvance').textContent = ''; botones(); pintar();
  };
  $('ocGrupo').addEventListener('change', reiniciar);
  $('ocEstado').addEventListener('change', reiniciar);
  modal.addEventListener('show.bs.modal', () => { if (!(A.fecha && A.yo)) preparar(); });
  modal.addEventListener('hidden.bs.modal', () => { A.turno++; A.escaneando = false; botones(); });

  // Sesión de Phoenix (2026-11-19): se conecta desde el indicador "Phoenix" de la barra superior (phoenix_estado.js). Si Phoenix
  // la cerró antes de la medianoche, el aviso sale en la lista y el indicador se actualiza para ofrecer conectarla de nuevo.
  new MutationObserver(() => { if (/sesión de Phoenix se cerró/i.test($('ocAvance').textContent)) window.sgsPhoenixRevisar?.(); })
    .observe($('ocAvance'), { childList: true, characterData: true, subtree: true });
})();
