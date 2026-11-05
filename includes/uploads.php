<?php
declare(strict_types=1);

/**
 * Guarda archivos de un <input type="file" multiple> como evidencias.
 * - Tipo real verificado con finfo (no se confía en la extensión ni en el MIME del navegador).
 * - Nombre aleatorio; el original solo se guarda como dato.
 * - Directorio fuera de public/.
 * Lanza RuntimeException (mensaje apto para el usuario) si un archivo no es válido.
 * Debe llamarse dentro de una transacción: si falla, los archivos ya movidos se eliminan.
 */
function guardar_evidencias(string $campo, string $entidad, int $entidad_id, int $usuario_id): int
{
    if (empty($_FILES[$campo]['name'])) {
        return 0;
    }
    $cfg = $GLOBALS['CFG']['uploads'];
    $nombres = (array) $_FILES[$campo]['name'];
    $guardados = [];
    $total = 0;

    if (!is_dir($cfg['directorio']) && !mkdir($cfg['directorio'], 0750, true) && !is_dir($cfg['directorio'])) {
        throw new RuntimeException('No se pudo preparar la carpeta de archivos.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);

    try {
        foreach ($nombres as $i => $original) {
            $err = (int) $_FILES[$campo]['error'][$i];
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($err !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Error al subir «' . mb_substr((string) $original, 0, 60) . '».');
            }
            $tmp  = (string) $_FILES[$campo]['tmp_name'][$i];
            $size = (int) $_FILES[$campo]['size'][$i];
            if (!is_uploaded_file($tmp)) {
                throw new RuntimeException('Archivo no válido.');
            }
            if ($size <= 0 || $size > $cfg['max_bytes']) {
                throw new RuntimeException('Cada archivo debe pesar como máximo ' . (int) ($cfg['max_bytes'] / 1048576) . ' MB.');
            }
            $mime = $finfo->file($tmp);
            if (!isset($cfg['mimes'][$mime])) {
                throw new RuntimeException('Formato no permitido (use JPG, PNG, WebP o PDF).');
            }
            $nombre = bin2hex(random_bytes(16)) . '.' . $cfg['mimes'][$mime];
            $destino = $cfg['directorio'] . '/' . $nombre;
            if (!move_uploaded_file($tmp, $destino)) {
                throw new RuntimeException('No se pudo guardar el archivo.');
            }
            $guardados[] = $destino;
            consulta(
                'INSERT INTO evidencias (entidad, entidad_id, ruta_archivo, nombre_original, tipo_mime, tamano_bytes, subido_por) VALUES (?,?,?,?,?,?,?)',
                [$entidad, $entidad_id, $nombre, mb_substr(basename((string) $original), 0, 255), $mime, $size, $usuario_id]
            );
            $total++;
        }
    } catch (Throwable $e) {
        foreach ($guardados as $ruta) {
            @unlink($ruta);
        }
        throw $e;
    }
    return $total;
}
