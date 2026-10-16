<?php
// 03_FRONTEND/src/layouts/gps_gate.php - GPS obligatorio para el personal de campo (supervisores directos y de partner).
// Mientras la ubicación no esté disponible, una pantalla bloquea el uso del sistema y explica cómo activarla.
// Se vuelve a comprobar cada 3 minutos (no en segundo plano) y cuando cambia el permiso; tolera fallas pasajeras. La última posición queda en sessionStorage
// ('sgs-gps') y se emite el evento 'sgs:gps' para que los formularios puedan reutilizarla.
?>
<div class="sgs-gps-gate" id="sgsGpsGate" role="dialog" aria-modal="true" aria-labelledby="sgsGpsTitulo" hidden>
  <div class="sgs-gps-card">
    <span class="sgs-gps-icon" aria-hidden="true"><i class="bi bi-geo-alt"></i></span>
    <h2 id="sgsGpsTitulo">Activa tu ubicación</h2>
    <p id="sgsGpsTexto">Para usar el sistema en campo necesitamos tu ubicación (GPS). Se usa para registrar dónde se realizan el turno y las supervisiones.</p>
    <ol class="sgs-gps-pasos" id="sgsGpsPasos" hidden></ol>
    <button type="button" class="btn btn-win w-100" id="sgsGpsBtn"><i class="bi bi-crosshair me-1"></i>Activar ubicación</button>
    <div class="sgs-gps-estado" id="sgsGpsEstado" role="status" aria-live="polite"></div>
  </div>
</div>
<script>
(() => {
  const gate = document.getElementById('sgsGpsGate');
  const titulo = document.getElementById('sgsGpsTitulo'), texto = document.getElementById('sgsGpsTexto');
  const pasos = document.getElementById('sgsGpsPasos'), btn = document.getElementById('sgsGpsBtn'), estado = document.getElementById('sgsGpsEstado');
  // 2026-10-16 (celulares Huawei): una sola lectura fallida bloqueaba todo con "Enciende el GPS" aunque estuviera
  // encendido. Los Huawei sin servicios de Google ubican solo con el chip GPS: bajo techo o con el ahorro de batería
  // la lectura de máxima precisión tarda o falla. Ahora: si falla, se intenta con precisión normal; una falla pasajera
  // no bloquea si hay una ubicación de los últimos 15 minutos; solo el permiso denegado bloquea de inmediato; y con la
  // pestaña en segundo plano no se consulta.
  const RECHEQUEO_MS = 3 * 60 * 1000, VIGENCIA_MS = 15 * 60 * 1000, REINTENTO_MS = 60 * 1000;
  let comprobando = false, fallos = 0, ultimaComprobacion = 0;

  const leerGuardado = () => { try { return JSON.parse(sessionStorage.getItem('sgs-gps') || 'null'); } catch (_) { return null; } };
  const guardar = (pos) => {
    const dato = { lat: pos.coords.latitude, lng: pos.coords.longitude, precision: Math.round(pos.coords.accuracy), t: Date.now() };
    try { sessionStorage.setItem('sgs-gps', JSON.stringify(dato)); } catch (_) {}
    window.dispatchEvent(new CustomEvent('sgs:gps', { detail: dato }));
  };
  const reciente = () => { const p = leerGuardado(); return Boolean(p && Date.now() - p.t < VIGENCIA_MS); };
  const abrir = () => { gate.hidden = false; document.documentElement.classList.add('sgs-gps-bloqueado'); };
  const cerrar = () => { gate.hidden = true; document.documentElement.classList.remove('sgs-gps-bloqueado'); };
  const mostrarError = (tituloTxt, textoTxt, listaPasos) => {
    titulo.textContent = tituloTxt; texto.textContent = textoTxt;
    pasos.innerHTML = (listaPasos || []).map((p) => `<li>${p}</li>`).join('');
    pasos.hidden = !listaPasos || !listaPasos.length;
    btn.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i>Reintentar';
    btn.disabled = false; estado.textContent = '';
    abrir();
  };
  const pedir = (opciones) => new Promise((ok, mal) => navigator.geolocation.getCurrentPosition(ok, mal, opciones));
  const PASOS_HUAWEI = 'En <strong>Huawei u Honor</strong>: Ajustes → Batería → Inicio de aplicaciones → el navegador en <strong>Gestionar manualmente</strong> (deja activo "Ejecutar en segundo plano").';

  async function comprobar(mostrarProgreso) {
    if (comprobando) return;
    if (!mostrarProgreso && document.hidden) return; // en segundo plano el celular frena el GPS: se revisa al volver
    if (!window.isSecureContext || !('geolocation' in navigator)) {
      return mostrarError('Ubicación no disponible', 'Este navegador no permite usar el GPS en esta dirección. El sistema debe abrirse con conexión segura (https). Avisa al administrador.', []);
    }
    comprobando = true; ultimaComprobacion = Date.now();
    if (mostrarProgreso) { btn.disabled = true; estado.textContent = 'Buscando tu ubicación…'; }
    try {
      let pos;
      try { pos = await pedir({ enableHighAccuracy: true, timeout: 20000, maximumAge: 120000 }); }
      catch (e) {
        if (e.code === e.PERMISSION_DENIED) throw e;
        pos = await pedir({ enableHighAccuracy: false, timeout: 20000, maximumAge: 600000 }); // precisión normal
      }
      fallos = 0; guardar(pos); cerrar();
    } catch (err) {
      if (err.code === err.PERMISSION_DENIED) {
        mostrarError('Permite el acceso a tu ubicación', 'El navegador tiene bloqueado el permiso de ubicación para este sistema.', [
          'Toca el candado <i class="bi bi-lock"></i> junto a la dirección de la página.',
          'En <strong>Ubicación</strong>, elige <strong>Permitir</strong>.',
          'Vuelve aquí y presiona <strong>Reintentar</strong>.'
        ]);
        return;
      }
      fallos += 1;
      // Falla pasajera en segundo plano: no se interrumpe al supervisor si su ubicación es reciente; se reintenta pronto.
      if (!mostrarProgreso && (reciente() || fallos < 2)) { setTimeout(() => comprobar(false), REINTENTO_MS); return; }
      if (err.code === err.POSITION_UNAVAILABLE) {
        mostrarError('Enciende el GPS', 'No se pudo obtener tu ubicación: el GPS del equipo parece estar apagado o sin señal.', [
          'Desliza el panel de accesos rápidos del celular y activa <strong>Ubicación</strong> (GPS).',
          PASOS_HUAWEI,
          'Presiona <strong>Reintentar</strong>.'
        ]);
      } else {
        mostrarError('Sin señal de ubicación', 'El GPS tardó demasiado en responder. Acércate a una ventana o a un lugar abierto y vuelve a intentar.', [PASOS_HUAWEI]);
      }
    } finally {
      comprobando = false;
    }
  }

  btn.addEventListener('click', () => comprobar(true));
  // Con una ubicación reciente de esta sesión no se bloquea al cambiar de pantalla; se verifica en segundo plano.
  if (reciente()) comprobar(false);
  else { abrir(); estado.textContent = 'Verificando ubicación…'; btn.disabled = true; comprobar(true); }
  setInterval(() => comprobar(false), RECHEQUEO_MS);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && Date.now() - ultimaComprobacion > RECHEQUEO_MS) comprobar(false);
  });
  if (navigator.permissions && navigator.permissions.query) {
    navigator.permissions.query({ name: 'geolocation' }).then((p) => { p.onchange = () => comprobar(false); }).catch(() => {});
  }
})();
</script>
