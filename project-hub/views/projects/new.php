<div class="card shadow-sm mx-auto form-card">
  <div class="card-body p-4">
    <h1 class="h4 mb-4">建立專案</h1>
    <?php if (!empty($error)): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="/projects">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="mb-3">
        <label for="name" class="form-label">專案名稱</label>
        <input id="name" name="name" class="form-control" maxlength="200" value="<?= e($old['name'] ?? '') ?>" required autofocus>
      </div>
      <div class="mb-4">
        <label for="description" class="form-label">專案說明</label>
        <textarea id="description" name="description" class="form-control" rows="8" required><?= e($old['description'] ?? '') ?></textarea>
        <div class="form-text">專案可用於任何用途（開發專案、公司規章、教育訓練…）。之後每次修改說明都會保留完整版本歷史。</div>
      </div>
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">建立</button>
        <a href="/" class="btn btn-outline-secondary">取消</a>
      </div>
    </form>
  </div>
</div>
