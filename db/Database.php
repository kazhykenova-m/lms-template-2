<?php
require_once __DIR__ . '/../config.php';

class Database {
    private static ?PDO $instance = null;

    public static function get(): PDO {
        if (self::$instance === null) {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
            try {
            self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            } catch (PDOException $e) {
                error_log('[LMS database connection] '.$e->getMessage());
                $driverCode=(int)($e->errorInfo[1] ?? 0);
                if (PHP_SAPI!=='cli' && in_array($driverCode,[1040,1203,2002,2003],true)) {
                    http_response_code(503);
                    header('Retry-After: 30');
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok'=>false,'error'=>'База данных временно недоступна. Повторите действие через 30 секунд.','retry_after'=>30],JSON_UNESCAPED_UNICODE);
                    exit;
                }
                throw $e;
            }
        }
        return self::$instance;
    }

    public static function rollbackActive(): void {
        if (self::$instance !== null && self::$instance->inTransaction()) self::$instance->rollBack();
    }

    public static function query(string $sql, array $params = []): PDOStatement {
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function find(string $sql, array $params = []): ?array {
        $row = self::query($sql, $params)->fetch();
        return $row ?: null;
    }

    public static function findAll(string $sql, array $params = []): array {
        return self::query($sql, $params)->fetchAll();
    }

    public static function insert(string $table, array $data): int {
        $cols = implode(', ', array_keys($data));
        $vals = implode(', ', array_fill(0, count($data), '?'));
        self::query("INSERT INTO `$table` ($cols) VALUES ($vals)", array_values($data));
        return (int) self::get()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int {
        $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        $stmt = self::query("UPDATE `$table` SET $set WHERE $where", [...array_values($data), ...$whereParams]);
        return $stmt->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int {
        $stmt = self::query("DELETE FROM `$table` WHERE $where", $params);
        return $stmt->rowCount();
    }
}
