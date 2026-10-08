/* 03_FRONTEND/src/assets/js/main.js - JavaScript principal de SGS */

// Las transiciones entre páginas (@view-transition en CSS) rechazan una promesa interna cuando
// el navegador las omite (navegación rápida). No es un error: se captura para no ensuciar 08_LOGS/frontend.
['pagereveal', 'pageswap'].forEach((type) => window.addEventListener(type, (event) => {
    const transition = event.viewTransition;
    if (!transition) return;
    [transition.ready, transition.finished, transition.updateCallbackDone].forEach((promise) => promise?.catch(() => {}));
}));

document.addEventListener('DOMContentLoaded', () => {
    // Toggle para Menú Vertical Lateral (Soporte Móvil & Escritorio)
    const sidebar = document.getElementById('sidebar');
    const sidebarToggleMobile = document.getElementById('sidebarToggleMobile');
    const sidebarCloseMobile = document.getElementById('sidebarCloseMobile');
    const sidebarOverlay = document.getElementById('sidebar-overlay');
    const floatingNotifications = document.querySelector('.floating-notifications');

    function syncMobileFloatingControls() {
        const menuIsOpen = window.innerWidth < 992 && sidebar?.classList.contains('active');
        floatingNotifications?.classList.toggle('menu-open-hidden', Boolean(menuIsOpen));
    }

    function closeMobileSidebar({ restoreFocus = false } = {}) {
        const focusWasInside = Boolean(sidebar?.contains(document.activeElement));
        sidebar?.classList.remove('active');
        sidebarOverlay?.classList.remove('active');
        sidebarToggleMobile?.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('sidebar-mobile-open');
        syncMobileFloatingControls();
        if (restoreFocus && focusWasInside) sidebarToggleMobile?.focus({ preventScroll: true });
    }

    function toggleSidebar(e) {
        if (e) e.stopPropagation();
        if (!sidebar) return;

        if (window.innerWidth < 992) {
            // Móvil: Abrir / Cerrar menú lateral flotante
            sidebar.classList.toggle('active');
            if (sidebarOverlay) sidebarOverlay.classList.toggle('active');
            sidebarToggleMobile?.setAttribute('aria-expanded', String(sidebar.classList.contains('active')));
            document.body.classList.toggle('sidebar-mobile-open', sidebar.classList.contains('active'));
            syncMobileFloatingControls();
            if (sidebar.classList.contains('active')) {
                window.requestAnimationFrame(() => sidebar.querySelector('a[href], button:not([disabled])')?.focus({ preventScroll: true }));
            }
        }
        syncMobileFloatingControls();
    }

    if (sidebarToggleMobile) sidebarToggleMobile.addEventListener('click', toggleSidebar);
    if (sidebarCloseMobile) sidebarCloseMobile.addEventListener('click', closeMobileSidebar);

    function normalizeSidebarForViewport() {
        if (!sidebar) return;
        if (window.innerWidth < 992) {
            sidebar.classList.remove('collapsed');
            sidebarToggleMobile?.setAttribute('aria-expanded', String(sidebar.classList.contains('active')));
        } else {
            sidebar.classList.remove('collapsed');
            sidebar.classList.remove('active');
            sidebarOverlay?.classList.remove('active');
            sidebarToggleMobile?.setAttribute('aria-expanded', 'true');
        }
        syncMobileFloatingControls();
    }

    normalizeSidebarForViewport();
    let resizeFrame = 0;
    window.addEventListener('resize', () => {
        window.cancelAnimationFrame(resizeFrame);
        resizeFrame = window.requestAnimationFrame(normalizeSidebarForViewport);
    });

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', closeMobileSidebar);
    }

    document.addEventListener('click', (e) => {
        if (window.innerWidth <= 991 && sidebar && sidebar.classList.contains('active')) {
            if (!sidebar.contains(e.target) && (!sidebarToggleMobile || !sidebarToggleMobile.contains(e.target))) {
                closeMobileSidebar();
            }
        }
    });

    sidebar?.addEventListener('click', (event) => {
        if (window.innerWidth < 992 && event.target.closest('a[href]:not([data-bs-toggle])')) {
            closeMobileSidebar();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && window.innerWidth < 992 && sidebar?.classList.contains('active')) {
            event.preventDefault();
            closeMobileSidebar({ restoreFocus: true });
        }
    });

    // Mejora también los elementos agregados por listados asíncronos. Esto mantiene
    // accesibles modales, tablas desplazables y filas navegables sin duplicar lógica.
    const enhanceInteractiveContent = (root = document) => {
        const collect = (selector) => {
            const elements = Array.from(root.querySelectorAll?.(selector) || []);
            if (root instanceof Element && root.matches(selector)) elements.unshift(root);
            return elements;
        };
        if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
            collect('[data-bs-toggle="tooltip"]').forEach((trigger) => {
                bootstrap.Tooltip.getOrCreateInstance(trigger);
            });
        }
        collect('.modal-dialog').forEach((dialog) => dialog.classList.add('modal-dialog-scrollable'));
        collect('.modal').forEach((modal) => {
            const title = modal.querySelector('.modal-title');
            if (title && !title.id) title.id = `${modal.id || 'win-modal'}-title`;
            if (title && !modal.hasAttribute('aria-labelledby')) modal.setAttribute('aria-labelledby', title.id);
            modal.querySelectorAll('.btn-close:not([aria-label])').forEach((button) => button.setAttribute('aria-label', 'Cerrar'));
        });
        collect('[data-row-href], [data-href][role="link"]').forEach((row) => {
            row.setAttribute('role', 'link');
            if (!row.hasAttribute('tabindex')) row.tabIndex = 0;
        });
        collect('.table-responsive').forEach((region) => {
            if (!region.hasAttribute('tabindex')) region.tabIndex = 0;
            if (!region.hasAttribute('role')) region.setAttribute('role', 'region');
            if (!region.hasAttribute('aria-label')) {
                const heading = region.closest('.card, section, main')?.querySelector('h1,h2,h3,h4,h5,h6,.card-title');
                region.setAttribute('aria-label', heading?.textContent?.trim() || 'Tabla de datos');
            }
        });
    };
    enhanceInteractiveContent();
    const interactiveObserver = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => mutation.addedNodes.forEach((node) => {
            if (node instanceof Element) enhanceInteractiveContent(node);
        }));
    });
    interactiveObserver.observe(document.body, { childList: true, subtree: true });

    document.querySelectorAll('#sidebar li.active > a').forEach((link) => link.setAttribute('aria-current', 'page'));

    const modalTriggers = new WeakMap();
    document.addEventListener('show.bs.modal', (event) => {
        const modal = event.target;
        if (!(modal instanceof HTMLElement)) return;
        if (document.activeElement instanceof HTMLElement) modalTriggers.set(modal, document.activeElement);
        enhanceInteractiveContent(modal);
        modal.setAttribute('aria-busy', 'false');
    });

    document.addEventListener('shown.bs.modal', (event) => {
        const modal = event.target;
        if (!(modal instanceof HTMLElement)) return;
        const preferred = modal.querySelector('[autofocus], [data-modal-focus], input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled]):not(.btn-close), .btn-close');
        if (preferred instanceof HTMLElement) {
            window.requestAnimationFrame(() => preferred.focus({ preventScroll: true }));
        }
    });

    // Restablecer estado de botones de envío al cerrar ventanas flotantes (Modales)
    document.addEventListener('hidden.bs.modal', (event) => {
        const modal = event.target;
        if (!(modal instanceof HTMLElement)) return;
        modal.querySelectorAll('.win-submit-pending').forEach((button) => {
            if (button.dataset.winOriginalHtml) {
                button.innerHTML = button.dataset.winOriginalHtml;
            }
            button.disabled = false;
            button.classList.remove('win-submit-pending');
        });

        // Bootstrap puede dejar un fondo bloqueando la pantalla cuando el modal
        // se reemplaza dinámicamente. Solo se limpia cuando ya no queda otro abierto.
        const trigger = modalTriggers.get(modal);
        modalTriggers.delete(modal);
        window.requestAnimationFrame(() => {
            if (!document.querySelector('.modal.show')) {
                document.querySelectorAll('.modal-backdrop').forEach((backdrop) => backdrop.remove());
                document.body.classList.remove('modal-open');
                document.body.style.removeProperty('overflow');
                document.body.style.removeProperty('padding-right');
            }
            if (trigger?.isConnected && !trigger.closest('[inert]')) trigger.focus({ preventScroll: true });
        });
    });

    // Lleva al primer dato inválido y evita la sensación de que el botón no hizo nada.
    const formsWithScheduledInvalidFocus = new WeakSet();
    document.addEventListener('invalid', (event) => {
        const field = event.target;
        if (!(field instanceof HTMLElement)) return;
        const form = field.closest('form');
        form?.classList.add('was-validated');
        const collapsed = field.closest('.collapse:not(.show)');
        if (collapsed && typeof bootstrap !== 'undefined') {
            bootstrap.Collapse.getOrCreateInstance(collapsed).show();
        }
        if (form && formsWithScheduledInvalidFocus.has(form)) return;
        if (form) formsWithScheduledInvalidFocus.add(form);
        window.setTimeout(() => {
            const firstInvalid = form?.querySelector(':invalid') || field;
            firstInvalid.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' });
            firstInvalid.focus({ preventScroll: true });
            showToast('Revise los campos obligatorios señalados antes de continuar.', 'warning');
            if (form) formsWithScheduledInvalidFocus.delete(form);
        }, 0);
    }, true);

    // En envíos tradicionales informa claramente que la operación está en curso.
    document.addEventListener('submit', (event) => {
        window.setTimeout(() => {
            if (event.defaultPrevented || !event.target.checkValidity() || event.target.dataset.noSubmitLoading === 'true') return;
            const button = event.submitter;
            if (!button || button.classList.contains('win-submit-pending') || button.type === 'button') return;
            event.target.setAttribute('aria-busy', 'true');
            window.winSetButtonLoading(button, true);
        }, 50);
    });

    // Filas declaradas como navegables funcionan con ratón y teclado sin interferir
    // con botones, enlaces o controles que vivan dentro de la propia fila.
    const openInteractiveRow = (row, event = null) => {
        const href = row.dataset.rowHref || row.dataset.href;
        if (!href) return;
        if (event && (event.ctrlKey || event.metaKey)) window.open(href, '_blank', 'noopener');
        else window.winNavigate(href);
    };
    document.addEventListener('click', (event) => {
        const row = event.target.closest('[data-row-href], [data-href][role="link"]');
        if (!row || event.target.closest('a,button,input,select,textarea,label,[data-no-row-open]')) return;
        openInteractiveRow(row, event);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        const row = event.target.closest('[data-row-href], [data-href][role="link"]');
        if (!row) return;
        event.preventDefault();
        openInteractiveRow(row, event);
    });

    // Da respuesta inmediata al cambiar de sección. Se excluyen descargas,
    // pestañas, acciones de Bootstrap y enlaces que abren otra ventana.
    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest('a[href]');
        if (!link || link.target || link.hasAttribute('download') || link.dataset.bsToggle || link.dataset.bsDismiss) return;
        const href = link.getAttribute('href') || '';
        if (!href || href.startsWith('#') || href.startsWith('javascript:') || href.startsWith('mailto:') || href.startsWith('tel:')) return;
        let destination;
        try { destination = new URL(link.href, window.location.href); } catch (_) { return; }
        if (destination.origin !== window.location.origin) return;
        // Los archivos (api/...) se descargan sin salir de la página: no se atenúa la pantalla
        if (destination.pathname.includes('/api/')) return;
        if (destination.pathname === location.pathname && destination.search === location.search && destination.hash !== location.hash) return;
        if (destination.href === location.href) return;
        window.winStartPageTransition();
    });

    window.addEventListener('pageshow', () => {
        document.documentElement.classList.remove('win-page-transitioning');
        document.documentElement.removeAttribute('aria-busy');
        window.winResetTransactions?.();
        document.querySelectorAll('form[aria-busy="true"]').forEach((form) => form.removeAttribute('aria-busy'));
        document.querySelectorAll('.win-submit-pending').forEach((button) => window.winSetButtonLoading(button, false));
    });
});

