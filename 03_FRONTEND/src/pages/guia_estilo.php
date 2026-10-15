<?php
// 03_FRONTEND/src/pages/guia_estilo.php - Guía de diseño en vivo de SGS (solo administración).
// Muestra los tokens y componentes con las clases reales de sgs-theme.css. Si un componente cambia aquí, cambia en todo el sistema.
// Reglas completas: skill "sgs-diseno" (.claude/skills/sgs-diseno/SKILL.md) y 01_DOCUMENTACION/03_ARQUITECTURA/GUIA_VISUAL.md
$colores = [
    'Marca' => [
        ['--sgs-orange', '#FF5A0A', 'Acción principal, ítem activo, foco'],
        ['--sgs-orange-dark', '#D8450B', 'Hover de acción, enlaces, texto naranja'],
        ['--sgs-orange-soft', '#FFEFE6', 'Fondo de ícono o insignia de marca'],
    ],
    'Neutros' => [
        ['--sgs-ink', '#141414', 'Títulos y cifras'],
        ['--sgs-brown', '#301D19', 'Texto de lectura'],
        ['--sgs-muted', '#6B6D70', 'Texto secundario, etiquetas'],
        ['--sgs-border', '#EDE4DE', 'Bordes y divisores'],
        ['--sgs-surface-alt', '#FAF9F7', 'Encabezado de tabla, zonas suaves'],
        ['--sgs-bg', '#F6F4F2', 'Fondo de la aplicación'],
    ],
    'Estados (solo con significado)' => [
        ['--sgs-success', '#12A150', 'Correcto, activo, a tiempo'],
        ['--sgs-warning', '#FFC200', 'En plazo, atención'],
        ['--sgs-danger', '#E03131', 'Error, vencido, bloqueado'],
        ['--sgs-info', '#4A4D52', 'Neutro / informativo'],
    ],
];
?>
<style>
.gde-swatch{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--sgs-border)}
.gde-swatch:last-child{border-bottom:0}
.gde-chip{width:44px;height:44px;border-radius:12px;border:1px solid var(--sgs-border);flex:none}
.gde-token{font-family:ui-monospace,Consolas,monospace;font-size:.8rem;color:var(--sgs-ink)}
.gde-type-row{display:flex;align-items:baseline;gap:16px;padding:10px 0;border-bottom:1px solid var(--sgs-border)}
.gde-type-row:last-child{border-bottom:0}
.gde-type-meta{width:170px;flex:none;font-size:.78rem;color:var(--sgs-muted)}
.gde-type-row > :last-child{flex:1;min-width:0;margin:0}
.gde-rule{display:flex;gap:10px;padding:8px 0}
.gde-rule i{font-size:1.1rem;flex:none}
.gde-do{color:var(--sgs-success-dark)} .gde-dont{color:var(--sgs-danger-dark)}
</style>

