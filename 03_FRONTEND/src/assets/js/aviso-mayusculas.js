// 03_FRONTEND/src/assets/js/aviso-mayusculas.js - Aviso "Mayúsculas activadas" (Bloq Mayús) en los campos de contraseña.
// 2026-10-16: en el ingreso, en el cambio de contraseña y en Mi información. Aparece debajo del campo mientras se
// escribe con Bloq Mayús activo y se oculta al salir del campo. Sigue avisando si se muestra la contraseña (pasa a texto).
// En celular el teclado no informa el Bloq Mayús: ahí no aparece. Estilo .sgs-mayus (sgs-login.css y sgs-theme.css).
(() => {
    const avisos = new WeakMap();
    const aviso = (input) => {
        let el = avisos.get(input);
        if (!el) {
            el = document.createElement('div');
            el.className = 'sgs-mayus';
            el.setAttribute('role', 'status');
            el.hidden = true;
            el.innerHTML = '<i class="bi bi-capslock-fill" aria-hidden="true"></i>Mayúsculas activadas';
            (input.closest('.input-group') || input).insertAdjacentElement('afterend', el);
            avisos.set(input, el);
        }
        return el;
    };
    const revisar = (e) => {
        const input = e.target;
        if (!(input instanceof HTMLInputElement) || !input.matches('input[type="password"], input[data-sgs-clave]')) return;
        if (typeof e.getModifierState !== 'function') return;
        input.dataset.sgsClave = '1';
        aviso(input).hidden = !e.getModifierState('CapsLock');
    };
    ['keydown', 'keyup', 'mousedown'].forEach((tipo) => document.addEventListener(tipo, revisar, true));
    document.addEventListener('focusout', (e) => { const el = avisos.get(e.target); if (el) el.hidden = true; }, true);
})();
