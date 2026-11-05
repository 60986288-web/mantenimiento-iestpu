<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
require dirname(__DIR__) . '/includes/catalogos.php';

$u = requiere_login();
$progs = programas_del_usuario((int) $u['id'], $u['rol']);
if (!$progs) {
    $progs = [0];
}
[$fEq, $pEq] = filtro_programas('programa_id', $progs);
[$fSm, $pSm] = filtro_programas('programa_id', $progs);

function contar(string $sql, array $p = []): int
{
    return (int) consulta($sql, $p)->fetchColumn();
}

$totalBienes      = contar("SELECT COUNT(*) FROM equipos WHERE $fEq AND estado <> 'dado_de_baja'", $pEq);
$equiposMant      = contar("SELECT COUNT(*) FROM equipos WHERE $fEq AND requiere_mantenimiento = 1 AND estado <> 'dado_de_baja'", $pEq);
$mantCompletos    = contar("SELECT COUNT(*) FROM equipos WHERE $fEq AND requiere_mantenimiento = 1 AND JSON_UNQUOTE(JSON_EXTRACT(especificaciones, '$.estado_servicio')) = 'completo'", $pEq);
$mantPendientes   = contar("SELECT COUNT(*) FROM equipos WHERE $fEq AND requiere_mantenimiento = 1 AND (fecha_limite_mantenimiento <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) OR estado IN ('con_falla','en_mantenimiento'))", $pEq);
$mantVencidos     = contar("SELECT COUNT(*) FROM equipos WHERE $fEq AND requiere_mantenimiento = 1 AND fecha_limite_mantenimiento < CURDATE()", $pEq);
$solicitudesAct   = contar("SELECT COUNT(*) FROM solicitudes_mantenimiento WHERE $fSm AND estado IN ('pendiente','asignada','en_atencion','pendiente_repuestos')", $pSm);

