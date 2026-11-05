<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit("Solo por consola.\n"); }

$GLOBALS['CFG'] = require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/includes/db.php';

if ($argc < 4) { 
    exit("Uso: php scripts/importar_csv.php <archivo.csv> <PROGRAMA> <AMBIENTE>\n"); 
}

$archivo = $argv[1];
$prog_codigo = strtoupper($argv[2]);
$ambiente_nombre = $argv[3];

if (!file_exists($archivo)) { exit("El archivo $archivo no existe.\n"); }

$pdo = db();
$pdo->beginTransaction();

try {
    $handle = fopen($archivo, "r");
    $linea_muestra = fgets($handle);
    $delimitador = (strpos($linea_muestra, ';') !== false) ? ';' : ',';
    rewind($handle);

    $total_general = 0;

    // 1. Programa
    $stmt = $pdo->prepare("SELECT id FROM programas WHERE codigo = ?");
    $stmt->execute([$prog_codigo]);
    $prog_id = $stmt->fetchColumn();
    if (!$prog_id) {
        $pdo->prepare("INSERT INTO programas (codigo, nombre) VALUES (?, ?)")->execute([$prog_codigo, $prog_codigo]);
        $prog_id = $pdo->lastInsertId();
    }

    // 2. Ambiente
    $stmt = $pdo->prepare("SELECT id FROM ambientes WHERE nombre = ? AND programa_id = ?");
    $stmt->execute([$ambiente_nombre, $prog_id]);
    $amb_id = $stmt->fetchColumn();
    if (!$amb_id) {
        $codigo_amb = 'AMB-' . strtoupper(substr(md5($ambiente_nombre), 0, 4));
        $pdo->prepare("INSERT INTO ambientes (programa_id, codigo, nombre) VALUES (?, ?, ?)")
            ->execute([$prog_id, $codigo_amb, $ambiente_nombre]);
        $amb_id = $pdo->lastInsertId();
    }

    // 3. Categoría
    $stmt = $pdo->prepare("SELECT id FROM categorias_equipos WHERE nombre = 'Varios' LIMIT 1");
    $stmt->execute();
    $cat_id = $stmt->fetchColumn();

    // LEER TODAS LAS FILAS
    while (($datos = fgetcsv($handle, 2000, $delimitador)) !== FALSE) {
        
        $codigo = '';
        $nombre = '';
        $indice_codigo = -1;

        // Buscar el código en cualquier columna (buscamos que tenga letras y números o un guion)
        foreach ($datos as $i => $celda) {
            $c = trim((string)$celda);
            if (strlen($c) > 3 && str_contains($c, '-') && preg_match('/[A-Z]/i', $c) && !str_contains(strtoupper($c), 'CODIGO')) {
                $codigo = $c;
                $indice_codigo = $i;
                break;
            }
        }

        // Si encontramos el código, el nombre es la siguiente celda que tenga letras
        if ($indice_codigo !== -1) {
            for ($j = $indice_codigo + 1; $j < count($datos); $j++) {
                $posible_nombre = trim((string)$datos[$j]);
                if (strlen($posible_nombre) > 2 && !is_numeric($posible_nombre)) {
                    $nombre = $posible_nombre;
                    break;
                }
            }
        }

        // Si no encontramos código o nombre, pasamos a la siguiente fila
        if (empty($codigo) || empty($nombre)) continue;

        // Extraer el resto de datos de forma genérica buscando por posición aproximada
        $marca  = isset($datos[$indice_codigo + 2]) ? trim($datos[$indice_codigo + 2]) : null;
        $modelo = isset($datos[$indice_codigo + 3]) ? trim($datos[$indice_codigo + 3]) : null;
        $tipo   = isset($datos[$indice_codigo + 4]) ? trim($datos[$indice_codigo + 4]) : null;
        $color  = isset($datos[$indice_codigo + 5]) ? trim($datos[$indice_codigo + 5]) : null;
        $serie  = isset($datos[$indice_codigo + 6]) ? trim($datos[$indice_codigo + 6]) : null;

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
            $total_general++;
        }
    }
    $pdo->commit();
    echo "\n✅ Importación terminada: $total_general equipos insertados para el programa $prog_codigo.\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "\n❌ Error en la importación: " . $e->getMessage() . "\n";
}