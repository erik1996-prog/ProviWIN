// 03_FRONTEND/src/assets/js/novedades.js - Recorrido "El sistema se actualizó" (2026-11-08, layouts/novedades.php).
// Se avanza con los botones, los puntos, las flechas del teclado o deslizando en el celular. Al terminar, omitir o ir a una
// novedad queda marcado como visto (api/novedades.php) y no vuelve a salir.
(() => {
  const raiz = document.getElementById('sgsNovedades');
  if (!raiz) return;
  const pasos = [...raiz.querySelectorAll('.sgs-nov-paso')];
  const puntos = [...raiz.querySelectorAll('[data-nov-ir]')];
  const anterior = document.getElementById('novAnterior');
  const siguiente = document.getElementById('novSiguiente');
  const omitir = document.getElementById('novOmitir');
  let actual = 0, marcada = false, inicioToque = null;

  const marcar = () => {
    if (marcada) return;
    marcada = true;
    fetch('api/novedades.php?action=vistas', { method: 'POST', keepalive: true, credentials: 'same-origin',
      headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' } }).catch(() => {});
  };
  const cerrar = () => {
    marcar();
    document.removeEventListener('keydown', teclas);
    document.body.classList.remove('sgs-nov-abierto');
    raiz.classList.add('is-saliendo');
    setTimeout(() => raiz.remove(), 300);
  };
  const pintar = () => {
    const ultimo = actual === pasos.length - 1;
    puntos.forEach((p, i) => { p.classList.toggle('is-actual', i === actual); p.setAttribute('aria-current', i === actual ? 'step' : 'false'); });
    anterior.hidden = actual === 0;
    omitir.hidden = ultimo;
    siguiente.innerHTML = ultimo ? 'Entrar al sistema<i class="bi bi-check-lg ms-1" aria-hidden="true"></i>' : 'Siguiente<i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>';
  };
  const ir = (i) => {
    if (i < 0 || i >= pasos.length || i === actual) return;
    const clase = i > actual ? 'entra-derecha' : 'entra-izquierda';
    pasos[actual].hidden = true;
    actual = i;
    pasos[i].hidden = false;
    pasos[i].classList.remove('entra-derecha', 'entra-izquierda');
    void pasos[i].offsetWidth; // reinicia la animación
    pasos[i].classList.add(clase);
    pintar();
  };
  function teclas(ev) {
    if (ev.key === 'ArrowRight') ir(actual + 1);
    else if (ev.key === 'ArrowLeft') ir(actual - 1);
    else if (ev.key === 'Escape') cerrar();
  }

  siguiente.addEventListener('click', () => (actual === pasos.length - 1 ? cerrar() : ir(actual + 1)));
  anterior.addEventListener('click', () => ir(actual - 1));
  omitir.addEventListener('click', cerrar);
  puntos.forEach((p) => p.addEventListener('click', () => ir(Number(p.dataset.novIr))));
  raiz.addEventListener('click', (ev) => { if (ev.target.closest('.sgs-nov-paso a')) marcar(); });
  raiz.addEventListener('touchstart', (ev) => { inicioToque = ev.changedTouches[0].clientX; }, { passive: true });
  raiz.addEventListener('touchend', (ev) => {
    const d = ev.changedTouches[0].clientX - inicioToque;
    if (Math.abs(d) > 50) ir(actual + (d < 0 ? 1 : -1));
  }, { passive: true });

  document.addEventListener('keydown', teclas);
  document.body.classList.add('sgs-nov-abierto');
  raiz.hidden = false;
  pintar();
  siguiente.focus();
})();