// Helper de Notificaciones Toast
function showToast(message, type = 'info') {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
        container.style.zIndex = '9999';
        document.body.appendChild(container);
    }

    const normalizedType = String(type || 'info').toLowerCase();
    const tone = {
        success: { className: 'bg-success text-white', live: 'polite' },
        error: { className: 'bg-danger text-white', live: 'assertive' },
        danger: { className: 'bg-danger text-white', live: 'assertive' },
        warning: { className: 'bg-warning text-dark', live: 'assertive' },
        info: { className: 'bg-primary text-white', live: 'polite' }
    }[normalizedType] || { className: 'bg-primary text-white', live: 'polite' };

    // Evita que una ráfaga de avisos cubra por completo la interfaz.
    while (container.children.length >= 4) container.firstElementChild?.remove();

    const toastEl = document.createElement('div');
    toastEl.className = `toast align-items-center ${tone.className} border-0 shadow-lg`;
    toastEl.setAttribute('role', normalizedType === 'error' || normalizedType === 'danger' ? 'alert' : 'status');
    toastEl.setAttribute('aria-live', tone.live);
    toastEl.setAttribute('aria-atomic', 'true');

    const toastContent = document.createElement('div');
    toastContent.className = 'd-flex';
    const toastBody = document.createElement('div');
    toastBody.className = 'toast-body fw-semibold';
    toastBody.textContent = message;
    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = `btn-close ${normalizedType === 'warning' ? '' : 'btn-close-white'} me-2 m-auto`;
    closeButton.dataset.bsDismiss = 'toast';
    closeButton.setAttribute('aria-label', 'Cerrar');
    toastContent.append(toastBody, closeButton);
    toastEl.appendChild(toastContent);

    container.appendChild(toastEl);
    if (typeof bootstrap === 'undefined' || !bootstrap.Toast) {
        toastEl.classList.add('show');
        window.setTimeout(() => toastEl.remove(), 4000);
        return;
    }

    const bsToast = bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 4000 });
    bsToast.show();

    toastEl.addEventListener('hidden.bs.toast', () => {
        toastEl.remove();
    });
}
window.showToast = showToast;

