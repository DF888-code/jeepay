<?php

declare(strict_types=1);

namespace ProjectHub\Support;

/** 時間來源集中於此，資料庫一律寫入 UTC 字串。 */
final class Clock
{
    public static function now(): int
    {
        return time();
    }

    public static function utc(?int $timestamp = null): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp ?? self::now());
    }

    /** 以系統設定時區顯示 UTC 時間字串。 */
    public static function display(?string $utc, string $timezone, string $format = 'Y-m-d H:i'): string
    {
        if ($utc === null || $utc === '') {
            return '—';
        }
        try {
            $dt = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
            return $dt->setTimezone(new \DateTimeZone($timezone))->format($format);
        } catch (\Exception) {
            return '—';
        }
    }
}
