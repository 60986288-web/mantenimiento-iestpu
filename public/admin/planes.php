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
    $modo        = $_POST['modo'] ?? 'ambiente'; // 'ambiente' o 'equipo'
    $ambienteId  = post_int('ambiente_id');
    $equipoId    = post_int('equipo_id');
    $frecuencia  = en_lista($_POST['frecuencia'] ?? null, ['mensual', 'trimestral', 'semestral', 'anual']) ?? 'trimestral';
    $fechaProg   = fecha_valida_o_null(post_str('fecha_programada', 10)) ?: date('Y-m-d', strtotime('+30 days'));
    $tecnicoId   = post_int('tecnico_id') ?: (int) $u['id'];
    $descripcion = post_str('descripcion', 500) ?: 'Mantenimiento preventivo y verificación operativa programada por Soporte Técnico';

    $equiposTarget = [];
    if ($modo === 'equipo' && $equipoId) {
        $eq = consulta('SELECT id, programa_id, ambiente_id, nombre FROM equipos WHERE id = ?', [$equipoId])->fetch();
        if ($eq && in_array((int) $eq['programa_id'], $progs, true)) {
            $equiposTarget[] = $eq;
        }
    } elseif ($ambienteId) {
        $amb = consulta('SELECT id, programa_id, nombre FROM ambientes WHERE id = ?', [$ambienteId])->fetch();
        if ($amb && in_array((int) $amb['programa_id'], $progs, true)) {
            $equiposTarget = consulta(
                "SELECT id, programa_id, ambiente_id, nombre FROM equipos WHERE ambiente_id = ? AND requiere_mantenimiento = 1 AND estado <> 'dado_de_baja'",
                [$ambienteId]
            )->fetchAll();
        }
    }

    if (!$equiposTarget) {
        flash('warning', 'No se encontraron equipos mantenibles para programar en la selección indicada.');
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        $cont = 0;
        foreach ($equiposTarget as $eq) {
            consulta(
                'INSERT INTO planes_mantenimiento_preventivo (programa_id, ambiente_id, equipo_id, nombre, descripcion, frecuencia, tecnico_responsable_id, fecha_inicio, activo, creado_por)
                 VALUES (?,?,?,?,?,?,?,?,1,?)',
                [$eq['programa_id'], $eq['ambiente_id'], $eq['id'], 'Plan Preventivo ' . ucfirst($frecuencia) . ' - ' . $eq['nombre'], $descripcion, $frecuencia, $tecnicoId, date('Y-m-d'), $u['id']]
            );
            $plId = (int) $pdo->lastInsertId();
            consulta(
                "INSERT INTO programaciones_mantenimiento (plan_id, fecha_programada, tecnico_id, estado, resultado, proxima_fecha)
                 VALUES (?,?,?,'programado',?,?)",
                [$plId, $fechaProg, $tecnicoId, $descripcion, $fechaProg]
            );
            consulta('UPDATE equipos SET fecha_limite_mantenimiento = ?, frecuencia_mantenimiento = ? WHERE id = ?',
                [$fechaProg, $frecuencia, $eq['id']]);
            $cont++;
        }
        $pdo->commit();
        flash('success', "Se programó el cronograma preventivo para $cont equipo(s) con fecha límite " . date('d/m/Y', strtotime($fechaProg)) . ". El Coordinador ya lo visualiza automáticamente.");
        redirigir('admin/cronograma.php');
    }
}

[$fA, $pA] = filtro_programas('a.programa_id', $progs);
$ambientes = consulta(
    "SELECT a.id, a.nombre, pr.codigo AS programa,
            (SELECT COUNT(*) FROM equipos e WHERE e.ambiente_id = a.id AND e.requiere_mantenimiento = 1) AS n_mant
       FROM ambientes a JOIN programas pr ON pr.id = a.programa_id
      WHERE a.activo = 1 AND $fA ORDER BY pr.codigo, a.nombre",
    $pA
)->fetchAll();

