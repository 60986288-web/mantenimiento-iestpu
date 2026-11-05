<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/layout.php';
require dirname(__DIR__, 2) . '/includes/catalogos.php';
require dirname(__DIR__, 2) . '/includes/uploads.php';

$u = requiere_rol('admin_general', 'admin_programa', 'docente', 'tecnico');
$progs = programas_del_usuario((int) $u['id'], $u['rol']);
if (!$progs) {
    $progs = [0];
}

$preEqId = get_int('equipo_id');

if (es_post()) {
    csrf_verificar();
    $equipo_id = post_int('equipo_id');
    $desc      = post_str('descripcion', 2000);
    $urg       = en_lista($_POST['urgencia'] ?? null, ['baja', 'media', 'alta', 'critica']);

    $equipo = $equipo_id ? consulta('SELECT id, codigo_inventario, codigo_interno, nombre, programa_id, ambiente_id, estado, especificaciones FROM equipos WHERE id = ?', [$equipo_id])->fetch() : false;

    if (!$equipo || !in_array((int) $equipo['programa_id'], $progs, true)) {
        flash('danger', 'Seleccione un equipo válido de su programa o área.');
    } elseif ($equipo['estado'] === 'dado_de_baja') {
        flash('danger', 'Ese equipo está dado de baja.');
    } elseif (mb_strlen($desc) < 5) {
        flash('danger', 'Describa el problema o falla (ej. Impresora no funciona, sale líneas).');
    } elseif (!$urg) {
        flash('danger', 'Seleccione el nivel de urgencia.');
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            consulta(
                'INSERT INTO solicitudes_mantenimiento (codigo, programa_id, ambiente_id, equipo_id, reportado_por, descripcion_falla, urgencia_reportada, prioridad, estado)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                ['TMP-' . bin2hex(random_bytes(5)), $equipo['programa_id'], $equipo['ambiente_id'], $equipo['id'], $u['id'], $desc, $urg, $urg, 'pendiente']
            );
            $id = (int) $pdo->lastInsertId();
            $codigo = generar_codigo('SM', $id);
            consulta('UPDATE solicitudes_mantenimiento SET codigo = ? WHERE id = ?', [$codigo, $id]);
            registrar_estado('solicitud_mantenimiento', $id, null, 'pendiente', 'Falla reportada por ' . $u['rol_nombre']);

            // Asignar automáticamente al técnico de soporte activo del programa para atención inmediata
            $tecId = consulta(
                "SELECT u.id FROM usuarios u JOIN roles r ON r.id = u.rol_id AND r.codigo = 'tecnico'
                  WHERE u.activo = 1 ORDER BY u.id LIMIT 1"
            )->fetchColumn();
            if ($tecId) {
                consulta('INSERT INTO asignaciones_tecnicos (solicitud_id, tecnico_id, asignado_por, observaciones) VALUES (?,?,?,?)',
                    [$id, $tecId, $u['id'], 'Derivado automáticamente al Área de Mantenimiento / Soporte']);
                consulta("UPDATE solicitudes_mantenimiento SET estado = 'asignada' WHERE id = ?", [$id]);
                consulta('INSERT INTO notificaciones (usuario_id, tipo, mensaje, enlace) VALUES (?,?,?,?)',
                    [$tecId, 'falla_reportada', "Nueva falla reportada ($codigo): " . $equipo['codigo_interno'] . ' - ' . mb_strimwidth($desc, 0, 80, '…'), 'tecnico/trabajos.php']);
            }

            // Actualizar estado del equipo y su JSON de visita
            $esp = json_decode((string) ($equipo['especificaciones'] ?? '{}'), true) ?: [];
            $esp['estado_visita']   = 'pendiente_visita';
            $esp['estado_servicio'] = 'pendiente';
            $esp['diagnostico']     = 'Reporte de falla (' . $codigo . '): ' . $desc;
            consulta("UPDATE equipos SET estado = 'con_falla', especificaciones = ? WHERE id = ?", [json_encode($esp, JSON_UNESCAPED_UNICODE), $equipo['id']]);
            registrar_estado('equipo', (int) $equipo['id'], $equipo['estado'], 'con_falla', "Solicitud $codigo");

            guardar_evidencias('fotos', 'solicitud_mantenimiento', $id, (int) $u['id']);
            auditar('crear', 'solicitudes_mantenimiento', $id, ['codigo' => $codigo]);
            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Reportar falla: ' . $ex->getMessage());
            flash('danger', 'No se pudo registrar la solicitud.');
            redirigir('docente/reportar.php');
        }
        flash('success', "Solicitud $codigo enviada al Área de Mantenimiento / Soporte Técnico. Cuando Soporte verifique y programe el cronograma, se reflejará automáticamente en su panel.");
        redirigir($u['rol'] === 'docente' ? 'docente/mis_solicitudes.php' : 'admin/solicitudes.php');
    }
    redirigir('docente/reportar.php');
}

