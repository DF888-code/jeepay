<?php
use ProjectHub\Activity\ActivityLabels;
use ProjectHub\Support\Clock;

$active = 'logs';
require __DIR__ . '/_nav.php';
$resultClass = ['success' => 'text-bg-success', 'failure' => 'text-bg-warning', 'denied' => 'text-bg-danger'];
$maxHour = max(1, max($stats['hours']));
?>
<form method="get" action="/admin/logs" class="card shadow-sm mb-3">
  <div class="card-body">
    <div class="row g-3 align-items-end">
      <div class="col-12 col-md-auto">
        <span class="form-label d-block small">日期</span>
        <div class="btn-group" role="group" aria-label="日期篩選方式">
          <input type="radio" class="btn-check" name="mode" id="mode-day" value="day"<?= $mode === 'day' ? ' checked' : '' ?>>
          <label class="btn btn-outline-secondary btn-sm" for="mode-day">單日</label>
          <input type="radio" class="btn-check" name="mode" id="mode-range" value="range"<?= $mode === 'range' ? ' checked' : '' ?>>
          <label class="btn btn-outline-secondary btn-sm" for="mode-range">日期範圍</label>
        </div>
      </div>
      <div class="col-6 col-md-2" data-mode="day">
        <label for="f-date" class="form-label small">日期</label>
        <input id="f-date" type="date" name="date" class="form-control form-control-sm" value="<?= e($filter['from']) ?>">
      </div>
      <div class="col-6 col-md-2" data-mode="range">
        <label for="f-from" class="form-label small">起</label>
        <input id="f-from" type="date" name="from" class="form-control form-control-sm" value="<?= e($filter['from']) ?>">
      </div>
      <div class="col-6 col-md-2" data-mode="range">
        <label for="f-to" class="form-label small">迄</label>
        <input id="f-to" type="date" name="to" class="form-control form-control-sm" value="<?= e($filter['to']) ?>">
      </div>
      <div class="col-12 col-md-3">
        <label for="f-user" class="form-label small">員工</label>
        <select id="f-user" name="user" class="form-select form-select-sm">
          <option value="">所有員工</option>
          <?php foreach ($actors as $a): ?>
            <option value="<?= (int)$a['id'] ?>"<?= $filter['user'] === (string)$a['id'] ? ' selected' : '' ?>><?= e($a['display_name']) ?>（<?= e($a['username']) ?>）<?= $a['status'] !== 'active' ? '－已停用' : '' ?></option>
          <?php endforeach; ?>
          <option value="unknown"<?= $filter['user'] === 'unknown' ? ' selected' : '' ?>>未知帳號（登入失敗）</option>
        </select>
      </div>
      <div class="col-12 col-md-2">
        <label for="f-category" class="form-label small">類型</label>
        <select id="f-category" name="category" class="form-select form-select-sm">
          <option value="">全部類型</option>
          <?php foreach ($categories as $code => $label): ?>
            <option value="<?= e($code) ?>"<?= $filter['category'] === $code ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-auto d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm">篩選</button>
        <a class="btn btn-outline-secondary btn-sm" href="/admin/logs/export?<?= e($queryString) ?>">匯出 CSV</a>
      </div>
    </div>
  </div>
</form>

<?php if (!empty($error)): ?><div class="alert alert-warning small"><?= e($error) ?></div><?php endif; ?>

<div class="alert alert-light border small">
  此紀錄用於了解系統使用狀況，<strong>沒有操作紀錄不代表沒有工作</strong>（可能正在思考、閱讀、開會或使用其他工具），登入中也不代表持續操作。
  紀錄保存 <?= (int)$retentionDays ?> 天，期限可於系統設定調整；資料庫紀錄並非不可竄改的稽核證據。
</div>

