<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/mail.php';

/**
 * 마스터 이메일 여부 확인
 */
function isMasterEmail(string $email): bool {
    $email = strtolower(safeText($email));
    if ($email === '') return false;

    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM `tb_masters` WHERE LOWER(`email`) = ?');
    $stmt->execute([$email]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * 이메일 기준으로 접근 가능한 현장 목록 반환
 */
function getAllowedSitesByEmail(string $email): array {
    $email = strtolower(safeText($email));
    if ($email === '') return [];

    $pdo = getDb();

    // 마스터인 경우 모든 활성 현장 반환
    if (isMasterEmail($email)) {
        $stmt = $pdo->query('SELECT `site_name` FROM `tb_sites` WHERE `active` = 1 ORDER BY `site_name` ASC');
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // 일반 관리자인 경우 매핑된 현장만 반환
    $stmt = $pdo->prepare('SELECT DISTINCT `site_name` FROM `tb_site_emails` WHERE LOWER(`email`) = ? ORDER BY `site_name` ASC');
    $stmt->execute([$email]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * 6자리 OTP 발급 및 저장
 */
function issueSiteOtp(string $email): string {
    $email = strtolower(safeText($email));
    $code = (string)random_int(100000, 999999);
    $ttlSec = (int)getConfigValue('SITE_OTP_TTL_SEC', '1800');
    $expiresAt = date('Y-m-d H:i:s', time() + $ttlSec);

    $pdo = getDb();
    $stmt = $pdo->prepare('
        INSERT INTO `tb_otp` (`email`, `code`, `expires_at`, `created_at`)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE `code` = VALUES(`code`), `expires_at` = VALUES(`expires_at`), `created_at` = NOW()
    ');
    $stmt->execute([$email, $code, $expiresAt]);

    return $code;
}

/**
 * OTP 또는 마스터 토큰 검증
 */
function verifySiteOtp(string $email, string $code): bool {
    $email = strtolower(safeText($email));
    $code = safeText($code);
    if ($code === '') return false;

    // 1. 고정 마스터 토큰 확인 (즉시 인증 지원)
    $masterToken = getConfigValue('ADMIN_TOKEN', 'sbtec5809*');
    $secondKey = getConfigValue('MASTER_SECOND_KEY', 'sbtec5809*');
    if ($code === $masterToken || $code === $secondKey) {
        return true;
    }

    // 2. 6자리 OTP 확인
    if (!preg_match('/^\d{6}$/', $code)) {
        return false;
    }

    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT `code`, `expires_at` FROM `tb_otp` WHERE LOWER(`email`) = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if (!$row) return false;
    if ($row['code'] !== $code) return false;
    if (strtotime($row['expires_at']) < time()) return false;

    return true;
}

/**
 * 관리자 로그인 OTP 발송 핸들러
 */
function handleSendSiteOtp(array $params): array {
    $email = strtolower(safeText($params['contact_email'] ?? ''));
    if ($email === '' || !isValidEmail($email)) {
        throw new Exception('유효한 이메일을 입력해 주십시오.');
    }

    $allowedSites = getAllowedSitesByEmail($email);
    if (empty($allowedSites)) {
        throw new Exception('미등록 계정입니다. 관리자에게 권한을 요청하세요.');
    }

    $isMaster = isMasterEmail($email);
    $otp = issueSiteOtp($email);

    // 메일 발송
    $sent = sendOtpMail($email, $otp);

    return [
        'ok'        => true,
        'is_master' => $isMaster,
        'message'   => $sent ? '인증 메일이 발송되었습니다.' : '인증번호가 생성되었습니다. (메일 서버 연동 확인 필요)',
        'debug_otp' => $otp // 내부망 테스트 편의를 위해 포함
    ];
}
