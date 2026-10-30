<?php

declare(strict_types=1);

/**
 * 極簡測試工具：不依賴 PHPUnit，方便在任何有 PHP 的環境執行。
 * 測試依註冊順序執行（整合測試的步驟彼此相依）。
 */

final class AssertionFailed extends RuntimeException
{
}

final class TestRegistry
{
    /** @var list<array{string, callable}> */
    public static array $tests = [];
}

function test(string $name, callable $fn): void
{
    TestRegistry::$tests[] = [$name, $fn];
}

function assert_true(mixed $cond, string $message = '預期條件成立'): void
{
    if ($cond !== true) {
        throw new AssertionFailed($message);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(($message !== '' ? $message . '：' : '')
            . '預期 ' . var_export($expected, true) . '，實際 ' . var_export($actual, true));
    }
}

function assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new AssertionFailed(($message !== '' ? $message . '：' : '') . '找不到「' . $needle . '」');
    }
}

function assert_not_contains(string $needle, string $haystack, string $message = ''): void
{
    if (str_contains($haystack, $needle)) {
        throw new AssertionFailed(($message !== '' ? $message . '：' : '') . '不應出現「' . $needle . '」');
    }
}

/** @return int 失敗數 */
function run_registered_tests(string $suite): int
{
    $pass = 0;
    $fail = 0;
    echo "== {$suite} ==\n";
    foreach (TestRegistry::$tests as [$name, $fn]) {
        try {
            $fn();
            $pass++;
            echo "  [PASS] {$name}\n";
        } catch (Throwable $e) {
            $fail++;
            echo "  [FAIL] {$name}\n         " . $e->getMessage() . "\n";
        }
    }
    TestRegistry::$tests = [];
    echo "  小計：{$pass} 通過，{$fail} 失敗\n\n";
    return $fail;
}
