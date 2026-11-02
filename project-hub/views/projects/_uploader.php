<?php /** 附件上傳區（新增與修改隨手記共用）。實際檢查全部在伺服器端，這裡只負責操作體驗。 */ ?>
<div class="note-dropzone rounded p-3 mb-2" data-dropzone>
  <div class="d-flex flex-wrap align-items-center gap-2">
    <span class="small d-none d-md-inline">把圖片或檔案拖曳到這裡，或</span>
    <label class="btn btn-sm btn-outline-secondary mb-0">
      選擇檔案／圖片
      <input type="file" class="visually-hidden" multiple accept="<?= e($acceptTypes) ?>" data-file-input>
    </label>
    <span class="small text-body-secondary">可多選；單檔上限 <?= (int)$maxFileMb ?> MB（圖片、PDF、Office、ZIP、文字檔）</span>
  </div>
  <ul class="list-unstyled mb-0 mt-2" data-upload-list></ul>
</div>
