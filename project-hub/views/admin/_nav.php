<?php $active = $active ?? ''; ?>
<h1 class="h3 mb-3">管理後台</h1>
<ul class="nav nav-tabs mb-4">
  <li class="nav-item"><a class="nav-link<?= $active === 'users' ? ' active' : '' ?>" href="/admin/users"<?= $active === 'users' ? ' aria-current="page"' : '' ?>>使用者管理</a></li>
  <li class="nav-item"><a class="nav-link<?= $active === 'permissions' ? ' active' : '' ?>" href="/admin/permissions"<?= $active === 'permissions' ? ' aria-current="page"' : '' ?>>全域權限</a></li>
  <li class="nav-item"><a class="nav-link<?= $active === 'projects' ? ' active' : '' ?>" href="/admin/projects"<?= $active === 'projects' ? ' aria-current="page"' : '' ?>>專案管理</a></li>
  <li class="nav-item"><a class="nav-link<?= $active === 'logs' ? ' active' : '' ?>" href="/admin/logs"<?= $active === 'logs' ? ' aria-current="page"' : '' ?>>歷史操作紀錄</a></li>
  <li class="nav-item"><a class="nav-link<?= $active === 'settings' ? ' active' : '' ?>" href="/admin/settings"<?= $active === 'settings' ? ' aria-current="page"' : '' ?>>系統設定</a></li>
  <li class="nav-item"><a class="nav-link<?= $active === 'errors' ? ' active' : '' ?>" href="/admin/errors"<?= $active === 'errors' ? ' aria-current="page"' : '' ?>>系統錯誤</a></li>
</ul>
