<?php
$active = 'settings';
require __DIR__ . '/_nav.php';
$tabs = ['general' => ['/admin/settings', '一般設定'], 'mail' => ['/admin/settings/mail', '寄信設定'], 'storage' => ['/admin/settings/storage', '附件儲存位置']];
?>
<ul class="nav nav-pills mb-3 small">
  <?php foreach ($tabs as $k => [$href, $label]): ?>
    <li class="nav-item"><a class="nav-link<?= $settingsTab === $k ? ' active' : '' ?>" href="<?= e($href) ?>"<?= $settingsTab === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?></a></li>
  <?php endforeach; ?>
</ul>
<?php require __DIR__ . '/_reauth.php'; ?>
