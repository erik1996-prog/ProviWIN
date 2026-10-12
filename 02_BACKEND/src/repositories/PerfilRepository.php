<?php
// 02_BACKEND/src/repositories/PerfilRepository.php - SQL de perfiles de acceso (sgs_perfil).

final class PerfilRepository
{
    public function __construct(private PDO $pdo) {}

    public function listar(bool $soloActivos = false): array
    {
        $rows = $this->pdo->query("SELECT pf.id, pf.nombre, pf.descripcion, pf.rol_base,
                pf.permisos, pf.activo, pf.fecha_actualizacion actualizado,
                (SELECT COUNT(*) FROM sgs_usuario_perfil up JOIN usuarios u ON u.id = up.usuario_id WHERE up.perfil_id = pf.id AND u.activo = 1) usuarios
            FROM sgs_perfil pf " . ($soloActivos ? 'WHERE pf.activo = 1 ' : '') . "ORDER BY pf.nombre")->fetchAll();
        foreach ($rows as &$r) { $r['permisos'] = json_decode((string)$r['permisos'], true) ?: []; $r['id'] = (int)$r['id']; $r['usuarios'] = (int)$r['usuarios']; }
        return $rows;
    }

    public function obtener(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id, nombre, descripcion, rol_base, permisos, activo FROM sgs_perfil WHERE id = :id');
        $st->execute([':id' => $id]);
        $r = $st->fetch();
        if (!$r) return null;
        $r['permisos'] = json_decode((string)$r['permisos'], true) ?: [];
        return $r;
    }

    public function nombreEnUso(string $nombre, int $exceptoId): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM sgs_perfil WHERE nombre = :n AND id <> :id LIMIT 1');
        $st->execute([':n' => $nombre, ':id' => $exceptoId]);
        return (bool)$st->fetchColumn();
    }

    public function guardar(int $id, array $d, int $usuarioId): int
    {
        $json = json_encode(array_values($d['permisos']));
        if ($id > 0) {
            $this->pdo->prepare('UPDATE sgs_perfil SET nombre = :n, descripcion = :d, rol_base = :r, permisos = :p WHERE id = :id')
                ->execute([':n' => $d['nombre'], ':d' => $d['descripcion'] ?: null, ':r' => $d['rol_base'], ':p' => $json, ':id' => $id]);
            return $id;
        }
        $this->pdo->prepare('INSERT INTO sgs_perfil (nombre, descripcion, rol_base, permisos, creado_por) VALUES (:n, :d, :r, :p, :by)')
            ->execute([':n' => $d['nombre'], ':d' => $d['descripcion'] ?: null, ':r' => $d['rol_base'], ':p' => $json, ':by' => $usuarioId]);
        return (int)$this->pdo->lastInsertId();
    }

    public function cambiarEstado(int $id, bool $activo): void
    {
        $this->pdo->prepare('UPDATE sgs_perfil SET activo = :a WHERE id = :id')->execute([':a' => $activo ? 1 : 0, ':id' => $id]);
    }
}
