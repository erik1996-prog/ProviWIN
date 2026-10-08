# Requerimientos — SGS

## Contexto del negocio
Sector telecomunicaciones. **WIN** es la empresa principal; las **contratas** (partners) ejecutan parte del trabajo de campo.
SGS controla la supervisión de campo en provincias y mide a cada actor según su rol.

## Roles y qué se controla de cada uno

| Rol | Qué hace en SGS | Qué se le mide (control) |
|---|---|---|
| **Administrador** | Ve y gestiona todo con su propia cuenta y rol. | — |
| **Gerente / Coordinador** | Dashboard, Línea de tiempo, Monitor de turnos, Control de observaciones, Supervisiones. | — |
| **Supervisor directo WIN** | Registra su **inicio y fin de turno** y sus pausas, registra supervisiones y observaciones, y **transfiere órdenes pendientes** a supervisores de partner. | **Seguimiento total:** turno (inicio, fin, pausas), supervisiones por día (matriz), línea de tiempo, traslados. |
| **Supervisor de partner** | **Mi agenda**: las órdenes que un supervisor directo (o el administrador) le transfirió, para atenderlas. **Observaciones de mi partner**: hallazgos y checklists de su partner **en su zona de trabajo**, con sus descargos. | **Solo el tiempo de respuesta.** No tiene turno ni línea de tiempo. |

## Reglas de negocio

1. **Turno obligatorio (personal directo).** Sin turno iniciado hoy, la API rechaza el registro de supervisiones. Después del fin de turno tampoco se permite registrar.
2. **Transferencia a partner.** Solo el administrador o un supervisor directo pueden transferir una orden *Pendiente*. El destino debe ser un supervisor activo del **mismo partner** de la orden y con acceso a su departamento. Se notifica al supervisor del partner.
3. **Plazo de 24 horas.** Desde la transferencia (`fecha_asignacion`), el partner tiene 24 h para registrar su atención. Si vence:
   - la orden queda **bloqueada**: la API rechaza la atención;
   - cuenta como **incumplimiento** en el indicador *Cumplimiento de partners* del Dashboard.
4. **Indicadores del partner:**
   - órdenes asignadas;
   - atendidas a tiempo;
   - bloqueadas;
   - en plazo;
   - respuesta promedio;
   - % de cumplimiento = a tiempo ÷ (a tiempo + bloqueadas).
5. **Seguimiento solo del personal directo.** La Línea de tiempo, el monitor de turnos y la matriz mensual excluyen a los supervisores de partner (`sgsDirectStaffSql()` en `02_BACKEND/src/config/territorial.php`).
6. **Observaciones del partner.** El usuario de un partner ve y responde solo las observaciones de su partner y de sus **zonas de trabajo** (departamentos marcados en Usuarios y roles). Con "Todas las zonas" ve todas las de su partner. Sin zona asignada no ve ninguna.
7. **Cada uno con su cuenta.** No existe forma de actuar como otro usuario (la *vista provisional* se retiró el 2026-10-06). El turno y las supervisiones los registra cada supervisor desde su propia cuenta.
8. **SLA de observaciones.** Si vence el plazo de respuesta sin descargo, la observación pasa a "Penalidad Directa" y el checklist a "Penalizado".
9. **Zonas de trabajo en lugar de sede.** (2026-10-06) Al crear o editar un usuario ya no se elige departamento ni sede/provincia: se marcan sus *zonas de trabajo* o "Todas las zonas" (obligatorio para Supervisor y Partner). El rol define qué puede hacer; las zonas, qué puede ver. Internamente la primera zona se guarda en `usuarios.sede` (compatibilidad con el sistema de origen) y las demás en `usuario_departamentos_acceso`; "Todas las zonas" = sede `PROVINCIAS`.

## Menú por rol

