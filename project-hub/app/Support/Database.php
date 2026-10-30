<?php

declare(strict_types=1);

namespace ProjectHub\Support;

use PDO;
use PDOStatement;
use Throwable;

/**
 * PDO 薄包裝：強制參數化查詢、例外模式、UTC 時區。
 * 所有 SQL 都必須以參數傳值，動態欄位名只能來自程式內白名單。
 */
final class Database
{
    private ?PDO $pdo = null;

    /** @param array{host?:string,port?:int|string,name?:string,user?:string,pass?:string} $settings */
    public function __construct(private array $settings)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $this->settings['host'] ?? '',
                (int)($this->settings['port'] ?? 3306),
                $this->settings['name'] ?? ''
            );
            $this->pdo = new PDO($dsn, $this->settings['user'] ?? '', $this->settings['pass'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // 關閉模擬 prepare：讓資料庫端真正做參數綁定
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            // 所有 DATETIME 以 UTC 寫入與比較，避免受主機預設時區（America/New_York）影響
            $this->pdo->exec("SET time_zone = '+00:00'");
        }
        return $this->pdo;
    }

    /** @param array<int|string, mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @param array<int|string, mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /**
     * 在交易中執行；任何例外都會回滾並重新拋出。
     *
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($this);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
