# AGENTS.md：AI 助手工作規範

> ChatGPT（Codex）、Cursor、Claude 等 AI 助手修改本專案前請先閱讀。
> 完整背景與陷阱清單見 `docs/10-maintenance-handbook.md`。

## 專案
- 多人使用的專案管理與知識紀錄平台；PHP 8.1+（正式主機 8.4），**不使用框架**，PSR-4 命名空間 `ProjectHub\` 對應 `app/`。
- 資料庫 MySQL／MariaDB，PDO 參數化查詢（關閉模擬 prepare）。所有時間以 UTC 存放，顯示時依系統時區（預設 Asia/Taipei）。
- 部署在 InfinityFree 免費主機：沒有命令列、沒有排程、單一請求 60 秒、單檔 10 MB。

## 語言
- 介面文字、程式註解、文件、Git 提交訊息一律使用**繁體中文**。
- 註解寫「為什麼這樣做」與「注意事項」，不寫無意義的註解。

## 不可違反
1. 專案相關的讀寫一律經 `Authorizer::can()`（全域權限且專案權限，編輯／刪除也要有查看）；列表與搜尋用 `Authorizer::visibleCondition()`。
2. 無權限與不存在回應相同（404）。不可用前端隱藏取代後端檢查。
3. 密碼、驗證碼、Token、金鑰絕不寫入紀錄、錯誤訊息或畫面。機密設定用 `SecretSettings`（加密）。
4. CSP：不可使用 inline script、inline style、`on*=` 事件屬性；樣板輸出一律 `e()`；JS 寫入使用者內容用 `textContent`。
5. 狀態變更只用 POST（CSRF 自動檢查），成功後 303 轉址；Turbo 下 POST 不可回 200 HTML。
6. 遷移檔只能新增（`database/migrations/000N_*.sql`），字串與 COMMENT 內不可有半形分號；不可修改已發佈的遷移檔。
7. 破壞性操作需確認；不可刪除使用者資料。
8. 改變既有行為前，先查 `docs/06-open-questions.md` 的決議；新的決定要補進該文件。

## 完成一項修改前
- 執行測試並確認全部通過：
  ```bash
  php tests/run.php
  PH_TEST_DB_HOST=127.0.0.1 PH_TEST_DB_NAME=ph_it_test PH_TEST_DB_USER=... PH_TEST_DB_PASS=... php tests/run.php
  ```
  （整合測試會清空該資料庫，名稱必須含 `test`；外部服務使用 `tests/Fakes/` 的假服務，不需連網。）
- 新功能補測試；新路由會被 `tests/Integration/SecurityTest.php` 自動掃描未登入、越權與 CSRF。
- 更新 `VERSION`、`CHANGELOG.md`，必要時更新 `docs/`。
- 產生部署包：`composer install --no-dev && php tools/build-deploy.php`。
