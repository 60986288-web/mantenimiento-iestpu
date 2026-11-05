<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/layout.php';
require dirname(__DIR__, 2) . '/includes/catalogos.php';

$u = requiere_rol('admin_general', 'admin_programa', 'tecnico');
$progs = programas_del_usuario((int) $u['id'], $u['rol']);
if (!$progs) {
    $progs = [0];
}

if (es_post()) {
    csrf_verificar();
    requiere_permiso('solicitudes.asignar');
    $sid = post_int('solicitud_id');
    $tec = post_int('tecnico_id');
    $pri = en_lista($_POST['prioridad'] ?? null, ['baja', 'media', 'alta', 'critica']);

    $sol = $sid ? consulta('SELECT id, programa_id, estado, codigo FROM solicitudes_mantenimiento WHERE id = ?', [$sid])->fetch() : false;
    $tecOk = $tec ? consulta("SELECT u.id FROM usuarios u JOIN roles r ON r.id = u.rol_id AND r.codigo = 'tecnico' WHERE u.id = ? AND u.activo = 1", [$tec])->fetchColumn() : false;

    if (!$sol || !puede_ver_programa($u, (int) $sol['programa_id'])) {
        flash('danger', 'Solicitud no encontrada.');
    } elseif (!$pri || !$tecOk) {
        flash('danger', 'Elija una prioridad y un técnico activo.');
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            consulta('UPDATE asignaciones_tecnicos SET vigente = 0 WHERE solicitud_id = ?', [$sol['id']]);
            consulta('INSERT INTO asignaciones_tecnicos (solicitud_id, tecnico_id, asignado_por) VALUES (?,?,?)', [$sol['id'], $tec, $u['id']]);
            consulta("UPDATE solicitudes_mantenimiento SET prioridad = ?, estado = 'asignada' WHERE id = ?", [$pri, $sol['id']]);
            registrar_estado('solicitud_mantenimiento', (int) $sol['id'], $sol['estado'], 'asignada', 'Prioridad ' . $pri);
            $pdo->commit();
            flash('success', 'Solicitud ' . $sol['codigo'] . ' asignada a Soporte Técnico.');
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('danger', 'No se pudo asignar la solicitud.');
        }
    }
    redirigir('admin/solicitudes.php');
}

[$f, $p] = filtro_programas('s.programa_id', $progs);
$estado = en_lista($_GET['estado'] ?? null, ['pendiente', 'asignada', 'en_atencion', 'pendiente_repuestos', 'resuelta', 'cerrada', 'cancelada']);
$sqlEstado = $estado ? ' AND s.estado = ?' : '';
$filas = consulta(
    "SELECT s.*, e.codigo_inventario, e.codigo_interno, e.nombre AS equipo, pr.codigo AS programa,
            am.nombre AS ambiente,
            CONCAT(r.nombres,' ',r.apellidos) AS reportante, CONCAT(t.nombres,' ',t.apellidos) AS tecnico,
            (SELECT d.diagnostico FROM diagnosticos d WHERE d.solicitud_id = s.id ORDER BY d.id DESC LIMIT 1) AS diagnostico
       FROM solicitudes_mantenimiento s
       JOIN equipos e ON e.id = s.equipo_id
       JOIN programas pr ON pr.id = s.programa_id
       JOIN usuarios r ON r.id = s.reportado_por
       LEFT JOIN ambientes am ON am.id = s.ambiente_id
       LEFT JOIN asignaciones_tecnicos a ON a.solicitud_id = s.id AND a.vigente = 1
       LEFT JOIN usuarios t ON t.id = a.tecnico_id
      WHERE $f $sqlEstado
      ORDER BY FIELD(s.estado,'pendiente','asignada','en_atencion','pendiente_repuestos','resuelta','cerrada','cancelada'), s.fecha_reporte DESC
      LIMIT 200",
    $estado ? [...$p, $estado] : $p
)->fetchAll();

