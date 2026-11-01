<?php
// 02_BACKEND/src/repositories/PerfilRepository.php - Único lugar con SQL de perfiles (2026-10-31).
// Tablas: perfiles, perfil_permisos, perfil_asignables (y usuarios.perfil_id).

final class PerfilRepository
{
    public function __construct(private PDO $pdo) {}

    /** Perfiles con sus trabajadores activos, permisos y perfiles que asignan (3 consultas para toda la lista). */
    public function listar(): array
    {
        $perfiles = $this->pdo->query("SELECT p.id, p.nombre, p.codigo, p.descripcion, p.rol_base, p.alcance,
                (SELECT COUNT(*) FROM usuarios u WHERE u.perfil_id = p.id AND u.activo = 1) AS trabajadores,
                (SELECT COUNT(*) FROM usuarios u WHERE u.perfil_id = p.id) AS usuarios
            FROM perfiles p ORDER BY FIELD(p.rol_base, 'Administrador', 'Gerente', 'Coordinador', 'Jefe de operaciones', 'Supervisor'), p.id")->fetchAll(); // por jerarquía, como el organigrama
        $permisos = $asignables = [];
        foreach ($this->pdo->query('SELECT perfil_id, permiso FROM perfil_permisos')->fetchAll() as $r) $permisos[(int)$r['perfil_id']][] = $r['permiso'];
        foreach ($this->pdo->query('SELECT perfil_id, asignable_id FROM perfil_asignables')->fetchAll() as $r) $asignables[(int)$r['perfil_id']][] = (int)$r['asignable_id'];
        return array_map(static fn($p) => ['id' => (int)$p['id'], 'trabajadores' => (int)$p['trabajadores'], 'usuarios' => (int)$p['usuarios'],
            'permisos' => $permisos[(int)$p['id']] ?? [], 'asignables' => $asignables[(int)$p['id']] ?? []] + $p, $perfiles);
    }

    public function obtener(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT p.*, (SELECT COUNT(*) FROM usuarios u WHERE u.perfil_id = p.id) AS usuarios FROM perfiles p WHERE p.id = :id');
        $st->execute([':id' => $id]);
        return $st->fetch() ?: null;
    }

    /** Perfil base de un rol: el primero con ese tipo base (lo usan los usuarios sin perfil). */
    public function baseDeRol(string $rol): ?int
    {
        $st = $this->pdo->prepare('SELECT id FROM perfiles WHERE rol_base = :r ORDER BY id LIMIT 1');
        $st->execute([':r' => $rol]);
        return ($id = $st->fetchColumn()) ? (int)$id : null;
    }

    /** Perfiles que puede asignar quien tiene el perfil $id. */
    public function asignablesDe(int $id): array
    {
        $st = $this->pdo->prepare('SELECT asignable_id FROM perfil_asignables WHERE perfil_id = :id');
        $st->execute([':id' => $id]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** ¿Otro perfil ya usa ese nombre o ese código? Devuelve cuál. */
    public function duplicado(string $nombre, string $codigo, int $exceptoId): ?string
    {
        $st = $this->pdo->prepare('SELECT nombre, codigo FROM perfiles WHERE (nombre = :n OR codigo = :c) AND id <> :id LIMIT 1');
        $st->execute([':n' => $nombre, ':c' => $codigo, ':id' => $exceptoId]);
        $r = $st->fetch();
        return $r ? (mb_strtolower($r['nombre']) === mb_strtolower($nombre) ? 'nombre' : 'código') : null;
    }

    public function crear(array $d): int
    {
        $this->pdo->prepare('INSERT INTO perfiles (nombre, codigo, descripcion, rol_base, alcance) VALUES (:n, :c, :d, :r, :a)')
            ->execute([':n' => $d['nombre'], ':c' => $d['codigo'], ':d' => $d['descripcion'] ?: null, ':r' => $d['rol_base'], ':a' => $d['alcance']]);
        return (int)$this->pdo->lastInsertId();
    }

    public function actualizar(int $id, array $d): void
    {
        $this->pdo->prepare('UPDATE perfiles SET nombre = :n, codigo = :c, descripcion = :d, rol_base = :r, alcance = :a WHERE id = :id')
            ->execute([':n' => $d['nombre'], ':c' => $d['codigo'], ':d' => $d['descripcion'] ?: null, ':r' => $d['rol_base'], ':a' => $d['alcance'], ':id' => $id]);
    }

    public function guardarAsignables(int $id, array $asignables): void
    {
        $this->pdo->prepare('DELETE FROM perfil_asignables WHERE perfil_id = :id')->execute([':id' => $id]);
        $ins = $this->pdo->prepare('INSERT INTO perfil_asignables (perfil_id, asignable_id) VALUES (:p, :a)');
        foreach ($asignables as $a) $ins->execute([':p' => $id, ':a' => $a]);
    }

    public function guardarPermisos(int $id, array $permisos): void
    {
        $this->pdo->prepare('DELETE FROM perfil_permisos WHERE perfil_id = :id')->execute([':id' => $id]);
        $ins = $this->pdo->prepare('INSERT INTO perfil_permisos (perfil_id, permiso) VALUES (:p, :x)');
        foreach ($permisos as $p) $ins->execute([':p' => $id, ':x' => $p]);
    }

    /** Ids de perfiles que existen (de una lista). */
    public function existentes(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return [];
        return array_map('intval', $this->pdo->query('SELECT id FROM perfiles WHERE id IN (' . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Cuántos perfiles tienen ese tipo base. */
    public function cuantosDeRol(string $rol): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM perfiles WHERE rol_base = :r');
        $st->execute([':r' => $rol]);
        return (int)$st->fetchColumn();
    }

    public function eliminar(int $id): void
    {
        $this->pdo->prepare('DELETE FROM perfiles WHERE id = :id')->execute([':id' => $id]);
    }
}
