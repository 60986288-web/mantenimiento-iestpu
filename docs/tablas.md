# Base de datos — descripción de tablas

MySQL 8 / MariaDB 10.4+ (XAMPP), InnoDB, `utf8mb4`. Script: `database/schema.sql` (33 tablas + 3 vistas). Diagrama: `docs/er.mmd` (Mermaid).

Convención: **PK** clave primaria · **FK** clave foránea · **UQ** única · **CK** restricción CHECK.

## Seguridad y usuarios

| Tabla | Función | Campos principales | Restricciones |
|---|---|---|---|
| `roles` | Los 4 roles del sistema | PK id; codigo (UQ), nombre | — |
| `permisos` | Permisos finos configurables | PK id; codigo (UQ) | — |
| `rol_permiso` | Permisos base de cada rol | PK (rol_id, permiso_id) | FK a roles y permisos |
| `usuario_permiso` | Permisos extra por persona (p. ej. aprobar compras) | PK (usuario_id, permiso_id) | FK a usuarios y permisos |
| `programas` | Los cuatro programas de estudios | PK id; codigo (UQ), nombre (UQ), activo | — |
| `usuarios` | Cuentas | PK id; FK rol_id; correo (UQ), dni (UQ), password_hash, intentos_fallidos, bloqueado_hasta | hash con `password_hash()` |
| `usuario_programa` | Vincula usuarios a uno o varios programas (base de la separación por programa) | PK (usuario_id, programa_id) | FK a usuarios y programas |

## Ubicaciones y equipos

| Tabla | Función | Campos principales | Restricciones |
|---|---|---|---|
| `ambientes` | Laboratorios, talleres, oficinas | PK id; FK programa_id; FK responsable_id; codigo (UQ), tipo | — |
| `categorias_equipos` | Categorías; `es_consumible` separa consumibles de equipos | PK id; FK programa_id (NULL = común) | UQ (programa_id, nombre) |
| `equipos` | Inventario de equipos y recursos | PK id; FK categoria_id, programa_id, ambiente_id; codigo_inventario (UQ); estado | estado ∈ operativo, con_falla, en_mantenimiento, fuera_de_servicio, dado_de_baja |

## Mantenimiento correctivo

| Tabla | Función | Campos principales | Restricciones |
|---|---|---|---|
| `solicitudes_mantenimiento` | Fallas reportadas y su ciclo de vida | PK id; codigo (UQ); FK programa_id, ambiente_id, equipo_id, reportado_por, cerrado_por; urgencia_reportada, prioridad, estado, fechas | CK fin ≥ inicio; CK «cancelada» exige motivo |
| `asignaciones_tecnicos` | Quién atiende cada solicitud; reasignar conserva la historia (`vigente`) | PK id; FK solicitud_id, tecnico_id, asignado_por | — |
| `diagnosticos` | Diagnóstico, causas y recomendaciones del técnico | PK id; FK solicitud_id, tecnico_id | — |
| `actividades_mantenimiento` | Actividades realizadas (correctivo o preventivo) | PK id; FK solicitud_id o programacion_id, tarea_plan_id, tecnico_id | CK exactamente uno de solicitud/programación |

## Mantenimiento preventivo

| Tabla | Función | Campos principales | Restricciones |
|---|---|---|---|
| `planes_mantenimiento_preventivo` | Plan por equipo con frecuencia | PK id; FK programa_id, ambiente_id, equipo_id, tecnico_responsable_id; frecuencia, intervalo_dias | CK personalizada exige intervalo > 0 |
| `tareas_plan` | Lista de tareas de un plan | PK id; FK plan_id; orden | borrado en cascada con el plan |
| `programaciones_mantenimiento` | Cada ejecución programada, con su estado y próxima fecha | PK id; FK plan_id, tecnico_id, reprogramada_de; estado, fecha_programada, proxima_fecha | CK fin_real ≥ inicio_real |

## Repuestos e inventario

