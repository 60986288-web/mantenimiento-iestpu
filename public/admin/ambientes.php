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
    $id     = post_int('id');
    $accion = en_lista($_POST['accion'] ?? null, ['guardar', 'desactivar', 'activar']);
    $actual = $id ? consulta('SELECT * FROM ambientes WHERE id = ?', [$id])->fetch() : false;

    if ($id && (!$actual || !in_array((int) $actual['programa_id'], $progs, true))) {
        flash('danger', 'Ambiente no encontrado.');
    } elseif ($accion === 'desactivar' || $accion === 'activar') {
        consulta('UPDATE ambientes SET activo = ? WHERE id = ?', [$accion === 'activar' ? 1 : 0, $id]);
        auditar('editar', 'ambientes', $id, ['activo' => $accion === 'activar']);
        flash('success', 'Ambiente actualizado.');
    } elseif ($accion === 'guardar') {
        $programa = post_int('programa_id');
        $codigo   = mb_strtoupper(post_str('codigo', 30));
        $nombre   = post_str('nombre', 120);
        $tipo     = en_lista($_POST['tipo'] ?? null, TIPOS_AMBIENTE);
        $ubic     = post_str('ubicacion', 150) ?: null;
        $resp     = post_int('responsable_id');

        if (!$programa || !in_array($programa, $progs, true)) {
            flash('danger', 'Programa no válido.');
        } elseif ($codigo === '' || $nombre === '' || !$tipo) {
            flash('danger', 'Complete código, nombre y tipo.');
        } else {
            if ($resp) { // el responsable debe ser un usuario activo del mismo programa
                $ok = consulta('SELECT 1 FROM usuarios u JOIN usuario_programa up ON up.usuario_id = u.id AND up.programa_id = ? WHERE u.id = ? AND u.activo = 1', [$programa, $resp])->fetchColumn();
                $resp = $ok ? $resp : null;
            }
            try {
                if ($id) {
                    consulta('UPDATE ambientes SET programa_id=?, codigo=?, nombre=?, tipo=?, ubicacion=?, responsable_id=? WHERE id=?',
                        [$programa, $codigo, $nombre, $tipo, $ubic, $resp, $id]);
                    auditar('editar', 'ambientes', $id);
                } else {
                    consulta('INSERT INTO ambientes (programa_id, codigo, nombre, tipo, ubicacion, responsable_id) VALUES (?,?,?,?,?,?)',
                        [$programa, $codigo, $nombre, $tipo, $ubic, $resp]);
                    auditar('crear', 'ambientes', (int) db()->lastInsertId());
                }
                flash('success', 'Ambiente guardado.');
            } catch (PDOException $ex) {
                flash('danger', $ex->getCode() === '23000' ? 'Ya existe un ambiente con ese código.' : 'No se pudo guardar.');
                if ($ex->getCode() !== '23000') {
                    error_log('Ambientes: ' . $ex->getMessage());
                }
            }
        }
    }
    redirigir('admin/ambientes.php');
}

$editId = get_int('editar');
$edit = false;
if ($editId) {
    $edit = consulta('SELECT * FROM ambientes WHERE id = ?', [$editId])->fetch();
    if ($edit && !in_array((int) $edit['programa_id'], $progs, true)) {
        $edit = false;
    }
}

[$f, $p] = filtro_programas('a.programa_id', $progs);
$filas = consulta(
    "SELECT a.*, pr.codigo AS programa, CONCAT(r.nombres,' ',r.apellidos) AS responsable,
            (SELECT COUNT(*) FROM equipos e WHERE e.ambiente_id = a.id AND e.estado <> 'dado_de_baja') AS n_equipos
       FROM ambientes a JOIN programas pr ON pr.id = a.programa_id
       LEFT JOIN usuarios r ON r.id = a.responsable_id
      WHERE $f ORDER BY pr.codigo, a.nombre", $p
)->fetchAll();

