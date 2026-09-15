(function () {
  'use strict';

  // Show what is about to be uploaded, so a 200-file drop is obvious before sending.
  document.querySelectorAll('input[type=file]').forEach(function (input) {
    var list = document.querySelector('.filelist[data-for="' + input.name.replace('[]', '') + '"]');
    input.addEventListener('change', function () {
      if (!list) return;
      var names = [].map.call(input.files, function (f) { return f.name; });
      list.textContent = names.length ? names.length + ' קבצים: ' + names.slice(0, 20).join(', ')
        + (names.length > 20 ? ' …' : '') : '';
    });
  });

  var drop = document.getElementById('imgform');
  if (drop) {
    var input = drop.querySelector('input[type=file]');
    ['dragenter', 'dragover'].forEach(function (t) {
      drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
    });
    ['dragleave', 'drop'].forEach(function (t) {
      drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.remove('is-over'); });
    });
    drop.addEventListener('drop', function (e) {
      if (!e.dataTransfer || !e.dataTransfer.files.length) return;
      input.files = e.dataTransfer.files;
      input.dispatchEvent(new Event('change'));
    });
  }

  var filter = document.getElementById('filter');
  if (filter) {
    filter.addEventListener('input', function () {
      var q = filter.value.trim().toLowerCase();
      document.querySelectorAll('tr[data-search]').forEach(function (row) {
        row.hidden = q !== '' && row.dataset.search.indexOf(q) === -1;
      });
    });
  }
}());
