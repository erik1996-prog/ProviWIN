<?php
// 02_BACKEND/src/services/InicioOrdenesService.php - "Inicio de órdenes" (2026-11-19): lee de Winbo (Phoenix) las órdenes de HOY de
// GESTIÓN PROVINCIA con su historial de estados. Copia de inicio-ordenes/core/fuente.py y seguidor.py, adaptada al hosting:
//   1) Grilla de Órdenes (Grilla.aspx/cargarGrilla) con Fecha Visita = hoy y el filtro Empresa = GESTIÓN PROVINCIA (así no trae Lima;
//      Órdenes cercanas no lo usa y sigue igual). 30 órdenes por página; desde la página 2 Winbo manda filas <tr> sueltas.
//   2) Pestaña "Estados" de cada orden (Formulario.aspx/CargarHistoEstaGrilla), solo para órdenes nuevas o que cambiaron, las que
//      siguen En camino / Iniciada / Revisión (en cada vuelta) y las abiertas sin cambios cada 2 horas.
// Sin cron: cada visita a ProviWIN (index.php) y la propia pantalla despiertan un TURNO de como máximo TURNO_SEGUNDOS, después de
// mandar la página y con candado (nunca dos a la vez). El trabajo sigue en el siguiente turno. Una vuelta completa cada VUELTA s
// (30 min, pedido del usuario para no consultar Phoenix tan seguido) y, además, una vuelta nueva al llegar cada corte de Telegram:
// el reporte sale recién cuando terminó esa lectura, nunca con datos de antes del corte. Despertar liviano: pulso.json (chico).
// Tiempo real, nada permanente: el trabajo del día vive en 08_LOGS/runtime/inicio_ordenes/ y se borra al cambiar de día.
// Sesión: la iniciada aquí (hasta las 23:59) o la del .env (WinboService::cookieLectura). El favorito no sirve: solo lee la grilla.
// Telegram: los reportes salen solo en los CORTES del día (08:06 primera orden; luego alertas), una vez cada uno (config/inicio_ordenes_telegram.php).
require_once __DIR__ . '/../config/inicio_ordenes.php';
require_once __DIR__ . '/../config/inicio_ordenes_telegram.php';
require_once __DIR__ . '/WinboService.php';

class InicioOrdenesService {
    private const BASE = 'https://winbo-phx.azurewebsites.net';
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36';
    private const URL_GRILLA = '/Paginas/OrdenTrabajo/grilla.aspx?to=1&nombre=Ordenes+de+Trabajo&id=5&icono=&edit=S';
    private const GRILLA = '/Paginas/OrdenTrabajo/Grilla.aspx';
    public const TURNO_SEGUNDOS = 18;        // trabajo máximo por turno (sistema liviano)
    public const TURNO_SEGUNDOS_CORTE = 45;  // con un corte pendiente (4 veces al día), para que el reporte no se demore
    public const VUELTA = 1800;              // segundos entre vueltas completas: cada 30 min (2026-11-19, pedido del usuario)
    private const REINTENTO_ERROR = 300;     // con error (sin sesión, columnas), se reintenta cada 5 min
    // Releer el historial de las órdenes abiertas sin cambios a la vista cada 2 h (2026-11-19: con vueltas de 30 min, menos consultas).
    // Las que cambiaron de estado, cuadrilla o visita y las que están En camino / Iniciada se releen en cada vuelta.
    private const REVISAR_ABIERTAS = 7200;
    private const PARALELO_GRILLA = 4;
    private const PARALELO_HISTORIAL = 12;   // 2026-11-20: como el sistema Python (12 hilos), pedido del usuario
    private const INTENTOS = 3;
    private const SEGUNDOS_WINBO = 20;
    public const LECTURA_DESDE = 6, LECTURA_HASTA = 21;       // horas en que se consulta Winbo sin pedirlo (06:00 a 20:59)
    // Envíos automáticos (2026-11-19, pedido del usuario): hora del corte => reporte. A las 08:06, las cuadrillas que a las 08:05
    // seguían En camino sin iniciar su primera orden; luego, las alertas de iniciadas (2 h o más).
    public const CORTES = ['08:06' => 'primera', '10:30' => 'alertas', '12:30' => 'alertas', '15:30' => 'alertas', '17:30' => 'alertas'];
    private const VENTANA_CORTE = 3600;     // si a la hora del corte nadie entró, sale con la primera visita de la hora siguiente
    private const FINALES = ['FINALIZADA', 'CANCELADA', 'ANULADA', 'CERRADA', 'RECHAZADA'];
    private const EN_CURSO = ['EN CAMINO', 'INICIADA', 'REVISION'];
    private const COLUMNAS = [['Cuadrilla', 'cuad'], ['Provincia', 'prov'], ['Localidad', 'loc'], ['Fecha Visita', 'fv'],
                              ['Región / Zona', 'rz'], ['Proveedeor', 'partner'], ['Código De Seguimiento Cliente', 'orden']];

    public const SIN_SESION = 'No hay sesión de Phoenix. Conéctala arriba, en el indicador «Phoenix» de la barra superior (dura hasta las 23:59).';

