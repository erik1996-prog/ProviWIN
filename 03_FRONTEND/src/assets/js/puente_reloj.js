// 03_FRONTEND/src/assets/js/puente_reloj.js - Reloj de la ventana del vínculo con Wincore (2026-11-11). Corre en un Worker
// porque Chrome frena los temporizadores de las ventanas minimizadas o tapadas (hasta 1 por minuto) y el vínculo debe seguir
// preguntando cada pocos segundos. Recibe los milisegundos (0 = detener) y avisa en cada vuelta.
let reloj = null;
onmessage = (e) => { clearInterval(reloj); if (e.data > 0) reloj = setInterval(() => postMessage('vuelta'), e.data); };