window.winStartPageTransition = () => {
    if (document.documentElement.classList.contains('win-page-transitioning')) return;
    document.documentElement.classList.add('win-page-transitioning');
    document.documentElement.setAttribute('aria-busy', 'true');
    window.winBeginTransaction?.();
};

window.winNavigate = (href, { replace = false } = {}) => {
    if (!href) return;
    window.winStartPageTransition();
    if (replace) window.location.replace(href);
    else window.location.assign(href);
};

window.winReload = () => {
    window.winStartPageTransition();
    window.location.reload();
};

// Adjunta automáticamente el token CSRF a las mutaciones hechas con fetch.
(() => {
    const originalFetch = window.fetch.bind(window);
    let activeTransactions = 0;
    const transactionBar = document.createElement('div');
    transactionBar.className = 'win-transaction-bar';
    transactionBar.setAttribute('role', 'progressbar');
    transactionBar.setAttribute('aria-label', 'Procesando transacción');
    document.documentElement.appendChild(transactionBar);
    const beginTransaction = () => {
        activeTransactions += 1;
        transactionBar.classList.add('is-active');
        document.documentElement.setAttribute('aria-busy', 'true');
    };
    const endTransaction = () => {
        activeTransactions = Math.max(0, activeTransactions - 1);
        if (activeTransactions === 0) {
            transactionBar.classList.remove('is-active');
            document.documentElement.removeAttribute('aria-busy');
        }
    };
    const resetTransactions = () => {
        activeTransactions = 0;
        transactionBar.classList.remove('is-active');
        document.documentElement.removeAttribute('aria-busy');
    };
    window.winBeginTransaction = beginTransaction;
    window.winEndTransaction = endTransaction;
    window.winResetTransactions = resetTransactions;
    window.fetch = (resource, options = {}) => {
        const method = String(options.method || (resource instanceof Request ? resource.method : 'GET')).toUpperCase();
        const isMutation = ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method);
        const shouldTrack = isMutation || options.winTrack === true;
        const { winTrack: _winTrack, ...fetchOptions } = options;
        let requestUrl = null;
        try {
            const rawUrl = resource instanceof Request ? resource.url : resource;
            requestUrl = new URL(String(rawUrl), window.location.href);
        } catch (_) {}
        const isSameOrigin = requestUrl?.origin === window.location.origin;
        if (isSameOrigin) {
            const headers = new Headers(fetchOptions.headers || (resource instanceof Request ? resource.headers : undefined));
            headers.set('X-Requested-With', 'XMLHttpRequest');
            const token = document.querySelector('meta[name="csrf-token"]')?.content;
            if (isMutation && token && !headers.has('X-CSRF-Token')) headers.set('X-CSRF-Token', token);
            fetchOptions.headers = headers;
        }
        if (shouldTrack) beginTransaction();
        try {
            const request = originalFetch(resource, fetchOptions);
            const observedRequest = request.then((response) => {
                if (isSameOrigin && response.status === 401) {
                    window.dispatchEvent(new CustomEvent('win:session-expired'));
                }
                return response;
            });
            return shouldTrack ? observedRequest.finally(endTransaction) : observedRequest;
        } catch (error) {
            if (shouldTrack) endTransaction();
            throw error;
        }
    };
})();

