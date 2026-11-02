<?php $active = $active ?? ''; ?>
<h1 class="h3 mb-3">管理後台</h1>
<ul class="nav nav-tabs mb-4">
  <li class="nav-item"><a class="nav-link<?= $active === 'users' ? ' active' : '' ?>" href="/admin/users"<?= $active === 'users' ? ' aria-current="page"' : '' ?>>使用者管理</a></li>
  <li class="nav-item"><a class="nav-link<?= $active === 'permissions' ? ' active' : '' ?>" href="/admin/permissions"<?= $active === 'permissions' ? ' aria-current="page"' : '' ?>>全域權限</a></li>
</ul>
