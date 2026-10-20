// 03_FRONTEND/src/assets/js/examen.js - Pantalla del examen del técnico (link general, 2026-10-20).
// Una pregunta por pantalla, con avance, tiempo y mapa de preguntas. Tipos: unica / caso (una), multiple (varias),
// vf (verdadero o falso) y orden (tocar los pasos en orden). Cada respuesta se guarda al momento en el servidor; si se
// corta, al volver a entrar retoma donde quedó (el tiempo sigue). Al terminar o al acabarse el tiempo se califica en el
// servidor y se muestra la nota, si aprobó y los temas a reforzar (nunca las respuestas correctas).
// Página: 03_FRONTEND/src/pages/examen_general.php (data-* de #examenApp). Estilos: sgs-login.css (sección examen).
(() => {
    const app = document.getElementById('examenApp');
    if (!app) return;
    const d = app.dataset;
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const tipos = { unica: 'Elige una', caso: 'Caso de campo · elige una', multiple: 'Marca todas las correctas', vf: 'Verdadero o falso', orden: 'Toca los pasos en orden' };
    let preguntas = [], respuestas = {}, actual = 0, fin = 0, reloj = null, terminando = false;

    const llamar = async (accion, datos = null) => {
        const opciones = datos ? { method: 'POST', body: datos, credentials: 'same-origin' } : { credentials: 'same-origin' };
        const res = await fetch(`${d.url}&accion=${accion}`, opciones);
        const r = await res.json().catch(() => ({ status: 'error', message: 'Sin conexión. Revisa tu señal.' }));
        if (r.codigo === 'sin_llave') { location.reload(); throw new Error(r.message); }
        return r;
    };

    // ---------- Instrucciones ----------
    const instrucciones = () => {
        app.innerHTML = `<div class="sgs-system">Sistema de Gestión de Supervisión</div>
            <div class="sgs-examen-icono"><i class="bi bi-shield-check" aria-hidden="true"></i></div>
            <h1>Hola, ${esc(d.nombre)}</h1>
            <p class="sgs-sub">${esc(d.titulo)}${d.partner ? ' · ' + esc(d.partner) : ''}</p>
            <ul class="ex-reglas">
              <li><i class="bi bi-list-ol" aria-hidden="true"></i><span><strong>${esc(d.preguntas)} preguntas</strong> al azar: casos de campo, verdadero o falso, marcar varias y ordenar pasos.</span></li>
              <li><i class="bi bi-stopwatch" aria-hidden="true"></i><span><strong>${esc(d.minutos)} minutos.</strong> El tiempo corre desde que presionas "Empezar" y no se detiene.</span></li>
              <li><i class="bi bi-award" aria-hidden="true"></i><span>Apruebas con <strong>${esc(d.aprueba)} %</strong> de respuestas correctas.</span></li>
              <li><i class="bi bi-1-circle" aria-hidden="true"></i><span><strong>Una sola oportunidad.</strong> Si se corta la señal, vuelve a entrar con tu DNI y sigues donde quedaste.</span></li>
            </ul>
            <button type="button" class="btn btn-sgs w-100" id="exEmpezar"><i class="bi bi-play-fill me-1"></i>Empezar examen</button>`;
        document.getElementById('exEmpezar').addEventListener('click', async (ev) => {
            ev.currentTarget.disabled = true;
            try { iniciar(await llamar('empezar', new FormData())); }
            catch (e) { ev.currentTarget.disabled = false; aviso(e.message); }
        });
    };

    const iniciar = (r) => {
        if (r.status === 'terminado') return resultado(r.resultado);
        if (r.status !== 'success') return aviso(r.message || 'No se pudo abrir el examen.');
        preguntas = r.preguntas;
        respuestas = r.respuestas || {};
        fin = Date.now() + r.segundos * 1000;
        actual = Math.max(0, preguntas.findIndex((p) => !(respuestas[p.n] || []).length));
        if (actual < 0) actual = 0;
        clearInterval(reloj);
        reloj = setInterval(tic, 500);
        pintar();
    };

    const aviso = (texto) => {
        let a = app.querySelector('.ex-aviso');
        if (!a) { a = document.createElement('div'); a.className = 'alert alert-danger sgs-alert ex-aviso'; a.setAttribute('role', 'alert'); app.prepend(a); }
        a.textContent = texto;
    };

    // ---------- Tiempo ----------
    const restante = () => Math.max(0, Math.round((fin - Date.now()) / 1000));
    const tic = () => {
        const s = restante();
        const el = document.getElementById('exReloj');
        if (el) {
            el.textContent = `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
            el.closest('.ex-reloj')?.classList.toggle('is-poco', s <= 60);
        }
        if (s <= 0) terminar(true);
    };

    // ---------- Respuestas (se guardan al momento) ----------
    const pendientes = new Map();
    const guardar = (n) => {
        clearTimeout(pendientes.get(n));
        pendientes.set(n, setTimeout(async () => {
            pendientes.delete(n);
            const datos = new FormData();
            datos.append('n', n);
            (respuestas[n] || []).forEach((k) => datos.append('r[]', k));
            try {
                const r = await llamar('responder', datos);
                if (r.status === 'terminado') return resultado(r.resultado);
                document.getElementById('exGuardado')?.classList.remove('is-error');
            } catch (e) {
                const g = document.getElementById('exGuardado');
                if (g) { g.classList.add('is-error'); g.textContent = 'Sin conexión: se guardará al volver la señal'; }
                pendientes.set(n, setTimeout(() => guardar(n), 3000));
            }
        }, 250));
    };
    const responder = (p, k) => {
        let r = [...(respuestas[p.n] || [])];
        if (p.tipo === 'multiple') r = r.includes(k) ? r.filter((x) => x !== k) : [...r, k];
        else if (p.tipo === 'orden') { if (!r.includes(k)) r.push(k); }
        else r = [k];
        respuestas[p.n] = r;
        guardar(p.n);
        pintar();
    };

    // ---------- Pregunta ----------
    const pintar = () => {
        const p = preguntas[actual];
        const r = respuestas[p.n] || [];
        const total = preguntas.length;
        const hechas = preguntas.filter((q) => (respuestas[q.n] || []).length).length;
        const opciones = p.opciones.map((o) => {
            const pos = r.indexOf(o.k);
            const marcada = pos >= 0;
            const marca = p.tipo === 'orden' ? (marcada ? pos + 1 : '') : (p.tipo === 'multiple' ? (marcada ? '<i class="bi bi-check-lg"></i>' : '') : '');
            return `<button type="button" class="ex-opcion ex-opcion--${p.tipo}${marcada ? ' is-marcada' : ''}" data-k="${o.k}" aria-pressed="${marcada}">
                <span class="ex-opcion-marca" aria-hidden="true">${marca}</span><span class="ex-opcion-texto">${esc(o.texto)}</span></button>`;
        }).join('');
        app.innerHTML = `<div class="ex-cabecera">
              <span class="ex-contador">Pregunta <strong>${actual + 1}</strong> de ${total}</span>
              <span class="ex-reloj" title="Tiempo restante"><i class="bi bi-stopwatch" aria-hidden="true"></i><span id="exReloj">--:--</span></span>
            </div>
            <div class="ex-progreso" role="progressbar" aria-valuemin="0" aria-valuemax="${total}" aria-valuenow="${hechas}" aria-label="Preguntas respondidas"><span style="width:${(hechas / total) * 100}%"></span></div>
            <div class="ex-pregunta${p.tipo === 'caso' ? ' ex-pregunta--caso' : ''}">
              <div class="ex-etiquetas"><span class="ex-tema">${esc(p.tema)}</span><span class="ex-tipo">${esc(tipos[p.tipo] || '')}</span></div>
              ${p.tipo === 'caso' ? '<div class="ex-caso-titulo"><i class="bi bi-cone-striped" aria-hidden="true"></i>Situación en campo</div>' : ''}
              <h2 class="ex-texto">${esc(p.texto)}</h2>
              <div class="ex-opciones ${p.tipo === 'vf' ? 'ex-opciones--vf' : ''}">${opciones}</div>
              ${p.tipo === 'orden' && r.length ? '<button type="button" class="btn btn-link btn-sm p-0 mt-2" id="exReiniciar"><i class="bi bi-arrow-counterclockwise me-1"></i>Volver a ordenar</button>' : ''}
            </div>
            <div class="ex-navegacion">
              <button type="button" class="btn btn-outline-secondary" id="exAnterior" ${actual === 0 ? 'disabled' : ''}><i class="bi bi-arrow-left"></i><span class="d-none d-sm-inline ms-1">Anterior</span></button>
              ${actual < total - 1
                ? '<button type="button" class="btn btn-sgs" id="exSiguiente">Siguiente<i class="bi bi-arrow-right ms-1"></i></button>'
                : '<button type="button" class="btn btn-sgs" id="exTerminar"><i class="bi bi-flag me-1"></i>Terminar examen</button>'}
            </div>
            <div class="ex-mapa" aria-label="Ir a una pregunta">${preguntas.map((q, i) =>
                `<button type="button" class="ex-punto${(respuestas[q.n] || []).length ? ' is-hecha' : ''}${i === actual ? ' is-actual' : ''}" data-ir="${i}" aria-label="Pregunta ${i + 1}">${i + 1}</button>`).join('')}</div>
            <small class="ex-guardado" id="exGuardado"><i class="bi bi-cloud-check me-1" aria-hidden="true"></i>Tus respuestas se guardan solas</small>`;
        tic();
        app.querySelectorAll('.ex-opcion').forEach((b) => b.addEventListener('click', () => responder(p, Number(b.dataset.k))));
        document.getElementById('exReiniciar')?.addEventListener('click', () => { respuestas[p.n] = []; guardar(p.n); pintar(); });
        document.getElementById('exAnterior').addEventListener('click', () => { actual--; pintar(); });
        document.getElementById('exSiguiente')?.addEventListener('click', () => { actual++; pintar(); });
        document.getElementById('exTerminar')?.addEventListener('click', () => terminar(false));
        app.querySelectorAll('[data-ir]').forEach((b) => b.addEventListener('click', () => { actual = Number(b.dataset.ir); pintar(); }));
        app.querySelector('.ex-pregunta')?.scrollIntoView({ block: 'nearest' });
    };

    // ---------- Terminar ----------
    const terminar = async (porTiempo) => {
        if (terminando) return;
        const sinResponder = preguntas.filter((q) => !(respuestas[q.n] || []).length).length;
        if (!porTiempo && !confirm(sinResponder
            ? `Tienes ${sinResponder} ${sinResponder === 1 ? 'pregunta' : 'preguntas'} sin responder. ¿Terminar el examen de todos modos? Ya no podrás cambiar tus respuestas.`
            : '¿Terminar el examen? Ya no podrás cambiar tus respuestas.')) return;
        terminando = true;
        clearInterval(reloj);
        // Primero se envía lo pendiente.
        for (const [n, t] of pendientes) { clearTimeout(t); pendientes.delete(n); const datos = new FormData(); datos.append('n', n); (respuestas[n] || []).forEach((k) => datos.append('r[]', k)); await llamar('responder', datos).catch(() => {}); }
        app.innerHTML = '<p class="text-center text-muted my-4">Calificando…</p>';
        try {
            const r = await llamar('terminar', new FormData());
            if (r.status === 'terminado') resultado(r.resultado, porTiempo);
            else throw new Error(r.message || 'No se pudo terminar.');
        } catch (e) {
            terminando = false;
            app.innerHTML = `<div class="alert alert-danger sgs-alert">${esc(e.message)}</div><button type="button" class="btn btn-sgs w-100" id="exReintentar">Reintentar</button>`;
            document.getElementById('exReintentar').addEventListener('click', () => terminar(true));
        }
    };

    // ---------- Resultado ----------
    const resultado = (r, porTiempo = false) => {
        clearInterval(reloj);
        terminando = true;
        const nota = Math.round(Number(r.nota));
        app.innerHTML = `<div class="sgs-system">Sistema de Gestión de Supervisión</div>
            <div class="ex-resultado ${r.aprobado ? 'is-aprobado' : 'is-desaprobado'}">
              <div class="ex-resultado-icono"><i class="bi ${r.aprobado ? 'bi-patch-check' : 'bi-x-octagon'}" aria-hidden="true"></i></div>
              <div class="ex-resultado-nota">${nota}<small>%</small></div>
              <div class="ex-resultado-estado">${r.aprobado ? 'Aprobado' : 'No aprobado'}</div>
              <p class="ex-resultado-detalle">${Number(r.correctas)} de ${Number(r.total)} respuestas correctas · se aprueba con ${Number(r.minimo)} %</p>
            </div>
            ${porTiempo ? '<p class="small text-center text-muted">Se terminó el tiempo: se calificó lo que respondiste.</p>' : ''}
            ${(r.reforzar || []).length ? `<div class="ex-reforzar"><strong><i class="bi bi-lightbulb me-1" aria-hidden="true"></i>Temas para reforzar</strong><ul>${r.reforzar.map((t) => `<li>${esc(t)}</li>`).join('')}</ul></div>` : '<p class="text-center small mb-2">Respondiste todo bien. ¡Excelente!</p>'}
            <p class="small text-center text-muted mb-0">${esc(d.titulo)}${d.nombre ? ' · ' + esc(d.nombre) : ''}. Tu resultado ya quedó registrado.</p>`;
    };

    // ---------- Inicio ----------
    if (d.vista === 'resultado') resultado(JSON.parse(d.resultado || '{}'));
    else if (d.vista === 'examen') llamar('preguntas').then(iniciar).catch((e) => aviso(e.message));
    else instrucciones();
})();
