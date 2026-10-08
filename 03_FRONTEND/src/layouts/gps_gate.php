<?php
// 03_FRONTEND/src/layouts/gps_gate.php - GPS obligatorio para el personal de campo (supervisores directos y de partner).
// Mientras la ubicación no esté disponible, una pantalla bloquea el uso del sistema y explica cómo activarla.
// Se vuelve a comprobar cada 3 minutos y cuando cambia el permiso. La última posición queda en sessionStorage
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
  const RECHEQUEO_MS = 3 * 60 * 1000, VIGENCIA_MS = 5 * 60 * 1000;
  let comprobando = false;

  const leerGuardado = () => { try { return JSON.parse(sessionStorage.getItem('sgs-gps') || 'null'); } catch (_) { return null; } };
  const guardar = (pos) => {
    const dato = { lat: pos.coords.latitude, lng: pos.coords.longitude, precision: Math.round(pos.coords.accuracy), t: Date.now() };
    try { sessionStorage.setItem('sgs-gps', JSON.stringify(dato)); } catch (_) {}
    window.dispatchEvent(new CustomEvent('sgs:gps', { detail: dato }));
  };
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

  function comprobar(mostrarProgreso) {
    if (comprobando) return;
    if (!window.isSecureContext || !('geolocation' in navigator)) {
      return mostrarError('Ubicación no disponible', 'Este navegador no permite usar el GPS en esta dirección. El sistema debe abrirse con conexión segura (https). Avisa al administrador.', []);
    }
    comprobando = true;
    if (mostrarProgreso) { btn.disabled = true; estado.textContent = 'Buscando tu ubicación…'; }
    navigator.geolocation.getCurrentPosition((pos) => {
      comprobando = false; guardar(pos); cerrar();
    }, (err) => {
      comprobando = false;
      if (err.code === err.PERMISSION_DENIED) {
        mostrarError('Permite el acceso a tu ubicación', 'El navegador tiene bloqueado el permiso de ubicación para este sistema.', [
          'Toca el candado <i class="bi bi-lock"></i> junto a la dirección de la página.',
          'En <strong>Ubicación</strong>, elige <strong>Permitir</strong>.',
          'Vuelve aquí y presiona <strong>Reintentar</strong>.'
        ]);
      } else if (err.code === err.POSITION_UNAVAILABLE) {
        mostrarError('Enciende el GPS', 'No se pudo obtener tu ubicación: el GPS del equipo parece estar apagado.', [
          'Desliza el panel de accesos rápidos del celular.',
          'Activa <strong>Ubicación</strong> (GPS).',
          'Presiona <strong>Reintentar</strong>.'
        ]);
      } else {
        mostrarError('Sin señal de ubicación', 'El GPS tardó demasiado en responder. Acércate a una ventana o a un lugar abierto y vuelve a intentar.', []);
      }
    }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
  }

  btn.addEventListener('click', () => comprobar(true));
  // Si hay una ubicación reciente de esta sesión no se bloquea al cambiar de pantalla; se verifica en segundo plano.
  const previo = leerGuardado();
  if (previo && Date.now() - previo.t < VIGENCIA_MS) comprobar(false);
  else { abrir(); estado.textContent = 'Verificando ubicación…'; btn.disabled = true; comprobar(true); }
  setInterval(() => comprobar(false), RECHEQUEO_MS);
  if (navigator.permissions && navigator.permissions.query) {
    navigator.permissions.query({ name: 'geolocation' }).then((p) => { p.onchange = () => comprobar(false); }).catch(() => {});
  }
})();
</script>
