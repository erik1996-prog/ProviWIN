<?php
// 02_BACKEND/src/repositories/UsuarioRepository.php - Único lugar con SQL de usuarios, permisos y zonas.
// Tablas: usuarios, partners, perfiles (solo lectura; 2026-10-31), usuario_departamentos_acceso.
// Perfil efectivo: el suyo o, si no tiene (personal sin acceso, datos anteriores), el perfil base de su rol.

final class UsuarioRepository
{
    public function __construct(private PDO $pdo) {}

    public function listar(array $f): array
    {
        $sql = "SELECT u.id, u.dni, u.nombres, u.apellidos, u.nombre_completo, u.usuario, u.email, u.telefono_personal,
                       u.telefono_corporativo, u.rol, u.partner_id, p.nombre_partner, u.sede, u.zona, u.area_trabajo, u.activo,
                       u.fecha_creacion, u.ultimo_acceso, u.debe_cambiar_password, u.por_activar, pf.id AS perfil_id, pf.nombre AS perfil_nombre
                FROM usuarios u
                LEFT JOIN partners p ON p.id = u.partner_id
                LEFT JOIN perfiles pf ON pf.id = COALESCE(u.perfil_id, (SELECT b.id FROM perfiles b WHERE b.rol_base = u.rol ORDER BY b.id LIMIT 1))
                WHERE 1=1";
        $params = [];
        if ($f['q'] !== '') {
            $sql .= " AND (u.nombre_completo LIKE :q1 OR u.usuario LIKE :q2 OR u.dni LIKE :q3 OR u.email LIKE :q4)";
            foreach (['q1', 'q2', 'q3', 'q4'] as $k) $params[":$k"] = '%' . $f['q'] . '%';
        }
        if ($f['perfil'] > 0) { $sql .= ' AND pf.id = :perfil'; $params[':perfil'] = $f['perfil']; }
        // "Activos" incluye al personal recién registrado que espera acceso (sin usuario), para que no se pierda de vista.
        if ($f['estado'] === 'activos') $sql .= " AND (u.activo = 1 OR u.usuario IS NULL OR u.usuario = '')";
        if ($f['estado'] === 'inactivos') $sql .= " AND u.activo = 0 AND u.usuario IS NOT NULL AND u.usuario <> ''";
        if ($f['organizacion'] === 'directo') $sql .= ' AND ' . sgsDirectStaffSql();
        elseif ($f['organizacion'] !== '' && ctype_digit($f['organizacion'])) { $sql .= ' AND u.partner_id = :pid'; $params[':pid'] = (int)$f['organizacion']; }
        $sql .= ' ORDER BY u.activo DESC, u.nombre_completo ASC';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function resumen(): array
    {
        return $this->pdo->query("SELECT COUNT(*) total, SUM(u.activo = 1) activos,
                SUM(u.activo = 1 AND " . sgsDirectStaffSql() . ") directos,
                SUM(u.activo = 1 AND NOT " . sgsDirectStaffSql() . ") partners,
                SUM(u.activo = 1 AND u.rol = 'Administrador') administradores
            FROM usuarios u LEFT JOIN partners p ON p.id = u.partner_id")->fetch() ?: [];
    }

    public function obtener(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT u.*, p.nombre_partner, pf.id AS perfil_id, pf.nombre AS perfil_nombre
            FROM usuarios u LEFT JOIN partners p ON p.id = u.partner_id LEFT JOIN perfiles pf ON pf.id = COALESCE(u.perfil_id, (SELECT b.id FROM perfiles b WHERE b.rol_base = u.rol ORDER BY b.id LIMIT 1)) WHERE u.id = :id LIMIT 1");
        $st->execute([':id' => $id]);
        $u = $st->fetch();
        return $u ?: null;
    }

    public function existeDuplicado(string $dni, string $email, string $usuario, int $exceptoId): ?string
    {
        $st = $this->pdo->prepare("SELECT dni, email, usuario FROM usuarios WHERE (dni = :d OR email = :e OR usuario = :u) AND id <> :id LIMIT 1");
        $st->execute([':d' => $dni, ':e' => $email, ':u' => $usuario, ':id' => $exceptoId]);
        $r = $st->fetch();
        if (!$r) return null;
        return $r['dni'] === $dni ? 'DNI' : (mb_strtolower((string)$r['email']) === $email ? 'correo' : 'usuario');
    }

    public function usuarioDisponible(string $base): string
    {
        $candidate = $base; $n = 1;
        $st = $this->pdo->prepare('SELECT 1 FROM usuarios WHERE usuario = :u LIMIT 1');
        while (true) { $st->execute([':u' => $candidate]); if (!$st->fetchColumn()) return $candidate; $candidate = $base . (++$n); }
    }

    public function crear(array $d, string $hash, bool $activo = true): int
    {
        $st = $this->pdo->prepare("INSERT INTO usuarios (dni, nombres, apellidos, nombre_completo, usuario, email, telefono_personal,
                telefono_corporativo, password_hash, rol, perfil_id, partner_id, sede, zona, area_trabajo, procesos_copiados, modalidad, turno, activo, por_activar, fecha_creacion)
            VALUES (:dni, :nom, :ape, :nc, :usr, :email, :tp, :tc, :hash, :rol, :perfil, :pid, :sede, :zona, :area, :procesos, :mod, :turno, :activo, :por_activar, NOW())");
        $st->execute($this->campos($d) + [':hash' => $hash, ':activo' => $activo ? 1 : 0, ':por_activar' => $activo ? 0 : 1]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Trabajador registrado solo con sus datos personales (2026-10-12): sin usuario, con una contraseña imposible
     * de adivinar e inactivo, hasta que el administrador le asigne usuario, contraseña, rol y zonas.
     */
    public function crearPersonal(array $d, string $hash): int
    {
        // Por defecto: turno Día y modalidad Presencial (el administrador puede cambiarlos al completar el acceso).
        $st = $this->pdo->prepare("INSERT INTO usuarios (dni, nombres, apellidos, nombre_completo, usuario, email, telefono_personal,
                telefono_corporativo, area_trabajo, turno, modalidad, password_hash, rol, partner_id, sede, zona, activo, fecha_creacion)
            VALUES (:dni, :nom, :ape, :nc, NULL, :email, :tp, :tc, :area, 'Día', 'PRESENCIAL', :hash, 'Supervisor', :pid, :sede, :zona, 0, NOW())");
        $st->execute([':dni' => $d['dni'], ':nom' => $d['nombres'], ':ape' => $d['apellidos'], ':nc' => $d['nombres'] . ' ' . $d['apellidos'],
            ':email' => $d['email'], ':tp' => $d['telefono_personal'] ?: null, ':tc' => $d['telefono_corporativo'] ?: null, ':area' => $d['area_trabajo'] ?: null,
            ':hash' => $hash,
            ':pid' => $d['partner_id'] ?: null, ':sede' => $d['sede'] ?: null,
            ':zona' => $d['sede'] === '' ? 'Provincias' : mb_convert_case(mb_strtolower($d['sede']), MB_CASE_TITLE)]);
        return (int)$this->pdo->lastInsertId();
    }

    /** Solo datos personales: no toca usuario, contraseña, rol, organización, zonas ni estado. */
    public function actualizarPersonal(int $id, array $d): void
    {
        $this->pdo->prepare("UPDATE usuarios SET dni = :dni, nombres = :nom, apellidos = :ape, nombre_completo = :nc, email = :email,
                telefono_personal = :tp, telefono_corporativo = :tc, area_trabajo = :area WHERE id = :id")
            ->execute([':dni' => $d['dni'], ':nom' => $d['nombres'], ':ape' => $d['apellidos'], ':nc' => $d['nombres'] . ' ' . $d['apellidos'],
                ':email' => $d['email'], ':tp' => $d['telefono_personal'] ?: null, ':tc' => $d['telefono_corporativo'] ?: null,
                ':area' => $d['area_trabajo'] ?: null, ':id' => $id]);
    }

    public function activar(int $id): void
    {
        $this->pdo->prepare('UPDATE usuarios SET activo = 1, por_activar = 0 WHERE id = :id')->execute([':id' => $id]);
    }

    /** Queda inactivo hasta que la cuenta principal lo active (2026-10-26). */
    public function dejarPorActivar(int $id): void
    {
        $this->pdo->prepare('UPDATE usuarios SET activo = 0, por_activar = 1 WHERE id = :id')->execute([':id' => $id]);
    }

    /** Aviso a los administradores activos. */
    public function notificarAdministradores(string $titulo, string $mensaje, string $url): void
    {
        $ins = $this->pdo->prepare('INSERT INTO notificaciones (usuario_id, titulo, mensaje, url_destino) VALUES (:u, :t, :m, :url)');
        foreach ($this->pdo->query("SELECT id FROM usuarios WHERE rol = 'Administrador' AND activo = 1")->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
            $ins->execute([':u' => (int)$adminId, ':t' => $titulo, ':m' => $mensaje, ':url' => $url]);
        }
    }

    public function actualizar(int $id, array $d): void
    {
        $st = $this->pdo->prepare("UPDATE usuarios SET dni = :dni, nombres = :nom, apellidos = :ape, nombre_completo = :nc, usuario = :usr,
                email = :email, telefono_personal = :tp, telefono_corporativo = :tc, rol = :rol, perfil_id = :perfil, partner_id = :pid, sede = :sede,
                zona = :zona, area_trabajo = :area, procesos_copiados = :procesos, modalidad = :mod, turno = :turno WHERE id = :id");
        $st->execute($this->campos($d) + [':id' => $id]);
    }

    /** $debeCambiar (2026-10-15): la persona tendrá que cambiarla al ingresar (contraseña puesta por el administrador). */
    public function cambiarPassword(int $id, string $hash, bool $debeCambiar = false): void
    {
        // También se limpia la copia reversible heredada (password_admin_encrypted): SGS no la usa.
        $this->pdo->prepare('UPDATE usuarios SET password_hash = :h, password_admin_encrypted = NULL, debe_cambiar_password = :d WHERE id = :id')
            ->execute([':h' => $hash, ':d' => $debeCambiar ? 1 : 0, ':id' => $id]);
    }

    public function passwordHash(int $id): ?string
    {
        $st = $this->pdo->prepare('SELECT password_hash FROM usuarios WHERE id = :id');
        $st->execute([':id' => $id]);
        $h = $st->fetchColumn();
        return $h === false ? null : (string)$h;
    }

    public function avatar(int $id): ?string
    {
        $st = $this->pdo->prepare('SELECT avatar FROM usuarios WHERE id = :id');
        $st->execute([':id' => $id]);
        $a = $st->fetchColumn();
        return $a === false || $a === null ? null : (string)$a;
    }

    public function cambiarAvatar(int $id, ?string $ruta): void
    {
        $this->pdo->prepare('UPDATE usuarios SET avatar = :a WHERE id = :id')->execute([':a' => $ruta, ':id' => $id]);
    }

    public function cambiarEstado(int $id, bool $activo): void
    {
        // Fecha de cese (2026-10-28, Matriz de asistencia): el día en que se desactiva; al reactivarlo se borra.
        $this->pdo->prepare('UPDATE usuarios SET activo = :a, por_activar = 0, fecha_cese = ' . ($activo ? 'NULL' : 'COALESCE(fecha_cese, CURDATE())') . ' WHERE id = :id')
            ->execute([':a' => $activo ? 1 : 0, ':id' => $id]);
    }

    public function administradoresActivos(int $exceptoId = 0): int
    {
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE rol = 'Administrador' AND activo = 1 AND id <> :id");
        $st->execute([':id' => $exceptoId]);
        return (int)$st->fetchColumn();
    }

    public function partners(): array
    {
        return $this->pdo->query("SELECT id, nombre_partner FROM partners WHERE estado = 1 ORDER BY nombre_partner")->fetchAll();
    }

    public function partnerWinDirectoId(): ?int
    {
        $id = $this->pdo->query("SELECT id FROM partners WHERE UPPER(TRIM(nombre_partner)) IN ('WIN DIRECTO','WIN') ORDER BY (UPPER(TRIM(nombre_partner))='WIN DIRECTO') DESC LIMIT 1")->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public function zonasAdicionales(int $userId): array
    {
        $st = $this->pdo->prepare('SELECT UPPER(departamento) FROM usuario_departamentos_acceso WHERE usuario_id = :u AND activo = 1 ORDER BY departamento');
        $st->execute([':u' => $userId]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Zonas adicionales de varios usuarios en una sola consulta: [id => [departamentos]]. */
    public function zonasAdicionalesDe(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return [];
        $map = [];
        foreach ($this->pdo->query('SELECT usuario_id, UPPER(departamento) AS departamento FROM usuario_departamentos_acceso WHERE activo = 1 AND usuario_id IN (' . implode(',', $ids) . ') ORDER BY departamento')->fetchAll() as $a)
            $map[(int)$a['usuario_id']][] = $a['departamento'];
        return $map;
    }

    public function guardarZonas(int $userId, array $zonas, int $otorgadoPor): void
    {
        $this->pdo->prepare('UPDATE usuario_departamentos_acceso SET activo = 0, fecha_actualizacion = NOW() WHERE usuario_id = :u')->execute([':u' => $userId]);
        $upd = $this->pdo->prepare('UPDATE usuario_departamentos_acceso SET activo = 1, otorgado_por = :by, fecha_actualizacion = NOW() WHERE usuario_id = :u AND UPPER(departamento) = :d');
        $ins = $this->pdo->prepare('INSERT INTO usuario_departamentos_acceso (usuario_id, departamento, activo, otorgado_por, fecha_registro, fecha_actualizacion) VALUES (:u, :d, 1, :by, NOW(), NOW())');
        foreach ($zonas as $z) {
            $upd->execute([':u' => $userId, ':d' => $z, ':by' => $otorgadoPor]);
            if ($upd->rowCount() === 0) $ins->execute([':u' => $userId, ':d' => $z, ':by' => $otorgadoPor]);
        }
    }


    private function campos(array $d): array
    {
        return [
            ':dni' => $d['dni'], ':nom' => $d['nombres'], ':ape' => $d['apellidos'], ':nc' => $d['nombres'] . ' ' . $d['apellidos'],
            ':usr' => $d['usuario'], ':email' => $d['email'], ':tp' => $d['telefono_personal'] ?: null, ':tc' => $d['telefono_corporativo'] ?: null,
            ':rol' => $d['rol'], ':perfil' => $d['perfil_id'], ':pid' => $d['partner_id'] ?: null, ':sede' => $d['sede'] ?: null, ':zona' => $d['sede'] === 'PROVINCIAS' || $d['sede'] === '' ? 'Provincias' : mb_convert_case(mb_strtolower($d['sede']), MB_CASE_TITLE),
            ':area' => $d['area_trabajo'] ?: null, ':procesos' => ($d['procesos_copiados'] ?? '') ?: null,
            ':mod' => $d['modalidad'] ?: null, ':turno' => $d['turno'] ?: null,
        ];
    }
}
