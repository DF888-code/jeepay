// 全站共用的小功能。CSP 禁止 inline script，因此以 data-* 屬性搭配此檔實作。
(function () {
  // <form data-confirm="訊息">：送出前再確認一次，避免誤按（伺服器端仍會做權限檢查）
  document.addEventListener('submit', function (e) {
    var form = e.target;
    var message = form.getAttribute && form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
      e.preventDefault();
    }
  });
})();