[$fPr, $pPr] = filtro_programas('pr.id', $progs);
$resumenProgramas = consulta(
    "SELECT pr.id, pr.codigo, pr.nombre,
            COUNT(DISTINCT a.id) AS total_ambientes,
            COUNT(e.id) AS total_bienes,
            SUM(CASE WHEN e.requiere_mantenimiento = 1 THEN 1 ELSE 0 END) AS total_mantenibles,
            SUM(CASE WHEN e.requiere_mantenimiento = 1 AND e.fecha_limite_mantenimiento < CURDATE() THEN 1 ELSE 0 END) AS vencidos,
            SUM(CASE WHEN e.requiere_mantenimiento = 1 AND e.fecha_limite_mantenimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS proximos
       FROM programas pr
       LEFT JOIN ambientes a ON a.programa_id = pr.id AND a.activo = 1
       LEFT JOIN equipos e ON e.programa_id = pr.id AND e.estado <> 'dado_de_baja'
      WHERE $fPr
      GROUP BY pr.id, pr.codigo, pr.nombre
      ORDER BY FIELD(pr.codigo,'APSTI','CONT','ENFE','AGRO','ADMIN')",
    $pPr
)->fetchAll();

cabecera('Panel Principal', $u);
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
  <div>
    <h1 class="h3 fw-bold mb-1">Bienvenido, <?= e($u['nombres'] . ' ' . $u['apellidos']) ?></h1>
    <p class="text-muted mb-0">
      <span class="badge bg-dark me-1"><?= e($u['rol_nombre']) ?></span>
      Sistema Integrado de Inventario Patrimonial y Mantenimiento Preventivo / Correctivo — IESTP Utcubamba
    </p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <a href="<?= e(url('admin/buscar_equipo.php')) ?>" class="btn btn-primary">
      <i class="bi bi-search me-1"></i> Buscar por Código o Serie
    </a>
    <a href="<?= e(url('docente/reportar.php')) ?>" class="btn btn-warning text-dark fw-semibold">
      <i class="bi bi-exclamation-triangle me-1"></i> Reportar Falla de Equipo
    </a>
    <?php if (in_array($u['rol'], ['admin_general', 'admin_programa', 'tecnico'], true)): ?>
      <a href="<?= e(url('admin/reportes.php')) ?>" class="btn btn-outline-dark">
        <i class="bi bi-file-earmark-bar-graph me-1"></i> Reportes por Área y Programa
      </a>
    <?php endif; ?>
  </div>
</div>

<!-- Buscador rápido directo al Módulo 2 (Mantenimiento por Código o Serie) -->
<div class="card border-0 shadow-sm mb-4 bg-white">
  <div class="card-body p-3">
    <form method="get" action="<?= e(url('admin/buscar_equipo.php')) ?>" class="row g-2 align-items-center">
      <div class="col-md-3">
        <span class="fw-bold text-primary"><i class="bi bi-upc-scan me-1"></i> Consulta Rápida de Mantenimiento:</span>
      </div>
      <div class="col-md-7">
        <input class="form-control" name="q" placeholder="Ingrese correlativo (ej. 001), código (ej. LC1-CPU01, DG-LAPO1, AAR-IMP01, AA-IMP) o N.º de Serie (ej. PF3AQQX0)…">
      </div>
      <div class="col-md-2">
        <button class="btn btn-primary w-100" type="submit"><i class="bi bi-search me-1"></i> Consultar Bien</button>
      </div>
    </form>
  </div>
</div>

<!-- KPIs -->
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-2">
    <div class="card border-start border-4 border-primary shadow-sm h-100">
      <div class="card-body py-3">
        <div class="text-muted small">Total Bienes Inventario</div>
        <div class="h2 fw-bold mb-0 text-primary"><?= number_format($totalBienes) ?></div>
        <a href="<?= e(url('admin/equipos.php')) ?>" class="small text-decoration-none">Ver inventario →</a>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-2">
    <div class="card border-start border-4 border-info shadow-sm h-100">
      <div class="card-body py-3">
        <div class="text-muted small">Equipos con Mant.</div>
        <div class="h2 fw-bold mb-0 text-info"><?= number_format($equiposMant) ?></div>
        <a href="<?= e(url('admin/equipos.php?f_mant=mantenibles')) ?>" class="small text-decoration-none">Ver equipos TI/Lab →</a>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-2">
    <div class="card border-start border-4 border-success shadow-sm h-100">
      <div class="card-body py-3">
        <div class="text-muted small">Servicio Completo</div>
        <div class="h2 fw-bold mb-0 text-success"><?= number_format($mantCompletos) ?></div>
        <a href="<?= e(url('admin/equipos.php?f_mant=completos')) ?>" class="small text-decoration-none">Al día →</a>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-2">
    <div class="card border-start border-4 border-warning shadow-sm h-100">
      <div class="card-body py-3">
        <div class="text-muted small">Próximos / Pendientes</div>
        <div class="h2 fw-bold mb-0 text-warning"><?= number_format($mantPendientes) ?></div>
        <a href="<?= e(url('admin/equipos.php?f_mant=proximos')) ?>" class="small text-decoration-none">Por vencer (≤30d) →</a>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-2">
    <div class="card border-start border-4 border-danger shadow-sm h-100">
      <div class="card-body py-3">
        <div class="text-muted small">Fecha Límite Vencida</div>
        <div class="h2 fw-bold mb-0 text-danger"><?= number_format($mantVencidos) ?></div>
        <a href="<?= e(url('admin/equipos.php?f_mant=vencidos')) ?>" class="small text-decoration-none">Atención urgente →</a>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-2">
    <div class="card border-start border-4 border-dark shadow-sm h-100">
      <div class="card-body py-3">
        <div class="text-muted small">Fallas en Atención</div>
        <div class="h2 fw-bold mb-0 text-dark"><?= number_format($solicitudesAct) ?></div>
        <a href="<?= e(url($u['rol'] === 'tecnico' ? 'tecnico/trabajos.php' : ($u['rol'] === 'docente' ? 'docente/mis_solicitudes.php' : 'admin/solicitudes.php'))) ?>" class="small text-decoration-none">Ver solicitudes →</a>
      </div>
    </div>
  </div>
</div>

<!-- Tabla Resumen por Programa de Estudio / Área Institucional -->
<div class="card shadow-sm border-0 mb-4">
  <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
    <h2 class="h5 fw-bold mb-0"><i class="bi bi-diagram-3 text-primary me-2"></i>Inventario y Fechas Límite de Mantenimiento por Programa de Estudio y Área</h2>
    <a href="<?= e(url('admin/reportes.php')) ?>" class="btn btn-sm btn-outline-primary">Ver Reporte Detallado por Áreas</a>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Código</th>
          <th>Programa de Estudio / Área Institucional</th>
          <th class="text-center">Ambientes / Oficinas</th>
          <th class="text-end">Total Bienes</th>
          <th class="text-end">Equipos Mantenibles</th>
          <th class="text-end">Próximos (≤30 días)</th>
          <th class="text-end">Vencidos</th>
          <th class="text-end">Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($resumenProgramas as $rp): ?>
          <tr>
            <td><span class="badge bg-primary"><?= e($rp['codigo']) ?></span></td>
            <td class="fw-semibold"><?= e($rp['nombre']) ?></td>
            <td class="text-center"><?= (int) $rp['total_ambientes'] ?></td>
            <td class="text-end fw-bold"><?= number_format((int) $rp['total_bienes']) ?></td>
            <td class="text-end"><?= number_format((int) $rp['total_mantenibles']) ?></td>
            <td class="text-end"><span class="badge text-bg-warning text-dark"><?= (int) $rp['proximos'] ?></span></td>
            <td class="text-end"><span class="badge text-bg-danger"><?= (int) $rp['vencidos'] ?></span></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/equipos.php?programa=' . (int) $rp['id'])) ?>">Ver Inventario</a>
              <a class="btn btn-sm btn-outline-dark" href="<?= e(url('admin/reportes.php?programa=' . (int) $rp['id'])) ?>">Reporte por Áreas</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php pie();
