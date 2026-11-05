<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/layout.php';
require dirname(__DIR__, 2) . '/includes/catalogos.php';

$u = requiere_rol('admin_general', 'admin_programa');
requiere_permiso('equipos.gestionar');
$progs = programas_del_usuario((int) $u['id'], $u['rol']);
$selProg = programas_para_select($u);

if (es_post()) {
    csrf_verificar();
    $nombre = post_str('nombre', 100);
    $prog   = post_int('programa_id');          // 0/NULL = común (solo administrador general)
    $cons   = isset($_POST['es_consumible']) ? 1 : 0;

    if ($nombre === '') {
        flash('danger', 'Escriba el nombre de la categoría.');
    } elseif ($prog === null || $prog === 0) {
        if ($u['rol'] !== 'admin_general') {
            flash('danger', 'Solo el administrador general crea categorías comunes.');
        } else {
            $prog = null;
        }
    } elseif (!in_array($prog, $progs, true)) {
        flash('danger', 'Programa no válido.');
        $prog = -1;
    }

    if ($nombre !== '' && $prog !== -1 && empty($_SESSION['flash'])) {
        $dup = consulta('SELECT 1 FROM categorias_equipos WHERE nombre = ? AND (programa_id <=> ?)', [$nombre, $prog])->fetchColumn();
        if ($dup) {
            flash('danger', 'Ya existe esa categoría en ese programa.');
        } else {
            consulta('INSERT INTO categorias_equipos (programa_id, nombre, es_consumible) VALUES (?,?,?)', [$prog, $nombre, $cons]);
            auditar('crear', 'categorias_equipos', (int) db()->lastInsertId(), ['nombre' => $nombre]);
            flash('success', 'Categoría creada.');
        }
    }
    redirigir('admin/categorias.php');
}

[$f, $p] = filtro_programas('c.programa_id', $progs);
$filas = consulta(
    "SELECT c.id, c.nombre, c.es_consumible, pr.codigo AS programa,
            (SELECT COUNT(*) FROM equipos e WHERE e.categoria_id = c.id) AS n
       FROM categorias_equipos c LEFT JOIN programas pr ON pr.id = c.programa_id
      WHERE c.programa_id IS NULL OR $f ORDER BY pr.codigo, c.nombre", $p
)->fetchAll();

cabecera('Categorías de equipos', $u);
?>
<h1 class="h3 mb-3">Categorías de equipos</h1>
<div class="row g-4">
  <div class="col-lg-4">
    <form method="post" class="card card-body shadow-sm">
      <h2 class="h6">Nueva categoría</h2>
      <?= csrf_campo() ?>
      <div class="mb-2"><label class="form-label">Nombre</label><input class="form-control" name="nombre" required maxlength="100"></div>
      <div class="mb-2"><label class="form-label">Programa</label>
        <select class="form-select" name="programa_id">
          <?php if ($u['rol'] === 'admin_general'): ?><option value="0">Común a todos los programas</option><?php endif; ?>
          <?php foreach ($selProg as $pg): ?><option value="<?= (int) $pg['id'] ?>"><?= e($pg['codigo'] . ' · ' . $pg['nombre']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="cons" name="es_consumible" value="1">
        <label class="form-check-label" for="cons">Material consumible (no requiere mantenimiento)</label></div>
      <button class="btn btn-primary" type="submit">Agregar</button>
    </form>
  </div>
  <div class="col-lg-8">
    <div class="table-responsive card shadow-sm">
      <table class="table table-hover mb-0">
        <thead><tr><th>Categoría</th><th>Programa</th><th>Tipo</th><th class="text-end">Recursos</th></tr></thead>
        <tbody>
        <?php foreach ($filas as $c): ?>
          <tr><td><?= e($c['nombre']) ?></td><td><?= e($c['programa'] ?? 'Común') ?></td>
              <td><?= $c['es_consumible'] ? '<span class="badge text-bg-secondary">Consumible</span>' : 'Equipo' ?></td>
              <td class="text-end"><?= (int) $c['n'] ?></td></tr>
        <?php endforeach; if (!$filas): ?>
          <tr><td colspan="4" class="text-center text-muted py-4">Aún no hay categorías.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php pie();
