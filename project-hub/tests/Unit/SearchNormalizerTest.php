<?php

declare(strict_types=1);

use ProjectHub\Search\SearchService;
use ProjectHub\Search\TextNormalizer;

test('標準化：全形轉半形、小寫、壓縮空白、詞彙級繁轉簡', function (): void {
    assert_same('api key', TextNormalizer::normalize('ＡＰＩ　　Key'));
    assert_same('资料库', TextNormalizer::normalize('資料庫'));
    assert_same('头发', TextNormalizer::normalize('頭髮'), '「髮」與「發」都轉為「发」，需靠詞彙判斷');
    assert_same('干净', TextNormalizer::normalize('乾淨'));
    assert_same('里面', TextNormalizer::normalize('裡面'), '台灣異體字');
});

test('搜尋寫法展開：兩岸用語雙向對應', function (): void {
    assert_true(in_array('数据库', TextNormalizer::variants('資料庫'), true));
    assert_true(in_array('资料库', TextNormalizer::variants('数据库'), true));
    assert_true(in_array('软件', TextNormalizer::variants('軟體'), true));
    assert_true(in_array('软体', TextNormalizer::variants('软件'), true));
    assert_true(in_array('项目', TextNormalizer::variants('专案'), true), '簡體字形的台灣用語也能對應');
    assert_same([], TextNormalizer::variants('   '));
});

test('簡體原文不會被台灣用語轉換改寫：儲存的標準形式只做字形轉換', function (): void {
    assert_same('程序', TextNormalizer::normalize('程序'));
    assert_true(in_array('程序', TextNormalizer::variants('程序'), true), '原寫法一定在比對清單中');
});

test('關鍵字同一概念判斷', function (): void {
    $n = [TextNormalizer::class, 'normalize'];
    assert_true(TextNormalizer::sameConcept($n('資料庫'), $n('数据库')));
    assert_true(TextNormalizer::sameConcept($n('記憶體'), $n('内存')));
    assert_true(TextNormalizer::sameConcept($n('ＡＰＩ'), $n('api')));
    assert_true(!TextNormalizer::sameConcept($n('資料庫'), $n('伺服器')));
});

test('搜尋摘要：回傳原文片段並分開標示符合處（由畫面負責跳脫）', function (): void {
    [$before, $match, $after] = SearchService::snippet('前面的文字 <b>資料庫</b> 連線錯誤', ['数据库', '资料库']);
    assert_same('資料庫', $match);
    assert_contains('<b>', $before, '原文保留，跳脫交由樣板處理');
    assert_contains('連線錯誤', $after);
});
