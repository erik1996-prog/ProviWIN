<?php
// 03_FRONTEND/src/pages/examen_general.php - Link GENERAL de examen (2026-10-20): un link para un grupo de técnicos.
// 1) El técnico entra con su DNI (solo los del grupo; 5 DNI equivocados desde un equipo, o 40 en total, bloquean el link
//    15 minutos). Al entrar recibe una llave en una cookie de este navegador (en la base solo su huella, sesion_hash):
//    si vuelve a entrar con su DNI desde otro equipo, la llave anterior deja de servir.
// 2) Instrucciones → "Empezar": se arma SU examen (20 preguntas al azar, alternativas mezcladas) y corre el tiempo (10 min).
// 3) Cada respuesta se guarda al momento (si se corta, retoma donde quedó; el reloj sigue). Al terminar o al vencer el
//    tiempo se califica en el servidor: una sola oportunidad. Ve su nota, si aprobó y qué temas reforzar (no las respuestas).
// Se entra por examen.php?g=…; las llamadas del examen (?g=…&accion=…) responden JSON. Pantalla: assets/js/examen.js.
// Lógica: config/examen_enlaces.php (link e intentos) y config/examen_motor.php (preguntas y nota).
require_once BACKEND_PATH . '/config/database.php';
require_once BACKEND_PATH . '/config/examen_motor.php';
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$assets = '03_FRONTEND/src/assets';
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$codigo = strtolower(trim((string)($_GET['g'] ?? '')));
$accion = (string)($_GET['accion'] ?? '');
$vista = 'invalido';
$error = '';
$conv = null;
$part = null;
$banco = null;

