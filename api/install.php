<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDb();

    // 1. 스키마 파일 실행
    $schemaFile = __DIR__ . '/../sql/01_schema.sql';
    if (!file_exists($schemaFile)) {
        throw new Exception('01_schema.sql 파일을 찾을 수 없습니다.');
    }
    $schemaSql = file_get_contents($schemaFile);
    $pdo->exec($schemaSql);

    // 2. 시드 데이터 파일 실행
    $seedFile = __DIR__ . '/../sql/02_seed_data.sql';
    if (!file_exists($seedFile)) {
        throw new Exception('02_seed_data.sql 파일을 찾을 수 없습니다.');
    }
    $seedSql = file_get_contents($seedFile);
    $pdo->exec($seedSql);

    // 3. 상태 확인
    $stmtSites = $pdo->query('SELECT COUNT(*) FROM `tb_sites`');
    $siteCount = (int)$stmtSites->fetchColumn();

    $stmtMasters = $pdo->query('SELECT COUNT(*) FROM `tb_masters`');
    $masterCount = (int)$stmtMasters->fetchColumn();

    echo json_encode([
        'ok'          => true,
        'message'     => 'Worker Voice 데이터베이스 및 테이블이 성공적으로 설치되었습니다.',
        'site_count'  => $siteCount,
        'master_count'=> $masterCount,
        'now'         => nowString()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $t) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => $t->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
