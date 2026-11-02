<?php

declare(strict_types=1);

namespace ProjectHub\Search;

/**
 * 搜尋與關鍵字比對用的標準化。原文欄位永遠保留使用者輸入，*_norm 欄位只用於比對。
 *
 * 寫入資料庫的標準形式：NFKC（全形英數轉半形）→ 小寫 → 壓縮空白 → 繁轉簡字形（詞彙級 OpenCC）。
 *
 * 為什麼查詢時要展開成多種寫法（variants），而不是把資料也轉成大陸用語：
 * 台灣用語轉大陸用語並非可逆。例如簡體「程序」在台灣用語轉換時會被當成「進程」，
 * 若直接用來改寫儲存的資料，原本簡體的「程序」就再也搜尋不到。
 * 因此資料只存字形轉換結果，查詢時同時比對「原字形」「台灣→大陸用語」「大陸→台灣用語」三種寫法。
 */
final class TextNormalizer
{
    /**
     * 標準化規則版本。規則或字典變更時遞增，管理後台會提示重建搜尋索引。
     * 1：NFKC＋小寫（階段 5、6）；2：加入 OpenCC 繁轉簡（階段 7）。
     */
    public const VERSION = 2;

    public static function normalize(string $text): string
    {
        return ChineseConverter::toSimplified(self::basic($text));
    }

    /**
     * 搜尋關鍵字的所有比對寫法（皆為標準形式，可直接與 *_norm 欄位比對）。
     *
     * @return list<string>
     */
    public static function variants(string $text): array
    {
        $base = self::normalize($text);
        if ($base === '') {
            return [];
        }
        $list = [
            $base,
            ChineseConverter::taiwanToMainland($base),
            ChineseConverter::mainlandToTaiwan($base),
        ];
        return array_values(array_unique(array_filter($list, static fn(string $v): bool => $v !== '')));
    }

    /**
     * 判斷兩個關鍵字是否為同一概念（用於避免「資料庫」與「数据库」重複建立）。
     * 兩者標準形式相同，或其中一方經用語對照後等於另一方，即視為相同。
     */
    public static function sameConcept(string $normA, string $normB): bool
    {
        return $normA === $normB
            || ChineseConverter::mainlandToTaiwan($normA) === $normB
            || ChineseConverter::mainlandToTaiwan($normB) === $normA
            || ChineseConverter::taiwanToMainland($normA) === $normB
            || ChineseConverter::taiwanToMainland($normB) === $normA;
    }

    /** 移除不可見的控制字元（保留換行與 Tab），避免貼上的文字夾帶怪異字元。 */
    public static function clean(string $text): string
    {
        $text = str_replace("\r\n", "\n", $text);
        return (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    }

    private static function basic(string $text): string
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
}
