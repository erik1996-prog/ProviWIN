(() => {
    const endpoint = 'api/error_tickets.php?action=report';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const originalFetch = window.fetch.bind(window);
    const transientStatuses = new Set([502, 503, 504]);
    let reporting = false;
    let healthFailures = 0;
    let healthBanner = null;
    let healthTimer = 0;

    const showHealth = (message) => {
        if (!healthBanner) {
            healthBanner = document.createElement('div');
            healthBanner.id = 'system-health-alert';
            healthBanner.setAttribute('role', 'alert');
            healthBanner.setAttribute('aria-live', 'assertive');
            healthBanner.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:20000;padding:10px 16px;background:#b42318;color:#fff;text-align:center;font:600 14px Inter,system-ui,sans-serif;box-shadow:0 2px 8px #0004';
            document.body.appendChild(healthBanner);
        }
        healthBanner.textContent = message;
    };

    const hideHealth = () => {
        healthFailures = 0;
        if (healthBanner) {
            healthBanner.remove();
            healthBanner = null;
        }
    };

    const report = (tipo, mensaje, detalle = '', codigo = '') => {
        if (reporting || !csrf) return;
        reporting = true;
        originalFetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify({
                tipo,
                mensaje: String(mensaje || 'Error sin detalle').slice(0, 1000),
                detalle: String(detalle || '').slice(0, 8000),
                codigo_http: String(codigo || ''),
                pagina: location.href,
            }),
        }).catch(() => {}).finally(() => { reporting = false; });
    };

    window.addEventListener('error', (event) => {
        report('JavaScript', event.message, event.error?.stack || `${event.filename || ''}:${event.lineno || 0}:${event.colno || 0}`);
    });
    window.addEventListener('unhandledrejection', (event) => {
        // Transición de página omitida por el navegador: no es un fallo de la aplicación.
        if (['AbortError', 'InvalidStateError'].includes(event.reason?.name) && /transition/i.test(event.reason?.message || '')) { event.preventDefault(); return; }
        report('Promesa no controlada', event.reason?.message || String(event.reason), event.reason?.stack || '');
    });

    window.fetch = async (...args) => {
        const url = String(args[0]?.url || args[0] || '');
        const isMonitoringRequest = url.includes('error_tickets.php') || url.includes('health.php');
        try {
            const response = await originalFetch(...args);
            if (!isMonitoringRequest && response.status >= 500) {
                const text = await response.clone().text();
                report('Respuesta del servidor', `HTTP ${response.status} en ${url}`, text, response.status);
            }
            if (!isMonitoringRequest && transientStatuses.has(response.status)) {
                healthFailures = 2;
                showHealth('Conexión temporalmente interrumpida. El sistema está verificando el servicio; no cierre esta página.');
                scheduleHealth(5000);
            }
            return response;
        } catch (error) {
            if (!isMonitoringRequest) {
                report('Fallo de red', `No se pudo conectar con ${url}`, error?.stack || error?.message || '');
                healthFailures = 2;
                showHealth('No fue posible conectar con el sistema. Se verificará la conexión automáticamente.');
                scheduleHealth(5000);
            }
            throw error;
        }
    };

    const checkHealth = async () => {
        if (document.hidden) return false;
        try {
            const response = await originalFetch('api/health.php', {
                cache: 'no-store',
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();
            if (!response.ok || data.status !== 'ok') throw new Error('Servicio degradado');
            hideHealth();
            return true;
        } catch (_) {
            healthFailures += 1;
            if (healthFailures >= 2) {
                showHealth('Conexión interrumpida. El sistema está reintentando automáticamente; no cierre esta página.');
            }
            return false;
        }
    };

    const scheduleHealth = (delay = 0) => {
        window.clearTimeout(healthTimer);
        healthTimer = window.setTimeout(async () => {
            await checkHealth();
            // En estado sano se usa un pulso liviano y con dispersión; ante un
            // fallo se reintenta pronto sin que todas las pestañas golpeen juntas.
            const nextDelay = healthFailures > 0
                ? 15000 + Math.round(Math.random() * 5000)
                : 180000 + Math.round(Math.random() * 60000);
            scheduleHealth(nextDelay);
        }, delay);
    };

    window.addEventListener('online', () => scheduleHealth(0));
    window.addEventListener('offline', () => {
        healthFailures = 2;
        showHealth('Sin conexión a Internet. Sus cambios no se enviarán hasta recuperar la conexión.');
    });
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && healthBanner) scheduleHealth(0);
    });

    scheduleHealth(30000);
})();