    private string $dir;
    private string $cookie = '';
    private array $e = [];       // estado del día (estado.json)

    public function __construct() {
        $this->dir = STORAGE_PATH . '/inicio_ordenes';
        if (!is_dir($this->dir)) @mkdir($this->dir, 0700, true);
    }

    private static function ahora(): DateTimeImmutable { return new DateTimeImmutable('now', new DateTimeZone('America/Lima')); }

    // ── Despertar desde las visitas ─────────────────────────
    /**
     * Un turno después de mandar la página (como sgsEnviarCortesTrasLaVisita). Se llama desde index.php con
     * win_run_periodic_task (como mucho una vez por minuto para todo el sistema) y desde la pantalla (Actualizar).
     */
    public static function turnoTrasLaVisita(bool $forzar = false): void {
        static $programado = false;
        if (PHP_SAPI === 'cli' || $programado) return;
        $h = (int)self::ahora()->format('G');
        if (!$forzar && ($h < self::LECTURA_DESDE || $h >= self::LECTURA_HASTA)) return;
        if (WinboService::sesionLectura() === null) return;
        if (!$forzar && !(new self())->toca()) return;        // nada que hacer: ni lectura ni corte pendiente
        $programado = true;
        register_shutdown_function(static function () use ($forzar): void {
            ignore_user_abort(true);
            @set_time_limit(90);
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
            try { (new self())->turno($forzar); }
            catch (Throwable $e) { win_log_error('inicio_ordenes', 'Falló un turno de Inicio de órdenes', ['error' => $e->getMessage()]); }
        });
    }

    public static function sinSesion(): bool { return WinboService::sesionLectura() === null; }

    /** ¿Hay una lectura a medias (grilla o historiales)? Lee solo pulso.json. */
    public static function leyendo(): bool {
        $p = json_decode((string)@file_get_contents(STORAGE_PATH . '/inicio_ordenes/pulso.json'), true);
        return is_array($p) && ($p['dia'] ?? '') === self::ahora()->format('Y-m-d') && in_array($p['fase'] ?? '', ['grilla', 'historial'], true) && empty($p['error']);
    }

    /** ¿Hay trabajo? Lee solo pulso.json y telegram.json (chicos), no el estado del día. */
    private function toca(): bool {
        $p = json_decode((string)@file_get_contents($this->dir . '/pulso.json'), true);
        if (!is_array($p) || ($p['dia'] ?? '') !== self::ahora()->format('Y-m-d') || ($p['fase'] ?? '') !== 'espera') return true;
        // Con error, se reintenta a los 5 min, o al momento si se acaba de conectar Phoenix.
        if (!empty($p['error'])) return time() - (int)($p['turno'] ?? 0) >= self::REINTENTO_ERROR
            || (int)@filemtime(sgsPhoenixDir() . '/sesion_aqui.json') > (int)($p['turno'] ?? 0);
        return time() - (int)($p['actualizado'] ?? 0) >= self::VUELTA || $this->cortePendiente() !== null;
    }

    /** Corte de Telegram que está en su hora (desde la hora del corte y hasta 59 min después) y que aún no se envió. */
    private function cortePendiente(): ?array {
        $ahora = self::ahora();
        foreach (self::CORTES as $c => $reporte) {
            $t = (new DateTimeImmutable($ahora->format('Y-m-d') . " $c:00", new DateTimeZone('America/Lima')))->getTimestamp();
            if ($ahora->getTimestamp() < $t || $ahora->getTimestamp() >= $t + self::VENTANA_CORTE) continue;
            $marca = json_decode((string)@file_get_contents($this->dir . '/telegram.json'), true);
            return ($marca['hora'] ?? '') === $ahora->format('Y-m-d ') . $c ? null : ['corte' => $c, 'reporte' => $reporte, 'desde' => $t];
        }
        return null;
    }

    // ── Estado del día ───────────────────────────────────────
    private function archivo(): string { return $this->dir . '/estado.json'; }

    private function cargar(): void {
        $dia = self::ahora()->format('Y-m-d');
        $e = json_decode((string)@file_get_contents($this->archivo()), true);
        // Día nuevo: se empieza de cero (no queda nada de días pasados).
        $this->e = (is_array($e) && ($e['dia'] ?? '') === $dia) ? $e
            : ['dia' => $dia, 'empresaId' => null, 'ordenes' => [], 'actualizado' => null, 'fase' => 'espera', 'ciclo' => null, 'error' => null, 'aviso' => null];
    }