let sessionExpiryHandled = false;
window.addEventListener('win:session-expired', () => {
    if (sessionExpiryHandled) return;
    sessionExpiryHandled = true;
    showToast('Tu sesión terminó. Te llevaremos al inicio de sesión.', 'warning');
    window.setTimeout(() => window.winNavigate('index.php?page=login', { replace: true }), 900);
});

// Utilidades comunes para acciones asíncronas. Evitan que cada módulo deje
// botones bloqueados o intente interpretar como JSON una página de error HTML.
window.winSetButtonLoading = (button, loading, label = 'Procesando...') => {
    if (!(button instanceof HTMLButtonElement) && !(button instanceof HTMLInputElement)) return;
    if (loading) {
        if (button.classList.contains('win-submit-pending')) return;
        button.dataset.winWasDisabled = button.disabled ? '1' : '0';
        if (button instanceof HTMLButtonElement) button.dataset.winOriginalHtml = button.innerHTML;
        else button.dataset.winOriginalValue = button.value;
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        button.classList.add('win-submit-pending');
        if (button instanceof HTMLButtonElement) {
            button.innerHTML = `<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>${label}`;
        } else {
            button.value = label;
        }
        return;
    }
    if (button instanceof HTMLButtonElement && button.dataset.winOriginalHtml !== undefined) button.innerHTML = button.dataset.winOriginalHtml;
    if (button instanceof HTMLInputElement && button.dataset.winOriginalValue !== undefined) button.value = button.dataset.winOriginalValue;
    button.disabled = button.dataset.winWasDisabled === '1';
    button.removeAttribute('aria-busy');
    button.classList.remove('win-submit-pending');
    delete button.dataset.winOriginalHtml;
    delete button.dataset.winOriginalValue;
    delete button.dataset.winWasDisabled;
};

