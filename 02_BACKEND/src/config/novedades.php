<?php
// 02_BACKEND/src/config/novedades.php - Recorrido "El sistema se actualizó" (2026-11-08, pedido del usuario).
// CADA IMPLEMENTACIÓN NUEVA agrega aquí una entrada al final, con el siguiente id:
//   id (creciente), fecha (aaaa-mm-dd), icono (Bootstrap Icons), titulo, texto ("Ahora puedes…"), url y puede: solo la ve
//   quien puede usarla.
// Sale al iniciar sesión, a pantalla completa, hasta que el usuario lo termina u omite (usuarios.novedad_vista). No se avisa
// lo anterior a la creación del usuario ni lo que tenga más de SGS_NOVEDADES_DIAS. Sistema liviano: una consulta por ingreso.
// Pantalla: layouts/novedades.php + assets/js/novedades.js. Se marca como visto con api/novedades.php.
const SGS_NOVEDADES_DIAS = 30;

function sgsNovedades(): array {
    return [
        ['id' => 1, 'fecha' => '2026-11-05', 'icono' => 'bi-ui-checks', 'titulo' => 'Exámenes de 10 preguntas y por tema',
         'texto' => 'Ahora el examen tiene 10 preguntas y, al generar el link, eliges los temas: trabajo en altura o manejo defensivo. También puedes generar el link de una sola cuadrilla.',
         'url' => 'index.php?page=cuadrillas', 'puede' => static fn(array $u): bool => Auth::puede(Auth::PERM_EXAMENES_EDIT, $u)],
        ['id' => 2, 'fecha' => '2026-11-05', 'icono' => 'bi-broadcast', 'titulo' => 'Seguimiento en vivo de los exámenes',
         'texto' => 'Ahora puedes ver quién está rindiendo, quién terminó y quién falta, y en qué preguntas falló cada técnico para reforzarlo.',
         'url' => 'index.php?page=examenes', 'puede' => static fn(array $u): bool => Auth::puede(Auth::PERM_EXAMENES_VIEW, $u)],
        ['id' => 3, 'fecha' => '2026-11-08', 'icono' => 'bi-geo-alt', 'titulo' => 'Órdenes cercanas',
         'texto' => 'Ahora, en Supervisiones, puedes ver las órdenes iniciadas hoy más cerca de ti, con la ruta en Google Maps.',
         'url' => 'index.php?page=supervision_lista', 'puede' => static fn(array $u): bool => Auth::puedeOrdenesCercanas($u) && getenv('WINBO_USUARIO')],
        ['id' => 4, 'fecha' => '2026-11-08', 'icono' => 'bi-shield-check', 'titulo' => 'Permisos separados de los indicadores',
         'texto' => 'Ahora puedes dar "Avisar lluvias" (en Mi turno) y "Generar links de examen" (en Técnicos y cuadrillas) sin dar acceso a los indicadores de Clima y Exámenes.',
         'url' => 'index.php?page=usuarios', 'puede' => static fn(array $u): bool => Auth::puede(Auth::PERM_USERS_MANAGE, $u)],
    ];
}

/** Novedades que corresponden: recientes, posteriores a la creación del usuario, aún no vistas y que él puede usar. */
function sgsNovedadesPara(array $catalogo, array $usuario, int $vista, string $creado, string $hoy): array {
    $desde = date('Y-m-d', strtotime($hoy . ' -' . SGS_NOVEDADES_DIAS . ' days'));
    $alta = substr($creado, 0, 10);
    return array_values(array_filter($catalogo, static fn($n) => $n['id'] > $vista && $n['fecha'] >= $desde && $n['fecha'] >= $alta && ($n['puede'])($usuario)));
}

/** Pendientes del usuario. Si algo falla (p. ej. la base aún sin la columna) no se avisa nada: nunca rompe la página. */
function sgsNovedadesPendientes(PDO $pdo, array $usuario): array {
    try {
        $st = $pdo->prepare('SELECT novedad_vista, fecha_creacion FROM usuarios WHERE id = :id');
        $st->execute([':id' => (int)$usuario['id']]);
        $fila = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    if (!$fila) return [];
    return sgsNovedadesPara(sgsNovedades(), $usuario, (int)$fila['novedad_vista'], (string)$fila['fecha_creacion'], date('Y-m-d'));
}

function sgsNovedadesMarcarVistas(PDO $pdo, int $usuarioId): void {
    $ultima = (int)max(array_column(sgsNovedades(), 'id'));
    $pdo->prepare('UPDATE usuarios SET novedad_vista = GREATEST(novedad_vista, :n) WHERE id = :id')->execute([':n' => $ultima, ':id' => $usuarioId]);
}
