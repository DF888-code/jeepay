<?php
/**
 * 依設定定義產生表單欄位。需要：$key、$def、$values、$errors、$disabledAttr。
 * 欄位名稱把「.」換成「__」，因為 PHP 會把表單名稱中的「.」轉成「_」。
 */
use ProjectHub\Controllers\AdminSettingsController;

$fname = AdminSettingsController::field($key);
$fid = 'set-' . str_replace(['.', '_'], '-', $key);
$value = $values[$key] ?? $def['default'];
$err = $errors[$key] ?? null;
$invalid = $err !== null ? ' is-invalid' : '';
$helpId = $fid . '-help';
$described = !empty($def['help']) || $err !== null ? ' aria-describedby="' . e($helpId) . '"' : '';
?>
<div class="mb-3" data-setting="<?= e($key) ?>">
  <?php if ($def['type'] === 'bool'): ?>
    <div class="form-check form-switch">
      <input type="hidden" name="<?= e($fname) ?>" value="0">
      <input class="form-check-input<?= $invalid ?>" type="checkbox" role="switch" id="<?= e($fid) ?>" name="<?= e($fname) ?>" value="1"<?= $value ? ' checked' : '' ?><?= $described ?><?= $disabledAttr ?>>
      <label class="form-check-label" for="<?= e($fid) ?>"><?= e($def['label']) ?></label>
    </div>
  <?php else: ?>
    <label class="form-label" for="<?= e($fid) ?>"><?= e($def['label']) ?></label>
    <?php if ($def['type'] === 'enum'): ?>
      <select class="form-select<?= $invalid ?>" id="<?= e($fid) ?>" name="<?= e($fname) ?>"<?= $described ?><?= $disabledAttr ?>>
        <?php foreach ($def['options'] as $optValue => $optLabel): ?>
          <option value="<?= e((string)$optValue) ?>"<?= (string)$value === (string)$optValue ? ' selected' : '' ?>><?= e($optLabel) ?></option>
        <?php endforeach; ?>
      </select>
    <?php elseif ($def['type'] === 'timezone'): ?>
      <select class="form-select<?= $invalid ?>" id="<?= e($fid) ?>" name="<?= e($fname) ?>"<?= $described ?><?= $disabledAttr ?>>
        <?php foreach (\DateTimeZone::listIdentifiers() as $tz): ?>
          <option value="<?= e($tz) ?>"<?= $tz === $value ? ' selected' : '' ?>><?= e($tz) ?></option>
        <?php endforeach; ?>
      </select>
    <?php elseif ($def['type'] === 'int'): ?>
      <input type="number" class="form-control setting-number<?= $invalid ?>" id="<?= e($fid) ?>" name="<?= e($fname) ?>" value="<?= e((string)$value) ?>" min="<?= (int)$def['min'] ?>" max="<?= (int)$def['max'] ?>" step="1" required<?= $described ?><?= $disabledAttr ?>>
    <?php else: ?>
      <input type="<?= ($def['format'] ?? '') === 'email' ? 'email' : 'text' ?>" class="form-control<?= $invalid ?>" id="<?= e($fid) ?>" name="<?= e($fname) ?>" value="<?= e((string)$value) ?>" maxlength="<?= (int)($def['max'] ?? 255) ?>" autocomplete="off"<?= $key === 'system.site_name' ? ' required' : '' ?><?= $described ?><?= $disabledAttr ?>>
    <?php endif; ?>
  <?php endif; ?>
  <?php if ($err !== null || !empty($def['help'])): ?>
    <div id="<?= e($helpId) ?>" class="<?= $err !== null ? 'invalid-feedback d-block' : 'form-text' ?>"><?= e($err ?? $def['help']) ?><?php if ($err !== null && !empty($def['help'])): ?><span class="d-block text-body-secondary"><?= e($def['help']) ?></span><?php endif; ?></div>
  <?php endif; ?>
  <?php if ($key === 'system.theme_color') { require __DIR__ . '/_theme_preview.php'; } ?>
  <?php if ($key === 'security.client_ip_mode' && isset($ipDiagnostic)) { require __DIR__ . '/_ip_diagnostic.php'; } ?>
</div>
