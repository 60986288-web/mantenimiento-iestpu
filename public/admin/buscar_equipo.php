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

// Procesar actualización rápida de Visita / Servicio / Diagnóstico / Próxima Visita desde la ventana de reporte
if (es_post()) {
    csrf_verificar();
    $eqId           = post_int('equipo_id');
    $estadoVisita   = en_lista($_POST['estado_visita'] ?? null, ['programada', 'en_visita', 'visitado', 'pendiente_visita', 'reprogramada']) ?? 'visitado';
    $fechaVisita    = fecha_valida_o_null(post_str('fecha_visita', 10)) ?: date('Y-m-d');
    $estadoServicio = en_lista($_POST['estado_servicio'] ?? null, ['completo', 'pendiente']) ?? 'completo';
    $proximaVisita  = fecha_valida_o_null(post_str('proxima_visita', 10)) ?: date('Y-m-d', strtotime('+90 days'));
    $tecnicoNombre  = post_str('tecnico_responsable', 150) ?: ($u['nombres'] . ' ' . $u['apellidos']);
    $oficinaCliente = post_str('oficina_cliente', 150);
    $usuarioCliente = post_str('usuario_cliente', 150);
    $diagnostico    = post_str('diagnostico', 2000);
    $ordenServicio  = post_str('orden_servicio', 60);
    $costoMant      = post_str('costo_mantenimiento', 20) ?: '0.00';

    $eqRow = $eqId ? consulta('SELECT * FROM equipos WHERE id = ?', [$eqId])->fetch() : false;
    if ($eqRow && in_array((int) $eqRow['programa_id'], $progs, true)) {
        $esp = json_decode((string) ($eqRow['especificaciones'] ?? '{}'), true) ?: [];
        $esp['estado_visita']       = $estadoVisita;
        $esp['fecha_visita']        = $fechaVisita;
        $esp['estado_servicio']     = $estadoServicio;
        $esp['proxima_visita']      = $proximaVisita;
        $esp['tecnico_responsable'] = $tecnicoNombre;
        if ($oficinaCliente !== '') $esp['oficina_acta'] = $oficinaCliente;
        if ($usuarioCliente !== '') $esp['responsable_acta'] = $usuarioCliente;
        if ($diagnostico !== '')    $esp['diagnostico'] = $diagnostico;
        if ($ordenServicio !== '')  $esp['orden_servicio'] = $ordenServicio;
        $esp['costo_mantenimiento'] = $costoMant;

        // Guardar también en historial de intervenciones dentro del JSON para la Ficha de Scribd
        $intervenciones = $esp['intervenciones_ficha'] ?? [];
        if ($diagnostico !== '') {
            array_unshift($intervenciones, [
                'fecha'       => $fechaVisita,
                'tipo'        => 'PREVENTIVO',
                'orden'       => $ordenServicio ?: ('OM-' . date('Y') . '-' . str_pad((string) $eqId, 4, '0', STR_PAD_LEFT)),
                'actividad'   => $diagnostico,
                'servicio'    => strtoupper($estadoServicio),
                'proxima'     => $proximaVisita,
                'empresa'     => $tecnicoNombre,
                'costo'       => $costoMant,
            ]);
            $esp['intervenciones_ficha'] = array_slice($intervenciones, 0, 20);
        }

        $nuevoEstadoEq = ($estadoServicio === 'completo') ? 'operativo' : 'en_mantenimiento';
        $ultMant = ($estadoServicio === 'completo') ? $fechaVisita : ($eqRow['ultimo_mantenimiento'] ?? null);

        consulta(
            'UPDATE equipos SET estado = ?, ultimo_mantenimiento = ?, fecha_limite_mantenimiento = ?, responsable_bien = ?, especificaciones = ? WHERE id = ?',
            [$nuevoEstadoEq, $ultMant, $proximaVisita, $usuarioCliente ?: $eqRow['responsable_bien'], json_encode($esp, JSON_UNESCAPED_UNICODE), $eqId]
        );

        // Actualizar o crear el cronograma preventivo para que el Coordinador lo vea automáticamente
        $planId = consulta('SELECT id FROM planes_mantenimiento_preventivo WHERE equipo_id = ? LIMIT 1', [$eqId])->fetchColumn();
        if (!$planId) {
            consulta(
                "INSERT INTO planes_mantenimiento_preventivo (programa_id, ambiente_id, equipo_id, nombre, descripcion, frecuencia, tecnico_responsable_id, fecha_inicio, activo, creado_por)
                 VALUES (?,?,?,?,?,'trimestral',?,?,1,?)",
                [$eqRow['programa_id'], $eqRow['ambiente_id'], $eqId, 'Plan Preventivo - ' . $eqRow['nombre'], $diagnostico ?: 'Verificación programada', $u['id'], $fechaVisita, $u['id']]
            );
            $planId = (int) db()->lastInsertId();
        }
        $estProg = ($estadoServicio === 'completo') ? 'realizado' : 'programado';
        consulta(
            'INSERT INTO programaciones_mantenimiento (plan_id, fecha_programada, tecnico_id, estado, fin_real, resultado, proxima_fecha)
             VALUES (?,?,?,?,?,?,?)',
            [$planId, $proximaVisita, $u['id'], $estProg, $estadoServicio === 'completo' ? date('Y-m-d H:i:s') : null, $diagnostico ?: 'Actualización desde Ventana de Mantenimiento', $proximaVisita]
        );

        auditar('editar', 'equipos', $eqId, ['estado_servicio' => $estadoServicio, 'proxima_visita' => $proximaVisita]);
        flash('success', 'Reporte de mantenimiento, visita técnica y cronograma actualizados correctamente.');
    }
    redirigir('admin/buscar_equipo.php?id=' . (int) $eqId . '&q=' . urlencode((string) ($_POST['q_actual'] ?? '')));
}

