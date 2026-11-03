-- Reversión de 2026-11-01_04: vuelve a juntar "Supervisor de SGI" y "Supervisor de SGA" en "Supervisor SGI/SGA" (SSG),
-- como quedó la primera versión de 2026-11-01_02. Los usuarios de SSGA pasan al perfil juntado.
UPDATE usuarios u JOIN perfiles a ON a.id = u.perfil_id AND a.codigo = 'SSGA' JOIN perfiles i ON i.codigo = 'SSGI' SET u.perfil_id = i.id;
DELETE pa FROM perfil_asignables pa JOIN perfiles a ON a.codigo = 'SSGA' WHERE pa.perfil_id = a.id OR pa.asignable_id = a.id;
DELETE pp FROM perfil_permisos pp JOIN perfiles a ON a.id = pp.perfil_id AND a.codigo = 'SSGA';
DELETE FROM perfiles WHERE codigo = 'SSGA';
UPDATE perfiles SET nombre = 'Supervisor SGI/SGA', codigo = 'SSG',
    descripcion = 'Remoto: hace seguimiento a los gestores (SGI en instalaciones, SGA en visitas técnicas, según su área).' WHERE codigo = 'SSGI';
