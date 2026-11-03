<?php

declare(strict_types=1);

namespace ProjectHub\Settings;

use ProjectHub\Support\Clock;
use ProjectHub\Support\Database;
use RuntimeException;
use Throwable;

/**
 * 讀取系統設定：資料庫只存覆寫值，未覆寫或值不合法時使用定義檔預設值。
 */
final class Settings
{
    /** @var array<string, mixed>|null */
    private ?array $overrides = null;

    public function __construct(private Database $db)
    {
    }

    public function get(string $key): mixed
    {
        $defs = SettingsRegistry::definitions();
        if (!isset($defs[$key])) {
            throw new RuntimeException('未定義的設定：' . $key);
        }
        $def = $defs[$key];
        $overrides = $this->overrides();
        if (array_key_exists($key, $overrides)) {
            $valid = self::validate($def, $overrides[$key]);
            if ($valid !== null) {
                return $valid;
            }
            error_log('[project-hub] 設定值不合法，已改用預設值：' . $key);
        }
        return $def['default'];
    }

    public function int(string $key): int
    {
        return (int)$this->get($key);
    }

    public function bool(string $key): bool
    {
        return (bool)$this->get($key);
    }

    public function string(string $key): string
    {
        return (string)$this->get($key);
    }

    /**
     * 依定義驗證並轉型；不合法回傳 null。
     *
     * @param array{type:string,min?:int,max?:int,options?:array<string,string>,format?:string} $def
     */
    public static function validate(array $def, mixed $value): mixed
    {
        switch ($def['type']) {
            case 'enum':
                return is_string($value) && array_key_exists($value, $def['options'] ?? []) ? $value : null;
            case 'int':
                if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
                    $value = (int)$value;
                }
                if (!is_int($value)) {
                    return null;
                }
                if ((isset($def['min']) && $value < $def['min']) || (isset($def['max']) && $value > $def['max'])) {
                    return null;
                }
                return $value;
            case 'string':
                if (!is_string($value) || (isset($def['max']) && mb_strlen($value) > $def['max'])
                    || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                    // 不允許換行等控制字元：設定值可能被放進信件標頭，換行會造成標頭注入
                    return null;
                }
                if ($value !== '' && isset($def['format']) && !self::validFormat($def['format'], $value)) {
                    return null;
                }
                return $value;
            case 'bool':
                if (is_bool($value)) {
                    return $value;
                }
                return in_array($value, [0, 1, '0', '1'], true) ? (bool)(int)$value : null;
            case 'timezone':
                return is_string($value) && in_array($value, \DateTimeZone::listIdentifiers(), true) ? $value : null;
            default:
                return null;
        }
    }

    private static function validFormat(string $format, string $value): bool
    {
        return match ($format) {
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'host' => (bool)preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/', $value),
            'google_client_id' => (bool)preg_match('/^[A-Za-z0-9._-]{10,200}$/', $value),
            'hex32' => (bool)preg_match('/^[a-f0-9]{32}$/', $value),
            'bucket' => (bool)preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/', $value),
            default => false,
        };
    }

    /**
     * 儲存設定：與預設值相同時刪除覆寫列，讓日後調整預設值能自動套用。
     * 呼叫端必須先以 validate() 驗證。
     */
    public function set(string $key, mixed $value, ?int $userId): void
    {
        $def = SettingsRegistry::definitions()[$key] ?? throw new RuntimeException('未定義的設定：' . $key);
        $this->overrides();
        if ($value === $def['default']) {
            $this->db->run('DELETE FROM system_settings WHERE setting_key = ?', [$key]);
            unset($this->overrides[$key]);
            return;
        }
        $this->db->run(
            'REPLACE INTO system_settings (setting_key, value_json, updated_by, updated_at) VALUES (?, ?, ?, ?)',
            [$key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $userId, Clock::utc()]
        );
        $this->overrides[$key] = $value;
    }

    /** @return array<string, mixed> */
    private function overrides(): array
    {
        if ($this->overrides === null) {
            $this->overrides = [];
            try {
                foreach ($this->db->all('SELECT setting_key, value_json FROM system_settings') as $row) {
                    $this->overrides[(string)$row['setting_key']] = json_decode((string)$row['value_json'], true);
                }
            } catch (Throwable) {
                // 尚未安裝（資料表不存在）時使用預設值
            }
        }
        return $this->overrides;
    }
}
