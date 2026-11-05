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

$fPrograma = get_int('programa');
$fAmbiente = get_int('ambiente');
$fSoloMant = isset($_GET['solo_mant']) ? (int) $_GET['solo_mant'] : 1; // Por defecto muestra los equipos que tienen mantenimiento y fechas límite

[$whereAmb, $paramsAmb] = filtro_programas('a.programa_id', $progs);
if ($fPrograma && in_array($fPrograma, $progs, true)) {
    $whereAmb .= ' AND a.programa_id = ?';
    $paramsAmb[] = $fPrograma;
}

// Resumen consolidado por cada Área / Ambiente / Laboratorio
$resumenAreas = consulta(
    "SELECT a.id AS ambiente_id, a.codigo AS ambiente_cod, a.nombre AS ambiente, a.tipo,
            pr.id AS programa_id, pr.codigo AS programa_cod, pr.nombre AS programa_nombre,
            COUNT(e.id) AS total_bienes,
            SUM(CASE WHEN e.requiere_mantenimiento = 1 THEN 1 ELSE 0 END) AS equipos_mant,
            SUM(CASE WHEN e.requiere_mantenimiento = 1 AND JSON_UNQUOTE(JSON_EXTRACT(e.especificaciones, '$.estado_servicio')) = 'completo' THEN 1 ELSE 0 END) AS mant_completos,
            SUM(CASE WHEN e.requiere_mantenimiento = 1 AND JSON_UNQUOTE(JSON_EXTRACT(e.especificaciones, '$.estado_servicio')) <> 'completo' THEN 1 ELSE 0 END) AS mant_pendientes,
            SUM(CASE WHEN e.requiere_mantenimiento = 1 AND e.fecha_limite_mantenimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS por_vencer_30d,
            SUM(CASE WHEN e.requiere_mantenimiento = 1 AND e.fecha_limite_mantenimiento < CURDATE() THEN 1 ELSE 0 END) AS vencidos
       FROM ambientes a
       JOIN programas pr ON pr.id = a.programa_id
       LEFT JOIN equipos e ON e.ambiente_id = a.id AND e.estado <> 'dado_de_baja'
      WHERE a.activo = 1 AND $whereAmb
      GROUP BY a.id, a.codigo, a.nombre, a.tipo, pr.id, pr.codigo, pr.nombre
      ORDER BY FIELD(pr.codigo,'APSTI','CONT','ENFE','AGRO','ADMIN'), equipos_mant DESC, a.nombre",
    $paramsAmb
)->fetchAll();

// Detalle de equipos del Área o Programa seleccionado
[$whereEq, $paramsEq] = filtro_programas('e.programa_id', $progs);
if ($fPrograma && in_array($fPrograma, $progs, true)) {
    $whereEq .= ' AND e.programa_id = ?';
    $paramsEq[] = $fPrograma;
}
if ($fAmbiente) {
    $whereEq .= ' AND e.ambiente_id = ?';
    $paramsEq[] = $fAmbiente;
}
if ($fSoloMant === 1) {
    $whereEq .= ' AND e.requiere_mantenimiento = 1';
}

$equiposDetalle = consulta(
    "SELECT e.id, e.numero_orden, e.codigo_inventario, e.codigo_interno, e.codigo_patrimonial,
            e.nombre, e.marca, e.modelo, e.numero_serie, e.fecha_adquisicion,
            e.estado, e.estado_conservacion, e.requiere_mantenimiento, e.frecuencia_mantenimiento,
            e.ultimo_mantenimiento, e.fecha_limite_mantenimiento, e.responsable_bien, e.especificaciones,
            pr.codigo AS programa_cod, pr.nombre AS programa_nombre,
            a.nombre AS ambiente_nombre,
            DATEDIFF(e.fecha_limite_mantenimiento, CURDATE()) AS dias_restantes
       FROM equipos e
       JOIN programas pr ON pr.id = e.programa_id
       LEFT JOIN ambientes a ON a.id = e.ambiente_id
      WHERE $whereEq AND e.estado <> 'dado_de_baja'
      ORDER BY pr.codigo, a.nombre, e.fecha_limite_mantenimiento ASC, e.codigo_inventario
      LIMIT 400",
    $paramsEq
)->fetchAll();

// Exportación directa a CSV compatible con Excel
if (($_GET['exportar'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_mantenimiento_areas_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 para Excel
    fputcsv($out, ['PROGRAMA', 'AREA / AMBIENTE', 'CODIGO', 'DENOMINACION', 'MARCA', 'MODELO', 'SERIE', 'FECHA COMPRA', 'CONSERVACION', 'ULTIMO MANT.', 'FECHA LIMITE MANT.', 'DIAS RESTANTES', 'ESTADO SERVICIO', 'RESPONSABLE AREA'], ';');
    foreach ($equiposDetalle as $row) {
        $esp = json_decode((string) ($row['especificaciones'] ?? '{}'), true) ?: [];
        fputcsv($out, [
            $row['programa_cod'],
            $row['ambiente_nombre'],
            $row['codigo_interno'] ?: $row['codigo_inventario'],
            $row['nombre'],
            $row['marca'],
            $row['modelo'],
            $row['numero_serie'],
            $row['fecha_adquisicion'],
            $row['estado_conservacion'],
            $row['ultimo_mantenimiento'],
            $row['fecha_limite_mantenimiento'],
            $row['dias_restantes'] !== null ? $row['dias_restantes'] . ' dias' : 'No aplica',
            strtoupper((string) ($esp['estado_servicio'] ?? 'PENDIENTE')),
            $row['responsable_bien'],
        ], ';');
    }
    fclose($out);
    exit;
}

$selProg = programas_para_select($u);
[$fAmbSel, $pAmbSel] = filtro_programas('programa_id', $progs);
if ($fPrograma && in_array($fPrograma, $progs, true)) {
    $fAmbSel .= ' AND programa_id = ?';
    $pAmbSel[] = $fPrograma;
}
$selAmbientes = consulta("SELECT id, nombre FROM ambientes WHERE activo = 1 AND $fAmbSel ORDER BY nombre", $pAmbSel)->fetchAll();

cabecera('Reportes por Área y Programa de Estudio', $u);
?>

<style>
@media print {
    .sidebar, nav.navbar, .no-print { display: none !important; }
    body { background: #fff !important; font-size: 11px; }
    .card { border: 1px solid #000 !important; box-shadow: none !important; }
}
</style>

<!-- Encabezado sólo visible al imprimir -->
<div class="d-none d-print-block text-center mb-3 border-bottom pb-2">
  <div class="fw-bold">INSTITUTO DE EDUCACIÓN SUPERIOR TECNOLÓGICO PÚBLICO "UTCUBAMBA" · CÓD. MODULAR 741744</div>
  <h2 class="h5 fw-bold mb-0">REPORTE DE INVENTARIO Y FECHAS LÍMITE DE MANTENIMIENTO POR PROGRAMA DE ESTUDIO Y ÁREA</h2>
  <div class="small">Emitido por: <?= e($u['nombres'] . ' ' . $u['apellidos'] . ' (' . $u['rol_nombre'] . ')') ?> · Fecha: <?= e(date('d/m/Y H:i')) ?></div>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2 no-print">
  <div>
    <h1 class="h3 fw-bold mb-0">
      <i class="bi bi-file-earmark-bar-graph text-primary me-2"></i>
      Reportes Profesionales por Programa de Estudio y Área / Ambiente
    </h1>
    <div class="text-muted small">
      <?= $u['rol'] === 'admin_general'
          ? 'Vista de Administrador General: Supervisión global de todas las áreas, laboratorios, oficinas y programas de estudio'
          : 'Vista de Coordinador de Programa: Control de inventario, equipos con mantenimiento realizado y fechas límite de su programa' ?>
    </div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-success" href="<?= e(url('admin/reportes.php?' . http_build_query(['programa' => $fPrograma, 'ambiente' => $fAmbiente, 'solo_mant' => $fSoloMant, 'exportar' => 'csv']))) ?>">
      <i class="bi bi-file-earmark-spreadsheet me-1"></i>Exportar a Excel (CSV)
    </a>
    <button onclick="window.print()" class="btn btn-danger">
      <i class="bi bi-printer me-1"></i>Imprimir Reporte / PDF
    </button>
  </div>
</div>

<!-- Filtros del Reporte -->
<form method="get" class="card card-body shadow-sm border-0 mb-4 no-print">
  <div class="row g-2 align-items-end">
    <div class="col-md-3">
      <label class="form-label small fw-bold mb-1">Programa de Estudio / Área</label>
      <select class="form-select form-select-sm" name="programa" onchange="this.form.submit()">
        <option value="">Todos los programas autorizados</option>
        <?php foreach ($selProg as $pg): ?>
          <option value="<?= (int) $pg['id'] ?>" <?= $fPrograma === (int) $pg['id'] ? 'selected' : '' ?>><?= e($pg['codigo'] . ' · ' . $pg['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label small fw-bold mb-1">Oficina / Laboratorio / Aula Específica</label>
      <select class="form-select form-select-sm" name="ambiente" onchange="this.form.submit()">
        <option value="">Todas las áreas / laboratorios (<?= count($selAmbientes) ?>)</option>
        <?php foreach ($selAmbientes as $am): ?>
          <option value="<?= (int) $am['id'] ?>" <?= $fAmbiente === (int) $am['id'] ? 'selected' : '' ?>><?= e($am['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label small fw-bold mb-1">Tipo de Bienes en el Detalle</label>
      <select class="form-select form-select-sm" name="solo_mant" onchange="this.form.submit()">
        <option value="1" <?= $fSoloMant === 1 ? 'selected' : '' ?>>Solo Equipos con Fecha de Mantenimiento (CPUs, Laptops, Impresoras…)</option>
        <option value="0" <?= $fSoloMant === 0 ? 'selected' : '' ?>>Todos los Bienes del Área (Incluye mobiliario y enseres)</option>
      </select>
    </div>
    <div class="col-md-2 d-grid">
      <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Generar Reporte</button>
    </div>
  </div>
</form>

<!-- Tabla 1: Consolidado por Área / Ambiente / Laboratorio -->
<div class="card shadow-sm border-0 mb-4">
  <div class="card-header bg-white py-3">
    <h2 class="h6 fw-bold mb-0 text-uppercase text-primary">
      <i class="bi bi-building-check me-1"></i>1. Resumen Consolidado por Área, Laboratorio y Oficina (<?= count($resumenAreas) ?> ambientes)
    </h2>
  </div>
  <div class="table-responsive">
    <table class="table table-hover table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Programa</th>
          <th>Área / Laboratorio / Oficina</th>
          <th class="text-end">Total Bienes</th>
          <th class="text-end">Equipos con Mant.</th>
          <th class="text-end text-success">Servicio Completo</th>
          <th class="text-end text-warning">Servicio Pendiente</th>
          <th class="text-end">Por Vencer (≤30d)</th>
          <th class="text-end text-danger">Vencidos</th>
          <th class="text-end no-print">Filtrar Detalle</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($resumenAreas as $ra): ?>
          <tr class="<?= $fAmbiente === (int) $ra['ambiente_id'] ? 'table-primary' : '' ?>">
            <td><span class="badge bg-primary"><?= e($ra['programa_cod']) ?></span></td>
            <td class="fw-semibold"><?= e($ra['ambiente']) ?></td>
            <td class="text-end"><?= number_format((int) $ra['total_bienes']) ?></td>
            <td class="text-end fw-bold"><?= number_format((int) $ra['equipos_mant']) ?></td>
            <td class="text-end text-success fw-semibold"><?= (int) $ra['mant_completos'] ?></td>
            <td class="text-end text-warning-emphasis fw-semibold"><?= (int) $ra['mant_pendientes'] ?></td>
            <td class="text-end"><span class="badge text-bg-warning text-dark"><?= (int) $ra['por_vencer_30d'] ?></span></td>
            <td class="text-end"><span class="badge text-bg-danger"><?= (int) $ra['vencidos'] ?></span></td>
            <td class="text-end no-print">
              <a class="btn btn-sm btn-outline-primary py-0" href="<?= e(url('admin/reportes.php?programa=' . (int) $ra['programa_id'] . '&ambiente=' . (int) $ra['ambiente_id'] . '&solo_mant=' . $fSoloMant)) ?>">
                Ver Equipos
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Tabla 2: Detalle de Equipos, Fechas de Mantenimiento y Tiempo Restante -->
<div class="card shadow-sm border-0">
  <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
    <h2 class="h6 fw-bold mb-0 text-uppercase text-primary">
      <i class="bi bi-list-Columns-reverse me-1"></i>2. Detalle de Dispositivos, Estado de Mantenimiento y Fechas Límite (<?= count($equiposDetalle) ?> registros mostrados)
    </h2>
  </div>
  <div class="table-responsive">
    <table class="table table-hover table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Código</th>
          <th>Dispositivo / Descripción</th>
          <th>Marca / Modelo / Serie</th>
          <th>Programa / Área</th>
          <th>Fecha Compra</th>
          <th>Últ. Mant.</th>
          <th>Fecha Límite (A qué tiempo)</th>
          <th>Estado Servicio</th>
          <th class="text-end no-print">Ficha</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($equiposDetalle as $eq): ?>
          <?php $esp = json_decode((string) ($eq['especificaciones'] ?? '{}'), true) ?: []; ?>
          <tr>
            <td class="fw-bold text-nowrap">
              <a href="<?= e(url('admin/buscar_equipo.php?id=' . (int) $eq['id'])) ?>" class="text-decoration-none">
                <?= e($eq['codigo_interno'] ?: $eq['codigo_inventario']) ?>
              </a>
            </td>
            <td class="fw-semibold"><?= e($eq['nombre']) ?></td>
            <td class="small">
              <?= e(trim(($eq['marca'] ?? '') . ' ' . ($eq['modelo'] ?? '')) ?: '—') ?>
              <?php if (!empty($eq['numero_serie'])): ?>
                <div class="text-muted">Serie: <?= e($eq['numero_serie']) ?></div>
              <?php endif; ?>
            </td>
            <td class="small">
              <span class="badge bg-secondary"><?= e($eq['programa_cod']) ?></span>
              <?= e($eq['ambiente_nombre'] ?? '—') ?>
            </td>
            <td class="small text-nowrap"><?= $eq['fecha_adquisicion'] ? e(date('d/m/Y', strtotime((string) $eq['fecha_adquisicion']))) : '—' ?></td>
            <td class="small text-nowrap"><?= $eq['ultimo_mantenimiento'] ? e(date('d/m/Y', strtotime((string) $eq['ultimo_mantenimiento']))) : '—' ?></td>
            <td class="text-nowrap">
              <?= semaforo_mantenimiento($eq['fecha_limite_mantenimiento'], (int) $eq['requiere_mantenimiento']) ?>
            </td>
            <td>
              <?= (int) $eq['requiere_mantenimiento'] === 1 ? insignia_servicio($esp['estado_servicio'] ?? 'pendiente') : '<span class="text-muted small">Mobiliario</span>' ?>
            </td>
            <td class="text-end no-print">
              <a class="btn btn-sm btn-outline-dark py-0" href="<?= e(url('admin/ficha_mantenimiento.php?id=' . (int) $eq['id'])) ?>">
                <i class="bi bi-file-earmark-text"></i> Ficha
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php pie();
