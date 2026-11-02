// 全域權限頁：只負責操作體驗（標示未儲存、自動勾選查看、離開前提醒）。
// 權限是否生效完全由伺服器決定；此檔不參與任何權限判斷。
(function () {
  var rows = document.querySelectorAll('[data-perm-row]');
  var submitting = false;

  function snapshot(form) {
    return Array.prototype.map.call(form.querySelectorAll('[data-perm]'), function (box) {
      return box.checked ? '1' : '0';
    }).join('');
  }

  rows.forEach(function (form) {
    var initial = snapshot(form);
    var badge = form.querySelector('[data-dirty-badge]');
    var view = form.querySelector('[data-perm="can_view_projects"]');

    form.addEventListener('change', function (event) {
      var target = event.target;
      var name = target.getAttribute('data-perm');
      // 與伺服器規則一致：編輯、刪除需要查看；取消查看時一併取消編輯、刪除
      if ((name === 'can_edit_projects' || name === 'can_delete_projects') && target.checked && view) {
        view.checked = true;
      }
      if (name === 'can_view_projects' && !target.checked) {
        ['can_edit_projects', 'can_delete_projects'].forEach(function (n) {
          var box = form.querySelector('[data-perm="' + n + '"]');
          if (box) { box.checked = false; }
        });
      }
      var dirty = snapshot(form) !== initial;
      form.classList.toggle('bg-warning-subtle', dirty);
      if (badge) { badge.classList.toggle('d-none', !dirty); }
    });

    form.addEventListener('submit', function () { submitting = true; });
  });

  window.addEventListener('beforeunload', function (event) {
    if (submitting) { return; }
    var anyDirty = Array.prototype.some.call(rows, function (form) {
      return form.classList.contains('bg-warning-subtle');
    });
    if (anyDirty) {
      event.preventDefault();
      event.returnValue = '';
    }
  });
})();