    private function guardar(): void {
        $tmp = $this->archivo() . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($this->e, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) !== false) @rename($tmp, $this->archivo());
        else @unlink($tmp);
        @file_put_contents($this->dir . '/pulso.json', json_encode(['dia' => $this->e['dia'], 'fase' => $this->e['fase'], 'actualizado' => $this->e['actualizado'],
            'error' => (bool)$this->e['error'], 'turno' => time()]), LOCK_EX);
    }

    // ── Turno de trabajo ─────────────────────────────────────
    public function turno(bool $forzar = false): void {
        $candado = @fopen($this->dir . '/turno.lock', 'c');
        if (!$candado || !flock($candado, LOCK_EX | LOCK_NB)) { if ($candado) fclose($candado); return; }
        try {
            $this->cargar();
            $t0 = microtime(true);
            try {
                $this->cookie = WinboService::cookieLectura();
                // Vuelta nueva: a pedido, cada 30 min, o al llegar un corte con datos leídos antes de su hora.
                $corte = $this->cortePendiente();
                if ($this->e['fase'] === 'espera' && ($forzar || time() - (int)$this->e['actualizado'] >= self::VUELTA
                        || ($corte && (int)($this->e['leidoDesde'] ?? 0) < $corte['desde']))) {
                    $this->e['fase'] = 'grilla';
                    $this->e['ciclo'] = ['inicio' => time(), 'cab' => null, 'total' => 0, 'paginas' => 0, 'faltan' => [], 'repaso' => false,
                                         'intentos' => [], 'vistos' => [], 'pedir' => [], 'pedidos' => 0];
                }
                while ($this->e['fase'] !== 'espera' && microtime(true) - $t0 < ($corte ? self::TURNO_SEGUNDOS_CORTE : self::TURNO_SEGUNDOS)) {
                    $this->e['fase'] === 'grilla' ? $this->pasoGrilla() : $this->pasoHistorial();
                }
                $this->e['error'] = null;
            } catch (Throwable $ex) {
                // Se reintenta en el siguiente turno con una vuelta nueva.
                $this->e['error'] = $ex->getMessage();
                $this->e['fase'] = 'espera';
                $this->e['ciclo'] = null;
                win_log_error('inicio_ordenes', $ex->getMessage());
            }
            $this->guardar();
            if ($this->e['fase'] === 'espera' && !$this->e['error']) $this->telegramCortes();
        } finally {
            flock($candado, LOCK_UN); fclose($candado);
        }
    }

    // ── 1) Grilla ────────────────────────────────────────────
    private function pasoGrilla(): void {
        $c = &$this->e['ciclo'];
        if ($c['cab'] === null) {                        // página 1: títulos, total y cuántas páginas
            [$filas, $total, $cab] = $this->paginaUno();
            $c['cab'] = $cab ?? [];                      // sin órdenes hoy: sin títulos, pero la página 1 ya se leyó
            $c['total'] = $total;
            $c['paginas'] = (int)ceil($total / max(count($filas), 1));
            $c['faltan'] = $c['paginas'] > 1 ? range(2, $c['paginas']) : [];
            foreach ($filas as $f) $c['vistos'][$f['ordenId']] = $f;
            return;
        }
        if ($c['faltan']) {
            // En paralelo; en el repaso (si faltaron órdenes por la paginación inestable de Winbo), de una en una y en orden.
            $lote = array_splice($c['faltan'], 0, $c['repaso'] ? 1 : self::PARALELO_GRILLA);
            $textos = $this->multi(array_map(fn($p) => ['ruta' => self::GRILLA . '/cargarGrilla', 'json' => $this->payload($p), 'referer' => self::BASE . self::URL_GRILLA], $lote));
            foreach ($lote as $i => $p) {
                $filas = null;
                if ($textos[$i] !== false) {
                    $d = self::decodificar($textos[$i]);
                    try { $filas = self::filasGrilla((string)($d['html'] ?? ''), $c['cab'])[0]; } catch (Throwable $x) { $filas = null; }
                }
                if ($filas === null) {
                    $c['intentos'][$p] = ($c['intentos'][$p] ?? 0) + 1;
                    if ($c['intentos'][$p] < self::INTENTOS) $c['faltan'][] = $p;
                    continue;
                }
                foreach ($filas as $f) if (!isset($c['vistos'][$f['ordenId']])) $c['vistos'][$f['ordenId']] = $f;
            }
            return;
        }
        if (count($c['vistos']) < $c['total'] && !$c['repaso'] && $c['paginas'] > 1) {
            $c['repaso'] = true;
            $c['intentos'] = [];
            $c['faltan'] = range(2, $c['paginas']);
            return;
        }
        $this->e['aviso'] = count($c['vistos']) < $c['total'] ? "La grilla declara {$c['total']} órdenes y se leyeron " . count($c['vistos']) . '.' : null;
        $this->prepararHistoriales();
    }

    /** Página 1 con sus títulos. Si Winbo "olvidó" la empresa o el área y vino sin columnas, repite los pasos del ingreso una vez. */
    private function paginaUno(): array {
        if (!$this->e['empresaId']) $this->e['empresaId'] = $this->empresaId();
        $vacias = 0; $resincronizado = false;
        for (;;) {
            $t = $this->multi([['ruta' => self::URL_GRILLA, 'json' => null, 'referer' => self::BASE . '/default.aspx'],
                               ['ruta' => self::GRILLA . '/cargarGrilla', 'json' => $this->payload(1), 'referer' => self::BASE . self::URL_GRILLA]], 1);
            $d = self::decodificar((string)$t[1]);
            if (empty($d['html']) && (string)($d['registros'] ?? '') !== '0') {      // la sesión cayó… o Winbo respondió vacío una vez
                $vacias++;
                // 2026-11-19: antes de darla por cerrada (y borrar la sesión iniciada aquí) se espera y se repite una vez.
                if ($vacias === 1) { sleep(2); continue; }
                if ($vacias > 2) throw new RuntimeException('Winbo no devolvió las órdenes. Se reintenta en unos minutos.');
                $this->cookie = WinboService::cookieLectura(true);          // .env: vuelve a entrar · sesión de aquí: avisa
                continue;
            }
            try {
                [$filas, $cab] = self::filasGrilla((string)($d['html'] ?? ''), null);
                return [$filas, (int)trim((string)($d['registros'] ?? count($filas))), $cab];
            } catch (RuntimeException $x) {
                if ($resincronizado || !str_contains($x->getMessage(), 'no trae las columnas')) throw $x;
                $this->resincronizar();
                $resincronizado = true;
            }
        }
    }

    /** Mismo cuerpo que Órdenes cercanas (filtro por fecha de visita dd/mm/aaaa), sin estado y con la empresa. */
    private function payload(int $pagina): array {
        $d = self::ahora()->format('d/m/Y');
        return ['tipoOrden' => 1, 'tipoTraba' => '', 'estado' => null, 'OrdenId' => '', 'NumeDocu' => '', 'Nombre' => '', 'suscrip' => '',
                'fechaEstaDesde' => '', 'fechaEstaHasta' => '', 'fechaVisiDesde' => $d, 'fechaVisiHasta' => $d, 'fechaSoliDesde' => '', 'fechaSoliHasta' => '',
                'conexion' => '0', 'cuadrilla' => null, 'provincia' => null, 'localidad' => null, 'region' => null, 'zona' => null, 'tipoProduc' => null, 'producto' => null,
                // Filtro "Empresa" = GESTIÓN PROVINCIA: sin él Winbo devuelve todas las empresas del usuario (Lima incluida).
                'Empresa' => (string)$this->e['empresaId'], 'Pais' => null, 'Oportunidad' => '', 'ubi' => '', 'tipoUbi' => '', 'CodiSeguiClien' => '', 'CodiSegui' => '',
                'ClienteFinal' => '', 'Link' => '', 'EstaCapi' => '0', 'EstaConta' => '0', 'Usuasign' => '0', 'CodiTipoInpu' => '', 'Motivo' => null,
                'MotivosReproId' => null, 'IdProyec' => '', 'NumeDanfe' => '', 'EstaDanfe' => '', 'pagiActu' => $pagina, 'idPage' => 5];
    }

    private static function normNombre(string $s): string {
        return trim((string)preg_replace('/\s+/u', ' ', sgsIoSinTildes(mb_strtolower($s, 'UTF-8'))));
    }

    private static function opciones(string $html): array {
        preg_match_all('/<option\s+value=[\'"](\d+)[\'"][^>]*>([^<]+)<\/option>/i', $html, $m, PREG_SET_ORDER);
        return array_map(static fn($x) => [$x[1], trim(html_entity_decode($x[2], ENT_QUOTES, 'UTF-8'))], $m);
    }

    private static function elegir(array $ops, string $nombre, string $que): string {
        $n = self::normNombre($nombre);
        foreach ($ops as [$v, $t]) if (self::normNombre($t) === $n) return $v;
        foreach ($ops as [$v, $t]) if (str_contains(self::normNombre($t), $n)) return $v;
        throw new RuntimeException("Phoenix no muestra $que «{$nombre}» para este usuario.");
    }

    /** Id de GESTIÓN PROVINCIA en la lista de empresas del usuario (se busca por nombre una vez al día). */
    private function empresaId(): string {
        $t = $this->multi([['ruta' => '/default.aspx/cargarEmpresas', 'json' => ['PaisId' => 0, 'lstEmpresa' => ''], 'referer' => self::BASE . '/default.aspx']], 1)[0];
        return self::elegir(self::opciones((string)(self::decodificar((string)$t)['resul'] ?? '')), getenv('WINBO_EMPRESA') ?: 'GESTIÓN PROVINCIA', 'la empresa');
    }

    /** Los mismos pasos que hizo el ingreso (sin contraseña): datos del usuario, empresa, área y abrir la página de Órdenes. */
    private function resincronizar(): void {
        $ref = self::BASE . '/default.aspx';
        $paso = fn(string $ruta, ?array $json) => (string)$this->multi([['ruta' => $ruta, 'json' => $json, 'referer' => $ref]], 1)[0];
        $paso('/default.aspx/ObtenerDatosUsua', []);
        $paso('/default.aspx/modificarUsuarioAE', ['CodiArea' => 0, 'EmpresaId' => $this->e['empresaId'], 'Tipo' => 'E']);
        $paso('/default.aspx/cargarIdiomas', []);
        $areas = self::opciones((string)(self::decodificar($paso('/default.aspx/cargarAreas', ['DepartamentoId' => 0, 'lstAreas' => '']))['resul'] ?? ''));
        $area = (string)getenv('WINBO_AREA');
        $id = $area === '' ? ($areas[0][0] ?? null) : self::elegir($areas, $area, 'el área');
        if ($id !== null) $paso('/default.aspx/modificarUsuarioAE', ['CodiArea' => $id, 'EmpresaId' => 0, 'Tipo' => 'A']);
        $paso('/default.aspx', null);
        $paso(self::URL_GRILLA, null);
    }

    /** Qué órdenes necesitan su historial (igual que fuente.traer_ordenes). Pasa las filas leídas a la memoria del día. */
    private function prepararHistoriales(): void {
        $c = &$this->e['ciclo'];
        $previas = $this->e['ordenes'];
        $pedir = [];
        foreach ($c['vistos'] as $id => $f) {
            $p = $previas[$id] ?? null;
            $f['firma'] = implode('|', [$f['estado'], $f['cuadrilla'], $f['fvisita'], $f['inicioVisita']]);
            $abierta = !in_array(sgsIoNormEstado($f['estado']), self::FINALES, true);
            // Las que están En camino / Iniciada / Revisión se releen en CADA vuelta (para ver al instante cuando cambian).
            $enCurso = $p && in_array($p['ultimoEstado'] ?? '', self::EN_CURSO, true);
            $f['historial'] = $p['historial'] ?? '';
            $f['histTs'] = $p['histTs'] ?? 0;
            $f['firmaPrevia'] = $p['firma'] ?? '';
            $f['ultimoEstado'] = $p['ultimoEstado'] ?? sgsIoNormEstado($f['estado']);
            if (!$p || ($p['historial'] ?? '') === '' || ($p['firma'] ?? '') !== $f['firma'] || $enCurso
                || ($abierta && time() - (int)($p['histTs'] ?? 0) > self::REVISAR_ABIERTAS)) $pedir[] = (string)$id;
            $this->e['ordenes'][$id] = $f;
        }
        $c['vistos'] = [];
        $c['pedir'] = $pedir;
        $c['pedidos'] = count($pedir);
        $c['intentos'] = [];
        $this->e['fase'] = 'historial';
    }

    // ── 2) Historial de estados ──────────────────────────────
    private function pasoHistorial(): void {
        $c = &$this->e['ciclo'];
        if (!$c['pedir']) {
            foreach ($this->e['ordenes'] as $id => $f) {
                $ev = sgsIoEventos($f['historial'] ?? '');
                $this->e['ordenes'][$id]['ultimoEstado'] = $ev ? end($ev)['estado'] : sgsIoNormEstado($f['estado']);
                unset($this->e['ordenes'][$id]['firmaPrevia']);
            }
            $this->e['actualizado'] = time();
            $this->e['leidoDesde'] = $c['inicio'];      // hora en que empezó esta lectura (para los cortes)
            $this->e['fase'] = 'espera';
            $this->e['ciclo'] = null;
            return;
        }
        $lote = array_splice($c['pedir'], 0, self::PARALELO_HISTORIAL);
        $textos = $this->multi(array_map(static fn($id) => ['ruta' => '/Paginas/OrdenTrabajo/Formulario.aspx/CargarHistoEstaGrilla',
            'json' => ['OrdenId' => (string)$id, 'IdPage' => '_Formu'], 'referer' => self::BASE . '/Paginas/OrdenTrabajo/grilla.aspx'], $lote));
        foreach ($lote as $i => $id) {
            $h = $textos[$i] === false ? null : self::historial(self::decodificar($textos[$i]));
            if ($h === null) {
                $c['intentos'][$id] = ($c['intentos'][$id] ?? 0) + 1;
                if ($c['intentos'][$id] < self::INTENTOS) { $c['pedir'][] = $id; continue; }
                // Se queda con lo que tenía y se reintenta en la próxima vuelta.
                $this->e['ordenes'][$id]['firma'] = $this->e['ordenes'][$id]['firmaPrevia'] ?? '';
                continue;
            }
            $this->e['ordenes'][$id]['historial'] = $h;
            $this->e['ordenes'][$id]['histTs'] = time();
        }
    }

    /**
     * Historial con el MISMO formato de la columna "Historial Estados" del Excel ("Fecha: dd/mm/aaaa hh:mm:ss Estado: X Usuario: Y
     * Observacion: ... Cuadrilla Asignada: Z;"), en orden de tiempo. Null si Winbo no respondió.
     */
    private static function historial(array $d): ?string {
        $html = (string)($d['html'] ?? '');
        if ($html === '') return null;
        $tablas = self::tablas($html);
        if (!$tablas) return '';
        usort($tablas, static fn($a, $b) => count($b) <=> count($a));
        $partes = [];
        foreach ($tablas[0] as $fila) {
            $c = $fila['celdas'];
            if (count($c) < 4 || sgsIoNormEncabezado($c[0]) === 'FECHA') continue;
            $at = self::fechaHistorial(trim($c[0]));
            if ($at === null) continue;
            $datos = str_replace(';', ',', $c[4] ?? '');
            $obs = str_replace(';', ',', $c[5] ?? '');
            $partes[] = rtrim("Fecha: $at Estado: " . trim($c[1]) . ' Usuario: ' . trim($c[3]) . " Observacion: $obs $datos");
        }
        return implode(';', array_reverse($partes));     // Winbo lo muestra del más nuevo al más viejo; el Excel va en orden
    }

    /** '11/19/2026 8:03:05 AM' o '19/11/2026 08:03:05' → '19/11/2026 08:03:05'. */
    private static function fechaHistorial(string $s): ?string {
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4}) (\d{1,2}):(\d{1,2}):(\d{1,2}) ?([AP])\.? ?M\.?$/i', $s, $m)) {
            $h = (int)$m[4] % 12 + (strtoupper($m[7]) === 'P' ? 12 : 0);
            if ((int)$m[4] < 1 || (int)$m[4] > 12) return null;
            return sprintf('%02d/%02d/%04d %02d:%02d:%02d', $m[2], $m[1], $m[3], $h, $m[5], $m[6]);
        }
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4}) (\d{1,2}):(\d{1,2}):(\d{1,2})$/', $s, $m))
            return sprintf('%02d/%02d/%04d %02d:%02d:%02d', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]);
        return null;
    }

    // ── Lectura de tablas HTML ───────────────────────────────
    /**
     * Filas de la grilla. Desde la página 2 Winbo no manda la fila de títulos: se usan los de la página 1 ($cab).
     * Devuelve [filas, títulos]. Si faltan columnas necesarias, lo dice claro (no se muestra vacío).
     */
    public static function filasGrilla(string $html, ?array $cab): array {
        $tablas = array_values(array_filter(self::tablas($html)));
        if (!$tablas) return [[], $cab];
        $conTh = array_values(array_filter($tablas, static fn($t) => $t[0]['th']));
        if ($conTh) {
            $t = $conTh[0];
            foreach ($conTh as $x) if (count($x) > count($t)) $t = $x;
            $cab = array_map('sgsIoNormEncabezado', $t[0]['celdas']);
            array_shift($t);
        } elseif ($cab) {
            $t = $tablas[0];
            foreach ($tablas as $x) if (count($x) > count($t)) $t = $x;
        } else {
            return [[], $cab];
        }
        $col = static function (string ...$nombres) use ($cab): int {
            foreach ($nombres as $n) { $i = array_search(sgsIoNormEncabezado($n), $cab, true); if ($i !== false) return (int)$i; }
            return -1;
        };
        $c = ['orden' => $col('Código De Seguimiento Cliente'), 'cuad' => $col('Cuadrilla'), 'est' => $col('Estado OT'), 'loc' => $col('Localidad'),
              'prov' => $col('Provincia'), 'partner' => $col('Proveedeor', 'Proveedor'), 'fv' => $col('Fecha Visita'), 'ini' => $col('Inicio de Visita'),
              'tt' => $col('Tipo Trabajo'), 'rz' => $col('Región / Zona')];
        $faltan = [];
        foreach (self::COLUMNAS as [$nombre, $k]) if ($c[$k] < 0) $faltan[] = $nombre;
        if ($faltan) throw new RuntimeException('La grilla de Winbo no trae las columnas: ' . implode(', ', $faltan)
            . '. En Winbo > Órdenes de Trabajo > Columnas, vuelve a marcarlas.');
        $filas = [];
        foreach ($t as $r) {
            if (!preg_match('/abrirModal5\s*\(\s*[\'"]?\s*(\d+)/', $r['attrs'], $m)) continue;
            $g = static fn(string $k) => $c[$k] >= 0 ? ($r['celdas'][$c[$k]] ?? '') : '';
            $rz = $c['rz'] >= 0 ? ($r['partes'][$c['rz']] ?? []) : [];
            $filas[] = ['ordenId' => $m[1], 'orden' => sgsIoLimpiar($g('orden')), 'cuadrilla' => sgsIoLimpiar($g('cuad')),
                        'proveedor' => sgsIoLimpiar($g('partner')),
                        // Winbo: "Provincia" = departamento, "Localidad" = provincia, "Zona" = distrito.
                        'departamento' => sgsIoDepartamento($g('prov')), 'provincia' => sgsIoLimpiar($g('loc') ?: $g('prov')),
                        'region' => sgsIoLimpiar($rz[0] ?? ''), 'zona' => count($rz) > 1 ? mb_strtoupper(sgsIoLimpiar(end($rz)), 'UTF-8') : '',
                        'estado' => sgsIoLimpiar($g('est')), 'tipoTrabajo' => sgsIoLimpiar($g('tt')),
                        'fvisita' => self::fvisita($g('fv')), 'inicioVisita' => $g('ini')];
        }
        return [$filas, $cab];
    }

    private static function fvisita(string $s): string {
        $s = trim($s);
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})(?: (\d{2}):(\d{2})(?::\d{2})?)?$/', $s, $m)) return "$m[3]-$m[2]-$m[1] " . (!empty($m[4]) ? "$m[4]:$m[5]" : '00:00');
        return $s;
    }

    /** Tablas HTML → filas con celdas (texto), partes de cada celda (líneas separadas por <p>, <div> o <br>) y atributos. */
    private static function tablas(string $html): array {
        if (stripos($html, '<table') === false && stripos($html, '<tr') !== false) $html = "<table>$html</table>";   // páginas 2+
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><html><body>' . $html . '</body></html>', LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT);
        libxml_clear_errors(); libxml_use_internal_errors($prev);
        $tablas = [];
        foreach ($dom->getElementsByTagName('table') as $tabla) {
            $filas = [];
            foreach ($tabla->getElementsByTagName('tr') as $tr) {
                for ($p = $tr->parentNode; $p && $p->nodeName !== 'table'; $p = $p->parentNode);
                if ($p !== $tabla) continue;                     // fila de una tabla anidada
                $celdas = []; $partes = []; $th = false; $attrs = '';
                foreach ([$tr, ...iterator_to_array($tr->getElementsByTagName('*'))] as $el)
                    foreach ($el->attributes as $a) $attrs .= ' ' . $a->name . '=' . $a->value;
                foreach ($tr->childNodes as $td) {
                    if ($td->nodeName !== 'td' && $td->nodeName !== 'th') continue;
                    $th = $th || $td->nodeName === 'th';
                    $lineas = [''];
                    self::lineas($td, $lineas);
                    $ps = array_values(array_filter(array_map(static fn($x) => trim((string)preg_replace('/[\s\x{00A0}]+/u', ' ', $x)), $lineas), static fn($x) => $x !== ''));
                    $partes[] = $ps;
                    $celdas[] = implode(' ', $ps);
                }
                if ($celdas) $filas[] = ['celdas' => $celdas, 'partes' => $partes, 'th' => $th, 'attrs' => $attrs];
            }
            $tablas[] = $filas;
        }
        return $tablas;
    }

    private static function lineas(DOMNode $n, array &$lineas): void {
        foreach ($n->childNodes as $h) {
            if ($h instanceof DOMText) { $lineas[count($lineas) - 1] .= $h->data; continue; }
            if (in_array($h->nodeName, ['p', 'div', 'br'], true)) $lineas[] = '';     // línea aparte (p. ej. Región / Zona)
            self::lineas($h, $lineas);
        }
    }

    // ── HTTP ─────────────────────────────────────────────────
    /**
     * Varias peticiones a Phoenix a la vez (curl_multi), con las cookies de la sesión (solo lectura: no las cambia).
     * Cada una: ruta, json (null = GET) y referer. Devuelve el texto de cada una o false si falló (con un reintento si fue 5xx).
     */
    private function multi(array $pets, int $paralelo = 0): array {
        $res = [];
        if ($paralelo === 1) {                           // en orden (p. ej. abrir la grilla y luego pedir la página 1)
            foreach ($pets as $i => $p) $res[$i] = $this->multi([$p])[0];
            return $res;
        }
        $mh = curl_multi_init();
        $hs = [];
        foreach ($pets as $i => $p) {
            $ch = curl_init(self::BASE . $p['ruta']);
            $cab = ['Referer: ' . $p['referer']];
            $op = (is_file('C:/xampp/apache/bin/curl-ca-bundle.crt') ? [CURLOPT_CAINFO => 'C:/xampp/apache/bin/curl-ca-bundle.crt'] : []) + [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => self::SEGUNDOS_WINBO,
                CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => self::UA, CURLOPT_COOKIEFILE => $this->cookie, CURLOPT_ENCODING => ''];
            if ($p['json'] !== null) {
                $op[CURLOPT_POST] = true;
                $op[CURLOPT_POSTFIELDS] = json_encode($p['json'], JSON_UNESCAPED_UNICODE);
                array_push($cab, 'Content-Type: application/json; charset=UTF-8', 'X-Requested-With: XMLHttpRequest',
                    'Accept: application/json, text/javascript, */*; q=0.01', 'Origin: ' . self::BASE);
            }
            $op[CURLOPT_HTTPHEADER] = $cab;
            curl_setopt_array($ch, $op);
            curl_multi_add_handle($mh, $ch);
            $hs[$i] = $ch;
        }
        do {
            $st = curl_multi_exec($mh, $activos);
            if ($activos) curl_multi_select($mh, 1.0);
        } while ($activos && $st === CURLM_OK);
        $reintentar = [];
        foreach ($hs as $i => $ch) {
            $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $texto = curl_multi_getcontent($ch);
            $res[$i] = ($texto === null || $codigo === 0 || $codigo >= 500) ? false : (string)$texto;
            if ($res[$i] === false && empty($pets[$i]['reintento'])) $reintentar[$i] = $pets[$i] + ['reintento' => true];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        if ($reintentar) { usleep(700000); foreach ($this->multi($reintentar) as $i => $t) $res[$i] = $t; }
        return $res;
    }

    // Winbo responde {"d": base64(JSON con campos en base64)} (igual que WinboService).
    private static function b64($s): ?string {
        if (!is_string($s) || strlen($s) < 2 || !preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $s)) return null;
        $limpio = rtrim((string)preg_replace('/\s+/', '', $s), '=');
        $pad = (4 - strlen($limpio) % 4) % 4;
        if ($pad === 3) return null;
        $t = base64_decode($limpio . str_repeat('=', $pad), true);
        if ($t === false || !mb_check_encoding($t, 'UTF-8')) return null;
        $raros = (int)preg_match_all('/[^\P{C}\r\n\t]/u', $t);
        return ((mb_strlen($t, 'UTF-8') - $raros) / max(mb_strlen($t, 'UTF-8'), 1)) > 0.85 ? $t : null;
    }
    private static function decodificar(string $http): array {
        $e = json_decode($http, true);
        if (!is_array($e) || !array_key_exists('d', $e)) return is_array($e) ? $e : [];
        $texto = is_string($e['d']) ? $e['d'] : '';
        $i = json_decode(self::b64($texto) ?? $texto, true);
        if (!is_array($i)) return [];
        foreach ($i as $k => $v) { $s = self::b64($v); if ($s !== null) $i[$k] = $s; }
        return $i;
    }

    // ── Para la pantalla y Telegram ──────────────────────────
    /** Tablero de hoy en tiempo real (con la hora de ahora). */
    public function tablero(): array {
        $this->cargar();
        $ahora = self::ahora();
        return sgsIoArmarTablero(array_values($this->e['ordenes']), $this->e['dia'], $ahora->format('Y-m-d H:i:s'));
    }

    /** Cómo va la lectura: sesión, última vuelta, avance de la vuelta en curso, error y último envío automático. */
    public function estado(): array {
        if (!$this->e) $this->cargar();
        $c = $this->e['ciclo'];
        $avance = null;
        if ($this->e['fase'] === 'grilla' && $c) $avance = 'Leyendo la grilla' . ($c['paginas'] ? ': ' . count($c['vistos']) . ' de ' . $c['total'] . ' órdenes' : '');
        if ($this->e['fase'] === 'historial' && $c) $avance = 'Leyendo historiales: ' . ($c['pedidos'] - count($c['pedir'])) . ' de ' . $c['pedidos'];
        $s = sgsPhoenixSesion();
        $modo = WinboService::sesionLectura();
        $tg = json_decode((string)@file_get_contents($this->dir . '/telegram.json'), true);
        $h = (int)self::ahora()->format('G');
        return ['sesion' => $modo, 'sesionVence' => $modo === 'aqui' ? date('H:i', (int)$s['vence']) : null,
                'actualizado' => $this->e['actualizado'] ? date('H:i:s', (int)$this->e['actualizado']) : null,
                'proxima' => $this->e['actualizado'] ? date('H:i', (int)$this->e['actualizado'] + self::VUELTA) : null,
                'avance' => $avance, 'error' => $this->e['error'], 'aviso' => $this->e['aviso'], 'ordenes' => count($this->e['ordenes']),
                'enHorario' => $h >= self::LECTURA_DESDE && $h < self::LECTURA_HASTA,
                'horario' => sprintf('%02d:00 a %02d:00', self::LECTURA_DESDE, self::LECTURA_HASTA),
                'telegramCortes' => array_keys(self::CORTES),
                'ultimoEnvio' => is_array($tg) && str_starts_with((string)($tg['hora'] ?? ''), $this->e['dia']) ? $tg : null];
    }

    /**
     * Reportes automáticos en los CORTES (08:06 primera orden; 10:30, 12:30, 15:30 y 17:30 alertas): una sola vez cada corte aunque haya varios usuarios
     * (marca compartida en telegram.json). Sale recién cuando terminó una lectura de Winbo hecha DESPUÉS de la hora del corte (el
     * turno la empieza al llegar el corte), así nunca va con datos desfasados. Si en la hora siguiente al corte nadie entró a
     * ProviWIN, ese corte se salta (no se manda atrasado).
     */
    private function telegramCortes(): void {
        $corte = $this->cortePendiente();
        if ($corte === null || !sgsIoTelegramConfigurado()) return;
        if ((int)($this->e['leidoDesde'] ?? 0) < $corte['desde']) return;    // la lectura empezada desde el corte todavía no termina
        $ahora = self::ahora();
        $archivo = $this->dir . '/telegram.json';
        $hora = $ahora->format('Y-m-d ') . $corte['corte'];
        @file_put_contents($archivo, json_encode(['hora' => $hora, 'enviado' => null], JSON_UNESCAPED_UNICODE), LOCK_EX);   // primero la marca
        try {
            $res = sgsIoEnviarATodos($this->tablero(), $corte['reporte'], $ahora);
        } catch (Throwable $ex) {
            $res = [['grupo' => 'Todos', 'partner' => '', 'ok' => false, 'error' => $ex->getMessage()]];
        }
        @file_put_contents($archivo, json_encode(['hora' => $hora, 'enviado' => $ahora->format('H:i'), 'resultados' => $res], JSON_UNESCAPED_UNICODE), LOCK_EX);
        foreach ($res as $r) if (!$r['ok']) win_log_error('inicio_ordenes_telegram', 'No se envió un reporte automático', $r);
    }
}
