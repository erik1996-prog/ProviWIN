// 03_FRONTEND/src/assets/js/password-meter.js - Indicador de seguridad de contraseña (2026-10-15).
// Uso: <div data-password-meter="idDelCampo"></div> debajo del campo. Solo usa clases de Bootstrap.
// Obligatorio (lo exige el servidor): 10 caracteres, letras y números. Recomendado: mayúsculas y minúsculas, un símbolo.
(() => {
  const reglas = [
    { texto: 'Al menos 10 caracteres', ok: v => v.length >= 10, obligatoria: true },
    { texto: 'Letras', ok: v => /[A-Za-zÁÉÍÓÚáéíóúÑñ]/.test(v), obligatoria: true },
    { texto: 'Números', ok: v => /\d/.test(v), obligatoria: true },
    { texto: 'Mayúsculas y minúsculas', ok: v => /[A-ZÁÉÍÓÚÑ]/.test(v) && /[a-záéíóúñ]/.test(v) },
    { texto: 'Un símbolo (por ejemplo # $ * !)', ok: v => /[^A-Za-z0-9ÁÉÍÓÚáéíóúÑñ\s]/.test(v) },
  ];
  const niveles = [
    { nombre: 'Débil', clase: 'bg-danger', ancho: 25 },
    { nombre: 'Aceptable', clase: 'bg-warning', ancho: 50 },
    { nombre: 'Buena', clase: 'bg-success', ancho: 75 },
    { nombre: 'Fuerte', clase: 'bg-success', ancho: 100 },
  ];

  const montar = (caja) => {
    const campo = document.getElementById(caja.dataset.passwordMeter);
    if (!campo || caja.dataset.listo) return;
    caja.dataset.listo = '1';
    caja.classList.add('mt-2', 'd-none');
    caja.innerHTML = '<div class="d-flex align-items-center gap-2 small"><div class="progress flex-grow-1" role="progressbar" aria-label="Seguridad de la contraseña" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar"></div></div><strong class="text-nowrap" aria-live="polite"></strong></div>'
      + '<ul class="list-unstyled small mb-0 mt-1">' + reglas.map(r => '<li><i class="bi bi-circle me-1"></i>' + r.texto + (r.obligatoria ? '' : ' <span class="text-muted">(recomendado)</span>') + '</li>').join('') + '</ul>';
    const barra = caja.querySelector('.progress-bar'), etiqueta = caja.querySelector('strong'), items = caja.querySelectorAll('li');

    const pintar = () => {
      const v = campo.value;
      caja.classList.toggle('d-none', v === '');
      if (v === '') return;
      const res = reglas.map(r => r.ok(v));
      res.forEach((ok, i) => {
        items[i].className = ok ? 'text-success' : 'text-muted';
        items[i].querySelector('i').className = 'bi me-1 ' + (ok ? 'bi-check-circle-fill' : 'bi-circle');
      });
      const minimo = res.slice(0, 3).every(Boolean);
      const extra = res.slice(3).filter(Boolean).length;
      const nivel = !minimo ? niveles[0] : (extra === 2 && v.length >= 12 ? niveles[3] : (extra >= 1 ? niveles[2] : niveles[1]));
      barra.className = 'progress-bar ' + nivel.clase;
      barra.style.width = nivel.ancho + '%';
      barra.parentElement.setAttribute('aria-valuenow', nivel.ancho);
      etiqueta.textContent = nivel.nombre;
    };
    campo.addEventListener('input', pintar);
    campo.addEventListener('change', pintar);
    pintar();
  };

  window.sgsMedidorPassword = () => document.querySelectorAll('[data-password-meter]').forEach(montar);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', window.sgsMedidorPassword);
  else window.sgsMedidorPassword();
})();
