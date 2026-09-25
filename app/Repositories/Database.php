<?php
declare(strict_types=1);
namespace App\Repositories;
use PDO;
final class Database
{
    private PDO $pdo;
    public function __construct() {
        $this->pdo = new PDO(getenv('DB_DSN') ?: 'sqlite:' . BASE_PATH . '/storage/panel.sqlite', getenv('DB_USER') ?: null, getenv('DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        if ($this->driver() === 'sqlite') { $this->pdo->exec('PRAGMA foreign_keys=ON'); $this->pdo->exec('PRAGMA busy_timeout=10000'); $this->pdo->exec('PRAGMA journal_mode=WAL'); }
        else $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }
    public function driver(): string { return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME); }
    public function exec(string $sql): void { $this->pdo->exec($sql); }
    public function query(string $sql, array $params = []): \PDOStatement { $s = $this->pdo->prepare($sql); $s->execute($params); return $s; }
    public function all(string $sql, array $params = []): array { return $this->query($sql, $params)->fetchAll(); }
    public function one(string $sql, array $params = []): ?array { return $this->query($sql, $params)->fetch() ?: null; }
    public function scalar(string $sql, array $params = []): mixed { return $this->query($sql, $params)->fetchColumn(); }
    public function insert(string $table, array $data): int {
        if (!preg_match('/^[a-z_]+$/', $table)) throw new \LogicException('Invalid table');
        $cols = array_keys($data);
        foreach ($cols as $col) if (!preg_match('/^[a-z_]+$/', $col)) throw new \LogicException('Invalid column');
        $this->query('INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($data));
        return (int)$this->pdo->lastInsertId();
    }
    public function transaction(callable $fn): mixed {
        if ($this->driver() === 'sqlite') $this->pdo->exec('BEGIN IMMEDIATE'); else $this->pdo->beginTransaction();
        try { $result = $fn(); if ($this->driver() === 'sqlite') $this->pdo->exec('COMMIT'); else $this->pdo->commit(); return $result; }
        catch (\Throwable $e) { if ($this->driver() === 'sqlite') $this->pdo->exec('ROLLBACK'); elseif ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }
    public function lockTenant(int $tenant): void { $this->query('SELECT id FROM tenants WHERE id=?' . ($this->driver() === 'mysql' ? ' FOR UPDATE' : ''), [$tenant]); }
}
