<?php
use ProjectHub\Settings\SettingsRegistry;

$settingsTab = 'general';
require __DIR__ . '/_settings_nav.php';
$disabledAttr = empty($elevated) ? ' disabled' : '';
?>
<?php if ($errors !== []): ?>
  <div class="alert alert-danger" role="alert">有 <?= count($errors) ?> 個欄位不正確，所有設定都尚未儲存，請修正後再送出。</div>
<?php endif; ?>
<form method="post" action="/admin/settings">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <div class="row g-3">
    <?php foreach (SettingsRegistry::CATEGORIES as $cat => $catLabel): if (empty($groups[$cat])) { continue; } ?>
      <div class="col-12 col-lg-6">
        <section class="card shadow-sm h-100" aria-labelledby="cat-<?= e($cat) ?>">
          <div class="card-body">
            <h2 class="h5 mb-3" id="cat-<?= e($cat) ?>"><?= e($catLabel) ?></h2>
            <?php foreach ($groups[$cat] as $key => $def) { require __DIR__ . '/_setting_field.php'; } ?>
          </div>
        </section>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
    <button type="submit" class="btn btn-primary"<?= $disabledAttr ?>>儲存設定</button>
    <span class="small text-body-secondary">所有變更都會寫入歷史操作紀錄（含修改前後的值）。</span>
  </div>
</form>