$json = static function (array $datos, int $codigo = 200): void {
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
};
$nombreCookie = static fn(int $convId): string => 'sgs_examen_' . $convId;
$rutaCookie = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/';
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
/** Participación del técnico que tiene la llave de este navegador. */
$participacionPorLlave = static function (PDO $pdo, int $convId) use ($nombreCookie): ?array {
    $llave = (string)($_COOKIE[$nombreCookie($convId)] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $llave)) return null;
    $st = $pdo->prepare('SELECT ct.*, t.nombre_tecnico, p.nombre_partner FROM examen_convocatoria_tecnicos ct JOIN tecnicos t ON t.id = ct.tecnico_id
        LEFT JOIN partners p ON p.id = t.partner_id WHERE ct.convocatoria_id = :c AND ct.sesion_hash = :h LIMIT 1');
    $st->execute([':c' => $convId, ':h' => hash('sha256', $llave)]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};

try {
    $pdo = Database::getConnection();
    $conv = sgsBuscarConvocatoria($pdo, $codigo);
    $banco = $conv ? sgsExamenBanco((string)($conv['examen'] ?? 'altura')) : null;
    if ($conv && $banco) {
        $estadoLink = sgsEstadoConvocatoria($conv);
        $part = $participacionPorLlave($pdo, (int)$conv['id']);

        // ---------- Llamadas del examen (JSON) ----------
        if ($accion !== '') {
            if (!$part) $json(['status' => 'error', 'codigo' => 'sin_llave', 'message' => 'Vuelve a ingresar con tu DNI.'], 401);
            if (!empty($part['usado_en'])) $json(['status' => 'terminado', 'resultado' => sgsResultadoGuardado($pdo, $part, $banco)]);
            if ($accion === 'empezar' || $accion === 'preguntas') {
                if (empty($part['inicio_en'])) {
                    if ($accion === 'preguntas') $json(['status' => 'error', 'message' => 'Todavía no empezaste el examen.'], 409);
                    if ($estadoLink !== 'activo') $json(['status' => 'error', 'message' => 'El link ya no está vigente.'], 409);
                    $armado = sgsArmarExamen($banco);
                    $pdo->prepare('UPDATE examen_convocatoria_tecnicos SET inicio_en = NOW(), preguntas = :p, respuestas = :r WHERE id = :id AND inicio_en IS NULL')
                        ->execute([':p' => json_encode($armado), ':r' => '{}', ':id' => (int)$part['id']]);
                    $part = $participacionPorLlave($pdo, (int)$conv['id']);
                }
                $restan = sgsSegundosRestantes($part, $banco);
                if ($restan <= 0) $json(['status' => 'terminado', 'resultado' => sgsTerminarExamen($pdo, (int)$part['id'])]);
                $json(['status' => 'success', 'segundos' => $restan, 'preguntas' => sgsPreguntasParaTecnico($banco, json_decode((string)$part['preguntas'], true) ?: []),
                    'respuestas' => json_decode((string)$part['respuestas'], true) ?: new stdClass()]);
            }
            if ($accion === 'responder' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $limite = strtotime((string)$part['inicio_en']) + (int)$banco['minutos'] * 60 + SGS_EXAMEN_GRACIA_SEG;
                if (time() > $limite) $json(['status' => 'terminado', 'resultado' => sgsTerminarExamen($pdo, (int)$part['id'])]);
                $armado = json_decode((string)$part['preguntas'], true) ?: [];
                $n = (int)($_POST['n'] ?? -1);
                $pregunta = isset($armado[$n]) ? sgsPreguntaDelBanco($banco, $armado[$n]['id']) : null;
                if (!$pregunta) $json(['status' => 'error', 'message' => 'Pregunta no válida.'], 422);
                $respuestas = json_decode((string)$part['respuestas'], true) ?: [];
                $respuestas[(string)$n] = sgsNormalizarRespuesta($pregunta, $_POST['r'] ?? []);
                $pdo->prepare('UPDATE examen_convocatoria_tecnicos SET respuestas = :r WHERE id = :id AND usado_en IS NULL')
                    ->execute([':r' => json_encode($respuestas), ':id' => (int)$part['id']]);
                $json(['status' => 'success', 'segundos' => sgsSegundosRestantes($part, $banco)]);
            }
            if ($accion === 'terminar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                if (empty($part['inicio_en'])) $json(['status' => 'error', 'message' => 'Todavía no empezaste el examen.'], 409);
                $json(['status' => 'terminado', 'resultado' => sgsTerminarExamen($pdo, (int)$part['id'])]);
            }
            $json(['status' => 'error', 'message' => 'Acción no válida.'], 400);
        }

        // ---------- Pantalla ----------
        if ($part && !empty($part['inicio_en']) && empty($part['usado_en']) && sgsSegundosRestantes($part, $banco) <= 0) {
            sgsTerminarExamen($pdo, (int)$part['id']); // se le acabó el tiempo con el navegador cerrado
            $part = $participacionPorLlave($pdo, (int)$conv['id']);
        }
        if ($part && !empty($part['usado_en'])) $vista = 'resultado';
        elseif ($part && !empty($part['inicio_en'])) $vista = 'examen';
        elseif ($estadoLink !== 'activo') $vista = $estadoLink;
        elseif ($part) $vista = 'instrucciones';
        else $vista = 'dni';

        if ($vista === 'dni' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $dni = preg_replace('/\D/', '', (string)($_POST['dni'] ?? ''));
            $tecnico = null;
            if (sgsExamenBloqueado($pdo, (int)$conv['id'])) {
                $error = 'Hubo demasiados intentos con un DNI que no está en la lista. Espera 15 minutos y vuelve a intentar.';
            } elseif (!preg_match('/^\d{8}$/', $dni)) {
                $error = 'Escribe tu DNI de 8 dígitos.';
            } elseif (!($tecnico = sgsTecnicoConvocatoriaPorDni($pdo, (int)$conv['id'], $dni))) {
                sgsRegistrarIntentoExamen($pdo, (int)$conv['id']);
                $error = 'Tu DNI no está en esta convocatoria. Revisa que esté bien escrito o consulta con tu supervisor.';
            } else {
                sgsLimpiarIntentosExamen($pdo, (int)$conv['id']);
                // Llave de este navegador (si entra desde otro equipo, la anterior deja de servir).
                $llave = bin2hex(random_bytes(32));
                $pdo->prepare('UPDATE examen_convocatoria_tecnicos SET sesion_hash = :h, ingreso_en = COALESCE(ingreso_en, NOW()) WHERE id = :id')
                    ->execute([':h' => hash('sha256', $llave), ':id' => (int)$tecnico['participacion_id']]);
                if (empty($tecnico['ingreso_en'])) {
                    win_log_error('audit_exam_group_entry', 'Técnico ingresó al link general de examen', ['target_id' => (int)$tecnico['id'], 'convocatoria_id' => (int)$conv['id']]);
                }
                setcookie($nombreCookie((int)$conv['id']), $llave, ['expires' => time() + 86400 * 3, 'path' => $rutaCookie, 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
                header('Location: examen.php?g=' . rawurlencode($codigo), true, 303); // que recargar no reenvíe el DNI
                exit;
            }
        }
    } elseif ($conv) {
        $vista = 'error';
    }
} catch (Throwable $ex) {
    win_log_error('exam_group_link_error', $ex->getMessage());
    if ($accion !== '') $json(['status' => 'error', 'message' => 'No se pudo guardar. Revisa tu conexión e inténtalo otra vez.'], 500);
    $vista = 'error';
}
$nombre = $part ? mb_convert_case(mb_strtolower(sgsNombrePersonaTecnico((string)$part['nombre_tecnico'], (int)$part['tipo']), 'UTF-8'), MB_CASE_TITLE, 'UTF-8') : '';
$resultado = $vista === 'resultado' ? sgsResultadoGuardado($pdo, $part, $banco) : null;
$mensajes = [
    'invalido' => ['bi-x-circle', 'Link no válido', 'Este link no existe o está incompleto. Pide a tu supervisor que te lo envíe otra vez.'],
    'vencido' => ['bi-clock-history', 'Link vencido', 'Este link ya venció. Consulta con tu supervisor.'],
    'anulado' => ['bi-slash-circle', 'Link anulado', 'Este link fue anulado. Usa el último link que te envió tu supervisor.'],
    'error' => ['bi-exclamation-triangle', 'No se pudo abrir el examen', 'Inténtalo de nuevo en unos minutos.'],
];
$verLogin = static fn(string $archivo): string => rawurlencode(ASSET_VERSION) . '.' . (int)@filemtime(FRONTEND_PATH . '/assets/' . $archivo);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Examen · <?= $e(SYSTEM_CODE) ?> WIN</title>
<link rel="icon" type="image/svg+xml" href="<?= $assets ?>/img/favicon.svg?v=<?= rawurlencode(ASSET_VERSION) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= $assets ?>/css/sgs-login.css?v=<?= $verLogin('css/sgs-login.css') ?>">
</head>
<body class="sgs-login">
  <div class="sgs-bg" aria-hidden="true"><span class="s1"></span><span class="s2"></span><span class="s3"></span></div>
  <main class="sgs-main">
    <div class="sgs-card sgs-examen<?= in_array($vista, ['examen', 'instrucciones', 'resultado'], true) ? ' sgs-examen--amplio' : '' ?>" id="examenTarjeta">
      <div class="sgs-logo sgs-logo--examen" role="img" aria-label="WIN"></div>
      <?php if (isset($mensajes[$vista])): [$icono, $titulo, $texto] = $mensajes[$vista]; ?>
        <div class="sgs-system">Sistema de Gestión de Supervisión</div>
        <div class="sgs-examen-icono"><i class="bi <?= $icono ?>" aria-hidden="true"></i></div>
        <h1><?= $e($titulo) ?></h1>
        <p class="sgs-sub"><?= $e($texto) ?></p>
      <?php elseif ($vista === 'dni'): ?>
        <div class="sgs-system">Sistema de Gestión de Supervisión</div>
        <div class="sgs-examen-icono"><i class="bi bi-person-vcard" aria-hidden="true"></i></div>
        <h1><?= $e($banco['titulo']) ?></h1>
        <p class="sgs-sub">Ingresa con tu DNI para rendir el examen.</p>
        <?php if ($error): ?><div class="alert alert-danger sgs-alert" role="alert"><?= $e($error) ?></div><?php endif; ?>
        <form method="post" novalidate autocomplete="off">
          <div class="mb-3">
            <label for="dni" class="form-label">DNI</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-person-vcard"></i></span>
              <input id="dni" name="dni" type="text" class="form-control<?= $error ? ' is-invalid' : '' ?>" inputmode="numeric" pattern="\d{8}" maxlength="8" placeholder="8 dígitos" required autofocus>
            </div>
          </div>
          <button class="btn btn-sgs w-100" type="submit">Ingresar al examen</button>
        </form>
        <div class="sgs-examen-dato mt-3 mb-0"><i class="bi bi-clock" aria-hidden="true"></i>Disponible hasta el <strong><?= date('d/m/Y', strtotime($conv['vence_en'])) ?> a las <?= date('H:i', strtotime($conv['vence_en'])) ?></strong></div>
      <?php elseif ($vista === 'resultado'): ?>
        <div id="examenApp" data-vista="resultado" data-resultado="<?= $e(json_encode($resultado, JSON_UNESCAPED_UNICODE)) ?>" data-nombre="<?= $e($nombre) ?>" data-titulo="<?= $e($banco['titulo']) ?>"></div>
      <?php else: ?>
        <div id="examenApp" data-vista="<?= $vista ?>" data-url="examen.php?g=<?= $e(rawurlencode($codigo)) ?>" data-nombre="<?= $e($nombre) ?>"
          data-titulo="<?= $e($banco['titulo']) ?>" data-preguntas="<?= (int)$banco['preguntas_por_examen'] ?>" data-minutos="<?= (int)$banco['minutos'] ?>"
          data-aprueba="<?= (int)$banco['aprueba'] ?>" data-partner="<?= $e($part['nombre_partner'] ?? '') ?>"></div>
      <?php endif; ?>
    </div>
  </main>
  <?php if (in_array($vista, ['instrucciones', 'examen', 'resultado'], true)): ?>
  <script src="<?= $assets ?>/js/examen.js?v=<?= rawurlencode(ASSET_VERSION) ?>"></script>
  <?php endif; ?>
</body>
</html>
