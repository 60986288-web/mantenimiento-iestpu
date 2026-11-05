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

$fPrograma  = get_int('programa');
$fAmbiente  = get_int('ambiente');
$fCategoria = get_int('categoria');
$fEstado    = en_lista($_GET['estado'] ?? null, ESTADOS_EQUIPO);
$fMant      = en_lista($_GET['f_mant'] ?? null, ['mantenibles', 'proximos', 'vencidos', 'completos']);
$fTexto     = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
$pagina     = max(1, get_int('pagina') ?? 1);
$porPagina  = 30;

[$where, $params] = filtro_programas('e.programa_id', $progs);
if ($fPrograma && in_array($fPrograma, $progs, true)) {
    $where .= ' AND e.programa_id = ?';
    $params[] = $fPrograma;
}
if ($fAmbiente) {
    $where .= ' AND e.ambiente_id = ?';
    $params[] = $fAmbiente;
}
if ($fCategoria) {
    $where .= ' AND e.categoria_id = ?';
    $params[] = $fCategoria;
}
if ($fEstado) {
    $where .= ' AND e.estado = ?';
    $params[] = $fEstado;
}
if ($fMant === 'mantenibles') {
    $where .= ' AND e.requiere_mantenimiento = 1';
} elseif ($fMant === 'proximos') {
    $where .= ' AND e.requiere_mantenimiento = 1 AND e.fecha_limite_mantenimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)';
} elseif ($fMant === 'vencidos') {
    $where .= ' AND e.requiere_mantenimiento = 1 AND e.fecha_limite_mantenimiento < CURDATE()';
} elseif ($fMant === 'completos') {
    $where .= " AND e.requiere_mantenimiento = 1 AND JSON_UNQUOTE(JSON_EXTRACT(e.especificaciones, '$.estado_servicio')) = 'completo'";
}
if ($fTexto !== '') {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $fTexto) . '%';
    $numQ = ltrim($fTexto, '0');
    if ($numQ !== '' && ctype_digit($numQ)) {
        $where .= ' AND (e.numero_orden = ? OR e.id = ? OR e.codigo_inventario LIKE ? OR e.codigo_interno LIKE ? OR e.nombre LIKE ? OR e.numero_serie LIKE ?)';
        array_push($params, (int) $numQ, (int) $numQ, $like, $like, $like, $like);
    } else {
        $where .= ' AND (e.codigo_inventario LIKE ? OR e.codigo_interno LIKE ? OR e.codigo_patrimonial LIKE ? OR e.nombre LIKE ? OR e.numero_serie LIKE ? OR e.marca LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }
}

$total = (int) consulta("SELECT COUNT(*) FROM equipos e WHERE $where", $params)->fetchColumn();
$paginas = max(1, (int) ceil($total / $porPagina));
$pagina = min($pagina, $paginas);
$offset = ($pagina - 1) * $porPagina;

$filas = consulta(
    "SELECT e.id, e.numero_orden, e.codigo_inventario, e.codigo_interno, e.codigo_patrimonial,
            e.nombre, e.descripcion, e.marca, e.modelo, e.numero_serie, e.fecha_adquisicion,
            e.estado, e.estado_conservacion, e.requiere_mantenimiento, e.frecuencia_mantenimiento,
            e.ultimo_mantenimiento, e.fecha_limite_mantenimiento, e.responsable_bien, e.especificaciones,
            pr.codigo AS programa, pr.nombre AS programa_nombre,
            c.nombre AS categoria, c.es_consumible,
            a.nombre AS ambiente
       FROM equipos e
       JOIN programas pr ON pr.id = e.programa_id
       JOIN categorias_equipos c ON c.id = e.categoria_id
       LEFT JOIN ambientes a ON a.id = e.ambiente_id
      WHERE $where
      ORDER BY pr.codigo, a.nombre, e.numero_orden, e.codigo_inventario
      LIMIT $porPagina OFFSET $offset",
    $params
)->fetchAll();

$selProg = programas_para_select($u);
[$fAmbSql, $pAmbSql] = filtro_programas('programa_id', $progs);
if ($fPrograma && in_array($fPrograma, $progs, true)) {
    $fAmbSql .= ' AND programa_id = ?';
    $pAmbSql[] = $fPrograma;
}
$selAmbientes = consulta("SELECT id, nombre, codigo FROM ambientes WHERE activo = 1 AND $fAmbSql ORDER BY nombre", $pAmbSql)->fetchAll();
$selCategorias = consulta("SELECT id, nombre FROM categorias_equipos ORDER BY id")->fetchAll();

