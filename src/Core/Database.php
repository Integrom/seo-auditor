<?php
namespace SeoAuditor\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo === null) {
            $cfg = Config::get('db');
            $dsn = "mysql:host={$cfg['host']};dbname={$cfg['dbname']};charset={$cfg['charset']}";
            self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pdo;
    }

    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = implode(',', array_map(fn($k) => "`$k`", array_keys($data)));
        $placeholders = implode(',', array_map(fn($k) => ":$k", array_keys($data)));
        self::query("INSERT INTO `$table` ($cols) VALUES ($placeholders)", $data);
        return (int) self::get()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): void
    {
        $set = implode(',', array_map(fn($k) => "`$k`=:$k", array_keys($data)));
        self::query("UPDATE `$table` SET $set WHERE $where", array_merge($data, $whereParams));
    }
}
