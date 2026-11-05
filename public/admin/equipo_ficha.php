<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/layout.php';
require dirname(__DIR__, 2) . '/includes/catalogos.php';

$u = requiere_rol('admin_general', 'admin_programa');
$progs = programas_del_usuario((int) $u['id'], $u['rol']);

$id = get_int('id');
$eq = $id ? consulta(
    "SELECT e.*, pr.nombre AS programa, c.nombre AS categoria, c.es_consumible, a.nombre AS ambiente, a.codigo AS ambiente_codigo
       FROM equipos e JOIN programas pr ON pr.id = e.programa_id
       JOIN categorias_equipos c ON c.id = e.categoria_id
       LEFT JOIN ambientes a ON a.id = e.ambiente_id WHERE e.id = ?", [$id])->fetch() : false;
if (!$eq || !in_array((int) $eq['programa_id'], $progs, true)) {
    http_response_code(404);
    exit('Equipo no encontrado.');
}

$correctivos = consulta(
    "SELECT s.id, s.codigo, s.fecha_reporte, s.estado, s.descripcion_falla, s.resultado_final,
            TIMESTAMPDIFF(MINUTE, s.inicio_atencion, s.fin_atencion) AS minutos,
            CONCAT(t.nombres,' ',t.apellidos) AS tecnico
       FROM solicitudes_mantenimiento s
       LEFT JOIN asignaciones_tecnicos a ON a.solicitud_id = s.id AND a.vigente = 1
       LEFT JOIN usuarios t ON t.id = a.tecnico_id
      WHERE s.equipo_id = ? ORDER BY s.fecha_reporte DESC", [$id])->fetchAll();

$preventivos = consulta(
    "SELECT pr.fecha_programada, pr.estado, pr.fin_real, pl.nombre, pl.frecuencia
       FROM programaciones_mantenimiento pr JOIN planes_mantenimiento_preventivo pl ON pl.id = pr.plan_id
      WHERE pl.equipo_id = ? ORDER BY pr.fecha_programada DESC LIMIT 50", [$id])->fetchAll();

$repuestos = consulta(
    "SELECT ru.fecha_registro, ru.cantidad, ru.estado, r.codigo, r.nombre, r.unidad_medida, s.codigo AS solicitud
       FROM repuestos_utilizados ru JOIN repuestos r ON r.id = ru.repuesto_id
       LEFT JOIN solicitudes_mantenimiento s ON s.id = ru.solicitud_id
      WHERE ru.equipo_id = ? ORDER BY ru.fecha_registro DESC LIMIT 50", [$id])->fetchAll();

$estados = consulta(
    "SELECT h.fecha_hora, h.estado_anterior, h.estado_nuevo, h.comentario, CONCAT(u.nombres,' ',u.apellidos) AS usuario
       FROM historial_estados h LEFT JOIN usuarios u ON u.id = h.usuario_id
      WHERE h.entidad = 'equipo' AND h.entidad_id = ? ORDER BY h.fecha_hora DESC, h.id DESC LIMIT 50", [$id])->fetchAll();

$resueltas = array_filter($correctivos, fn($s) => $s['minutos'] !== null);
$promedio = $resueltas ? (int) round(array_sum(array_column($resueltas, 'minutos')) / count($resueltas)) : null;

cabecera('Ficha del equipo', $u);
?>
<div class="d-flex flex-wrap justify-content-between align-items-start mb-3 gap-2">
  <div>
    <a class="small" href="<?= e(url('admin/equipos.php')) ?>">← Equipos</a>
    <h1 class="h3 mb-0"><?= e($eq['nombre']) ?> <?= insignia_equipo($eq['estado']) ?></h1>
    <div class="text-muted"><?= e($eq['codigo_inventario']) ?> · <?= e($eq['programa']) ?></div>
  </div>
  <?php if (tiene_permiso($u, 'equipos.gestionar')): ?>
    <a class="btn btn-outline-primary" href="<?= e(url('admin/equipo_form.php?id=' . (int) $eq['id'])) ?>">Editar</a>
  <?php endif; ?>
</div>

<div class="card shadow-sm mb-4"><div class="card-body"><dl class="row mb-0">
  <dt class="col-sm-3">Ambiente</dt><dd class="col-sm-9"><?= e($eq['ambiente'] ? $eq['ambiente_codigo'] . ' · ' . $eq['ambiente'] : '—') ?></dd>
  <dt class="col-sm-3">Categoría</dt><dd class="col-sm-9"><?= e($eq['categoria']) ?><?= $eq['es_consumible'] ? ' (consumible)' : '' ?></dd>
  <dt class="col-sm-3">Marca / modelo</dt><dd class="col-sm-9"><?= e(trim(($eq['marca'] ?? '') . ' ' . ($eq['modelo'] ?? '')) ?: '—') ?></dd>
  <dt class="col-sm-3">N.º de serie</dt><dd class="col-sm-9"><?= e($eq['numero_serie'] ?? '—') ?></dd>
  <dt class="col-sm-3">Adquisición</dt><dd class="col-sm-9"><?= e($eq['fecha_adquisicion'] ? date('d/m/Y', strtotime($eq['fecha_adquisicion'])) : '—') ?></dd>
  <dt class="col-sm-3">Descripción</dt><dd class="col-sm-9"><?= nl2br(e($eq['descripcion'] ?? '—')) ?></dd>
  <dt class="col-sm-3">Observaciones</dt><dd class="col-sm-9 mb-0"><?= nl2br(e($eq['observaciones'] ?? '—')) ?></dd>
</dl></div></div>

<div class="row g-3 mb-4">
  <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body"><div class="small text-muted">Reparaciones</div><div class="display-6"><?= count($correctivos) ?></div></div></div></div>
  <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body"><div class="small text-muted">Tiempo medio de reparación</div><div class="display-6"><?= $promedio === null ? '—' : e(intdiv($promedio, 60) . ' h ' . ($promedio % 60) . ' min') ?></div></div></div></div>
</div>

<ul class="nav nav-tabs" role="tablist">
  <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#t-corr" type="button">Correctivos</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-prev" type="button">Preventivos</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-rep" type="button">Repuestos usados</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-est" type="button">Cambios de estado</button></li>
</ul>
<div class="tab-content card card-body border-top-0 rounded-top-0 shadow-sm">
  <div class="tab-pane fade show active" id="t-corr"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Código</th><th>Fecha</th><th>Falla</th><th>Técnico</th><th>Estado</th><th>Resultado</th></tr></thead><tbody>
    <?php foreach ($correctivos as $s): ?><tr>
      <td><?= e($s['codigo']) ?></td><td><?= e(fecha_hora($s['fecha_reporte'])) ?></td>
      <td class="small"><?= e(mb_strimwidth($s['descripcion_falla'], 0, 80, '…')) ?></td>
      <td class="small"><?= e($s['tecnico'] ?? '—') ?></td><td><?= etiqueta_estado($s['estado']) ?></td>
      <td class="small"><?= e($s['resultado_final'] ?? '—') ?></td></tr>
    <?php endforeach; if (!$correctivos): ?><tr><td colspan="6" class="text-muted text-center">Sin fallas registradas.</td></tr><?php endif; ?>
  </tbody></table></div></div>
  <div class="tab-pane fade" id="t-prev"><div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>Plan</th><th>Frecuencia</th><th>Programado</th><th>Ejecutado</th><th>Estado</th></tr></thead><tbody>
    <?php foreach ($preventivos as $r): ?><tr>
      <td><?= e($r['nombre']) ?></td><td><?= e(texto_estado($r['frecuencia'])) ?></td>
      <td><?= e(date('d/m/Y', strtotime($r['fecha_programada']))) ?></td><td><?= e(fecha_hora($r['fin_real'])) ?></td>
      <td><?= e(texto_estado($r['estado'])) ?></td></tr>
    <?php endforeach; if (!$preventivos): ?><tr><td colspan="5" class="text-muted text-center">Sin mantenimientos preventivos.</td></tr><?php endif; ?>
  </tbody></table></div></div>
  <div class="tab-pane fade" id="t-rep"><div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>Fecha</th><th>Repuesto</th><th class="text-end">Cantidad</th><th>Solicitud</th><th>Estado</th></tr></thead><tbody>
    <?php foreach ($repuestos as $r): ?><tr>
      <td><?= e(fecha_hora($r['fecha_registro'])) ?></td><td><?= e($r['codigo'] . ' · ' . $r['nombre']) ?></td>
      <td class="text-end"><?= e(rtrim(rtrim((string) $r['cantidad'], '0'), '.') . ' ' . $r['unidad_medida']) ?></td>
      <td><?= e($r['solicitud'] ?? '—') ?></td><td><?= e(texto_estado($r['estado'])) ?></td></tr>
    <?php endforeach; if (!$repuestos): ?><tr><td colspan="5" class="text-muted text-center">Sin repuestos registrados.</td></tr><?php endif; ?>
  </tbody></table></div></div>
  <div class="tab-pane fade" id="t-est"><div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>Fecha</th><th>De</th><th>A</th><th>Responsable</th><th>Comentario</th></tr></thead><tbody>
    <?php foreach ($estados as $h): ?><tr>
      <td><?= e(fecha_hora($h['fecha_hora'])) ?></td><td><?= e($h['estado_anterior'] ? texto_estado($h['estado_anterior']) : '—') ?></td>
      <td><?= e(texto_estado($h['estado_nuevo'])) ?></td><td class="small"><?= e($h['usuario'] ?? '—') ?></td>
      <td class="small"><?= e($h['comentario'] ?? '') ?></td></tr>
    <?php endforeach; if (!$estados): ?><tr><td colspan="5" class="text-muted text-center">Sin cambios registrados.</td></tr><?php endif; ?>
  </tbody></table></div></div>
</div>
<?php pie();