$qs = fn(int $pg) => url('admin/equipos.php?' . http_build_query(array_filter([
    'programa'  => $fPrograma,
    'ambiente'  => $fAmbiente,
    'categoria' => $fCategoria,
    'estado'    => $fEstado,
    'f_mant'    => $fMant,
    'q'         => $fTexto,
    'pagina'    => $pg,
], fn($v) => $v !== null && $v !== '' && $v !== 0)));

cabecera('Módulo 1 · Inventario de Bienes', $u);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div>
    <h1 class="h3 fw-bold mb-0"><i class="bi bi-box-seam text-primary me-2"></i>Módulo 1: Inventario General de Bienes <span class="badge bg-secondary fs-6 ms-1"><?= number_format($total) ?> bienes</span></h1>
    <div class="text-muted small">Registro institucional por Programa de Estudio y Área/Ambiente con Fecha de Compra y Fecha Límite de Mantenimiento</div>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <?php if (tiene_permiso($u, 'equipos.gestionar')): ?>
      <a class="btn btn-outline-success" href="<?= e(url('admin/importar_inventario.php')) ?>"><i class="bi bi-file-earmark-excel me-1"></i>Importar Excel/CSV</a>
      <a class="btn btn-primary" href="<?= e(url('admin/equipo_form.php')) ?>"><i class="bi bi-plus-lg me-1"></i>Registrar Bien</a>
    <?php endif; ?>
    <a class="btn btn-outline-dark" href="<?= e(url('admin/reportes.php?' . http_build_query(array_filter(['programa' => $fPrograma, 'ambiente' => $fAmbiente])))) ?>">
      <i class="bi bi-printer me-1"></i>Reporte Profesional de Área
    </a>
  </div>
</div>

