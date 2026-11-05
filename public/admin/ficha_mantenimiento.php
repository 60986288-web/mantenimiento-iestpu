<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/layout.php';
require dirname(__DIR__, 2) . '/includes/catalogos.php';

$u = requiere_login();
$progs = programas_del_usuario((int) $u['id'], $u['rol']);
$id = get_int('id') ?? post_int('equipo_id');

$eq = $id ? consulta("SELECT * FROM v_equipo_busqueda WHERE id = ?", [$id])->fetch() : false;
if (!$eq || !in_array((int) $eq['programa_id'], $progs, true)) {
    http_response_code(404);
    exit('Equipo no encontrado.');
}

$esp = json_decode((string) ($eq['especificaciones'] ?? '{}'), true) ?: [];

// Permitir agregar un nuevo registro de mantenimiento a la Ficha (Preventivo o Correctivo)
if (es_post()) {
    csrf_verificar();
    $fechaRealizacion = fecha_valida_o_null(post_str('fecha_realizacion', 10)) ?: date('Y-m-d');
    $tipoMant         = en_lista($_POST['tipo_mantenimiento'] ?? null, ['PREVENTIVO', 'CORRECTIVO']) ?? 'PREVENTIVO';
    $ordenServicio    = post_str('orden_servicio', 60) ?: ('OM-' . date('Y') . '-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT));
    $actividadDesc    = post_str('descripcion_actividad', 2000);
    $nombreEmpresa    = post_str('nombre_empresa', 150) ?: ($u['nombres'] . ' ' . $u['apellidos'] . ' (Soporte IESTPU)');
    $costo            = post_str('costo', 20) ?: '0.00';
    $estadoServ       = en_lista($_POST['estado_servicio'] ?? null, ['completo', 'pendiente']) ?? 'completo';
    $proximaVisita    = fecha_valida_o_null(post_str('proxima_visita', 10)) ?: date('Y-m-d', strtotime('+90 days'));
    $periodoCalib     = post_str('periodo_calibracion', 60) ?: ($esp['periodo_calibracion'] ?? 'TRIMESTRAL');
    $codigoProyecto   = post_str('codigo_proyecto', 60) ?: ($esp['codigo_proyecto'] ?? 'MOD-741744-IESTPU');

    if ($actividadDesc !== '') {
        $intervenciones = $esp['intervenciones_ficha'] ?? [];
        array_unshift($intervenciones, [
            'fecha'     => $fechaRealizacion,
            'tipo'      => $tipoMant,
            'orden'     => $ordenServicio,
            'actividad' => $actividadDesc,
            'servicio'  => strtoupper($estadoServ),
            'proxima'   => $proximaVisita,
            'empresa'   => $nombreEmpresa,
            'costo'     => $costo,
        ]);
        $esp['intervenciones_ficha'] = array_slice($intervenciones, 0, 25);
        $esp['estado_servicio']      = $estadoServ;
        $esp['estado_visita']        = ($estadoServ === 'completo') ? 'visitado' : 'programada';
        $esp['fecha_visita']         = $fechaRealizacion;
        $esp['proxima_visita']       = $proximaVisita;
        $esp['tecnico_responsable']  = $nombreEmpresa;
        $esp['diagnostico']          = $actividadDesc;
        $esp['orden_servicio']       = $ordenServicio;
        $esp['costo_mantenimiento']  = $costo;
        $esp['periodo_calibracion']  = $periodoCalib;
        $esp['codigo_proyecto']      = $codigoProyecto;

        $nuevoEstado = ($estadoServ === 'completo') ? 'operativo' : 'en_mantenimiento';
        consulta(
            'UPDATE equipos SET estado = ?, ultimo_mantenimiento = ?, fecha_limite_mantenimiento = ?, especificaciones = ? WHERE id = ?',
            [$nuevoEstado, $fechaRealizacion, $proximaVisita, json_encode($esp, JSON_UNESCAPED_UNICODE), $id]
        );
        auditar('editar', 'equipos', $id, ['ficha_mantenimiento' => $ordenServicio]);
        flash('success', 'Registro agregado a la Ficha de Mantenimiento correctamente.');
    }
    redirigir('admin/ficha_mantenimiento.php?id=' . $id);
}

// Combinar intervenciones guardadas en JSON + Solicitudes Correctivas + Programaciones Preventivas de la BD
$filasFicha = [];
foreach (($esp['intervenciones_ficha'] ?? []) as $it) {
    $filasFicha[] = [
        'fecha'      => $it['fecha'] ?? date('Y-m-d'),
        'preventivo' => ($it['tipo'] ?? 'PREVENTIVO') === 'PREVENTIVO' ? 'X' : '',
        'correctivo' => ($it['tipo'] ?? '') === 'CORRECTIVO' ? 'X' : '',
        'orden'      => $it['orden'] ?? '—',
        'actividad'  => $it['actividad'] ?? '',
        'servicio'   => $it['servicio'] ?? 'COMPLETO',
        'proxima'    => $it['proxima'] ?? '',
        'empresa'    => $it['empresa'] ?? 'Soporte Técnico IESTPU',
        'costo'      => $it['costo'] ?? '0.00',
    ];
}

$corrDB = consulta(
    "SELECT s.codigo, DATE(s.fecha_reporte) AS fecha, s.descripcion_falla, s.resultado_final, s.estado,
            d.diagnostico, CONCAT(t.nombres,' ',t.apellidos) AS tecnico
       FROM solicitudes_mantenimiento s
       LEFT JOIN asignaciones_tecnicos a ON a.solicitud_id = s.id AND a.vigente = 1
       LEFT JOIN usuarios t ON t.id = a.tecnico_id
       LEFT JOIN diagnosticos d ON d.solicitud_id = s.id
      WHERE s.equipo_id = ? ORDER BY s.fecha_reporte DESC LIMIT 10",
    [$id]
)->fetchAll();

foreach ($corrDB as $c) {
    $act = 'Falla: ' . $c['descripcion_falla'];
    if (!empty($c['diagnostico'])) $act .= ' | Diagnóstico: ' . $c['diagnostico'];
    if (!empty($c['resultado_final'])) $act .= ' | Acción: ' . $c['resultado_final'];
    $filasFicha[] = [
        'fecha'      => $c['fecha'],
        'preventivo' => '',
        'correctivo' => 'X',
        'orden'      => $c['codigo'],
        'actividad'  => $act,
        'servicio'   => in_array($c['estado'], ['resuelta', 'cerrada'], true) ? 'COMPLETO' : 'PENDIENTE',
        'proxima'    => $eq['proximo_preventivo'] ?? $eq['fecha_limite_mantenimiento'] ?? '',
        'empresa'    => $c['tecnico'] ?: 'Área de Mantenimiento IESTPU',
        'costo'      => '0.00',
    ];
}

$prevDB = consulta(
    "SELECT pr.id, pr.fecha_programada AS fecha, pr.estado, pr.resultado, pr.proxima_fecha,
            pl.nombre AS plan, CONCAT(t.nombres,' ',t.apellidos) AS tecnico
       FROM programaciones_mantenimiento pr
       JOIN planes_mantenimiento_preventivo pl ON pl.id = pr.plan_id
       LEFT JOIN usuarios t ON t.id = pr.tecnico_id
      WHERE pl.equipo_id = ? ORDER BY pr.fecha_programada DESC LIMIT 10",
    [$id]
)->fetchAll();

foreach ($prevDB as $p) {
    $filasFicha[] = [
        'fecha'      => $p['fecha'],
        'preventivo' => 'X',
        'correctivo' => '',
        'orden'      => 'PM-2026-' . str_pad((string) $p['id'], 4, '0', STR_PAD_LEFT),
        'actividad'  => $p['plan'] . ($p['resultado'] ? ' — ' . $p['resultado'] : ''),
        'servicio'   => ($p['estado'] === 'realizado') ? 'COMPLETO' : 'PENDIENTE',
        'proxima'    => $p['proxima_fecha'] ?: ($eq['fecha_limite_mantenimiento'] ?? ''),
        'empresa'    => $p['tecnico'] ?: 'Soporte Técnico IESTPU',
        'costo'      => '0.00',
    ];
}

$corrNum = str_pad((string) ($eq['numero_orden'] ?: $eq['id']), 3, '0', STR_PAD_LEFT);
$estConservMap = ['N' => 'NUEVO (N)', 'MB' => 'MUY BUENO (MB)', 'B' => 'BUENO (B)', 'R' => 'REGULAR (R)', 'M' => 'MALO (M)', 'MM' => 'MUY MALO (MM)'];

cabecera('Ficha de Registro de Mantenimiento de Equipo', $u);
?>

<style>
.ficha-scribd {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 11.5px;
    color: #000;
    background: #fff;
    padding: 24px;
    border: 1px solid #dee2e6;
}
.tabla-scribd {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 12px;
}
.tabla-scribd th, .tabla-scribd td {
    border: 1.5px solid #000;
    padding: 5px 7px;
    vertical-align: middle;
}
.tabla-scribd .encabezado-bloque {
    background-color: #dbe4f0;
    text-align: center;
    font-weight: bold;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}
.tabla-scribd .celda-label {
    background-color: #f1f5f9;
    font-weight: bold;
    font-size: 10.5px;
    text-transform: uppercase;
    width: 16%;
}
.tabla-scribd .celda-valor {
    font-size: 11.5px;
}
.linea-firma {
    border-top: 1.5px solid #000;
    width: 75%;
    margin: 45px auto 4px auto;
    padding-top: 4px;
    text-align: center;
    font-weight: bold;
}
@media print {
    body { background: #fff !important; }
    .sidebar, nav.navbar, .no-print { display: none !important; }
    .flex-grow-1, main.container-fluid { margin: 0 !important; padding: 0 !important; }
    .ficha-scribd { border: none !important; padding: 0 !important; }
}
</style>

<!-- Barra superior de acciones (no se imprime) -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2 no-print">
  <div>
    <h1 class="h4 fw-bold text-dark mb-0">
      <i class="bi bi-file-earmark-medical text-primary me-1"></i>
      Ficha de Registro de Mantenimiento de Equipo (Adecuada al Excel IESTPU y Formato Estándar)
    </h1>
    <div class="small text-muted">Jala automáticamente los datos del Inventario General de Bienes 2024 y las órdenes de mantenimiento preventivo/correctivo</div>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#collapseAgregarOrden">
      <i class="bi bi-plus-circle me-1"></i>Registrar Actividad en la Ficha
    </button>
    <button onclick="window.print()" class="btn btn-danger">
      <i class="bi bi-printer me-1"></i>Imprimir / Guardar PDF
    </button>
    <a href="<?= e(url('admin/buscar_equipo.php?id=' . (int) $eq['id'])) ?>" class="btn btn-outline-secondary">Volver al Buscador</a>
  </div>
</div>

<!-- Formulario desplegable para alimentar la ficha antes de imprimir (no se imprime) -->
<div class="collapse mb-4 no-print" id="collapseAgregarOrden">
  <div class="card border-primary shadow-sm">
    <div class="card-header bg-primary text-white fw-bold">
      Agregar Orden / Actividad de Mantenimiento a esta Ficha
    </div>
    <div class="card-body">
      <form method="post" class="row g-3">
        <?= csrf_campo() ?>
        <input type="hidden" name="equipo_id" value="<?= (int) $eq['id'] ?>">
        <div class="col-md-3">
          <label class="form-label small fw-bold">Fecha de Realización</label>
          <input type="date" name="fecha_realizacion" class="form-control form-control-sm" value="<?= e(date('Y-m-d')) ?>" required>
        </div>
        <div class="col-md-3">
          <label class="form-label small fw-bold">Tipo de Mantenimiento</label>
          <select name="tipo_mantenimiento" class="form-select form-select-sm">
            <option value="PREVENTIVO">MANTENIMIENTO PREVENTIVO</option>
            <option value="CORRECTIVO">MANTENIMIENTO CORRECTIVO</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label small fw-bold">Orden de Compra / Servicio</label>
          <input type="text" name="orden_servicio" class="form-control form-control-sm" value="<?= e('OM-' . date('Y') . '-' . str_pad((string) $eq['id'], 4, '0', STR_PAD_LEFT)) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label small fw-bold">Estado del Servicio</label>
          <select name="estado_servicio" class="form-select form-select-sm">
            <option value="completo">SERVICIO COMPLETO</option>
            <option value="pendiente">SERVICIO PENDIENTE</option>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label small fw-bold">Descripción de la Actividad y Diagnóstico Técnico</label>
          <input type="text" name="descripcion_actividad" class="form-control form-control-sm" placeholder="Ej. Limpieza interna, mantenimiento de cabezales, diagnóstico operativo conforme…" required>
        </div>
        <div class="col-md-3">
          <label class="form-label small fw-bold">Nombre Empresa / Técnico Responsable</label>
          <input type="text" name="nombre_empresa" class="form-control form-control-sm" value="<?= e($u['nombres'] . ' ' . $u['apellidos'] . ' (Soporte IESTPU)') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label small fw-bold">Costo (S/) y Próxima Visita</label>
          <div class="input-group input-group-sm">
            <span class="input-group-text">S/</span>
            <input type="text" name="costo" class="form-control" value="0.00" style="max-width: 80px;">
            <input type="date" name="proxima_visita" class="form-control" value="<?= e(date('Y-m-d', strtotime('+90 days'))) ?>">
          </div>
        </div>
        <div class="col-12 text-end">
          <button type="submit" class="btn btn-sm btn-success px-4"><i class="bi bi-check-lg me-1"></i>Guardar en la Ficha</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- FORMATO OFICIAL DE IMPRESIÓN: FICHA DE REGISTRO DE MANTENIMIENTO DE EQUIPO -->
<div class="ficha-scribd shadow-sm">
  <!-- Cabecera Institucional del Excel del IESTP Utcubamba -->
  <table class="tabla-scribd" style="margin-bottom: 10px;">
    <tr>
      <td style="width: 15%; text-align: center;">
        <img src="<?= e(url('assets/img/logo-iestp.png')) ?>" alt="Logo IESTPU" style="width: 65px; height: 65px; object-fit: contain;">
      </td>
      <td style="width: 60%; text-align: center;">
        <div style="font-size: 10px; font-weight: bold;">GOBIERNO REGIONAL AMAZONAS · DIRECCIÓN REGIONAL DE EDUCACIÓN AMAZONAS</div>
        <div style="font-size: 13px; font-weight: bold; margin: 2px 0;">INSTITUTO DE EDUCACIÓN SUPERIOR TECNOLÓGICO PÚBLICO "UTCUBAMBA"</div>
        <div style="font-size: 15px; font-weight: bold; margin-top: 4px; text-decoration: underline;">FICHA DE REGISTRO DE MANTENIMIENTO DE EQUIPO</div>
      </td>
      <td style="width: 25%; font-size: 10.5px;">
        <div><strong>CÓDIGO MODULAR:</strong> 741744</div>
        <div><strong>FICHA N.º:</strong> FM-<?= e($corrNum) ?>-<?= e(date('Y')) ?></div>
        <div><strong>FECHA EMISIÓN:</strong> <?= e(date('d/m/Y')) ?></div>
        <div><strong>LUGAR:</strong> BAGUA GRANDE - UTCUBAMBA</div>
      </td>
    </tr>
  </table>

  <!-- 1. DATOS DEL EQUIPO Y UBICACIÓN (Campos de la Ficha de Scribd + Columnas del Excel IESTPU) -->
  <table class="tabla-scribd">
    <tr>
      <th colspan="6" class="encabezado-bloque">1. IDENTIFICACIÓN DEL EQUIPO Y REGISTRO PATRIMONIAL (INVENTARIO IESTPU)</th>
    </tr>
    <tr>
      <td class="celda-label">NOMBRE DEL EQUIPO</td>
      <td class="celda-valor fw-bold" colspan="2"><?= e($eq['nombre']) ?></td>
      <td class="celda-label">TIPO DE EQUIPO</td>
      <td class="celda-valor" colspan="2"><?= e($eq['categoria_nombre'] . ($eq['tipo_fisico'] ? ' (' . $eq['tipo_fisico'] . ')' : '')) ?></td>
    </tr>
    <tr>
      <td class="celda-label">ÁREA / PROGRAMA</td>
      <td class="celda-valor" colspan="2"><strong><?= e($eq['programa']) ?></strong> — <?= e($eq['programa_nombre']) ?></td>
      <td class="celda-label">UBICACIÓN / OFICINA</td>
      <td class="celda-valor" colspan="2"><?= e($eq['ambiente_nombre'] ?? ($esp['oficina_acta'] ?? '—')) ?></td>
    </tr>
    <tr>
      <td class="celda-label">MARCA</td>
      <td class="celda-valor"><?= e($eq['marca'] ?: 'S/M') ?></td>
      <td class="celda-label">MODELO</td>
      <td class="celda-valor"><?= e($eq['modelo'] ?: 'S/M') ?></td>
      <td class="celda-label">SERIE</td>
      <td class="celda-valor fw-bold"><?= e($eq['numero_serie'] ?: 'S/N') ?></td>
    </tr>
    <tr>
      <td class="celda-label">CÓDIGO INVENTARIO</td>
      <td class="celda-valor fw-bold"><?= e($eq['codigo_interno'] ?: $eq['codigo_inventario']) ?> (N.º <?= e($corrNum) ?>)</td>
      <td class="celda-label">CÓDIGO PATRIMONIAL</td>
      <td class="celda-valor"><?= e($eq['codigo_patrimonial'] ?: '—') ?></td>
      <td class="celda-label">CÓDIGO PROYECTO</td>
      <td class="celda-valor"><?= e($esp['codigo_proyecto'] ?? 'MOD-741744-IESTPU') ?></td>
    </tr>
    <tr>
      <td class="celda-label">FECHA DE COMPRA</td>
      <td class="celda-valor"><?= $eq['fecha_adquisicion'] ? e(date('d/m/Y', strtotime((string) $eq['fecha_adquisicion']))) : '—' ?></td>
      <td class="celda-label">COLOR / MEDIDAS</td>
      <td class="celda-valor"><?= e(trim(($eq['color'] ?? '') . ' / ' . ($eq['dimensiones'] ?? ''), ' /') ?: '—') ?></td>
      <td class="celda-label">CONSERVACIÓN (EXCEL)</td>
      <td class="celda-valor"><?= e($estConservMap[$eq['estado_conservacion'] ?? 'B'] ?? 'BUENO (B)') ?> · Uso (U)</td>
    </tr>
  </table>

  <!-- 2. DATOS DEL CLIENTE, VISITA TÉCNICA Y FECHAS LÍMITE -->
  <table class="tabla-scribd">
    <tr>
      <th colspan="6" class="encabezado-bloque">2. DATOS DEL CLIENTE (RESPONSABLE DE ÁREA), DIAGNÓSTICO Y PROGRAMACIÓN DE VISITA</th>
    </tr>
    <tr>
      <td class="celda-label">CLIENTE / USUARIO</td>
      <td class="celda-valor" colspan="2"><?= e($eq['responsable_bien'] ?: ($esp['responsable_acta'] ?? 'ERNESTO CORREA BARRANTES')) ?> (DNI: <?= e($eq['dni_responsable'] ?: '27722958') ?>)</td>
      <td class="celda-label">FECHA SOLICITUD MANT.</td>
      <td class="celda-valor" colspan="2"><?= e(isset($corrDB[0]['fecha']) ? date('d/m/Y', strtotime((string) $corrDB[0]['fecha'])) : ($eq['ultimo_preventivo'] ? date('d/m/Y', strtotime((string) $eq['ultimo_preventivo'])) : date('d/m/Y'))) ?></td>
    </tr>
    <tr>
      <td class="celda-label">PERIODO CALIBRACIÓN / FREC.</td>
      <td class="celda-valor"><?= e(strtoupper((string) ($eq['frecuencia_mantenimiento'] ?? 'TRIMESTRAL'))) ?></td>
      <td class="celda-label">ESTADO DE VISITA</td>
      <td class="celda-valor fw-bold"><?= e(strtoupper(str_replace('_', ' ', (string) ($esp['estado_visita'] ?? 'PROGRAMADA')))) ?> (<?= !empty($esp['fecha_visita']) ? e(date('d/m/Y', strtotime((string) $esp['fecha_visita']))) : '—' ?>)</td>
      <td class="celda-label">ESTADO DEL SERVICIO</td>
      <td class="celda-valor fw-bold"><?= e(strtoupper((string) ($esp['estado_servicio'] ?? 'PENDIENTE'))) ?></td>
    </tr>
    <tr>
      <td class="celda-label">ÚLTIMO MANTENIMIENTO</td>
      <td class="celda-valor"><?= $eq['ultimo_preventivo'] ? e(date('d/m/Y', strtotime((string) $eq['ultimo_preventivo']))) : '—' ?></td>
      <td class="celda-label">PRÓXIMA VISITA (FECHA LÍMITE)</td>
      <td class="celda-valor fw-bold text-danger"><?= ($eq['proximo_preventivo'] ?: $eq['fecha_limite_mantenimiento']) ? e(date('d/m/Y', strtotime((string) ($eq['proximo_preventivo'] ?: $eq['fecha_limite_mantenimiento'])))) : '—' ?></td>
      <td class="celda-label">TÉCNICO ASIGNADO</td>
      <td class="celda-valor"><?= e($esp['tecnico_responsable'] ?? 'Soporte Técnico IESTPU') ?></td>
    </tr>
    <tr>
      <td class="celda-label">DIAGNÓSTICO TÉCNICO</td>
      <td class="celda-valor" colspan="5"><?= e($eq['ultimo_diagnostico'] ?: ($esp['diagnostico'] ?? 'Verificación preventiva y operativa conforme a parámetros institucionales.')) ?></td>
    </tr>
  </table>

  <!-- 3. REGISTRO DE ACTIVIDADES DE MANTENIMIENTO PREVENTIVO / CORRECTIVO (Estructura exacta de la Ficha de Scribd) -->
  <table class="tabla-scribd">
    <tr>
      <th colspan="9" class="encabezado-bloque">3. HISTORIAL Y REGISTRO DE ÓRDENES DE MANTENIMIENTO (PREVENTIVO / CORRECTIVO)</th>
    </tr>
    <tr style="background-color: #f1f5f9; font-weight: bold; text-align: center; font-size: 10px;">
      <td rowspan="2" style="width: 4%;">N.º</td>
      <td rowspan="2" style="width: 10%;">FECHA DE REALIZACIÓN<br>(DÍA/MES/AÑO)</td>
      <td colspan="2" style="width: 12%;">TIPO MANTENIMIENTO</td>
      <td rowspan="2" style="width: 11%;">ORDEN DE COMPRA / SERVICIO</td>
      <td rowspan="2" style="width: 31%;">DESCRIPCIÓN DE LA ACTIVIDAD / DIAGNÓSTICO</td>
      <td rowspan="2" style="width: 9%;">SERVICIO<br>(COMP./PEND.)</td>
      <td rowspan="2" style="width: 15%;">NOMBRE EMPRESA / TÉCNICO RESPONSABLE</td>
      <td rowspan="2" style="width: 8%;">COSTO<br>(S/)</td>
    </tr>
    <tr style="background-color: #f1f5f9; font-weight: bold; text-align: center; font-size: 9.5px;">
      <td style="width: 6%;">PREVENTIVO</td>
      <td style="width: 6%;">CORRECTIVO</td>
    </tr>
    <?php
    $numFila = 1;
    foreach ($filasFicha as $f):
    ?>
    <tr>
      <td style="text-align: center;"><?= $numFila++ ?></td>
      <td style="text-align: center;"><?= $f['fecha'] ? e(date('d/m/Y', strtotime((string) $f['fecha']))) : '' ?></td>
      <td style="text-align: center; font-weight: bold;"><?= e($f['preventivo']) ?></td>
      <td style="text-align: center; font-weight: bold;"><?= e($f['correctivo']) ?></td>
      <td style="text-align: center;"><?= e($f['orden']) ?></td>
      <td><?= e($f['actividad']) ?></td>
      <td style="text-align: center; font-weight: bold;"><?= e($f['servicio']) ?></td>
      <td><?= e($f['empresa']) ?></td>
      <td style="text-align: right;">S/ <?= e($f['costo']) ?></td>
    </tr>
    <?php endforeach; ?>

    <?php for ($i = count($filasFicha); $i < 8; $i++): ?>
    <tr style="height: 24px;">
      <td style="text-align: center;"><?= $i + 1 ?></td>
      <td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
    </tr>
    <?php endfor; ?>
  </table>

  <!-- 4. BLOQUE DE FIRMAS (Colaborador / Coordinador / Administración) -->
  <table class="tabla-scribd" style="border: none; margin-top: 15px;">
    <tr>
      <td style="border: none; width: 33%; text-align: center;">
        <div class="linea-firma">Nombre – Firma Colaborador / Técnico</div>
        <div style="font-size: 10px;"><?= e($esp['tecnico_responsable'] ?? 'Área de Mantenimiento y Soporte TI') ?></div>
      </td>
      <td style="border: none; width: 34%; text-align: center;">
        <div class="linea-firma">Conformidad Coordinador / Usuario del Área</div>
        <div style="font-size: 10px;"><?= e($eq['responsable_bien'] ?: 'Responsable de Oficina / Laboratorio') ?></div>
      </td>
      <td style="border: none; width: 33%; text-align: center;">
        <div class="linea-firma">V° B° Jefatura de Administración</div>
        <div style="font-size: 10px;">IESTP Utcubamba</div>
      </td>
    </tr>
  </table>
</div>

<?php pie();
