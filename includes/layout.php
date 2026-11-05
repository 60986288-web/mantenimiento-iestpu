<?php
declare(strict_types=1);

function menu_para_rol(string $rol): array
{
    $m = ['Inicio / Panel' => ['ruta' => 'index.php', 'icono' => 'bi-speedometer2']];

    if ($rol === 'docente') {
        $m['Buscador de Bienes']    = ['ruta' => 'admin/buscar_equipo.php', 'icono' => 'bi-search'];
        $m['Reportar Falla']        = ['ruta' => 'docente/reportar.php', 'icono' => 'bi-exclamation-triangle'];
        $m['Mis Solicitudes']       = ['ruta' => 'docente/mis_solicitudes.php', 'icono' => 'bi-list-check'];
        $m['Cronograma Preventivo'] = ['ruta' => 'admin/cronograma.php', 'icono' => 'bi-calendar-event'];
    } elseif ($rol === 'tecnico') {
        $m['Mantenimiento / Buscar']= ['ruta' => 'admin/buscar_equipo.php', 'icono' => 'bi-search'];
        $m['Atención y Diagnóstico']= ['ruta' => 'tecnico/trabajos.php', 'icono' => 'bi-tools'];
        $m['Cronograma Preventivo'] = ['ruta' => 'admin/cronograma.php', 'icono' => 'bi-calendar-check'];
        $m['Inventario de Bienes']  = ['ruta' => 'admin/equipos.php', 'icono' => 'bi-pc-display'];
        $m['Reportes por Área']     = ['ruta' => 'admin/reportes.php', 'icono' => 'bi-bar-chart-line'];
    } else {
        // admin_general y admin_programa (Coordinador de Programa)
        $m['1. Inventario Bienes']  = ['ruta' => 'admin/equipos.php', 'icono' => 'bi-box-seam'];
        $m['2. Mantenimiento / Buscar'] = ['ruta' => 'admin/buscar_equipo.php', 'icono' => 'bi-search'];
        $m['Reportar Falla']        = ['ruta' => 'docente/reportar.php', 'icono' => 'bi-exclamation-triangle'];
        $m['Solicitudes Soporte']   = ['ruta' => 'admin/solicitudes.php', 'icono' => 'bi-ticket-detailed'];
        $m['Cronograma Preventivo'] = ['ruta' => 'admin/cronograma.php', 'icono' => 'bi-calendar-event'];
        $m['Reportes por Área/Prog']= ['ruta' => 'admin/reportes.php', 'icono' => 'bi-file-earmark-bar-graph'];
        $m['Áreas y Ambientes']     = ['ruta' => 'admin/ambientes.php', 'icono' => 'bi-door-open'];
        $m['Categorías']            = ['ruta' => 'admin/categorias.php', 'icono' => 'bi-tags'];
        $m['Importar Excel / CSV']  = ['ruta' => 'admin/importar_inventario.php', 'icono' => 'bi-file-earmark-spreadsheet'];
    }
    return $m;
}

