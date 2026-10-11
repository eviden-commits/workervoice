<?php
/**
 * 세방테크 Worker Voice - MySQL 데이터베이스 덤프 백업 모듈
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/db_config.php';

define('BACKUP_SECRET_TOKEN', 'sebangtec_backup_token_2026_9981');

$token = $_GET['token'] ?? $_POST['token'] ?? '';
if ($token !== BACKUP_SECRET_TOKEN) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => '접근 권한이 없습니다 (유효하지 않은 토큰)'], JSON_UNESCAPED_UNICODE);
    exit;
}

date_default_timezone_set('Asia/Seoul');

try {
    $pdo = getDb();
    $dbName = DB_NAME;

    $now = date('Y-m-d H:i:s');
    $dateFile = date('Ymd_His');
    $fileName = "backup_{$dbName}_{$dateFile}.sql";

    header('Content-Type: application/sql; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$fileName}\"");
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    echo "-- ============================================================\n";
    echo "-- 세방테크 Worker Voice MySQL 자동 백업 덤프\n";
    echo "-- 데이터베이스: {$dbName}\n";
    echo "-- 백업 일시: {$now}\n";
    echo "-- ============================================================\n\n";

    echo "SET FOREIGN_KEY_CHECKS=0;\n";
    echo "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
    echo "SET NAMES utf8mb4;\n\n";

    $tablesStmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        echo "-- ------------------------------------------------------------\n";
        echo "-- 테이블 구조 및 데이터: `{$table}`\n";
        echo "-- ------------------------------------------------------------\n";
        echo "DROP TABLE IF EXISTS `{$table}`;\n";

        $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
        $row = $createStmt->fetch(PDO::FETCH_ASSOC);
        $createSql = $row['Create Table'] ?? '';
        echo $createSql . ";\n\n";

        $rowsStmt = $pdo->query("SELECT * FROM `{$table}`");
        $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($rows)) {
            $cols = array_keys($rows[0]);
            $colNames = implode('`, `', $cols);

            echo "INSERT INTO `{$table}` (`{$colNames}`) VALUES\n";

            $valueLines = [];
            foreach ($rows as $r) {
                $vals = [];
                foreach ($cols as $col) {
                    $v = $r[$col];
                    if ($v === null) {
                        $vals[] = 'NULL';
                    } elseif (is_numeric($v)) {
                        $vals[] = $v;
                    } else {
                        $vals[] = $pdo->quote((string)$v);
                    }
                }
                $valueLines[] = '(' . implode(', ', $vals) . ')';
            }
            echo implode(",\n", $valueLines) . ";\n\n";
        }
    }

    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    echo "-- 백업 완료: " . date('Y-m-d H:i:s') . "\n";

} catch (Throwable $t) {
    http_response_code(500);
    echo "-- 백업 실패: " . $t->getMessage() . "\n";
}
