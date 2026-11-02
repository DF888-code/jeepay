<?php

declare(strict_types=1);

namespace ProjectHub\Search;

/**
 * 以 OpenCC 字典進行詞彙級簡繁轉換（純 PHP，不需執行外部程式；主機已停用 exec）。
 *
 * strtr() 會優先比對最長的字串，因此「詞彙」會先於「單字」被轉換，
 * 符合規格「不可只使用單字元替換」的要求。
 */
final class ChineseConverter
{
    /** @var array<string, array<string, string>> */
    private static array $cache = [];

    /** 繁體（含台灣異體字）→ 簡體字形，不改變用詞。例如「資料庫」→「资料库」。 */
    public static function toSimplified(string $text): string
    {
        $text = strtr($text, self::dict('tw-variants', ['TWVariantsRevPhrases', 'TWVariantsRev']));
        return strtr($text, self::dict('t2s', ['TSPhrases', 'TSCharacters']));
    }

    /**
     * 台灣用語 → 大陸用語（輸入與輸出皆為標準化後的簡體字形）。例如「资料库」→「数据库」、「记忆体」→「内存」。
     * 以字形轉換後的形式比對，因此使用者不論以繁體或簡體字形輸入台灣用語都能對應。
     */
    public static function taiwanToMainland(string $simplified): string
    {
        return strtr($simplified, self::phraseMaps()['tw-to-mainland']);
    }

    /** 大陸用語 → 台灣用語（輸入與輸出皆為簡體字形）。例如「数据库」→「资料库」。 */
    public static function mainlandToTaiwan(string $simplified): string
    {
        return strtr($simplified, self::phraseMaps()['mainland-to-tw']);
    }

    /**
     * 由 TWPhrasesRev（台灣用語 → 大陸用語）產生兩個方向的對照表，兩個方向使用同一份資料，不會互相矛盾。
     * 只收錄至少兩個字的詞，避免單字被誤轉。
     *
     * @return array{tw-to-mainland: array<string, string>, mainland-to-tw: array<string, string>}
     */
    private static function phraseMaps(): array
    {
        if (!isset(self::$cache['tw-to-mainland'])) {
            $toMainland = [];
            $toTaiwan = [];
            foreach (self::dict('tw-phrases', ['TWPhrasesRev']) as $tw => $mainland) {
                $twS = self::toSimplified((string)$tw);
                $mainS = self::toSimplified((string)$mainland);
                if ($twS === $mainS || mb_strlen($twS) < 2 || mb_strlen($mainS) < 2) {
                    continue;
                }
                $toMainland[$twS] ??= $mainS;
                // 一個大陸用語對應多個台灣用語時保留第一個
                $toTaiwan[$mainS] ??= $twS;
            }
            self::$cache['tw-to-mainland'] = $toMainland;
            self::$cache['mainland-to-tw'] = $toTaiwan;
        }
        return ['tw-to-mainland' => self::$cache['tw-to-mainland'], 'mainland-to-tw' => self::$cache['mainland-to-tw']];
    }

    /**
     * @param list<string> $files
     * @return array<string, string>
     */
    private static function dict(string $key, array $files): array
    {
        if (!isset(self::$cache[$key])) {
            $merged = [];
            foreach ($files as $file) {
                /** @var array<string, string> $data */
                $data = require __DIR__ . '/opencc/' . $file . '.php';
                // 前面的檔案（詞彙）優先；strtr 本身也會優先使用最長的鍵
                $merged += $data;
            }
            self::$cache[$key] = $merged;
        }
        return self::$cache[$key];
    }
}