<div class="row g-3 mb-3">
  <?php
  $cards = [
      ['操作總數', (string)$stats['total']],
      ['首次操作', $stats['first'] === null ? '—' : Clock::display($stats['first'], $timezone, $filter['from'] === $filter['to'] ? 'H:i:s' : 'm-d H:i')],
      ['最後操作', $stats['last'] === null ? '—' : Clock::display($stats['last'], $timezone, $filter['from'] === $filter['to'] ? 'H:i:s' : 'm-d H:i')],
      ['上午（00:00–11:59）', (string)$stats['morning']],
      ['下午（12:00–23:59）', (string)$stats['afternoon']],
  ];
  foreach ($cards as [$label, $value]): ?>
    <div class="col-6 col-md-4 col-lg">
      <div class="card shadow-sm h-100"><div class="card-body py-2">
        <div class="small text-body-secondary"><?= e($label) ?></div>
        <div class="fs-4 fw-semibold" data-stat="<?= e($label) ?>"><?= e($value) ?></div>
      </div></div>
    </div>
  <?php endforeach; ?>
</div>

<section class="card shadow-sm mb-3">
  <div class="card-header small fw-semibold">每小時操作分布（<?= e($filter['from']) ?><?= $filter['from'] !== $filter['to'] ? '～' . e($filter['to']) : '' ?>，<?= e($timezone) ?>）</div>
  <div class="card-body">
    <?php // 以 SVG 屬性繪製長條（CSP 禁止行內 style），每條附 <title> 提示數量 ?>
    <svg viewBox="0 0 480 130" class="w-100 hour-chart" role="img" aria-label="每小時操作次數長條圖">
      <?php foreach ($stats['hours'] as $h => $c): $bh = (int)round($c / $maxHour * 100); ?>
        <rect x="<?= $h * 20 + 2 ?>" y="<?= 105 - $bh ?>" width="16" height="<?= max($bh, $c > 0 ? 2 : 0) ?>" class="<?= $h < 12 ? 'bar-am' : 'bar-pm' ?>"><title><?= $h ?>:00–<?= $h ?>:59：<?= $c ?> 次</title></rect>
        <?php if ($h % 3 === 0): ?><text x="<?= $h * 20 + 10 ?>" y="122" text-anchor="middle" class="bar-label"><?= $h ?></text><?php endif; ?>
      <?php endforeach; ?>
      <line x1="0" y1="105.5" x2="480" y2="105.5" class="bar-axis"/>
    </svg>
  </div>
</section>

<section class="card shadow-sm">
  <div class="card-header small fw-semibold">紀錄明細（新到舊，每頁 50 筆）</div>
  <?php if ($items === []): ?>
    <div class="card-body text-body-secondary small">此條件下沒有任何操作紀錄。</div>
  <?php else: ?>
    <div class="d-none d-lg-flex row g-0 px-3 py-2 small text-body-secondary border-bottom">
      <div class="col-lg-2">時間</div><div class="col-lg-2">員工</div><div class="col-lg-2">操作</div>
      <div class="col-lg-2">相關專案</div><div class="col-lg-1">結果</div><div class="col-lg-3">說明</div>
    </div>
    <ul class="list-group list-group-flush">
      <?php foreach ($items as $row): ?>
        <li class="list-group-item small" data-log-row>
          <div class="row g-1">
            <div class="col-12 col-lg-2 text-body-secondary"><?= e(Clock::display((string)$row['occurred_at'], $timezone, 'Y-m-d H:i:s')) ?></div>
            <div class="col-6 col-lg-2 fw-semibold text-break"><?= e($row['actor_label']) ?></div>
            <div class="col-6 col-lg-2"><?= e(ActivityLabels::action((string)$row['action'])) ?> <span class="text-body-secondary">· <?= e(ActivityLabels::category((string)$row['category'])) ?></span></div>
            <div class="col-12 col-lg-2 text-break"><?= $row['project_label'] !== null ? e($row['project_label']) : '<span class="text-body-secondary">—</span>' ?></div>
            <div class="col-12 col-lg-1"><span class="badge <?= $resultClass[$row['result']] ?? 'text-bg-secondary' ?>"><?= e(ActivityLabels::result((string)$row['result'])) ?></span></div>
            <div class="col-12 col-lg-3 text-body-secondary text-break"><?= e($row['detail'] ?? '') ?></div>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($next !== null): ?>
      <div class="card-footer text-end">
        <a class="btn btn-sm btn-outline-secondary" href="/admin/logs?<?= e($queryString . ($queryString !== '' ? '&' : '') . 'before=' . (int)$next) ?>">更早的紀錄</a>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>
