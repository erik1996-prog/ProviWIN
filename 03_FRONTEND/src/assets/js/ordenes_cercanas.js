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

  async function api(q) {
    let r;
    try { r = await fetch('api/ordenes_cercanas.php?' + q, { headers: { Accept: 'application/json' } }); }
    catch { throw new Error('No se pudo conectar con el servidor.'); }
    const d = await r.json().catch(() => ({}));
    if (!r.ok || d.status !== 'success') throw new Error(d.message || 'No se pudo completar la consulta.');
    return d;
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

  async function preparar() {
    if (A.preparando) return;
    A.preparando = true; A.fecha = ''; A.yo = null; botones();
    estado('ocFecha', 'buscando', 'Detectando la fecha de hoy desde internet…');
    estado('ocUbicacion', '', '');
    try {
      const r = await api('action=fecha');
      A.fecha = r.fecha;
      // Grupo de órdenes según el usuario (provincia, Lima instalaciones, Lima visitas técnicas): selector solo si tiene más de uno.
      $('ocGrupo').innerHTML = r.grupos.map(g => `<option value="${esc(g.codigo)}">${esc(g.nombre)}</option>`).join('');
      $('ocGrupoFiltro').classList.toggle('d-none', r.grupos.length < 2);
      estado('ocFecha', 'ok', `Fecha de hoy: ${A.fecha}`);
      estado('ocUbicacion', 'buscando', 'Detectando tu ubicación…');
      try {
        const p = await ubicar();
        A.yo = { lat: p.lat, lon: p.lon }; A.precision = p.precision;
        const buena = p.precision <= GPS_ACEPTABLE;
        estado('ocUbicacion', buena ? 'ok' : 'baja', `Ubicación detectada (±${Math.round(p.precision)} m)${buena ? '' : ': poco precisa'}`);
      } catch (x) { estado('ocUbicacion', 'error', x.message); }
    } catch (x) { estado('ocFecha', 'error', x.message); }
    A.preparando = false; botones(); pintar();
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
        <td data-label="Ubicación"><a class="text-nowrap" href="${ruta(p)}" target="_blank" rel="noopener" title="Ver la ruta en Google Maps"><i class="bi bi-sign-turn-right me-1"></i>${p.km.toFixed(2)} km</a></td>
        <td data-label="N° de orden">${esc(p.id || '—')}</td><td data-label="Cuadrilla">${esc(p.cuadrilla || '—')}</td>
        <td data-label="Fecha" class="text-nowrap">${esc(p.fecha || '—')}</td><td data-label="Dirección">${esc(p.direccion || '—')}</td></tr>`).join('')
    }</tbody></table></div>`;
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
    if (vigente()) { A.escaneando = false; botones(); pintar(); }
  }

  $('ocEscanear').addEventListener('click', escanear);
  $('ocReintentar').addEventListener('click', preparar);
  $('ocRadio').addEventListener('change', pintar);
  // Otro grupo u otro estado: lo escaneado ya no vale; se detiene lo que esté en curso y se vuelve a escanear a pedido.
  const reiniciar = () => {
    A.turno++; A.escaneando = false; A.leido = false; A.puntos = []; A.vistos = new Set();
    $('ocAvance').textContent = ''; botones(); pintar();
  };
  $('ocGrupo').addEventListener('change', reiniciar);
  $('ocEstado').addEventListener('change', reiniciar);
  modal.addEventListener('show.bs.modal', () => { if (!(A.fecha && A.yo)) preparar(); });
  modal.addEventListener('hidden.bs.modal', () => { A.turno++; A.escaneando = false; botones(); });
})();