<div class="container-fluid p-3 p-md-4">
  <div class="sgs-page-actions">
    <span class="sgs-counter"><i class="bi bi-palette"></i>Sistema de diseño v1.0</span>
  </div>

  <div class="row g-4">
    <!-- Colores -->
    <div class="col-lg-6">
      <div class="card p-3 p-md-4 h-100">
        <h5 class="mb-1"><i class="bi bi-droplet-half me-2 text-primary"></i>Colores</h5>
        <p class="text-muted small mb-3">Siempre con su variable, nunca con el código #hex en las pantallas.</p>
        <?php foreach ($colores as $grupo => $items): ?>
          <div class="small fw-bold text-muted text-uppercase mt-3 mb-1" style="letter-spacing:.05em"><?= htmlspecialchars($grupo) ?></div>
          <?php foreach ($items as [$var, $hex, $uso]): ?>
            <div class="gde-swatch">
              <span class="gde-chip" style="background:var(<?= $var ?>)"></span>
              <div><div class="gde-token">var(<?= $var ?>) · <?= $hex ?></div><div class="small text-muted"><?= htmlspecialchars($uso) ?></div></div>
            </div>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Tipografía -->
    <div class="col-lg-6">
      <div class="card p-3 p-md-4 mb-4">
        <h5 class="mb-1"><i class="bi bi-fonts me-2 text-primary"></i>Tipografía</h5>
        <p class="text-muted small mb-3">Una sola letra: Nunito (texto 500, etiquetas y botones 600, títulos y cifras 700; nunca más de 700). Estilo de oración, sin mayúsculas en cada palabra.</p>
        <div class="gde-type-row"><span class="gde-type-meta">Título de pantalla<br>barra superior · 22 px · 800</span><span style="font-family:var(--sgs-font-display);font-weight: 700;font-size:1.35rem;color:var(--sgs-ink)">Línea de tiempo</span></div>
        <div class="gde-type-row"><span class="gde-type-meta">Título de tarjeta<br>h5 · 16 px · 800</span><h5 class="mb-0">Actividad del día</h5></div>
        <div class="gde-type-row"><span class="gde-type-meta">Cifra de indicador<br>Nunito 700 · 24 px</span><span class="sgs-kpi-value" style="font-size:1.55rem">1 284</span></div>
        <div class="gde-type-row"><span class="gde-type-meta">Texto<br>Nunito · 15 px</span><span>Las órdenes se bloquean a las 24 h sin atención.</span></div>
        <div class="gde-type-row"><span class="gde-type-meta">Etiqueta / encabezado<br>12 px · mayúsculas</span><small class="sgs-kpi-label">Supervisiones del mes</small></div>
      </div>

      <div class="card p-3 p-md-4">
        <h5 class="mb-3"><i class="bi bi-check2-square me-2 text-primary"></i>Reglas rápidas</h5>
        <div class="gde-rule"><i class="bi bi-check-circle-fill gde-do"></i><span>El naranja marca la <strong>acción principal</strong> y lo <strong>activo</strong>. Una sola acción naranja por zona.</span></div>
        <div class="gde-rule"><i class="bi bi-check-circle-fill gde-do"></i><span>Verde, amarillo y rojo solo cuando el dato <strong>significa</strong> eso (a tiempo, en plazo, vencido).</span></div>
        <div class="gde-rule"><i class="bi bi-check-circle-fill gde-do"></i><span>Íconos solo de Bootstrap Icons. Sin dato: <strong>—</strong>.</span></div>
        <div class="gde-rule"><i class="bi bi-x-circle-fill gde-dont"></i><span>Sin emojis, sin "Mayúscula En Cada Palabra", sin opciones "-- Seleccione --".</span></div>
        <div class="gde-rule"><i class="bi bi-x-circle-fill gde-dont"></i><span>Sin colores #hex ni estilos escritos en el HTML de las pantallas; sin otras fuentes.</span></div>
      </div>
    </div>

    <!-- Indicadores -->
    <div class="col-12">
      <div class="card p-3 p-md-4">
        <h5 class="mb-1"><i class="bi bi-speedometer2 me-2 text-primary"></i>Indicadores</h5>
        <p class="text-muted small mb-3">Tarjeta neutra. El punto de color aparece solo si el valor tiene un estado. Ícono opcional en la esquina.</p>
        <div class="row g-3">
          <div class="col-6 col-lg-3"><div class="sgs-kpi p-3 h-100"><small class="sgs-kpi-label">Usuarios activos</small><h3 class="mb-0 sgs-kpi-value">11</h3></div></div>
          <div class="col-6 col-lg-3"><div class="sgs-kpi sgs-kpi--success p-3 h-100"><span class="sgs-kpi-icon"><i class="bi bi-check2-circle"></i></span><small class="sgs-kpi-label">Atendidas a tiempo</small><h3 class="mb-0 sgs-kpi-value">42</h3></div></div>
          <div class="col-6 col-lg-3"><div class="sgs-kpi sgs-kpi--warning p-3 h-100"><span class="sgs-kpi-icon"><i class="bi bi-hourglass-split"></i></span><small class="sgs-kpi-label">En plazo</small><h3 class="mb-0 sgs-kpi-value">7</h3></div></div>
          <div class="col-6 col-lg-3"><div class="sgs-kpi sgs-kpi--danger p-3 h-100"><span class="sgs-kpi-icon"><i class="bi bi-lock"></i></span><small class="sgs-kpi-label">Bloqueadas</small><h3 class="mb-0 sgs-kpi-value">2</h3></div></div>
        </div>
      </div>
    </div>

    <!-- Botones e insignias -->
    <div class="col-lg-6">
      <div class="card p-3 p-md-4 h-100">
        <h5 class="mb-3"><i class="bi bi-hand-index me-2 text-primary"></i>Botones</h5>
        <div class="d-flex flex-wrap gap-2 mb-3">
          <button type="button" class="btn btn-win"><i class="bi bi-plus-lg me-1"></i>Nuevo usuario</button>
          <button type="button" class="btn btn-outline-secondary"><i class="bi bi-funnel me-1"></i>Filtrar</button>
          <button type="button" class="btn btn-outline-secondary"><i class="bi bi-x-lg me-1"></i>Limpiar</button>
          <button type="button" class="btn btn-outline-danger"><i class="bi bi-trash me-1"></i>Eliminar</button>
        </div>
        <div class="small text-muted">Principal (naranja) · Secundario (blanco con borde) · Peligro (borde rojo). Verbo + objeto: "Guardar foto", no "Aceptar".</div>
        <hr>
        <h5 class="mb-3"><i class="bi bi-tag me-2 text-primary"></i>Insignias de estado</h5>
        <div class="d-flex flex-wrap gap-2">
          <span class="badge bg-success">Activo</span><span class="badge bg-warning">En plazo</span><span class="badge bg-danger">Vencida</span>
          <span class="badge bg-primary">Supervisor</span><span class="badge bg-dark">Administrador</span><span class="badge bg-secondary">Inactivo</span>
        </div>
      </div>
    </div>

    <!-- Formulario -->
    <div class="col-lg-6">
      <div class="card p-3 p-md-4 h-100">
        <h5 class="mb-3"><i class="bi bi-ui-radios me-2 text-primary"></i>Formularios y filtros</h5>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label" for="gdeDep">Departamento</label><select class="form-select" id="gdeDep"><option value="">Todos los departamentos</option><option>PIURA</option></select></div>
          <div class="col-md-6"><label class="form-label" for="gdeFecha">Desde</label><input class="form-control" type="date" id="gdeFecha"></div>
          <div class="col-12"><label class="form-label" for="gdeTxt">Buscar</label><input class="form-control" id="gdeTxt" placeholder="Nombre, usuario o DNI"></div>
        </div>
        <div class="small text-muted mt-3">Etiqueta siempre visible arriba del campo. Opción vacía: "Todos los…" o "Seleccione…".</div>
      </div>
    </div>

    <!-- Tabla -->
    <div class="col-12">
      <div class="card overflow-hidden">
        <div class="p-3 p-md-4 pb-0"><h5 class="mb-1"><i class="bi bi-table me-2 text-primary"></i>Tabla</h5><p class="text-muted small">Encabezado neutro en mayúsculas pequeñas; acciones a la derecha con botones de ícono. Lista larga: <code>table-responsive sgs-tabla-alta</code> (alto máximo con encabezado fijo).</p></div>
        <div class="table-responsive">
          <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Código</th><th>Supervisor</th><th>Departamento</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
            <tbody>
              <tr><td>SUP-00128</td><td>Angelo Palomino</td><td>JUNÍN</td><td><span class="badge bg-success">Finalizada</span></td><td class="text-end"><button type="button" class="btn btn-sm btn-outline-secondary" aria-label="Ver"><i class="bi bi-eye"></i></button></td></tr>
              <tr><td>SUP-00129</td><td>Enzo Ayllón</td><td>ÁNCASH</td><td><span class="badge bg-warning">En proceso</span></td><td class="text-end"><button type="button" class="btn btn-sm btn-outline-secondary" aria-label="Ver"><i class="bi bi-eye"></i></button></td></tr>
            </tbody>
          </table>
        </div>
        <div class="text-center text-muted py-4 border-top"><i class="bi bi-inbox fs-3 d-block mb-1"></i>Sin registros: así se ve el estado vacío.</div>
      </div>
    </div>
  </div>
</div>
