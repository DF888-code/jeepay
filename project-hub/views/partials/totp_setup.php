<?php /** QR Code 由伺服器以程式產生（不含使用者輸入的 HTML），因此可直接輸出 SVG。 */ ?>
<ol class="steps">
  <li>在手機安裝並開啟 <strong>Google Authenticator</strong>。</li>
  <li>點「＋」→「掃描 QR 圖碼」，掃描下方 QR Code。</li>
  <li>輸入 App 中「<?= e($accountLabel) ?>」顯示的 6 位數驗證碼。</li>
</ol>
<div class="qr"><?= $qrSvg ?></div>
<details class="manual-key">
  <summary>無法掃描？改用手動輸入金鑰</summary>
  <p class="small">在 App 中選「輸入設定金鑰」，帳戶名稱可自訂，金鑰如下（類型選「根據時間」）：</p>
  <code id="totp-secret" class="secret"><?= e($secretText) ?></code>
</details>
<p class="muted small">本頁的 QR Code 與金鑰 10 分鐘內有效；請勿截圖或分享給他人。</p>