$tecnicos = consulta("SELECT u.id, u.nombres, u.apellidos FROM usuarios u JOIN roles r ON r.id = u.rol_id AND r.codigo = 'tecnico' WHERE u.activo = 1 ORDER BY u.apellidos")->fetchAll();

cabecera('Solicitudes de Mantenimiento y Diagnósticos', $u);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div>
    <h1 class="h3 fw-bold mb-0"><i class="bi bi-ticket-detailed text-primary me-2"></i>Solicitudes de Mantenimiento y Respuesta de Soporte</h1>
    <div class="text-muted small">Seguimiento de fallas reportadas por Coordinadores/Docentes, verificación técnica y enlace directo a la Ficha y Cronograma</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-warning text-dark fw-semibold" href="<?= e(url('docente/reportar.php')) ?>"><i class="bi bi-plus-circle me-1"></i>Reportar Nueva Falla</a>
    <?php if (in_array($u['rol'], ['admin_general', 'tecnico'], true)): ?>
      <a class="btn btn-primary" href="<?= e(url('tecnico/trabajos.php')) ?>"><i class="bi bi-tools me-1"></i>Panel Técnico de Verificación</a>
    <?php endif; ?>
  </div>
</div>

<div class="table-responsive card shadow-sm border-0">
<table class="table table-hover mb-0 align-middle">
  <thead class="table-light">
    <tr>
      <th>Solicitud / Fecha</th>
      <th>Programa / Área</th>
      <th>Equipo (Código)</th>
      <th>Falla Reportada y Diagnóstico de Soporte</th>
      <th>Reportado por</th>
      <th>Estado</th>
      <th>Técnico / Acciones</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($filas as $s): ?>
    <tr>
      <td class="fw-semibold">
        <?= e($s['codigo']) ?>
        <div class="small text-muted"><?= e(fecha_hora($s['fecha_reporte'])) ?></div>
      </td>
      <td>
        <span class="badge bg-primary"><?= e($s['programa']) ?></span>
        <div class="small text-muted"><?= e($s['ambiente'] ?? '—') ?></div>
      </td>
      <td>
        <a class="fw-bold text-decoration-none" href="<?= e(url('admin/buscar_equipo.php?id=' . (int) $s['equipo_id'])) ?>">
          <?= e($s['codigo_interno'] ?: $s['codigo_inventario']) ?>
        </a>
        <div class="small"><?= e($s['equipo']) ?></div>
      </td>
      <td class="small" style="max-width: 360px;">
        <div><strong>Falla:</strong> <?= e($s['descripcion_falla']) ?></div>
        <?php if (!empty($s['diagnostico'])): ?>
          <div class="text-success mt-1"><strong><i class="bi bi-clipboard2-pulse me-1"></i>Diagnóstico Soporte:</strong> <?= e($s['diagnostico']) ?></div>
        <?php endif; ?>
        <?php if (!empty($s['observaciones'])): ?>
          <div class="text-muted mt-1"><?= e($s['observaciones']) ?></div>
        <?php endif; ?>
      </td>
      <td class="small"><?= e($s['reportante']) ?></td>
      <td><?= etiqueta_estado($s['estado']) ?></td>
      <td>
        <div class="small fw-semibold mb-1"><?= e($s['tecnico'] ?? 'Por asignar') ?></div>
        <div class="d-flex flex-wrap gap-1">
          <a class="btn btn-sm btn-outline-dark" href="<?= e(url('admin/ficha_mantenimiento.php?id=' . (int) $s['equipo_id'])) ?>">Ficha</a>
          <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/cronograma.php?programa=' . (int) $s['programa_id'])) ?>">Cronograma</a>
        </div>
      </td>
    </tr>
  <?php endforeach; if (!$filas): ?>
    <tr><td colspan="7" class="text-center text-muted py-4">No hay solicitudes registradas.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div>
<?php pie();
