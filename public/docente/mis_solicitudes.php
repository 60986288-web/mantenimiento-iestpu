<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/layout.php';

$u = requiere_rol('docente');

$filas = consulta(
    "SELECT s.id, s.codigo, s.fecha_reporte, s.estado, s.urgencia_reportada, s.resultado_final, s.observaciones,
            e.codigo_inventario, e.nombre AS equipo, a.nombre AS ambiente
       FROM solicitudes_mantenimiento s
       JOIN equipos e ON e.id = s.equipo_id
       LEFT JOIN ambientes a ON a.id = s.ambiente_id
      WHERE s.reportado_por = ? ORDER BY s.fecha_reporte DESC LIMIT 200",
    [$u['id']]
)->fetchAll();

cabecera('Mis solicitudes', $u);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h3 mb-0">Mis solicitudes</h1>
  <a class="btn btn-primary" href="<?= e(url('docente/reportar.php')) ?>">Reportar falla</a>
</div>
<div class="table-responsive card shadow-sm">
<table class="table table-hover mb-0 align-middle">
  <thead><tr><th>Código</th><th>Fecha</th><th>Equipo</th><th>Ambiente</th><th>Estado</th><th>Resultado / observaciones</th></tr></thead>
  <tbody>
  <?php foreach ($filas as $f): ?>
    <tr>
      <td class="fw-semibold"><?= e($f['codigo']) ?></td>
      <td><?= e(fecha_hora($f['fecha_reporte'])) ?></td>
      <td><?= e($f['codigo_inventario'] . ' · ' . $f['equipo']) ?></td>
      <td><?= e($f['ambiente'] ?? '—') ?></td>
      <td><?= etiqueta_estado($f['estado']) ?></td>
      <td class="small"><?= e($f['resultado_final'] ?: ($f['observaciones'] ?: '—')) ?></td>
    </tr>
  <?php endforeach; if (!$filas): ?>
    <tr><td colspan="6" class="text-center text-muted py-4">Aún no ha reportado fallas.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div>
<?php pie();