window.winRunAction = async (button, task, label = 'Procesando...') => {
    if (button?.dataset.winActionRunning === '1') return undefined;
    if (button) {
        button.dataset.winActionRunning = '1';
        window.winSetButtonLoading(button, true, label);
    }
    try {
        return await task();
    } finally {
        if (button) {
            delete button.dataset.winActionRunning;
            window.winSetButtonLoading(button, false);
        }
    }
};

window.winFetchJson = async (resource, options = {}) => {
    const response = await window.fetch(resource, { ...options, winTrack: options.winTrack ?? true });
    const contentType = response.headers.get('content-type') || '';
    let payload = null;
    if (contentType.includes('json')) {
        try {
            payload = await response.json();
        } catch (_) {
            throw new Error(`El servidor devolvió una respuesta JSON incompleta (HTTP ${response.status})`);
        }
    } else {
        const text = await response.text();
        throw new Error(text && text.length < 240 ? text : `Respuesta no válida del servidor (HTTP ${response.status})`);
    }
    if (!response.ok || payload?.status === 'error') {
        throw new Error(payload?.message || `La operación falló (HTTP ${response.status})`);
    }
    return payload;
};

// Visor de fotos (window.sgsVerFoto): la foto entra completa y se puede acercar con los botones,
// la rueda del mouse, doble clic o pellizco; con zoom se arrastra para recorrerla. Teclas: + − 0.
(function () {
    const MIN = 1, MAX = 6, PASO = 1.4;
    let modal, img, lienzo, porcentaje, enlace, titulo, bsModal;
    let escala = 1, x = 0, y = 0;
    const punteros = new Map();
    let pellizco = null, arrastre = null;

    function crear() {
        if (modal) return;
        modal = document.createElement('div');
        modal.className = 'modal fade sgs-visor';
        modal.id = 'sgsVisorFoto';
        modal.tabIndex = -1;
        modal.setAttribute('aria-labelledby', 'sgsVisorTitulo');
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML = `
          <div class="modal-dialog modal-dialog-centered modal-xl modal-fullscreen-md-down">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="sgsVisorTitulo">Foto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
              </div>
              <div class="sgs-visor-lienzo" data-visor-lienzo>
                <img class="sgs-visor-img" alt="" draggable="false" data-visor-img>
              </div>
              <div class="modal-footer sgs-visor-barra">
                <div class="sgs-visor-zoom" role="group" aria-label="Zoom">
                  <button type="button" class="btn btn-outline-secondary btn-sm" data-visor="menos" aria-label="Alejar" title="Alejar (−)"><i class="bi bi-zoom-out"></i></button>
                  <span class="sgs-visor-pct" data-visor-pct aria-live="polite">100%</span>
                  <button type="button" class="btn btn-outline-secondary btn-sm" data-visor="mas" aria-label="Acercar" title="Acercar (+)"><i class="bi bi-zoom-in"></i></button>
                  <button type="button" class="btn btn-outline-secondary btn-sm" data-visor="ajustar" title="Ver la foto completa (0)"><i class="bi bi-arrows-angle-contract me-1"></i>Ajustar</button>
                </div>
                <span class="sgs-visor-ayuda d-none d-md-inline">Rueda del mouse o doble clic para acercar · arrastra para mover</span>
                <a class="btn btn-win btn-sm" data-visor-enlace href="#" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>Abrir original</a>
              </div>
            </div>
          </div>`;
        document.body.appendChild(modal);
        img = modal.querySelector('[data-visor-img]');
        lienzo = modal.querySelector('[data-visor-lienzo]');
        porcentaje = modal.querySelector('[data-visor-pct]');
        enlace = modal.querySelector('[data-visor-enlace]');
        titulo = modal.querySelector('#sgsVisorTitulo');
        bsModal = bootstrap.Modal.getOrCreateInstance(modal);

        modal.querySelector('[data-visor="mas"]').addEventListener('click', () => zoom(escala * PASO));
        modal.querySelector('[data-visor="menos"]').addEventListener('click', () => zoom(escala / PASO));
        modal.querySelector('[data-visor="ajustar"]').addEventListener('click', () => { escala = 1; x = 0; y = 0; pintar(); });
        modal.addEventListener('hidden.bs.modal', () => { img.removeAttribute('src'); });
        modal.addEventListener('keydown', (e) => {
            if (e.key === '+' || e.key === '=') { e.preventDefault(); zoom(escala * PASO); }
            else if (e.key === '-') { e.preventDefault(); zoom(escala / PASO); }
            else if (e.key === '0') { e.preventDefault(); escala = 1; x = 0; y = 0; pintar(); }
        });
        lienzo.addEventListener('wheel', (e) => {
            e.preventDefault();
            zoom(escala * (e.deltaY < 0 ? 1.2 : 1 / 1.2), puntoRelativo(e));
        }, { passive: false });
        lienzo.addEventListener('dblclick', (e) => zoom(escala > 1 ? 1 : 2.5, puntoRelativo(e)));
        lienzo.addEventListener('pointerdown', (e) => {
            lienzo.setPointerCapture(e.pointerId);
            punteros.set(e.pointerId, { x: e.clientX, y: e.clientY });
            if (punteros.size === 2) {
                const [a, b] = [...punteros.values()];
                pellizco = { d: Math.hypot(a.x - b.x, a.y - b.y), escala, centro: puntoRelativo({ clientX: (a.x + b.x) / 2, clientY: (a.y + b.y) / 2 }) };
                arrastre = null;
            } else if (escala > 1) {
                arrastre = { px: e.clientX, py: e.clientY, x, y };
                lienzo.classList.add('is-arrastrando');
            }
        });
        lienzo.addEventListener('pointermove', (e) => {
            if (!punteros.has(e.pointerId)) return;
            punteros.set(e.pointerId, { x: e.clientX, y: e.clientY });
            if (pellizco && punteros.size === 2) {
                const [a, b] = [...punteros.values()];
                zoom(pellizco.escala * Math.hypot(a.x - b.x, a.y - b.y) / pellizco.d, pellizco.centro);
            } else if (arrastre) {
                x = arrastre.x + e.clientX - arrastre.px;
                y = arrastre.y + e.clientY - arrastre.py;
                pintar();
            }
        });
        const soltar = (e) => {
            punteros.delete(e.pointerId);
            if (punteros.size < 2) pellizco = null;
            if (!punteros.size) { arrastre = null; lienzo.classList.remove('is-arrastrando'); }
        };
        lienzo.addEventListener('pointerup', soltar);
        lienzo.addEventListener('pointercancel', soltar);
    }

    // Punto respecto al centro del lienzo (el origen del zoom)
    function puntoRelativo(e) {
        const r = lienzo.getBoundingClientRect();
        return { x: e.clientX - r.left - r.width / 2, y: e.clientY - r.top - r.height / 2 };
    }

    // Acerca manteniendo fijo el punto indicado (por defecto, el centro)
    function zoom(nueva, punto = { x: 0, y: 0 }) {
        nueva = Math.min(MAX, Math.max(MIN, nueva));
        const f = nueva / escala;
        x = punto.x - (punto.x - x) * f;
        y = punto.y - (punto.y - y) * f;
        escala = nueva;
        pintar();
    }

    function pintar() {
        if (escala <= 1) { x = 0; y = 0; }
        // No deja que la foto se escape por completo del lienzo
        const r = lienzo.getBoundingClientRect();
        const limX = r.width * (escala - 1) / 2, limY = r.height * (escala - 1) / 2;
        x = Math.min(limX, Math.max(-limX, x));
        y = Math.min(limY, Math.max(-limY, y));
        img.style.transform = `translate(${x}px, ${y}px) scale(${escala})`;
        porcentaje.textContent = `${Math.round(escala * 100)}%`;
        lienzo.classList.toggle('is-zoom', escala > 1);
        modal.querySelector('[data-visor="menos"]').disabled = escala <= MIN;
        modal.querySelector('[data-visor="mas"]').disabled = escala >= MAX;
    }

    window.sgsVerFoto = function (src, texto) {
        if (!src) return;
        if (typeof bootstrap === 'undefined' || !bootstrap.Modal) { window.open(src, '_blank', 'noopener'); return; }
        crear();
        escala = 1; x = 0; y = 0;
        img.src = src;
        img.alt = texto || 'Foto';
        titulo.textContent = texto || 'Foto';
        enlace.href = src;
        pintar();
        bsModal.show();
    };
})();