$q = trim((string) ($_GET['q'] ?? ''));
$idSel = get_int('id');

[$fProg, $pProg] = filtro_programas('programa_id', $progs);

$seleccionado = null;
if ($idSel) {
    $seleccionado = consulta("SELECT * FROM v_equipo_busqueda WHERE id = ? AND $fProg", [$idSel, ...$pProg])->fetch();
}

$resultados = [];
if ($q !== '') {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $numQ = ltrim($q, '0');
    if ($numQ !== '' && ctype_digit($numQ)) {
        // Si busca "001" o "1", prioriza coincidencia por correlativo / orden o códigos terminados en 01
        $resultados = consulta(
            "SELECT * FROM v_equipo_busqueda
              WHERE $fProg AND (numero_orden = ? OR id = ? OR codigo_inventario LIKE ? OR codigo_interno LIKE ? OR numero_serie LIKE ?)
              ORDER BY (id = ?) DESC, (numero_orden = ?) DESC, requiere_mantenimiento DESC, codigo_inventario
              LIMIT 40",
            [...$pProg, (int) $numQ, (int) $numQ, $like, $like, $like, (int) $numQ, (int) $numQ]
        )->fetchAll();
    } else {
        $resultados = consulta(
            "SELECT * FROM v_equipo_busqueda
              WHERE $fProg AND (codigo_inventario LIKE ? OR codigo_patrimonial LIKE ? OR codigo_interno LIKE ? OR numero_serie LIKE ? OR nombre LIKE ? OR ambiente_nombre LIKE ?)
              ORDER BY (codigo_interno = ?) DESC, (numero_serie = ?) DESC, requiere_mantenimiento DESC, codigo_inventario
              LIMIT 40",
            [...$pProg, $like, $like, $like, $like, $like, $like, strtoupper($q), strtoupper($q)]
        )->fetchAll();
    }
    if (!$seleccionado && count($resultados) > 0) {
        $seleccionado = $resultados[0];
    }
}

cabecera('Módulo 2 · Mantenimiento y Buscador por Código o Serie', $u);
?>

