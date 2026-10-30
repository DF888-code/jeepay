<?php

declare(strict_types=1);

namespace ProjectHub\Support;

use RuntimeException;

/**
 * 極簡 PHP 樣板。樣板內一律使用 e() 輸出；只有程式產生的可信 HTML（例如 QR Code SVG）才可原樣輸出。
 */
final class View
{
    /** @param array<string, mixed> $shared */
    public function __construct(private string $dir, private array $shared = [])
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @param array<string, mixed> $vars */
    public function render(string $template, array $vars = [], ?string $layout = 'layout'): string
    {
        $content = $this->renderFile($template, $vars);
        if ($layout === null) {
            return $content;
        }
        return $this->renderFile($layout, $vars + ['content' => $content]);
    }

    /** @param array<string, mixed> $vars */
    private function renderFile(string $template, array $vars): string
    {
        if (!preg_match('#^[a-z0-9_/]+$#', $template)) {
            throw new RuntimeException('樣板名稱不合法');
        }
        $file = $this->dir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('找不到樣板');
        }
        $data = $vars + $this->shared;
        return (static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            try {
                require $__file;
                return (string)ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
        })($file, $data);
    }
}
