<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/layout.php';
require dirname(__DIR__, 2) . '/includes/catalogos.php';

$u = requiere_rol('admin_general', 'admin_programa', 'tecnico');
requiere_permiso('equipos.gestionar');
$progs = programas_del_usuario((int) $u['id'], $u['rol']);

$id = get_int('id') ?? post_int('id');
$eq = false;
if ($id) {
    $eq = consulta('SELECT * FROM equipos WHERE id = ?', [$id])->fetch();
    if (!$eq || !in_array((int) $eq['programa_id'], $progs, true)) {
        http_response_code(404);
        exit('Equipo no encontrado.');
    }
}

$espActual = json_decode((string) ($eq['especificaciones'] ?? '{}'), true) ?: [];

if (es_post()) {
    csrf_verificar();
    $programa   = post_int('programa_id');
    $categoria  = post_int('categoria_id');
    $ambiente   = post_int('ambiente_id');
    $codigo     = mb_strtoupper(post_str('codigo_inventario', 40));
    $codPat     = mb_strtoupper(post_str('codigo_patrimonial', 40)) ?: null;
    $codInt     = mb_strtoupper(post_str('codigo_interno', 40)) ?: $codigo;
    $numOrden   = post_int('numero_orden') ?: 1;
    $nombre     = post_str('nombre', 150);
    $desc       = post_str('descripcion', 2000) ?: null;
    $marca      = post_str('marca', 80) ?: null;
    $modelo     = post_str('modelo', 80) ?: null;
    $tipoFisico = post_str('tipo_fisico', 60) ?: null;
    $color      = post_str('color', 60) ?: null;
    $dimensiones= post_str('dimensiones', 100) ?: null;
    $serie      = post_str('numero_serie', 100) ?: null;
    $obs        = post_str('observaciones', 2000) ?: null;
    $fechaAdq   = fecha_o_null(post_str('fecha_adquisicion', 10));
    $estado     = en_lista($_POST['estado'] ?? null, ESTADOS_EQUIPO) ?? 'operativo';
    $estCons    = en_lista($_POST['estado_conservacion'] ?? null, array_keys(ESTADOS_CONSERVACION)) ?? 'B';
    $reqMant    = isset($_POST['requiere_mantenimiento']) ? 1 : 0;
    $frecMant   = en_lista($_POST['frecuencia_mantenimiento'] ?? null, array_keys(FRECUENCIAS_MANT)) ?? 'no_aplica';
    $ultMant    = fecha_valida_o_null(post_str('ultimo_mantenimiento', 10)) ?: null;
    $fechaLim   = fecha_valida_o_null(post_str('fecha_limite_mantenimiento', 10)) ?: null;
    $respBien   = post_str('responsable_bien', 150) ?: 'ERNESTO CORREA BARRANTES';
    $dniResp    = post_str('dni_responsable', 15) ?: '27722958';

    $error = null;
    if (!$programa || !in_array($programa, $progs, true)) {
        $error = 'Programa no válido.';
    } elseif ($codigo === '' || $nombre === '') {
        $error = 'El código de inventario y la descripción/nombre son obligatorios.';
    } elseif ($fechaAdq === false) {
        $error = 'La fecha de compra/adquisición no es válida.';
    } else {
        $catOk = $categoria && consulta('SELECT 1 FROM categorias_equipos WHERE id = ? AND (programa_id IS NULL OR programa_id = ?)', [$categoria, $programa])->fetchColumn();
        if (!$catOk) {
            $error = 'Seleccione una categoría válida.';
        }
        if ($ambiente) {
            $ambOk = consulta('SELECT 1 FROM ambientes WHERE id = ? AND programa_id = ? AND activo = 1', [$ambiente, $programa])->fetchColumn();
            if (!$ambOk) {
                $error = 'El ambiente no pertenece a ese programa.';
            }
        } else {
            $ambiente = null;
        }
    }

    if ($error) {
        flash('danger', $error);
        redirigir('admin/equipo_form.php' . ($id ? '?id=' . $id : ''));
    }

    $espNueva = array_merge($espActual, [
        'responsable_acta'    => $respBien,
        'dni_responsable'     => $dniResp,
        'periodo_calibracion' => $reqMant ? strtoupper($frecMant) : 'NO APLICA',
        'proxima_visita'      => $fechaLim,
        'fecha_visita'        => $espActual['fecha_visita'] ?? $fechaLim,
        'estado_servicio'     => $espActual['estado_servicio'] ?? ($reqMant ? 'pendiente' : 'no_aplica'),
        'estado_visita'       => $espActual['estado_visita'] ?? ($reqMant ? 'programada' : 'no_requiere'),
    ]);
    $espJson = json_encode($espNueva, JSON_UNESCAPED_UNICODE);

    $pdo = db();
    try {
        $pdo->beginTransaction();
        if ($eq) {
            consulta(
                'UPDATE equipos SET programa_id=?, ambiente_id=?, categoria_id=?, codigo_inventario=?, codigo_patrimonial=?, codigo_interno=?, numero_orden=?,
                        nombre=?, descripcion=?, marca=?, modelo=?, tipo_fisico=?, color=?, dimensiones=?, numero_serie=?, fecha_adquisicion=?,
                        estado=?, estado_conservacion=?, requiere_mantenimiento=?, frecuencia_mantenimiento=?, ultimo_mantenimiento=?, fecha_limite_mantenimiento=?,
                        responsable_bien=?, dni_responsable=?, observaciones=?, especificaciones=?
                  WHERE id=?',
                [$programa, $ambiente, $categoria, $codigo, $codPat, $codInt, $numOrden,
                 $nombre, $desc, $marca, $modelo, $tipoFisico, $color, $dimensiones, $serie, $fechaAdq,
                 $estado, $estCons, $reqMant, $frecMant, $ultMant, $fechaLim,
                 $respBien, $dniResp, $obs, $espJson, $id]
            );
            if ($estado !== $eq['estado']) {
                registrar_estado('equipo', $id, $eq['estado'], $estado, 'Actualización desde inventario');
            }
            auditar('editar', 'equipos', $id);
            $destino = $id;
        } else {
            consulta(
                'INSERT INTO equipos (programa_id, ambiente_id, categoria_id, codigo_inventario, codigo_patrimonial, codigo_interno, numero_orden,
                        nombre, descripcion, marca, modelo, tipo_fisico, color, dimensiones, numero_serie, fecha_adquisicion,
                        estado, estado_conservacion, requiere_mantenimiento, frecuencia_mantenimiento, ultimo_mantenimiento, fecha_limite_mantenimiento,
                        responsable_bien, dni_responsable, observaciones, especificaciones, creado_por)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$programa, $ambiente, $categoria, $codigo, $codPat, $codInt, $numOrden,
                 $nombre, $desc, $marca, $modelo, $tipoFisico, $color, $dimensiones, $serie, $fechaAdq,
                 $estado, $estCons, $reqMant, $frecMant, $ultMant, $fechaLim,
                 $respBien, $dniResp, $obs, $espJson, $u['id']]
            );
            $destino = (int) $pdo->lastInsertId();
            registrar_estado('equipo', $destino, null, $estado, 'Alta en el inventario');
            auditar('crear', 'equipos', $destino, ['codigo' => $codigo]);
        }
        $pdo->commit();
        flash('success', 'Bien guardado correctamente en el inventario.');
        redirigir('admin/buscar_equipo.php?id=' . $destino);
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', $ex->getCode() === '23000' ? 'Ya existe un bien con ese código de inventario.' : 'No se pudo guardar el bien.');
        redirigir('admin/equipo_form.php' . ($id ? '?id=' . $id : ''));
    }
}

