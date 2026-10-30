<?php

declare(strict_types=1);

namespace ProjectHub\Settings;

/**
 * 系統設定定義檔：每個設定的分類、型別、預設值與允許範圍都在這裡定義。
 * 新功能只要在此新增定義，階段 9 的系統設定中心就會自動出現對應欄位。
 */
final class SettingsRegistry
{
    /**
     * @return array<string, array{category:string,label:string,type:string,default:mixed,min?:int,max?:int,help?:string}>
     */
    public static function definitions(): array
    {
        return [
            'system.site_name' => [
                'category' => 'system', 'label' => '網站名稱', 'type' => 'string', 'default' => 'Project Hub',
                'max' => 50,
            ],
            'system.timezone' => [
                'category' => 'system', 'label' => '顯示時區', 'type' => 'timezone', 'default' => 'Asia/Taipei',
                'help' => '資料庫一律存 UTC，畫面依此時區顯示；歷史紀錄的上午／下午統計也依此時區計算。',
            ],
            'security.login_max_failures' => [
                'category' => 'security', 'label' => '同一帳號連續失敗上限', 'type' => 'int', 'default' => 5,
                'min' => 3, 'max' => 20, 'help' => '密碼與 2FA 驗證碼錯誤合併計算，達上限後暫時鎖定。',
            ],
            'security.login_ip_max_failures' => [
                'category' => 'security', 'label' => '同一 IP 失敗上限', 'type' => 'int', 'default' => 30,
                'min' => 5, 'max' => 500,
            ],
            'security.login_lock_minutes' => [
                'category' => 'security', 'label' => '鎖定時間（分鐘）', 'type' => 'int', 'default' => 15,
                'min' => 1, 'max' => 1440,
            ],
            'security.totp_window' => [
                'category' => 'security', 'label' => 'TOTP 時間容許範圍（前後各幾個 30 秒）', 'type' => 'int',
                'default' => 1, 'min' => 0, 'max' => 2,
            ],
            'security.pre_auth_minutes' => [
                'category' => 'security', 'label' => '密碼驗證後完成 2FA 的時限（分鐘）', 'type' => 'int',
                'default' => 10, 'min' => 2, 'max' => 30,
            ],
            'security.session_idle_minutes' => [
                'category' => 'security', 'label' => '閒置自動登出（分鐘）', 'type' => 'int', 'default' => 120,
                'min' => 5, 'max' => 1440,
            ],
            'security.session_absolute_hours' => [
                'category' => 'security', 'label' => '登入最長有效時間（小時）', 'type' => 'int', 'default' => 12,
                'min' => 1, 'max' => 168,
            ],
            'security.password_min_length' => [
                'category' => 'security', 'label' => '密碼最短長度', 'type' => 'int', 'default' => 10,
                'min' => 8, 'max' => 64,
            ],
        ];
    }
}
