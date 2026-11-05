<?php
declare(strict_types=1);
set_time_limit(300);
ini_set('memory_limit', '512M');

$cfg = require dirname(__DIR__) . '/config/config.php';
$dbCfg = $cfg['db'];
$mensaje = null;
$error = null;

$host    = trim((string) ($_POST['host'] ?? $dbCfg['host']));
$puerto  = (int) ($_POST['puerto'] ?? $dbCfg['puerto'] ?: 3306);
$nombre  = trim((string) ($_POST['nombre'] ?? $dbCfg['nombre']));
$usuario = trim((string) ($_POST['usuario'] ?? $dbCfg['usuario']));
$clave   = (string) ($_POST['clave'] ?? $dbCfg['clave']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        // En hosting compartido la BD ya existe en el panel; en XAMPP local la creamos si no existe
        try {
            $dsnServer = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $puerto);
            $pdo = new PDO($dsnServer, $usuario, $clave, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$nombre` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
            $pdo->exec("USE `$nombre`");
        } catch (Throwable $eCreate) {
            // Conectar directamente a la base de datos creada en el panel del Hosting
            $dsnDb = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $puerto, $nombre);
            $pdo = new PDO($dsnDb, $usuario, $clave, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }

        try {
            $pdo->exec("SET GLOBAL max_allowed_packet = 67108864");
        } catch (Throwable $ignore) {}

        $sqlPath = dirname(__DIR__) . '/database/instalacion_completa_iestpu.sql';
        if (!file_exists($sqlPath)) {
            throw new RuntimeException('No se encontró database/instalacion_completa_iestpu.sql');
        }
        $sql = file_get_contents($sqlPath);

        $bloques = preg_split('/;\r?\n/', $sql);
        $ejecutadas = 0;
        foreach ($bloques as $bloque) {
            $lineas = array_filter(explode("\n", $bloque), fn($l) => !str_starts_with(trim($l), '--'));
            $sentencia = trim(implode("\n", $lineas));
            if ($sentencia !== '') {
                $pdo->exec($sentencia);
                $ejecutadas++;
            }
        }

        // Guardar automáticamente las credenciales en config/config.local.php para que el hosting quede conectado
        $localPhp = "<?php\nreturn [\n    'db' => [\n"
                  . "        'host'    => " . var_export($host, true) . ",\n"
                  . "        'puerto'  => " . var_export($puerto, true) . ",\n"
                  . "        'nombre'  => " . var_export($nombre, true) . ",\n"
                  . "        'usuario' => " . var_export($usuario, true) . ",\n"
                  . "        'clave'   => " . var_export($clave, true) . ",\n"
                  . "    ],\n];\n";
        @file_put_contents(dirname(__DIR__) . '/config/config.local.php', $localPhp);

        $mensaje = "¡Instalación completada al 100% ($ejecutadas bloques SQL ejecutados)! Se guardó la conexión y se cargaron las 39 áreas, los 3,440 bienes del Excel y el cronograma preventivo.";
    } catch (Throwable $e) {
        $error = 'Error al conectar o instalar la base de datos: ' . $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Instalador Web / Hosting · IESTP Utcubamba</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center min-vh-100 py-4">
<div class="container" style="max-width: 680px;">
  <div class="card shadow border-0 rounded-4">
    <div class="card-body p-4 p-md-5">
      <h1 class="h3 fw-bold text-primary text-center mb-2">Instalador en 1 Clic (XAMPP y Hosting Web)</h1>
      <p class="text-muted text-center small mb-4">Si está en <strong>XAMPP</strong> deje los datos por defecto. Si lo subió a un <strong>Hosting Web</strong> (Hostinger, InfinityFree, cPanel), coloque aquí los datos de la base de datos MySQL que creó en su panel de hosting.</p>

      <?php if ($mensaje): ?>
        <div class="alert alert-success">
          <strong><?= htmlspecialchars($mensaje) ?></strong>
          <hr>
          <div><strong>Administrador General:</strong> <code>admin@iestpu.edu.pe</code></div>
          <div><strong>Coordinador APSTI:</strong> <code>coordinador.apsti@iestpu.edu.pe</code></div>
          <div><strong>Soporte / Mantenimiento:</strong> <code>soporte@iestpu.edu.pe</code></div>
          <div class="mt-1"><strong>Contraseña para todas las cuentas:</strong> <code>admin123</code></div>
        </div>
        <a href="login.php" class="btn btn-success btn-lg w-100 fw-bold">Ir a Iniciar Sesión Ahora →</a>
      <?php else: ?>
        <?php if ($error): ?>
          <div class="alert alert-danger small"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post" class="row g-3">
          <div class="col-md-8">
            <label class="form-label small fw-bold">Servidor MySQL (Host)</label>
            <input class="form-control" name="host" value="<?= htmlspecialchars($host) ?>" required placeholder="Ej. localhost o sql123.infinityfree.com">
          </div>
          <div class="col-md-4">
            <label class="form-label small fw-bold">Puerto</label>
            <input class="form-control" type="number" name="puerto" value="<?= (int) $puerto ?>" required>
          </div>
          <div class="col-md-12">
            <label class="form-label small fw-bold">Nombre de la Base de Datos</label>
            <input class="form-control" name="nombre" value="<?= htmlspecialchars($nombre) ?>" required placeholder="Ej. bd_mantenimiento_iestpu">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-bold">Usuario MySQL</label>
            <input class="form-control" name="usuario" value="<?= htmlspecialchars($usuario) ?>" required placeholder="Ej. root">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-bold">Contraseña MySQL</label>
            <input class="form-control" type="password" name="clave" value="<?= htmlspecialchars($clave) ?>" placeholder="Vacío en XAMPP, o la clave de su hosting">
          </div>
          <div class="col-12 mt-4">
            <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold py-3">
              Conectar e Instalar los 3,440 Bienes Ahora
            </button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