$selProg = programas_para_select($u);
[$fA, $pA] = filtro_programas('programa_id', $progs);
$ambientes = consulta("SELECT id, programa_id, nombre FROM ambientes WHERE activo = 1 AND $fA ORDER BY nombre", $pA)->fetchAll();
$categorias = consulta("SELECT id, programa_id, nombre, es_consumible FROM categorias_equipos WHERE programa_id IS NULL OR $fA ORDER BY id", $pA)->fetchAll();

cabecera($eq ? 'Editar Bien del Inventario' : 'Registrar Bien en el Inventario', $u);
$v = fn(string $k, string $d = '') => e((string) ($eq[$k] ?? $d));
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h3 fw-bold mb-0"><?= $eq ? 'Editar Bien del Inventario' : 'Nuevo Registro en Inventario de Bienes' ?></h1>
  <a class="btn btn-outline-secondary" href="<?= e(url('admin/equipos.php')) ?>">← Volver al Inventario</a>
</div>

<form method="post" class="card card-body shadow-sm border-0">
  <?= csrf_campo() ?>
  <?php if ($eq): ?><input type="hidden" name="id" value="<?= (int) $eq['id'] ?>"><?php endif; ?>

  <h2 class="h6 fw-bold text-primary border-bottom pb-2 mb-3">1. Ubicación Institucional (Programa de Estudio y Área/Oficina)</h2>
  <div class="row g-3 mb-4">
    <div class="col-md-4">
      <label class="form-label fw-semibold">Programa de Estudio / Área</label>
      <select class="form-select" name="programa_id" id="f_programa" required>
        <?php foreach ($selProg as $pg): ?>
          <option value="<?= (int) $pg['id'] ?>" <?= ($eq['programa_id'] ?? 0) == $pg['id'] ? 'selected' : '' ?>><?= e($pg['codigo'] . ' · ' . $pg['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label fw-semibold">Oficina / Laboratorio / Ambiente</label>
      <select class="form-select" name="ambiente_id" id="f_ambiente">
        <option value="">Seleccione ambiente…</option>
        <?php foreach ($ambientes as $a): ?>
          <option value="<?= (int) $a['id'] ?>" data-programa="<?= (int) $a['programa_id'] ?>" <?= ($eq['ambiente_id'] ?? 0) == $a['id'] ? 'selected' : '' ?>><?= e($a['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label fw-semibold">Categoría del Bien</label>
      <select class="form-select" name="categoria_id" id="f_categoria" required>
        <?php foreach ($categorias as $c): ?>
          <option value="<?= (int) $c['id'] ?>" data-programa="<?= (int) ($c['programa_id'] ?? 0) ?>" <?= ($eq['categoria_id'] ?? 1) == $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-8">
      <label class="form-label fw-semibold">Usuario / Responsable del Área (Cliente)</label>
      <input class="form-control" name="responsable_bien" maxlength="150" value="<?= $v('responsable_bien', 'ERNESTO CORREA BARRANTES') ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label fw-semibold">DNI del Responsable</label>
      <input class="form-control" name="dni_responsable" maxlength="15" value="<?= $v('dni_responsable', '27722958') ?>">
    </div>
  </div>

  <h2 class="h6 fw-bold text-primary border-bottom pb-2 mb-3">2. Identificación del Producto (Campos del Excel Institucional)</h2>
  <div class="row g-3 mb-4">
    <div class="col-md-2">
      <label class="form-label fw-semibold">N.º Correlativo</label>
      <input class="form-control" type="number" name="numero_orden" min="1" value="<?= $v('numero_orden', '1') ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label fw-semibold">Código de Inventario (Único)</label>
      <input class="form-control" name="codigo_inventario" required maxlength="40" placeholder="Ej. LC1-CPU01" value="<?= $v('codigo_inventario') ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label fw-semibold">Código Interno (Acta Excel)</label>
      <input class="form-control" name="codigo_interno" maxlength="40" placeholder="Ej. LC1-CPU01" value="<?= $v('codigo_interno') ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label fw-semibold">Código Patrimonial (SBN)</label>
      <input class="form-control" name="codigo_patrimonial" maxlength="40" placeholder="Opcional" value="<?= $v('codigo_patrimonial') ?>">
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold">Denominación / Nombre del Producto</label>
      <input class="form-control" name="nombre" required maxlength="150" placeholder="Ej. LAPTOP LENOVO / CPU ADVANCE / IMPRESORA EPSON" value="<?= $v('nombre') ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label fw-semibold">Fecha de Compra / Adquisición</label>
      <input class="form-control" type="date" name="fecha_adquisicion" max="<?= e(date('Y-m-d')) ?>" value="<?= $v('fecha_adquisicion', '2024-01-18') ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label fw-semibold">N.º de Serie</label>
      <input class="form-control" name="numero_serie" maxlength="100" placeholder="Ej. PF3AQQX0" value="<?= $v('numero_serie') ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label fw-semibold">Marca</label>
      <input class="form-control" name="marca" maxlength="80" value="<?= $v('marca') ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label fw-semibold">Modelo</label>
      <input class="form-control" name="modelo" maxlength="80" value="<?= $v('modelo') ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label fw-semibold">Tipo / Material</label>
      <input class="form-control" name="tipo_fisico" maxlength="60" value="<?= $v('tipo_fisico') ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label fw-semibold">Color</label>
      <input class="form-control" name="color" maxlength="60" value="<?= $v('color') ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label fw-semibold">Dimensiones</label>
      <input class="form-control" name="dimensiones" maxlength="100" value="<?= $v('dimensiones') ?>">
    </div>
  </div>

  <h2 class="h6 fw-bold text-primary border-bottom pb-2 mb-3">3. Estado y Fechas Límite de Mantenimiento</h2>
  <div class="row g-3 mb-4">
    <div class="col-md-3">
      <label class="form-label fw-semibold">Estado Operativo</label>
      <select class="form-select" name="estado">
        <?php foreach (ESTADOS_EQUIPO as $es): ?>
          <option value="<?= e($es) ?>" <?= ($eq['estado'] ?? 'operativo') === $es ? 'selected' : '' ?>><?= e(texto_estado($es)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label fw-semibold">Estado de Conservación (Excel)</label>
      <select class="form-select" name="estado_conservacion">
        <?php foreach (ESTADOS_CONSERVACION as $k => $lbl): ?>
          <option value="<?= e($k) ?>" <?= ($eq['estado_conservacion'] ?? 'B') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label fw-semibold">Frecuencia de Mantenimiento</label>
      <select class="form-select" name="frecuencia_mantenimiento">
        <?php foreach (FRECUENCIAS_MANT as $k => $lbl): ?>
          <option value="<?= e($k) ?>" <?= ($eq['frecuencia_mantenimiento'] ?? 'trimestral') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3 d-flex align-items-center pt-4">
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" id="req_mant" name="requiere_mantenimiento" value="1" <?= ((int) ($eq['requiere_mantenimiento'] ?? 1)) === 1 ? 'checked' : '' ?>>
        <label class="form-check-label fw-semibold" for="req_mant">Requiere Mantenimiento Técnico</label>
      </div>
    </div>
    <div class="col-md-4">
      <label class="form-label fw-semibold">Fecha de Último Mantenimiento</label>
      <input class="form-control" type="date" name="ultimo_mantenimiento" value="<?= $v('ultimo_mantenimiento') ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label fw-semibold text-danger">Fecha Límite de Mantenimiento (Próxima Visita)</label>
      <input class="form-control border-danger" type="date" name="fecha_limite_mantenimiento" value="<?= $v('fecha_limite_mantenimiento') ?>">
    </div>
    <div class="col-md-12">
      <label class="form-label fw-semibold">Descripción detallada del bien</label>
      <textarea class="form-control" name="descripcion" rows="2" maxlength="2000"><?= $v('descripcion') ?></textarea>
    </div>
    <div class="col-md-12">
      <label class="form-label fw-semibold">Observaciones</label>
      <textarea class="form-control" name="observaciones" rows="2" maxlength="2000"><?= $v('observaciones') ?></textarea>
    </div>
  </div>

  <div class="d-flex gap-2">
    <button class="btn btn-primary px-4" type="submit"><i class="bi bi-save me-1"></i>Guardar Bien en Inventario</button>
    <a class="btn btn-outline-secondary" href="<?= e(url('admin/equipos.php')) ?>">Cancelar</a>
  </div>
</form>
<?php pie();
