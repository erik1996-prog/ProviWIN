<?php
// 03_FRONTEND/src/pages/mi_informacion.php - Perfil del usuario y su actividad según el rol.
//   Supervisor directo WIN: turno de hoy y supervisiones (seguimiento).
//   Supervisor de partner: órdenes asignadas y cumplimiento del plazo de 24 h (único indicador que se le mide).
Auth::requireLogin();
$sessionUser = Auth::getLoggedInUser();
$profileUserId = (int)$sessionUser['id'];

$pdo = Database::getConnection();
$stmt = $pdo->prepare("SELECT u.*, p.nombre_partner FROM usuarios u LEFT JOIN partners p ON p.id = u.partner_id WHERE u.id = :id LIMIT 1");
$stmt->execute([':id' => $profileUserId]);
$profile = $stmt->fetch() ?: [];

$fullName = trim((string)($profile['nombre_completo'] ?? '')) ?: trim(($profile['nombres'] ?? '') . ' ' . ($profile['apellidos'] ?? ''));
$initials = mb_strtoupper(mb_substr(trim((string)($profile['nombres'] ?? $fullName)), 0, 1) . mb_substr(trim((string)($profile['apellidos'] ?? '')), 0, 1));
$avatar = trim((string)($profile['avatar'] ?? ''));
$hasAvatar = $avatar !== '' && !str_contains($avatar, 'default-avatar') && is_file(SHARED_FILES_PATH . '/' . $avatar);
$rolPerfil = (string)($profile['rol'] ?? '');
$partnerName = trim((string)($profile['nombre_partner'] ?? ''));
$isDirectStaff = $partnerName === '' || in_array(mb_strtoupper($partnerName), ['WIN', 'WIN DIRECTO'], true);
$organizacion = $isDirectStaff ? 'WIN · Personal directo' : $partnerName . ' · Partner';
$isSupervisor = $rolPerfil === Auth::ROLE_SUPERVISOR;
$isDirectSupervisor = $isSupervisor && $isDirectStaff;
$isPartnerSupervisor = ($isSupervisor && !$isDirectStaff) || $rolPerfil === Auth::ROLE_JEFE_OPERACIONES;
$activo = !empty($profile['activo']);

$departamento = mb_strtoupper(operationalDepartmentFromSite((string)(($profile['sede'] ?? '') ?: ($profile['zona'] ?? ''))), 'UTF-8');
$todasZonas = $departamento === 'PROVINCIAS';
if ($todasZonas) $departamento = '';
$miembroDesde = !empty($profile['fecha_creacion']) ? date('d/m/Y', strtotime((string)$profile['fecha_creacion'])) : '';

// Zonas de trabajo: departamento base + accesos adicionales otorgados
$zonas = $departamento !== '' ? [$departamento] : [];
try {
    $stZ = $pdo->prepare("SELECT departamento FROM usuario_departamentos_acceso WHERE usuario_id = :id AND activo = 1 ORDER BY departamento");
    $stZ->execute([':id' => $profileUserId]);
    foreach ($stZ->fetchAll(PDO::FETCH_COLUMN) as $z) if (!in_array(mb_strtoupper($z), array_map('mb_strtoupper', $zonas), true)) $zonas[] = $z;
} catch (Throwable $e) { win_log_error('perfil_zonas_error', $e->getMessage()); }

