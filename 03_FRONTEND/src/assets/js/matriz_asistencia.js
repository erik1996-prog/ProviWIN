// 03_FRONTEND/src/assets/js/matriz_asistencia.js - Pantalla Matriz de asistencia (pages/matriz_asistencia.php, 2026-10-28).
// Pide la matriz del mes a api/asistencia_matriz.php (una sola consulta por cambio de filtro, sin sondeos) y la dibuja:
// supervisores en filas, días en columnas y totales por código al final. Quien puede editar corrige un día con un clic.
document.addEventListener('DOMContentLoaded', () => {
  const $ = (id) => document.getElementById(id);
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const puedeEditar = Boolean(window.SGS_MATRIZ?.puedeEditar);
  const fmt = (f) => f.split('-').reverse().join('/');
  let datos = null;
  let celdaActual = null;

  const filtros = () => new URLSearchParams({ mes: $('masMes').value, zona: $('masZona').value, supervisor_id: $('masSupervisor').value });

  async function cargar() {
    $('masBody').innerHTML = '<tr><td class="text-center text-muted py-4">Cargando…</td></tr>';
    $('masHead').innerHTML = '';
    try {
      const res = await (await fetch('api/asistencia_matriz.php?action=matriz&' + filtros())).json();
      if (res.status !== 'success') throw new Error(res.message);
      datos = res.data;
      dibujar();
    } catch (e) {
      $('masBody').innerHTML = `<tr><td class="text-center text-danger py-4">${esc(e.message || 'No se pudo cargar la matriz.')}</td></tr>`;
    }
  }

  function dibujar() {
    const codigos = Object.keys(datos.codigos);
    $('masHead').innerHTML = `<tr><th class="sticky-col-name">Supervisor</th>${datos.dias.map((d) =>
      `<th class="text-center small px-1${d.semana === 'D' ? ' is-domingo' : ''}">${d.dia}<br>${d.semana}</th>`).join('')}${codigos.map((c) =>
      `<th class="text-center small px-1" title="${esc(datos.codigos[c])}">${c}</th>`).join('')}</tr>`;
    if (!datos.filas.length) {
      $('masBody').innerHTML = `<tr><td colspan="${datos.dias.length + codigos.length + 1}" class="text-center text-muted py-4"><i class="bi bi-inbox me-1"></i>No hay supervisores directos con este filtro.</td></tr>`;
      return;
    }
    $('masBody').innerHTML = datos.filas.map((f) => `<tr>
      <td class="sticky-col-name"><span class="fw-bold">${esc(f.nombre)}</span><br><small class="text-muted">${esc(f.dni)}</small></td>
      ${datos.dias.map((d) => {
        const c = f.celdas[d.fecha];
        const titulo = `${fmt(d.fecha)} · ${c.codigo ? datos.codigos[c.codigo] : 'Sin dato'}${c.ajuste ? ' · Corregido: ' + c.ajuste : ''}`;
        return `<td class="sgs-asis-celda${puedeEditar ? ' is-editable' : ''}${c.ajuste ? ' is-ajuste' : ''}" data-id="${f.id}" data-fecha="${d.fecha}" title="${esc(titulo)}">${c.codigo
          ? `<span class="sgs-asis sgs-asis--${c.codigo}">${c.codigo}</span>` : '<span class="sgs-asis-vacio">·</span>'}</td>`;
      }).join('')}
      ${codigos.map((c) => `<td class="text-center small fw-bold">${f.totales[c] || ''}</td>`).join('')}
    </tr>`).join('');
  }

  // Corrección de un día (solo con permiso de editar)
  $('masBody').addEventListener('click', (ev) => {
    const td = ev.target.closest('td.is-editable');
    if (!td || !datos) return;
    const fila = datos.filas.find((f) => String(f.id) === td.dataset.id);
    const celda = fila?.celdas[td.dataset.fecha];
    if (!celda) return;
    celdaActual = { id: fila.id, fecha: td.dataset.fecha };
    $('masModalDetalle').textContent = `${fila.nombre} · ${fmt(td.dataset.fecha)} · hoy: ${celda.codigo ? datos.codigos[celda.codigo] : 'sin dato'}${celda.ajuste ? ' (corregido)' : ''}`;
    $('masCodigo').value = celda.ajuste ? celda.codigo : '';
    $('masComentario').value = celda.ajuste && celda.ajuste !== 'Corregido' ? celda.ajuste : '';
    bootstrap.Modal.getOrCreateInstance($('masModal')).show();
  });

  $('masForm').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    if (!celdaActual) return;
    const body = new FormData();
    body.append('supervisor_id', celdaActual.id);
    body.append('fecha', celdaActual.fecha);
    body.append('codigo', $('masCodigo').value);
    body.append('comentario', $('masComentario').value);
    try {
      const res = await (await fetch('api/asistencia_matriz.php?action=ajustar', { method: 'POST', headers: { 'X-CSRF-Token': csrf }, body })).json();
      window.showToast(res.message || 'Listo.', res.status === 'success' ? 'success' : 'error');
      if (res.status === 'success') { bootstrap.Modal.getOrCreateInstance($('masModal')).hide(); cargar(); }
    } catch (e) { window.showToast('No se pudo guardar la corrección.', 'error'); }
  });

  ['masMes', 'masZona', 'masSupervisor'].forEach((id) => $(id).addEventListener('change', cargar));
  $('masLimpiar').addEventListener('click', () => {
    const hoy = new Date();
    $('masMes').value = `${hoy.getFullYear()}-${String(hoy.getMonth() + 1).padStart(2, '0')}`;
    $('masZona').value = ''; $('masSupervisor').value = '0';
    cargar();
  });
  $('masExcel').addEventListener('click', () => { window.location.href = 'api/asistencia_matriz.php?action=excel&' + filtros(); });
  cargar();
});
