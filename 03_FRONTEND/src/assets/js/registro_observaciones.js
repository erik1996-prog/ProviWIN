// 03_FRONTEND/src/assets/js/registro_observaciones.js - Registro de observaciones (pages/registro_observaciones.php). Al filtrar se reemplaza el listado
// (#observationWorkspace) y se vuelve a llamar a sgsVistaObservaciones(), como antes se volvía a correr el script de la página.
// 2026-10-27: salió de la página a este archivo (sistema liviano: el navegador lo guarda).
function sgsVistaObservaciones() {
const observationFilters=document.getElementById('observationFilters');
if(observationFilters){
    let filterTimer;
    const applyObservationFilters=()=>observationFilters.requestSubmit();
    // Cada filtro se aplica apenas cambia (sin botón "Filtrar"); lo escrito espera a que se deje de teclear.
    observationFilters.querySelectorAll('input[type="radio"],input[type="date"],select').forEach(control=>control.addEventListener('change',applyObservationFilters));
    observationFilters.querySelectorAll('input[type="text"],input[type="search"]').forEach(control=>control.addEventListener('input',()=>{
        clearTimeout(filterTimer);
        filterTimer=setTimeout(applyObservationFilters,550);
    }));
    observationFilters.addEventListener('submit',async event=>{
        event.preventDefault();
        const workspace=document.getElementById('observationWorkspace');
        const url=`index.php?${new URLSearchParams(new FormData(observationFilters)).toString()}`;
        const enfocado=document.activeElement&&observationFilters.contains(document.activeElement)?document.activeElement.id:'';
        workspace.classList.add('is-filtering');
        try{
            const response=await fetch(url,{headers:{'X-Requested-With':'XMLHttpRequest'}});
            if(!response.ok)throw new Error('No se pudo actualizar el listado');
            const page=new DOMParser().parseFromString(await response.text(),'text/html');
            const nextWorkspace=page.getElementById('observationWorkspace');
            if(!nextWorkspace)throw new Error('Respuesta incompleta');
            workspace.replaceWith(nextWorkspace);
            history.replaceState({},'',url);
            sgsVistaObservaciones();
            // Quien estaba escribiendo sigue en el mismo campo, con el cursor al final.
            const campo=enfocado?document.getElementById(enfocado):null;
            if(campo){campo.focus();if(typeof campo.value==='string'&&campo.type!=='date'&&campo.setSelectionRange){const fin=campo.value.length;try{campo.setSelectionRange(fin,fin)}catch(e){}}}
        }catch(error){
            workspace.classList.remove('is-filtering');
            window.showToast('No se pudieron aplicar los filtros. Inténtelo nuevamente.', 'error');
        }
    });
}
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
const obsEscape=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
function openChecklistDetail(row){let detail;try{detail=JSON.parse(row.dataset.detail||'{}')}catch(error){return}const canRespond=row.dataset.canRespond==='1',canReview=row.dataset.canReview==='1';const form=document.getElementById('formChecklistResponse');form.reset();form.dataset.detail=JSON.stringify(detail);document.getElementById('checklistSupervisionId').value=detail.supervision_id||'';document.getElementById('checklistDetailCrew').textContent=`${detail.codigo||'Sin código'} · ${detail.cuadrilla||'Sin cuadrilla'}`;document.getElementById('checklistDetailMeta').innerHTML=`<div class="row g-2"><div class="col-md-3"><strong>Código:</strong> ${obsEscape(detail.codigo||'Sin código')}</div><div class="col-md-3"><strong>Partner:</strong> ${obsEscape(detail.partner)}</div><div class="col-md-3"><strong>Ingreso:</strong> ${obsEscape(detail.fecha)}</div><div class="col-md-3"><strong>Fecha límite:</strong> ${obsEscape(detail.plazo)}</div><div class="col-md-3"><strong>Estado:</strong> ${obsEscape(detail.estado)}</div><div class="col-md-4"><strong>Supervisor:</strong> ${obsEscape(detail.supervisor||'Sin asignar')}</div><div class="col-md-5"><strong>Ubicación:</strong> ${obsEscape(detail.ubicacion||'No registrada')}</div></div>`;document.getElementById('checklistGeneralOriginal').innerHTML=detail.comentario_general?`<div class="p-3 border rounded bg-light"><strong>Comentario general de la cuadrilla</strong><div>${obsEscape(detail.comentario_general)}</div></div>`:'';document.getElementById('checklistDetailItems').innerHTML=(detail.items||[]).map((item,index)=>`<div class="col-lg-6"><div class="card h-100 border shadow-sm"><div class="card-body"><div class="d-flex gap-3 mb-3">${item.foto?`<button type="button" class="btn p-0 border-0 bg-transparent checklist-zoom" data-photo="${obsEscape(item.foto)}" data-title="Evidencia original"><img src="${obsEscape(item.foto)}" class="checklist-detail-photo" alt="Evidencia"></button>`:''}<div><span class="badge bg-warning text-dark mb-2">Observación ${index+1}</span><h6 class="fw-bold">${obsEscape(item.titulo)}</h6><p class="small mb-0">${obsEscape(item.observacion)}</p></div></div>${canRespond?`<div class="border-top pt-3"><label class="form-label fw-bold small">Foto del levantamiento *</label><input class="form-control form-control-sm mb-2" type="file" name="foto_item_${Number(item.id)}" accept="image/jpeg,image/png,image/webp" required><label class="form-label fw-bold small">Comentario del levantamiento *</label><textarea class="form-control form-control-sm" name="comentario_item[${Number(item.id)}]" rows="3" required></textarea></div>`:(item.descargo_foto||item.descargo_comentario?`<div class="border-top pt-3"><strong class="small text-success">Levantamiento presentado</strong>${item.descargo_foto?`<button type="button" class="btn p-0 border-0 bg-transparent checklist-zoom d-block my-2" data-photo="${obsEscape(item.descargo_foto)}" data-title="Foto del levantamiento"><img src="${obsEscape(item.descargo_foto)}" class="checklist-detail-photo"></button>`:''}<p class="small mb-0">${obsEscape(item.descargo_comentario)}</p></div>`:'')}</div></div></div>`).join('');document.getElementById('checklistResponseArea').classList.toggle('d-none',!canRespond);document.getElementById('checklistSubmitButton').classList.toggle('d-none',!canRespond);document.getElementById('checklistReviewArea').classList.toggle('d-none',!canReview);document.getElementById('checklistApproveButton').classList.toggle('d-none',!canReview);document.getElementById('checklistRejectButton').classList.toggle('d-none',!canReview);document.getElementById('checklistPartnerGeneral').textContent=detail.respuesta_general||'';document.getElementById('checklistReviewComment').value=detail.evaluacion||'';bootstrap.Modal.getOrCreateInstance(document.getElementById('modalChecklistDetail')).show()}
function appendChecklistSupervisor(row){try{const detail=JSON.parse(row.dataset.detail||'{}');const meta=document.getElementById('checklistDetailMeta');if(detail.respondido_por)meta.insertAdjacentHTML('beforeend',`<div class="mt-2"><strong>Respondido por (Partner):</strong> ${obsEscape(detail.respondido_por)}${detail.fecha_respuesta?` · ${obsEscape(detail.fecha_respuesta)}`:''}</div>`)}catch(error){}}
const checklistReopenButton=document.createElement('button');checklistReopenButton.type='button';checklistReopenButton.className='btn btn-warning d-none';checklistReopenButton.innerHTML='<i class="bi bi-unlock me-1"></i>Reabrir observación';document.getElementById('checklistSubmitButton')?.before(checklistReopenButton);
function prepareChecklistActions(row){let detail={};try{detail=JSON.parse(row.dataset.detail||'{}')}catch(error){}document.getElementById('checklistEvaluationResult')?.remove();if(detail.evaluacion){const result=document.createElement('div');result.id='checklistEvaluationResult';result.className=`alert ${detail.estado==='Penalizado'?'alert-danger':'alert-success'} mt-3 mb-0`;result.innerHTML=`<strong class="d-block mb-1">${detail.estado==='Penalizado'?'Motivo de no validación':'Resultado de la validación'}</strong><span>${obsEscape(detail.evaluacion)}</span>`;document.getElementById('checklistGeneralOriginal')?.after(result)}checklistReopenButton.classList.toggle('d-none',row.dataset.canReopen!=='1');const review=document.getElementById('checklistReviewComment'),reject=document.getElementById('checklistRejectButton');if(review){review.value='';review.classList.add('d-none');if(review.previousElementSibling)review.previousElementSibling.textContent='Se validan descargos'}if(reject)reject.dataset.awaitingReason='0';if(row.dataset.canRespond==='1'){(detail.items||[]).forEach(item=>{const textarea=document.querySelector(`[name="comentario_item[${Number(item.id)}]"]`),input=document.querySelector(`[name="foto_item_${Number(item.id)}"]`);if(textarea)textarea.value=item.descargo_comentario||'';if(input&&item.descargo_foto){input.required=false;const preview=document.createElement('button');preview.type='button';preview.className='btn btn-sm btn-outline-secondary checklist-zoom mb-2';preview.dataset.photo=item.descargo_foto;preview.dataset.title='Descargo anterior';preview.innerHTML='<i class="bi bi-image me-1"></i>Ver foto anterior';input.after(preview)}})}}
document.querySelectorAll('.checklist-record-row').forEach(row=>{row.addEventListener('click',event=>{if(!event.target.closest('button,a,input,select,textarea,label,[data-no-row-open]')){openChecklistDetail(row);appendChecklistSupervisor(row);prepareChecklistActions(row)}});row.addEventListener('keydown',event=>{if(event.target===row&&(event.key==='Enter'||event.key===' ')){event.preventDefault();openChecklistDetail(row);appendChecklistSupervisor(row);prepareChecklistActions(row)}})});
document.querySelectorAll('.field-record-row').forEach(row=>{const open=()=>row.querySelector('.btn-descargo,.btn-revision,.btn-detalle-flujo')?.click();row.addEventListener('click',event=>{if(event.target.closest('button,a,input,select,textarea,label'))return;open()});row.addEventListener('keydown',event=>{if(event.target===row&&(event.key==='Enter'||event.key===' ')){event.preventDefault();open()}})});
document.getElementById('checklistDetailItems')?.addEventListener('click',event=>{const button=event.target.closest('.checklist-zoom');if(!button)return;document.getElementById('observationPhotoImage').src=button.dataset.photo;document.getElementById('observationPhotoTitle').textContent=button.dataset.title||'Evidencia';bootstrap.Modal.getOrCreateInstance(document.getElementById('modalObservationPhoto')).show()});
document.getElementById('formChecklistResponse')?.addEventListener('submit',async event=>{event.preventDefault();const submit=document.getElementById('checklistSubmitButton');submit.disabled=true;try{const response=await fetch('api/observaciones.php?action=responder_checklist_partner',{method:'POST',headers:{'X-CSRF-Token':csrfToken},body:new FormData(event.currentTarget)});const result=await response.json();window.showToast(result.message||'Levantamiento procesado.',result.status==='success'?'success':'error');if(result.status==='success')location.reload()}catch(error){window.showToast('No se pudo enviar el levantamiento.','error')}finally{submit.disabled=false}});
const checklistReviewComment=document.getElementById('checklistReviewComment');if(checklistReviewComment){checklistReviewComment.classList.add('d-none');if(checklistReviewComment.previousElementSibling)checklistReviewComment.previousElementSibling.textContent='Se validan descargos'}const validButton=document.getElementById('checklistApproveButton'),invalidButton=document.getElementById('checklistRejectButton');if(validButton)validButton.innerHTML='<i class="bi bi-check-circle me-1"></i>Válido';if(invalidButton)invalidButton.innerHTML='<i class="bi bi-x-circle me-1"></i>No válido';
async function evaluateChecklist(approve){const reject=document.getElementById('checklistRejectButton'),reason=document.getElementById('checklistReviewComment');if(!approve&&reject.dataset.awaitingReason!=='1'){reject.dataset.awaitingReason='1';reason.classList.remove('d-none');reason.previousElementSibling.textContent='¿Por qué no se valida la observación? *';reason.placeholder='Detalle el motivo del rechazo...';reason.focus();return}const comment=approve?'':reason.value.trim();if(!approve&&!comment){window.showToast('Indique por qué no se valida la observación.','warning');reason.focus();return}const buttons=[document.getElementById('checklistApproveButton'),reject];buttons.forEach(button=>button.disabled=true);try{const response=await fetch('api/observaciones.php?action=evaluar_checklist',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},body:JSON.stringify({supervision_id:Number(document.getElementById('checklistSupervisionId').value),aprobar:approve,comentario:comment})});const result=await response.json();window.showToast(result.message||'Evaluación procesada.',result.status==='success'?'success':'error');if(result.status==='success')location.reload()}catch(error){window.showToast('No se pudo guardar la evaluación.','error')}finally{buttons.forEach(button=>button.disabled=false)}}
document.getElementById('checklistApproveButton')?.addEventListener('click',()=>evaluateChecklist(true));document.getElementById('checklistRejectButton')?.addEventListener('click',()=>evaluateChecklist(false));
checklistReopenButton.addEventListener('click',async()=>{checklistReopenButton.disabled=true;try{const response=await fetch('api/observaciones.php?action=reabrir_checklist',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},body:JSON.stringify({supervision_id:Number(document.getElementById('checklistSupervisionId').value)})});const result=await response.json();window.showToast(result.message||'Observación reabierta.',result.status==='success'?'success':'error');if(result.status==='success')location.reload()}catch(error){window.showToast('No se pudo reabrir la observación.','error')}finally{checklistReopenButton.disabled=false}});
document.querySelectorAll('.obs-photo-link').forEach(button => button.addEventListener('click', () => {
    document.getElementById('observationPhotoImage').src = button.dataset.photo;
    document.getElementById('observationPhotoTitle').textContent = button.dataset.title || 'Evidencia';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalObservationPhoto')).show();
}));
// Todas las fotos de esta pantalla se ven en el visor común con zoom (main.js)
const observationPhotoModal=document.getElementById('modalObservationPhoto');
if(observationPhotoModal&&!observationPhotoModal.dataset.visor){observationPhotoModal.dataset.visor='1';observationPhotoModal.addEventListener('show.bs.modal',event=>{event.preventDefault();window.sgsVerFoto(document.getElementById('observationPhotoImage').getAttribute('src'),document.getElementById('observationPhotoTitle').textContent)})}
// Responder (partner): de 1 a 3 fotos (2026-10-19) + un comentario general. Se abre desde la fila o desde "Ver detalle".
function abrirRespuestaPartner(id, codigo, titulo){
    document.getElementById('formDescargo').reset();
    document.getElementById('descargoObsId').value=id;
    document.getElementById('descargoCodigo').textContent=codigo?`Pedido o ticket ${codigo}`:'';
    document.getElementById('descargoTitle').textContent=titulo||'Observación';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalDescargo')).show();
}
document.querySelectorAll('.btn-descargo').forEach(button => button.addEventListener('click',()=>abrirRespuestaPartner(button.dataset.id,button.dataset.codigo,button.dataset.title)));
document.getElementById('formDescargo')?.addEventListener('submit',async event=>{
    event.preventDefault();
    if (![1,2,3].some(i => document.getElementById('descargoFoto'+i)?.files?.length)) { window.showToast('Adjunte al menos una foto del levantamiento.','error'); return; }
    const submit=event.submitter; submit.disabled=true;
    try{const response=await fetch('api/observaciones.php?action=responder_partner',{method:'POST',headers:{'X-CSRF-Token':csrfToken},body:new FormData(event.currentTarget)});const result=await response.json();window.showToast(result.message||'Descargo procesado.',result.status==='success'?'success':'error');if(result.status==='success')location.reload();}
    catch(error){window.showToast('No se pudo enviar el descargo.','error');}finally{submit.disabled=false;}
});
document.querySelectorAll('.btn-revision').forEach(button=>button.addEventListener('click',()=>{
    document.getElementById('revisionObsId').value=button.dataset.id; document.getElementById('revisionTitle').textContent=button.dataset.title;
    document.getElementById('revisionRespondidoPor').textContent=`Respondido por (Partner): ${button.dataset.respondidoPor||'No registrado'}`;
    document.getElementById('revisionRespuesta').textContent=button.dataset.respuesta||'—'; document.getElementById('revisionComentario').value='';
    let fotos=[];try{fotos=JSON.parse(button.dataset.fotos||'[]')}catch(e){}
    document.getElementById('revisionFotos').innerHTML=fotos.map((foto,i)=>`<div class="border rounded p-2" style="width:180px"><button type="button" class="btn p-0 border-0 bg-transparent review-photo" data-photo="${String(foto.url||'').replace(/"/g,'&quot;')}"><img src="${String(foto.url||'').replace(/"/g,'&quot;')}" class="w-100 rounded" style="height:115px;object-fit:cover" alt="Foto de descargo ${i+1}"></button>${foto.comentario?`<small class="d-block mt-2">${String(foto.comentario).replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]))}</small>`:''}</div>`).join('');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalRevision')).show();
}));
document.getElementById('revisionFotos')?.addEventListener('click',event=>{const button=event.target.closest('.review-photo');if(!button)return;document.getElementById('observationPhotoImage').src=button.dataset.photo;document.getElementById('observationPhotoTitle').textContent='Evidencia del levantamiento';bootstrap.Modal.getOrCreateInstance(document.getElementById('modalObservationPhoto')).show();});
document.querySelectorAll('.revision-submit').forEach(button=>button.addEventListener('click',()=>button.closest('form').dataset.aprobar=button.dataset.aprobar));
document.getElementById('formRevision')?.addEventListener('submit',async event=>{
    event.preventDefault(); const comment=document.getElementById('revisionComentario').value.trim();if(!comment){window.showToast('Ingrese el comentario de la revisión.','warning');document.getElementById('revisionComentario').focus();return;}
    const payload={observacion_id:Number(document.getElementById('revisionObsId').value),aprobar_apelacion:event.currentTarget.dataset.aprobar==='1',sustentacion_evaluacion:comment};
    const response=await fetch('api/observaciones.php?action=evaluar_supervisor',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},body:JSON.stringify(payload)});const result=await response.json();window.showToast(result.message||'Revisión procesada.',result.status==='success'?'success':'error');if(result.status==='success')location.reload();
});
// Detalle de la observación: datos de la orden, hallazgo con fotos y seguimiento (respuesta del partner y evaluación WIN)
let flowActual = null;
const flowEstadoClase = estado => ['Levantamiento validado','Apelación Aprobada'].includes(estado) ? 'bg-success' : (['Levantamiento rechazado','Penalidad Directa','Apelación Rechazada'].includes(estado) ? 'bg-danger' : (estado === 'En revisión' ? 'bg-info text-dark' : 'bg-warning text-dark'));
const flowFotos = (fotos, prefijo) => fotos.length
  ? fotos.map((f, i) => `<button type="button" class="sgs-obs-foto flow-original-photo" data-photo="${obsEscape(f.url)}" data-title="${prefijo} ${i + 1}"><img src="${obsEscape(f.url)}" alt="${prefijo} ${i + 1}" loading="lazy">${f.comment ? `<span>${obsEscape(f.comment)}</span>` : ''}</button>`).join('')
  : '<p class="text-muted small mb-0">Sin fotos.</p>';
