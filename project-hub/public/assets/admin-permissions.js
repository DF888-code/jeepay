// 全域權限與專案權限頁：只負責操作體驗（標示未儲存、自動勾選查看、離開前提醒）。
// 勾選框以 data-perm-role="view|edit|delete" 標示角色。
// 權限是否生效完全由伺服器決定；此檔不參與任何權限判斷。
(function () {
  var rows = document.querySelectorAll('[data-perm-row]');

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

    // 送出時標記在 body 上：換頁後 body 會被替換，標記自動清除
    form.addEventListener('submit', function () { document.body.dataset.permSubmitting = '1'; });
  });

  // 以下監聽只註冊一次（Turbo 換頁時本檔會再執行），每次都檢查「當下頁面」是否有未儲存的修改
  if (window.__phPermGuard) { return; }
  window.__phPermGuard = true;
  var MESSAGE = '有尚未儲存的權限修改，確定要離開嗎？';

  function hasUnsaved() {
    return !document.body.dataset.permSubmitting && document.querySelector('[data-perm-row].bg-warning-subtle') !== null;
  }

  window.addEventListener('beforeunload', function (event) {
    if (hasUnsaved()) {
      event.preventDefault();
      event.returnValue = '';
    }
  });
  // Turbo 換頁不會觸發 beforeunload，另外攔截
  document.addEventListener('turbo:before-visit', function (event) {
    if (hasUnsaved() && !window.confirm(MESSAGE)) {
      event.preventDefault();
    }
  });
})();