[$fE, $pE] = filtro_programas('e.programa_id', $progs);
$equipos = consulta(
    "SELECT e.id, e.codigo_interno, e.codigo_inventario, e.nombre, a.nombre AS ambiente
       FROM equipos e LEFT JOIN ambientes a ON a.id = e.ambiente_id
      WHERE $fE AND e.requiere_mantenimiento = 1 ORDER BY a.nombre, e.codigo_inventario",
    $pE
)->fetchAll();

$tecnicos = consulta("SELECT u.id, u.nombres, u.apellidos FROM usuarios u JOIN roles r ON r.id = u.rol_id AND r.codigo IN ('tecnico','admin_general') WHERE u.activo = 1")->fetchAll();

cabecera('Programar Cronograma Preventivo', $u);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h3 fw-bold mb-0"><i class="bi bi-calendar-plus text-primary me-2"></i>Generar Cronograma de Verificación y Mantenimiento Preventivo</h1>
  <a class="btn btn-outline-secondary" href="<?= e(url('admin/cronograma.php')) ?>">← Volver al Cronograma</a>
</div>

<form method="post" class="card card-body shadow-sm border-0">
  <?= csrf_campo() ?>
  <div class="row g-3">
    <div class="col-md-4">
      <label class="form-label fw-semibold">Modalidad de Programación</label>
      <select class="form-select" name="modo" id="modo_prog" onchange="document.getElementById('box_amb').style.display = this.value==='ambiente'?'block':'none'; document.getElementById('box_eq').style.display = this.value==='equipo'?'block':'none';">
        <option value="ambiente">Por Área / Laboratorio Completo (En lote)</option>
        <option value="equipo">Para un Equipo Específico</option>
      </select>
    </div>
    <div class="col-md-8" id="box_amb">
      <label class="form-label fw-semibold">Seleccione el Área / Laboratorio (ej. Laboratorio de Cómputo I, Dirección General)</label>
      <select class="form-select" name="ambiente_id">
        <?php foreach ($ambientes as $a): ?>
          <option value="<?= (int) $a['id'] ?>"><?= e($a['programa'] . ' · ' . $a['nombre'] . ' (' . $a['n_mant'] . ' equipos mantenibles)') ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-8" id="box_eq" style="display:none;">
      <label class="form-label fw-semibold">Seleccione el Equipo Específico</label>
      <select class="form-select" name="equipo_id">
        <?php foreach ($equipos as $eq): ?>
          <option value="<?= (int) $eq['id'] ?>"><?= e(($eq['codigo_interno'] ?: $eq['codigo_inventario']) . ' · ' . $eq['nombre'] . ' (' . ($eq['ambiente'] ?? '') . ')') ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label fw-semibold text-danger">Fecha Programada / Fecha Límite</label>
      <input type="date" class="form-control border-danger" name="fecha_programada" value="<?= e(date('Y-m-d', strtotime('+15 days'))) ?>" required>
    </div>
    <div class="col-md-4">
      <label class="form-label fw-semibold">Frecuencia del Plan</label>
      <select class="form-select" name="frecuencia">
        <option value="trimestral" selected>Trimestral (Cada 90 días)</option>
        <option value="mensual">Mensual (Cada 30 días)</option>
        <option value="semestral">Semestral (Cada 180 días)</option>
        <option value="anual">Anual (Cada 365 días)</option>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label fw-semibold">Técnico de Soporte Responsable</label>
      <select class="form-select" name="tecnico_id">
        <?php foreach ($tecnicos as $t): ?>
          <option value="<?= (int) $t['id'] ?>"><?= e($t['nombres'] . ' ' . $t['apellidos']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12">
      <label class="form-label fw-semibold">Actividades del Cronograma Preventivo</label>
      <input class="form-control" name="descripcion" value="Verificación operativa, limpieza interna de hardware, revisión eléctrica y actualización de ficha técnica">
    </div>
  </div>
  <div class="mt-4">
    <button type="submit" class="btn btn-primary px-4 fw-bold"><i class="bi bi-calendar2-check me-1"></i>Generar Cronograma Preventivo</button>
  </div>
</form>
<?php pie();