function cabecera(string $titulo, ?array $u = null): void
{
    $app = $GLOBALS['CFG']['app']['nombre'];
    $pagina_actual = basename($_SERVER['SCRIPT_NAME']);
    ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($titulo) ?> · <?= e($app) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
  <link href="<?= e(url('assets/css/app.css')) ?>" rel="stylesheet">
</head>
<body class="bg-light">

<?php if ($u): ?>
<div class="d-flex">
  <aside class="sidebar d-none d-md-flex flex-column flex-shrink-0 p-3 text-white" style="width: 265px;">
    <a href="<?= e(url('index.php')) ?>" class="d-flex align-items-center mb-3 mb-md-0 me-md-auto text-white text-decoration-none gap-2 brand">
      <img src="<?= e(url('assets/img/logo-iestp.png')) ?>" alt="Logo" style="width: 40px; height: 40px; border-radius: 50%; background: white; padding: 2px;">
      <div>
        <div class="fs-6 fw-bold lh-1">IESTP Utcubamba</div>
        <small class="text-info" style="font-size: 0.72rem;">Inventario y Mantenimiento</small>
      </div>
    </a>
    <hr class="text-secondary my-2">
    <ul class="nav nav-pills flex-column mb-auto gap-1">
      <?php foreach (menu_para_rol($u['rol']) as $txt => $item): ?>
        <li class="nav-item">
          <a href="<?= e(url($item['ruta'])) ?>" class="nav-link text-white d-flex align-items-center gap-2 py-2 <?= $pagina_actual === basename($item['ruta']) ? 'active' : '' ?>">
            <i class="bi <?= e($item['icono']) ?>"></i>
            <span><?= e($txt) ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
    <hr class="text-secondary">
    <form method="post" action="<?= e(url('logout.php')) ?>">
      <?= csrf_campo() ?>
      <button class="btn btn-outline-danger btn-sm w-100 text-start d-flex align-items-center gap-2" type="submit">
        <i class="bi bi-box-arrow-right"></i> Cerrar Sesión
      </button>
    </form>
  </aside>

  <div class="flex-grow-1 min-vh-100 d-flex flex-column">
    <nav class="navbar navbar-light bg-white border-bottom px-4 py-2 shadow-sm no-print">
      <div class="container-fluid px-0">
        <button class="navbar-toggler d-md-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarMobile">
          <span class="navbar-toggler-icon"></span>
        </button>
        <span class="navbar-text fw-bold text-dark d-none d-md-block">
          <i class="bi bi-building-gear text-primary me-1"></i> <?= e($app) ?> · Cód. Modular 741744
        </span>
        <div class="d-flex align-items-center gap-3 ms-auto">
          <form method="get" action="<?= e(url('admin/buscar_equipo.php')) ?>" class="d-none d-lg-flex">
            <div class="input-group input-group-sm">
              <input class="form-control" name="q" placeholder="Buscar cód. (001, LC1-CPU01) o serie…">
              <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search"></i></button>
            </div>
          </form>
          <span class="navbar-text small">
            <i class="bi bi-person-circle fs-5 align-middle me-1 text-primary"></i>
            <strong><?= e($u['nombres'] . ' ' . $u['apellidos']) ?></strong>
            <span class="badge bg-primary ms-1"><?= e($u['rol_nombre']) ?></span>
          </span>
        </div>
      </div>
    </nav>

    <main class="container-fluid py-4 px-4 flex-grow-1">
<?php else: ?>
    <main class="container py-4">
<?php endif; ?>

      <?php foreach (flash_consumir() as $f): ?>
        <div class="alert alert-<?= e($f['tipo']) ?> alert-dismissible fade show shadow-sm no-print" role="alert">
          <?= e($f['mensaje']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endforeach; ?>
<?php
}

function pie(): void
{
    ?>
    </main>
<?php if (isset($_SESSION['uid'])): ?>
  </div>
</div>

<div class="offcanvas offcanvas-start sidebar text-white no-print" tabindex="-1" id="sidebarMobile" style="width: 265px;">
  <div class="offcanvas-header border-bottom border-secondary">
    <h5 class="offcanvas-title fw-bold">IESTP Utcubamba</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="offcanvas-body d-flex flex-column p-3">
    <ul class="nav nav-pills flex-column mb-auto">
      <?php $u = usuario_actual(); if ($u): ?>
        <?php foreach (menu_para_rol($u['rol']) as $txt => $item): ?>
          <li class="nav-item">
            <a href="<?= e(url($item['ruta'])) ?>" class="nav-link text-white d-flex align-items-center gap-2">
              <i class="bi <?= e($item['icono']) ?>"></i> <?= e($txt) ?>
            </a>
          </li>
        <?php endforeach; ?>
      <?php endif; ?>
    </ul>
    <hr class="text-secondary">
    <form method="post" action="<?= e(url('logout.php')) ?>">
      <?= csrf_campo() ?>
      <button class="btn btn-outline-danger w-100 text-start" type="submit">Cerrar Sesión</button>
    </form>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
<?php
}
