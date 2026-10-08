<?php
// 02_BACKEND/src/validators/UsuarioValidator.php - Validación de datos de usuarios y perfiles.

final class ValidationException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}

final class UsuarioValidator
{
    /** Roles que SGS permite asignar (SGA y SGI no tienen permisos definidos). */
    public const ROLES = ['Administrador', 'Gerente', 'Coordinador', 'Supervisor', 'Partner'];


    public static function usuario(array $in, bool $isNew): array
    {
        $d = [
            'dni' => preg_replace('/\D/', '', (string)($in['dni'] ?? '')),
            'nombres' => self::text($in['nombres'] ?? '', 100),
            'apellidos' => self::text($in['apellidos'] ?? '', 100),
            'usuario' => mb_strtolower(trim((string)($in['usuario'] ?? ''))),
            'email' => mb_strtolower(trim((string)($in['email'] ?? ''))),
            'telefono_personal' => self::text($in['telefono_personal'] ?? '', 20),
            'telefono_corporativo' => self::text($in['telefono_corporativo'] ?? '', 20),
            'rol' => trim((string)($in['rol'] ?? '')),
            'perfil_id' => max(0, (int)($in['perfil_id'] ?? 0)),
            'partner_id' => max(0, (int)($in['partner_id'] ?? 0)),
            'todas_zonas' => !empty($in['todas_zonas']),
            'area_trabajo' => self::text($in['area_trabajo'] ?? '', 100),
            'modalidad' => self::text($in['modalidad'] ?? '', 30),
            'turno' => self::text($in['turno'] ?? '', 30),
            'password' => (string)($in['password'] ?? ''),
            'zonas' => array_values(array_unique(array_filter(array_map(static fn($z) => mb_strtoupper(trim((string)$z)), (array)($in['zonas'] ?? []))))),
            'permisos' => array_values(array_unique(array_map('strval', (array)($in['permisos'] ?? [])))),
            'permisos_personalizados' => !empty($in['permisos_personalizados']),
        ];
        if (strlen($d['dni']) !== 8) throw new ValidationException('El DNI debe tener 8 dígitos.', 'dni');
        if ($d['nombres'] === '' || $d['apellidos'] === '') throw new ValidationException('Ingrese nombres y apellidos.', 'nombres');
        if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) throw new ValidationException('Ingrese un correo electrónico válido.', 'email');
        if ($d['usuario'] !== '' && !preg_match('/^[a-z0-9._-]{3,50}$/', $d['usuario'])) {
            throw new ValidationException('El usuario solo admite letras minúsculas, números, punto, guion y guion bajo (3 a 50).', 'usuario');
        }
        if ($d['perfil_id'] === 0 && !in_array($d['rol'], self::ROLES, true)) throw new ValidationException('Seleccione un rol válido.', 'rol');
        // Zonas de trabajo = departamentos que el usuario puede ver (o todas).
        $validas = sgsOperationalDepartments();
        foreach ($d['zonas'] as $z) if (!in_array($z, $validas, true)) throw new ValidationException("Zona de trabajo no válida: $z.", 'zonas');
        if ($d['todas_zonas']) $d['zonas'] = [];
        if ($isNew || $d['password'] !== '') self::password($d['password']);
        return $d;
    }

    public static function password(string $password): void
    {
        if (mb_strlen($password) < 10 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            throw new ValidationException('La contraseña debe tener al menos 10 caracteres, con letras y números.', 'password');
        }
    }

    public static function perfil(array $in): array
    {
        $d = [
            'nombre' => self::text($in['nombre'] ?? '', 80),
            'descripcion' => self::text($in['descripcion'] ?? '', 255),
            'rol_base' => trim((string)($in['rol_base'] ?? '')),
            'permisos' => array_values(array_unique(array_map('strval', (array)($in['permisos'] ?? [])))),
        ];
        if (mb_strlen($d['nombre']) < 3) throw new ValidationException('El nombre del perfil debe tener al menos 3 caracteres.', 'nombre');
        if (!in_array($d['rol_base'], self::ROLES, true)) throw new ValidationException('Seleccione el rol base del perfil.', 'rol_base');
        if (in_array(mb_strtolower($d['nombre']), array_map('mb_strtolower', self::ROLES), true)) {
            throw new ValidationException('El nombre no puede ser igual a un rol del sistema.', 'nombre');
        }
        return $d;
    }

    private static function text($value, int $max): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', (string)$value)), 0, $max);
    }
}
