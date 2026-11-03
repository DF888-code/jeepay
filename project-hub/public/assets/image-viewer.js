// 點擊隨手記中的圖片時以 Bootstrap Modal 放大檢視（未啟用 JavaScript 時則直接在新分頁開啟原圖）。
// 換頁採 Turbo（不重新載入整頁），本檔每次進入專案頁都會再執行一次：
// document 層級的監聽只註冊一次，並在點擊當下才找目前頁面上的 Modal，避免重複註冊或操作到已移除的舊元素。
(function () {
  if (window.__phImageViewer) { return; }
  window.__phImageViewer = true;

  document.addEventListener('click', function (e) {
    var link = e.target.closest && e.target.closest('[data-image-viewer]');
    var modalEl = document.getElementById('image-viewer');
    if (!link || !modalEl || !window.bootstrap) { return; }
    e.preventDefault();
    var img = modalEl.querySelector('[data-viewer-image]');
    img.src = link.getAttribute('data-full');
    img.alt = link.getAttribute('data-name') || '';
    modalEl.querySelector('.modal-title').textContent = link.getAttribute('data-name') || '圖片';
    modalEl.querySelector('[data-viewer-open]').href = link.getAttribute('data-full');
    if (!modalEl.dataset.viewerReady) {
      modalEl.dataset.viewerReady = '1';
      modalEl.addEventListener('hidden.bs.modal', function () { img.src = ''; });
    }
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
  });
})();