| Tabla | Función | Campos principales | Restricciones |
|---|---|---|---|
| `categorias_repuestos` | Tipos de repuesto | PK id; nombre (UQ) | — |
| `repuestos` | Catálogo (sin cantidades) | PK id; codigo (UQ); FK categoria_id; unidad_medida, stock_minimo | CK stock_minimo ≥ 0 |
| `repuesto_equipo_compatible` | Categorías de equipo con las que es compatible | PK (repuesto_id, categoria_equipo_id) | FK a ambas |
| `almacenes` | Almacén central y almacenes por programa (según configuración) | PK id; codigo (UQ); tipo; FK programa_id | CK central sin programa / programa con programa |
| `existencias_repuestos` | Existencia por repuesto y almacén. El stock total es la **suma**, por eso un traslado nunca duplica | PK (repuesto_id, almacen_id); cantidad, cantidad_reservada | CK cantidad ≥ 0; CK reservada ≤ cantidad (**sin stock negativo**) |
| `movimientos_inventario` | Libro de movimientos: entradas, salidas, traslados | PK id; FK repuesto_id, almacén origen/destino, registrado_por, autorizado_por, solicitud_id, programacion_id, recepcion_id; cantidad_anterior/posterior; estado | CK cantidad > 0; CK traslado con origen ≠ destino |
| `repuestos_utilizados` | Repuestos consumidos en una reparación; el stock baja solo al confirmarse | PK id; FK repuesto_id, almacen_id, equipo_id, solicitud_id/programacion_id, movimiento_id | CK cantidad > 0; CK un solo origen |

## Compras y recepción

| Tabla | Función | Campos principales | Restricciones |
|---|---|---|---|
| `solicitudes_compra` | Necesidad de compra/reposición y su seguimiento (también si la compra se gestiona fuera del sistema: `referencia_externa`) | PK id; codigo (UQ); FK programa_id, solicitante_id, solicitud_mantenimiento_id, revisado_por | estado ∈ pendiente_revision … cancelada |
| `detalles_solicitud_compra` | Repuestos y cantidades solicitadas/recibidas | PK id; FK solicitud_compra_id, repuesto_id | UQ (solicitud, repuesto); CK cantidades |
| `recepciones_compra` | Evento de recepción (fecha, documento) | PK id; FK solicitud_compra_id, almacen_id, recibido_por | — |
| `detalles_recepcion` | Cantidad realmente recibida; solo esto alimenta el stock | PK id; FK recepcion_id, repuesto_id | UQ; CK cantidad > 0 |

## Transversales

| Tabla | Función | Notas |
|---|---|---|
| `historial_estados` | Cambios de estado de solicitudes, programaciones, equipos y compras | Solo inserciones; conserva responsable y fecha |
| `evidencias` | Fotos y PDF adjuntos | Nombre aleatorio en disco; se sirven con control de acceso |
| `notificaciones` | Avisos por usuario | FK usuario, `leida` |
| `auditoria` | Registro de accesos y cambios | FK usuario (SET NULL), JSON de detalle, IP |
| `configuracion` | Parámetros (modo de almacén, días de alerta, etc.) | clave/valor |

## Vistas

- `v_stock_repuestos`: stock total, reservado, disponible y alerta (suficiente / stock_bajo / agotado).
- `v_pendiente_recepcion`: cantidades aprobadas o en compra aún no recibidas.
- `v_tiempos_correctivo`: minutos hasta el inicio de atención y de reparación.

## Reglas de integridad clave

- **Sin stock negativo**: CHECK en `existencias_repuestos`; en la capa PHP, las salidas se hacen en transacción con `SELECT … FOR UPDATE`.
- **Sin pérdida de historial**: los datos históricos usan `RESTRICT` (no cascada); `historial_estados` y `movimientos_inventario` no se editan ni borran.
- **Compras**: `compras.aprobar` y `inventario.autorizar_salida` **no** están asignados a ningún rol por defecto; se conceden por usuario según las funciones reales de cada responsable.
