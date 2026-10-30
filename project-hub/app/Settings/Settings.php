<?php

declare(strict_types=1);

namespace ProjectHub\Settings;

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

    public function string(string $key): string
    {
        return (string)$this->get($key);
    }

    /**
     * 依定義驗證並轉型；不合法回傳 null。
     *
     * @param array{type:string,min?:int,max?:int} $def
     */
    public static function validate(array $def, mixed $value): mixed
    {
        switch ($def['type']) {
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
                if (!is_string($value) || (isset($def['max']) && mb_strlen($value) > $def['max'])) {
                    return null;
                }
                return $value;
            case 'timezone':
                return is_string($value) && in_array($value, \DateTimeZone::listIdentifiers(), true) ? $value : null;
            default:
                return null;
        }
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
