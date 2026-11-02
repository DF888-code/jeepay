// 專案說明草稿保護：避免版本衝突或網路問題時遺失使用者正在編輯的文字。
// 只使用本機瀏覽器的 sessionStorage（關閉分頁即清除），不會送到伺服器；也不會自動合併或覆蓋任何內容。
(function () {
  var root = document.querySelector('[data-description-draft]');
  if (!root) { return; }
  var key = 'ph-description-draft-' + root.getAttribute('data-description-draft');
  var source = document.querySelector('[data-draft-source]');

  function read() { try { return sessionStorage.getItem(key); } catch (e) { return null; } }
  function write(v) { try { sessionStorage.setItem(key, v); } catch (e) { /* 無法儲存時略過 */ } }
  function clear() { try { sessionStorage.removeItem(key); } catch (e) { /* 略過 */ } }

  function copy(text, button) {
    if (navigator.clipboard) {
      navigator.clipboard.writeText(text).then(function () { button.textContent = '已複製'; });
    }
  }

  // 衝突頁：保存使用者未儲存的內容
  if (root.getAttribute('data-conflict') === '1') {
    if (source) { write(source.value); }
    var copyBtn = document.querySelector('[data-copy-target]');
    if (copyBtn && source) {
      copyBtn.addEventListener('click', function () { copy(source.value, copyBtn); });
    }
    return;
  }

  // 編輯頁：送出前先暫存；若有與目前內容不同的暫存，顯示供使用者自行取回
  var draft = read();
  if (draft !== null && source) {
    if (draft === source.value) {
      clear(); // 內容已成功儲存
    } else {
      var box = document.querySelector('[data-draft-restore]');
      var text = document.querySelector('[data-draft-text]');
      if (box && text) {
        text.value = draft;
        box.classList.remove('d-none');
        document.querySelector('[data-draft-copy]').addEventListener('click', function (ev) { copy(draft, ev.target); });
        document.querySelector('[data-draft-discard]').addEventListener('click', function () { clear(); box.classList.add('d-none'); });
      }
    }
  }
  root.addEventListener('submit', function () {
    if (source) { write(source.value); }
  });
})();
