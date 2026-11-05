<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

/** Sirve una evidencia solo a quien tenga acceso a la entidad a la que pertenece. */
$u = requiere_login();
$id = get_int('id');
$ev = $id ? consulta('SELECT * FROM evidencias WHERE id = ?', [$id])->fetch() : false;
if (!$ev) {
    http_response_code(404);
    exit('No encontrado.');
}

$permitido = false;
if ($ev['entidad'] === 'solicitud_mantenimiento') {
    $s = consulta('SELECT programa_id, reportado_por FROM solicitudes_mantenimiento WHERE id = ?', [$ev['entidad_id']])->fetch();
    if ($s) {
        if ($u['rol'] === 'docente') {
            $permitido = (int) $s['reportado_por'] === (int) $u['id'];
        } elseif ($u['rol'] === 'tecnico') {
            $permitido = (bool) consulta('SELECT 1 FROM asignaciones_tecnicos WHERE solicitud_id = ? AND tecnico_id = ?', [$ev['entidad_id'], $u['id']])->fetchColumn();
        } else {
            $permitido = puede_ver_programa($u, (int) $s['programa_id']);
        }
    }
} else {
    $permitido = in_array($u['rol'], ['admin_general', 'admin_programa'], true); // otras entidades: etapas siguientes
}
if (!$permitido) {
    http_response_code(403);
    exit('Acceso denegado.');
}

$ruta = $GLOBALS['CFG']['uploads']['directorio'] . '/' . basename($ev['ruta_archivo']);
if (!is_file($ruta)) {
    http_response_code(404);
    exit('Archivo no disponible.');
}
header('Content-Type: ' . $ev['tipo_mime']);
header('Content-Length: ' . filesize($ruta));
header('Content-Disposition: inline; filename="evidencia-' . (int) $ev['id'] . '.' . pathinfo($ruta, PATHINFO_EXTENSION) . '"');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
readfile($ruta);
