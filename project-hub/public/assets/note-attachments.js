// 隨手記附件上傳：拖拉（桌機）或選擇檔案（手機、平板、桌機皆可），支援一次多個檔案。
// 每個檔案各自上傳成「暫存附件」，取得 ID 後放進表單；按下儲存時伺服器才會綁定到隨手記。
// 注意：這裡只負責操作體驗，格式、大小與權限全部由伺服器檢查。
(function () {
  var csrf = (document.querySelector('input[name="_csrf"]') || {}).value || '';

  document.querySelectorAll('form[data-uploader]').forEach(function (form) {
    var url = form.getAttribute('data-upload-url');
    var input = form.querySelector('[data-file-input]');
    var list = form.querySelector('[data-upload-list]');
    var submit = form.querySelector('button[type="submit"]');
    var pending = 0;

    function setBusy(delta) {
      pending += delta;
      if (submit) { submit.disabled = pending > 0; }
    }

    function addItem(file) {
      var li = document.createElement('li');
      li.className = 'd-flex align-items-center gap-2 small py-1';
      var name = document.createElement('span');
      name.className = 'text-break flex-grow-1';
      name.textContent = file.name;
      var status = document.createElement('span');
      status.className = 'text-body-secondary text-nowrap';
      status.textContent = '上傳中 0%';
      li.appendChild(name);
      li.appendChild(status);
      list.appendChild(li);
      return { li: li, status: status };
    }

    function upload(file) {
      var item = addItem(file);
      setBusy(1);
      var data = new FormData();
      data.append('file', file);
      var xhr = new XMLHttpRequest();
      xhr.open('POST', url);
      xhr.setRequestHeader('X-CSRF-Token', csrf);
      xhr.upload.addEventListener('progress', function (e) {
        if (e.lengthComputable) { item.status.textContent = '上傳中 ' + Math.round(e.loaded / e.total * 100) + '%'; }
      });
      xhr.addEventListener('load', function () {
        setBusy(-1);
        var res = null;
        try { res = JSON.parse(xhr.responseText); } catch (err) { res = null; }
        if (xhr.status === 200 && res && res.id) {
          var hidden = document.createElement('input');
          hidden.type = 'hidden';
          hidden.name = 'attachment_ids[]';
          hidden.value = String(res.id);
          form.appendChild(hidden);
          item.status.textContent = res.label + ' · ' + res.size;
          item.status.className = 'text-success text-nowrap';
          if (res.preview_url) {
            var img = document.createElement('img');
            img.src = res.preview_url;
            img.alt = res.name;
            img.className = 'rounded border upload-thumb';
            item.li.insertBefore(img, item.li.firstChild);
          }
          var remove = document.createElement('button');
          remove.type = 'button';
          remove.className = 'btn btn-sm btn-link link-danger p-0';
          remove.textContent = '移除';
          remove.addEventListener('click', function () {
            var del = new XMLHttpRequest();
            del.open('POST', '/api/attachments/' + res.id + '/discard');
            del.setRequestHeader('X-CSRF-Token', csrf);
            del.send();
            hidden.remove();
            item.li.remove();
          });
          item.li.appendChild(remove);
        } else {
          item.status.textContent = (res && res.error) ? res.error : '上傳失敗';
          item.status.className = 'text-danger';
        }
      });
      xhr.addEventListener('error', function () {
        setBusy(-1);
        item.status.textContent = '網路錯誤，上傳失敗';
        item.status.className = 'text-danger';
      });
      xhr.send(data);
    }

    function handle(files) {
      Array.prototype.forEach.call(files, upload);
    }

    if (input) {
      input.addEventListener('change', function () {
        handle(input.files);
        input.value = '';
      });
    }
    // 整個表單（含文字框）都可以放下檔案
    ['dragenter', 'dragover'].forEach(function (type) {
      form.addEventListener(type, function (e) {
        if (e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types, 'Files') !== -1) {
          e.preventDefault();
          form.classList.add('is-dragover');
        }
      });
    });
    ['dragleave', 'drop'].forEach(function (type) {
      form.addEventListener(type, function () { form.classList.remove('is-dragover'); });
    });
    form.addEventListener('drop', function (e) {
      if (e.dataTransfer && e.dataTransfer.files.length > 0) {
        e.preventDefault();
        handle(e.dataTransfer.files);
      }
    });
    // 也支援直接貼上剪貼簿中的截圖
    form.addEventListener('paste', function (e) {
      var files = e.clipboardData ? e.clipboardData.files : null;
      if (files && files.length > 0) {
        e.preventDefault();
        handle(files);
      }
    });
  });
})();
