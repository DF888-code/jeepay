<?php

declare(strict_types=1);

if (!function_exists('e')) {
    /** HTML 輸出跳脫。樣板中所有動態值都必須經過此函式。 */
    function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
