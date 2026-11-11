<?php
// 02_BACKEND/src/services/WinboService.php - Lectura de órdenes de Winbo para "Órdenes cercanas" (2026-11-08).
// Credenciales solo en .env (WINBO_*): nunca salen al navegador. La sesión se reutiliza y se renueva sola.
// Liviano: cada visita lee una sola página de Winbo y el resultado se guarda 2 minutos para todos los usuarios.
// Grupos (2026-11-10): elegir empresa en Winbo no filtra la grilla (las tres empresas devuelven las mismas órdenes), así que
// el grupo de cada usuario se aplica aquí con las columnas Provincia y Tipo trabajo de cada orden.
class WinboService {
    private const BASE = 'https://winbo-phx.azurewebsites.net';
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36';
    private const URL_GRILLA = '/Paginas/OrdenTrabajo/grilla.aspx?to=1&nombre=Ordenes+de+Trabajo&id=5&icono=&edit=S';
    private const PATRONES_ID = ['/abrirModal5_Formu\s*\(\s*[\'"]?\s*(\d+)/', '/abrirModal5\s*\(\s*[\'"]?\s*(\d+)/', '/resultadoSeleccciona5\s*\(\s*[\'"]?\s*(\d+)/'];
    private const SEGUNDOS_WINBO = 25;
    private const SEGUNDOS_CACHE = 120;
    /** Grupos: GP = provincia (instalaciones y visitas técnicas) · SGI = Lima instalaciones · SGA = Lima visitas técnicas. */
    public const GRUPOS = ['GP', 'SGI', 'SGA'];
    private const PROVINCIAS_LIMA = ['lima', 'callao'];

    private string $dir, $cookie, $cols;

    public function __construct() {
        $this->dir = BASE_PATH . '/08_LOGS/runtime/winbo';
        if (!is_dir($this->dir)) @mkdir($this->dir, 0775, true);
        $this->cookie = $this->dir . '/sesion.cookie';
        $this->cols = $this->dir . '/columnas.json';
    }

    /**
     * Grupos que puede consultar un usuario (2026-11-10). Provincia (Piura, Lambayeque, La Libertad, Áncash, Junín, Cusco,
     * Arequipa) → GP (Gestión provincia: instalaciones y visitas técnicas). Lima → SGI (instalaciones) o SGA (visitas técnicas)
     * según su área de trabajo; si tiene las dos, elige en la ventana. Administración elige entre los tres.
     */
    public static function gruposDe(array $u): array {
        if (in_array($u['rol'] ?? '', [Auth::ROLE_ADMINISTRADOR, Auth::ROLE_GERENTE, Auth::ROLE_COORDINADOR], true)) return self::GRUPOS;
        $zonas = sgsUserZones((int)($u['id'] ?? 0));      // null = todas las provincias
        $lima = ['LIMA', 'CALLAO', 'HUACHO', 'NORTE CHICO'];
        $empresas = ($zonas === null || array_diff($zonas, $lima)) ? ['GP'] : [];
        if ($zonas !== null && array_intersect($zonas, $lima)) {
            $area = mb_strtolower(sgsUserArea((int)$u['id']), 'UTF-8');
            $instalaciones = str_contains($area, 'instal');
            $vt = (bool)preg_match('/\bvt\b|visita|aver/u', $area);
            if ($instalaciones || !$vt) $empresas[] = 'SGI';
            if ($vt || !$instalaciones) $empresas[] = 'SGA';
        }
        return $empresas ?: ['GP'];
    }

    public static function configurado(): bool {
        return getenv('WINBO_USUARIO') && getenv('WINBO_CLAVE') && getenv('WINBO_EMPRESA') && getenv('WINBO_AREA');
    }

