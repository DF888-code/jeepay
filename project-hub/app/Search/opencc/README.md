# OpenCC 字典資料

本目錄的字典取自 [OpenCC](https://github.com/BYVoid/OpenCC)（Apache License 2.0），
使用 [overtrue/php-opencc](https://github.com/overtrue/php-opencc) 1.3.1（MIT，見 `LICENSE-php-opencc.txt`）已轉換為 PHP 陣列的版本。

只保留搜尋標準化需要的 5 個檔案，避免引入整個套件（含命令列工具等上千個檔案，會占用主機檔案數上限）：

| 檔案 | 用途 |
|---|---|
| `TSPhrases.php`、`TSCharacters.php` | 繁體 → 簡體（詞彙優先，再逐字） |
| `TWVariantsRevPhrases.php`、`TWVariantsRev.php` | 台灣異體字 → 標準繁體 |
| `TWPhrasesRev.php` | 台灣用語 → 大陸用語（例如「資料庫」→「数据库」），反向對照表由程式在執行時產生 |

更新字典時，以新版本的同名檔案覆蓋，並到管理後台重建搜尋索引。
