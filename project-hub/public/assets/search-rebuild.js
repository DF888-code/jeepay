// 搜尋索引重建：每次請求處理一段時間，自動連續呼叫直到完成（主機單次請求上限 60 秒）。
// 未啟用 JavaScript 時，表單會以一般方式送出，由管理者重複點擊「繼續重建」。
(function () {
  var form = document.querySelector('[data-rebuild-form]');
  if (!form) { return; }
  var status = form.querySelector('[data-rebuild-status]');
  var button = form.querySelector('button');
  var total = 0;
  var names = { projects: '專案', project_keywords: '關鍵字', project_notes: '隨手記', note_attachments: '附件' };

  function step() {
    var data = new FormData(form);
    fetch(form.action, { method: 'POST', body: data, headers: { 'Accept': 'application/json', 'X-CSRF-Token': data.get('_csrf') } })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        total += res.processed || 0;
        if (res.done) {
          status.textContent = '完成，共處理 ' + total + ' 筆。';
          setTimeout(function () { window.location.reload(); }, 800);
        } else {
          status.textContent = '處理中（' + (names[res.table] || res.table) + '）… 已處理 ' + total + ' 筆';
          step();
        }
      })
      .catch(function () {
        status.textContent = '連線中斷，請再按一次「繼續重建」。';
        button.disabled = false;
      });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    button.disabled = true;
    status.textContent = '處理中…';
    step();
  });
})();