// Actividad según el rol (solo lectura)
$actividad = null;
try {
    if ($isDirectSupervisor) {
        $stT = $pdo->prepare("SELECT estado_turno, inicio_turno, fin_turno FROM asistencia_supervisores WHERE supervisor_id = :id AND fecha = CURRENT_DATE() LIMIT 1");
        $stT->execute([':id' => $profileUserId]);
        $turno = $stT->fetch() ?: null;
        $stS = $pdo->prepare("SELECT
                SUM(DATE(fecha_registro) = CURRENT_DATE()) AS hoy,
                COUNT(*) AS mes,
                SUM(estado_supervision = 'Finalizada') AS finalizadas
            FROM supervisiones
            WHERE supervisor_id = :id AND fecha_registro >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')");
        $stS->execute([':id' => $profileUserId]);
        $sup = $stS->fetch() ?: [];
        if (!$turno) { $turnoTxt = 'Sin iniciar'; $turnoCls = 'sgs-kpi--danger'; $turnoSub = 'Necesario para supervisar'; }
        elseif (!empty($turno['fin_turno'])) { $turnoTxt = 'Finalizado'; $turnoCls = 'sgs-kpi--info'; $turnoSub = 'De ' . date('H:i', strtotime($turno['inicio_turno'])) . ' a ' . date('H:i', strtotime($turno['fin_turno'])); }
        else { $turnoTxt = (string)($turno['estado_turno'] ?: 'En turno'); $turnoCls = 'sgs-kpi--success'; $turnoSub = 'Desde las ' . date('H:i', strtotime($turno['inicio_turno'])); }
        $actividad = [
            'titulo' => 'Mi actividad', 'icono' => 'bi-activity',
            'nota' => 'Como personal directo de WIN, tu turno y tus supervisiones forman parte del seguimiento diario.',
            'kpis' => [
                ['Turno de hoy', $turnoTxt, $turnoCls, $turnoSub],
                ['Supervisiones hoy', (int)($sup['hoy'] ?? 0), 'sgs-kpi--warning', 'Registradas por ti'],
                ['Supervisiones del mes', (int)($sup['mes'] ?? 0), 'sgs-kpi--info', date('m/Y')],
                ['Finalizadas del mes', (int)($sup['finalizadas'] ?? 0), 'sgs-kpi--success', 'Con cierre definitivo'],
            ],
            'accion' => ['index.php?page=asistencia', 'bi-play-circle', 'Ir a mi turno'],
        ];
    } elseif ($isPartnerSupervisor) {
        $lim = 'DATE_ADD(fecha_asignacion, INTERVAL 24 HOUR)';
        $stP = $pdo->prepare("SELECT
                SUM(estado_atencion_partner <> 'Atendida' AND estado_supervision = 'Pendiente' AND NOW() <= {$lim}) AS en_plazo,
                SUM(estado_atencion_partner <> 'Atendida' AND NOW() > {$lim}) AS bloqueadas,
                SUM(estado_atencion_partner = 'Atendida' AND fecha_atencion_partner <= {$lim}) AS a_tiempo
            FROM supervisiones
            WHERE supervisor_id = :id AND partner_id = :partner AND fecha_asignacion IS NOT NULL
              AND fecha_asignacion >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')");
        $stP->execute([':id' => $profileUserId, ':partner' => (int)($profile['partner_id'] ?? 0)]);
        $pm = array_map('intval', $stP->fetch() ?: []);
        $cerradas = ($pm['a_tiempo'] ?? 0) + ($pm['bloqueadas'] ?? 0);
        $pct = $cerradas > 0 ? round(($pm['a_tiempo'] ?? 0) * 100 / $cerradas) : null;
        $actividad = [
            'titulo' => 'Mi cumplimiento del mes', 'icono' => 'bi-stopwatch',
            'nota' => 'Se te evalúa por el tiempo de respuesta: cada orden asignada debe atenderse dentro de las 24 horas; si vence, se bloquea y cuenta como incumplimiento.',
            'kpis' => [
                ['Por atender (en plazo)', $pm['en_plazo'] ?? 0, 'sgs-kpi--warning', 'Antes de que venzan las 24 h'],
                ['Atendidas a tiempo', $pm['a_tiempo'] ?? 0, 'sgs-kpi--success', 'Dentro del plazo'],
                ['Bloqueadas', $pm['bloqueadas'] ?? 0, 'sgs-kpi--danger', 'Incumplimientos'],
                ['Cumplimiento', $pct === null ? '—' : $pct . '%', $pct === null ? 'sgs-kpi--info' : ($pct >= 90 ? 'sgs-kpi--success' : ($pct >= 70 ? 'sgs-kpi--warning' : 'sgs-kpi--danger')), date('m/Y')],
            ],
            'accion' => ['index.php?page=supervision_lista', 'bi-calendar-check', 'Ir a mi agenda'],
        ];
    }
} catch (Throwable $e) {
    win_log_error('perfil_actividad_error', $e->getMessage(), ['user_id' => $profileUserId]);
}

$grupos = [
    ['Datos personales', 'bi-person', [
        ['Nombres', $profile['nombres'] ?? ''], ['Apellidos', $profile['apellidos'] ?? ''], ['DNI', $profile['dni'] ?? ''],
    ]],
    ['Contacto', 'bi-telephone', [
        ['Correo electrónico', $profile['email'] ?? '', 'mailto'],
        ['Teléfono personal', $profile['telefono_personal'] ?? '', 'tel'],
        ['Teléfono corporativo', $profile['telefono_corporativo'] ?? '', 'tel'],
    ]],
    ['Datos laborales', 'bi-briefcase', [
        ['Usuario', $profile['usuario'] ?? ''], ['Rol', $rolPerfil], ['Organización', $organizacion],
        ['Área de trabajo', $profile['area_trabajo'] ?? ''], 
        ['Modalidad', $profile['modalidad'] ?? ''], ['Turno', $profile['turno'] ?? ''],
    ]],
];
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<style>
.sgs-profile-page{max-width:1180px;margin:0 auto}
.sgs-pcard{position:relative;overflow:hidden;text-align:center;padding:28px 22px 22px !important}
.sgs-pcard-band{display:none} /* 2026-10-15: sin franja con degradado (se veía recargado) */
.sgs-pcard-photo{position:relative;width:88px;margin:0 auto 14px}
.sgs-pcard-photo .sgs-pcard-avatar{margin:0}
.sgs-pcard-camera{position:absolute;right:-4px;bottom:0;width:32px;height:32px;border-radius:50%;border:3px solid #fff;background:var(--sgs-ink,#141414);color:#fff;display:grid;place-items:center;padding:0;font-size:1rem;cursor:pointer;box-shadow:0 4px 10px rgba(48,29,25,.25)}
.sgs-pcard-camera:hover,.sgs-pcard-camera:focus-visible{background:var(--sgs-orange);outline:none}
.sgs-pcard-camera:disabled{opacity:.6;cursor:wait}
.sgs-pcard-photo-actions{text-align:center;min-height:1.5rem;font-size:.85rem}
.sgs-pcard-photo-actions .btn-link{font-size:.85rem;padding:0;color:var(--sgs-muted,#7C7E80)}
.sgs-pcard-avatar{width:88px;height:88px;margin:0 auto 14px;border-radius:50%;border:3px solid var(--sgs-orange-soft);background:var(--sgs-orange-soft);color:var(--sgs-orange-dark);display:grid;place-items:center;font-family:var(--sgs-font);font-weight:700;font-size:1.6rem;object-fit:cover}
#main-content-area .sgs-pcard-name{font-size:17px !important;margin:0 0 6px;overflow-wrap:anywhere}
.sgs-pcard-chips{display:flex;flex-wrap:wrap;justify-content:center;gap:6px;margin-bottom:14px}
.sgs-chip{display:inline-flex;align-items:center;gap:6px;padding:4px 11px;border-radius:30px;font-size:.76rem;font-weight: 700;border:1px solid var(--sgs-border);background:var(--sgs-surface-alt);color:var(--sgs-ink-2)}
.sgs-chip--brand{background:var(--sgs-orange-soft);border-color:var(--sgs-orange-border);color:var(--sgs-orange-dark)}
.sgs-chip--ok{background:var(--sgs-success-soft);border-color:#BFE6CD;color:var(--sgs-success-dark)}
.sgs-chip--off{background:var(--sgs-surface-alt);color:var(--sgs-muted)}
.sgs-chip--ok::before,.sgs-chip--off::before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}
.sgs-pcard-contact{display:grid;gap:8px;margin:4px 0 16px}
.sgs-pcard-contact .btn{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block}
.sgs-pcard-meta{display:flex;justify-content:space-between;gap:10px;padding-top:14px;border-top:1px solid var(--sgs-border);font-size:.8rem;color:var(--sgs-muted)}
.sgs-pcard-meta strong{color:var(--sgs-ink-2)}
.sgs-section-head{display:flex;align-items:center;gap:10px;margin-bottom:14px}
.sgs-section-head>i{width:34px;height:34px;display:grid;place-items:center;border-radius:10px;background:var(--sgs-orange-soft);color:var(--sgs-orange);font-size:1rem;flex:none}
#main-content-area .sgs-section-head h2{margin:0;font-size:1rem !important}
.sgs-field{padding:10px 0;border-bottom:1px dashed var(--sgs-border)}
.sgs-field small{display:block;color:var(--sgs-muted);font-size:.72rem;font-weight: 700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px}
.sgs-field span{display:block;color:var(--sgs-ink);font-weight:700;font-size:.92rem;overflow-wrap:anywhere}
.sgs-field span.is-empty{color:var(--sgs-muted-2);font-weight:600;font-style:italic}
.sgs-field a{color:var(--sgs-orange-dark);font-weight:700;text-decoration:none}
.sgs-field a:hover{text-decoration:underline}
.sgs-zonas{display:flex;flex-wrap:wrap;gap:8px}
.sgs-activity-note{display:flex;gap:10px;align-items:flex-start;margin-top:14px;font-size:.84rem;color:var(--sgs-muted)}
.sgs-activity-note i{color:var(--sgs-orange);margin-top:2px}
.sgs-kpi small.sgs-kpi-sub{display:block;margin-top:4px;color:var(--sgs-muted);font-size:.74rem;font-weight:600}
.sgs-kpi h3.sgs-kpi-value.is-text{font-size:1.15rem !important}
</style>

<div class="container-fluid p-3 p-md-4">
<div class="sgs-profile-page">
  

  <div class="row g-4">
    <!-- Tarjeta de perfil -->
    <div class="col-lg-4">
      <div class="card sgs-pcard h-100">
        <div class="sgs-pcard-band" aria-hidden="true"></div>
        <div class="sgs-pcard-photo">
          <?php if ($hasAvatar): ?>
            <img class="sgs-pcard-avatar" id="pcAvatar" src="<?= $e($avatar) ?>" alt="Foto de perfil de <?= $e($fullName) ?>" decoding="async">
          <?php else: ?>
            <div class="sgs-pcard-avatar" id="pcAvatar" aria-hidden="true"><?= $e($initials ?: 'U') ?></div>
          <?php endif; ?>
          
          <button type="button" class="sgs-pcard-camera" id="btnFoto" title="Cambiar foto" aria-label="Cambiar foto de perfil"><i class="bi bi-camera-fill"></i></button>
          <input type="file" id="inpFoto" accept="image/jpeg,image/png,image/webp" data-sin-comprimir hidden>
          
        </div>
        
        <div class="sgs-pcard-photo-actions" id="fotoAcciones">
          <button type="button" class="btn btn-link<?= $hasAvatar ? '' : ' d-none' ?>" id="btnQuitarFoto">Quitar foto</button>
          <div class="small" id="fotoMsg" role="status"></div>
        </div>
        
        <h3 class="sgs-pcard-name"><?= $e($fullName ?: 'Usuario') ?></h3>
        <div class="sgs-pcard-chips">
          <span class="sgs-chip sgs-chip--brand"><i class="bi bi-person-badge"></i><?= $e($rolPerfil ?: 'Sin rol') ?></span>
          <span class="sgs-chip"><i class="bi bi-building"></i><?= $e($isDirectStaff ? 'WIN Directo' : $partnerName) ?></span>
          <span class="sgs-chip <?= $activo ? 'sgs-chip--ok' : 'sgs-chip--off' ?>"><?= $activo ? 'Cuenta activa' : 'Cuenta inactiva' ?></span>
        </div>
        <div class="sgs-pcard-contact">
          <?php if (!empty($profile['email'])): ?><a class="btn btn-outline-secondary w-100" href="mailto:<?= $e($profile['email']) ?>"><i class="bi bi-envelope"></i><?= $e($profile['email']) ?></a><?php endif; ?>
          <?php $tel = trim((string)($profile['telefono_corporativo'] ?? '')) ?: trim((string)($profile['telefono_personal'] ?? '')); ?>
          <?php if ($tel !== ''): ?><a class="btn btn-outline-secondary w-100" href="tel:<?= $e(preg_replace('/[^0-9+]/', '', $tel)) ?>"><i class="bi bi-telephone"></i><?= $e($tel) ?></a><?php endif; ?>
        </div>
        <div class="sgs-pcard-meta mt-auto">
          <span>Usuario<br><strong><?= $e($profile['usuario'] ?? '—') ?></strong></span>
          <span class="text-end">Miembro desde<br><strong><?= $e($miembroDesde ?: '—') ?></strong></span>
        </div>
      </div>
    </div>

    <div class="col-lg-8 d-flex flex-column gap-4">
      <?php if ($actividad): ?>
      <!-- Actividad según el rol -->
      <div class="card p-3 p-md-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
          <div class="sgs-section-head mb-0"><i class="bi <?= $actividad['icono'] ?>"></i><h2><?= $e($actividad['titulo']) ?></h2></div>
          <a class="btn btn-win btn-sm" href="<?= $actividad['accion'][0] ?>"><i class="bi <?= $actividad['accion'][1] ?>"></i><?= $e($actividad['accion'][2]) ?></a>
        </div>
        <div class="row g-3">
          <?php foreach ($actividad['kpis'] as [$label, $valor, $cls, $sub]): ?>
          <div class="col-6 col-xl-3">
            <div class="sgs-kpi <?= $cls ?> p-3 h-100">
              <small class="sgs-kpi-label"><?= $e($label) ?></small>
              <h3 class="mb-0 sgs-kpi-value <?= is_numeric($valor) || str_ends_with((string)$valor, '%') || $valor === '—' ? '' : 'is-text' ?>"><?= $e($valor) ?></h3>
              <small class="sgs-kpi-sub"><?= $e($sub) ?></small>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="sgs-activity-note"><i class="bi bi-info-circle"></i><span><?= $e($actividad['nota']) ?></span></div>
      </div>
      <?php endif; ?>

      <!-- Datos agrupados -->
      <div class="card p-3 p-md-4">
        <div class="row g-4">
          <?php foreach ($grupos as $i => [$titulo, $icono, $campos]): ?>
          <div class="<?= $i === 2 ? 'col-12' : 'col-md-6' ?>">
            <div class="sgs-section-head"><i class="bi <?= $icono ?>"></i><h2><?= $e($titulo) ?></h2></div>
            <div class="row g-0 column-gap-4">
              <?php foreach ($campos as $campo): [$label, $valor] = $campo; $tipo = $campo[2] ?? null; $valor = trim((string)$valor); ?>
              <div class="<?= $i === 2 ? 'col-sm-6 col-xl' : 'col-12' ?> <?= $i === 2 ? 'sgs-field-col' : '' ?>" style="<?= $i === 2 ? 'flex:1 1 30%;' : '' ?>">
                <div class="sgs-field">
                  <small><?= $e($label) ?></small>
                  <?php if ($valor === ''): ?>
                    <span class="is-empty">No registrado</span>
                  <?php elseif ($tipo === 'mailto'): ?>
                    <span><a href="mailto:<?= $e($valor) ?>"><?= $e($valor) ?></a></span>
                  <?php elseif ($tipo === 'tel'): ?>
                    <span><a href="tel:<?= $e(preg_replace('/[^0-9+]/', '', $valor)) ?>"><?= $e($valor) ?></a></span>
                  <?php else: ?>
                    <span><?= $e($valor) ?></span>
                  <?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <?php if ($isSupervisor && ($zonas || $todasZonas)): ?>
        <div class="mt-4">
          <div class="sgs-section-head"><i class="bi bi-geo-alt"></i><h2>Zonas de trabajo</h2></div>
          <div class="sgs-zonas">
            <?php if ($todasZonas): ?><span class="sgs-chip sgs-chip--brand"><i class="bi bi-globe-americas"></i>Todas las zonas</span><?php endif; ?>
            <?php foreach ($zonas as $zona): ?><span class="sgs-chip sgs-chip--brand"><i class="bi bi-pin-map"></i><?= $e(mb_strtoupper($zona, 'UTF-8')) ?></span><?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <div class="sgs-activity-note mt-4 pt-3 border-top"><i class="bi bi-shield-lock"></i><span>Estos datos son de consulta. Para corregir información personal, comunícate con el administrador del sistema. Tu foto la cambias con el botón de cámara. Tu contraseña la puedes cambiar abajo.</span></div>
      </div>
      
      <!-- Cambiar contraseña (cuenta propia) -->
      <div class="card p-3 p-md-4" id="cardPassword">
        <div class="sgs-section-head"><i class="bi bi-key"></i><h2>Cambiar contraseña</h2></div>
        <form id="formPassword" class="row g-3 align-items-end" novalidate autocomplete="off">
          <div class="col-md-4"><label class="form-label" for="pwActual">Contraseña actual</label><input class="form-control" type="password" id="pwActual" name="actual" autocomplete="current-password" required></div>
          <div class="col-md-4"><label class="form-label" for="pwNueva">Nueva contraseña</label><input class="form-control" type="password" id="pwNueva" name="nueva" autocomplete="new-password" required><div data-password-meter="pwNueva"></div></div>
          <div class="col-md-4"><label class="form-label" for="pwConfirma">Repite la nueva</label><input class="form-control" type="password" id="pwConfirma" autocomplete="new-password" required></div>
          <div class="col-md-8"><small class="text-muted">Mínimo 10 caracteres, con letras y números. Al cambiarla, la anterior deja de funcionar.</small></div>
          <div class="col-md-4"><button class="btn btn-win w-100" type="submit" id="btnPassword"><i class="bi bi-check2"></i>Actualizar contraseña</button></div>
          <div class="col-12"><div class="alert d-none mb-0" id="pwMsg" role="alert"></div></div>
        </form>
      </div>
      <script>
      (() => {
        const f = document.getElementById('formPassword'), msg = document.getElementById('pwMsg'), btn = document.getElementById('btnPassword');
        const show = (text, ok) => { msg.textContent = text; msg.className = 'alert mb-0 ' + (ok ? 'alert-success' : 'alert-danger'); };
        f.addEventListener('submit', async (e) => {
          e.preventDefault();
          if (f.nueva.value !== document.getElementById('pwConfirma').value) return show('Las contraseñas nuevas no coinciden.', false);
          btn.disabled = true;
          try {
            const res = await fetch('api/usuarios.php?action=cambiar_password_propia', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ actual: f.actual.value, nueva: f.nueva.value }) });
            const data = await res.json().catch(() => ({}));
            if (data.status === 'success') { show(data.message, true); f.reset(); f.nueva.dispatchEvent(new Event('input')); } else show(data.message || 'No se pudo cambiar la contraseña.', false);
          } catch (_) { show('No hay conexión con el servidor.', false); }
          finally { btn.disabled = false; }
        });
      })();
      </script>
      
    </div>
  </div>
</div>
</div>

<!-- Editor de foto: mover (arrastrar), zoom, girar; vista previa circular -->
<style>
.sgs-crop-stage{position:relative;width:min(300px,72vw);aspect-ratio:1;margin:0 auto;border-radius:16px;overflow:hidden;background:#2a2a2a;touch-action:none;cursor:grab;user-select:none}
.sgs-crop-stage.is-dragging{cursor:grabbing}
.sgs-crop-stage canvas{width:100%;height:100%;display:block}
.sgs-crop-mask{position:absolute;inset:6%;border-radius:50%;box-shadow:0 0 0 999px rgba(20,20,20,.55);border:2px solid rgba(255,255,255,.9);pointer-events:none}
.sgs-crop-tools{display:flex;align-items:center;gap:10px;max-width:340px;margin:16px auto 0}
.sgs-crop-tools .form-range{flex:1}
.sgs-crop-tools .btn{width:40px;height:40px;padding:0;display:grid;place-items:center;border-radius:50%}
.sgs-crop-hint{text-align:center;font-size:.82rem;color:var(--sgs-muted,#7C7E80);margin-top:10px}
</style>
<div class="modal fade" id="mFoto" tabindex="-1" aria-labelledby="mFotoTitulo" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="mFotoTitulo"><i class="bi bi-crop me-2"></i>Ajustar foto</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="sgs-crop-stage" id="cropStage"><canvas id="cropCanvas"></canvas><div class="sgs-crop-mask"></div></div>
        <div class="sgs-crop-tools">
          <button type="button" class="btn btn-outline-secondary" id="cropMenos" title="Alejar" aria-label="Alejar"><i class="bi bi-zoom-out"></i></button>
          <input type="range" class="form-range" id="cropZoom" min="1" max="4" step="0.01" value="1" aria-label="Zoom">
          <button type="button" class="btn btn-outline-secondary" id="cropMas" title="Acercar" aria-label="Acercar"><i class="bi bi-zoom-in"></i></button>
          <button type="button" class="btn btn-outline-secondary" id="cropGirar" title="Girar" aria-label="Girar 90 grados"><i class="bi bi-arrow-clockwise"></i></button>
        </div>
        <div class="sgs-crop-hint">Arrastra la imagen para encuadrarla. Acerca con el zoom, la rueda del mouse o con dos dedos.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary me-auto" id="cropOtra"><i class="bi bi-image me-1"></i>Otra imagen</button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-win" id="cropGuardar"><i class="bi bi-check2 me-1"></i>Guardar foto</button>
      </div>
    </div>
  </div>
</div>
<script>
// Foto de perfil: el usuario encuadra la imagen; se exporta un cuadrado de 512 px en JPEG (pesa poco y sin datos ocultos como el GPS).
(() => {
  const $ = (id) => document.getElementById(id);
  const btn = $('btnFoto'), inp = $('inpFoto'), quitar = $('btnQuitarFoto'), msg = $('fotoMsg');
  if (!btn || !inp) return;
  const stage = $('cropStage'), canvas = $('cropCanvas'), zoomIn = $('cropZoom'), guardar = $('cropGuardar');
  const OUT = 512;
  const st = { img: null, rot: 0, zoom: 1, ox: 0, oy: 0 };
  const say = (t, ok) => { msg.textContent = t; msg.className = 'small ' + (ok ? 'text-success' : 'text-danger'); };
  const modal = () => bootstrap.Modal.getOrCreateInstance($('mFoto'));

  const setAvatar = (url) => {
    if (!url) return location.reload();
    const img = document.createElement('img');
    img.className = 'sgs-pcard-avatar'; img.id = 'pcAvatar'; img.alt = 'Foto de perfil'; img.src = url;
    $('pcAvatar').replaceWith(img);
    // Menú lateral: misma foto sin recargar
    document.querySelectorAll('.sgs-avatar').forEach((a) => {
      const cur = a.querySelector('img, span'); if (!cur) return;
      const i = document.createElement('img'); i.src = url; i.alt = ''; cur.replaceWith(i);
    });
  };

  // Geometría: la imagen (girada) siempre cubre todo el cuadro; ox/oy = desplazamiento del centro en px del cuadro.
  const size = () => stage.clientWidth;
  const dims = () => (st.rot % 180 ? [st.img.naturalHeight, st.img.naturalWidth] : [st.img.naturalWidth, st.img.naturalHeight]);
  const scaleFor = (S) => { const [w, h] = dims(); return Math.max(S / w, S / h) * st.zoom; };
  const clamp = () => {
    const S = size(), [w, h] = dims(), k = scaleFor(S);
    const mx = Math.max(0, (w * k - S) / 2), my = Math.max(0, (h * k - S) / 2);
    st.ox = Math.min(mx, Math.max(-mx, st.ox)); st.oy = Math.min(my, Math.max(-my, st.oy));
  };
  const paint = (ctx, S, factor) => {
    const k = scaleFor(S) * factor;
    ctx.save(); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, S * factor, S * factor);
    ctx.translate((S / 2 + st.ox) * factor, (S / 2 + st.oy) * factor);
    ctx.rotate(st.rot * Math.PI / 180); ctx.scale(k, k);
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(st.img, -st.img.naturalWidth / 2, -st.img.naturalHeight / 2);
    ctx.restore();
  };
  const render = () => {
    if (!st.img) return;
    // Resolución real del lienzo = tamaño en pantalla × densidad de píxeles (ancho Y alto; el lienzo nace en 300×150).
    const S = size(), dpr = Math.max(1, window.devicePixelRatio || 1), px = Math.round(S * dpr);
    if (canvas.width !== px || canvas.height !== px) { canvas.width = px; canvas.height = px; }
    clamp(); paint(canvas.getContext('2d'), S, dpr);
  };
  // El zoom mantiene enfocado lo que está en el centro del cuadro.
  const setZoom = (z) => {
    const nz = Math.min(4, Math.max(1, z)), r = nz / st.zoom;
    st.zoom = nz; st.ox *= r; st.oy *= r; zoomIn.value = nz; render();
  };

  // Arrastrar (mouse o dedo) y zoom con dos dedos
  const ptrs = new Map(); let pinch = null;
  stage.addEventListener('pointerdown', (e) => { stage.setPointerCapture(e.pointerId); ptrs.set(e.pointerId, [e.clientX, e.clientY]); stage.classList.add('is-dragging'); });
  stage.addEventListener('pointermove', (e) => {
    if (!ptrs.has(e.pointerId)) return;
    const prev = ptrs.get(e.pointerId); ptrs.set(e.pointerId, [e.clientX, e.clientY]);
    if (ptrs.size === 2) {
      const [a, b] = [...ptrs.values()], d = Math.hypot(a[0] - b[0], a[1] - b[1]);
      if (pinch) setZoom(st.zoom * d / pinch); pinch = d; return;
    }
    st.ox += e.clientX - prev[0]; st.oy += e.clientY - prev[1]; render();
  });
  const up = (e) => { ptrs.delete(e.pointerId); pinch = null; if (!ptrs.size) stage.classList.remove('is-dragging'); };
  stage.addEventListener('pointerup', up); stage.addEventListener('pointercancel', up);
  stage.addEventListener('wheel', (e) => { e.preventDefault(); setZoom(st.zoom * (e.deltaY < 0 ? 1.08 : 1 / 1.08)); }, { passive: false });
  zoomIn.addEventListener('input', () => setZoom(+zoomIn.value));
  $('cropMenos').addEventListener('click', () => setZoom(st.zoom - 0.2));
  $('cropMas').addEventListener('click', () => setZoom(st.zoom + 0.2));
  $('cropGirar').addEventListener('click', () => { st.rot = (st.rot + 90) % 360; st.ox = st.oy = 0; render(); });
  $('cropOtra').addEventListener('click', () => inp.click());
  $('mFoto').addEventListener('shown.bs.modal', render);
  window.addEventListener('resize', render);

  btn.addEventListener('click', () => inp.click());
  inp.addEventListener('change', () => {
    const file = inp.files && inp.files[0]; inp.value = '';
    if (!file) return;
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) return say('Usa una imagen JPG, PNG o WEBP.', false);
    if (file.size > 15 * 1024 * 1024) return say('La imagen es muy pesada (máximo 15 MB).', false);
    const url = URL.createObjectURL(file), img = new Image();
    img.onload = () => {
      if (st.img && st.img.src.startsWith('blob:')) URL.revokeObjectURL(st.img.src);
      Object.assign(st, { img, rot: 0, zoom: 1, ox: 0, oy: 0 }); zoomIn.value = 1; say('', true);
      if ($('mFoto').classList.contains('show')) render(); else modal().show();
    };
    img.onerror = () => { URL.revokeObjectURL(url); say('No se pudo leer la imagen. Prueba con otra.', false); };
    img.src = url;
  });

  guardar.addEventListener('click', async () => {
    if (!st.img) return;
    guardar.disabled = true; const label = guardar.innerHTML;
    guardar.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Guardando…';
    try {
      const out = document.createElement('canvas'); out.width = out.height = OUT;
      clamp(); paint(out.getContext('2d'), size(), OUT / size());
      const blob = await new Promise((r, j) => out.toBlob((b) => (b ? r(b) : j(new Error('blob'))), 'image/jpeg', 0.9));
      const fd = new FormData(); fd.append('foto', blob, 'foto.jpg');
      const res = await fetch('api/usuarios.php?action=mi_foto', { method: 'POST', body: fd });
      const data = await res.json().catch(() => ({}));
      if (data.status === 'success') { setAvatar(data.avatar_url); quitar.classList.remove('d-none'); say(data.message, true); modal().hide(); }
      else say(data.message || 'No se pudo actualizar la foto.', false);
    } catch (_) { say('No hay conexión con el servidor.', false); }
    finally { guardar.disabled = false; guardar.innerHTML = label; }
  });

  quitar.addEventListener('click', async () => {
    if (!confirm('¿Quitar tu foto de perfil?')) return;
    quitar.disabled = true;
    try {
      const res = await fetch('api/usuarios.php?action=quitar_mi_foto', { method: 'POST' });
      const data = await res.json().catch(() => ({}));
      if (data.status === 'success') setAvatar(null); else say(data.message || 'No se pudo quitar la foto.', false);
    } catch (_) { say('No hay conexión con el servidor.', false); }
    finally { quitar.disabled = false; }
  });
})();
</script>

