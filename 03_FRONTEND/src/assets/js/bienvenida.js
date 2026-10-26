// 03_FRONTEND/src/assets/js/bienvenida.js - Bienvenida animada (2026-10-26, layouts/bienvenida.php, sgs-bienvenida.css).
// Al abrir la app desde el ícono (data-al-abrir): ~2 s y se va sola, dejando ver el ingreso o el sistema. Se quita
// ?origen=app de la dirección para que recargar no la repita. Al iniciar sesión la muestra login.php (window.sgsBienvenida).
(() => {
    const caja = document.getElementById('bienvenida');
    if (!caja) return;
    const quieta = matchMedia('(prefers-reduced-motion: reduce)').matches;
    const texto = () => setTimeout(() => { const t = document.getElementById('bienvenidaTexto'); if (t) t.textContent = 'Preparando tu información…'; }, 950);
    // Muestra la bienvenida y, al terminar, llama a "luego" (ir al sistema) o la oculta
    window.sgsBienvenida = (saludo, luego, duracion = 2100) => {
        if (saludo) document.getElementById('bienvenidaSaludo').textContent = saludo;
        caja.hidden = false;
        texto();
        setTimeout(() => {
            if (luego) return luego();
            caja.classList.add('is-saliendo');
            setTimeout(() => { caja.hidden = true; caja.classList.remove('is-saliendo'); }, 400); // se reusa al iniciar sesión
        }, quieta ? 700 : duracion);
    };
    if (caja.hasAttribute('data-al-abrir')) {
        const url = new URL(location.href);
        url.searchParams.delete('origen');
        history.replaceState(null, '', url.pathname + (url.search || '') + url.hash);
        window.sgsBienvenida('', null, 2400); // nace del logo de Android (sgs-bienvenida.css): un poco más larga
    }
})();
