// 點擊隨手記中的圖片時以 Bootstrap Modal 放大檢視（未啟用 JavaScript 時則直接在新分頁開啟原圖）。
(function () {
  var modalEl = document.getElementById('image-viewer');
  if (!modalEl || !window.bootstrap) { return; }
  var modal = new bootstrap.Modal(modalEl);
  var img = modalEl.querySelector('[data-viewer-image]');
  var title = modalEl.querySelector('.modal-title');
  var open = modalEl.querySelector('[data-viewer-open]');

  document.addEventListener('click', function (e) {
    var link = e.target.closest('[data-image-viewer]');
    if (!link) { return; }
    e.preventDefault();
    img.src = link.getAttribute('data-full');
    img.alt = link.getAttribute('data-name') || '';
    title.textContent = link.getAttribute('data-name') || '圖片';
    open.href = link.getAttribute('data-full');
    modal.show();
  });
  modalEl.addEventListener('hidden.bs.modal', function () { img.src = ''; });
})();
