-- =====================================================================
-- Sistema Web de Gestión de Mantenimiento Preventivo y Correctivo de
-- Equipos, Materiales y Repuestos del IESTPU
-- Esquema MySQL 8 / MariaDB 10.4+ (XAMPP)  ·  InnoDB · utf8mb4
-- =====================================================================


SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. SEGURIDAD: roles, permisos, usuarios
-- ---------------------------------------------------------------------
CREATE TABLE roles (
  id            TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo        VARCHAR(30)  NOT NULL,            -- admin_general, admin_programa, tecnico, docente
  nombre        VARCHAR(80)  NOT NULL,
  descripcion   VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_codigo (codigo)
) ENGINE=InnoDB;

-- Permisos finos y configurables (p. ej. aprobar compras no se asume por rol)
CREATE TABLE permisos (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo        VARCHAR(60)  NOT NULL,            -- ej. compras.aprobar, inventario.movimiento.autorizar
  descripcion   VARCHAR(255) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permisos_codigo (codigo)
) ENGINE=InnoDB;

CREATE TABLE rol_permiso (
  rol_id        TINYINT UNSIGNED NOT NULL,
  permiso_id    SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (rol_id, permiso_id),
  CONSTRAINT fk_rp_rol     FOREIGN KEY (rol_id)     REFERENCES roles(id)    ON DELETE CASCADE,
  CONSTRAINT fk_rp_permiso FOREIGN KEY (permiso_id) REFERENCES permisos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Permisos adicionales concedidos a un usuario concreto (según funciones reales)
CREATE TABLE usuario_permiso (
  usuario_id    INT UNSIGNED NOT NULL,
  permiso_id    SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (usuario_id, permiso_id),
  CONSTRAINT fk_up_permiso FOREIGN KEY (permiso_id) REFERENCES permisos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE programas (
  id            TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo        VARCHAR(20)  NOT NULL,
  nombre        VARCHAR(120) NOT NULL,
  activo        TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_programas_codigo (codigo),
  UNIQUE KEY uq_programas_nombre (nombre)
) ENGINE=InnoDB;

CREATE TABLE usuarios (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  rol_id           TINYINT UNSIGNED NOT NULL,
  dni              CHAR(8)      NULL,
  nombres          VARCHAR(100) NOT NULL,
  apellidos        VARCHAR(100) NOT NULL,
  correo           VARCHAR(150) NOT NULL,
  telefono         VARCHAR(20)  NULL,
  password_hash    VARCHAR(255) NOT NULL,          -- password_hash() (bcrypt/argon2)
  debe_cambiar_clave TINYINT(1) NOT NULL DEFAULT 1,
  intentos_fallidos TINYINT UNSIGNED NOT NULL DEFAULT 0,
  bloqueado_hasta  DATETIME     NULL,
  ultimo_acceso    DATETIME     NULL,
  activo           TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuarios_correo (correo),
  UNIQUE KEY uq_usuarios_dni (dni),
  KEY ix_usuarios_rol (rol_id),
  CONSTRAINT fk_usuarios_rol FOREIGN KEY (rol_id) REFERENCES roles(id)
) ENGINE=InnoDB;

ALTER TABLE usuario_permiso
  ADD CONSTRAINT fk_up_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE;

-- Un usuario puede pertenecer a uno o varios programas (separación de información)
CREATE TABLE usuario_programa (
  usuario_id    INT UNSIGNED NOT NULL,
  programa_id   TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (usuario_id, programa_id),
  CONSTRAINT fk_upg_usuario  FOREIGN KEY (usuario_id)  REFERENCES usuarios(id)  ON DELETE CASCADE,
  CONSTRAINT fk_upg_programa FOREIGN KEY (programa_id) REFERENCES programas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. UBICACIONES Y EQUIPOS
-- ---------------------------------------------------------------------
CREATE TABLE ambientes (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  programa_id    TINYINT UNSIGNED NOT NULL,
  codigo         VARCHAR(30)  NOT NULL,
  nombre         VARCHAR(120) NOT NULL,
  tipo           ENUM('laboratorio','taller','oficina','aula','area_practica','simulacion','otro') NOT NULL DEFAULT 'laboratorio',
  ubicacion      VARCHAR(150) NULL,
  responsable_id INT UNSIGNED NULL,
  activo         TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ambientes_codigo (codigo),
  KEY ix_ambientes_programa (programa_id),
  CONSTRAINT fk_amb_programa    FOREIGN KEY (programa_id)    REFERENCES programas(id),
  CONSTRAINT fk_amb_responsable FOREIGN KEY (responsable_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE categorias_equipos (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  programa_id   TINYINT UNSIGNED NULL,             -- NULL = categoría común a todos los programas
  nombre        VARCHAR(100) NOT NULL,
  es_consumible TINYINT(1)   NOT NULL DEFAULT 0,   -- diferencia consumibles de equipos con mantenimiento
  PRIMARY KEY (id),
  UNIQUE KEY uq_cat_equipo (programa_id, nombre),
  CONSTRAINT fk_cateq_programa FOREIGN KEY (programa_id) REFERENCES programas(id)
) ENGINE=InnoDB;

CREATE TABLE equipos (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo_inventario VARCHAR(40)  NOT NULL,
  nombre            VARCHAR(150) NOT NULL,
  categoria_id      INT UNSIGNED NOT NULL,
  programa_id       TINYINT UNSIGNED NOT NULL,
  ambiente_id       INT UNSIGNED NULL,
  descripcion       TEXT NULL,
  marca             VARCHAR(80)  NULL,
  modelo            VARCHAR(80)  NULL,
  numero_serie      VARCHAR(100) NULL,
  fecha_adquisicion DATE NULL,
  estado            ENUM('operativo','con_falla','en_mantenimiento','fuera_de_servicio','dado_de_baja') NOT NULL DEFAULT 'operativo',
  observaciones     TEXT NULL,
  creado_por        INT UNSIGNED NULL,
  creado_en         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_equipos_codigo (codigo_inventario),
  KEY ix_equipos_programa_estado (programa_id, estado),
  KEY ix_equipos_ambiente (ambiente_id),
  CONSTRAINT fk_eq_categoria FOREIGN KEY (categoria_id) REFERENCES categorias_equipos(id),
  CONSTRAINT fk_eq_programa  FOREIGN KEY (programa_id)  REFERENCES programas(id),
  CONSTRAINT fk_eq_ambiente  FOREIGN KEY (ambiente_id)  REFERENCES ambientes(id) ON DELETE SET NULL,
  CONSTRAINT fk_eq_creador   FOREIGN KEY (creado_por)   REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. MANTENIMIENTO CORRECTIVO
-- ---------------------------------------------------------------------
CREATE TABLE solicitudes_mantenimiento (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo             VARCHAR(25)  NOT NULL,        -- ej. SM-2026-000123
  programa_id        TINYINT UNSIGNED NOT NULL,
  ambiente_id        INT UNSIGNED NULL,
  equipo_id          INT UNSIGNED NOT NULL,
  reportado_por      INT UNSIGNED NOT NULL,
  descripcion_falla  TEXT NOT NULL,
  urgencia_reportada ENUM('baja','media','alta','critica') NOT NULL DEFAULT 'media',
  prioridad          ENUM('baja','media','alta','critica') NULL,   -- la fija el administrador
  estado             ENUM('pendiente','asignada','en_atencion','pendiente_repuestos','resuelta','cerrada','cancelada') NOT NULL DEFAULT 'pendiente',
  fecha_reporte      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  inicio_atencion    DATETIME NULL,
  fin_atencion       DATETIME NULL,
  equipo_quedo_operativo TINYINT(1) NULL,
  resultado_final    TEXT NULL,
  observaciones      TEXT NULL,
  motivo_cancelacion VARCHAR(255) NULL,
  cerrado_por        INT UNSIGNED NULL,
  fecha_cierre       DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sm_codigo (codigo),
  KEY ix_sm_programa_estado (programa_id, estado),
  KEY ix_sm_equipo (equipo_id),
  KEY ix_sm_reportante (reportado_por),
  CONSTRAINT fk_sm_programa  FOREIGN KEY (programa_id)   REFERENCES programas(id),
  CONSTRAINT fk_sm_ambiente  FOREIGN KEY (ambiente_id)   REFERENCES ambientes(id) ON DELETE SET NULL,
  CONSTRAINT fk_sm_equipo    FOREIGN KEY (equipo_id)     REFERENCES equipos(id),
  CONSTRAINT fk_sm_reporta   FOREIGN KEY (reportado_por) REFERENCES usuarios(id),
  CONSTRAINT fk_sm_cierra    FOREIGN KEY (cerrado_por)   REFERENCES usuarios(id),
  CONSTRAINT ck_sm_fechas    CHECK (fin_atencion IS NULL OR inicio_atencion IS NULL OR fin_atencion >= inicio_atencion),
  CONSTRAINT ck_sm_cancel    CHECK (estado <> 'cancelada' OR motivo_cancelacion IS NOT NULL)
) ENGINE=InnoDB;

CREATE TABLE asignaciones_tecnicos (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  solicitud_id   INT UNSIGNED NOT NULL,
  tecnico_id     INT UNSIGNED NOT NULL,
  asignado_por   INT UNSIGNED NOT NULL,
  fecha_asignacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  vigente        TINYINT(1) NOT NULL DEFAULT 1,    -- reasignar no borra la historia
  observaciones  VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY ix_at_solicitud (solicitud_id, vigente),
  KEY ix_at_tecnico (tecnico_id, vigente),
  CONSTRAINT fk_at_solicitud FOREIGN KEY (solicitud_id) REFERENCES solicitudes_mantenimiento(id),
  CONSTRAINT fk_at_tecnico   FOREIGN KEY (tecnico_id)   REFERENCES usuarios(id),
  CONSTRAINT fk_at_asigna    FOREIGN KEY (asignado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE diagnosticos (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  solicitud_id   INT UNSIGNED NOT NULL,
  tecnico_id     INT UNSIGNED NOT NULL,
  diagnostico    TEXT NOT NULL,
  causas_probables TEXT NULL,
  recomendaciones TEXT NULL,
  fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_diag_solicitud (solicitud_id),
  CONSTRAINT fk_diag_solicitud FOREIGN KEY (solicitud_id) REFERENCES solicitudes_mantenimiento(id),
  CONSTRAINT fk_diag_tecnico   FOREIGN KEY (tecnico_id)   REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- Programación preventiva (se declara antes por la FK de actividades)
CREATE TABLE planes_mantenimiento_preventivo (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  programa_id    TINYINT UNSIGNED NOT NULL,
  ambiente_id    INT UNSIGNED NULL,
  equipo_id      INT UNSIGNED NOT NULL,
  nombre         VARCHAR(150) NOT NULL,
  descripcion    TEXT NULL,
  frecuencia     ENUM('mensual','trimestral','semestral','anual','personalizada') NOT NULL,
  intervalo_dias INT UNSIGNED NULL,                -- obligatorio si es personalizada
  tecnico_responsable_id INT UNSIGNED NULL,
  fecha_inicio   DATE NOT NULL,
  activo         TINYINT(1) NOT NULL DEFAULT 1,
  creado_por     INT UNSIGNED NOT NULL,
  creado_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_pmp_equipo (equipo_id),
  KEY ix_pmp_programa (programa_id),
  CONSTRAINT fk_pmp_programa FOREIGN KEY (programa_id) REFERENCES programas(id),
  CONSTRAINT fk_pmp_ambiente FOREIGN KEY (ambiente_id) REFERENCES ambientes(id) ON DELETE SET NULL,
  CONSTRAINT fk_pmp_equipo   FOREIGN KEY (equipo_id)   REFERENCES equipos(id),
  CONSTRAINT fk_pmp_tecnico  FOREIGN KEY (tecnico_responsable_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_pmp_creador  FOREIGN KEY (creado_por)  REFERENCES usuarios(id),
  CONSTRAINT ck_pmp_intervalo CHECK (frecuencia <> 'personalizada' OR (intervalo_dias IS NOT NULL AND intervalo_dias > 0))
) ENGINE=InnoDB;

-- Lista de tareas que forman parte de un plan
CREATE TABLE tareas_plan (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  plan_id     INT UNSIGNED NOT NULL,
  orden       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  descripcion VARCHAR(255) NOT NULL,
  PRIMARY KEY (id),
  KEY ix_tp_plan (plan_id),
  CONSTRAINT fk_tp_plan FOREIGN KEY (plan_id) REFERENCES planes_mantenimiento_preventivo(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE programaciones_mantenimiento (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  plan_id         INT UNSIGNED NOT NULL,
  fecha_programada DATE NOT NULL,
  tecnico_id      INT UNSIGNED NULL,
  estado          ENUM('programado','pendiente','en_ejecucion','realizado','reprogramado','vencido') NOT NULL DEFAULT 'programado',
  inicio_real     DATETIME NULL,
  fin_real        DATETIME NULL,
  resultado       TEXT NULL,
  observaciones   TEXT NULL,
  proxima_fecha   DATE NULL,
  reprogramada_de INT UNSIGNED NULL,               -- cadena de reprogramaciones
  PRIMARY KEY (id),
  KEY ix_prog_fecha_estado (fecha_programada, estado),
  KEY ix_prog_plan (plan_id),
  CONSTRAINT fk_prog_plan    FOREIGN KEY (plan_id)   REFERENCES planes_mantenimiento_preventivo(id),
  CONSTRAINT fk_prog_tecnico FOREIGN KEY (tecnico_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_prog_repro   FOREIGN KEY (reprogramada_de) REFERENCES programaciones_mantenimiento(id),
  CONSTRAINT ck_prog_fechas  CHECK (fin_real IS NULL OR inicio_real IS NULL OR fin_real >= inicio_real)
) ENGINE=InnoDB;

-- Registro de tareas ejecutadas (correctivo o preventivo)
CREATE TABLE actividades_mantenimiento (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  solicitud_id    INT UNSIGNED NULL,
  programacion_id INT UNSIGNED NULL,
  tarea_plan_id   INT UNSIGNED NULL,
  tecnico_id      INT UNSIGNED NOT NULL,
  descripcion     TEXT NOT NULL,
  completada      TINYINT(1) NOT NULL DEFAULT 1,
  fecha_ejecucion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_act_solicitud (solicitud_id),
  KEY ix_act_prog (programacion_id),
  CONSTRAINT fk_act_solicitud FOREIGN KEY (solicitud_id)    REFERENCES solicitudes_mantenimiento(id),
  CONSTRAINT fk_act_prog      FOREIGN KEY (programacion_id) REFERENCES programaciones_mantenimiento(id),
  CONSTRAINT fk_act_tarea     FOREIGN KEY (tarea_plan_id)   REFERENCES tareas_plan(id) ON DELETE SET NULL,
  CONSTRAINT fk_act_tecnico   FOREIGN KEY (tecnico_id)      REFERENCES usuarios(id),
  -- debe pertenecer a un correctivo O a un preventivo, no a ambos ni a ninguno
  CONSTRAINT ck_act_origen CHECK ((solicitud_id IS NULL) <> (programacion_id IS NULL))
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. REPUESTOS E INVENTARIO
-- ---------------------------------------------------------------------
CREATE TABLE categorias_repuestos (
  id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre  VARCHAR(100) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_catrep_nombre (nombre)
) ENGINE=InnoDB;

CREATE TABLE repuestos (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo           VARCHAR(40)  NOT NULL,
  nombre           VARCHAR(150) NOT NULL,
  categoria_id     INT UNSIGNED NOT NULL,
  descripcion      TEXT NULL,
  unidad_medida    VARCHAR(30)  NOT NULL DEFAULT 'unidad',
  stock_minimo     DECIMAL(12,2) NOT NULL DEFAULT 0,
  marca            VARCHAR(80)  NULL,
  modelo_compatible VARCHAR(150) NULL,
  ubicacion_fisica VARCHAR(120) NULL,
  estado           ENUM('activo','inactivo','descontinuado') NOT NULL DEFAULT 'activo',
  observaciones    TEXT NULL,
  creado_en        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rep_codigo (codigo),
  KEY ix_rep_categoria (categoria_id),
  CONSTRAINT fk_rep_categoria FOREIGN KEY (categoria_id) REFERENCES categorias_repuestos(id),
  CONSTRAINT ck_rep_minimo CHECK (stock_minimo >= 0)
) ENGINE=InnoDB;

-- Equipos/categorías con los que es compatible un repuesto
CREATE TABLE repuesto_equipo_compatible (
  repuesto_id  INT UNSIGNED NOT NULL,
  categoria_equipo_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (repuesto_id, categoria_equipo_id),
  CONSTRAINT fk_rec_rep FOREIGN KEY (repuesto_id) REFERENCES repuestos(id) ON DELETE CASCADE,
  CONSTRAINT fk_rec_cat FOREIGN KEY (categoria_equipo_id) REFERENCES categorias_equipos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Almacén central y almacenes/ubicaciones por programa (configurable)
CREATE TABLE almacenes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo      VARCHAR(20)  NOT NULL,
  nombre      VARCHAR(120) NOT NULL,
  tipo        ENUM('central','programa') NOT NULL DEFAULT 'central',
  programa_id TINYINT UNSIGNED NULL,
  activo      TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_alm_codigo (codigo),
  CONSTRAINT fk_alm_programa FOREIGN KEY (programa_id) REFERENCES programas(id),
  CONSTRAINT ck_alm_tipo CHECK ((tipo = 'programa' AND programa_id IS NOT NULL) OR (tipo = 'central' AND programa_id IS NULL))
) ENGINE=InnoDB;

-- Una fila por repuesto y almacén: el stock total del instituto es la suma
-- de existencias, de modo que un traslado nunca duplica cantidades.
CREATE TABLE existencias_repuestos (
  repuesto_id         INT UNSIGNED NOT NULL,
  almacen_id          INT UNSIGNED NOT NULL,
  cantidad            DECIMAL(12,2) NOT NULL DEFAULT 0,   -- existencia física
  cantidad_reservada  DECIMAL(12,2) NOT NULL DEFAULT 0,   -- comprometida para una reparación
  actualizado_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (repuesto_id, almacen_id),
  CONSTRAINT fk_ex_rep FOREIGN KEY (repuesto_id) REFERENCES repuestos(id),
  CONSTRAINT fk_ex_alm FOREIGN KEY (almacen_id)  REFERENCES almacenes(id),
  CONSTRAINT ck_ex_no_negativo CHECK (cantidad >= 0 AND cantidad_reservada >= 0),
  CONSTRAINT ck_ex_reserva     CHECK (cantidad_reservada <= cantidad)
) ENGINE=InnoDB;

CREATE TABLE movimientos_inventario (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  repuesto_id        INT UNSIGNED NOT NULL,
  tipo               ENUM('entrada_compra','entrada_devolucion','entrada_ajuste','salida_reparacion','salida_entrega','salida_ajuste','traslado') NOT NULL,
  cantidad           DECIMAL(12,2) NOT NULL,
  almacen_origen_id  INT UNSIGNED NULL,
  almacen_destino_id INT UNSIGNED NULL,
  cantidad_anterior  DECIMAL(12,2) NULL,              -- existencia previa en el almacén afectado
  cantidad_posterior DECIMAL(12,2) NULL,
  estado             ENUM('pendiente','confirmado','rechazado') NOT NULL DEFAULT 'pendiente',
  fecha_hora         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  registrado_por     INT UNSIGNED NOT NULL,
  autorizado_por     INT UNSIGNED NULL,
  fecha_autorizacion DATETIME NULL,
  solicitud_id       INT UNSIGNED NULL,
  programacion_id    INT UNSIGNED NULL,
  recepcion_id       INT UNSIGNED NULL,
  documento_respaldo VARCHAR(150) NULL,
  observaciones      VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY ix_mov_repuesto_fecha (repuesto_id, fecha_hora),
  KEY ix_mov_solicitud (solicitud_id),
  CONSTRAINT fk_mov_rep     FOREIGN KEY (repuesto_id)        REFERENCES repuestos(id),
  CONSTRAINT fk_mov_origen  FOREIGN KEY (almacen_origen_id)  REFERENCES almacenes(id),
  CONSTRAINT fk_mov_destino FOREIGN KEY (almacen_destino_id) REFERENCES almacenes(id),
  CONSTRAINT fk_mov_reg     FOREIGN KEY (registrado_por)     REFERENCES usuarios(id),
  CONSTRAINT fk_mov_aut     FOREIGN KEY (autorizado_por)     REFERENCES usuarios(id),
  CONSTRAINT fk_mov_sol     FOREIGN KEY (solicitud_id)       REFERENCES solicitudes_mantenimiento(id),
  CONSTRAINT fk_mov_prog    FOREIGN KEY (programacion_id)    REFERENCES programaciones_mantenimiento(id),
  CONSTRAINT ck_mov_cantidad CHECK (cantidad > 0),
  CONSTRAINT ck_mov_traslado CHECK (tipo <> 'traslado' OR (almacen_origen_id IS NOT NULL AND almacen_destino_id IS NOT NULL AND almacen_origen_id <> almacen_destino_id))
) ENGINE=InnoDB;

-- Repuestos consumidos en una reparación; el stock solo baja al confirmarse
CREATE TABLE repuestos_utilizados (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  repuesto_id     INT UNSIGNED NOT NULL,
  almacen_id      INT UNSIGNED NOT NULL,
  cantidad        DECIMAL(12,2) NOT NULL,
  solicitud_id    INT UNSIGNED NULL,
  programacion_id INT UNSIGNED NULL,
  equipo_id       INT UNSIGNED NOT NULL,
  registrado_por  INT UNSIGNED NOT NULL,
  movimiento_id   BIGINT UNSIGNED NULL,               -- se llena al confirmar la salida
  estado          ENUM('registrado','confirmado','rechazado') NOT NULL DEFAULT 'registrado',
  fecha_registro  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_ru_repuesto (repuesto_id),
  KEY ix_ru_solicitud (solicitud_id),
  KEY ix_ru_equipo (equipo_id),
  CONSTRAINT fk_ru_rep   FOREIGN KEY (repuesto_id)     REFERENCES repuestos(id),
  CONSTRAINT fk_ru_alm   FOREIGN KEY (almacen_id)      REFERENCES almacenes(id),
  CONSTRAINT fk_ru_sol   FOREIGN KEY (solicitud_id)    REFERENCES solicitudes_mantenimiento(id),
  CONSTRAINT fk_ru_prog  FOREIGN KEY (programacion_id) REFERENCES programaciones_mantenimiento(id),
  CONSTRAINT fk_ru_eq    FOREIGN KEY (equipo_id)       REFERENCES equipos(id),
  CONSTRAINT fk_ru_reg   FOREIGN KEY (registrado_por)  REFERENCES usuarios(id),
  CONSTRAINT fk_ru_mov   FOREIGN KEY (movimiento_id)   REFERENCES movimientos_inventario(id),
  CONSTRAINT ck_ru_cant  CHECK (cantidad > 0),
  CONSTRAINT ck_ru_origen CHECK ((solicitud_id IS NULL) <> (programacion_id IS NULL))
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. SOLICITUDES DE COMPRA Y RECEPCIÓN
-- ---------------------------------------------------------------------
CREATE TABLE solicitudes_compra (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo          VARCHAR(25) NOT NULL,             -- ej. SC-2026-000045
  programa_id     TINYINT UNSIGNED NOT NULL,
  solicitante_id  INT UNSIGNED NOT NULL,
  motivo          TEXT NOT NULL,
  solicitud_mantenimiento_id INT UNSIGNED NULL,
  prioridad       ENUM('baja','media','alta','critica') NOT NULL DEFAULT 'media',
  estado          ENUM('pendiente_revision','aprobada','rechazada','en_proceso_compra','recibida','cancelada') NOT NULL DEFAULT 'pendiente_revision',
  fecha_solicitud DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revisado_por    INT UNSIGNED NULL,
  fecha_revision  DATETIME NULL,
  documento_respaldo VARCHAR(255) NULL,
  referencia_externa VARCHAR(100) NULL,             -- nº de expediente/orden si la compra se gestiona fuera
  observaciones   TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sc_codigo (codigo),
  KEY ix_sc_programa_estado (programa_id, estado),
  CONSTRAINT fk_sc_programa FOREIGN KEY (programa_id) REFERENCES programas(id),
  CONSTRAINT fk_sc_solic    FOREIGN KEY (solicitante_id) REFERENCES usuarios(id),
  CONSTRAINT fk_sc_sm       FOREIGN KEY (solicitud_mantenimiento_id) REFERENCES solicitudes_mantenimiento(id),
  CONSTRAINT fk_sc_revisor  FOREIGN KEY (revisado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE detalles_solicitud_compra (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  solicitud_compra_id INT UNSIGNED NOT NULL,
  repuesto_id   INT UNSIGNED NOT NULL,
  cantidad_solicitada DECIMAL(12,2) NOT NULL,
  cantidad_recibida   DECIMAL(12,2) NOT NULL DEFAULT 0,
  observaciones VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dsc (solicitud_compra_id, repuesto_id),
  CONSTRAINT fk_dsc_sc  FOREIGN KEY (solicitud_compra_id) REFERENCES solicitudes_compra(id) ON DELETE CASCADE,
  CONSTRAINT fk_dsc_rep FOREIGN KEY (repuesto_id) REFERENCES repuestos(id),
  CONSTRAINT ck_dsc_cant CHECK (cantidad_solicitada > 0 AND cantidad_recibida >= 0)
) ENGINE=InnoDB;

CREATE TABLE recepciones_compra (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  solicitud_compra_id INT UNSIGNED NOT NULL,
  almacen_id    INT UNSIGNED NOT NULL,
  fecha_recepcion DATE NOT NULL,
  documento     VARCHAR(150) NULL,                  -- guía, factura u otro documento
  recibido_por  INT UNSIGNED NOT NULL,
  observaciones VARCHAR(255) NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_rc_sc   FOREIGN KEY (solicitud_compra_id) REFERENCES solicitudes_compra(id),
  CONSTRAINT fk_rc_alm  FOREIGN KEY (almacen_id)  REFERENCES almacenes(id),
  CONSTRAINT fk_rc_user FOREIGN KEY (recibido_por) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE detalles_recepcion (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  recepcion_id  INT UNSIGNED NOT NULL,
  repuesto_id   INT UNSIGNED NOT NULL,
  cantidad_recibida DECIMAL(12,2) NOT NULL,         -- solo lo realmente recibido alimenta el stock
  PRIMARY KEY (id),
  UNIQUE KEY uq_dr (recepcion_id, repuesto_id),
  CONSTRAINT fk_dr_rec FOREIGN KEY (recepcion_id) REFERENCES recepciones_compra(id) ON DELETE CASCADE,
  CONSTRAINT fk_dr_rep FOREIGN KEY (repuesto_id)  REFERENCES repuestos(id),
  CONSTRAINT ck_dr_cant CHECK (cantidad_recibida > 0)
) ENGINE=InnoDB;

ALTER TABLE movimientos_inventario
  ADD CONSTRAINT fk_mov_recep FOREIGN KEY (recepcion_id) REFERENCES recepciones_compra(id);

-- ---------------------------------------------------------------------
-- 6. TRANSVERSALES: historial, evidencias, notificaciones, auditoría
-- ---------------------------------------------------------------------
-- Historial de cambios de estado (solo se inserta; nunca se actualiza ni borra)
CREATE TABLE historial_estados (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entidad        ENUM('solicitud_mantenimiento','programacion','equipo','solicitud_compra') NOT NULL,
  entidad_id     INT UNSIGNED NOT NULL,
  estado_anterior VARCHAR(40) NULL,
  estado_nuevo   VARCHAR(40) NOT NULL,
  usuario_id     INT UNSIGNED NULL,
  comentario     VARCHAR(255) NULL,
  fecha_hora     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_he_entidad (entidad, entidad_id, fecha_hora),
  CONSTRAINT fk_he_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE evidencias (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  entidad        ENUM('solicitud_mantenimiento','programacion','diagnostico','solicitud_compra','equipo') NOT NULL,
  entidad_id     INT UNSIGNED NOT NULL,
  ruta_archivo   VARCHAR(255) NOT NULL,             -- nombre aleatorio, fuera de la raíz pública
  nombre_original VARCHAR(255) NOT NULL,
  tipo_mime      VARCHAR(80) NOT NULL,
  tamano_bytes   INT UNSIGNED NOT NULL,
  subido_por     INT UNSIGNED NOT NULL,
  fecha_subida   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_ev_entidad (entidad, entidad_id),
  CONSTRAINT fk_ev_usuario FOREIGN KEY (subido_por) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE notificaciones (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id  INT UNSIGNED NOT NULL,
  tipo        VARCHAR(40) NOT NULL,                 -- falla_asignada, stock_bajo, preventivo_proximo...
  mensaje     VARCHAR(255) NOT NULL,
  enlace      VARCHAR(255) NULL,
  leida       TINYINT(1) NOT NULL DEFAULT 0,
  creada_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_not_usuario (usuario_id, leida),
  CONSTRAINT fk_not_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE auditoria (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id  INT UNSIGNED NULL,
  accion      VARCHAR(40) NOT NULL,                 -- login, login_fallido, crear, editar, eliminar, exportar
  tabla       VARCHAR(60) NULL,
  registro_id BIGINT UNSIGNED NULL,
  detalle     JSON NULL,
  ip          VARCHAR(45) NULL,
  fecha_hora  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_aud_usuario (usuario_id, fecha_hora),
  KEY ix_aud_tabla (tabla, registro_id),
  CONSTRAINT fk_aud_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE configuracion (
  clave       VARCHAR(60) NOT NULL,
  valor       VARCHAR(255) NOT NULL,
  descripcion VARCHAR(255) NULL,
  PRIMARY KEY (clave)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- VISTAS ÚTILES PARA PANELES Y REPORTES
-- ---------------------------------------------------------------------
-- Stock total del instituto, por programa/almacén y alertas
CREATE OR REPLACE VIEW v_stock_repuestos AS
SELECT r.id AS repuesto_id, r.codigo, r.nombre, r.unidad_medida, r.stock_minimo,
       COALESCE(SUM(e.cantidad),0)                          AS stock_total,
       COALESCE(SUM(e.cantidad_reservada),0)                AS reservado,
       COALESCE(SUM(e.cantidad - e.cantidad_reservada),0)   AS disponible,
       CASE WHEN COALESCE(SUM(e.cantidad),0) = 0 THEN 'agotado'
            WHEN COALESCE(SUM(e.cantidad),0) <= r.stock_minimo THEN 'stock_bajo'
            ELSE 'suficiente' END                           AS alerta
FROM repuestos r
LEFT JOIN existencias_repuestos e ON e.repuesto_id = r.id
WHERE r.estado = 'activo'
GROUP BY r.id, r.codigo, r.nombre, r.unidad_medida, r.stock_minimo;

-- Repuestos pendientes de recepción (aprobados/en compra y no recibidos del todo)
CREATE OR REPLACE VIEW v_pendiente_recepcion AS
SELECT d.repuesto_id, SUM(d.cantidad_solicitada - d.cantidad_recibida) AS pendiente
FROM detalles_solicitud_compra d
JOIN solicitudes_compra s ON s.id = d.solicitud_compra_id
WHERE s.estado IN ('aprobada','en_proceso_compra')
GROUP BY d.repuesto_id
HAVING pendiente > 0;

-- Tiempos de atención correctiva
CREATE OR REPLACE VIEW v_tiempos_correctivo AS
SELECT s.id, s.codigo, s.programa_id, s.equipo_id, s.estado,
       TIMESTAMPDIFF(MINUTE, s.fecha_reporte, s.inicio_atencion) AS min_hasta_inicio,
       TIMESTAMPDIFF(MINUTE, s.inicio_atencion, s.fin_atencion)  AS min_reparacion
FROM solicitudes_mantenimiento s;

-- ---------------------------------------------------------------------
-- DATOS INICIALES
-- ---------------------------------------------------------------------
INSERT INTO roles (codigo, nombre, descripcion) VALUES
 ('admin_general',  'Administrador general',  'Supervisa los cuatro programas y la configuración'),
 ('admin_programa', 'Administrador de programa', 'Coordinador: gestiona los recursos de su programa'),
 ('tecnico',        'Técnico de mantenimiento', 'Atiende fallas y ejecuta mantenimientos'),
 ('docente',        'Docente', 'Reporta fallas de los equipos que utiliza');

INSERT INTO programas (codigo, nombre) VALUES
 ('AGRO','Agropecuaria'), ('ENFE','Enfermería'), ('CONT','Contabilidad'), ('APSTI','Arquitectura de Plataformas y Servicios de Tecnologías de la Información');

INSERT INTO permisos (codigo, descripcion) VALUES
 ('usuarios.gestionar',            'Crear y editar usuarios y permisos'),
 ('equipos.gestionar',             'Registrar y editar equipos y ambientes'),
 ('solicitudes.asignar',           'Revisar solicitudes, fijar prioridad y asignar técnico'),
 ('solicitudes.cerrar',            'Verificar el resultado y cerrar solicitudes'),
 ('preventivo.gestionar',          'Crear planes y programar mantenimientos preventivos'),
 ('inventario.ver',                'Consultar repuestos y existencias'),
 ('inventario.movimientos',        'Registrar entradas, salidas y traslados'),
 ('inventario.autorizar_salida',   'Confirmar y autorizar salidas de repuestos'),
 ('compras.solicitar',             'Registrar solicitudes de compra o reposición'),
 ('compras.ver',                   'Consultar solicitudes de compra'),
 ('compras.aprobar',               'Aprobar o rechazar compras (se asigna según funciones reales)'),
 ('compras.registrar_recepcion',   'Registrar la recepción de repuestos'),
 ('reportes.ver',                  'Generar y exportar reportes'),
 ('configuracion.gestionar',       'Modificar la configuración general');

-- Permisos base por rol. 'compras.aprobar' y 'inventario.autorizar_salida' NO se
-- asignan a ningún rol por defecto: se conceden por usuario (usuario_permiso).
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p
WHERE (r.codigo='admin_general' AND p.codigo IN ('usuarios.gestionar','equipos.gestionar','solicitudes.asignar','solicitudes.cerrar','preventivo.gestionar','inventario.ver','inventario.movimientos','compras.solicitar','compras.ver','reportes.ver','configuracion.gestionar'))
   OR (r.codigo='admin_programa' AND p.codigo IN ('equipos.gestionar','solicitudes.asignar','solicitudes.cerrar','preventivo.gestionar','inventario.ver','compras.solicitar','compras.ver','reportes.ver'))
   OR (r.codigo='tecnico' AND p.codigo IN ('inventario.ver','compras.solicitar'));

INSERT INTO almacenes (codigo, nombre, tipo, programa_id) VALUES
 ('ALM-CENTRAL','Almacén central','central',NULL);

INSERT INTO configuracion (clave, valor, descripcion) VALUES
 ('modo_almacen','central','central = un almacén; por_programa = almacenes independientes por programa'),
 ('dias_alerta_preventivo','7','Días de anticipación para alertar mantenimientos próximos'),
 ('max_intentos_login','5','Intentos fallidos antes de bloquear temporalmente'),
 ('minutos_bloqueo','15','Duración del bloqueo tras exceder intentos'),
 ('max_tamano_evidencia_mb','5','Tamaño máximo por archivo de evidencia');

INSERT INTO categorias_repuestos (nombre) VALUES
 ('Cables y conectores'),('Componentes electrónicos'),('Componentes eléctricos'),('Periféricos'),('Herramientas'),('Ferretería'),('Otros');