<div class="row justify-content-center mb-4">
  <div class="col-lg-10">
    <div class="card shadow-sm border-0 bg-white">
      <div class="card-body p-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
          <h1 class="h4 fw-bold mb-0 text-primary">
            <i class="bi bi-wrench-adjustable-circle me-2"></i>Módulo 2: Mantenimiento y Buscador por Código o Serie
          </h1>
          <span class="badge bg-light text-dark border">Consulta inmediata de Estado de Visita, Servicio, Cliente y Diagnóstico</span>
        </div>
        <p class="text-muted small mb-3">
          Ingrese el <strong>Código Correlativo</strong> (ej. <code>001</code>), <strong>Código de Inventario</strong> (ej. <code>LC1-CPU01</code>, <code>DG-LAPO1</code>, <code>AAR-IMP01</code>, <code>AA-IMP</code>) o <strong>Número de Serie</strong> (ej. <code>PF3AQQX0</code>, <code>W9AY160949</code>, <code>XAH3354926</code>) para abrir su ventana de reporte técnico y generar su Ficha de Mantenimiento.
        </p>
        <form method="get">
          <div class="input-group input-group-lg shadow-sm">
            <span class="input-group-text bg-white border-end-0"><i class="bi bi-upc-scan text-primary"></i></span>
            <input class="form-control border-start-0" name="q" value="<?= e($q) ?>" placeholder="Buscar por Código (001, LC1-CPU01, DG-LAPO1, AAR-IMP01) o N.º de Serie (PF3AQQX0)…" autofocus>
            <button class="btn btn-primary px-4 fw-semibold" type="submit"><i class="bi bi-search me-1"></i>Buscar Equipo</button>
          </div>
        </form>
        <div class="mt-2 small d-flex flex-wrap gap-2 align-items-center">
          <span class="text-muted">Ejemplos rápidos del Excel:</span>
          <a href="<?= e(url('admin/buscar_equipo.php?q=001')) ?>" class="badge bg-light text-primary border text-decoration-none">Código: 001</a>
          <a href="<?= e(url('admin/buscar_equipo.php?q=LC1-CPU01')) ?>" class="badge bg-light text-primary border text-decoration-none">Lab. Cómputo I: LC1-CPU01</a>
          <a href="<?= e(url('admin/buscar_equipo.php?q=DG-LAPO1')) ?>" class="badge bg-light text-primary border text-decoration-none">Laptop Dirección: DG-LAPO1 (Serie PF3AQQX0)</a>
          <a href="<?= e(url('admin/buscar_equipo.php?q=AAR-IMP01')) ?>" class="badge bg-light text-danger border text-decoration-none">Impresora con falla APSTI: AAR-IMP01</a>
          <a href="<?= e(url('admin/buscar_equipo.php?q=DG-IMPR01')) ?>" class="badge bg-light text-success border text-decoration-none">Impresora Dirección: DG-IMPR01</a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if ($seleccionado): ?>
  <?php
    $esp = json_decode((string) ($seleccionado['especificaciones'] ?? '{}'), true) ?: [];
    $corr = str_pad((string) ($seleccionado['numero_orden'] ?: $seleccionado['id']), 3, '0', STR_PAD_LEFT);
    $estVisita   = $esp['estado_visita'] ?? ($seleccionado['requiere_mantenimiento'] ? 'programada' : 'no_requiere');
    $fechaVisita = $esp['fecha_visita'] ?? ($seleccionado['proximo_preventivo'] ?? $seleccionado['fecha_limite_mantenimiento']);
    $estServicio = $esp['estado_servicio'] ?? ($seleccionado['requiere_mantenimiento'] ? 'pendiente' : 'no_aplica');
    $proxVisita  = $seleccionado['proximo_preventivo'] ?? ($seleccionado['fecha_limite_mantenimiento'] ?? ($esp['proxima_visita'] ?? null));
    $tecnicoResp = $esp['tecnico_responsable'] ?? 'Ing. Carlos Alberto Quispe Huamán (Soporte Técnico)';
    $oficinaCli  = $seleccionado['ambiente_nombre'] ?: ($esp['oficina_acta'] ?? 'Sin oficina asignada');
    $usuarioCli  = $seleccionado['responsable_bien'] ?: ($esp['responsable_acta'] ?? 'ERNESTO CORREA BARRANTES');
    $dniCli      = $seleccionado['dni_responsable'] ?: ($esp['dni_responsable'] ?? '27722958');
    $diagTexto   = $seleccionado['ultimo_diagnostico'] ?: ($esp['diagnostico'] ?? 'Equipo operativo en inventario institucional. Sin fallas críticas pendientes.');
  ?>
  <!-- VENTANA DE REPORTE DEL CÓDIGO / BIEN SELECCIONADO -->
  <div class="card shadow border-primary border-2 mb-4">
    <div class="card-header bg-primary text-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div>
        <span class="badge bg-light text-primary fw-bold me-2">CÓDIGO #<?= e($corr) ?> · <?= e($seleccionado['codigo_interno'] ?: $seleccionado['codigo_inventario']) ?></span>
        <strong class="fs-5 align-middle"><?= e($seleccionado['nombre']) ?></strong>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-sm btn-warning fw-bold text-dark" data-bs-toggle="modal" data-bs-target="#modalActualizarMant">
          <i class="bi bi-pencil-square me-1"></i>Registrar / Actualizar Visita y Diagnóstico
        </button>
        <a class="btn btn-sm btn-light fw-bold text-primary" href="<?= e(url('admin/ficha_mantenimiento.php?id=' . (int) $seleccionado['id'])) ?>">
          <i class="bi bi-file-earmark-medical me-1"></i>Generar Ficha de Mantenimiento (Imprimir/PDF)
        </a>
        <a class="btn btn-sm btn-danger fw-bold" href="<?= e(url('docente/reportar.php?equipo_id=' . (int) $seleccionado['id'])) ?>">
          <i class="bi bi-exclamation-triangle me-1"></i>Reportar Falla
        </a>
      </div>
    </div>

    <div class="card-body p-4">
      <div class="row g-4">
        <!-- Columna 1: Datos del Bien / Producto -->
        <div class="col-lg-4 border-end-lg">
          <h3 class="h6 fw-bold text-uppercase text-primary border-bottom pb-2 mb-3">
            <i class="bi bi-pc-display me-1"></i>1. Datos del Producto (Inventario)
          </h3>
          <dl class="row small mb-0 gy-2">
            <dt class="col-5 text-muted">Código Interno / Correl.:</dt>
            <dd class="col-7 fw-bold"><?= e($seleccionado['codigo_interno'] ?: $seleccionado['codigo_inventario']) ?> (N.º <?= e($corr) ?>)</dd>

            <dt class="col-5 text-muted">Código Patrimonial:</dt>
            <dd class="col-7"><?= e($seleccionado['codigo_patrimonial'] ?: 'Sin código SBN') ?></dd>

            <dt class="col-5 text-muted">N.º de Serie:</dt>
            <dd class="col-7"><span class="badge bg-dark"><?= e($seleccionado['numero_serie'] ?: 'S/N') ?></span></dd>

            <dt class="col-5 text-muted">Nombre / Denominación:</dt>
            <dd class="col-7 fw-semibold"><?= e($seleccionado['nombre']) ?></dd>

            <dt class="col-5 text-muted">Descripción:</dt>
            <dd class="col-7"><?= e($seleccionado['descripcion'] ?: '—') ?></dd>

            <dt class="col-5 text-muted">Marca / Modelo:</dt>
            <dd class="col-7"><?= e(trim(($seleccionado['marca'] ?? '') . ' / ' . ($seleccionado['modelo'] ?? ''), ' /') ?: '—') ?></dd>

            <dt class="col-5 text-muted">Color / Dimensiones:</dt>
            <dd class="col-7"><?= e(trim(($seleccionado['color'] ?? '') . ' · ' . ($seleccionado['dimensiones'] ?? ''), ' ·') ?: '—') ?></dd>

            <dt class="col-5 text-muted">Fecha de Compra:</dt>
            <dd class="col-7 fw-semibold"><?= $seleccionado['fecha_adquisicion'] ? e(date('d/m/Y', strtotime((string) $seleccionado['fecha_adquisicion']))) : '—' ?></dd>

            <dt class="col-5 text-muted">Estado Operativo / Cons.:</dt>
            <dd class="col-7"><?= insignia_equipo($seleccionado['estado']) ?> <?= insignia_conservacion($seleccionado['estado_conservacion']) ?></dd>
          </dl>
        </div>

        <!-- Columna 2: Datos del Cliente (Oficina, Programa, Usuario) -->
        <div class="col-lg-4 border-end-lg">
          <h3 class="h6 fw-bold text-uppercase text-primary border-bottom pb-2 mb-3">
            <i class="bi bi-building me-1"></i>2. Datos del Cliente / Ubicación
          </h3>
          <div class="p-3 bg-light rounded-3 mb-3">
            <div class="small text-muted">Programa de Estudio / Área:</div>
            <div class="fw-bold text-dark fs-6 mb-2">
              <span class="badge bg-primary me-1"><?= e($seleccionado['programa']) ?></span>
              <?= e($seleccionado['programa_nombre']) ?>
            </div>

            <div class="small text-muted">Oficina / Ambiente / Laboratorio:</div>
            <div class="fw-bold text-primary fs-6 mb-2">
              <i class="bi bi-geo-alt-fill me-1"></i><?= e($oficinaCli) ?>
            </div>

            <div class="small text-muted">Usuario / Responsable Asignado (Cliente):</div>
            <div class="fw-semibold text-dark">
              <i class="bi bi-person-badge me-1"></i><?= e($usuarioCli) ?>
              <span class="text-muted small">(DNI: <?= e($dniCli) ?>)</span>
            </div>
          </div>

          <h3 class="h6 fw-bold text-uppercase text-primary border-bottom pb-2 mb-2">
            <i class="bi bi-clipboard2-pulse me-1"></i>Diagnóstico Técnico
          </h3>
          <div class="p-3 border rounded-3 bg-white small">
            <?= nl2br(e($diagTexto)) ?>
          </div>
        </div>

        <!-- Columna 3: Estado de Visita, Técnico, Servicio Completo/Pendiente y Próxima Visita -->
        <div class="col-lg-4">
          <h3 class="h6 fw-bold text-uppercase text-primary border-bottom pb-2 mb-3">
            <i class="bi bi-calendar2-check me-1"></i>3. Estado de Visita y Mantenimiento
          </h3>
          <ul class="list-group list-group-flush small">
            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
              <span class="text-muted fw-semibold">Estado de Visita:</span>
              <?= insignia_visita($estVisita) ?>
            </li>
            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
              <span class="text-muted fw-semibold">Fecha de Visita Técnica:</span>
              <span class="fw-bold text-dark">
                <i class="bi bi-calendar-event me-1 text-primary"></i>
                <?= $fechaVisita ? e(date('d/m/Y', strtotime((string) $fechaVisita))) : 'Por programar' ?>
              </span>
            </li>
            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
              <span class="text-muted fw-semibold">Encargado del Mantenimiento:</span>
              <span class="fw-semibold text-end"><?= e($tecnicoResp) ?></span>
            </li>
            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
              <span class="text-muted fw-semibold">Estado del Servicio:</span>
              <?= insignia_servicio($estServicio) ?>
            </li>
            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
              <span class="text-muted fw-semibold">Último Mantenimiento:</span>
              <span><?= $seleccionado['ultimo_preventivo'] ? e(date('d/m/Y', strtotime((string) $seleccionado['ultimo_preventivo']))) : '—' ?></span>
            </li>
            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
              <span class="text-muted fw-semibold">Próxima Visita / Fecha Límite:</span>
              <?= semaforo_mantenimiento($proxVisita, (int) $seleccionado['requiere_mantenimiento']) ?>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal para actualizar Estado de Visita, Fecha de Visita, Servicio Completo/Pendiente, Diagnóstico y Próxima Visita -->
  <div class="modal fade" id="modalActualizarMant" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <form method="post" class="modal-content">
        <?= csrf_campo() ?>
        <input type="hidden" name="equipo_id" value="<?= (int) $seleccionado['id'] ?>">
        <input type="hidden" name="q_actual" value="<?= e($q) ?>">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title fw-bold">Actualizar Mantenimiento y Visita — <?= e($seleccionado['codigo_interno'] ?: $seleccionado['codigo_inventario']) ?></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label fw-semibold">Estado de Visita</label>
              <select class="form-select" name="estado_visita">
                <option value="programada" <?= $estVisita === 'programada' ? 'selected' : '' ?>>Visita Programada</option>
                <option value="en_visita" <?= $estVisita === 'en_visita' ? 'selected' : '' ?>>En Visita Técnica</option>
                <option value="visitado" <?= $estVisita === 'visitado' ? 'selected' : '' ?>>Visitado / Verificado</option>
                <option value="pendiente_visita" <?= $estVisita === 'pendiente_visita' ? 'selected' : '' ?>>Pendiente de Visita</option>
                <option value="reprogramada" <?= $estVisita === 'reprogramada' ? 'selected' : '' ?>>Reprogramada</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Fecha de Visita (Cuándo viene el técnico)</label>
              <input type="date" class="form-control" name="fecha_visita" value="<?= e($fechaVisita ?: date('Y-m-d')) ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Estado del Servicio</label>
              <select class="form-select" name="estado_servicio">
                <option value="completo" <?= $estServicio === 'completo' ? 'selected' : '' ?>>SERVICIO COMPLETO</option>
                <option value="pendiente" <?= $estServicio === 'pendiente' ? 'selected' : '' ?>>SERVICIO PENDIENTE</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Técnico / Empresa que da el Mantenimiento</label>
              <input class="form-control" name="tecnico_responsable" value="<?= e($tecnicoResp) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold text-danger">Próxima Visita / Nueva Fecha Límite</label>
              <input type="date" class="form-control border-danger" name="proxima_visita" value="<?= e($proxVisita ?: date('Y-m-d', strtotime('+90 days'))) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Oficina del Cliente</label>
              <input class="form-control" name="oficina_cliente" value="<?= e($oficinaCli) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Usuario / Responsable del Área</label>
              <input class="form-control" name="usuario_cliente" value="<?= e($usuarioCli) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">N.º Orden de Servicio / Compra</label>
              <input class="form-control" name="orden_servicio" value="<?= e($esp['orden_servicio'] ?? ('OM-2026-' . str_pad((string) $seleccionado['id'], 4, '0', STR_PAD_LEFT))) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Costo (S/)</label>
              <input class="form-control" name="costo_mantenimiento" value="<?= e($esp['costo_mantenimiento'] ?? '0.00') ?>">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Diagnóstico Técnico y Actividades Realizadas</label>
              <textarea class="form-control" name="diagnostico" rows="3" required><?= e($diagTexto) ?></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Guardar y Actualizar Cronograma</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>

