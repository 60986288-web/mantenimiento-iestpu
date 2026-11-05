<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/layout.php';
require dirname(__DIR__, 2) . '/includes/catalogos.php';
require dirname(__DIR__, 2) . '/includes/SimpleXLSX.php';

$u = requiere_rol('admin_general', 'admin_programa');
requiere_permiso('equipos.gestionar');

$mensajeResultado = null;

if (es_post()) {
    csrf_verificar();
    $usarStorage = isset($_POST['usar_storage']);
    $rutaArchivo = '';

    if ($usarStorage) {
        $rutaArchivo = dirname(__DIR__, 2) . '/storage/inventario.xlsx';
    } elseif (!empty($_FILES['archivo_excel']['tmp_name']) && is_uploaded_file($_FILES['archivo_excel']['tmp_name'])) {
        $rutaArchivo = $_FILES['archivo_excel']['tmp_name'];
    }

    if ($rutaArchivo && file_exists($rutaArchivo)) {
        $xlsx = \Shuchkin\SimpleXLSX::parse($rutaArchivo);
        if ($xlsx) {
            $hojas = $xlsx->sheetNames();
            $insertados = 0;
            $actualizados = 0;
            $pdo = db();
            $pdo->beginTransaction();
            try {
                // Asegurar categorías base
                $pdo->exec("INSERT IGNORE INTO categorias_equipos (id, programa_id, nombre, es_consumible) VALUES
                    (1, NULL, 'Equipos de Cómputo y Procesamiento', 0),
                    (2, NULL, 'Impresión, Escaneo y Proyección', 0),
                    (3, NULL, 'Protección Eléctrica y Climatización', 0),
                    (4, NULL, 'Equipos de Laboratorio, Salud y Audio/Video', 0),
                    (5, NULL, 'Redes y Telecomunicaciones', 0),
                    (6, NULL, 'Mobiliario, Enseres e Infraestructura', 1),
                    (7, NULL, 'Materiales, Instrumental Menor y Consumibles', 1),
                    (8, NULL, 'Varios', 1)");

                foreach ($hojas as $idx => $nombreHoja) {
                    if (stripos($nombreHoja, 'Movimiento') !== false) {
                        continue; // La hoja 0 es de traslados, no una oficina
                    }
                    $hUp = mb_strtoupper($nombreHoja, 'UTF-8');
                    $progCod = 'ADMIN';
                    if (str_contains($hUp, 'APSTI') || str_contains($hUp, 'ASPTI') || str_contains($hUp, 'COMPUTO I') || str_contains($hUp, 'CÓMPUTO I') || str_contains($hUp, 'ENSAMBLAJE')) {
                        if (str_contains($hUp, 'CONTAB')) $progCod = 'CONT';
                        else $progCod = 'APSTI';
                    } elseif (str_contains($hUp, 'CONTAB')) {
                        $progCod = 'CONT';
                    } elseif (str_contains($hUp, 'ENFERM')) {
                        $progCod = 'ENFE';
                    } elseif (str_contains($hUp, 'AGROP') || str_contains($hUp, 'BIOLOG')) {
                        $progCod = 'AGRO';
                    }

                    $progId = (int) consulta('SELECT id FROM programas WHERE codigo = ?', [$progCod])->fetchColumn();
                    if (!$progId) continue;

                    $ambId = (int) consulta('SELECT id FROM ambientes WHERE nombre = ? AND programa_id = ?', [$nombreHoja, $progId])->fetchColumn();
                    if (!$ambId) {
                        $ambId = (int) consulta('SELECT id FROM ambientes WHERE programa_id = ? AND UPPER(nombre) LIKE ? LIMIT 1', [$progId, '%' . substr($hUp, 0, 12) . '%'])->fetchColumn();
                    }
                    if (!$ambId) {
                        $codAmb = 'AMB-' . strtoupper(substr(md5($nombreHoja), 0, 5));
                        consulta('INSERT INTO ambientes (programa_id, codigo, nombre, tipo) VALUES (?,?,?,?)', [$progId, $codAmb, $nombreHoja, 'laboratorio']);
                        $ambId = (int) $pdo->lastInsertId();
                    }

                    $filas = $xlsx->rows($idx);
                    $orden = 0;
                    foreach ($filas as $rIdx => $fila) {
                        if ($rIdx < 24) continue;
                        $codPat = trim((string) ($fila[1] ?? ''));
                        $codInt = strtoupper(trim((string) ($fila[2] ?? '')));
                        $denom  = trim((string) ($fila[3] ?? ''));
                        if ($denom === '' || stripos($denom, 'NOTA') === 0 || stripos($denom, '(1)') === 0 || stripos($denom, 'DENOMINAC') !== false) continue;

                        $orden++;
                        $marca  = trim((string) ($fila[4] ?? '')) ?: null;
                        $modelo = trim((string) ($fila[5] ?? '')) ?: null;
                        $tipo   = trim((string) ($fila[6] ?? '')) ?: null;
                        $color  = trim((string) ($fila[7] ?? '')) ?: null;
                        $serie  = trim((string) ($fila[8] ?? '')) ?: null;
                        $dimen  = trim((string) ($fila[9] ?? '')) ?: null;
                        $estCon = strtoupper(trim((string) ($fila[11] ?? 'B')));
                        if (!in_array($estCon, ['N','MB','B','R','M','MM'], true)) $estCon = 'B';

                        $dUp = mb_strtoupper($denom, 'UTF-8');
                        $reqMant = 0; $catId = 6; $frec = 'no_aplica';
                        if (preg_match('/CPU|LAPTOP|COMPUTADORA|MONITOR|TECLADO|MOUSE|SERVIDOR/', $dUp)) {
                            $catId = 1; $reqMant = 1; $frec = 'trimestral';
                        } elseif (preg_match('/IMPRESORA|PROYECTOR|FOTOCOPIADORA|ESCANER|ECRAN/', $dUp)) {
                            $catId = 2; $reqMant = 1; $frec = 'trimestral';
                        } elseif (preg_match('/ESTABILIZADOR|AIRE ACONDIC|VENTILADOR|EXTINTOR|ENCERADORA/', $dUp)) {
                            $catId = 3; $reqMant = 1; $frec = 'semestral';
                        } elseif (preg_match('/MICROSCOPIO|CENTRIFUGA|AUTOCLAVE|ESTERILIZADOR|BALANZA|REFRIGERADORA|TELEVISOR|PARLANTE|CONSOLA|MICROFONO|AMPLIFICADOR/', $dUp)) {
                            $catId = 4; $reqMant = 1; $frec = 'semestral';
                        }

                        $codInv = $codInt ?: ($codPat ?: ('BIEN-' . $ambId . '-' . $orden));
                        $existe = consulta('SELECT id FROM equipos WHERE codigo_interno = ? AND ambiente_id = ? LIMIT 1', [$codInv, $ambId])->fetchColumn();
                        if ($existe) {
                            $actualizados++;
                        } else {
                            $dupInv = consulta('SELECT 1 FROM equipos WHERE codigo_inventario = ?', [$codInv])->fetchColumn();
                            if ($dupInv) {
                                $codInv = $codInv . '-' . $ambId . '-' . $orden;
                            }
                            $fechaLim = $reqMant ? date('Y-m-d', strtotime('+60 days')) : null;
                            consulta(
                                'INSERT INTO equipos (codigo_inventario, codigo_patrimonial, codigo_interno, nombre, categoria_id, programa_id, ambiente_id, marca, modelo, tipo_fisico, color, numero_serie, dimensiones, fecha_adquisicion, estado, estado_conservacion, requiere_mantenimiento, frecuencia_mantenimiento, fecha_limite_mantenimiento, numero_orden)
                                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                                [$codInv, $codPat ?: null, $codInt ?: $codInv, $denom, $catId, $progId, $ambId, $marca, $modelo, $tipo, $color, $serie, $dimen, '2024-01-18', 'operativo', $estCon, $reqMant, $frec, $fechaLim, $orden]
                            );
                            $insertados++;
                        }
                    }
                }
                $pdo->commit();
                $mensajeResultado = "Importación completada con éxito: $insertados bienes nuevos insertados y $actualizados verificados/actualizados en las 39 áreas institucionales.";
                flash('success', $mensajeResultado);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flash('danger', 'Error durante la importación: ' . $e->getMessage());
            }
        } else {
            flash('danger', 'No se pudo leer el archivo Excel: ' . \Shuchkin\SimpleXLSX::parseError());
        }
    } else {
        flash('danger', 'Seleccione un archivo válido.');
    }
    redirigir('admin/importar_inventario.php');
}

$totalActual = (int) consulta('SELECT COUNT(*) FROM equipos')->fetchColumn();
cabecera('Importar Inventario Excel / CSV', $u);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h3 fw-bold mb-0"><i class="bi bi-file-earmark-spreadsheet text-success me-2"></i>Alimentar Base de Datos desde Excel Institucional</h1>
    <div class="text-muted small">Carga masiva del Inventario General de Bienes Institucional (40 hojas de áreas, laboratorios, oficinas y aulas)</div>
  </div>
  <a class="btn btn-outline-primary" href="<?= e(url('admin/equipos.php')) ?>">Ver Inventario (<?= number_format($totalActual) ?> bienes)</a>
</div>

<div class="row g-4">
  <div class="col-md-6">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-body">
        <h2 class="h5 fw-bold text-primary mb-2">Opción 1: Sincronizar Excel Institucional Incluido (<code>storage/inventario.xlsx</code>)</h2>
        <p class="small text-muted">
          Procesa automáticamente las <strong>39 hojas de áreas/ambientes</strong> (Laboratorio de Cómputo I y II, Dirección General, Contabilidad, Enfermería Técnica, Producción Agropecuaria, APSTI, etc.), clasifica los equipos que requieren mantenimiento y asigna sus fechas límite.
        </p>
        <form method="post">
          <?= csrf_campo() ?>
          <input type="hidden" name="usar_storage" value="1">
          <button type="submit" class="btn btn-success fw-bold">
            <i class="bi bi-database-fill-up me-1"></i>Procesar / Verificar <code>storage/inventario.xlsx</code> Ahora
          </button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-md-6">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-body">
        <h2 class="h5 fw-bold text-primary mb-2">Opción 2: Subir un Nuevo Archivo Excel (<code>.xlsx</code>)</h2>
        <p class="small text-muted">
          Si el docente o el área de patrimonio le entrega un nuevo archivo Excel con el formato institucional del IESTP Utcubamba, súbalo aquí directamente.
        </p>
        <form method="post" enctype="multipart/form-data">
          <?= csrf_campo() ?>
          <div class="mb-3">
            <input type="file" name="archivo_excel" class="form-control" accept=".xlsx" required>
          </div>
          <button type="submit" class="btn btn-primary fw-bold">
            <i class="bi bi-upload me-1"></i>Subir e Importar Excel
          </button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php pie();
