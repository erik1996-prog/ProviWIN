<?php
// 02_BACKEND/src/validators/UsuarioValidator.php - Validación de datos de usuarios.

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
    public const ROLES = ['Administrador', 'Gerente', 'Coordinador', 'Supervisor', 'Jefe de operaciones'];
    /** Turnos de trabajo (2026-10-12: solo día y noche). */
    public const TURNOS = ['Día', 'Noche'];
    /** Área de trabajo: el equipo al que pertenece (2026-10-12). No da permisos. */
    public const AREAS = ['Instalaciones', 'Averías', 'Instalaciones y averías', 'Almacén'];
    /** Cargo: su puesto en la empresa (2026-10-12). No da permisos; eso lo define el rol del sistema. */
    public const CARGOS = ['Supervisor de campo', 'Supervisor de calidad', 'Supervisor UM', 'Coordinador', 'Jefe de operaciones', 'Jefe', 'Gerente',
        'Subgerente', 'Asistente', 'Asesor', 'Almacenero', 'Analista'];
    /** Procesos en los que se le copia en los correos (se guardan en usuarios.procesos_copiados). */
    public const PROCESOS = ['Liquidaciones', 'Penalidades', 'Gestión Phoenix'];

    /**
     * Valor de una lista fija. Se acepta vacío, un valor de la lista o el valor que ya tenía guardado
     * (registros anteriores a la lista, como "SUPERVISOR UM"), para no obligar a cambiarlo al editar otra cosa.
     */
    public static function deLista(string $valor, array $lista, ?string $actual, string $campo, string $nombre): string
    {
        if ($valor === '' || in_array($valor, $lista, true) || ($actual !== null && $valor === $actual)) return $valor;
        throw new ValidationException("Elija un {$nombre} de la lista.", $campo);
    }

    /** Procesos marcados (o "Todos") → texto guardado: "Liquidaciones, Penalidades". */
    public static function procesos($marcados): string
    {
        $marcados = array_map('strval', (array)$marcados);
        if (in_array('Todos', $marcados, true)) return implode(', ', self::PROCESOS);
        return implode(', ', array_values(array_intersect(self::PROCESOS, $marcados)));
    }


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
            'partner_id' => max(0, (int)($in['partner_id'] ?? 0)),
            'todas_zonas' => !empty($in['todas_zonas']),
            'area_trabajo' => self::text($in['area_trabajo'] ?? '', 100),
            'cargo' => self::text($in['cargo'] ?? '', 60),
            'procesos_copiados' => self::procesos($in['procesos'] ?? []),
            'modalidad' => self::text($in['modalidad'] ?? '', 30),
            'turno' => self::text($in['turno'] ?? '', 30),
            'password' => (string)($in['password'] ?? ''),
            'pedir_cambio' => !empty($in['pedir_cambio']) && $in['pedir_cambio'] !== 'false', // la pantalla lo envía marcado por defecto
            'zonas' => array_values(array_unique(array_filter(array_map(static fn($z) => mb_strtoupper(trim((string)$z)), (array)($in['zonas'] ?? []))))),
            'permisos' => array_values(array_unique(array_map('strval', (array)($in['permisos'] ?? [])))),
            'permisos_personalizados' => !empty($in['permisos_personalizados']),
        ];
        if (strlen($d['dni']) !== 8) throw new ValidationException('El DNI debe tener 8 dígitos.', 'dni');
        if ($d['nombres'] === '' || $d['apellidos'] === '') throw new ValidationException('Ingrese nombres y apellidos.', 'nombres');
        // 2026-10-12: el correo es libre (cada persona trae el suyo); ya no se fuerza @win.pe ni @partner.pe.
        if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) throw new ValidationException('Ingrese un correo electrónico válido.', 'email');
        // Turnos anteriores (Mañana, Tarde, Rotativo) pasan a Día, igual que en el cambio de base 2026-10-12.
        if (in_array($d['turno'], ['Mañana', 'Tarde', 'Rotativo'], true)) $d['turno'] = 'Día';
        if ($d['turno'] !== '' && !in_array($d['turno'], self::TURNOS, true)) throw new ValidationException('Seleccione el turno: Día o Noche.', 'turno');
        if ($d['usuario'] !== '' && !preg_match('/^[a-z0-9._-]{3,50}$/', $d['usuario'])) {
            throw new ValidationException('El usuario solo admite letras minúsculas, números, punto, guion y guion bajo (3 a 50).', 'usuario');
        }
        if (!in_array($d['rol'], self::ROLES, true)) throw new ValidationException('Seleccione un rol válido.', 'rol');
        // Zonas de trabajo = departamentos que el usuario puede ver (o todas).
        $validas = sgsOperationalDepartments();
        foreach ($d['zonas'] as $z) if (!in_array($z, $validas, true)) throw new ValidationException("Zona de trabajo no válida: $z.", 'zonas');
        if ($d['todas_zonas']) $d['zonas'] = [];
        if ($isNew || $d['password'] !== '') self::password($d['password']);
        return $d;
    }

    /** Solo datos personales: lo que puede registrar o corregir quien tiene "Agregar y editar personal". */
    public static function personal(array $in): array
    {
        $d = [
            'dni' => preg_replace('/\D/', '', (string)($in['dni'] ?? '')),
            'nombres' => self::text($in['nombres'] ?? '', 100),
            'apellidos' => self::text($in['apellidos'] ?? '', 100),
            'email' => mb_strtolower(trim((string)($in['email'] ?? ''))),
            'telefono_personal' => self::text($in['telefono_personal'] ?? '', 20),
            'telefono_corporativo' => self::text($in['telefono_corporativo'] ?? '', 20),
            'area_trabajo' => self::text($in['area_trabajo'] ?? '', 100),
            'cargo' => self::text($in['cargo'] ?? '', 60),
        ];
        if (strlen($d['dni']) !== 8) throw new ValidationException('El DNI debe tener 8 dígitos.', 'dni');
        if ($d['nombres'] === '' || $d['apellidos'] === '') throw new ValidationException('Ingrese nombres y apellidos.', 'nombres');
        if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) throw new ValidationException('Ingrese un correo electrónico válido.', 'email');
        return $d;
    }

    public static function password(string $password): void
    {
        if (mb_strlen($password) < 10 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            throw new ValidationException('La contraseña debe tener al menos 10 caracteres, con letras y números.', 'password');
        }
    }

    private static function text($value, int $max): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', (string)$value)), 0, $max);
    }
}
