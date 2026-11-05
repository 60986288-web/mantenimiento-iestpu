<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/layout.php';
require dirname(__DIR__, 2) . '/includes/catalogos.php';

$u = requiere_rol('tecnico', 'admin_general');

// Cuando el Área de Mantenimiento (Soporte) verifica la falla, registra el diagnóstico y genera el Cronograma Preventivo
if (es_post()) {
    csrf_verificar();
    $solId          = post_int('solicitud_id');
    $diagTexto      = post_str('diagnostico', 2000);
    $causas         = post_str('causas_probables', 1000) ?: null;
    $recom          = post_str('recomendaciones', 1000) ?: null;
    $resultado      = post_str('resultado_final', 2000);
    $estadoServ     = en_lista($_POST['estado_servicio'] ?? null, ['completo', 'pendiente']) ?? 'completo';
    $fechaVisita    = fecha_valida_o_null(post_str('fecha_visita', 10)) ?: date('Y-m-d');
    $proxPreventivo = fecha_valida_o_null(post_str('proxima_visita', 10)) ?: date('Y-m-d', strtotime('+90 days'));
    $frecuencia     = en_lista($_POST['frecuencia'] ?? null, ['mensual', 'trimestral', 'semestral', 'anual']) ?? 'trimestral';
    $ordenServ      = post_str('orden_servicio', 60) ?: ('OM-' . date('Y') . '-' . str_pad((string) $solId, 4, '0', STR_PAD_LEFT));
    $costoMant      = post_str('costo_mantenimiento', 20) ?: '0.00';

    $sol = $solId ? consulta('SELECT * FROM solicitudes_mantenimiento WHERE id = ?', [$solId])->fetch() : false;
    if ($sol && $diagTexto !== '') {
        $pdo = db();
        try {
            $pdo->beginTransaction();

            // 1. Insertar diagnóstico técnico
            consulta(
                'INSERT INTO diagnosticos (solicitud_id, tecnico_id, diagnostico, causas_probables, recomendaciones) VALUES (?,?,?,?,?)',
                [$solId, $u['id'], $diagTexto, $causas, $recom]
            );

            // 2. Actualizar estado de la solicitud correctiva
            $nuevoEstSol = ($estadoServ === 'completo') ? 'resuelta' : 'en_atencion';
            $obsSol = "Orden $ordenServ · Servicio " . strtoupper($estadoServ) . " · Visita: " . date('d/m/Y', strtotime($fechaVisita)) . " · Próx. Preventivo: " . date('d/m/Y', strtotime($proxPreventivo));
            consulta(
                'UPDATE solicitudes_mantenimiento
                    SET estado = ?, inicio_atencion = COALESCE(inicio_atencion, NOW()),
                        fin_atencion = ?, equipo_quedo_operativo = ?, resultado_final = ?, observaciones = ?
                  WHERE id = ?',
                [$nuevoEstSol, $estadoServ === 'completo' ? date('Y-m-d H:i:s') : null, $estadoServ === 'completo' ? 1 : 0, $resultado ?: $diagTexto, $obsSol, $solId]
            );
            registrar_estado('solicitud_mantenimiento', $solId, $sol['estado'], $nuevoEstSol, 'Verificado por Soporte Técnico');

            // 3. Generar / Actualizar automáticamente el Cronograma de Verificación y Mantenimiento Preventivo (lo ve el Coordinador automáticamente)
            $planId = consulta('SELECT id FROM planes_mantenimiento_preventivo WHERE equipo_id = ? LIMIT 1', [$sol['equipo_id']])->fetchColumn();
            if (!$planId) {
                consulta(
                    'INSERT INTO planes_mantenimiento_preventivo (programa_id, ambiente_id, equipo_id, nombre, descripcion, frecuencia, tecnico_responsable_id, fecha_inicio, activo, creado_por)
                     VALUES (?,?,?,?,?,?,?,?,1,?)',
                    [$sol['programa_id'], $sol['ambiente_id'], $sol['equipo_id'], 'Cronograma de Verificación y Mantenimiento Preventivo', $recom ?: $diagTexto, $frecuencia, $u['id'], $fechaVisita, $u['id']]
                );
                $planId = (int) $pdo->lastInsertId();
            } else {
                consulta('UPDATE planes_mantenimiento_preventivo SET frecuencia = ?, tecnico_responsable_id = ?, descripcion = ? WHERE id = ?',
                    [$frecuencia, $u['id'], $recom ?: $diagTexto, $planId]);
            }

            // Registrar la programación en el cronograma para el Coordinador
            $estProg = ($estadoServ === 'completo') ? 'programado' : 'pendiente';
            consulta(
                'INSERT INTO programaciones_mantenimiento (plan_id, fecha_programada, tecnico_id, estado, resultado, observaciones, proxima_fecha)
                 VALUES (?,?,?,?,?,?,?)',
                [$planId, $proxPreventivo, $u['id'], $estProg, $resultado ?: $diagTexto, "Generado tras verificar solicitud " . $sol['codigo'], $proxPreventivo]
            );

            // 4. Actualizar equipo y su Ficha de Mantenimiento (JSON)
            $eqRow = consulta('SELECT * FROM equipos WHERE id = ?', [$sol['equipo_id']])->fetch();
            if ($eqRow) {
                $esp = json_decode((string) ($eqRow['especificaciones'] ?? '{}'), true) ?: [];
                $esp['estado_visita']       = ($estadoServ === 'completo') ? 'visitado' : 'programada';
                $esp['fecha_visita']        = $fechaVisita;
                $esp['estado_servicio']     = $estadoServ;
                $esp['proxima_visita']      = $proxPreventivo;
                $esp['tecnico_responsable'] = $u['nombres'] . ' ' . $u['apellidos'] . ' (Soporte Técnico)';
                $esp['diagnostico']         = $diagTexto . ($resultado ? ' — ' . $resultado : '');
                $esp['orden_servicio']      = $ordenServ;
                $esp['costo_mantenimiento'] = $costoMant;

                $interv = $esp['intervenciones_ficha'] ?? [];
                array_unshift($interv, [
                    'fecha'     => $fechaVisita,
                    'tipo'      => 'CORRECTIVO',
                    'orden'     => $ordenServ,
                    'actividad' => 'Falla: ' . $sol['descripcion_falla'] . ' | Diagnóstico: ' . $diagTexto . ($resultado ? ' | Solución: ' . $resultado : ''),
                    'servicio'  => strtoupper($estadoServ),
                    'proxima'   => $proxPreventivo,
                    'empresa'   => $u['nombres'] . ' ' . $u['apellidos'] . ' (Soporte IESTPU)',
                    'costo'     => $costoMant,
                ]);
                $esp['intervenciones_ficha'] = array_slice($interv, 0, 25);

                $nuevoEstEq = ($estadoServ === 'completo') ? 'operativo' : 'en_mantenimiento';
                consulta(
                    'UPDATE equipos SET estado = ?, requiere_mantenimiento = 1, frecuencia_mantenimiento = ?, ultimo_mantenimiento = ?, fecha_limite_mantenimiento = ?, especificaciones = ? WHERE id = ?',
                    [$nuevoEstEq, $frecuencia, $fechaVisita, $proxPreventivo, json_encode($esp, JSON_UNESCAPED_UNICODE), $sol['equipo_id']]
                );
            }

            // 5. Notificar al Coordinador / Reportante
            consulta(
                'INSERT INTO notificaciones (usuario_id, tipo, mensaje, enlace) VALUES (?,?,?,?)',
                [$sol['reportado_por'], 'cronograma_generado', "Soporte verificó su reporte {$sol['codigo']} y programó el cronograma preventivo para el " . date('d/m/Y', strtotime($proxPreventivo)), 'admin/cronograma.php']
            );

            $pdo->commit();
            flash('success', "Solicitud {$sol['codigo']} verificada: se registró el diagnóstico, se actualizó la Ficha del Equipo y se generó automáticamente el Cronograma Preventivo para el Coordinador.");
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Soporte verificar: ' . $ex->getMessage());
            flash('danger', 'Error al guardar la verificación técnica.');
        }
    }
    redirigir('tecnico/trabajos.php');
}

