<?php
if (!defined('IN_APP')) {
    exit('Access Denied');
}

class DB {
    private static $pdo = null;

    public static function connect() {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        // 数据库配置
        $host = 'mhdlmskp2kpxguj.mysql.db'; // 或 localhost
        $db   = 'mhdlmskp2kpxguj'; // 请修改为实际数据库名
        $user = 'mhdlmskp2kpxguj';       // 请修改为实际用户名
        $pass = 'BWNrmksqMEqgbX37r3QNDJLGRrUka';   // 请修改为实际密码
        $charset = 'utf8mb4';

        $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            self::$pdo = new PDO($dsn, $user, $pass, $options);
            return self::$pdo;
        } catch (\PDOException $e) {
            // 生产环境不应直接输出详细错误，这里为了调试先保留
            throw new \PDOException($e->getMessage(), (int)$e->getCode());
        }
    }
}