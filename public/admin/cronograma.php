<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/layout.php';
require dirname(__DIR__, 2) . '/includes/catalogos.php';

$u = requiere_login();
$progs = programas_del_usuario((int) $u['id'], $u['rol']);
if (!$progs) {
    $progs = [0];
}

if (es_post()) {
    csrf_verificar();
    $prog_id = post_int('programacion_id');
    if ($prog_id) {
        $p = consulta(
            "SELECT pr.id, pl.equipo_id, pl.frecuencia
               FROM programaciones_mantenimiento pr
               JOIN planes_mantenimiento_preventivo pl ON pl.id = pr.plan_id
              WHERE pr.id = ?",
            [$prog_id]
        )->fetch();
        if ($p) {
            $diasMap = ['mensual' => 30, 'trimestral' => 90, 'semestral' => 180, 'anual' => 365];
            $dias = $diasMap[$p['frecuencia']] ?? 90;
            $proxFecha = date('Y-m-d', strtotime("+{$dias} days"));

            consulta("UPDATE programaciones_mantenimiento SET estado = 'realizado', fin_real = NOW(), resultado = 'Mantenimiento preventivo verificado y completado', proxima_fecha = ? WHERE id = ?", [$proxFecha, $prog_id]);

            $eqRow = consulta('SELECT especificaciones FROM equipos WHERE id = ?', [$p['equipo_id']])->fetch();
            $esp = json_decode((string) ($eqRow['especificaciones'] ?? '{}'), true) ?: [];
            $esp['estado_visita']   = 'visitado';
            $esp['fecha_visita']    = date('Y-m-d');
            $esp['estado_servicio'] = 'completo';
            $esp['proxima_visita']  = $proxFecha;
            consulta("UPDATE equipos SET estado = 'operativo', ultimo_mantenimiento = CURDATE(), fecha_limite_mantenimiento = ?, especificaciones = ? WHERE id = ?",
                [$proxFecha, json_encode($esp, JSON_UNESCAPED_UNICODE), $p['equipo_id']]);

            registrar_estado('programacion', $prog_id, 'programado', 'realizado', 'Completado desde el cronograma');
            flash('success', 'Mantenimiento marcado como SERVICIO COMPLETO. Se actualizó la próxima fecha límite a ' . date('d/m/Y', strtotime($proxFecha)) . '.');
        }
    }
    redirigir('admin/cronograma.php');
}

$fPrograma = get_int('programa');
$fAmbiente = get_int('ambiente');
$fEstado   = en_lista($_GET['estado'] ?? null, ['programado', 'pendiente', 'realizado', 'vencido']);

[$where, $params] = filtro_programas('pl.programa_id', $progs);
if ($fPrograma && in_array($fPrograma, $progs, true)) {
    $where .= ' AND pl.programa_id = ?';
    $params[] = $fPrograma;
}
if ($fAmbiente) {
    $where .= ' AND pl.ambiente_id = ?';
    $params[] = $fAmbiente;
}
if ($fEstado) {
    if ($fEstado === 'vencido') {
        $where .= " AND (pr.estado = 'vencido' OR (pr.fecha_programada < CURDATE() AND pr.estado IN ('programado','pendiente')))";
    } else {
        $where .= ' AND pr.estado = ?';
        $params[] = $fEstado;
    }
}

$cronograma = consulta(
    "SELECT pr.id, pr.fecha_programada, pr.estado, pr.resultado, pr.proxima_fecha,
            e.id AS equipo_id, e.codigo_inventario, e.codigo_interno, e.numero_serie, e.nombre AS equipo,
            prg.codigo AS programa, a.nombre AS ambiente, pl.nombre AS plan, pl.frecuencia,
            CONCAT(t.nombres,' ',t.apellidos) AS tecnico
       FROM programaciones_mantenimiento pr
       JOIN planes_mantenimiento_preventivo pl ON pl.id = pr.plan_id
       JOIN equipos e ON e.id = pl.equipo_id
       JOIN programas prg ON prg.id = pl.programa_id
       LEFT JOIN ambientes a ON a.id = pl.ambiente_id
       LEFT JOIN usuarios t ON t.id = pr.tecnico_id
      WHERE $where
      ORDER BY (pr.estado IN ('programado','pendiente','vencido')) DESC, pr.fecha_programada ASC
      LIMIT 150",
    $params
)->fetchAll();

$selProg = programas_para_select($u);
[$fAmb, $pAmb] = filtro_programas('programa_id', $progs);
$selAmbientes = consulta("SELECT id, nombre FROM ambientes WHERE activo = 1 AND $fAmb ORDER BY nombre", $pAmb)->fetchAll();

cabecera('Cronograma de Verificación y Mantenimiento Preventivo', $u);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div>
    <h1 class="h3 fw-bold text-dark mb-0"><i class="bi bi-calendar-check text-primary me-2"></i>Cronograma de Verificación y Mantenimiento Preventivo</h1>
    <div class="text-muted small">Generado por el Área de Soporte / Mantenimiento y visualizado automáticamente por los Coordinadores de Programa de Estudio</div>
  </div>
  <div class="d-flex gap-2 no-print">
    <?php if (in_array($u['rol'], ['admin_general', 'admin_programa', 'tecnico'], true)): ?>
      <a href="<?= e(url('admin/planes.php')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Programar Mantenimiento (Individual o por Laboratorio/Área)</a>
    <?php endif; ?>
    <button onclick="window.print()" class="btn btn-outline-dark"><i class="bi bi-printer me-1"></i>Imprimir Cronograma</button>
  </div>