$filas = consulta(
    "SELECT s.*, e.codigo_inventario, e.codigo_interno, e.numero_serie, e.nombre AS equipo, e.marca, e.modelo,
            am.nombre AS ambiente, pr.codigo AS programa_cod, pr.nombre AS programa,
            CONCAT(rep.nombres,' ',rep.apellidos) AS reportante,
            (SELECT d.diagnostico FROM diagnosticos d WHERE d.solicitud_id = s.id ORDER BY d.id DESC LIMIT 1) AS ultimo_diag
       FROM solicitudes_mantenimiento s
       JOIN equipos e ON e.id = s.equipo_id
       JOIN programas pr ON pr.id = s.programa_id
       JOIN usuarios rep ON rep.id = s.reportado_por
       LEFT JOIN ambientes am ON am.id = s.ambiente_id
      ORDER BY FIELD(s.estado,'pendiente','asignada','en_atencion','pendiente_repuestos','resuelta','cerrada','cancelada'),
               FIELD(s.urgencia_reportada,'critica','alta','media','baja'), s.fecha_reporte DESC
      LIMIT 200"
)->fetchAll();

cabecera('Área de Mantenimiento · Verificación y Diagnóstico', $u);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div>
    <h1 class="h3 fw-bold mb-0"><i class="bi bi-tools text-primary me-2"></i>Área de Mantenimiento y Soporte: Verificación de Fallas y Diagnóstico</h1>
    <div class="text-muted small">Verifique los reportes enviados por los Coordinadores/Docentes, registre el diagnóstico y genere automáticamente el Cronograma Preventivo</div>
  </div>
  <a class="btn btn-outline-primary" href="<?= e(url('admin/cronograma.php')) ?>">
    <i class="bi bi-calendar-check me-1"></i>Ver Cronograma Preventivo
  </a>