$posibles = [];
if ($progs) {
    [$fU, $pU] = filtro_programas('up.programa_id', $progs);
    $posibles = consulta("SELECT DISTINCT u.id, u.nombres, u.apellidos FROM usuarios u JOIN usuario_programa up ON up.usuario_id = u.id WHERE u.activo = 1 AND $fU ORDER BY u.apellidos", $pU)->fetchAll();
}

cabecera('Ambientes', $u);
?>
<h1 class="h3 mb-3">Laboratorios y ambientes</h1>
<div class="row g-4">
  <div class="col-lg-4">
    <form method="post" class="card card-body shadow-sm">
      <h2 class="h6"><?= $edit ? 'Editar ambiente' : 'Nuevo ambiente' ?></h2>
      <?= csrf_campo() ?><input type="hidden" name="accion" value="guardar">
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
      <div class="mb-2"><label class="form-label">Programa</label>
        <select class="form-select" name="programa_id" required>
          <?php foreach ($selProg as $pg): ?><option value="<?= (int) $pg['id'] ?>" <?= ($edit['programa_id'] ?? 0) == $pg['id'] ? 'selected' : '' ?>><?= e($pg['codigo'] . ' · ' . $pg['nombre']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="mb-2"><label class="form-label">Código</label><input class="form-control" name="codigo" required maxlength="30" value="<?= e($edit['codigo'] ?? '') ?>"></div>
      <div class="mb-2"><label class="form-label">Nombre</label><input class="form-control" name="nombre" required maxlength="120" value="<?= e($edit['nombre'] ?? '') ?>"></div>
      <div class="mb-2"><label class="form-label">Tipo</label>
        <select class="form-select" name="tipo" required>
          <?php foreach (TIPOS_AMBIENTE as $t): ?><option value="<?= e($t) ?>" <?= ($edit['tipo'] ?? 'laboratorio') === $t ? 'selected' : '' ?>><?= e(texto_estado($t)) ?></option><?php endforeach; ?>
        </select></div>
      <div class="mb-2"><label class="form-label">Ubicación</label><input class="form-control" name="ubicacion" maxlength="150" value="<?= e($edit['ubicacion'] ?? '') ?>"></div>
      <div class="mb-3"><label class="form-label">Responsable (opcional)</label>
        <select class="form-select" name="responsable_id"><option value="">—</option>
          <?php foreach ($posibles as $r): ?><option value="<?= (int) $r['id'] ?>" <?= ($edit['responsable_id'] ?? 0) == $r['id'] ? 'selected' : '' ?>><?= e($r['apellidos'] . ', ' . $r['nombres']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="d-flex gap-2"><button class="btn btn-primary" type="submit">Guardar</button>
        <?php if ($edit): ?><a class="btn btn-outline-secondary" href="<?= e(url('admin/ambientes.php')) ?>">Cancelar</a><?php endif; ?></div>
    </form>
  </div>
  <div class="col-lg-8">
    <div class="table-responsive card shadow-sm">
      <table class="table table-hover mb-0 align-middle">
        <thead><tr><th>Código</th><th>Nombre</th><th>Programa</th><th>Responsable</th><th class="text-end">Equipos</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($filas as $a): ?>
          <tr class="<?= $a['activo'] ? '' : 'text-muted' ?>">
            <td><?= e($a['codigo']) ?></td>
            <td><?= e($a['nombre']) ?><div class="small text-muted"><?= e(texto_estado($a['tipo'])) ?><?= $a['ubicacion'] ? ' · ' . e($a['ubicacion']) : '' ?></div></td>
            <td><?= e($a['programa']) ?></td>
            <td class="small"><?= e($a['responsable'] ?? '—') ?></td>
            <td class="text-end"><?= (int) $a['n_equipos'] ?></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/ambientes.php?editar=' . (int) $a['id'])) ?>">Editar</a>
              <form method="post" class="d-inline"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                <input type="hidden" name="accion" value="<?= $a['activo'] ? 'desactivar' : 'activar' ?>">
                <button class="btn btn-sm btn-outline-secondary" type="submit"><?= $a['activo'] ? 'Desactivar' : 'Activar' ?></button></form>
            </td>
          </tr>
        <?php endforeach; if (!$filas): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">Aún no hay ambientes.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php pie();
