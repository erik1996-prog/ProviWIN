// 03_FRONTEND/src/assets/js/app-instalable.js - App instalable (PWA, 2026-10-26): registra sw.js (solo la página
// "Sin conexión") y muestra "Instalar app" en el menú cuando el celular o la PC lo permiten. Lo cargan index.php y login.php.
(() => {
    if (!('serviceWorker' in navigator) || !window.isSecureContext) return;
    // La ruta del sistema (ej. /ProviWIN/) sale de la propia página: el trabajador de fondo vale para todo el sistema
    const base = new URL('.', location.href).pathname;
    window.addEventListener('load', () => navigator.serviceWorker.register(base + 'sw.js', { scope: base }).catch(() => {}));

    let aviso = null;
    const boton = () => document.getElementById('btnInstalarApp');
    window.addEventListener('beforeinstallprompt', (ev) => {
        ev.preventDefault(); // se ofrece desde el menú, no con la ventana automática del navegador
        aviso = ev;
        boton()?.closest('li')?.classList.remove('d-none');
    });
    window.addEventListener('appinstalled', () => { aviso = null; boton()?.closest('li')?.classList.add('d-none'); });
    document.addEventListener('click', async (ev) => {
        if (!ev.target.closest('#btnInstalarApp') || !aviso) return;
        ev.preventDefault();
        aviso.prompt();
        await aviso.userChoice.catch(() => null);
        aviso = null;
        boton()?.closest('li')?.classList.add('d-none');
    });
})();
