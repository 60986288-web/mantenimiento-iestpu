
-- =====================================================================
-- ACTUALIZACIÓN ESTRUCTURAL V2/V3 — ADECUACIÓN AL EXCEL IESTPU Y FICHA SCRIBD
-- =====================================================================
INSERT INTO programas (codigo, nombre) VALUES
  ('ADMIN', 'Áreas Administrativas y Dirección General')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

ALTER TABLE equipos
  ADD COLUMN codigo_patrimonial VARCHAR(40) NULL AFTER codigo_inventario,
  ADD COLUMN codigo_interno     VARCHAR(40) NULL AFTER codigo_patrimonial,
  ADD COLUMN tipo_fisico        VARCHAR(60) NULL AFTER modelo,
  ADD COLUMN color              VARCHAR(60) NULL AFTER tipo_fisico,
  ADD COLUMN dimensiones        VARCHAR(100) NULL AFTER color,
  ADD COLUMN estado_conservacion ENUM('N','MB','B','R','M','MM') NULL DEFAULT 'B'
        COMMENT 'N=Nuevo MB=Muy bueno B=Bueno R=Regular M=Malo MM=Muy malo'
        AFTER estado,
  ADD COLUMN situacion ENUM('en_uso','almacen','baja','prestado') NOT NULL DEFAULT 'en_uso'
        COMMENT 'U en el Excel = en_uso' AFTER estado_conservacion,
  ADD COLUMN requiere_mantenimiento TINYINT(1) NOT NULL DEFAULT 0 AFTER situacion,
  ADD COLUMN frecuencia_mantenimiento ENUM('mensual','trimestral','semestral','anual','no_aplica') NOT NULL DEFAULT 'no_aplica' AFTER requiere_mantenimiento,
  ADD COLUMN ultimo_mantenimiento DATE NULL AFTER frecuencia_mantenimiento,
  ADD COLUMN fecha_limite_mantenimiento DATE NULL AFTER ultimo_mantenimiento,
  ADD COLUMN responsable_bien   VARCHAR(150) NULL AFTER fecha_limite_mantenimiento,
  ADD COLUMN dni_responsable    VARCHAR(15) NULL AFTER responsable_bien,
  ADD COLUMN especificaciones   JSON NULL AFTER observaciones,
  ADD COLUMN numero_orden       SMALLINT UNSIGNED NULL COMMENT 'N° correlativo dentro del acta',
  ADD KEY ix_eq_cod_patrimonial (codigo_patrimonial),
  ADD KEY ix_eq_cod_interno (codigo_interno),
  ADD KEY ix_eq_fecha_limite (fecha_limite_mantenimiento);

CREATE TABLE IF NOT EXISTS movimientos_equipos (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  equipo_id              INT UNSIGNED NOT NULL,
  ambiente_origen_id     INT UNSIGNED NULL,
  ambiente_destino_id    INT UNSIGNED NULL,
  responsable_entrega_id INT UNSIGNED NULL,
  responsable_recibe_id  INT UNSIGNED NULL,
  fecha_movimiento       DATE NOT NULL,
  documento_respaldo     VARCHAR(150) NULL,
  observaciones          VARCHAR(255) NULL,
  registrado_por         INT UNSIGNED NOT NULL,
  creado_en              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_me_equipo_fecha (equipo_id, fecha_movimiento),
  CONSTRAINT fk_me_equipo  FOREIGN KEY (equipo_id)  REFERENCES equipos(id) ON DELETE CASCADE,
  CONSTRAINT fk_me_origen  FOREIGN KEY (ambiente_origen_id)  REFERENCES ambientes(id) ON DELETE SET NULL,
  CONSTRAINT fk_me_destino FOREIGN KEY (ambiente_destino_id) REFERENCES ambientes(id) ON DELETE SET NULL,
  CONSTRAINT fk_me_entrega FOREIGN KEY (responsable_entrega_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_me_recibe  FOREIGN KEY (responsable_recibe_id)  REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_me_reg     FOREIGN KEY (registrado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE OR REPLACE VIEW v_equipo_busqueda AS
SELECT e.id, e.numero_orden, e.codigo_inventario, e.codigo_patrimonial, e.codigo_interno,
       e.nombre, e.descripcion, e.marca, e.modelo, e.numero_serie,
       e.fecha_adquisicion, e.estado, e.estado_conservacion, e.situacion,
       e.color, e.tipo_fisico, e.dimensiones, e.requiere_mantenimiento,
       e.frecuencia_mantenimiento, e.ultimo_mantenimiento, e.fecha_limite_mantenimiento,
       e.responsable_bien, e.dni_responsable, e.especificaciones, e.observaciones,
       e.categoria_id, c.nombre AS categoria_nombre, c.es_consumible,
       e.programa_id, pr.codigo AS programa, pr.nombre AS programa_nombre,
       a.id AS ambiente_id, a.codigo AS ambiente_codigo, a.nombre AS ambiente_nombre, a.ubicacion AS ambiente_ubicacion,
       COALESCE(
         (SELECT MAX(pr2.fecha_programada)
            FROM programaciones_mantenimiento pr2
            JOIN planes_mantenimiento_preventivo pl ON pl.id = pr2.plan_id
           WHERE pl.equipo_id = e.id AND pr2.estado = 'realizado'),
         e.ultimo_mantenimiento
       ) AS ultimo_preventivo,
       COALESCE(
         (SELECT MIN(pr3.fecha_programada)
            FROM programaciones_mantenimiento pr3
            JOIN planes_mantenimiento_preventivo pl3 ON pl3.id = pr3.plan_id
           WHERE pl3.equipo_id = e.id AND pr3.estado IN ('programado','pendiente','vencido')),
         e.fecha_limite_mantenimiento
       ) AS proximo_preventivo,
       (SELECT d.diagnostico
          FROM diagnosticos d
          JOIN solicitudes_mantenimiento sm ON sm.id = d.solicitud_id
         WHERE sm.equipo_id = e.id
         ORDER BY d.fecha_registro DESC LIMIT 1) AS ultimo_diagnostico
FROM equipos e
JOIN programas pr ON pr.id = e.programa_id
JOIN categorias_equipos c ON c.id = e.categoria_id
LEFT JOIN ambientes a ON a.id = e.ambiente_id;