<!-- Filtros por Programa de Estudio, Área/Ambiente, Categoría y Estado de Mantenimiento -->
<form method="get" class="card card-body shadow-sm border-0 mb-3">
  <div class="row g-2 align-items-end">
    <div class="col-md-2">
      <label class="form-label small fw-bold mb-1">Programa de Estudio</label>
      <select class="form-select form-select-sm" name="programa" onchange="this.form.submit()">
        <option value="">Todos los programas</option>
        <?php foreach ($selProg as $pg): ?>
          <option value="<?= (int) $pg['id'] ?>" <?= $fPrograma === (int) $pg['id'] ? 'selected' : '' ?>><?= e($pg['codigo'] . ' · ' . $pg['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label small fw-bold mb-1">Área / Ambiente / Laboratorio</label>
      <select class="form-select form-select-sm" name="ambiente" onchange="this.form.submit()">
        <option value="">Todas las áreas / ambientes (<?= count($selAmbientes) ?>)</option>
        <?php foreach ($selAmbientes as $am): ?>
          <option value="<?= (int) $am['id'] ?>" <?= $fAmbiente === (int) $am['id'] ? 'selected' : '' ?>><?= e($am['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small fw-bold mb-1">Categoría</label>
      <select class="form-select form-select-sm" name="categoria">
        <option value="">Todas las categorías</option>
        <?php foreach ($selCategorias as $cat): ?>
          <option value="<?= (int) $cat['id'] ?>" <?= $fCategoria === (int) $cat['id'] ? 'selected' : '' ?>><?= e($cat['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small fw-bold mb-1">Control Mantenimiento</label>
      <select class="form-select form-select-sm" name="f_mant">
        <option value="">Todos los bienes</option>
        <option value="mantenibles" <?= $fMant === 'mantenibles' ? 'selected' : '' ?>>Solo Equipos con Mant.</option>
        <option value="proximos" <?= $fMant === 'proximos' ? 'selected' : '' ?>>Próximos a vencer (≤30d)</option>
        <option value="vencidos" <?= $fMant === 'vencidos' ? 'selected' : '' ?>>Fecha límite vencida</option>
        <option value="completos" <?= $fMant === 'completos' ? 'selected' : '' ?>>Servicio Completo</option>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small fw-bold mb-1">Código, Serie o Nombre</label>
      <input class="form-control form-control-sm" name="q" value="<?= e($fTexto) ?>" placeholder="Ej. 001, LC1-CPU01, PF3AQQX0">
    </div>
    <div class="col-md-1 d-grid">
      <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Filtrar</button>
    </div>
  </div>
</form>

<div class="table-responsive card shadow-sm border-0">
<table class="table table-hover table-sm mb-0 align-middle">
  <thead class="table-light">
    <tr>
      <th>N.º / Código</th>
      <th>Descripción / Denominación</th>
      <th>Marca / Modelo / Serie</th>
      <th>Fecha Compra</th>
      <th>Programa / Área (Ambiente)</th>
      <th class="text-center">Conserv.</th>
      <th>Estado</th>
      <th>Fecha Límite Mantenimiento</th>
      <th class="text-end">Mantenimiento / Ficha</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($filas as $q): ?>
    <?php
      $esp = json_decode((string) ($q['especificaciones'] ?? '{}'), true) ?: [];
      $corr = str_pad((string) ($q['numero_orden'] ?: $q['id']), 3, '0', STR_PAD_LEFT);
    ?>
    <tr>
      <td>
        <span class="badge bg-light text-dark border me-1">#<?= e($corr) ?></span>
        <a class="fw-bold text-decoration-none" href="<?= e(url('admin/buscar_equipo.php?id=' . (int) $q['id'])) ?>">
          <?= e($q['codigo_interno'] ?: $q['codigo_inventario']) ?>
        </a>
        <?php if (!empty($q['codigo_patrimonial'])): ?>
          <div class="small text-muted">Patr: <?= e($q['codigo_patrimonial']) ?></div>
        <?php endif; ?>
      </td>
      <td>
        <div class="fw-semibold"><?= e($q['nombre']) ?></div>
        <div class="small text-muted"><?= e(mb_strimwidth((string) ($q['descripcion'] ?? ''), 0, 65, '…')) ?></div>
      </td>
      <td class="small">
        <div><?= e(trim(($q['marca'] ?? '') . ' ' . ($q['modelo'] ?? '')) ?: '—') ?></div>
        <?php if (!empty($q['numero_serie'])): ?>
          <span class="badge bg-light text-dark border"><i class="bi bi-upc me-1"></i>Serie: <?= e($q['numero_serie']) ?></span>
        <?php endif; ?>
      </td>
      <td class="small text-nowrap">
        <?= $q['fecha_adquisicion'] ? e(date('d/m/Y', strtotime((string) $q['fecha_adquisicion']))) : '—' ?>
      </td>
      <td>
        <span class="badge bg-primary"><?= e($q['programa']) ?></span>
        <div class="small fw-semibold text-dark mt-1"><?= e($q['ambiente'] ?? '—') ?></div>
      </td>
      <td class="text-center"><?= insignia_conservacion($q['estado_conservacion'] ?? null) ?></td>
      <td><?= insignia_equipo($q['estado']) ?></td>
      <td>
        <?= semaforo_mantenimiento($q['fecha_limite_mantenimiento'] ?? null, (int) $q['requiere_mantenimiento']) ?>
        <?php if ((int) $q['requiere_mantenimiento'] === 1): ?>
          <div class="mt-1"><?= insignia_servicio($esp['estado_servicio'] ?? 'pendiente') ?></div>
        <?php endif; ?>
      </td>
      <td class="text-end text-nowrap">
        <a class="btn btn-sm btn-outline-primary" title="Abrir en Módulo de Mantenimiento" href="<?= e(url('admin/buscar_equipo.php?id=' . (int) $q['id'])) ?>">
          <i class="bi bi-wrench-adjustable"></i>
        </a>
        <a class="btn btn-sm btn-outline-dark" title="Ver Ficha de Mantenimiento (Formato Excel/Scribd)" href="<?= e(url('admin/ficha_mantenimiento.php?id=' . (int) $q['id'])) ?>">
          <i class="bi bi-file-earmark-text"></i> Ficha
        </a>
        <?php if (tiene_permiso($u, 'equipos.gestionar')): ?>
          <a class="btn btn-sm btn-outline-secondary" title="Editar Bien" href="<?= e(url('admin/equipo_form.php?id=' . (int) $q['id'])) ?>">
            <i class="bi bi-pencil"></i>
          </a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$filas): ?>
    <tr><td colspan="9" class="text-center text-muted py-4">No se encontraron bienes con esos filtros.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div>

<?php if ($paginas > 1): ?>
<nav class="mt-3"><ul class="pagination pagination-sm flex-wrap">
  <?php for ($i = 1; $i <= $paginas; $i++): ?>
    <li class="page-item <?= $i === $pagina ? 'active' : '' ?>"><a class="page-link" href="<?= e($qs($i)) ?>"><?= $i ?></a></li>
  <?php endfor; ?>
</ul></nav>
<?php endif;
pie();