[$f, $p] = filtro_programas('e.programa_id', $progs);
$equipos = consulta(
    "SELECT e.id, e.codigo_inventario, e.codigo_interno, e.numero_serie, e.nombre, e.marca, e.modelo,
            a.nombre AS ambiente, a.id AS ambiente_id, pr.codigo AS programa
       FROM equipos e
       JOIN programas pr ON pr.id = e.programa_id
       LEFT JOIN ambientes a ON a.id = e.ambiente_id
      WHERE $f AND e.estado <> 'dado_de_baja'
      ORDER BY e.requiere_mantenimiento DESC, a.nombre, e.codigo_inventario",
    $p
)->fetchAll();

[$f2, $p2] = filtro_programas('programa_id', $progs);
$ambientes = consulta("SELECT id, nombre FROM ambientes WHERE $f2 AND activo = 1 ORDER BY nombre", $p2)->fetchAll();

cabecera('Reportar Falla al Área de Mantenimiento', $u);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div>
    <h1 class="h3 fw-bold mb-0"><i class="bi bi-exclamation-triangle text-warning me-2"></i>Reportar Falla / Solicitud al Área de Mantenimiento</h1>
    <div class="text-muted small">Reporte de desperfectos por Coordinadores de Programa o Docentes (ej. Impresora con código tal no funciona, salen líneas)</div>
  </div>
  <a class="btn btn-outline-secondary" href="<?= e(url($u['rol'] === 'docente' ? 'docente/mis_solicitudes.php' : 'admin/solicitudes.php')) ?>">Ver Solicitudes Reportadas</a>
</div>

<form method="post" enctype="multipart/form-data" class="card card-body shadow-sm border-0">
  <?= csrf_campo() ?>
  <div class="row g-3">
    <div class="col-md-5">
      <label class="form-label fw-semibold" for="ambiente">1. Filtrar por Área / Laboratorio / Ambiente</label>
      <select class="form-select" id="ambiente" data-filtro-equipos>
        <option value="">Todas las áreas de mi programa (<?= count($ambientes) ?>)</option>
        <?php foreach ($ambientes as $a): ?>
          <option value="<?= (int) $a['id'] ?>"><?= e($a['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-7">
      <label class="form-label fw-semibold" for="equipo_id">2. Seleccione el Equipo (Código · Nombre · Serie)</label>
      <select class="form-select" id="equipo_id" name="equipo_id" required>
        <option value="">Seleccione el equipo con falla…</option>
        <?php foreach ($equipos as $eq): ?>
          <option value="<?= (int) $eq['id'] ?>" data-ambiente="<?= (int) $eq['ambiente_id'] ?>" <?= $preEqId === (int) $eq['id'] ? 'selected' : '' ?>>
            <?= e(($eq['codigo_interno'] ?: $eq['codigo_inventario']) . ' · ' . $eq['nombre'] . ($eq['marca'] ? ' ' . $eq['marca'] : '') . ($eq['numero_serie'] ? ' (Serie: ' . $eq['numero_serie'] . ')' : '') . ' — [' . ($eq['ambiente'] ?? $eq['programa']) . ']') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12">
      <label class="form-label fw-semibold" for="descripcion">3. Descripción detallada de la falla reportada</label>
      <textarea class="form-control" id="descripcion" name="descripcion" rows="3" required minlength="5" maxlength="2000" placeholder="Ejemplo: Impresora Epson con código AAR-IMP01 no funciona correctamente, salen líneas horizontales al imprimir y requiere revisión técnica urgente."></textarea>
    </div>
    <div class="col-md-4">
      <label class="form-label fw-semibold" for="urgencia">Nivel de Urgencia</label>
      <select class="form-select" id="urgencia" name="urgencia" required>
        <option value="baja">Baja</option>
        <option value="media">Media</option>
        <option value="alta" selected>Alta</option>
        <option value="critica">Crítica (Detiene actividades)</option>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label fw-semibold">Reportado por</label>
      <input class="form-control bg-light" value="<?= e($u['nombres'] . ' ' . $u['apellidos'] . ' (' . $u['rol_nombre'] . ')') ?>" disabled>
    </div>
    <div class="col-md-4">
      <label class="form-label fw-semibold" for="fotos">Evidencia Fotográfica (Opcional)</label>
      <input class="form-control" type="file" id="fotos" name="fotos[]" accept="image/jpeg,image/png,image/webp" multiple>
    </div>
  </div>
  <div class="mt-4 d-flex gap-2">
    <button class="btn btn-primary px-4 fw-bold" type="submit"><i class="bi bi-send me-1"></i>Enviar Reporte al Área de Mantenimiento</button>
    <a class="btn btn-outline-secondary" href="<?= e(url('index.php')) ?>">Cancelar</a>
  </div>
</form>
<?php pie();
