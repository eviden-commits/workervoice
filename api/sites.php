<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/auth.php';

/**
 * 1. 활성 현장 이름 목록 반환
 */
function handleListSites(): array {
    $pdo = getDb();
    $stmt = $pdo->query('SELECT `site_name` FROM `tb_sites` WHERE `active` = 1 ORDER BY `site_name` ASC');
    $items = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return ['ok' => true, 'items' => $items];
}

/**
 * 2. 관리자 이메일 관리 목록 조회
 */
function handleListSiteEmails(array $params): array {
    $contactEmail = strtolower(safeText($params['contact_email'] ?? ''));
    $otp = safeText($params['site_otp'] ?? '');

    if ($contactEmail === '' || !isValidEmail($contactEmail)) {
        throw new Exception('유효한 관리자 이메일이 필요합니다.');
    }
    if (!verifySiteOtp($contactEmail, $otp)) {
        throw new Exception('인증 코드가 올바르지 않거나 만료되었습니다.');
    }

    $isMaster = isMasterEmail($contactEmail);
    $allowedSites = getAllowedSitesByEmail($contactEmail);

    if (empty($allowedSites)) {
        throw new Exception('등록된 권한이 없는 계정입니다.');
    }

    $pdo = getDb();

    // 마스터는 전체 현장, 일반 관리자는 본인 현장만
    if ($isMaster) {
        $stmt = $pdo->query('SELECT `site_name` FROM `tb_sites` WHERE `active` = 1 ORDER BY `site_name` ASC');
        $targetSites = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $targetSites = $allowedSites;
    }

    // 각 현장별 이메일 매핑 조회
    $stmtEmails = $pdo->query('SELECT `site_name`, `email` FROM `tb_site_emails` ORDER BY `site_name` ASC, `id` ASC');
    $allEmails = $stmtEmails->fetchAll(PDO::FETCH_ASSOC);

    $siteEmailMap = [];
    foreach ($allEmails as $row) {
        $s = $row['site_name'];
        if (!isset($siteEmailMap[$s])) {
            $siteEmailMap[$s] = [];
        }
        $siteEmailMap[$s][] = $row['email'];
    }

    $items = [];
    foreach ($targetSites as $siteName) {
        $items[] = [
            'site_name' => $siteName,
            'emails'    => $siteEmailMap[$siteName] ?? []
        ];
    }

    return [
        'ok'        => true,
        'is_master' => $isMaster,
        'items'     => $items
    ];
}

/**
 * 3. 관리자 이메일 추가/제거
 */
function handleAdminManageEmails(array $params): array {
    $contactEmail = strtolower(safeText($params['contact_email'] ?? ''));
    $otp = safeText($params['site_otp'] ?? '');
    $op = safeText($params['op'] ?? ''); // 'add' | 'remove'
    $siteName = safeText($params['site_name'] ?? '');
    $targetEmail = strtolower(safeText($params['target_email'] ?? ''));

    if ($contactEmail === '' || !isValidEmail($contactEmail)) {
        throw new Exception('유효한 관리자 이메일이 필요합니다.');
    }
    if (!verifySiteOtp($contactEmail, $otp)) {
        throw new Exception('인증 코드가 올바르지 않거나 만료되었습니다.');
    }
    if ($op !== 'add' && $op !== 'remove') {
        throw new Exception('잘못된 작업 요청(op)입니다.');
    }
    if ($siteName === '') {
        throw new Exception('현장명이 필요합니다.');
    }
    if ($targetEmail === '' || !isValidEmail($targetEmail)) {
        throw new Exception('올바른 대상 이메일 주소를 입력해 주세요.');
    }

    $isMaster = isMasterEmail($contactEmail);
    $allowedSites = getAllowedSitesByEmail($contactEmail);

    if (!$isMaster && !in_array($siteName, $allowedSites, true)) {
        throw new Exception('해당 현장에 대한 관리 권한이 없습니다.');
    }

    $pdo = getDb();

    if ($op === 'add') {
        $stmt = $pdo->prepare('
            INSERT IGNORE INTO `tb_site_emails` (`site_name`, `email`, `created_at`)
            VALUES (?, ?, NOW())
        ');
        $stmt->execute([$siteName, $targetEmail]);
    } else {
        $stmt = $pdo->prepare('
            DELETE FROM `tb_site_emails` 
            WHERE `site_name` = ? AND LOWER(`email`) = ?
        ');
        $stmt->execute([$siteName, $targetEmail]);
    }

    // 최신 이메일 목록 반환
    $stmtLatest = $pdo->prepare('SELECT `email` FROM `tb_site_emails` WHERE `site_name` = ?');
    $stmtLatest->execute([$siteName]);
    $emails = $stmtLatest->fetchAll(PDO::FETCH_COLUMN);

    return [
        'ok'           => true,
        'site_name'    => $siteName,
        'op'           => $op,
        'target_email' => $targetEmail,
        'emails'       => $emails
    ];
}
