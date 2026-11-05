<?php
declare(strict_types=1);

/**
 * Autenticación y autorización.
 * Roles: admin_general, admin_programa, tecnico, docente.
 * Los permisos finos (p. ej. compras.aprobar) se leen de rol_permiso + usuario_permiso.
 */

function usuario_actual(): ?array
{
    static $cache = null;
    if (empty($_SESSION['uid'])) {
        return null;
    }
    if ($cache !== null && $cache['id'] === $_SESSION['uid']) {
        return $cache;
    }
    $u = consulta(
        'SELECT u.id, u.nombres, u.apellidos, u.correo, u.activo, u.debe_cambiar_clave, r.codigo AS rol, r.nombre AS rol_nombre
           FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?',
        [$_SESSION['uid']]
    )->fetch();
    if (!$u || !$u['activo']) {
        cerrar_sesion();
        return null;
    }
    $cache = $u;
    return $u;
}

function requiere_login(): array
{
    $u = usuario_actual();
    if (!$u) {
        redirigir('login.php');
    }
    // Expiración por inactividad
    $limite = $GLOBALS['CFG']['seguridad']['sesion_minutos'] * 60;
    if (time() - ($_SESSION['ultima_actividad'] ?? time()) > $limite) {
        cerrar_sesion();
        session_start();
        flash('warning', 'Su sesión expiró por inactividad.');
        redirigir('login.php');
    }
    $_SESSION['ultima_actividad'] = time();

    if ($u['debe_cambiar_clave'] && basename($_SERVER['SCRIPT_NAME']) !== 'cambiar_clave.php') {
        redirigir('cambiar_clave.php');
    }
    return $u;
}

function requiere_rol(string ...$roles): array
{
    $u = requiere_login();
    if (!in_array($u['rol'], $roles, true)) {
        http_response_code(403);
        exit('Acceso denegado: su rol no tiene permiso para esta sección.');
    }
    return $u;
}

/** Programas visibles para el usuario (separación de información). */
function programas_del_usuario(int $uid, string $rol): array
{
    if ($rol === 'admin_general') {
        return array_column(consulta('SELECT id FROM programas WHERE activo = 1')->fetchAll(), 'id');
    }
    return array_map('intval', array_column(
        consulta('SELECT programa_id FROM usuario_programa WHERE usuario_id = ?', [$uid])->fetchAll(),
        'programa_id'
    ));
}

/** Devuelve un fragmento SQL "col IN (?,?,..)" y sus parámetros para filtrar por programa. */
function filtro_programas(string $columna, array $ids): array
{
    if (!$ids) {
        return ['1 = 0', []];
    }
    return [$columna . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_values($ids)];
}

function puede_ver_programa(array $u, int $programa_id): bool
{
    return in_array($programa_id, programas_del_usuario((int) $u['id'], $u['rol']), true);
}

function tiene_permiso(array $u, string $codigo): bool
{
    static $cache = [];
    $k = $u['id'] . ':' . $codigo;
    if (!isset($cache[$k])) {
        $n = consulta(
            'SELECT COUNT(*) FROM (
                SELECT p.id FROM permisos p
                  JOIN rol_permiso rp ON rp.permiso_id = p.id
                  JOIN roles r ON r.id = rp.rol_id
                  JOIN usuarios us ON us.rol_id = r.id AND us.id = ?
                 WHERE p.codigo = ?
                UNION
                SELECT p.id FROM permisos p
                  JOIN usuario_permiso up ON up.permiso_id = p.id AND up.usuario_id = ?
                 WHERE p.codigo = ?
             ) x',
            [$u['id'], $codigo, $u['id'], $codigo]
        )->fetchColumn();
        $cache[$k] = $n > 0;
    }
    return $cache[$k];
}

function requiere_permiso(string $codigo): array
{
    $u = requiere_login();
    if (!tiene_permiso($u, $codigo)) {
        http_response_code(403);
        exit('Acceso denegado: no tiene el permiso «' . e($codigo) . '».');
    }
    return $u;
}

/** Intento de inicio de sesión con bloqueo temporal y mensaje genérico. */
function iniciar_sesion(string $correo, string $clave): bool
{
    $cfg = $GLOBALS['CFG']['seguridad'];
    $u = consulta('SELECT * FROM usuarios WHERE correo = ?', [$correo])->fetch();

    // Hash ficticio para igualar tiempos cuando el usuario no existe
    $hash = $u['password_hash'] ?? '$2y$10$usesomesillystringforsalt1234567890abcdefghijklmnopq';

    if ($u && $u['bloqueado_hasta'] && strtotime($u['bloqueado_hasta']) > time()) {
        auditar('login_bloqueado', 'usuarios', (int) $u['id'], [], (int) $u['id']);
        return false;
    }

    $ok = password_verify($clave, $hash) && $u && $u['activo'];
    if (!$ok) {
        if ($u) {
            $intentos = (int) $u['intentos_fallidos'] + 1;
            $bloq = $intentos >= $cfg['max_intentos'] ? date('Y-m-d H:i:s', time() + $cfg['minutos_bloqueo'] * 60) : null;
            consulta('UPDATE usuarios SET intentos_fallidos = ?, bloqueado_hasta = ? WHERE id = ?',
                [$bloq ? 0 : $intentos, $bloq, $u['id']]);
        }
        auditar('login_fallido', 'usuarios', $u ? (int) $u['id'] : null, ['correo' => $correo], $u ? (int) $u['id'] : null);
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $u['id'];
    $_SESSION['ultima_actividad'] = time();
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        consulta('UPDATE usuarios SET password_hash = ? WHERE id = ?', [password_hash($clave, PASSWORD_DEFAULT), $u['id']]);
    }
    consulta('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL, ultimo_acceso = NOW() WHERE id = ?', [$u['id']]);
    auditar('login', 'usuarios', (int) $u['id'], [], (int) $u['id']);
    return true;
}

function cerrar_sesion(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function validar_clave_nueva(string $clave): ?string
{
    $min = $GLOBALS['CFG']['seguridad']['password_min'];
    if (mb_strlen($clave) < $min) {
        return "La contraseña debe tener al menos $min caracteres.";
    }
    if (!preg_match('/[A-Za-z]/', $clave) || !preg_match('/\d/', $clave)) {
        return 'La contraseña debe combinar letras y números.';
    }
    return null;
}
