<?php
// 02_BACKEND/src/repositories/PerfilRepository.php - SQL de perfiles de acceso (sgs_perfil).

final class PerfilRepository
{
    public function __construct(private PDO $pdo) {}

    public function listar(bool $soloActivos = false): array
    {
        $rows = $this->pdo->query("SELECT pf.PERFIL_ID id, pf.NOMBRE nombre, pf.DESCRIPCION descripcion, pf.ROL_BASE rol_base,
                pf.PERMISOS permisos, pf.ACTIVO activo, pf.FECHA_ACTUALIZACION actualizado,
                (SELECT COUNT(*) FROM sgs_usuario_perfil up JOIN usuarios u ON u.id = up.USUARIO_ID WHERE up.PERFIL_ID = pf.PERFIL_ID AND u.activo = 1) usuarios
            FROM sgs_perfil pf " . ($soloActivos ? 'WHERE pf.ACTIVO = 1 ' : '') . "ORDER BY pf.NOMBRE")->fetchAll();
        foreach ($rows as &$r) { $r['permisos'] = json_decode((string)$r['permisos'], true) ?: []; $r['id'] = (int)$r['id']; $r['usuarios'] = (int)$r['usuarios']; }
        return $rows;
    }

    public function obtener(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT PERFIL_ID id, NOMBRE nombre, DESCRIPCION descripcion, ROL_BASE rol_base, PERMISOS permisos, ACTIVO activo FROM sgs_perfil WHERE PERFIL_ID = :id');
        $st->execute([':id' => $id]);
        $r = $st->fetch();
        if (!$r) return null;
        $r['permisos'] = json_decode((string)$r['permisos'], true) ?: [];
        return $r;
    }

    public function nombreEnUso(string $nombre, int $exceptoId): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM sgs_perfil WHERE NOMBRE = :n AND PERFIL_ID <> :id LIMIT 1');
        $st->execute([':n' => $nombre, ':id' => $exceptoId]);
        return (bool)$st->fetchColumn();
    }

    public function guardar(int $id, array $d, int $usuarioId): int
    {
        $json = json_encode(array_values($d['permisos']));
        if ($id > 0) {
            $this->pdo->prepare('UPDATE sgs_perfil SET NOMBRE = :n, DESCRIPCION = :d, ROL_BASE = :r, PERMISOS = :p WHERE PERFIL_ID = :id')
                ->execute([':n' => $d['nombre'], ':d' => $d['descripcion'] ?: null, ':r' => $d['rol_base'], ':p' => $json, ':id' => $id]);
            return $id;
        }
        $this->pdo->prepare('INSERT INTO sgs_perfil (NOMBRE, DESCRIPCION, ROL_BASE, PERMISOS, CREADO_POR) VALUES (:n, :d, :r, :p, :by)')
            ->execute([':n' => $d['nombre'], ':d' => $d['descripcion'] ?: null, ':r' => $d['rol_base'], ':p' => $json, ':by' => $usuarioId]);
        return (int)$this->pdo->lastInsertId();
    }

    public function cambiarEstado(int $id, bool $activo): void
    {
        $this->pdo->prepare('UPDATE sgs_perfil SET ACTIVO = :a WHERE PERFIL_ID = :id')->execute([':a' => $activo ? 1 : 0, ':id' => $id]);
    }
}