document.querySelectorAll('.btn-detalle-flujo').forEach(button => button.addEventListener('click', () => {
  let d = {};
  try { d = JSON.parse(button.dataset.detail || '{}'); } catch (error) { return; }
  flowActual = d;
  document.getElementById('flowCodigoVentana').textContent = `Pedido o ticket ${d.codigo || '—'}`;
  const vencido = d.estado === 'Pendiente' && d.plazo_iso && new Date(d.plazo_iso) < new Date();
  document.getElementById('flowEstado').innerHTML = `<span class="badge ${flowEstadoClase(d.estado)}">${obsEscape(d.estado)}</span>`
    + (d.plazo ? `<span class="${vencido ? 'text-danger fw-semibold' : 'text-muted'}"><i class="bi bi-alarm me-1"></i>Plazo para regularizar: <strong>${obsEscape(d.plazo)}</strong></span>` : '');
  const dato = (etiqueta, valor) => `<div><dt>${etiqueta}</dt><dd>${obsEscape(valor || '—')}</dd></div>`;
  document.getElementById('flowDatos').innerHTML = dato('Pedido o ticket', d.codigo) + dato('Fecha de ingreso', d.fecha) + dato('Plazo para regularizar', d.plazo || 'No aplica')
    + dato('Partner', d.partner) + dato('Cuadrilla', d.cuadrilla) + dato('Departamento', [d.departamento, d.zona].filter(Boolean).join(' · ')) + dato('Supervisor', d.supervisor);
  document.getElementById('flowTitulo').textContent = d.titulo || 'Observación registrada';
  const grav = document.getElementById('flowGravedad');
  grav.textContent = d.gravedad || 'Sin gravedad';
  grav.className = 'badge ' + (d.gravedad === 'Media' ? 'bg-warning text-dark' : (d.gravedad ? 'bg-danger' : 'bg-secondary'));
  document.getElementById('flowObservacion').textContent = d.observacion || 'Sin descripción';
  document.getElementById('flowEvidencias').innerHTML = flowFotos(d.fotos || [], 'Evidencia');
  const paso = (icono, titulo, cuando, cuerpo, hecho) => `<li class="${hecho ? 'is-hecho' : ''}"><i class="bi ${icono}"></i><div><strong>${titulo}</strong>${cuando ? `<small class="text-muted ms-2">${obsEscape(cuando)}</small>` : ''}<div class="mt-1">${cuerpo}</div></div></li>`;
  document.getElementById('flowSeguimiento').innerHTML =
    paso('bi-flag', 'Observación registrada', d.fecha, `Por ${obsEscape(d.supervisor)}`, true)
    + paso('bi-chat-left-text', 'Respuesta del partner', d.fecha_respuesta,
        d.respuesta || (d.descargos || []).length
          ? `${d.respondido_por ? `<small class="d-block text-muted mb-1">Respondió: ${obsEscape(d.respondido_por)}</small>` : ''}<p class="mb-2">${obsEscape(d.respuesta || 'Sin comentario')}</p><div class="sgs-obs-fotos">${flowFotos(d.descargos || [], 'Levantamiento')}</div>`
          : '<span class="text-muted">Todavía no existe un descargo.</span>', Boolean(d.respuesta))
    + paso('bi-shield-check', 'Evaluación WIN', d.fecha_evaluacion, obsEscape(d.evaluacion || 'Todavía no existe una evaluación.'), Boolean(d.evaluacion));
  document.getElementById('flowEliminar').classList.toggle('d-none', !d.puede_eliminar);
  document.getElementById('flowAmpliar').classList.toggle('d-none', !d.puede_ampliar);
  document.getElementById('flowResponder').classList.toggle('d-none', !d.puede_responder);
  document.getElementById('flowAcciones').classList.toggle('d-none', !d.puede_eliminar && !d.puede_ampliar && !d.puede_responder);
  bootstrap.Modal.getOrCreateInstance(document.getElementById('modalFlowDetail')).show();
}));
document.getElementById('flowResponder')?.addEventListener('click', () => {
  if (!flowActual) return;
  bootstrap.Modal.getOrCreateInstance(document.getElementById('modalFlowDetail')).hide();
  abrirRespuestaPartner(flowActual.id, flowActual.codigo, flowActual.titulo);
});
document.getElementById('flowAmpliar')?.addEventListener('click', () => {
  if (!flowActual) return;
  bootstrap.Modal.getOrCreateInstance(document.getElementById('modalFlowDetail')).hide();
  document.getElementById('plazoObsId').value = flowActual.id;
  document.getElementById('plazoFecha').value = flowActual.plazo_iso;
  bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPlazo')).show();
});
document.getElementById('flowEliminar')?.addEventListener('click', () => {
  if (!flowActual) return;
  bootstrap.Modal.getOrCreateInstance(document.getElementById('modalFlowDetail')).hide();
  document.getElementById('eliminarObsCodigo').textContent = flowActual.codigo || '';
  document.getElementById('eliminarObsMotivo').value = '';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEliminarObs')).show();
});
document.getElementById('formEliminarObs')?.addEventListener('submit', async event => {
  event.preventDefault();
  if (!flowActual) return;
  const motivo = document.getElementById('eliminarObsMotivo').value.trim();
  if (motivo.length < 5) { window.showToast('Escribe un motivo de al menos 5 caracteres.', 'error'); return; }
  const boton = document.getElementById('eliminarObsConfirmar');
  boton.disabled = true;
  try {
    const response = await fetch('api/observaciones.php?action=eliminar', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken }, body: JSON.stringify({ observacion_id: flowActual.id, motivo }) });
    const result = await response.json();
    window.showToast(result.message || 'Listo.', result.status === 'success' ? 'success' : 'error');
    if (result.status === 'success') location.reload();
  } catch (error) { window.showToast('No se pudo eliminar la observación.', 'error'); }
  finally { boton.disabled = false; }
});
document.getElementById('modalFlowDetail')?.addEventListener('click',event=>{const button=event.target.closest('.flow-original-photo');if(!button)return;document.getElementById('observationPhotoImage').src=button.dataset.photo;document.getElementById('observationPhotoTitle').textContent=button.dataset.title||'Evidencia original';bootstrap.Modal.getOrCreateInstance(document.getElementById('modalObservationPhoto')).show();});
document.querySelectorAll('.btn-editar-plazo').forEach(button=>button.addEventListener('click',()=>{document.getElementById('plazoObsId').value=button.dataset.id;document.getElementById('plazoFecha').value=button.dataset.fecha;bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPlazo')).show();}));
document.getElementById('formPlazo')?.addEventListener('submit',async event=>{event.preventDefault();try{const response=await fetch('api/observaciones.php?action=actualizar_plazo',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},body:JSON.stringify({observacion_id:Number(document.getElementById('plazoObsId').value),fecha_limite_respuesta:document.getElementById('plazoFecha').value})});const result=await response.json();window.showToast(result.message||'Fecha procesada.',result.status==='success'?'success':'error');if(result.status==='success')location.reload()}catch(error){window.showToast('No se pudo actualizar la fecha de levantamiento.','error')}});
}
sgsVistaObservaciones();
