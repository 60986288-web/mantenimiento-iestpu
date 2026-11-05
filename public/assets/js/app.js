// Filtra la lista de equipos según el ambiente elegido (formulario de reporte de falla)
document.addEventListener('DOMContentLoaded', function () {
  var amb = document.querySelector('[data-filtro-equipos]');
  var eq = document.getElementById('equipo_id');
  if (!amb || !eq) { return; }
  amb.addEventListener('change', function () {
    Array.prototype.forEach.call(eq.options, function (o) {
      if (!o.value) { return; }
      var visible = !amb.value || o.getAttribute('data-ambiente') === amb.value;
      o.hidden = !visible;
      o.disabled = !visible;
    });
    eq.value = '';
  });
});

// Formulario de equipo: muestra solo ambientes y categorías del programa elegido
document.addEventListener('DOMContentLoaded', function () {
  var prog = document.getElementById('f_programa');
  var amb = document.getElementById('f_ambiente');
  var cat = document.getElementById('f_categoria');
  if (!prog || !amb || !cat) { return; }
  function aplicar(sel, comunes) {
    Array.prototype.forEach.call(sel.options, function (o) {
      if (!o.value) { return; }
      var p = o.getAttribute('data-programa');
      var ver = p === prog.value || (comunes && p === '0');
      o.hidden = !ver; o.disabled = !ver;
    });
    if (sel.selectedOptions[0] && sel.selectedOptions[0].disabled) { sel.value = ''; }
  }
  function todo() { aplicar(amb, false); aplicar(cat, true); }
  prog.addEventListener('change', todo);
  todo();
});