    /** Fecha de hoy en Perú (UTC-5), leída de la cabecera Date de un sitio de internet; null si no hay conexión. */
    public static function fechaInternet(): ?string {
        foreach (['https://www.google.com', 'https://www.cloudflare.com'] as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, self::opcionesCa() + [CURLOPT_NOBODY => true, CURLOPT_HEADER => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3]);
            $r = curl_exec($ch);
            curl_close($ch);
            if ($r && preg_match('/^date:\s*(.+)$/mi', (string)$r, $m) && ($t = strtotime(trim($m[1])))) return gmdate('d/m/Y', $t - 5 * 3600);
        }
        return null;
    }

    /** Certificados: en el hosting, los del sistema; en XAMPP, su lista propia. */
    private static function opcionesCa(): array {
        $xampp = 'C:/xampp/apache/bin/curl-ca-bundle.crt';
        return is_file($xampp) ? [CURLOPT_CAINFO => $xampp] : [];
    }

    // ── Página de órdenes con caché compartida ──────────────
    /** Primera página del día, solo con las órdenes del grupo: total, páginas y puntos. */
    public function inicio(string $estadoNombre, string $fecha, string $grupo): array {
        $p = $this->conCache($this->clave($estadoNombre, $fecha, 1), function () use ($estadoNombre, $fecha) {
            $codigo = $this->codigoEstado($estadoNombre);
            $p = $this->leerPagina($codigo, $fecha, 1);
            foreach (glob($this->dir . '/orden_*.json') ?: [] as $viejo) if (time() - (int)@filemtime($viejo) > 3600) @unlink($viejo);
            return ['fecha' => $fecha, 'codigo' => $codigo, 'total' => $p['total'], 'paginas' => (int)ceil($p['total'] / max($p['filas'], 1)), 'puntos' => $p['puntos']];
        });
        return ['puntos' => self::delGrupo($p['puntos'], $grupo)] + $p;
    }

    /** Una página siguiente (solo existe si antes se abrió la primera de ese día), con las órdenes del grupo. */
    public function pagina(string $estadoNombre, string $fecha, int $n, string $grupo): array {
        $base = json_decode((string)@file_get_contents($this->ruta($this->clave($estadoNombre, $fecha, 1))), true);
        if (!is_array($base) || $n < 2 || $n > (int)$base['paginas']) throw new InvalidArgumentException('Vuelve a escanear las órdenes.');
        $p = $this->conCache($this->clave($estadoNombre, $fecha, $n), fn() => ['puntos' => $this->leerPagina((string)$base['codigo'], $fecha, $n)['puntos']]);
        return ['puntos' => self::delGrupo($p['puntos'], $grupo)];
    }

    /** GP = fuera de Lima · SGI = Lima e instalación · SGA = Lima y visita técnica (todo lo que no es instalación). */
    private static function delGrupo(array $puntos, string $grupo): array {
        if (!in_array($grupo, self::GRUPOS, true)) throw new InvalidArgumentException('Grupo no válido.');
        $quedan = array_filter($puntos, static fn($p) => $grupo === 'GP' ? !$p['lima'] : ($p['lima'] && $p['inst'] === ($grupo === 'SGI')));
        return array_values(array_map(static fn($p) => array_diff_key($p, ['lima' => 0, 'inst' => 0]), $quedan));
    }

    private function clave(string $estado, string $fecha, int $n): string {
        return 'orden_' . strtolower($estado) . '_' . str_replace('/', '', $fecha) . '_' . $n;
    }
    private function ruta(string $clave): string { return $this->dir . '/' . $clave . '.json'; }

    /** Si varios usuarios piden lo mismo a la vez, solo uno consulta a Winbo y los demás reciben ese resultado. */
    private function conCache(string $clave, callable $leer): array {
        $archivo = $this->ruta($clave);
        $fresco = static fn() => is_file($archivo) && time() - (int)filemtime($archivo) < self::SEGUNDOS_CACHE;
        if ($fresco()) return json_decode((string)file_get_contents($archivo), true);
        $candado = fopen($archivo . '.lock', 'c');
        flock($candado, LOCK_EX);
        try {
            if ($fresco()) return json_decode((string)file_get_contents($archivo), true);
            $datos = $leer();
            file_put_contents($archivo, json_encode($datos, JSON_UNESCAPED_UNICODE), LOCK_EX);
            return $datos;
        } finally {
            flock($candado, LOCK_UN); fclose($candado); @unlink($archivo . '.lock');
        }
    }

    // ── HTTP ─────────────────────────────────────────────────
    private function http(string $ruta, ?array $json, string $referer, bool $guardar = false): string {
        $ch = curl_init(self::BASE . $ruta);
        $cab = ['Referer: ' . $referer];
        $op = self::opcionesCa() + [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => self::SEGUNDOS_WINBO,
               CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => self::UA, CURLOPT_COOKIEFILE => $this->cookie];
        if ($guardar) $op[CURLOPT_COOKIEJAR] = $this->cookie;   // solo el inicio de sesión escribe las cookies
        if ($json !== null) {
            $op[CURLOPT_POST] = true;
            $op[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE);
            array_push($cab, 'Content-Type: application/json; charset=UTF-8', 'X-Requested-With: XMLHttpRequest', 'Accept: application/json, text/javascript, */*; q=0.01', 'Origin: ' . self::BASE);
        }
        $op[CURLOPT_HTTPHEADER] = $cab;
        curl_setopt_array($ch, $op);
        $r = curl_exec($ch);
        if ($r === false) { usleep(700000); $r = curl_exec($ch); }   // Winbo a veces corta la conexión: un reintento
        curl_close($ch);
        if ($r === false) throw new RuntimeException('No se pudo conectar con Winbo. Inténtalo en unos minutos.');
        return (string)$r;
    }
    private function post(string $ruta, array $json, bool $guardar = false): string {
        return $this->http($ruta, $json, self::BASE . '/default.aspx', $guardar);
    }

    // Winbo responde {"d": base64(JSON con campos en base64)}.
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
    private static function opciones(string $html): array {
        preg_match_all('/<option\s+value=[\'"](\d+)[\'"][^>]*>([^<]+)<\/option>/i', $html, $m, PREG_SET_ORDER);
        return array_map(static fn($x) => [$x[1], trim(html_entity_decode($x[2], ENT_QUOTES, 'UTF-8'))], $m);
    }
    private static function norm(string $s): string {
        $s = strtr(mb_strtolower($s, 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        return trim((string)preg_replace('/\s+/u', ' ', $s));
    }
    private static function elegir(array $ops, string $nombre, string $que): string {
        $n = self::norm($nombre);
        foreach ($ops as [$v, $t]) if (self::norm($t) === $n) return $v;
        foreach ($ops as [$v, $t]) if (str_contains(self::norm($t), $n)) return $v;
        throw new RuntimeException("Winbo no tiene {$que} configurado para este módulo.");
    }

    // ── Sesión ───────────────────────────────────────────────
    /** Con candado: si varias peticiones la pierden a la vez, solo una vuelve a entrar. */
    private function conectar(): void {
        $f = fopen($this->dir . '/login.lock', 'c');
        flock($f, LOCK_EX);
        try {
            $marca = $this->dir . '/login.ok';         // hora del último ingreso correcto (las cookies cambian en cada consulta)
            if (is_file($this->cookie) && is_file($marca) && time() - (int)filemtime($marca) < 20) return;  // otra petición acaba de entrar
            @unlink($this->cookie);
            $login = self::BASE . '/login.aspx';
            $this->http('/login.aspx', null, $login, true);
            $r = $this->http('/login.aspx/IniciarSesion', ['CodiUsua' => (string)getenv('WINBO_USUARIO'), 'Contraseña' => (string)getenv('WINBO_CLAVE'),
                'CodiSuscrip' => getenv('WINBO_SUSCRIP') ?: 'WIN', 'Navegador' => 'Navegador Chrome version:152.0 sobre Windows', 'Query' => '', 'AutenDoblePasoCodi' => '', 'LoginInterno' => 'N'], $login, true);
            if ((string)(self::decodificar($r)['codigo'] ?? '') !== '99') { @unlink($this->cookie); throw new RuntimeException('Winbo rechazó el acceso configurado para este módulo.'); }
            @chmod($this->cookie, 0600);
            $this->http('/login.aspx/VerificarTermiCondi', [], $login, true);
            $this->post('/default.aspx/ObtenerDatosUsua', [], true);
            $this->http('/default.aspx', null, self::BASE . '/default.aspx', true);
            $emp = self::opciones((string)(self::decodificar($this->post('/default.aspx/cargarEmpresas', ['PaisId' => 0, 'lstEmpresa' => ''], true))['resul'] ?? ''));
            $this->post('/default.aspx/modificarUsuarioAE', ['CodiArea' => 0, 'EmpresaId' => self::elegir($emp, (string)getenv('WINBO_EMPRESA'), 'la empresa'), 'Tipo' => 'E'], true);
            $this->post('/default.aspx/cargarIdiomas', [], true);
            $are = self::opciones((string)(self::decodificar($this->post('/default.aspx/cargarAreas', ['DepartamentoId' => 0, 'lstAreas' => ''], true))['resul'] ?? ''));
            $this->post('/default.aspx/modificarUsuarioAE', ['CodiArea' => self::elegir($are, (string)getenv('WINBO_AREA'), 'el área'), 'EmpresaId' => 0, 'Tipo' => 'A'], true);
            $this->http(self::URL_GRILLA, null, self::BASE . '/default.aspx', true);
            @touch($marca);
        } finally {
            flock($f, LOCK_UN); fclose($f);
        }
    }

    // ── Lectura de Winbo ─────────────────────────────────────
    private function codigoEstado(string $nombre): string {
        if (!is_file($this->cookie)) $this->conectar();
        for ($i = 0; $i < 2; $i++) {
            $d = json_decode($this->post('/default.aspx/CargarComboJsonX', ['parametro' => '', 'combo' => 'EstadoOrden', 'valor' => '', 'filtro' => '', 'minFiltroChar' => 0, 'todos' => true]), true)['d'] ?? null;
            if (is_string($d)) $d = json_decode(self::b64($d) ?? $d, true);
            $ops = [];
            foreach (is_array($d) ? $d : [] as $it) if (isset($it['id'], $it['text'])) $ops[] = [(string)$it['id'], (string)$it['text']];
            if ($ops) return self::elegir($ops, $nombre, 'el estado');
            if ($i === 0) $this->conectar();             // sin opciones: la sesión cayó
        }
        throw new RuntimeException('Winbo no devolvió los estados de orden.');
    }

    private function consulta(string $estado, string $fecha, int $pagina): array {
        $p = ['tipoOrden' => 1, 'tipoTraba' => '', 'estado' => $estado, 'OrdenId' => '', 'NumeDocu' => '', 'Nombre' => '', 'suscrip' => '',
              'fechaEstaDesde' => '', 'fechaEstaHasta' => '', 'fechaVisiDesde' => $fecha, 'fechaVisiHasta' => $fecha, 'fechaSoliDesde' => '', 'fechaSoliHasta' => '',
              'conexion' => '0', 'cuadrilla' => null, 'provincia' => null, 'localidad' => null, 'region' => null, 'zona' => null, 'tipoProduc' => null, 'producto' => null,
              'Empresa' => null, 'Pais' => null, 'Oportunidad' => '', 'ubi' => '', 'tipoUbi' => '', 'CodiSeguiClien' => '', 'CodiSegui' => '', 'ClienteFinal' => '', 'Link' => '',
              'EstaCapi' => '0', 'EstaConta' => '0', 'Usuasign' => '0', 'CodiTipoInpu' => '', 'Motivo' => null, 'MotivosReproId' => null, 'IdProyec' => '', 'NumeDanfe' => '', 'EstaDanfe' => '',
              'pagiActu' => $pagina, 'idPage' => 5];
        if ($pagina === 1) $this->http(self::URL_GRILLA, null, self::BASE . '/default.aspx');
        return self::decodificar($this->http('/Paginas/OrdenTrabajo/Grilla.aspx/cargarGrilla', $p, self::BASE . self::URL_GRILLA));
    }

    private function leerPagina(string $estado, string $fecha, int $n): array {
        if (!is_file($this->cookie)) $this->conectar();
        $d = $this->consulta($estado, $fecha, $n);
        $vacia = static fn($x) => empty($x['html']) && (string)($x['registros'] ?? '') !== '0';
        if ($vacia($d)) {                                // la sesión cayó: vuelve a entrar y repite
            $this->conectar();
            $d = $this->consulta($estado, $fecha, $n);
            if ($vacia($d)) throw new RuntimeException('Winbo no devolvió las órdenes. Inténtalo en unos minutos.');
        }
        return $this->interpretar((string)($d['html'] ?? ''), (int)($d['registros'] ?? 0));
    }

    private function interpretar(string $html, int $total): array {
        $tabla = self::tablaPrincipal($html);
        $puntos = []; $filas = 0;
        if ($tabla) {
            if ($tabla[0]['th']) {
                $cols = self::columnas($tabla[0]['celdas']);
                array_shift($tabla);
                if ($cols['geo'] !== null) @file_put_contents($this->cols, json_encode($cols), LOCK_EX);
            } else {
                $cols = json_decode((string)@file_get_contents($this->cols), true);
            }
            if (!is_array($cols) || in_array(null, $cols, true) || count($cols) < 6) throw new RuntimeException('Winbo cambió el formato de sus órdenes.');
            foreach ($tabla as $f) {
                $c = $f['celdas'];
                if (count($c) <= $cols['geo']) continue;
                $filas++;
                $xy = self::coords($c[$cols['geo']]);
                if ($xy) $puntos[] = ['id' => $f['id'], 'tipo' => $c[$cols['tipo']] ?? '', 'cuadrilla' => $c[$cols['cuad']] ?? '',
                    'fecha' => $c[$cols['fecha']] ?? '', 'direccion' => $c[$cols['dir']] ?? '', 'lat' => $xy[0], 'lon' => $xy[1],
                    'lima' => in_array(self::norm($c[$cols['prov']] ?? ''), self::PROVINCIAS_LIMA, true),
                    'inst' => str_starts_with(self::norm($c[$cols['tipo']] ?? ''), 'instalacion')];
            }
        }
        return ['total' => $total, 'filas' => $filas, 'puntos' => $puntos];
    }

    private static function columnas(array $h): array {
        $n = array_map([self::class, 'norm'], $h);
        $buscar = static function (callable $f) use ($n) { foreach ($n as $i => $x) if ($f($x)) return $i; return null; };
        return ['cuad' => $buscar(static fn($x) => str_starts_with($x, 'cuadrilla')),
                'fecha' => $buscar(static fn($x) => str_starts_with($x, 'fecha visita')),
                'dir' => $buscar(static fn($x) => str_starts_with($x, 'direccion')),
                'geo' => $buscar(static fn($x) => str_starts_with($x, 'georeferencia') || str_starts_with($x, 'georreferencia')),
                'prov' => $buscar(static fn($x) => $x === 'provincia'),
                'tipo' => $buscar(static fn($x) => str_starts_with($x, 'tipo trabajo') || str_starts_with($x, 'tipo de trabajo'))];
    }

    private static function coords(string $t): ?array {
        if (!preg_match('/(-?\d{1,3}(?:[.,]\d+)?)\s*[,;]\s*(-?\d{1,3}(?:[.,]\d+)?)/', $t, $m)) return null;
        $lat = (float)str_replace(',', '.', $m[1]); $lon = (float)str_replace(',', '.', $m[2]);
        if (abs($lat) > 90 || abs($lon) > 180 || ($lat == 0 && $lon == 0)) return null;
        return [round($lat, 7), round($lon, 7)];
    }

    private static function tablaPrincipal(string $html): ?array {
        if ($html === '') return null;
        if (stripos($html, '<tr') !== false && stripos($html, '<table') === false) $html = "<table>{$html}</table>";
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><html><body>' . preg_replace('/<br\s*\/?>/i', ' ', $html) . '</body></html>', LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT);
        libxml_clear_errors(); libxml_use_internal_errors($prev);
        $mejor = null; $mejorTh = false;
        foreach ($dom->getElementsByTagName('table') as $t) {
            $filas = self::filasDe($t);
            if (!$filas) continue;
            $th = $filas[0]['th'];
            if (($th && !$mejorTh) || ($th === $mejorTh && count($filas) > count($mejor ?? []))) { $mejor = $filas; $mejorTh = $th; }
        }
        return $mejor;
    }

    private static function filasDe(DOMElement $t): array {
        $filas = [];
        foreach ($t->getElementsByTagName('tr') as $tr) {
            for ($p = $tr->parentNode; $p && $p->nodeName !== 'table'; $p = $p->parentNode);
            if ($p !== $t) continue;                     // fila de una tabla anidada
            $celdas = []; $th = false;
            foreach ($tr->childNodes as $c) {
                if ($c->nodeName !== 'td' && $c->nodeName !== 'th') continue;
                $th = $th || $c->nodeName === 'th';
                $celdas[] = trim((string)preg_replace('/[\s\x{00A0}]+/u', ' ', $c->textContent));
            }
            if ($celdas) $filas[] = ['th' => $th, 'celdas' => $celdas, 'id' => self::idFila($tr, $celdas)];
        }
        return $filas;
    }

    private static function idFila(DOMElement $tr, array $celdas): string {
        $attrs = '';
        foreach ([$tr, ...iterator_to_array($tr->getElementsByTagName('*'))] as $e)
            foreach ($e->attributes as $a)
                if (in_array($a->name, ['onclick', 'href', 'ondblclick'], true) || str_starts_with($a->name, 'data-')) $attrs .= ' ' . $a->value;
        foreach (self::PATRONES_ID as $p) if (preg_match($p, $attrs, $m)) return $m[1];
        foreach ($celdas as $c) if (preg_match('/^\d{6,10}$/', $c)) return $c;
        return '';
    }
}
