<?php
declare(strict_types=1);

// 직접 접근 차단
if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

// MariaDB / MySQL 연결 설정
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_USER', 'root');
define('DB_PASS', 'epson21@!@!');
define('DB_NAME', 'workervoice');
define('DB_CHARSET', 'utf8mb4');

/**
 * PDO 데이터베이스 커넥션 싱글톤 반환
 */
function getDb(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // DB가 아직 없을 경우 DB명 없이 접속하여 생성 가능하게 함
            $dsnWithoutDb = sprintf('mysql:host=%s;port=%s;charset=%s', DB_HOST, DB_PORT, DB_CHARSET);
            $pdo = new PDO($dsnWithoutDb, DB_USER, DB_PASS, $options);
        }
    }
    return $pdo;
}