| Rol | Menú |
|---|---|
| Supervisor directo | Mi turno · Supervisiones · Observaciones · Mi información |
| Supervisor de partner | Mi agenda · Observaciones de mi partner · Mi información |
| Gerente / Coordinador | Supervisiones · Observaciones · Dashboard · Línea de tiempo · Monitor de turnos · Control de observaciones |
| Administrador | Supervisiones · Observaciones · Dashboard · Línea de tiempo · Control de observaciones · **Usuarios y roles** |

El menú es solo presentación: `index.php` valida cada ruta y la API valida cada acción.

## Usuarios, roles y perfiles
- Solo el administrador crea, edita, activa o desactiva usuarios y restablece contraseñas.
- **Roles del sistema** (Administrador, Gerente, Coordinador, Supervisor, Partner): traen sus permisos de base y no se modifican.
- **Perfiles de acceso**: roles personalizados que se crean sobre un rol base con los permisos que se elijan (tablas `SGS_PERFIL` y `SGS_USUARIO_PERFIL`).
- El sistema nunca queda sin un administrador activo.

## Fuera de alcance
Calendario de asistencia, permisos y vacaciones, recursos, exámenes, flyers y chat. Siguen en el sistema de origen.

## No funcionales
- **Capacidad: 100 usuarios simultáneos** (probado: 0 errores y menos de 0,26 s para el 95% de las peticiones; con un año de datos, ninguna consulta pasa de 0,41 s). Pruebas en `05_TEST/integration/`.
- Funciona en celular (supervisores en campo).
- Cada error deja un registro rastreable con su código (ver `03_ARQUITECTURA/TRAZABILIDAD.md`).
- Usa la misma BD que el sistema de origen sin alterar su esquema.
10. **GPS obligatorio en campo.** (2026-10-07) Supervisores directos y de partner no pueden usar el sistema sin ubicación activa; una pantalla indica cómo activarla. Administración, gerencia y coordinación no se bloquean. En producción el sistema debe publicarse con https, porque sin conexión segura los navegadores no entregan el GPS.
11. **Turno antes de supervisar.** (2026-10-07) El supervisor directo no puede abrir ni retomar una supervisión sin turno iniciado; se le muestra el aviso con el botón "Iniciar turno". El servidor además lo valida al guardar.
12. **Horas reales de supervisión.** (2026-10-07) Inicio = registro de la supervisión; fin = cambio a "Finalizada" en `supervision_estado_historial`. La línea de tiempo pinta en curso (verde) y finalizada (gris), con "Inicio HH:MM · Fin HH:MM · N min".
13. **Meta de productividad.** (2026-10-08) Meta: 4.5 supervisiones finalizadas por día laborable (lunes a sábado). Un domingo cuenta solo si el supervisor inició turno ese día. Se muestran dos lecturas: promedio por día trabajado (días con turno iniciado) y avance frente a la meta del mes a la fecha. Configurable con `SGS_META_SUPERVISIONES_DIA`.
14. **Bloqueo a los 3 intentos.** (2026-10-08) Tres contraseñas incorrectas seguidas bloquean el usuario; el mensaje pide contactar al administrador. Solo el administrador lo libera (botón "Desbloquear" en Usuarios y roles, o al restablecer la contraseña). El aviso indica los intentos que quedan.
15. **Cierre automático del turno a las 23:59.** (2026-10-08) Un turno sin finalizar se cierra a las 23:59 del día en que se abrió, con "Fuera de turno", horas calculadas como en el cierre manual y una nota de cierre automático. Corre al usarse el sistema (cada 5 min) y desde `07_SCRIPTS/cerrar_turnos.php` programado a las 23:59.
16. **Relación de observaciones descargable.** (2026-10-08) En Registro de observaciones, "Descargar" entrega la lista con los filtros aplicados (fechas, tipo, estado, partner, departamento, cuadrilla) en Excel (todas las columnas) o PDF (columnas esenciales y totales por resultado). Solo personal WIN.
