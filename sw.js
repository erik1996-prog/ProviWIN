// sw.js - Trabajador de fondo de la app instalable (2026-10-26). Sistema liviano: NO guarda páginas, datos ni la API en el
// celular (siempre se ve la versión del hosting). Solo guarda la página "Sin conexión" y su logo, y la muestra cuando
// una pantalla no carga por falta de señal. Para que el celular tome cambios de este archivo, subir la versión.
const VERSION = 'sgs-sin-conexion-1';
const SIN_CONEXION = 'offline.html';
const ARCHIVOS = [SIN_CONEXION, '03_FRONTEND/src/assets/img/win-logo-blanco.png'];

self.addEventListener('install', (ev) => {
    ev.waitUntil(caches.open(VERSION).then((c) => c.addAll(ARCHIVOS)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (ev) => {
    ev.waitUntil(caches.keys()
        .then((claves) => Promise.all(claves.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
        .then(() => self.clients.claim()));
});

// Solo las pantallas (navegación): si no hay señal, "Sin conexión". Todo lo demás pasa directo a la red.
self.addEventListener('fetch', (ev) => {
    if (ev.request.mode !== 'navigate') return;
    ev.respondWith(fetch(ev.request).catch(() => caches.match(SIN_CONEXION)));
});
