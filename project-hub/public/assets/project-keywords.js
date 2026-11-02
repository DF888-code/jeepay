/*
 * 專案關鍵字：新增／移除時只更新關鍵字區塊，不重新載入整頁（避免畫面閃一下、捲動位置跳回頂端）。
 *
 * 漸進增強：沒有 JavaScript 或請求失敗時，表單照常送出（伺服器端流程不變）。
 * 權限與驗證一律由伺服器判斷；畫面只依伺服器回傳的清單重繪。
 * 關鍵字一律以 textContent 寫入，不使用 innerHTML，避免 XSS。
 */
(function () {
  'use strict';
  var section = document.querySelector('[data-keywords]');
  if (!section || !window.fetch) {
    return;
  }
  var api = section.getAttribute('data-api');
  var formBase = section.getAttribute('data-form-base');
  var list = section.querySelector('[data-keyword-list]');
  var empty = section.querySelector('[data-keyword-empty]');
  var status = section.querySelector('[data-keyword-status]');
  var addForm = section.querySelector('[data-keyword-add]');
  var input = addForm.querySelector('input[name="keyword"]');
  var csrf = addForm.querySelector('input[name="_csrf"]').value;
  var busy = false;

  var classes = { success: 'text-success', warning: 'text-warning-emphasis', error: 'text-danger' };

  function showStatus(type, message) {
    status.className = 'small mt-2 ' + (classes[type] || 'text-body-secondary');
    status.textContent = message;
  }

  function chip(k) {
    var span = document.createElement('span');
    span.className = 'badge rounded-pill text-bg-light border d-inline-flex align-items-center gap-1 fs-6 fw-normal keyword-badge';
    span.appendChild(document.createTextNode(k.keyword));
    // 同時保留一般表單，JavaScript 失敗時仍可移除
    var form = document.createElement('form');
    form.method = 'post';
    form.action = formBase + '/' + k.id + '/delete';
    form.className = 'd-inline m-0';
    form.setAttribute('data-keyword-remove', String(k.id));
    var token = document.createElement('input');
    token.type = 'hidden';
    token.name = '_csrf';
    token.value = csrf;
    var btn = document.createElement('button');
    btn.type = 'submit';
    btn.className = 'btn-close btn-close-sm keyword-remove';
    btn.setAttribute('aria-label', '移除關鍵字 ' + k.keyword);
    form.appendChild(token);
    form.appendChild(btn);
    span.appendChild(form);
    return span;
  }

  function render(keywords) {
    while (list.firstChild) {
      list.removeChild(list.firstChild);
    }
    keywords.forEach(function (k) {
      list.appendChild(chip(k));
    });
    list.classList.toggle('d-none', keywords.length === 0);
    empty.classList.toggle('d-none', keywords.length !== 0);
  }

  /** 以 fetch 送出；失敗時改用一般表單送出 */
  function send(url, body, form) {
    if (busy) {
      return;
    }
    busy = true;
    var buttons = form.querySelectorAll('button');
    buttons.forEach(function (b) { b.disabled = true; });
    fetch(url, {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' }
    }).then(function (res) {
      return res.json().then(function (data) { return { res: res, data: data }; });
    }).then(function (r) {
      if (r.res.status === 401 || r.res.status === 403) {
        // 登入逾時或狀態改變：重新整理，讓伺服器導向正確的頁面
        window.location.reload();
        return;
      }
      if (!r.data || !Array.isArray(r.data.keywords)) {
        showStatus('error', (r.data && r.data.error) || '操作失敗，請重新整理頁面後再試。');
        return;
      }
      render(r.data.keywords);
      showStatus(r.data.type, r.data.message);
      if (r.data.type === 'success' && form === addForm) {
        input.value = '';
      }
    }).catch(function () {
      // 網路或回應格式錯誤：改用一般表單送出，確保仍能完成操作
      busy = false;
      form.submit();
    }).then(function () {
      busy = false;
      buttons.forEach(function (b) { b.disabled = false; });
      if (form === addForm) {
        input.focus();
      }
    });
  }

  addForm.addEventListener('submit', function (e) {
    e.preventDefault();
    var body = new FormData();
    body.append('keyword', input.value);
    send(api, body, addForm);
  });

  // 事件委派：重繪後新產生的移除按鈕也有效
  list.addEventListener('submit', function (e) {
    var form = e.target.closest('[data-keyword-remove]');
    if (!form) {
      return;
    }
    e.preventDefault();
    send(api + '/' + form.getAttribute('data-keyword-remove') + '/delete', new FormData(), form);
  });
})();