</div>

<div class="row g-3">
<?php foreach ($filas as $s): ?>
  <div class="col-12">
    <div class="card shadow-sm border-0 border-start border-4 <?= in_array($s['estado'], ['resuelta','cerrada'], true) ? 'border-success' : 'border-warning' ?>">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
          <div>
            <span class="badge bg-dark me-1"><?= e($s['codigo']) ?></span>
            <span class="badge bg-primary me-1"><?= e($s['programa_cod']) ?> · <?= e($s['ambiente'] ?? '—') ?></span>
            <?= etiqueta_estado($s['estado']) ?>
            <span class="badge text-bg-danger ms-1">Urgencia: <?= e(ucfirst($s['urgencia_reportada'])) ?></span>
          </div>
          <div class="small text-muted">
            Reportado por: <strong><?= e($s['reportante']) ?></strong> el <?= e(fecha_hora($s['fecha_reporte'])) ?>
          </div>
        </div>

        <h2 class="h5 fw-bold mb-1">
          <a href="<?= e(url('admin/buscar_equipo.php?id=' . (int) $s['equipo_id'])) ?>" class="text-decoration-none">
            <?= e(($s['codigo_interno'] ?: $s['codigo_inventario']) . ' — ' . $s['equipo'] . ' ' . ($s['marca'] ?? '') . ' ' . ($s['modelo'] ?? '')) ?>
          </a>
          <?php if (!empty($s['numero_serie'])): ?>
            <span class="badge bg-light text-dark border ms-1">Serie: <?= e($s['numero_serie']) ?></span>
          <?php endif; ?>
        </h2>

        <div class="p-2 bg-light rounded border-start border-3 border-danger mb-2 small">
          <strong>Problema reportado por Coordinación / Usuario:</strong> <?= e($s['descripcion_falla']) ?>
        </div>

        <?php if (!empty($s['ultimo_diag']) || !empty($s['resultado_final'])): ?>
          <div class="p-2 bg-success-subtle rounded small mb-2">
            <div><strong><i class="bi bi-check2-circle me-1"></i>Diagnóstico Técnico Registrado:</strong> <?= e($s['ultimo_diag'] ?? '—') ?></div>
            <?php if (!empty($s['resultado_final'])): ?>
              <div><strong>Acción / Cronograma:</strong> <?= e($s['resultado_final']) ?> <span class="text-muted">(<?= e($s['observaciones'] ?? '') ?>)</span></div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="d-flex flex-wrap gap-2 mt-2">
          <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formVerificar<?= (int) $s['id'] ?>">
            <i class="bi bi-clipboard2-check me-1"></i>Verificar Falla, Diagnosticar y Generar Cronograma
          </button>
          <a class="btn btn-sm btn-outline-dark" href="<?= e(url('admin/ficha_mantenimiento.php?id=' . (int) $s['equipo_id'])) ?>">
            <i class="bi bi-file-earmark-text me-1"></i>Ver Ficha de Mantenimiento
          </a>
          <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/buscar_equipo.php?id=' . (int) $s['equipo_id'])) ?>">
            <i class="bi bi-eye me-1"></i>Ventana de Reporte del Código
          </a>
        </div>

        <!-- Formulario desplegable de Verificación y Generación de Cronograma -->
        <div class="collapse mt-3" id="formVerificar<?= (int) $s['id'] ?>">
          <form method="post" class="card card-body bg-light border-primary">
            <?= csrf_campo() ?>
            <input type="hidden" name="solicitud_id" value="<?= (int) $s['id'] ?>">
            <h3 class="h6 fw-bold text-primary mb-3">Verificación Técnica y Programación Automática de Cronograma Preventivo</h3>
            <div class="row g-2">
              <div class="col-md-6">
                <label class="form-label small fw-bold">Diagnóstico Técnico de la Verificación</label>
                <input class="form-control form-control-sm" name="diagnostico" required value="<?= e($s['ultimo_diag'] ?? '') ?>" placeholder="Ej. Cabezal obstruido, limpieza ultrasónica y ajuste de rodillos…">
              </div>
              <div class="col-md-6">
                <label class="form-label small fw-bold">Trabajo / Solución Aplicada</label>
                <input class="form-control form-control-sm" name="resultado_final" value="<?= e($s['resultado_final'] ?? '') ?>" placeholder="Ej. Se realizó purga de tinta y calibración; queda programado en cronograma.">
              </div>
              <div class="col-md-3">
                <label class="form-label small fw-bold">Fecha de Visita Técnica</label>
                <input type="date" class="form-control form-control-sm" name="fecha_visita" value="<?= e(date('Y-m-d')) ?>" required>
              </div>
              <div class="col-md-3">
                <label class="form-label small fw-bold">Estado del Servicio</label>
                <select class="form-select form-select-sm" name="estado_servicio">
                  <option value="completo">SERVICIO COMPLETO (Resuelto)</option>
                  <option value="pendiente">SERVICIO PENDIENTE (En seguimiento)</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label small fw-bold text-danger">Próxima Visita (Cronograma Preventivo)</label>
                <input type="date" class="form-control form-control-sm border-danger" name="proxima_visita" value="<?= e(date('Y-m-d', strtotime('+90 days'))) ?>" required>
              </div>
              <div class="col-md-3">
                <label class="form-label small fw-bold">Frecuencia Preventiva</label>
                <select class="form-select form-select-sm" name="frecuencia">
                  <option value="trimestral" selected>Trimestral (90 días)</option>
                  <option value="mensual">Mensual (30 días)</option>
                  <option value="semestral">Semestral (180 días)</option>
                  <option value="anual">Anual (365 días)</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label small fw-bold">Causas Probables</label>
                <input class="form-control form-control-sm" name="causas_probables" placeholder="Ej. Desgaste de uso / falta de limpieza previa">
              </div>
              <div class="col-md-4">
                <label class="form-label small fw-bold">Recomendaciones para el Coordinador</label>
                <input class="form-control form-control-sm" name="recomendaciones" placeholder="Ej. Realizar limpieza preventiva cada 3 meses">
              </div>
              <div class="col-md-2">
                <label class="form-label small fw-bold">N.º Orden</label>
                <input class="form-control form-control-sm" name="orden_servicio" value="<?= e('OM-2026-' . str_pad((string) $s['id'], 4, '0', STR_PAD_LEFT)) ?>">
              </div>
              <div class="col-md-2">
                <label class="form-label small fw-bold">Costo (S/)</label>
                <input class="form-control form-control-sm" name="costo_mantenimiento" value="0.00">
              </div>
            </div>
            <div class="mt-3 text-end">
              <button type="submit" class="btn btn-sm btn-success fw-bold px-3">
                <i class="bi bi-check2-all me-1"></i>Guardar Diagnóstico y Publicar en Cronograma del Coordinador
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
<?php endforeach; if (!$filas): ?>
  <div class="col-12 text-center text-muted py-5">No hay solicitudes registradas.</div>
<?php endif; ?>
</div>
<?php pie();
