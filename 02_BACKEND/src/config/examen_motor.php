<?php
// 02_BACKEND/src/config/examen_motor.php - Motor de los exámenes del link general (2026-10-20).
// Bancos de preguntas en config/examenes/*.php (uno por examen). A cada técnico se le arma su examen: N preguntas al azar
// (al menos una por tema, con tope por tipo) y alternativas mezcladas. Se califica en el servidor; el navegador nunca
// recibe las respuestas correctas. Una sola oportunidad: al terminar (o al vencer el tiempo) queda "usado".
// Tablas: examen_convocatoria_tecnicos (avance y nota) y examen_respuestas (tema y acierto por pregunta, para el dashboard).
require_once __DIR__ . '/examen_enlaces.php';

const SGS_EXAMEN_GRACIA_SEG = 10; // margen por la demora de la red al enviar la última respuesta

/** Exámenes disponibles: codigo => banco completo. */
function sgsExamenesDisponibles(): array {
    static $lista = null;
    if ($lista !== null) return $lista;
    $lista = [];
    foreach (glob(__DIR__ . '/examenes/*.php') ?: [] as $archivo) {
        $banco = require $archivo;
        if (is_array($banco) && !empty($banco['codigo']) && !empty($banco['preguntas'])) $lista[$banco['codigo']] = $banco;
    }
    return $lista;
}

function sgsExamenBanco(string $codigo): ?array {
    return sgsExamenesDisponibles()[$codigo] ?? null;
}

/** Arma el examen de un técnico: [['id' => 'ALT01', 'orden' => [índices de alternativas en el orden mostrado]], …]. */
function sgsArmarExamen(array $banco): array {
    $porTema = [];
    foreach ($banco['preguntas'] as $p) $porTema[$p['tema']][] = $p;
    $cantidad = min((int)$banco['preguntas_por_examen'], count($banco['preguntas']));
    $topes = $banco['maximo_por_tipo'] ?? [];
    $elegidas = []; $usadosPorTipo = [];
    $puede = static function (array $p) use (&$usadosPorTipo, $topes, &$elegidas): bool {
        return !isset($elegidas[$p['id']]) && (!isset($topes[$p['tipo']]) || ($usadosPorTipo[$p['tipo']] ?? 0) < $topes[$p['tipo']]);
    };
    $tomar = static function (array $p) use (&$usadosPorTipo, &$elegidas): void {
        $elegidas[$p['id']] = $p;
        $usadosPorTipo[$p['tipo']] = ($usadosPorTipo[$p['tipo']] ?? 0) + 1;
    };
    // Una de cada tema (en orden de temas al azar), luego el resto al azar.
    $temas = array_keys($porTema);
    shuffle($temas);
    foreach ($temas as $tema) {
        if (count($elegidas) >= $cantidad) break;
        $candidatas = $porTema[$tema];
        shuffle($candidatas);
        foreach ($candidatas as $p) if ($puede($p)) { $tomar($p); break; }
    }
    $resto = $banco['preguntas'];
    shuffle($resto);
    foreach ($resto as $p) {
        if (count($elegidas) >= $cantidad) break;
        if ($puede($p)) $tomar($p);
    }
    $armado = [];
    foreach ($elegidas as $p) {
        $orden = array_keys($p['opciones']);
        if ($p['tipo'] !== 'vf') {
            // En "ordenar pasos" nunca se muestran ya en el orden correcto.
            do { shuffle($orden); } while ($p['tipo'] === 'orden' && count($orden) > 1 && $orden === array_keys($p['opciones']));
        }
        $armado[] = ['id' => $p['id'], 'orden' => $orden];
    }
    shuffle($armado);
    return $armado;
}

function sgsPreguntaDelBanco(array $banco, string $id): ?array {
    foreach ($banco['preguntas'] as $p) if ($p['id'] === $id) return $p;
    return null;
}

/** Lo que ve el técnico: sin las respuestas correctas. Cada alternativa lleva su índice original (k). */
function sgsPreguntasParaTecnico(array $banco, array $armado): array {
    $salida = [];
    foreach ($armado as $n => $a) {
        $p = sgsPreguntaDelBanco($banco, $a['id']);
        if (!$p) continue;
        $salida[] = ['n' => $n, 'tipo' => $p['tipo'], 'tema' => $p['tema'], 'texto' => $p['texto'],
            'opciones' => array_map(static fn($k) => ['k' => (int)$k, 'texto' => $p['opciones'][$k]], $a['orden'])];
    }
    return $salida;
}

/** Limpia una respuesta recibida: índices válidos, sin repetir; una sola para unica/caso/vf. */
function sgsNormalizarRespuesta(array $pregunta, $valor): array {
    $validos = array_keys($pregunta['opciones']);
    $r = [];
    foreach ((array)$valor as $k) {
        if (!is_numeric($k)) continue;
        $k = (int)$k;
        if (in_array($k, $validos, true) && !in_array($k, $r, true)) $r[] = $k;
    }
    if (in_array($pregunta['tipo'], ['unica', 'caso', 'vf'], true)) $r = array_slice($r, 0, 1);
    return $r;
}

function sgsRespuestaCorrecta(array $pregunta, array $r): bool {
    if (!$r) return false;
    if ($pregunta['tipo'] === 'orden') return $r === array_keys($pregunta['opciones']);
    $a = $r; $b = array_map('intval', $pregunta['correcta']);
    sort($a); sort($b);
    return $a === $b;
}

