// admin/create_event.js - client-side checks for the create-event form.
(function () {
  var form = document.getElementById('create-form');
  if (!form) return;
  var input = document.getElementById('slides');
  var btn = document.getElementById('create-btn');
  var errBox = document.getElementById('create-error');
  var IMG = /\.(jpe?g|png|gif|webp)$/i;

  function validateFiles() {
    var files = Array.prototype.slice.call(input.files);
    var zips = files.filter(function (f) { return /\.zip$/i.test(f.name); });
    if (zips.length > 1 || (zips.length === 1 && files.length > 1)) {
      return 'Upload either one zip file or multiple images, not a mix.';
    }
    var bad = files.filter(function (f) { return !/\.zip$/i.test(f.name) && !IMG.test(f.name); });
    if (bad.length) return '"' + bad[0].name + '" is not a supported file.';
    return '';
  }

  function show(msg) {
    errBox.textContent = msg;
    errBox.hidden = !msg;
  }

  input.addEventListener('change', function () { show(validateFiles()); });

  form.addEventListener('submit', function (e) {
    var msg = validateFiles();
    if (msg) {
      e.preventDefault();
      show(msg);
      return;
    }
    btn.disabled = true; // prevent double submission while uploading
    btn.textContent = 'Creating...';
  });
})();
