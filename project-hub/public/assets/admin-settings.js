// 系統設定頁：站台顏色即時預覽。只改變預覽區塊的 class 與文字（以 textContent 寫入），不影響實際設定；儲存後才生效。
(function () {
  var preview = document.querySelector('[data-theme-preview]');
  if (!preview) { return; }
  var select = document.querySelector('select[name="system__theme_color"]');
  var nameInput = document.querySelector('input[name="system__site_name"]');
  var labelInput = document.querySelector('input[name="system__site_label"]');
  var boxes = preview.querySelectorAll('[data-preview-box]');

  function applyColor() {
    if (!select) { return; }
    // 只接受小寫英文字母（與伺服器端相同的限制），避免把任意字串組成 class
    var color = /^[a-z]+$/.test(select.value) ? select.value : 'default';
    boxes.forEach(function (box) {
      box.className = box.className.replace(/\btp-(?!light\b|dark\b)[a-z]+\b/g, '').trim() + ' tp-' + color;
    });
  }
  function applyText() {
    preview.querySelectorAll('[data-preview-name]').forEach(function (el) {
      el.textContent = (nameInput && nameInput.value.trim()) || '網站名稱';
    });
    var label = labelInput ? labelInput.value.trim() : '';
    preview.querySelectorAll('[data-preview-label]').forEach(function (el) {
      el.textContent = label;
      el.classList.toggle('d-none', label === '');
    });
  }
  if (select) { select.addEventListener('change', applyColor); }
  if (nameInput) { nameInput.addEventListener('input', applyText); }
  if (labelInput) { labelInput.addEventListener('input', applyText); }
})();
