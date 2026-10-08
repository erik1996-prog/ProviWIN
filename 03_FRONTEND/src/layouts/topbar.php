<?php
// 03_FRONTEND/src/layouts/topbar.php - Encabezado superior: título de la sección y notificaciones
$user = Auth::getLoggedInUser();
?>
<header class="sgs-topbar sticky-top" aria-label="Barra superior">
    <button type="button" id="sidebarToggleMobile" class="sgs-icon-btn d-lg-none" title="Mostrar u ocultar menú" aria-label="Mostrar u ocultar menú lateral" aria-controls="sidebar" aria-expanded="true">
        <i class="bi bi-list"></i>
    </button>
    <div class="sgs-topbar-title">
        <h1><?= htmlspecialchars($pageTitle ?? APP_NAME) ?></h1>
        <?php if (!empty($pageDescription)): ?><p><?= htmlspecialchars($pageDescription) ?></p><?php endif; ?>
    </div>
    <div class="sgs-topbar-date d-none d-md-flex"><i class="bi bi-calendar3"></i><span><?= htmlspecialchars(date("d/m/Y")) ?></span></div>
</header>

<div class="dropdown floating-notifications">
    <button class="floating-notification-trigger position-relative" id="notificationBell" data-bs-toggle="dropdown" aria-expanded="false" title="Notificaciones" aria-label="Abrir notificaciones">
        <i class="bi bi-bell-fill"></i><span id="notificationCount" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none">0</span>
    </button>
    <div class="dropdown-menu dropdown-menu-end shadow border-0 p-0" style="width:min(380px,calc(100vw - 24px));" aria-labelledby="notificationBell">
        <div class="p-3 border-bottom fw-bold">Notificaciones</div>
        <div id="notificationList" style="max-height:380px;overflow:auto;"><div class="p-4 text-center text-muted small">No hay notificaciones nuevas.</div></div>
    </div>
</div>


<script>
    const notificationCsrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const notificationAlertKey = 'sgs-notification-alerted-<?= (int)$user['id'] ?>';
    let lastNotificationShown = 0;
    let notificationRequestRunning = false;
    try { lastNotificationShown = Number(localStorage.getItem(notificationAlertKey)) || 0; } catch (_) {}

    function safeNotificationDestination(value) {
        try {
            const url = new URL(String(value || '#'), location.href);
            return url.origin === location.origin ? url.href : '#';
        } catch (_) {
            return '#';
        }
    }

    function renderNotifications(items) {
        const list = document.getElementById('notificationList');
        list.replaceChildren();
        if (!items.length) {
            const empty = document.createElement('div');
            empty.className = 'p-4 text-center text-muted small';
            empty.textContent = 'No hay notificaciones nuevas.';
            list.appendChild(empty);
            return;
        }
        items.forEach((item) => {
            const link = document.createElement('a');
            link.className = 'dropdown-item p-3 border-bottom text-wrap notification-item';
            link.href = safeNotificationDestination(item.url_destino);
            link.dataset.id = String(item.id || '');
            const title = document.createElement('strong');
            title.className = 'd-block small text-danger';
            title.textContent = item.titulo || 'Notificación';
            const message = document.createElement('span');
            message.className = 'd-block small mt-1';
            message.textContent = item.mensaje || '';
            const date = document.createElement('small');
            date.className = 'text-muted';
            date.textContent = item.fecha_registro || '';
            link.append(title, message, date);
            list.appendChild(link);
        });
    }

    async function loadNotifications(showAlert = false) {
        if (notificationRequestRunning || (document.hidden && showAlert)) return;
        notificationRequestRunning = true;
        const bell = document.getElementById('notificationBell');
        bell?.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch('api/notificaciones.php?action=list', {cache:'no-store'});
            const data = await response.json();
            if (!response.ok || data.status !== 'success') throw new Error(data.message || 'No se pudieron cargar las notificaciones.');
            const badge = document.getElementById('notificationCount');
            const items = Array.isArray(data.items) ? data.items : [];
            const count = Number(data.count) || 0;
            badge.textContent = String(count);
            badge.classList.toggle('d-none', count < 1);
            renderNotifications(items);
            if (!items.length) return;
            const newest = items.reduce((latest, item) => Number(item.id) > Number(latest.id) ? item : latest, items[0]);
            // El aviso se muestra una vez; la notificación permanece guardada hasta que se lea.
            if (showAlert && Number(newest.id) > lastNotificationShown) {
                lastNotificationShown = Number(newest.id);
                try { localStorage.setItem(notificationAlertKey, String(lastNotificationShown)); } catch (_) {}
                showToast(`${newest.titulo}: ${newest.mensaje}`, 'warning');
            }
        } catch (error) {
            if (!showAlert) showToast(error.message || 'No se pudieron actualizar las notificaciones.', 'error');
        } finally {
            notificationRequestRunning = false;
            bell?.removeAttribute('aria-busy');
        }
    }
    window.addEventListener('storage', event => {
        if (event.key === notificationAlertKey) lastNotificationShown = Math.max(lastNotificationShown, Number(event.newValue) || 0);
    });
    document.getElementById('notificationList')?.addEventListener('click', async event => {
        const item = event.target.closest('.notification-item'); if (!item) return;
        event.preventDefault();
        if (item.dataset.reading) return;
        item.dataset.reading = '1';
        item.setAttribute('aria-disabled', 'true');
        item.classList.add('opacity-50');
        const body = new URLSearchParams({id:item.dataset.id});
        try {
            const response = await fetch('api/notificaciones.php?action=read',{method:'POST',headers:{'X-CSRF-Token':notificationCsrf,'Content-Type':'application/x-www-form-urlencoded'},body});
            const result = await response.json();
            if (!response.ok || result.status !== 'success') throw new Error('No se pudo marcar la notificación como leída.');
            const destination = new URL(item.href, location.href);
            item.remove();
            await loadNotifications();
            if (destination.origin === location.origin && destination.href !== location.href + '#') window.winNavigate(destination.href);
        } catch (error) {
            delete item.dataset.reading;
            item.removeAttribute('aria-disabled');
            item.classList.remove('opacity-50');
            showToast(error.message || 'No se pudo abrir la notificación. Inténtelo de nuevo.', 'error');
        }
    });
    document.addEventListener('DOMContentLoaded', () => {
        loadNotifications(true);
        window.setInterval(() => loadNotifications(true), 60000);
    });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) loadNotifications(true); });

</script>