<?php if ($q !== ''): ?>
  <h2 class="h6 fw-bold text-muted mb-3"><?= count($resultados) ?> coincidencia(s) encontrada(s) para «<?= e($q) ?>»</h2>
  <div class="row g-3">
  <?php foreach ($resultados as $r): ?>
    <?php
      $espR = json_decode((string) ($r['especificaciones'] ?? '{}'), true) ?: [];
      $corrR = str_pad((string) ($r['numero_orden'] ?: $r['id']), 3, '0', STR_PAD_LEFT);
      $activoCard = ($seleccionado && (int) $seleccionado['id'] === (int) $r['id']);
    ?>
    <div class="col-md-6 col-xl-4">
      <div class="card h-100 shadow-sm border-0 border-start border-4 <?= $activoCard ? 'border-success bg-light' : 'border-primary' ?>">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <div>
              <span class="badge bg-dark me-1">#<?= e($corrR) ?></span>
              <span class="fw-bold text-primary"><?= e($r['codigo_interno'] ?: $r['codigo_inventario']) ?></span>
            </div>
            <?= insignia_equipo((string) ($r['estado'] ?? 'operativo')) ?>
          </div>
          <h3 class="h6 fw-bold mb-1"><?= e($r['nombre']) ?></h3>
          <div class="small text-muted mb-2">
            <strong>Marca/Mod:</strong> <?= e(trim(($r['marca'] ?? '') . ' ' . ($r['modelo'] ?? '')) ?: '—') ?> ·
            <strong>Serie:</strong> <?= e($r['numero_serie'] ?: 'S/N') ?>
          </div>
          <div class="small mb-2">
            <div><i class="bi bi-building me-1 text-primary"></i><strong>Oficina:</strong> <?= e($r['ambiente_nombre'] ?? '—') ?> (<?= e($r['programa']) ?>)</div>
            <div><i class="bi bi-person me-1 text-secondary"></i><strong>Usuario:</strong> <?= e($r['responsable_bien'] ?? '—') ?></div>
          </div>
          <div class="p-2 bg-white border rounded small mb-3">
            <div class="d-flex justify-content-between mb-1">
              <span>Visita: <?= insignia_visita($espR['estado_visita'] ?? 'programada') ?></span>
              <?= insignia_servicio($espR['estado_servicio'] ?? 'pendiente') ?>
            </div>
            <div class="mt-1"><strong>Próx. Visita:</strong> <?= semaforo_mantenimiento($r['proximo_preventivo'] ?? $r['fecha_limite_mantenimiento'], (int) $r['requiere_mantenimiento']) ?></div>
          </div>
          <div class="d-flex gap-2">
            <a class="btn btn-sm btn-primary flex-grow-1" href="<?= e(url('admin/buscar_equipo.php?id=' . (int) $r['id'] . '&q=' . urlencode($q))) ?>">
              <i class="bi bi-eye me-1"></i>Ver Ventana Reporte
            </a>
            <a class="btn btn-sm btn-outline-dark" href="<?= e(url('admin/ficha_mantenimiento.php?id=' . (int) $r['id'])) ?>">
              <i class="bi bi-file-earmark-text me-1"></i>Ficha
            </a>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php pie();
