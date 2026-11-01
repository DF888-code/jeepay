// 依作業系統設定切換 Bootstrap 深色／淺色模式。
// 放在 <head> 同步執行以避免畫面閃爍；CSP 禁止 inline script，因此獨立成檔案。
(function () {
  var media = window.matchMedia('(prefers-color-scheme: dark)');
  function apply() {
    document.documentElement.setAttribute('data-bs-theme', media.matches ? 'dark' : 'light');
  }
  apply();
  if (media.addEventListener) {
    media.addEventListener('change', apply);
  }
})();