/** Califica: ['correctas', 'total', 'nota', 'aprobado', 'detalle' => [[id, tema, correcta, respondida]], 'reforzar' => [temas]]. */
function sgsCalificarExamen(array $banco, array $armado, array $respuestas): array {
    $detalle = []; $correctas = 0; $falloPorTema = [];
    foreach ($armado as $n => $a) {
        $p = sgsPreguntaDelBanco($banco, $a['id']);
        if (!$p) continue;
        $r = sgsNormalizarRespuesta($p, $respuestas[(string)$n] ?? []);
        $ok = sgsRespuestaCorrecta($p, $r);
        if ($ok) $correctas++; else $falloPorTema[$p['tema']] = ($falloPorTema[$p['tema']] ?? 0) + 1;
        $detalle[] = ['id' => $p['id'], 'tema' => $p['tema'], 'correcta' => $ok, 'respondida' => (bool)$r];
    }
    $total = count($detalle);
    $nota = $total ? round($correctas * 100 / $total, 2) : 0.0;
    arsort($falloPorTema);
    return ['correctas' => $correctas, 'total' => $total, 'nota' => $nota, 'aprobado' => $nota >= (float)$banco['aprueba'],
        'detalle' => $detalle, 'reforzar' => array_keys($falloPorTema)];
}

/** Segundos que le quedan (0 si ya venció); null si no empezó. */
function sgsSegundosRestantes(array $part, array $banco): ?int {
    if (empty($part['inicio_en'])) return null;
    return max(0, strtotime((string)$part['inicio_en']) + (int)$banco['minutos'] * 60 - time());
}

/**
 * Termina el examen de un técnico (una sola vez): califica lo guardado, registra la nota y una fila por pregunta.
 * Devuelve el resultado; si ya estaba terminado, el resultado guardado.
 */
function sgsTerminarExamen(PDO $pdo, int $participacionId): ?array {
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT ct.*, c.examen FROM examen_convocatoria_tecnicos ct JOIN examen_convocatorias c ON c.id = ct.convocatoria_id WHERE ct.id = :id FOR UPDATE');
        $st->execute([':id' => $participacionId]);
        $part = $st->fetch(PDO::FETCH_ASSOC);
        $banco = $part ? sgsExamenBanco((string)$part['examen']) : null;
        if (!$part || !$banco || empty($part['inicio_en'])) { $pdo->rollBack(); return null; }
        if (!empty($part['usado_en'])) { $pdo->commit(); return sgsResultadoGuardado($pdo, $part, $banco); }
        $armado = json_decode((string)$part['preguntas'], true) ?: [];
        $respuestas = json_decode((string)$part['respuestas'], true) ?: [];
        $res = sgsCalificarExamen($banco, $armado, $respuestas);
        $segundos = min((int)$banco['minutos'] * 60, max(0, time() - strtotime((string)$part['inicio_en'])));
        $pdo->prepare('UPDATE examen_convocatoria_tecnicos SET usado_en = NOW(), correctas = :c, total_preguntas = :t, nota = :n, aprobado = :a, segundos = :s
                WHERE id = :id AND usado_en IS NULL')
            ->execute([':c' => $res['correctas'], ':t' => $res['total'], ':n' => $res['nota'], ':a' => $res['aprobado'] ? 1 : 0, ':s' => $segundos, ':id' => $participacionId]);
        $ins = $pdo->prepare('INSERT IGNORE INTO examen_respuestas (participacion_id, pregunta, tema, correcta, respondida) VALUES (:p, :q, :t, :c, :r)');
        foreach ($res['detalle'] as $d) $ins->execute([':p' => $participacionId, ':q' => $d['id'], ':t' => $d['tema'], ':c' => $d['correcta'] ? 1 : 0, ':r' => $d['respondida'] ? 1 : 0]);
        $pdo->commit();
        win_log_error('audit_exam_finished', 'Examen rendido', ['target_id' => (int)$part['tecnico_id'], 'convocatoria_id' => (int)$part['convocatoria_id'], 'nota' => $res['nota']]);
        return $res + ['minimo' => (int)$banco['aprueba']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Resultado de un examen ya terminado (para mostrarlo otra vez). */
function sgsResultadoGuardado(PDO $pdo, array $part, array $banco): array {
    $st = $pdo->prepare('SELECT tema, COUNT(*) AS fallos FROM examen_respuestas WHERE participacion_id = :p AND correcta = 0 GROUP BY tema ORDER BY fallos DESC');
    $st->execute([':p' => (int)$part['id']]);
    return ['correctas' => (int)$part['correctas'], 'total' => (int)$part['total_preguntas'], 'nota' => (float)$part['nota'],
        'aprobado' => (bool)$part['aprobado'], 'reforzar' => array_column($st->fetchAll(PDO::FETCH_ASSOC), 'tema'), 'minimo' => (int)$banco['aprueba']];
}

/** Cierra (califica) los exámenes empezados cuyo tiempo ya venció y nadie terminó (se cerró el navegador). */
function sgsCerrarExamenesVencidos(PDO $pdo): int {
    $cerrados = 0;
    $st = $pdo->query('SELECT ct.id, ct.inicio_en, c.examen FROM examen_convocatoria_tecnicos ct JOIN examen_convocatorias c ON c.id = ct.convocatoria_id
        WHERE ct.inicio_en IS NOT NULL AND ct.usado_en IS NULL LIMIT 500');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $banco = sgsExamenBanco((string)$p['examen']);
        if (!$banco) continue;
        if (strtotime((string)$p['inicio_en']) + (int)$banco['minutos'] * 60 + SGS_EXAMEN_GRACIA_SEG < time()) {
            if (sgsTerminarExamen($pdo, (int)$p['id'])) $cerrados++;
        }
    }
    return $cerrados;
}