</div>

<!-- Filtros del Cronograma -->
<form method="get" class="card card-body shadow-sm border-0 mb-3 no-print">
  <div class="row g-2 align-items-end">
    <div class="col-md-3">
      <label class="form-label small fw-bold mb-1">Programa de Estudio</label>
      <select class="form-select form-select-sm" name="programa" onchange="this.form.submit()">
        <option value="">Todos mis programas</option>
        <?php foreach ($selProg as $pg): ?>
          <option value="<?= (int) $pg['id'] ?>" <?= $fPrograma === (int) $pg['id'] ? 'selected' : '' ?>><?= e($pg['codigo'] . ' · ' . $pg['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label small fw-bold mb-1">Área / Laboratorio / Oficina</label>
      <select class="form-select form-select-sm" name="ambiente" onchange="this.form.submit()">
        <option value="">Todos los ambientes</option>
        <?php foreach ($selAmbientes as $am): ?>
          <option value="<?= (int) $am['id'] ?>" <?= $fAmbiente === (int) $am['id'] ? 'selected' : '' ?>><?= e($am['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label small fw-bold mb-1">Estado en Cronograma</label>
      <select class="form-select form-select-sm" name="estado">
        <option value="">Todos los estados</option>
        <option value="programado" <?= $fEstado === 'programado' ? 'selected' : '' ?>>Programados / Próximas Visitas</option>
        <option value="realizado" <?= $fEstado === 'realizado' ? 'selected' : '' ?>>Realizados (Servicio Completo)</option>
        <option value="vencido" <?= $fEstado === 'vencido' ? 'selected' : '' ?>>Vencidos (Atención Urgente)</option>
      </select>
    </div>
    <div class="col-md-2 d-grid">
      <button class="btn btn-sm btn-primary" type="submit">Filtrar</button>
    </div>
  </div>
</form>

<div class="card shadow-sm border-0">
  <div class="table-responsive">
    <table class="table table-hover table-sm mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>Fecha Límite / Visita</th>
          <th>Código · Equipo</th>
          <th>Programa / Área (Ambiente)</th>
          <th>Plan / Diagnóstico</th>
          <th>Técnico Soporte</th>
          <th>Estado Servicio</th>
          <th class="text-end no-print">Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($cronograma as $c): ?>
          <?php
            $hoy = date('Y-m-d');
            $vencido = ($c['fecha_programada'] < $hoy && in_array($c['estado'], ['programado','pendiente','vencido'], true));
          ?>
          <tr class="<?= $vencido ? 'table-danger' : ($c['estado'] === 'realizado' ? '' : 'table-warning') ?>">
            <td class="fw-semibold text-nowrap">
              <?= semaforo_mantenimiento($c['fecha_programada'], 1) ?>
            </td>
            <td>
              <a class="fw-bold text-decoration-none" href="<?= e(url('admin/buscar_equipo.php?id=' . (int) $c['equipo_id'])) ?>">
                <?= e($c['codigo_interno'] ?: $c['codigo_inventario']) ?>
              </a>
              <div class="small fw-semibold"><?= e($c['equipo']) ?></div>
              <?php if (!empty($c['numero_serie'])): ?><div class="small text-muted">Serie: <?= e($c['numero_serie']) ?></div><?php endif; ?>
            </td>
            <td>
              <span class="badge bg-primary"><?= e($c['programa']) ?></span>
              <div class="small fw-semibold"><?= e($c['ambiente'] ?? '—') ?></div>
            </td>
            <td class="small" style="max-width: 300px;">
              <div class="fw-semibold"><?= e($c['plan']) ?> (<?= e(ucfirst($c['frecuencia'])) ?>)</div>
              <div class="text-muted"><?= e($c['resultado'] ?? '—') ?></div>
            </td>
            <td class="small"><?= e($c['tecnico'] ?? 'Soporte Técnico IESTPU') ?></td>
            <td>
              <?= $c['estado'] === 'realizado' ? insignia_servicio('completo') : insignia_servicio('pendiente') ?>
            </td>
            <td class="text-end text-nowrap no-print">
              <?php if ($c['estado'] !== 'realizado' && in_array($u['rol'], ['admin_general', 'admin_programa', 'tecnico'], true)): ?>
                <form method="post" class="d-inline">
                  <?= csrf_campo() ?>
                  <input type="hidden" name="programacion_id" value="<?= (int) $c['id'] ?>">
                  <button class="btn btn-sm btn-success" type="submit" title="Confirmar que se realizó el mantenimiento">
                    <i class="bi bi-check-lg"></i> Completar
                  </button>
                </form>
              <?php endif; ?>
              <a class="btn btn-sm btn-outline-dark" href="<?= e(url('admin/ficha_mantenimiento.php?id=' . (int) $c['equipo_id'])) ?>">
                Ficha
              </a>
            </td>
          </tr>
        <?php endforeach; if (!$cronograma): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No hay mantenimientos programados con esos filtros.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php pie();
