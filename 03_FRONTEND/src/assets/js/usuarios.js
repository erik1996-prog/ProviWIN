// 03_FRONTEND/src/assets/js/usuarios.js - Usuarios y roles / Personal (pages/usuarios.php). Datos del servidor en window.SGS_USUARIOS.
// 2026-10-27: salió de la página a este archivo (sistema liviano: el navegador lo guarda).
// Espera a que carguen Bootstrap y main.js (se incluyen al final de la página).
document.addEventListener('DOMContentLoaded', () => {
  const D = window.SGS_USUARIOS;
  const MI_ID = D.miId;
  const ES_ADMIN = D.esAdmin, GESTIONA = D.gestiona;
  const ES_TOTAL = D.esTotal; // false = líder con su equipo: sin contraseñas ni desbloqueos
  const ETQ = D.etiquetasRol, etqRol = (r) => ETQ[r] || r; // nombre que se muestra
  const PUEDE_ACTIVAR = D.puedeActivar; // solo la cuenta principal activa (2026-10-26)
  const $ = (s, el = document) => el.querySelector(s);
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const toast = (m, t = 'success') => (window.showToast ? window.showToast(m, t) : alert(m));
  let CAT = null;
  const mUsuario = new bootstrap.Modal('#mUsuario'), mPersonal = new bootstrap.Modal('#mPersonal'), mPassword = new bootstrap.Modal('#mPassword');

  async function api(action, { method = 'GET', params = {}, body = null } = {}) {
    const url = 'api/usuarios.php?' + new URLSearchParams({ action, ...params });
    const res = await fetch(url, { method, headers: body ? { 'Content-Type': 'application/json' } : {}, body: body ? JSON.stringify(body) : null, cache: 'no-store' });
    const data = await res.json().catch(() => ({ status: 'error', message: 'Respuesta no válida del servidor.' }));
    if (data.status !== 'success') { const e = new Error(data.message || 'Error'); e.field = data.field; throw e; }
    return data;
  }

  // ---------- Pestañas ----------
  document.querySelectorAll('.usr-tabs .btn').forEach((b) => b.addEventListener('click', () => {
    document.querySelectorAll('.usr-tabs .btn').forEach((x) => x.classList.toggle('active', x === b));
    const roles = b.dataset.tab === 'roles';
    $('#tab-usuarios').classList.toggle('d-none', roles); $('#tab-roles').classList.toggle('d-none', !roles);
    $('#btnNuevoUsuario').classList.toggle('d-none', roles);
  }));

  // ---------- Catálogos ----------
  // Permisos por pantalla (2026-10-26): una fila por pantalla, en el orden del menú; editar, eliminar y opciones
  // dependen de "Ver" (data-requiere): sin Ver se desmarcan y se bloquean (el servidor aplica la misma regla).
  const SECCIONES = ['Indicadores', 'Supervisiones', 'Seguimiento', 'Observaciones', 'Personal', 'Otros'];
  const pantallas = () => {
    const p = {};
    CAT.permisos.forEach((x) => (p[x.grupo] = p[x.grupo] || { seccion: x.seccion, items: [] }).items.push(x));
    return SECCIONES.map((s) => [s, Object.entries(p).filter(([, v]) => v.seccion === s)]).filter(([, l]) => l.length);
  };
  const etiquetaCorta = (p) => {
    if (p.accion === 'ver') return 'Ver';
    const t = p.etiqueta.replace(/^(Ver|Editar|Eliminar|Global):\s*/i, '');
    return t.charAt(0).toLocaleUpperCase('es') + t.slice(1);
  };
  function permisosHtml(prefix) {
    const COLS = [['ver', 'Ver'], ['editar', 'Editar'], ['eliminar', 'Eliminar'], [null, 'Opciones']];
    const casilla = (p) => `<div class="form-check"><input class="form-check-input" type="checkbox" name="permisos[]" value="${esc(p.clave)}" id="${prefix}_${esc(p.clave)}"${p.requiere ? ` data-requiere="${esc(p.requiere)}"` : ''}><label class="form-check-label" for="${prefix}_${esc(p.clave)}">${esc(etiquetaCorta(p))}</label></div>`;
    const filas = pantallas().map(([sec, lista]) => `<tr class="usr-perm-seccion"><td colspan="5">${esc(sec)}</td></tr>` + lista.map(([g, v]) => `<tr><td data-label="Pantalla" class="fw-bold">${esc(g)}</td>`
      + COLS.map(([a, t]) => { const ps = v.items.filter((p) => (p.accion || null) === a); return `<td data-label="${t}">${ps.length ? ps.map(casilla).join('') : '<span class="text-muted">—</span>'}</td>`; }).join('') + '</tr>').join('')).join('');
    return `<div class="table-responsive"><table class="table table-sm mb-0 sgs-tabla-tarjetas usr-perm-tabla"><thead><tr><th>Pantalla</th>${COLS.map(([, t]) => `<th>${t}</th>`).join('')}</tr></thead><tbody>${filas}</tbody></table></div>`;
  }
  function aplicarDependencias(cont) {
    const personal = $('#chkPersonalizar').checked;
    cont.querySelectorAll('input[name="permisos[]"]').forEach((c) => {
      const ver = c.dataset.requiere ? cont.querySelector(`input[value="${c.dataset.requiere}"]`) : null;
      const puede = !c.dataset.requiere || !!ver?.checked;
      if (!puede) c.checked = false;
      c.disabled = !personal || !puede;
      c.closest('.form-check')?.classList.toggle('is-bloqueado', !puede);
    });
  }
  const rolPermisos = (rol) => (CAT.roles.find((r) => r.rol === rol) || { permisos: [] }).permisos;
  const setChecks = (container, list) => container.querySelectorAll('input[name="permisos[]"]').forEach((c) => (c.checked = list.includes(c.value)));

  async function cargarCatalogos() {
    CAT = (await api('catalogos')).data;
    const rolOpts = CAT.roles.map((r) => `<option value="${esc(r.rol)}">${esc(etqRol(r.rol))}</option>`).join('');
    $('#fRol').innerHTML = '<option value="">Todos los roles</option>' + rolOpts;
    $('#wrapTodasZonas').classList.toggle('d-none', !!CAT.equipo); // el líder solo asigna sus zonas
    $('#fOrg').innerHTML = '<option value="">Todas las organizaciones</option><option value="directo">WIN Directo</option>' + CAT.partners.map((p) => `<option value="${p.id}">${esc(p.nombre_partner)}</option>`).join('');
    const f = $('#formUsuario');
    f.rol.innerHTML = rolOpts;
    f.partner_id.innerHTML = '<option value="0">Seleccione…</option>' + CAT.partners.map((p) => `<option value="${p.id}">${esc(p.nombre_partner)}</option>`).join('');
    llenarLista(f.area_trabajo, CAT.areas); llenarLista(f.cargo, CAT.cargos);
    $('#procesos').innerHTML = ['Todos', ...CAT.procesos].map((p, i) => `<input type="checkbox" class="btn-check" name="procesos[]" value="${esc(p)}" id="proc${i}"><label class="btn btn-outline-secondary" for="proc${i}">${esc(p)}</label>`).join('');
    $('#zonas').innerHTML = CAT.departamentos.map((d, i) => `<input type="checkbox" class="btn-check" name="zonas[]" value="${esc(d)}" id="z${i}"><label class="btn btn-outline-secondary" for="z${i}">${esc(d)}</label>`).join('');
    $('#permisos').innerHTML = permisosHtml('u');
    renderRoles();
  }

  // Listas fijas (área, cargo). Un valor guardado que no está en la lista (registros anteriores) se muestra igual.
  function llenarLista(select, opciones, valor = '') {
    if (!select) return;
    const extra = valor && !opciones.includes(valor) ? [valor] : [];
    select.innerHTML = '<option value="">—</option>' + [...extra, ...opciones].map((o) => `<option value="${esc(o)}">${esc(o)}${extra.includes(o) ? ' (anterior)' : ''}</option>`).join('');
    select.value = valor || '';
  }
  // "Todos" marca todos los procesos; si se desmarca uno, "Todos" se desmarca.
  document.getElementById('procesos')?.addEventListener('change', (ev) => {
    const caja = ev.target; if (!caja.matches('input[name="procesos[]"]')) return;
    const todas = [...document.querySelectorAll('#procesos input')];
    if (caja.value === 'Todos') todas.forEach((c) => (c.checked = caja.checked));
    else todas[0].checked = todas.slice(1).every((c) => c.checked);
  });

  // ---------- Usuarios ----------
  let estadoAcceso = '';
  // "2026-10-15 08:05:00" -> "15/10/2026 08:05"
  const fechaHora = (v) => { const m = String(v).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/); return m ? `${m[3]}/${m[2]}/${m[1]} ${m[4]}:${m[5]}` : String(v); };
  const iniciales = (n) => String(n || '').trim().split(/\s+/).filter(Boolean).map((x) => x[0]).slice(0, 2).join('').toUpperCase() || 'U';
  async function cargarUsuarios() {
    // Administración: estado de acceso elegido en los contadores; el resto ve solo al personal activo.
    const params = { q: $('#fQ').value.trim(), rol: $('#fRol')?.value || '', organizacion: $('#fOrg')?.value || '', estado: ES_ADMIN ? estadoAcceso : 'activos' };
    const body = $('#usrBody');
    try {
      const data = await api('listar', { params });
      const r = data.resumen || {};
      if (ES_ADMIN) {
        $('#kActivos').textContent = r.activos ?? 0; $('#kDirectos').textContent = r.directos ?? 0;
        $('#kPartners').textContent = r.partners ?? 0; $('#kAdmins').textContent = r.administradores ?? 0;
        document.querySelectorAll('#usrEstados [data-cuenta]').forEach((el) => { el.textContent = (data.conteos || {})[el.dataset.cuenta] ?? 0; });
      }
      const rows = data.usuarios || [];
      if ($('#usrContador')) $('#usrContador').textContent = `${rows.length} persona${rows.length === 1 ? '' : 's'}`;
      body.innerHTML = rows.length ? rows.map((u) => {
        const directo = !u.nombre_partner || ['WIN', 'WIN DIRECTO'].includes(String(u.nombre_partner).trim().toUpperCase());
        const activo = +u.activo === 1, yo = +u.id === MI_ID;
        return `<tr class="${activo ? '' : 'usr-row-off'}">
          <td><div class="d-flex align-items-center gap-2"><span class="usr-avatar">${esc(iniciales(u.nombre_completo))}</span><div><div class="usr-name">${esc(u.nombre_completo)}${yo ? ' <span class="badge bg-light">Tú</span>' : ''}</div><div class="usr-sub">${esc(u.usuario || '—')} · DNI ${esc(u.dni)}</div>${u.cargo || u.area_trabajo ? `<div class="usr-sub">${esc([u.cargo, u.area_trabajo].filter(Boolean).join(' · '))}</div>` : ''}</div></div></td>
          <td data-label="Rol y organización"><div class="usr-celda-doble"><span class="badge ${u.rol === 'Supervisor' ? 'bg-primary' : 'bg-dark'}">${esc(etqRol(u.rol))}</span><small class="usr-org">${directo ? 'WIN Directo' : esc(u.nombre_partner)}</small></div></td>
          <td data-label="Zonas">${u.todas_zonas ? 'Todas' : (u.zonas && u.zonas.length ? esc(u.zonas.join(', ')) : '—')}</td>
          ${!ES_ADMIN ? '' : `<td data-label="Estado"><div class="usr-celda-doble"><div class="usr-insignias">${u.sin_acceso ? '<span class="badge bg-warning" title="Registrado solo con datos personales: falta que el administrador le dé acceso">Sin acceso</span>' : (+u.por_activar === 1 && !activo ? '<span class="badge bg-warning" title="Espera que la cuenta principal lo active">Por activar</span>' : `<span class="badge ${activo ? 'bg-success' : 'bg-secondary'}">${activo ? 'Activo' : 'Inactivo'}</span>`)}${u.bloqueado ? ' <span class="badge bg-danger" title="3 intentos fallidos"><i class="bi bi-lock"></i> Bloqueado</span>' : ''}${u.debe_cambiar_password && !u.sin_acceso ? ' <span class="badge bg-warning" title="Al ingresar, el sistema le pedirá crear su propia contraseña">Cambiará su contraseña</span>' : ''}</div>
            <small class="usr-ingreso" title="Último ingreso">${u.ultimo_acceso ? '<i class="bi bi-box-arrow-in-right"></i> ' + esc(fechaHora(u.ultimo_acceso)) : 'Nunca ingresó'}</small></div></td>`}
          <td class="text-end"><div class="sgs-row-actions">${u.protegido && !yo ? '<span class="badge bg-secondary" title="Solo su titular puede verla o modificarla"><i class="bi bi-shield-lock me-1"></i>Cuenta protegida</span></div></td></tr>' : !ES_ADMIN ? (GESTIONA && u.rol !== 'Administrador'
            ? `<button class="sgs-icon-action" title="Editar datos personales" aria-label="Editar datos personales" data-personal="${u.id}"><i class="bi bi-pencil"></i></button>`
            : `<button class="sgs-icon-action" title="Ver ficha" aria-label="Ver ficha" data-ficha="${u.id}"><i class="bi bi-eye"></i></button>`) + '</div></td></tr>' : `
            <button class="sgs-icon-action" title="${u.sin_acceso ? 'Completar acceso' : 'Editar'}" aria-label="${u.sin_acceso ? 'Completar acceso' : 'Editar'}" data-edit="${u.id}"><i class="bi ${u.sin_acceso ? 'bi-person-gear' : 'bi-pencil'}"></i></button>
            ${u.bloqueado && ES_TOTAL ? `<button class="sgs-icon-action" title="Desbloquear" aria-label="Desbloquear usuario" data-unlock="${u.id}" data-name="${esc(u.nombre_completo)}"><i class="bi bi-unlock"></i></button>` : ''}
            ${ES_TOTAL ? `<button class="sgs-icon-action" title="Restablecer contraseña" aria-label="Restablecer contraseña" data-pw="${u.id}" data-name="${esc(u.nombre_completo)}"><i class="bi bi-key"></i></button>` : ''}
            ${yo || u.sin_acceso || (!activo && !PUEDE_ACTIVAR) ? '' : `<button class="sgs-icon-action ${activo ? 'sgs-icon-action--danger' : ''}" title="${activo ? 'Desactivar' : 'Activar'}" aria-label="${activo ? 'Desactivar' : 'Activar'}" data-state="${u.id}" data-to="${activo ? 0 : 1}" data-name="${esc(u.nombre_completo)}"><i class="bi ${activo ? 'bi-person-dash' : 'bi-person-check'}"></i></button>`}
          </div></td></tr>`}`;
      }).join('') : `<tr><td colspan="${ES_ADMIN ? 5 : 4}" class="text-center text-muted py-4"><i class="bi bi-inbox d-block fs-3 mb-1"></i>No hay personas con esos filtros.</td></tr>`;
    } catch (e) { body.innerHTML = `<tr><td colspan="${ES_ADMIN ? 5 : 4}" class="text-center text-danger py-4">${esc(e.message)}</td></tr>`; }
  }
  let tQ; $('#fQ').addEventListener('input', () => { clearTimeout(tQ); tQ = setTimeout(cargarUsuarios, 300); });
  ['#fRol', '#fOrg'].forEach((s) => $(s)?.addEventListener('change', cargarUsuarios));
  const marcarEstado = (valor) => {
    estadoAcceso = valor;
    document.querySelectorAll('#usrEstados [data-estado]').forEach((b) => b.classList.toggle('active', b.dataset.estado === valor));
  };
  $('#usrEstados')?.addEventListener('click', (ev) => { const b = ev.target.closest('[data-estado]'); if (b) { marcarEstado(b.dataset.estado); cargarUsuarios(); } });
  $('#fLimpiar').addEventListener('click', () => { $('#fQ').value = ''; if ($('#fRol')) $('#fRol').value = ''; if ($('#fOrg')) $('#fOrg').value = ''; marcarEstado(''); cargarUsuarios(); });

  // Formulario: reglas visibles
  const fU = $('#formUsuario');

  function syncForm() {
    // Líder (2026-10-27): Jefe de operaciones → rol Partner de un partner; Asistente, Asesor, Almacenero y Analista, sin acceso
    const sinAcceso = !!CAT.equipo && (CAT.cargos_sin_acceso || []).includes(fU.cargo.value);
    if (CAT.equipo && fU.cargo.value === 'Jefe de operaciones') { fU.rol.value = 'Jefe de operaciones'; fU.organizacion.value = 'partner'; }
    else if (CAT.equipo && fU.rol.value === 'Jefe de operaciones') fU.rol.value = 'Supervisor';
    if (sinAcceso) fU.rol.value = 'Supervisor';
    $('#wrapAcceso').classList.toggle('d-none', sinAcceso); $('#sinAccesoAviso').classList.toggle('d-none', !sinAcceso);
    $('#wrapPartner').classList.toggle('d-none', fU.organizacion.value !== 'partner');
    // Llevan zonas el supervisor y el coordinador (líder: sin zonas ve todo); el jefe de operaciones ve todo su partner
    // (el que registra un líder lleva su zona: así queda en su equipo)
    const rol = fU.rol.value, conZonas = ['Supervisor', 'Coordinador'].includes(rol) || (!!CAT.equipo && rol === 'Jefe de operaciones');
    $('#wrapZonas').classList.toggle('d-none', !conZonas);
    $('#zonasLider').classList.toggle('d-none', rol !== 'Coordinador');
    const todas = $('#chkTodasZonas').checked;
    $('#zonas').querySelectorAll('input').forEach((c) => (c.disabled = todas));
    const personal = $('#chkPersonalizar').checked;
    if (!personal) setChecks($('#permisos'), rolPermisos(rol));
    aplicarDependencias($('#permisos'));
    $('#permHint').textContent = personal ? 'Permisos personalizados solo para este usuario.' : 'Usa los permisos por defecto del rol. Activa "Personalizar" para ajustarlos.';
  }
  ['rol', 'organizacion', 'cargo'].forEach((n) => fU[n].addEventListener('change', syncForm));

  $('#chkPersonalizar').addEventListener('change', syncForm);
  $('#permisos').addEventListener('change', (e) => { if (e.target.name === 'permisos[]') aplicarDependencias($('#permisos')); });
  $('#chkTodasZonas').addEventListener('change', syncForm);
  let zonaBase = '';
  $('#btnGenerarPw').addEventListener('click', () => {
    const a = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz', n = '23456789', s = '#$%&*?';
    const pick = (src, k) => Array.from(crypto.getRandomValues(new Uint32Array(k)), (x) => src[x % src.length]).join('');
    fU.password.value = pick(a, 9) + pick(n, 3) + pick(s, 1);
    fU.password.dispatchEvent(new Event('input'));
  });

  function abrirUsuario(u = null) {
    fU.reset(); zonaBase = ''; $('#uError').classList.add('d-none'); fU.password.dispatchEvent(new Event('input'));
    fU.querySelectorAll('.is-invalid').forEach((x) => x.classList.remove('is-invalid'));
    fU.id.value = u ? u.id : 0;
    const completar = !!u && !u.usuario;   // personal registrado sin acceso: falta usuario y contraseña
    $('#mUsuarioTitulo').textContent = completar ? `Completar acceso · ${u.nombres} ${u.apellidos}` : (u ? `Editar · ${u.nombres} ${u.apellidos}` : 'Nuevo usuario');
    $('#lblPassword').textContent = u && !completar ? 'Nueva contraseña (opcional)' : 'Contraseña *';
    $('#hintPassword').textContent = completar ? 'Al guardar con contraseña, la persona queda activa y ya puede ingresar.' : (u ? 'Déjala vacía para mantener la actual.' : 'Compártela con el usuario por un medio seguro.');
    if (u) {
      ['dni', 'nombres', 'apellidos', 'email', 'telefono_personal', 'telefono_corporativo', 'usuario', 'area_trabajo', 'modalidad', 'turno'].forEach((k) => { if (fU[k]) fU[k].value = u[k] ?? ''; });
      fU.rol.value = u.rol; fU.organizacion.value = u.organizacion; fU.partner_id.value = u.partner_id || 0;
      llenarLista(fU.area_trabajo, CAT.areas, u.area_trabajo || ''); llenarLista(fU.cargo, CAT.cargos, u.cargo || '');
      const procs = u.procesos || [];
      document.querySelectorAll('#procesos input').forEach((c) => { c.checked = c.value === 'Todos' ? CAT.procesos.every((p) => procs.includes(p)) : procs.includes(c.value); });
      fU.querySelectorAll('input[name="zonas[]"]').forEach((c) => (c.checked = (u.zonas || []).includes(c.value)));
      $('#chkTodasZonas').checked = !!u.todas_zonas; zonaBase = (u.zonas || [])[0] || '';
      $('#chkPersonalizar').checked = !!u.permisos_personalizados;
    } else { fU.rol.value = 'Supervisor'; fU.organizacion.value = 'directo'; llenarLista(fU.area_trabajo, CAT.areas); llenarLista(fU.cargo, CAT.cargos); }
    syncForm();
    if (u && u.permisos_personalizados) { setChecks($('#permisos'), u.permisos || []); aplicarDependencias($('#permisos')); }
    mUsuario.show();
  }
  $('#btnNuevoUsuario')?.addEventListener('click', () => abrirUsuario());

  fU.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const fd = new FormData(fU), body = {};
    fd.forEach((v, k) => { if (k.endsWith('[]')) (body[k.slice(0, -2)] = body[k.slice(0, -2)] || []).push(v); else body[k] = v; });
    body.rol = fU.rol.value; body.permisos_personalizados = $('#chkPersonalizar').checked;
    body.pedir_cambio = $('#uPedirCambio').checked; // solo aplica si se escribe una contraseña

    body.zonas = body.zonas || []; body.permisos = body.permisos || [];
    body.todas_zonas = $('#chkTodasZonas').checked;
    // La zona base actual se envía primero para no cambiar la sede heredada del usuario.
    if (zonaBase && body.zonas.includes(zonaBase)) body.zonas = [zonaBase, ...body.zonas.filter((z) => z !== zonaBase)];
    const btn = $('#btnGuardarUsuario'); btn.disabled = true;
    try {
      const r = await api('guardar', { method: 'POST', body });
      mUsuario.hide(); toast(r.message); cargarUsuarios(); cargarCatalogos();
    } catch (e) {
      $('#uError').textContent = e.message; $('#uError').classList.remove('d-none');
      if (e.field && fU[e.field]) { fU[e.field].classList.add('is-invalid'); fU[e.field].focus(); }
    } finally { btn.disabled = false; }
  });

  $('#usrBody').addEventListener('click', async (ev) => {
    const b = ev.target.closest('button'); if (!b) return;
    try {
      if (b.dataset.edit) { abrirUsuario((await api('detalle', { params: { id: b.dataset.edit } })).data); }
      else if (b.dataset.personal || b.dataset.ficha) { abrirPersonal((await api('ficha', { params: { id: b.dataset.personal || b.dataset.ficha } })).data, !!b.dataset.ficha); }
      else if (b.dataset.pw) {
        if (!confirm(`¿Generar una contraseña temporal para ${b.dataset.name}? La actual dejará de funcionar.`)) return;
        const r = await api('restablecer_password', { method: 'POST', body: { id: +b.dataset.pw } });
        $('#pwUsuario').textContent = b.dataset.name; $('#pwValor').textContent = r.password; mPassword.show();
      } else if (b.dataset.unlock) {
        if (!confirm(`¿Desbloquear a ${b.dataset.name}? Podrá volver a intentar ingresar con su contraseña actual.`)) return;
        const r = await api('desbloquear', { method: 'POST', body: { id: +b.dataset.unlock } });
        toast(r.message); cargarUsuarios();
      } else if (b.dataset.state) {
        const activar = b.dataset.to === '1';
        if (!confirm(`¿${activar ? 'Activar' : 'Desactivar'} a ${b.dataset.name}?${activar ? '' : ' No podrá ingresar al sistema.'}`)) return;
        const r = await api('estado', { method: 'POST', body: { id: +b.dataset.state, activo: activar } });
        toast(r.message); cargarUsuarios();
      }
    } catch (e) { toast(e.message, 'error'); }
  });
  $('#btnCopiarPw').addEventListener('click', () => navigator.clipboard?.writeText($('#pwValor').textContent).then(() => toast('Contraseña copiada.')));
  document.getElementById('mPassword').addEventListener('hidden.bs.modal', () => { $('#pwValor').textContent = ''; });

  // ---------- Roles y permisos ----------
  const etiquetaPermiso = (k) => (CAT.permisos.find((p) => p.clave === k) || { etiqueta: k }).etiqueta;
  function renderRoles() {
    $('#rolesSistema').innerHTML = CAT.roles.map((r) => `<div class="col-md-6 col-xl-4"><div class="card role-card is-system">
      <div class="d-flex justify-content-between align-items-center"><h5><i class="bi bi-shield-lock me-1"></i>${esc(etqRol(r.rol))}</h5><span class="badge bg-light">Sistema</span></div>
      <div class="role-meta"><span class="sgs-chip"><i class="bi bi-people"></i>${r.usuarios} activos</span><span class="sgs-chip"><i class="bi bi-check2-square"></i>${r.permisos.length} permisos</span></div>
      ${r.descripcion ? `<p class="small mb-1">${esc(r.descripcion)}</p>` : ''}
      <div class="role-perms">${r.permisos.slice(0, 6).map((p) => esc(etiquetaPermiso(p))).join(' · ')}${r.permisos.length > 6 ? ` · y ${r.permisos.length - 6} más` : ''}</div>
    </div></div>`).join('');
    // Matriz: filas = módulos (grupos con permisos de ver/editar/eliminar), columnas = roles
    const ACC = [['ver', 'Ver'], ['editar', 'Editar'], ['eliminar', 'Eliminar']];
    const conAccion = new Set(CAT.permisos.filter((p) => p.accion).map((p) => p.grupo));
    const celda = (rol, grupo) => ACC.map(([a, txt]) => {
      const perms = CAT.permisos.filter((p) => p.grupo === grupo && p.accion === a);
      if (!perms.length) return '';
      const tiene = perms.filter((p) => rol.permisos.includes(p.clave));
      return `<span class="perm-acc is-${a}${tiene.length ? '' : ' is-no'}" title="${esc(perms.map((p) => `${rol.permisos.includes(p.clave) ? 'Sí' : 'No'}: ${p.etiqueta}`).join('\n'))}">${tiene.length ? '' : '<s>'}${txt}${tiene.length ? '' : '</s>'}</span>`;
    }).join('');
    $('#matrizPermisos').innerHTML = `<thead><tr><th>Pantalla</th>${CAT.roles.map((r) => `<th>${esc(etqRol(r.rol))}</th>`).join('')}</tr></thead>`
      + `<tbody>${pantallas().map(([sec, lista]) => `<tr class="usr-perm-seccion"><td colspan="${CAT.roles.length + 1}">${esc(sec)}</td></tr>` + lista.filter(([g]) => conAccion.has(g)).map(([g]) => `<tr><td data-label="Pantalla" class="fw-bold">${esc(g)}</td>${CAT.roles.map((r) => `<td data-label="${esc(etqRol(r.rol))}"><div class="perm-accs">${celda(r, g)}</div></td>`).join('')}</tr>`).join('')).join('')}</tbody>`;
  }

  // ---------- Personal: solo datos personales (agregar, editar o ver ficha) ----------
  const fP = $('#formPersonal');
  let ZONAS = { zonas: [], es_partner: true, partners: [] };
  async function cargarZonasPropias() {
    ZONAS = (await api('zonas_propias')).data;
    llenarLista($('#pArea'), ZONAS.areas || []); llenarLista($('#pCargo'), ZONAS.cargos || []);
    $('#pZona').innerHTML = '<option value="">Seleccione la zona</option>' + ZONAS.zonas.map((z) => `<option value="${esc(z)}">${esc(z)}</option>`).join('');
    $('#pPartner').innerHTML = '<option value="0">Seleccione el partner</option>' + (ZONAS.partners || []).map((p) => `<option value="${p.id}">${esc(p.nombre_partner)}</option>`).join('');
  }
  function syncPersonal() {
    const nuevo = +fP.id.value === 0;
    $('#pWrapOrg').classList.toggle('d-none', !nuevo || ZONAS.es_partner);
    $('#pWrapPartner').classList.toggle('d-none', !nuevo || ZONAS.es_partner || fP.organizacion.value !== 'partner');
    $('#pWrapZona').classList.toggle('d-none', !nuevo);
    $('#pZonaLabel').textContent = ZONAS.es_partner ? 'Zona de trabajo *' : 'Zona de trabajo';
  }
  fP.organizacion.addEventListener('change', syncPersonal);
  function abrirPersonal(u = null, soloVer = false) {
    fP.reset(); fP.id.value = u ? u.id : 0; $('#pError').classList.add('d-none');
    ['dni', 'nombres', 'apellidos', 'email', 'telefono_personal', 'telefono_corporativo'].forEach((k) => { fP[k].value = u ? (u[k] ?? '') : ''; });
    llenarLista(fP.area_trabajo, ZONAS.areas || [], u?.area_trabajo || ''); llenarLista(fP.cargo, ZONAS.cargos || [], u?.cargo || '');
    $('#mPersonalTitulo').textContent = soloVer ? (u?.nombres ? `${u.nombres} ${u.apellidos}` : 'Ficha') : (u ? `Editar datos · ${u.nombres} ${u.apellidos}` : 'Agregar personal');
    $('#pAyuda').classList.toggle('d-none', soloVer || !!u);
    fP.querySelectorAll('input:not([type=hidden]),select').forEach((c) => (c.disabled = soloVer));
    $('#btnGuardarPersonal').classList.toggle('d-none', soloVer);
    const info = $('#pInfo');
    info.classList.toggle('d-none', !u);
    info.innerHTML = u ? `<div class="d-flex flex-wrap gap-2"><span class="badge ${u.rol === 'Supervisor' ? 'bg-primary' : 'bg-dark'}">${esc(etqRol(u.rol))}</span><span class="sgs-chip">${esc(u.nombre_partner || 'WIN Directo')}</span><span class="sgs-chip">${u.zonas && u.zonas.length ? esc(u.zonas.join(', ')) : 'Todas las zonas'}</span></div>` : '';
    syncPersonal();
    mPersonal.show();
  }
  $('#btnNuevoPersonal')?.addEventListener('click', () => abrirPersonal());
  fP.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const body = Object.fromEntries(new FormData(fP).entries());
    const btn = $('#btnGuardarPersonal'); btn.disabled = true;
    try { const r = await api('guardar_personal', { method: 'POST', body }); mPersonal.hide(); toast(r.message); cargarUsuarios(); }
    catch (e) { $('#pError').textContent = e.message; $('#pError').classList.remove('d-none'); if (e.field && fP[e.field]) fP[e.field].focus(); }
    finally { btn.disabled = false; }
  });

  if (ES_ADMIN) cargarCatalogos().then(cargarUsuarios).catch((e) => toast(e.message, 'error'));
  else { cargarUsuarios(); if (GESTIONA) cargarZonasPropias().catch((e) => toast(e.message, 'error')); }
});
