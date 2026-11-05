<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit("Solo por consola.\n"); }

$GLOBALS['CFG'] = require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/includes/db.php';
require dirname(__DIR__) . '/includes/SimpleXLSX.php';

if ($argc < 2) { exit("Uso: php scripts/importar_excel.php <ruta_al_excel.xlsx>\n"); }

$archivo = $argv[1];
if (!file_exists($archivo)) { exit("El archivo $archivo no existe.\n"); }

function limpiar($str) {
    return strtoupper(str_replace(
        ['á', 'é', 'í', 'ó', 'ú', 'Á', 'É', 'Í', 'Ó', 'Ú', 'ñ', 'Ñ'],
        ['A', 'E', 'I', 'O', 'U', 'A', 'E', 'I', 'O', 'U', 'N', 'N'],
        trim((string)$str)
    ));
}

$pdo = db();
$pdo->beginTransaction();

try {
    if ($xlsx = \Shuchkin\SimpleXLSX::parse($archivo)) {
        $hojas = $xlsx->sheetNames();
        $total_general = 0;

        foreach ($hojas as $index => $nombre_hoja) {
            echo "\n--- Procesando Hoja: $nombre_hoja ---\n";
            $datos = $xlsx->rows($index);
            
            // Adivinar programa
            $prog_codigo = 'ADMIN';
            if (stripos($nombre_hoja, 'APSTI') !== false) $prog_codigo = 'APSTI';
            elseif (stripos($nombre_hoja, 'CONTABILIDAD') !== false) $prog_codigo = 'CONT';
            elseif (stripos($nombre_hoja, 'ENFERMERIA') !== false) $prog_codigo = 'ENFE';
            elseif (stripos($nombre_hoja, 'AGROPECUARIA') !== false) $prog_codigo = 'AGRO';

            $stmt = $pdo->prepare("SELECT id FROM programas WHERE codigo = ?");
            $stmt->execute([$prog_codigo]);
            $prog_id = $stmt->fetchColumn();
            if (!$prog_id) {
                $pdo->prepare("INSERT INTO programas (codigo, nombre) VALUES (?, ?)")->execute([$prog_codigo, $prog_codigo]);
                $prog_id = $pdo->lastInsertId();
            }

            $stmt = $pdo->prepare("SELECT id FROM ambientes WHERE nombre = ? AND programa_id = ?");
            $stmt->execute([$nombre_hoja, $prog_id]);
            $amb_id = $stmt->fetchColumn();
            if (!$amb_id) {
                $codigo_amb = 'AMB-' . strtoupper(substr(uniqid(), -6));
                $pdo->prepare("INSERT INTO ambientes (programa_id, codigo, nombre) VALUES (?, ?, ?)")
                    ->execute([$prog_id, $codigo_amb, $nombre_hoja]);
                $amb_id = $pdo->lastInsertId();
            }

            $stmt = $pdo->prepare("SELECT id FROM categorias_equipos WHERE nombre = 'Varios' LIMIT 1");
            $stmt->execute();
            $cat_id = $stmt->fetchColumn();

            $importados_hoja = 0;

            // LEEMOS TODAS LAS FILAS A LA FUERZA
            foreach ($datos as $fila) {
                // Buscamos si hay un código en esta fila (que tenga un guion y letras)
                $codigo = '';
                $indice_codigo = -1;
                
                foreach ($fila as $i => $celda) {
                    $c = limpiar($celda);
                    // Si la celda tiene un guion y letras, es el código
                    if (strlen($c) > 3 && str_contains($c, '-') && preg_match('/[A-Z]/', $c) && !str_contains($c, 'ESTADO')) {
                        $codigo = $c;
                        $indice_codigo = $i;
                        break;
                    }
                }

                // Si encontramos un código, intentamos sacar el nombre (la siguiente celda no vacía)
                if (!empty($codigo) && $indice_codigo !== -1) {
                    $nombre = '';
                    // Buscar el nombre en la celda de al lado o dos al lado
                    if (isset($fila[$indice_codigo + 1]) && !empty(trim((string)$fila[$indice_codigo + 1]))) {
                        $nombre = limpiar($fila[$indice_codigo + 1]);
                    } elseif (isset($fila[$indice_codigo + 2]) && !empty(trim((string)$fila[$indice_codigo + 2]))) {
                        $nombre = limpiar($fila[$indice_codigo + 2]);
                    }

                    if (empty($nombre) || is_numeric($nombre)) continue;

                    // Extraer el resto de datos de forma genérica
                    $marca       = limpiar($fila[$indice_codigo + 2] ?? '');
                    $modelo      = limpiar($fila[$indice_codigo + 3] ?? '');
                    $tipo        = limpiar($fila[$indice_codigo + 4] ?? '');
                    $color       = limpiar($fila[$indice_codigo + 5] ?? '');
                    $serie       = limpiar($fila[$indice_codigo + 6] ?? '');

                    $sql = "INSERT IGNORE INTO equipos (
                                codigo_inventario, codigo_interno, nombre, marca, modelo, numero_serie, 
                                color, tipo_fisico, programa_id, ambiente_id, categoria_id, estado, situacion
                            ) VALUES (?,?,?,?,?,?,?,?,?,?,?, 'operativo', 'en_uso')";
                    
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        $codigo, $codigo, $nombre, $marca ?: null, $modelo ?: null, $serie ?: null,
                        $color ?: null, $tipo ?: null, $prog_id, $amb_id, $cat_id
                    ]);
                    
                    if ($stmt->rowCount() > 0) {
                        $importados_hoja++;
                        $total_general++;
                    }
                }
            }
            echo "  -> $importados_hoja equipos importados en esta hoja.\n";
        }
        
        $pdo->commit();
        echo "\n✅ ¡TODO EL EXCEL HA SIDO IMPORTADO! ($total_general equipos en total)\n";

    } else {
        echo "Error al leer el Excel: " . \Shuchkin\SimpleXLSX::parseError() . "\n";
    }
} catch (Exception $e) {
    $pdo->rollBack();
    echo "\n❌ Error en la importación: " . $e->getMessage() . "\n";
}