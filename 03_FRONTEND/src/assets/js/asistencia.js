// assets/js/asistencia.js - Script de Gestión de Asistencia, Pausas y Monitor Admin en Tiempo Real

document.addEventListener('DOMContentLoaded', () => {
    // -------------------------------------------------------------
    // VARIABLES GLOBAL DE ESTADO
    // -------------------------------------------------------------
    let currentShiftStatus = 'Fuera de turno';
    let shiftStartTime = null;
    let eventStartTime = null;
    let timerInterval = null;
    let adminAutoRefreshInterval = null;
    let userCoords = { lat: null, lng: null };

    // Elementos DOM Supervisor
    const badgeShiftStatus = document.getElementById('badge-shift-status');
    const txtTimerLive = document.getElementById('txt-timer-live');
    const lblTimerType = document.getElementById('lbl-timer-type');
    const txtTimerSub = document.getElementById('txt-timer-sub');
    const txtLocationStatus = document.getElementById('txt-location-status');
    
    const btnStartShift = document.getElementById('btn-start-shift');
    const btnEndShift = document.getElementById('btn-end-shift');
    const btnStartAlmuerzo = document.getElementById('btn-start-almuerzo');
    const btnStartReunion = document.getElementById('btn-start-reunion');
    const btnStartCapacitacion = document.getElementById('btn-start-capacitacion');
    const btnStartRemoto = document.getElementById('btn-start-remoto');
    const btnEndEvent = document.getElementById('btn-end-event');

    const boxActiveEventAlert = document.getElementById('box-active-event-alert');
    const txtActiveEventTitle = document.getElementById('txt-active-event-title');
    const txtActiveEventDetail = document.getElementById('txt-active-event-detail');

    const txtHorasOrdinarias = document.getElementById('txt-horas-ordinarias');
    const txtHorasExtras = document.getElementById('txt-horas-extras');
    const txtTotalAlmuerzo = document.getElementById('txt-total-almuerzo');
    const txtTotalReunion = document.getElementById('txt-total-reunion');
    const txtTotalRemoto = document.getElementById('txt-total-remoto');
    const containerTimelineToday = document.getElementById('container-timeline-today');

    // Elementos DOM Admin
    const filterAdminFecha = document.getElementById('filter-admin-fecha');
    const filterAdminEstado = document.getElementById('filter-admin-estado');
    const filterAdminSearch = document.getElementById('filter-admin-search');
    const btnAdminRefresh = document.getElementById('btn-admin-refresh');
    const btnAdminExport = document.getElementById('btn-admin-export');
    const tbodyAdminAsistencia = document.getElementById('tbody-admin-asistencia');
    const tabVistaAdmin = document.getElementById('tab-vista-admin');

    // Modal Reunión
    const modalReunionEl = document.getElementById('modalReunion');
    const modalReunion = modalReunionEl ? new bootstrap.Modal(modalReunionEl) : null;
    const btnConfirmReunion = document.getElementById('btn-confirm-reunion');
    const txtMotivoReunion = document.getElementById('txt-motivo-reunion');

    // -------------------------------------------------------------
    // GEOLOCALIZACIÓN
    // -------------------------------------------------------------
    if ("geolocation" in navigator) {
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                userCoords.lat = pos.coords.latitude.toFixed(6);
                userCoords.lng = pos.coords.longitude.toFixed(6);
                if (txtLocationStatus) {
                    txtLocationStatus.textContent = `GPS activo (${userCoords.lat}, ${userCoords.lng})`;
                }
            },
            (err) => {
                if (txtLocationStatus) {
                    txtLocationStatus.textContent = 'GPS no disponible';
                }
            },
            { enableHighAccuracy: true, timeout: 10000 }
        );
    }

    // -------------------------------------------------------------
    // 1. CARGAR Y ACTUALIZAR ESTADO DE SUPERVISOR
    // -------------------------------------------------------------
    async function loadSupervisorStatus() {
        try {
            const resp = await fetch('api/asistencia.php?action=status');
            const data = await resp.json();

            if (data.status !== 'success') return;

            currentShiftStatus = data.estado_turno;
            updateBadgeStatus(currentShiftStatus);

            // Horas y Resumen
            if (txtHorasOrdinarias) txtHorasOrdinarias.textContent = Number(data.horas_ordinarias || 0).toFixed(2) + ' h';
            if (txtHorasExtras) txtHorasExtras.textContent = Number(data.horas_extras || 0).toFixed(2) + ' h';
            if (txtTotalAlmuerzo) txtTotalAlmuerzo.textContent = (data.minutos_almuerzo || 0) + ' min';
            if (txtTotalReunion) txtTotalReunion.textContent = (data.minutos_reunion || 0) + ' min';
            if (txtTotalRemoto) txtTotalRemoto.textContent = (data.minutos_remoto || 0) + ' min';

            // Eventos / Timeline
            renderTimelineToday(data.eventos || [], data.asistencia);

            // Manejo de Cronómetro y Alertas según Estado
            if (data.asistencia && !data.asistencia.fin_turno) {
                shiftStartTime = new Date(data.asistencia.inicio_turno.replace(/-/g, '/'));
                
                if (data.evento_activo) {
                    eventStartTime = new Date(data.evento_activo.inicio_evento.replace(/-/g, '/'));
                    showActiveEventBanner(data.evento_activo);
                } else {
                    eventStartTime = null;
                    hideActiveEventBanner();
                }

                const tiposReg = data.tipos_registrados || [];
                startLiveTimer();
                updateButtonStates('EN_TURNO', data.estado_turno, tiposReg);
            } else {
                shiftStartTime = null;
                eventStartTime = null;
                stopLiveTimer();
                hideActiveEventBanner();
                if (txtTimerLive) txtTimerLive.textContent = '00:00:00';
                if (lblTimerType) lblTimerType.textContent = 'Fuera de Turno';
                updateButtonStates('FUERA_DE_TURNO', currentShiftStatus, []);
            }

        } catch (e) {
            console.error('Error al cargar estado de asistencia:', e);
        }
    }

    // -------------------------------------------------------------
    // BADGES Y BOTONES
    // -------------------------------------------------------------
    function updateBadgeStatus(status) {
        const esActivo = ['En turno', 'En almuerzo', 'En reunión', 'En capacitación', 'En remoto'].includes(status);

        if (badgeShiftStatus) {
            badgeShiftStatus.className = 'badge badge-status py-2 px-3 fs-6 ';
            const statusClasses = {
                'En turno': 'badge-en-turno',
                'En almuerzo': 'badge-en-almuerzo',
                'En reunión': 'badge-en-reunion',
                'En capacitación': 'badge-en-capacitacion',
                'En remoto': 'badge-en-remoto',
                'Fuera de turno': 'badge-fuera-turno'
            };
            badgeShiftStatus.classList.add(statusClasses[status] || 'badge-fuera-turno');
            badgeShiftStatus.innerHTML = `<i class="bi bi-circle-fill me-1 ${esActivo ? 'text-light' : 'text-secondary'}"></i> ${escapeHtml(status || 'Fuera de turno')}`;
        }

        // Actualizar también los indicadores de estado en el Menú Lateral
        const sidebarDot = document.getElementById('sidebar-user-status-dot');
        const sidebarBadge = document.getElementById('sidebar-user-status-badge');

        if (sidebarDot) {
            sidebarDot.className = `position-absolute bottom-0 end-0 ${esActivo ? 'bg-success' : 'bg-secondary'} border border-white rounded-circle p-1`;
            sidebarDot.title = esActivo ? 'Activo' : 'Inactivo';
        }
        if (sidebarBadge) {
            sidebarBadge.className = `badge ${esActivo ? 'bg-success text-white' : 'bg-secondary text-white'} text-xs px-2`;
            sidebarBadge.textContent = esActivo ? 'Activo' : 'Inactivo';
        }
    }

    function updateButtonStates(mode, subState, tiposRegistrados = []) {
        if (mode === 'FUERA_DE_TURNO') {
            if (btnStartShift) btnStartShift.disabled = false;
            if (btnEndShift) btnEndShift.disabled = true;
            if (btnStartAlmuerzo) btnStartAlmuerzo.disabled = true;
            if (btnStartReunion) btnStartReunion.disabled = true;
            if (btnStartCapacitacion) btnStartCapacitacion.disabled = true;
            if (btnStartRemoto) btnStartRemoto.disabled = true;
        } else {
            if (btnStartShift) btnStartShift.disabled = true;
            if (btnEndShift) btnEndShift.disabled = false;

            // Las reuniones vuelven a habilitarse después de reanudar el turno.
            const enPausa = ['En almuerzo', 'En reunión', 'En capacitación', 'En remoto'].includes(subState);
            const yaAlmuerzo = tiposRegistrados.includes('almuerzo');
            const yaCapacitacion = tiposRegistrados.includes('capacitacion');

            if (btnStartAlmuerzo) {
                btnStartAlmuerzo.disabled = enPausa || yaAlmuerzo;
                if (yaAlmuerzo) {
                    btnStartAlmuerzo.innerHTML = '<i class="bi bi-check-circle-fill text-success me-1"></i> Almuerzo';
                    btnStartAlmuerzo.title = 'Almuerzo ya registrado hoy';
                } else {
                    btnStartAlmuerzo.innerHTML = '<i class="bi bi-cup-hot me-1"></i> Almuerzo';
                }
            }

            if (btnStartReunion) {
                btnStartReunion.disabled = enPausa;
                btnStartReunion.innerHTML = '<i class="bi bi-people me-1"></i> Reunión';
                btnStartReunion.title = enPausa ? 'Reanuda el turno antes de iniciar otra reunión' : 'Iniciar una nueva reunión';
            }

            // El trabajo remoto puede registrarse varias veces en el turno, como las reuniones
            if (btnStartRemoto) {
                btnStartRemoto.disabled = enPausa;
                btnStartRemoto.title = enPausa ? 'Reanuda el turno antes de iniciar trabajo remoto' : 'Registrar trabajo remoto';
            }

            if (btnStartCapacitacion) {
                btnStartCapacitacion.disabled = enPausa || yaCapacitacion;
                if (yaCapacitacion) {
                    btnStartCapacitacion.innerHTML = '<i class="bi bi-check-circle-fill text-success me-1"></i> Capacitación';
                    btnStartCapacitacion.title = 'Capacitación ya registrada hoy';
                } else {
                    btnStartCapacitacion.innerHTML = '<i class="bi bi-mortarboard me-1"></i> Capacitación';
                }
            }
        }
    }

    function showActiveEventBanner(evento) {
        if (!boxActiveEventAlert) return;
        boxActiveEventAlert.classList.remove('d-none');

        const mapTitle = {
            'almuerzo': '<i class="bi bi-cup-hot-fill me-2"></i> En Almuerzo',
            'reunion': '<i class="bi bi-people-fill me-2"></i> En Reunión (' + escapeHtml(evento.nota || 'Coordinación') + ')',
            'capacitacion': '<i class="bi bi-mortarboard-fill me-2"></i> En Capacitación',
            'campo': '<i class="bi bi-briefcase-fill me-2"></i> En Trabajo de Campo',
            'remoto': '<i class="bi bi-laptop me-2"></i> En trabajo remoto' + (evento.nota ? ' (' + escapeHtml(evento.nota) + ')' : '')
        };

        if (txtActiveEventTitle) txtActiveEventTitle.innerHTML = mapTitle[evento.tipo_evento] || 'En Pausa';
        const horaInicio = new Date(evento.inicio_evento.replace(/-/g, '/')).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        if (txtActiveEventDetail) txtActiveEventDetail.textContent = `Iniciado a las ${horaInicio}. Al finalizar, presiona "Reanudar Turno".`;
    }

    function hideActiveEventBanner() {
        if (boxActiveEventAlert) boxActiveEventAlert.classList.add('d-none');
    }

    // -------------------------------------------------------------
    // CRONÓMETRO EN TIEMPO REAL
    // -------------------------------------------------------------
    function startLiveTimer() {
        stopLiveTimer();
        updateTimerDisplay();
        timerInterval = setInterval(updateTimerDisplay, 1000);
    }

    function stopLiveTimer() {
        if (timerInterval) clearInterval(timerInterval);
    }

    function updateTimerDisplay() {
        if (!txtTimerLive) return;

        const now = new Date();

        if (eventStartTime) {
            // Mostrar tiempo transcurrido en la pausa/evento activo
            if (lblTimerType) lblTimerType.textContent = 'Duración de Pausa Activa';
            const diffMs = Math.max(0, now - eventStartTime);
            txtTimerLive.textContent = formatHMS(diffMs);
        } else if (shiftStartTime) {
            // Mostrar tiempo transcurrido total del turno
            if (lblTimerType) lblTimerType.textContent = 'Tiempo Transcurrido en Turno';
            const diffMs = Math.max(0, now - shiftStartTime);
            txtTimerLive.textContent = formatHMS(diffMs);
        }
    }

    function formatHMS(ms) {
        const totalSeconds = Math.floor(ms / 1000);
        const hours = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;
        return [hours, minutes, seconds].map(v => v < 10 ? '0' + v : v).join(':');
    }

    // -------------------------------------------------------------
    // RENDEREAR TIMELINE DE HOY
    // -------------------------------------------------------------
    function renderTimelineToday(eventos, asistencia) {
        if (!containerTimelineToday) return;

        if (!asistencia) {
            containerTimelineToday.innerHTML = '<p class="text-muted small text-center my-3">No hay inicio de turno registrado hoy.</p>';
            return;
        }

        let html = `
            <div class="timeline-item">
                <strong class="text-dark">Inicio de Turno:</strong> 
                <span class="badge bg-success-subtle text-success border border-success-subtle ms-1">${asistencia.inicio_turno.substring(11, 16)}</span>
            </div>
        `;

        eventos.forEach(ev => {
            const hIni = ev.inicio_evento.substring(11, 16);
            const hFin = ev.fin_evento ? ev.fin_evento.substring(11, 16) : 'En proceso...';
            const dur = ev.duracion_minutos ? `(${ev.duracion_minutos} min)` : '';
            const nota = ev.nota ? ` - <em>${escapeHtml(ev.nota)}</em>` : '';

            let badgeClass = 'bg-secondary';
            let label = ev.tipo_evento.toUpperCase();

            if (ev.tipo_evento === 'almuerzo') { badgeClass = 'bg-warning text-dark'; label = 'ALMUERZO'; }
            else if (ev.tipo_evento === 'reunion') { badgeClass = 'bg-info text-dark'; label = 'REUNIÓN'; }
            else if (ev.tipo_evento === 'capacitacion') { badgeClass = 'bg-primary text-white'; label = 'CAPACITACIÓN'; }
            else if (ev.tipo_evento === 'remoto') { badgeClass = 'badge-en-remoto'; label = 'TRABAJO REMOTO'; }

            html += `
                <div class="timeline-item">
                    <span class="badge ${badgeClass}">${label}</span>
                    <span class="small fw-semibold text-dark ms-1">${hIni} - ${hFin}</span> ${dur} ${nota}
                </div>
            `;
        });

        if (asistencia.fin_turno) {
            html += `
                <div class="timeline-item">
                    <strong class="text-danger">Fin de Turno:</strong> 
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle ms-1">${asistencia.fin_turno.substring(11, 16)}</span>
                </div>
            `;
        }

        containerTimelineToday.innerHTML = html;
    }

    // -------------------------------------------------------------
    // ACCIONES DE BOTONES SUPERVISOR
    // -------------------------------------------------------------
    if (btnStartShift) {
        btnStartShift.addEventListener('click', async () => {
            if (!confirm('¿Deseas registrar el INICIO DE TU TURNO para el día de hoy?')) return;
            
            btnStartShift.disabled = true;
            try {
                const resp = await fetch('api/asistencia.php?action=start', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        latitud: userCoords.lat,
                        longitud: userCoords.lng
                    })
                });
                const res = await resp.json();
                alert(res.message);
                loadSupervisorStatus();
            } catch (e) {
                alert('Error al conectar con el servidor.');
                btnStartShift.disabled = false;
            }
        });
    }

    if (btnEndShift) {
        btnEndShift.addEventListener('click', async () => {
            if (!confirm('¿Estás seguro de que deseas FINALIZAR TU TURNO de hoy?')) return;

            btnEndShift.disabled = true;
            try {
                const resp = await fetch('api/asistencia.php?action=end', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        latitud: userCoords.lat,
                        longitud: userCoords.lng
                    })
                });
                const res = await resp.json();
                alert(res.message);
                loadSupervisorStatus();
            } catch (e) {
                alert('Error al finalizar turno.');
                btnEndShift.disabled = false;
            }
        });
    }

    if (btnStartAlmuerzo) {
        btnStartAlmuerzo.addEventListener('click', async () => {
            if (!confirm('¿Iniciar pausa de ALMUERZO?')) return;

            try {
                const resp = await fetch('api/asistencia.php?action=start_event', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        tipo_evento: 'almuerzo',
                        latitud: userCoords.lat,
                        longitud: userCoords.lng
                    })
                });
                const res = await resp.json();
                alert(res.message);
                loadSupervisorStatus();
            } catch (e) {
                alert('Error al registrar almuerzo.');
            }
        });
    }

    if (btnStartReunion) {
        btnStartReunion.addEventListener('click', () => {
            if (txtMotivoReunion) txtMotivoReunion.value = '';
            if (modalReunion) modalReunion.show();
        });
    }

    if (btnConfirmReunion) {
        btnConfirmReunion.addEventListener('click', async () => {
            const nota = txtMotivoReunion ? txtMotivoReunion.value.trim() : '';
            if (modalReunion) modalReunion.hide();

            try {
                const resp = await fetch('api/asistencia.php?action=start_event', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        tipo_evento: 'reunion',
                        nota: nota || 'Reunión de Coordinación',
                        latitud: userCoords.lat,
                        longitud: userCoords.lng
                    })
                });
                const res = await resp.json();
                alert(res.message);
                loadSupervisorStatus();
            } catch (e) {
                alert('Error al registrar reunión.');
            }
        });
    }

    if (btnStartCapacitacion) {
        btnStartCapacitacion.addEventListener('click', async () => {
            if (!confirm('¿Iniciar estado de CAPACITACIÓN?')) return;

            try {
                const resp = await fetch('api/asistencia.php?action=start_event', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        tipo_evento: 'capacitacion',
                        latitud: userCoords.lat,
                        longitud: userCoords.lng
                    })
                });
                const res = await resp.json();
                alert(res.message);
                loadSupervisorStatus();
            } catch (e) {
                alert('Error al registrar capacitación.');
            }
        });
    }

    if (btnStartRemoto) {
        // Trabajo remoto: ventana con una nota opcional (como el motivo de la reunión)
        const modalRemotoEl = document.getElementById('modalRemoto');
        const modalRemoto = modalRemotoEl ? new bootstrap.Modal(modalRemotoEl) : null;
        const txtNotaRemoto = document.getElementById('txt-nota-remoto');
        btnStartRemoto.addEventListener('click', () => {
            if (txtNotaRemoto) txtNotaRemoto.value = '';
            if (modalRemoto) modalRemoto.show();
        });
        document.getElementById('btn-confirm-remoto')?.addEventListener('click', async () => {
            const nota = txtNotaRemoto ? txtNotaRemoto.value : '';
            if (modalRemoto) modalRemoto.hide();

            try {
                const resp = await fetch('api/asistencia.php?action=start_event', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        tipo_evento: 'remoto',
                        nota: nota.trim(),
                        latitud: userCoords.lat,
                        longitud: userCoords.lng
                    })
                });
                const res = await resp.json();
                alert(res.message);
                loadSupervisorStatus();
            } catch (e) {
                alert('Error al registrar trabajo remoto.');
            }
        });
    }

    if (btnEndEvent) {
        btnEndEvent.addEventListener('click', async () => {
            try {
                const resp = await fetch('api/asistencia.php?action=end_event', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' }
                });
                const res = await resp.json();
                alert(res.message);
                loadSupervisorStatus();
            } catch (e) {
                alert('Error al reanudar turno.');
            }
        });
    }

    // -------------------------------------------------------------
    // 2. VISTA ADMINISTRADOR (MONITOR EN TIEMPO REAL)
    // -------------------------------------------------------------
    async function loadAdminSummary() {
        if (!tbodyAdminAsistencia) return;

        const fecha = filterAdminFecha ? filterAdminFecha.value : '';
        const estado = filterAdminEstado ? filterAdminEstado.value : '';
        const search = filterAdminSearch ? filterAdminSearch.value : '';

        const url = `api/asistencia.php?action=admin_summary&fecha=${encodeURIComponent(fecha)}&estado=${encodeURIComponent(estado)}&search=${encodeURIComponent(search)}`;

        try {
            const resp = await fetch(url);
            const data = await resp.json();

            if (data.status !== 'success') return;

            // Actualizar KPIs
            const k = data.kpis || {};
            document.getElementById('kpi-admin-total').textContent = k.total_personal || 0;
            document.getElementById('kpi-admin-turno').textContent = k.en_turno || 0;
            document.getElementById('kpi-admin-almuerzo').textContent = k.en_almuerzo || 0;
            document.getElementById('kpi-admin-reunion').textContent = k.en_reunion || 0;
            document.getElementById('kpi-admin-capacitacion').textContent = k.en_capacitacion || 0;
            const kpiRemoto = document.getElementById('kpi-admin-remoto');
            if (kpiRemoto) kpiRemoto.textContent = k.en_remoto || 0;
            document.getElementById('kpi-admin-finalizado').textContent = k.turno_finalizado || 0;

            // Renderear Tabla
            renderAdminTable(data.reporte || []);

        } catch (e) {
            console.error('Error al cargar reporte de administración:', e);
        }
    }

    function renderAdminTable(reporte) {
        if (!tbodyAdminAsistencia) return;

        if (reporte.length === 0) {
            tbodyAdminAsistencia.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-muted">No se encontraron registros de asistencia para los criterios seleccionados.</td></tr>';
            return;
        }

        let html = '';
        reporte.forEach(item => {
            const sup = item.supervisor;
            const asist = item.asistencia;
            const estado = item.estado_actual;

            let badgeClass = 'bg-secondary text-white';
            if (estado === 'En turno') badgeClass = 'bg-success text-white';
            else if (estado === 'En almuerzo') badgeClass = 'bg-warning text-dark';
            else if (estado === 'En reunión') badgeClass = 'bg-info text-dark';
            else if (estado === 'En capacitación') badgeClass = 'bg-primary text-white';
            else if (estado === 'En remoto') badgeClass = 'badge-en-remoto';
            else if (estado === 'Fuera de turno') badgeClass = 'bg-dark text-white';

            const iniStr = asist ? asist.inicio_turno.substring(11, 16) : '--:--';
            // Primera acción del turno (supervisión, reunión, capacitación o remoto) y cuánto tardó desde el inicio
            const pa = item.primera_accion;
            const primeraAccion = !asist ? '' : (pa
                ? `<small class="d-block text-muted fw-normal">1.ª acción: ${escapeHtml(pa.tipo)} ${pa.hora} · ${pa.minutos} min</small>`
                : '<small class="d-block text-muted fw-normal">Sin acciones aún</small>');
            const finStr = (asist && asist.fin_turno) ? asist.fin_turno.substring(11, 16) : '--:--';
            const hTrab = asist ? (asist.horas_trabajadas || 0) + 'h' : '0.00h';
            const minAlm = (item.minutos_almuerzo || 0) + ' min';
            const hExt = asist ? (asist.horas_extras || 0) + 'h' : '0.00h';

            const initial = nameInitials(sup.nombre);

            html += `
                <tr>
                    <td class="ps-3">
                        <div class="d-flex align-items-center gap-2">
                            <div class="rounded-circle text-white d-flex align-items-center justify-content-center fw-bold shadow-sm" style="width:36px; height:36px; background-color:#FF5A0A; font-size:0.9rem;">
                                ${initial}
                            </div>
                            <div>
                                <strong class="d-block text-dark">${escapeHtml(sup.nombre)}</strong>
                                <small class="text-muted">${escapeHtml(sup.dni)} | ${escapeHtml(sup.rol)}</small>
                            </div>
                        </div>
                    </td>
                    <td><span class="badge bg-light text-dark border">${escapeHtml(sup.partner)}</span></td>
                    <td><span class="badge ${badgeClass} px-2 py-1.5 fw-bold">${escapeHtml(estado)}</span></td>
                    <td class="fw-semibold">${iniStr}${primeraAccion}</td>
                    <td class="fw-semibold">${finStr}</td>
                    <td><span class="fw-bold text-dark">${hTrab}</span></td>
                    <td><span class="text-muted">${minAlm}</span></td>
                    <td><span class="fw-bold text-warning-emphasis">${hExt}</span></td>
                    <td class="text-end pe-3">
                        <button type="button" class="btn btn-outline-dark btn-sm btn-detail-sup" data-sup-id="${sup.id}">
                            <i class="bi bi-eye-fill"></i> Detalle
                        </button>
                    </td>
                </tr>
            `;
        });

        tbodyAdminAsistencia.innerHTML = html;

        // Listener para Ver Detalle
        document.querySelectorAll('.btn-detail-sup').forEach(btn => {
            btn.addEventListener('click', () => {
                const supId = btn.getAttribute('data-sup-id');
                const found = reporte.find(r => r.supervisor.id == supId);
                if (found) showSupervisorDetailModal(found);
            });
        });
    }

    function showSupervisorDetailModal(dataItem) {
        const modalEl = document.getElementById('modalDetailSupervisor');
        if (!modalEl) return;
        const modal = new bootstrap.Modal(modalEl);

        const sup = dataItem.supervisor;
        const asist = dataItem.asistencia;
        const evs = dataItem.eventos || [];

        document.getElementById('modalDetailTitle').innerHTML = `<i class="bi bi-person-badge-fill me-2 text-warning"></i> Asistencia: ${escapeHtml(sup.nombre)}`;

        let bodyHtml = `
            <div class="mb-3 p-3 bg-light rounded border">
                <h6 class="fw-bold text-dark mb-1">${escapeHtml(sup.nombre)} (${escapeHtml(sup.rol)})</h6>
                <small class="text-muted d-block">Partner: <strong>${escapeHtml(sup.partner)}</strong> | DNI: ${escapeHtml(sup.dni)}</small>
            </div>
        `;

        if (!asist) {
            bodyHtml += '<div class="alert alert-secondary text-center">El supervisor no ha registrado inicio de turno en esta fecha.</div>';
        } else {
            bodyHtml += `
                <div class="row g-2 mb-3 text-center">
                    <div class="col-6">
                        <div class="p-2 bg-success-subtle text-success rounded border border-success-subtle">
                            <small class="d-block">Inicio Turno</small>
                            <strong>${asist.inicio_turno}</strong>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-2 bg-danger-subtle text-danger rounded border border-danger-subtle">
                            <small class="d-block">Fin Turno</small>
                            <strong>${asist.fin_turno || 'En curso...'}</strong>
                        </div>
                    </div>
                </div>

                <h6 class="fw-bold text-dark mb-2">Línea de Tiempo de Pausas & Eventos:</h6>
                <div class="timeline-today mb-3">
            `;

            if (evs.length === 0) {
                bodyHtml += '<p class="text-muted small my-2">No se registraron pausas ni reuniones.</p>';
            } else {
                evs.forEach(e => {
                    bodyHtml += `
                        <div class="timeline-item">
                            <strong class="text-dark text-capitalize">${e.tipo_evento}</strong>: 
                            <span class="small">${e.inicio_evento.substring(11, 16)} a ${e.fin_evento ? e.fin_evento.substring(11, 16) : 'En curso'}</span> 
                            (${e.duracion_minutos || 0} min) ${e.nota ? ' - <em>' + escapeHtml(e.nota) + '</em>' : ''}
                        </div>
                    `;
                });
            }

            bodyHtml += '</div>';

            if (asist.latitud_inicio && asist.longitud_inicio) {
                bodyHtml += `
                    <div class="alert alert-light border small mb-0">
                        <i class="bi bi-geo-alt-fill text-danger me-1"></i>
                        <strong>Ubicación GPS Inicio:</strong> ${asist.latitud_inicio}, ${asist.longitud_inicio}
                    </div>
                `;
            }
        }

        document.getElementById('modalDetailBody').innerHTML = bodyHtml;
        modal.show();
    }

    // Exportar a CSV
    if (btnAdminExport) {
        btnAdminExport.addEventListener('click', () => {
            const fecha = filterAdminFecha ? filterAdminFecha.value : '';
            window.open(`api/asistencia.php?action=admin_summary&fecha=${fecha}&export=csv`, '_blank');
        });
    }

    // Listeners Filtros Admin
    if (btnAdminRefresh) btnAdminRefresh.addEventListener('click', loadAdminSummary);
    if (filterAdminFecha) filterAdminFecha.addEventListener('change', loadAdminSummary);
    if (filterAdminEstado) filterAdminEstado.addEventListener('change', loadAdminSummary);
    if (filterAdminSearch) {
        let timerSearch;
        filterAdminSearch.addEventListener('input', () => {
            clearTimeout(timerSearch);
            timerSearch = setTimeout(loadAdminSummary, 300);
        });
    }

    if (tabVistaAdmin) {
        tabVistaAdmin.addEventListener('shown.bs.tab', () => {
            loadAdminSummary();
            if (!adminAutoRefreshInterval) {
                adminAutoRefreshInterval = setInterval(loadAdminSummary, 30000); // Auto-refresh cada 30s
            }
        });
    }

    function nameInitials(name) {
        const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
        return ((parts[0]?.[0] || 'S') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toLocaleUpperCase('es');
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // -------------------------------------------------------------
    // INICIALIZACIÓN
    // -------------------------------------------------------------
    loadSupervisorStatus();
    if (document.getElementById('content-vista-admin') && document.getElementById('content-vista-admin').classList.contains('active')) {
        loadAdminSummary();
    }
});
