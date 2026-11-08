// 03_FRONTEND/src/assets/js/usuarios.js - Usuarios y perfiles / Personal (pages/usuarios.php). Datos del servidor en window.SGS_USUARIOS.
// 2026-10-27: salió de la página a este archivo (sistema liviano: el navegador lo guarda).
// Espera a que carguen Bootstrap y main.js (se incluyen al final de la página).
document.addEventListener('DOMContentLoaded', () => {
  const D = window.SGS_USUARIOS;
  const MI_ID = D.miId;
  const ES_ADMIN = D.esAdmin, GESTIONA = D.gestiona;
  const ES_TOTAL = D.esTotal; // false = líder con su equipo: sin contraseñas ni desbloqueos
  const ETQ = D.etiquetasRol, etqRol = (r) => ETQ[r] || r; // nombre que se muestra
  const PUEDE_ACTIVAR = D.puedeActivar; // solo la cuenta principal activa (2026-10-26)
  const VER_COMO = D.verComo; // "Ver como" (2026-10-28): solo el administrador general
  const EDITA_PERFILES = D.editaPerfiles; // perfiles y sus permisos (2026-10-31): solo el administrador general
  const $ = (s, el = document) => el.querySelector(s);
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const toast = (m, t = 'success') => (window.showToast ? window.showToast(m, t) : alert(m));
  let CAT = null;
  const mUsuario = new bootstrap.Modal('#mUsuario'), mPersonal = new bootstrap.Modal('#mPersonal'), mPassword = new bootstrap.Modal('#mPassword');
  const mPerfil = EDITA_PERFILES ? new bootstrap.Modal('#mPerfil') : null;

  async function api(action, { method = 'GET', params = {}, body = null } = {}) {
    const url = 'api/usuarios.php?' + new URLSearchParams({ action, ...params });
    const res = await fetch(url, { method, headers: body ? { 'Content-Type': 'application/json' } : {}, body: body ? JSON.stringify(body) : null, cache: 'no-store' });
    const data = await res.json().catch(() => ({ status: 'error', message: 'Respuesta no válida del servidor.' }));
    if (data.status !== 'success') { const e = new Error(data.message || 'Error'); e.field = data.field; throw e; }
    return data;
  }

  // ---------- Pestañas: Usuarios · Perfiles · Permisos ----------
  const abrirPestana = (tab) => {
    document.querySelectorAll('.usr-tabs .btn').forEach((x) => x.classList.toggle('active', x.dataset.tab === tab));
    ['usuarios', 'perfiles', 'permisos'].forEach((t) => $(`#tab-${t}`)?.classList.toggle('d-none', t !== tab));
    $('#btnNuevoUsuario')?.classList.toggle('d-none', tab !== 'usuarios');
    $('#btnNuevoPerfil')?.classList.toggle('d-none', tab !== 'perfiles');
  };
  document.querySelectorAll('.usr-tabs .btn').forEach((b) => b.addEventListener('click', () => abrirPestana(b.dataset.tab)));

  // ---------- Catálogos ----------
  // Perfiles (2026-10-31): el usuario elige su perfil; su tipo base (rol) y lo que ve salen del perfil.
  // 2026-11-01: el perfil se define con "Perfil administrador" y "¿Qué datos puede ver?" (el tipo base sale de ahí).
  const DATOS = {
    todo: ['bi-globe-americas', 'Todo el país', 'Todas las zonas y áreas. Ej.: gerencia general.'],
    zonas: ['bi-geo-alt', 'Sus zonas a cargo', 'Las zonas y el área que se marquen a cada usuario. Ej.: subgerente, coordinador, líder técnico.'],
    partner: ['bi-buildings', 'Su partner', 'Todo lo de su contrata. Ej.: jefe de operaciones.'],
    propio: ['bi-person', 'Solo lo suyo', 'Su turno, sus supervisiones y su asistencia. Ej.: supervisor, gestor.'],
  };
  const perfilDe = (id) => CAT.perfiles.find((p) => +p.id === +id);
  const esAdminPerfil = (p) => p.rol_base === 'Administrador';
  const datosDe = (p) => (p.rol_base === 'Jefe de operaciones' ? 'partner' : p.alcance);
  const textoAlcance = (p) => DATOS[datosDe(p)][1];
  const iconoPerfil = (p) => (esAdminPerfil(p) ? 'bi-shield-lock' : 'bi-person-vcard');

  async function cargarCatalogos() {
    CAT = (await api('catalogos')).data;
    const perfilOpts = CAT.perfiles.map((p) => `<option value="${p.id}" data-rol="${esc(p.rol_base)}">${esc(p.nombre)}</option>`).join('');
    $('#fPerfil').innerHTML = '<option value="">Todos los perfiles</option>' + perfilOpts;
    $('#wrapTodasZonas').classList.toggle('d-none', !!CAT.equipo); // el líder solo asigna sus zonas
    $('#fOrg').innerHTML = '<option value="">Todas las organizaciones</option><option value="directo">WIN Directo</option>' + CAT.partners.map((p) => `<option value="${p.id}">${esc(p.nombre_partner)}</option>`).join('');
    const f = $('#formUsuario');
    f.perfil_id.innerHTML = perfilOpts;
    f.partner_id.innerHTML = '<option value="0">Seleccione…</option>' + CAT.partners.map((p) => `<option value="${p.id}">${esc(p.nombre_partner)}</option>`).join('');
    llenarLista(f.area_trabajo, CAT.areas);
    $('#wrapSinAcceso').classList.toggle('d-none', !CAT.equipo); // solo el líder registra personas sin acceso
    $('#procesos').innerHTML = ['Todos', ...CAT.procesos].map((p, i) => `<input type="checkbox" class="btn-check" name="procesos[]" value="${esc(p)}" id="proc${i}"><label class="btn btn-outline-secondary" for="proc${i}">${esc(p)}</label>`).join('');
    $('#zonas').innerHTML = CAT.departamentos.map((d, i) => `<input type="checkbox" class="btn-check" name="zonas[]" value="${esc(d)}" id="z${i}"><label class="btn btn-outline-secondary" for="z${i}">${esc(d)}</label>`).join('');
    if (!CAT.equipo) { renderPerfiles(); renderPermisos(); }
  }

  // Listas fijas (área). Un valor guardado que no está en la lista (registros anteriores) se muestra igual.
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
    const params = { q: $('#fQ').value.trim(), perfil: $('#fPerfil')?.value || '', organizacion: $('#fOrg')?.value || '', estado: ES_ADMIN ? estadoAcceso : 'activos' };
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
          <td><div class="d-flex align-items-center gap-2"><span class="usr-avatar">${esc(iniciales(u.nombre_completo))}</span><div><div class="usr-name">${esc(u.nombre_completo)}${yo ? ' <span class="badge bg-light">Tú</span>' : ''}</div><div class="usr-sub">${esc(u.usuario || '—')} · DNI ${esc(u.dni)}</div>${u.area_trabajo ? `<div class="usr-sub">${esc(u.area_trabajo)}</div>` : ''}</div></div></td>
          <td data-label="Perfil y organización"><div class="usr-celda-doble"><span class="badge ${u.rol === 'Supervisor' ? 'bg-primary' : 'bg-dark'}">${esc(u.perfil_nombre || etqRol(u.rol))}</span><small class="usr-org">${directo ? 'WIN Directo' : esc(u.nombre_partner)}</small></div></td>
          <td data-label="Zonas">${u.todas_zonas ? 'Todas' : (u.zonas && u.zonas.length ? esc(u.zonas.join(', ')) : '—')}</td>
          ${!ES_ADMIN ? '' : `<td data-label="Estado"><div class="usr-celda-doble"><div class="usr-insignias">${u.sin_acceso ? '<span class="badge bg-warning" title="Registrado solo con datos personales: falta que el administrador le dé acceso">Sin acceso</span>' : (+u.por_activar === 1 && !activo ? '<span class="badge bg-warning" title="Espera que la cuenta principal lo active">Por activar</span>' : `<span class="badge ${activo ? 'bg-success' : 'bg-secondary'}">${activo ? 'Activo' : 'Inactivo'}</span>`)}${u.bloqueado ? ' <span class="badge bg-danger" title="3 intentos fallidos"><i class="bi bi-lock"></i> Bloqueado</span>' : ''}${u.debe_cambiar_password && !u.sin_acceso ? ' <span class="badge bg-warning" title="Al ingresar, el sistema le pedirá crear su propia contraseña">Cambiará su contraseña</span>' : ''}</div>
            <small class="usr-ingreso" title="Último ingreso">${u.ultimo_acceso ? '<i class="bi bi-box-arrow-in-right"></i> ' + esc(fechaHora(u.ultimo_acceso)) : 'Nunca ingresó'}</small></div></td>`}
          <td class="text-end"><div class="sgs-row-actions">${u.protegido && !yo ? '<span class="badge bg-secondary" title="Solo su titular puede verla o modificarla"><i class="bi bi-shield-lock me-1"></i>Cuenta protegida</span></div></td></tr>' : !ES_ADMIN ? (GESTIONA && u.rol !== 'Administrador'
            ? `<button class="sgs-icon-action" title="Editar datos personales" aria-label="Editar datos personales" data-personal="${u.id}"><i class="bi bi-pencil"></i></button>`
            : `<button class="sgs-icon-action" title="Ver ficha" aria-label="Ver ficha" data-ficha="${u.id}"><i class="bi bi-eye"></i></button>`) + '</div></td></tr>' : `
            ${VER_COMO && activo && !u.sin_acceso && !yo ? `<button class="sgs-icon-action" title="Ver como este usuario (solo mirar)" aria-label="Ver el sistema como este usuario" data-ver-como="${u.id}" data-name="${esc(u.nombre_completo)}"><i class="bi bi-eye"></i></button>` : ''}
            <button class="sgs-icon-action" title="${u.sin_acceso ? 'Completar acceso' : 'Editar'}" aria-label="${u.sin_acceso ? 'Completar acceso' : 'Editar'}" data-edit="${u.id}"><i class="bi ${u.sin_acceso ? 'bi-person-gear' : 'bi-pencil'}"></i></button>
            ${u.bloqueado && ES_TOTAL ? `<button class="sgs-icon-action" title="Desbloquear" aria-label="Desbloquear usuario" data-unlock="${u.id}" data-name="${esc(u.nombre_completo)}"><i class="bi bi-unlock"></i></button>` : ''}
            ${ES_TOTAL ? `<button class="sgs-icon-action" title="Restablecer contraseña" aria-label="Restablecer contraseña" data-pw="${u.id}" data-name="${esc(u.nombre_completo)}"><i class="bi bi-key"></i></button>` : ''}
            ${yo || u.sin_acceso || (!activo && !PUEDE_ACTIVAR) ? '' : `<button class="sgs-icon-action ${activo ? 'sgs-icon-action--danger' : ''}" title="${activo ? 'Desactivar' : 'Activar'}" aria-label="${activo ? 'Desactivar' : 'Activar'}" data-state="${u.id}" data-to="${activo ? 0 : 1}" data-name="${esc(u.nombre_completo)}"><i class="bi ${activo ? 'bi-person-dash' : 'bi-person-check'}"></i></button>`}
          </div></td></tr>`}`;
      }).join('') : `<tr><td colspan="${ES_ADMIN ? 5 : 4}" class="text-center text-muted py-4"><i class="bi bi-inbox d-block fs-3 mb-1"></i>No hay personas con esos filtros.</td></tr>`;
    } catch (e) { body.innerHTML = `<tr><td colspan="${ES_ADMIN ? 5 : 4}" class="text-center text-danger py-4">${esc(e.message)}</td></tr>`; }
  }
  let tQ; $('#fQ').addEventListener('input', () => { clearTimeout(tQ); tQ = setTimeout(cargarUsuarios, 300); });
  ['#fPerfil', '#fOrg'].forEach((s) => $(s)?.addEventListener('change', cargarUsuarios));
  const marcarEstado = (valor) => {
    estadoAcceso = valor;
    document.querySelectorAll('#usrEstados [data-estado]').forEach((b) => b.classList.toggle('active', b.dataset.estado === valor));
  };
  $('#usrEstados')?.addEventListener('click', (ev) => { const b = ev.target.closest('[data-estado]'); if (b) { marcarEstado(b.dataset.estado); cargarUsuarios(); } });
  $('#fLimpiar').addEventListener('click', () => { $('#fQ').value = ''; if ($('#fPerfil')) $('#fPerfil').value = ''; if ($('#fOrg')) $('#fOrg').value = ''; marcarEstado(''); cargarUsuarios(); });

  // Formulario: reglas visibles
  const fU = $('#formUsuario');

  const rolElegido = () => fU.perfil_id.selectedOptions[0]?.dataset.rol || '';
  const primerPerfil = (rol) => CAT.perfiles.find((p) => p.rol_base === rol)?.id;
  function syncForm() {
    // Líder (2026-10-27): "Solo registrar sus datos" = persona sin acceso al sistema (sin usuario ni perfil)
    const sinAcceso = !!CAT.equipo && $('#uSinAcceso').checked;
    $('#uPerfil').closest('.col-md-6').classList.toggle('d-none', sinAcceso);
    $('#wrapAcceso').classList.toggle('d-none', sinAcceso); $('#sinAccesoAviso').classList.toggle('d-none', !sinAcceso);
    $('#wrapPartner').classList.toggle('d-none', fU.organizacion.value !== 'partner');
    // Llevan zonas el supervisor y todo perfil con "Sus zonas y su área" (2026-10-31; sin zonas ve todo); el jefe de
    // operaciones ve todo su partner (el que registra un líder lleva su zona: así queda en su equipo)
    const rol = rolElegido(), porZonas = perfilDe(fU.perfil_id.value)?.alcance === 'zonas';
    const conZonas = rol === 'Supervisor' || porZonas || (!!CAT.equipo && rol === 'Jefe de operaciones');
    $('#wrapZonas').classList.toggle('d-none', !conZonas);
    $('#zonasLider').classList.toggle('d-none', !porZonas);
    const todas = $('#chkTodasZonas').checked;
    $('#zonas').querySelectorAll('input').forEach((c) => (c.disabled = todas));
    const p = perfilDe(fU.perfil_id.value);
    $('#uPerfilAyuda').textContent = p ? `${esAdminPerfil(p) ? 'Perfil administrador · ' : ''}Ve: ${textoAlcance(p)}.${CAT.equipo ? '' : ' Sus permisos están en la pestaña Permisos.'}` : '';
  }
  ['perfil_id', 'organizacion', 'sin_acceso'].forEach((n) => fU[n].addEventListener('change', syncForm));

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
      fU.perfil_id.value = u.perfil_id; fU.organizacion.value = u.organizacion; fU.partner_id.value = u.partner_id || 0;
      llenarLista(fU.area_trabajo, CAT.areas, u.area_trabajo || '');
      const procs = u.procesos || [];
      document.querySelectorAll('#procesos input').forEach((c) => { c.checked = c.value === 'Todos' ? CAT.procesos.every((p) => procs.includes(p)) : procs.includes(c.value); });
      fU.querySelectorAll('input[name="zonas[]"]').forEach((c) => (c.checked = (u.zonas || []).includes(c.value)));
      $('#chkTodasZonas').checked = !!u.todas_zonas; zonaBase = (u.zonas || [])[0] || '';
    } else { fU.perfil_id.value = primerPerfil('Supervisor') ?? CAT.perfiles[0]?.id ?? ''; fU.organizacion.value = 'directo'; llenarLista(fU.area_trabajo, CAT.areas); }
    syncForm();
    mUsuario.show();
  }
  $('#btnNuevoUsuario')?.addEventListener('click', () => abrirUsuario());

  fU.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const fd = new FormData(fU), body = {};
    fd.forEach((v, k) => { if (k.endsWith('[]')) (body[k.slice(0, -2)] = body[k.slice(0, -2)] || []).push(v); else body[k] = v; });
    body.pedir_cambio = $('#uPedirCambio').checked; // solo aplica si se escribe una contraseña

    body.zonas = body.zonas || [];
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
      if (b.dataset.verComo) {
        const cuerpo = new FormData(); cuerpo.append('id', b.dataset.verComo);
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const r = await (await fetch('api/ver_como.php?action=iniciar', { method: 'POST', headers: { 'X-CSRF-Token': csrf }, body: cuerpo })).json();
        if (r.status !== 'success') throw new Error(r.message || 'No se pudo ver como este usuario.');
        window.location.href = r.redirect;
      }
      else if (b.dataset.edit) { abrirUsuario((await api('detalle', { params: { id: b.dataset.edit } })).data); }
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

  // ---------- Perfiles (2026-10-31) ----------
  function renderPerfiles() {
    $('#perfBody').innerHTML = CAT.perfiles.map((p) => `<tr>
      <td data-label="Perfil"><div class="sgs-persona"><span class="sgs-mini-avatar" aria-hidden="true"><i class="bi ${iconoPerfil(p)}"></i></span><div class="sgs-persona-texto"><span class="sgs-persona-nombre">${esc(p.nombre)} <small class="text-muted">· ${esc(p.codigo)}</small></span>${p.descripcion ? `<small>${esc(p.descripcion)}</small>` : ''}</div></div></td>
      <td data-label="Tipo de acceso">${esAdminPerfil(p) ? '<span class="badge bg-primary"><i class="bi bi-shield-lock me-1"></i>Acceso total</span>' : `<span class="badge bg-light">${p.permisos.length} acciones</span>`}</td>
      <td data-label="Datos que ve"><span class="small"><i class="bi ${DATOS[datosDe(p)][0]} me-1"></i>${esc(textoAlcance(p))}</span></td>
      <td data-label="Trabajadores" class="text-center"><span>${p.trabajadores}</span></td>
      <td class="text-end"><div class="sgs-row-actions justify-content-end">
        <button type="button" class="sgs-icon-action" title="Permisos" aria-label="Permisos de ${esc(p.nombre)}" data-perfil-permisos="${p.id}"><i class="bi bi-ui-checks-grid"></i></button>
        ${EDITA_PERFILES ? `<button type="button" class="sgs-icon-action" title="Editar" aria-label="Editar ${esc(p.nombre)}" data-perfil-editar="${p.id}"><i class="bi bi-pencil"></i></button>
        <button type="button" class="sgs-icon-action sgs-icon-action--danger" title="${p.usuarios ? 'Tiene usuarios: no se puede eliminar' : 'Eliminar'}" aria-label="Eliminar ${esc(p.nombre)}" data-perfil-eliminar="${p.id}" data-name="${esc(p.nombre)}"${p.usuarios ? ' disabled' : ''}><i class="bi bi-trash3"></i></button>` : ''}
      </div></td></tr>`).join('') || '<tr><td colspan="5" class="text-center text-muted py-4"><i class="bi bi-inbox d-block fs-3 mb-1"></i>No hay perfiles.</td></tr>';
  }
  $('#perfBody')?.addEventListener('click', async (ev) => {
    const b = ev.target.closest('button'); if (!b || b.disabled) return;
    if (b.dataset.perfilPermisos) { perfilPermisos = +b.dataset.perfilPermisos; renderPermisos(); abrirPestana('permisos'); }
    else if (b.dataset.perfilEditar) abrirPerfil(perfilDe(b.dataset.perfilEditar));
    else if (b.dataset.perfilEliminar) {
      if (!confirm(`¿Eliminar el perfil ${b.dataset.name}?`)) return;
      try { const r = await api('perfil_eliminar', { method: 'POST', body: { id: +b.dataset.perfilEliminar } }); toast(r.message); await cargarCatalogos(); }
      catch (e) { toast(e.message, 'error'); }
    }
  });

  // Ventana del perfil: datos, "Perfil administrador", "¿Qué datos puede ver?" y perfiles que puede asignar. Si el perfil tiene
  // usuarios, lo que se cambie vale para todos ellos (el servidor valida que no choque con su organización). Un perfil
  // administrador ve todo el país.
  const fPerf = $('#formPerfil');
  function pintarDatos(valor) {
    const admin = fPerf.es_admin.checked;
    if (admin) valor = 'todo';
    $('#perfAlcances').innerHTML = Object.entries(DATOS).map(([k, [icono, titulo, ayuda]]) => `<label class="perf-alcance"><input type="radio" name="datos" value="${k}"${k === valor ? ' checked' : ''}${admin ? ' disabled' : ''}><i class="bi ${icono}" aria-hidden="true"></i><span><strong>${titulo}</strong><small>${ayuda}</small></span></label>`).join('');
  }
  function abrirPerfil(p = null) {
    fPerf.reset(); $('#perfError').classList.add('d-none');
    fPerf.id.value = p ? p.id : 0;
    $('#mPerfilTitulo').textContent = p ? 'Editar perfil' : 'Registrar perfil';
    ['nombre', 'codigo', 'descripcion'].forEach((k) => { fPerf[k].value = p ? (p[k] ?? '') : ''; });
    fPerf.es_admin.checked = !!(p && esAdminPerfil(p));
    pintarDatos(p ? datosDe(p) : 'propio');
    // Perfiles que puede asignar: tarjetas con casilla; un perfil administrador no se delega
    $('#perfAsignables').innerHTML = CAT.perfiles.map((x) => {
      const noDelega = esAdminPerfil(x) && !(p && esAdminPerfil(p));
      return `<label class="perf-asignable"><input class="form-check-input mt-0" type="checkbox" name="asignables[]" value="${x.id}"${p && p.asignables.includes(+x.id) ? ' checked' : ''}${noDelega ? ' disabled' : ''}>`
        + `<span><strong>${esc(x.nombre)}${p && +x.id === +p.id ? ' <small class="text-muted">(este perfil)</small>' : ''}</strong><small>${esc(x.codigo)}${noDelega ? ' · no se delega' : ''}</small></span></label>`;
    }).join('');
    mPerfil.show();
  }
  $('#btnNuevoPerfil')?.addEventListener('click', () => abrirPerfil());
  fPerf?.es_admin.addEventListener('change', () => pintarDatos(fPerf.querySelector('input[name="datos"]:checked')?.value || 'propio'));
  fPerf?.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const body = { id: +fPerf.id.value, nombre: fPerf.nombre.value, codigo: fPerf.codigo.value, descripcion: fPerf.descripcion.value, es_admin: fPerf.es_admin.checked,
      datos: fPerf.querySelector('input[name="datos"]:checked')?.value || '', asignables: [...fPerf.querySelectorAll('input[name="asignables[]"]:checked')].map((c) => +c.value) };
    const btn = $('#btnGuardarPerfil'); btn.disabled = true;
    try { const r = await api('perfil_guardar', { method: 'POST', body }); mPerfil.hide(); toast(r.message); await cargarCatalogos(); }
    catch (e) { $('#perfError').textContent = e.message; $('#perfError').classList.remove('d-none'); if (e.field && fPerf[e.field]) fPerf[e.field].focus(); }
    finally { btn.disabled = false; }
  });

  // ---------- Permisos del perfil (2026-10-31) ----------
  // Una sección del menú por tarjeta y una fila por pantalla con sus botones (Ver primero, Eliminar al final). Sin "Ver" los
  // demás botones de esa pantalla se apagan y se bloquean (el servidor aplica la misma regla al guardar).
  const SECCIONES = [['Supervisiones', 'bi-journal-check'], ['Seguimiento', 'bi-activity'], ['Observaciones', 'bi-eye'], ['Asistencia', 'bi-calendar-check'],
    ['Personal', 'bi-people'], ['Indicadores', 'bi-graph-up'], ['Otros', 'bi-grid']];
  const ORDEN_ACCION = { ver: 0, editar: 1, eliminar: 3 };
  // Ícono de cada pantalla: el mismo del menú
  const ICONO_PANTALLA = { 'Supervisiones': 'bi-journal-check', 'Línea de tiempo': 'bi-diagram-3', 'Mi turno': 'bi-play-circle', 'Observaciones': 'bi-clipboard2-pulse',
    'Matriz de asistencia': 'bi-calendar3', 'Vacaciones y licencias': 'bi-calendar2-x', 'Usuarios y perfiles': 'bi-person-badge', 'Técnicos y cuadrillas': 'bi-people-fill', 'Partners': 'bi-buildings',
    'Dashboard de supervisiones': 'bi-speedometer2', 'Dashboard de partners': 'bi-buildings', 'Pendientes y rescates': 'bi-arrow-repeat', 'Clima': 'bi-cloud-sun',
    'Exámenes': 'bi-clipboard-check' };
  let perfilPermisos = 0; // perfil elegido
  let marcados = new Set();
  function renderPermisos() {
    if (!perfilDe(perfilPermisos)) perfilPermisos = +(CAT.perfiles[0]?.id || 0);
    const p = perfilDe(perfilPermisos); if (!p) return;
    marcados = new Set(p.permisos);
    $('#permPerfiles').innerHTML = CAT.perfiles.map((x) => `<button type="button" class="perm-perfil${+x.id === +p.id ? ' is-activo' : ''}" role="option" aria-selected="${+x.id === +p.id}" data-perfil="${x.id}"><span class="perm-perfil-icono" aria-hidden="true"><i class="bi ${iconoPerfil(x)}"></i></span><span class="perm-perfil-texto"><strong>${esc(x.nombre)}</strong><small>${esAdminPerfil(x) ? 'Acceso total · ' : ''}${esc(textoAlcance(x))}</small></span></button>`).join('');
    $('#permAviso span').textContent = esAdminPerfil(p)
      ? 'Este perfil es administrador: tiene acceso total a todas las pantallas y acciones sin marcarlas (menos registrar supervisiones, su turno, avisar lluvias y responder observaciones como partner, que son de campo).'
      : `Los cambios valen para todos los usuarios con el perfil ${p.nombre} (${p.trabajadores} activos).` + (EDITA_PERFILES ? '' : ' Solo el administrador general puede cambiarlos.');
    $('.perm-guardar')?.classList.toggle('d-none', esAdminPerfil(p));
    pintarGrupos();
  }
  function pintarGrupos() {
    // Perfil administrador: tiene todo menos lo de campo, aunque no esté marcado; no se edita aquí
    const admin = esAdminPerfil(perfilDe(perfilPermisos)), editable = EDITA_PERFILES && !admin;
    const tiene = (clave) => (admin ? !CAT.de_campo.includes(clave) : marcados.has(clave));
    const porPantalla = {};
    CAT.permisos.forEach((x) => (porPantalla[x.grupo] = porPantalla[x.grupo] || { seccion: x.seccion, items: [] }).items.push(x));
    $('#permGrupos').innerHTML = SECCIONES.map(([sec, icono]) => {
      const pantallas = Object.entries(porPantalla).filter(([, v]) => v.seccion === sec);
      if (!pantallas.length) return '';
      const todos = pantallas.flatMap(([, v]) => v.items), n = todos.filter((x) => tiene(x.clave)).length;
      const cabecera = `<div class="perm-grupo-cab"><i class="bi ${icono}" aria-hidden="true"></i><h6>${esc(sec)}</h6><span class="perm-grupo-cuenta">${n} de ${todos.length}</span>`
        + (editable ? `<input class="form-check-input ms-1 mt-0" type="checkbox" data-seccion="${esc(sec)}"${n === todos.length ? ' checked' : ''} title="Marcar o quitar todo" aria-label="Marcar o quitar todo en ${esc(sec)}">` : '') + '</div>';
      return `<div class="card p-0 perm-grupo">${cabecera}` + pantallas.map(([pantalla, v]) => {
        const botones = [...v.items].sort((a, b) => (ORDEN_ACCION[a.accion] ?? 2) - (ORDEN_ACCION[b.accion] ?? 2)).map((x) => `<button type="button" class="perm-pill" aria-pressed="${tiene(x.clave)}" data-permiso="${esc(x.clave)}" title="${esc(x.etiqueta)}"${!editable || (x.requiere && !tiene(x.requiere)) ? ' disabled' : ''}><i class="bi bi-check2" aria-hidden="true"></i>${esc(x.boton)}</button>`).join('');
        const todas = v.items.every((x) => tiene(x.clave));
        const casilla = `<input class="form-check-input mt-0" type="checkbox" data-pantalla="${esc(pantalla)}"${todas ? ' checked' : ''}${editable ? '' : ' disabled'} aria-label="Todas las acciones de ${esc(pantalla)}">`;
        return `<div class="perm-fila"><div class="perm-fila-nombre">${casilla}<i class="bi ${ICONO_PANTALLA[pantalla] || 'bi-window'}" aria-hidden="true"></i><span>${esc(pantalla)}<small>${esc(sec)}</small></span></div><div class="perm-acciones">${botones}</div></div>`;
      }).join('') + '</div>';
    }).join('');
  }
  $('#permPerfiles')?.addEventListener('click', (ev) => { const b = ev.target.closest('[data-perfil]'); if (b) { perfilPermisos = +b.dataset.perfil; renderPermisos(); } });
  $('#permGrupos')?.addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-permiso]'); if (!b || b.disabled) return;
    const clave = b.dataset.permiso;
    if (marcados.has(clave)) { marcados.delete(clave); CAT.permisos.filter((x) => x.requiere === clave).forEach((x) => marcados.delete(x.clave)); }
    else marcados.add(clave);
    pintarGrupos();
  });
  // Casilla de la sección o de una pantalla: marca o quita todas sus acciones
  $('#permGrupos')?.addEventListener('change', (ev) => {
    const c = ev.target.closest('[data-seccion], [data-pantalla]'); if (!c) return;
    CAT.permisos.filter((x) => (c.dataset.seccion ? x.seccion === c.dataset.seccion : x.grupo === c.dataset.pantalla)).forEach((x) => (c.checked ? marcados.add(x.clave) : marcados.delete(x.clave)));
    pintarGrupos();
  });
  $('#btnGuardarPermisos')?.addEventListener('click', async (ev) => {
    const btn = ev.currentTarget; btn.disabled = true;
    try { const r = await api('perfil_permisos', { method: 'POST', body: { id: perfilPermisos, permisos: [...marcados] } }); toast(r.message); await cargarCatalogos(); }
    catch (e) { toast(e.message, 'error'); }
    finally { btn.disabled = false; }
  });
  // ---------- Personal: solo datos personales (agregar, editar o ver ficha) ----------
  const fP = $('#formPersonal');
  let ZONAS = { zonas: [], es_partner: true, partners: [] };
  async function cargarZonasPropias() {
    ZONAS = (await api('zonas_propias')).data;
    llenarLista($('#pArea'), ZONAS.areas || []);
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
    llenarLista(fP.area_trabajo, ZONAS.areas || [], u?.area_trabajo || '');
    $('#mPersonalTitulo').textContent = soloVer ? (u?.nombres ? `${u.nombres} ${u.apellidos}` : 'Ficha') : (u ? `Editar datos · ${u.nombres} ${u.apellidos}` : 'Agregar personal');
    $('#pAyuda').classList.toggle('d-none', soloVer || !!u);
    fP.querySelectorAll('input:not([type=hidden]),select').forEach((c) => (c.disabled = soloVer));
    $('#btnGuardarPersonal').classList.toggle('d-none', soloVer);
    const info = $('#pInfo');
    info.classList.toggle('d-none', !u);
    info.innerHTML = u ? `<div class="d-flex flex-wrap gap-2"><span class="badge ${u.rol === 'Supervisor' ? 'bg-primary' : 'bg-dark'}">${esc(u.perfil_nombre || etqRol(u.rol))}</span><span class="sgs-chip">${esc(u.nombre_partner || 'WIN Directo')}</span><span class="sgs-chip">${u.zonas && u.zonas.length ? esc(u.zonas.join(', ')) : 'Todas las zonas'}</span></div>` : '';
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
