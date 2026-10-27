// 03_FRONTEND/src/assets/js/app-instalable.js - App instalable (PWA, 2026-10-26): registra sw.js (solo la página
// "Sin conexión") y muestra "Instalar app" en el menú cuando el celular o la PC lo permiten. Lo cargan index.php y login.php.
// 2026-10-27: el navegador de Huawei, Safari (iPhone), Firefox y los que abren WhatsApp o Teams no avisan que se puede
// instalar (solo Chrome, Edge y Samsung lo hacen). En el celular, si no hay aviso, "Instalar app" aparece igual y explica
// los pasos de ese navegador ("Añadir a la pantalla de inicio").
(() => {
    if (!('serviceWorker' in navigator) || !window.isSecureContext) return;
    // La ruta del sistema (ej. /ProviWIN/) sale de la propia página: el trabajador de fondo vale para todo el sistema
    const base = new URL('.', location.href).pathname;
    window.addEventListener('load', () => navigator.serviceWorker.register(base + 'sw.js', { scope: base }).catch(() => {}));

    const instalada = matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
    const celular = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
    let aviso = null;
    const boton = () => document.getElementById('btnInstalarApp');
    const mostrar = (si) => boton()?.closest('li')?.classList.toggle('d-none', !si);
    window.addEventListener('beforeinstallprompt', (ev) => {
        ev.preventDefault(); // se ofrece desde el menú, no con la ventana automática del navegador
        aviso = ev;
        mostrar(true);
    });
    window.addEventListener('appinstalled', () => { aviso = null; mostrar(false); });
    // Sin aviso del navegador a los 3 s (Huawei, iPhone, Firefox…): en el celular se ofrece con los pasos a mano
    if (celular && !instalada) window.addEventListener('load', () => setTimeout(() => { if (!aviso) mostrar(true); }, 3000));

    /** Pasos para instalar a mano según el navegador del celular. */
    const pasos = () => {
        const ua = navigator.userAgent;
        if (/FBAN|FBAV|Instagram|WhatsApp|Teams|; wv\)/i.test(ua)) return ['Este enlace se abrió dentro de otra aplicación (WhatsApp, Teams…), que no permite instalar.',
            'Toca los <strong>tres puntos</strong> y elige <strong>Abrir en el navegador</strong> (Chrome o el navegador de Huawei).', 'Ahí vuelve a tocar <strong>Instalar app</strong> en el menú del sistema.'];
        if (/HuaweiBrowser|HONORBrowser|ArkWeb/i.test(ua)) return ['Toca el botón de <strong>menú</strong> del navegador (las tres rayas o los tres puntos, abajo o arriba a la derecha).',
            'Elige <strong>Añadir a la pantalla de inicio</strong> (puede decir <strong>Añadir acceso directo</strong>).', 'Confirma con <strong>Añadir</strong>: el ícono de WIN aparecerá en tu pantalla de inicio.'];
        if (/iPhone|iPad|iPod/i.test(ua)) return ['Abre el sistema en <strong>Safari</strong>.', 'Toca el botón <strong>Compartir</strong> (el cuadrado con la flecha hacia arriba).',
            'Elige <strong>Agregar a inicio</strong> y luego <strong>Agregar</strong>.'];
        if (/Firefox/i.test(ua)) return ['Toca los <strong>tres puntos</strong> del navegador.', 'Elige <strong>Instalar</strong> (o <strong>Añadir a la pantalla de inicio</strong>).', 'Confirma con <strong>Añadir</strong>.'];
        return ['Toca el <strong>menú</strong> del navegador (los tres puntos).', 'Elige <strong>Instalar aplicación</strong> o <strong>Añadir a la pantalla de inicio</strong>.',
            'Confirma: el ícono de WIN aparecerá en tu pantalla de inicio. Si no ves la opción, abre el sistema en <strong>Chrome</strong>.'];
    };
    const ventanaPasos = () => {
        let modal = document.getElementById('modalInstalarApp');
        if (!modal) {
            modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = 'modalInstalarApp';
            modal.tabIndex = -1;
            modal.setAttribute('aria-labelledby', 'modalInstalarAppTitulo');
            modal.innerHTML = `<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
                <div class="modal-header"><h5 class="modal-title" id="modalInstalarAppTitulo"><i class="bi bi-phone me-2 text-primary"></i>Instalar la app</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                <div class="modal-body"><p class="text-muted small">Tu navegador no instala con un solo toque; hazlo así:</p><ol class="mb-0" id="modalInstalarAppPasos"></ol></div>
                <div class="modal-footer"><button type="button" class="btn btn-win" data-bs-dismiss="modal">Entendido</button></div></div></div>`;
            document.body.appendChild(modal);
        }
        modal.querySelector('#modalInstalarAppPasos').innerHTML = pasos().map((p) => `<li class="mb-2">${p}</li>`).join('');
        bootstrap.Modal.getOrCreateInstance(modal).show();
    };

    document.addEventListener('click', async (ev) => {
        if (!ev.target.closest('#btnInstalarApp')) return;
        ev.preventDefault();
        if (!aviso) { ventanaPasos(); return; }
        aviso.prompt();
        await aviso.userChoice.catch(() => null);
        aviso = null;
        mostrar(false);
    });
})();
