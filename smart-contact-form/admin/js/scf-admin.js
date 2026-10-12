/**
 * Smart Contact Form – Admin JS (vanilla, zero jQuery)
 * Field builder: add, remove, drag-to-reorder rows.
 */
(function () {
  'use strict';

  var tbody   = document.getElementById('scf-fields-body');
  var addBtn  = document.getElementById('scf-add-field');
  var tmpl    = document.getElementById('scf-field-row-template');

  if (!tbody || !addBtn || !tmpl) return;

  /* ── index required checkboxes by row position ── */
  function rebuildIndexes() {
    Array.from(tbody.querySelectorAll('.scf-field-row')).forEach(function (row, i) {
      var cb = row.querySelector('input[name="scf_field_required[]"]');
      if (cb) cb.value = i;
    });
  }

  /* ── add row ── */
  addBtn.addEventListener('click', function () {
    var idx  = tbody.querySelectorAll('.scf-field-row').length;
    var html = tmpl.innerHTML.replace(/__INDEX__/g, idx);
    var tmp  = document.createElement('tbody');
    tmp.innerHTML = html;
    tbody.appendChild(tmp.firstElementChild);
    rebuildIndexes();
  });

  /* ── remove row ── */
  tbody.addEventListener('click', function (e) {
    if (!e.target.classList.contains('scf-remove-field')) return;
    if (tbody.querySelectorAll('.scf-field-row').length <= 1) {
      alert(SCF_Admin.i18n.fieldName + ' — you need at least one field.');
      return;
    }
    e.target.closest('.scf-field-row').remove();
    rebuildIndexes();
  });

  /* ── drag-to-reorder (native HTML5 drag, no jQuery UI needed) ── */
  var dragging = null;

  tbody.addEventListener('dragstart', function (e) {
    dragging = e.target.closest('.scf-field-row');
    if (dragging) {
      dragging.classList.add('scf-dragging');
      e.dataTransfer.effectAllowed = 'move';
    }
  });

  tbody.addEventListener('dragover', function (e) {
    e.preventDefault();
    var over = e.target.closest('.scf-field-row');
    if (!over || over === dragging) return;
    var rect = over.getBoundingClientRect();
    var mid  = rect.top + rect.height / 2;
    if (e.clientY < mid) {
      tbody.insertBefore(dragging, over);
    } else {
      tbody.insertBefore(dragging, over.nextSibling);
    }
  });

  tbody.addEventListener('dragend', function () {
    if (dragging) dragging.classList.remove('scf-dragging');
    dragging = null;
    rebuildIndexes();
  });

  /* Make rows draggable */
  function makeRowsDraggable() {
    Array.from(tbody.querySelectorAll('.scf-drag-handle')).forEach(function (handle) {
      handle.closest('tr').setAttribute('draggable', 'true');
    });
  }
  makeRowsDraggable();

  /* Re-apply after adding a row */
  addBtn.addEventListener('click', makeRowsDraggable);

}());
