// 歷史操作紀錄篩選：依「單日／日期範圍」切換顯示對應的日期欄位（只影響操作體驗）。
(function () {
  var radios = document.querySelectorAll('input[name="mode"]');
  function apply() {
    var mode = (document.querySelector('input[name="mode"]:checked') || {}).value || 'day';
    document.querySelectorAll('[data-mode]').forEach(function (el) {
      var on = el.getAttribute('data-mode') === mode;
      el.classList.toggle('d-none', !on);
      el.querySelectorAll('input').forEach(function (i) { i.disabled = !on; });
    });
  }
  radios.forEach(function (r) { r.addEventListener('change', apply); });
  apply();
})();
