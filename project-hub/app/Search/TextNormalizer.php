<?php

declare(strict_types=1);

namespace ProjectHub\Search;

/**
 * 搜尋與關鍵字比對用的標準化（寫入 *_norm 欄位與查詢時共用）。
 *
 * 目前：NFKC（全形英數轉半形）→ 小寫 → 壓縮空白。
 * 階段 7 會加入 OpenCC 詞彙級繁轉簡，屆時以重建工具重新計算所有 *_norm 欄位；
 * 原文欄位永遠保留使用者輸入，不受標準化影響。
 */
final class TextNormalizer
{
    public static function normalize(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($text, \Normalizer::FORM_KC);
            if (is_string($normalized)) {
                $text = $normalized;
            }
        }
        $text = mb_strtolower($text, 'UTF-8');
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    /** 移除不可見的控制字元（保留換行與 Tab），避免貼上的文字夾帶怪異字元。 */
    public static function clean(string $text): string
    {
        $text = str_replace("\r\n", "\n", $text);
        return (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    }
}
