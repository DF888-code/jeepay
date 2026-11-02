// 全域權限與專案權限頁：只負責操作體驗（標示未儲存、自動勾選查看、離開前提醒）。
// 勾選框以 data-perm-role="view|edit|delete" 標示角色。
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
    var view = form.querySelector('[data-perm-role="view"]');

    form.addEventListener('change', function (event) {
      var target = event.target;
      var role = target.getAttribute('data-perm-role');
      // 與伺服器規則一致：編輯、刪除需要查看；取消查看時一併取消編輯、刪除
      if ((role === 'edit' || role === 'delete') && target.checked && view) {
        view.checked = true;
      }
      if (role === 'view' && !target.checked) {
        form.querySelectorAll('[data-perm-role="edit"], [data-perm-role="delete"]').forEach(function (box) {
          box.checked = false;
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
