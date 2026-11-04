<?php
/**
 * 站台顏色即時預覽：選擇顏色、修改網站名稱或標籤時，由 admin-settings.js 切換 class 與文字，不需先儲存。
 * 預覽使用與實際頁面相同的色票（app.css 的 .tp-* 規則），淺色與深色模式並排顯示。
 */
$previewColor = preg_match('/^[a-z]+$/', (string)$value) ? (string)$value : 'default';
$previewName = (string)($values['system.site_name'] ?? $siteName);
$previewLabel = (string)($values['system.site_label'] ?? '');
?>
<div class="row g-2 mt-1" data-theme-preview>
  <?php foreach (['light' => '淺色模式', 'dark' => '深色模式'] as $mode => $modeLabel): ?>
    <div class="col-12 col-sm-6">
      <div class="small text-body-secondary mb-1">預覽（<?= e($modeLabel) ?>）</div>
      <div class="theme-preview tp-<?= e($mode) ?> tp-<?= e($previewColor) ?>" data-preview-box aria-hidden="true">
        <div class="tp-nav">
          <span class="tp-brand" data-preview-name><?= e($previewName) ?></span>
          <span class="badge site-label<?= $previewLabel === '' ? ' d-none' : '' ?>" data-preview-label><?= e($previewLabel) ?></span>
        </div>
        <div class="tp-body">
          <div class="tp-card">
            <div class="tp-line tp-line-title"></div>
            <div class="tp-line"></div>
            <div class="tp-line tp-line-short"></div>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
