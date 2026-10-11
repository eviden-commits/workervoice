<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mail.php';

/**
 * reCAPTCHA 검증
 */
function verifyRecaptcha(string $token): bool {
    $secret = getConfigValue('RECAPTCHA_SECRET_KEY');
    if ($secret === '') return true; // 미설정 시 통과

    $url = 'https://www.google.com/recaptcha/api/siteverify';
    $postData = http_build_query([
        'secret'   => $secret,
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
    ]);

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'content' => $postData,
            'timeout' => 5
        ]
    ]);

    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) return true; // 네트워크 에러 시 사내 환경 편의를 위해 통과

    $json = json_decode($resp, true);
    return !empty($json['success']);
}

/**
 * Base64 첨부파일 디스크 저장
 */
function saveBase64File(string $base64Raw, string $mimeType, string $originalName, string $reportId, string $tag): string {
    if (empty($base64Raw)) return '';

    // data:image/png;base64,... 프리픽스 제거
    if (strpos($base64Raw, ',') !== false) {
        $parts = explode(',', $base64Raw, 2);
        $base64Raw = $parts[1];
    }

    $bin = base64_decode($base64Raw);
    if ($bin === false) return '';

    // 파일 용량 제한 (10MB)
    if (strlen($bin) > 10 * 1024 * 1024) {
        throw new Exception('첨부파일은 최대 10MB까지 가능합니다.');
    }

    $subDir = ($tag === 'action') ? 'actions' : 'reports';
    $yearMonth = date('Ym');
    $uploadDir = __DIR__ . '/../uploads/' . $subDir . '/' . $yearMonth;
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    $ext = pathinfo($originalName, PATHINFO_EXTENSION);
    if (empty($ext)) {
        $ext = ($mimeType === 'application/pdf') ? 'pdf' : 'png';
    }
    $safeName = sprintf('%s_%s_%s.%s', $reportId, $tag, date('Ymd_His'), strtolower($ext));
    $filePath = $uploadDir . '/' . $safeName;

    file_put_contents($filePath, $bin);

    // 상대 웹 경로 반환
    return sprintf('uploads/%s/%s/%s', $subDir, $yearMonth, $safeName);
}

/**
 * 1. 신규 제보 등록
 */
function handleCreateReport(array $p): array {
    $pdo = getDb();

    // 1) 리캡차 검증
    $recaptchaToken = safeText($p['recaptcha_token'] ?? '');
    if (!empty($recaptchaToken) && !verifyRecaptcha($recaptchaToken)) {
        throw new Exception('자동입력 방지(reCAPTCHA) 확인에 실패했습니다.');
    }

    // 2) 필수 필드 검증
    $siteName = safeText($p['site_name'] ?? '');
    $category = safeText($p['category'] ?? '');
    $title = safeText($p['title'] ?? '');
    $content = safeText($p['content'] ?? '');
    $isAnon = boolString($p['is_anonymous'] ?? 'FALSE');
    $riskLevel = safeText($p['risk_level'] ?? 'medium');
    $reporterName = safeText($p['reporter_name'] ?? '');
    $reporterContact = safeText($p['reporter_contact'] ?? '');
    $reporterEmail = safeText($p['reporter_email'] ?? '');

    if ($siteName === '') throw new Exception('현장명을 선택해 주세요.');
    if ($category === '') throw new Exception('카테고리를 선택해 주세요.');
    if ($title === '') throw new Exception('제목을 입력해 주세요.');
    if ($content === '') throw new Exception('내용을 입력해 주세요.');

    if ($isAnon === 'FALSE') {
        if ($reporterName === '') throw new Exception('이름을 입력해 주세요.');
        if ($reporterContact === '') throw new Exception('전화번호를 입력해 주세요.');
        if (!preg_match('/^\d{9,11}$/', $reporterContact)) throw new Exception('전화번호는 9~11자리 숫자여야 합니다.');
        if ($reporterEmail === '' || !isValidEmail($reporterEmail)) throw new Exception('올바른 이메일 주소를 입력해 주세요.');
    } else {
        $reporterName = '';
        $reporterContact = '';
        $reporterEmail = '';
    }

    // 3) 10분 이내 동일 내용 중복 방지
    $stmtDup = $pdo->prepare('
        SELECT COUNT(*) FROM `tb_reports` 
        WHERE `site_name` = ? AND `title` = ? AND `content` = ? 
          AND `created_at` >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    ');
    $stmtDup->execute([$siteName, $title, $content]);
    if ((int)$stmtDup->fetchColumn() > 0) {
        throw new Exception('동일한 내용의 제보가 최근 접수되었습니다. 잠시 후 다시 시도해 주세요.');
    }

    // 4) 신규 접수번호 생성
    $todayPrefix = 'WV-' . date('Ymd') . '-';
    $stmtSeq = $pdo->prepare('SELECT COUNT(*) FROM `tb_reports` WHERE `id` LIKE ?');
    $stmtSeq->execute([$todayPrefix . '%']);
    $seq = (int)$stmtSeq->fetchColumn() + 1;
    $receiptNo = str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
    $reportId = $todayPrefix . $receiptNo;

    // 5) 첨부파일 저장
    $attachmentUrls = '';
    $base64 = safeText($p['attachment_base64'] ?? '');
    if (!empty($base64)) {
        $mime = safeText($p['attachment_mime'] ?? '');
        $origName = safeText($p['attachment_name'] ?? 'attachment');
        $attachmentUrls = saveBase64File($base64, $mime, $origName, $reportId, 'report');
    }

    // 6) 현장 담당자 및 본사 마스터 이메일 취합
    $stmtEmails = $pdo->prepare('SELECT `email` FROM `tb_site_emails` WHERE `site_name` = ?');
    $stmtEmails->execute([$siteName]);
    $siteEmails = $stmtEmails->fetchAll(PDO::FETCH_COLUMN);

    $stmtMasters = $pdo->query('SELECT `email` FROM `tb_masters`');
    $masterEmails = $stmtMasters->fetchAll(PDO::FETCH_COLUMN);

    $combinedEmails = array_unique(array_filter(array_merge($siteEmails, $masterEmails)));
    $siteEmailStr = implode(',', $combinedEmails);

    // 목표일: 접수일 기준 7일 후 기본 세팅
    $dueDate = date('Y-m-d', strtotime('+7 days'));

    // 7) DB 저장
    $stmtInsert = $pdo->prepare('
        INSERT INTO `tb_reports` (
            `id`, `receipt_no`, `created_at`, `category`, `title`, `content`,
            `site_name`, `due_date`, `status`, `site_email`, `risk_level`,
            `is_anonymous`, `reporter_name`, `reporter_contact`, `reporter_email`,
            `attachment_urls`, `action_owner`, `action_note`, `action_attachment_urls`,
            `updated_at`, `updated_by`
        ) VALUES (
            ?, ?, NOW(), ?, ?, ?,
            ?, ?, "active", ?, ?,
            ?, ?, ?, ?,
            ?, "", "", "",
            NOW(), "system"
        )
    ');

    $stmtInsert->execute([
        $reportId,
        $receiptNo,
        $category,
        $title,
        $content,
        $siteName,
        $dueDate,
        $siteEmailStr,
        $riskLevel,
        $isAnon === 'TRUE' ? 1 : 0,
        $reporterName,
        $reporterContact,
        $reporterEmail,
        $attachmentUrls
    ]);

    // 8) 이메일 통지
    $reportRow = [
        'id'              => $reportId,
        'receipt_no'      => $receiptNo,
        'site_name'       => $siteName,
        'category'        => $category,
        'title'           => $title,
        'content'         => $content,
        'risk_level'      => $riskLevel,
        'is_anonymous'    => $isAnon,
        'reporter_name'   => $reporterName,
        'reporter_contact'=> $reporterContact,
        'reporter_email'  => $reporterEmail,
        'site_email'      => $siteEmailStr
    ];
    @sendIntakeMailToSite($reportRow);
    if (!empty($reporterEmail)) {
        @sendReceiptMailToReporter($reportRow);
    }

    return [
        'ok'         => true,
        'id'         => $reportId,
        'receipt_no' => $receiptNo
    ];
}

/**
 * 2. 접수번호 4자리로 제보 조회
 */
function handleReceiptLookup(array $p): array {
    $receiptNo = safeText($p['receipt_no'] ?? '');
    if (!preg_match('/^\d{4}$/', $receiptNo)) {
        throw new Exception('접수번호는 4자리 숫자여야 합니다.');
    }

    $pdo = getDb();
    $stmt = $pdo->prepare('
        SELECT 
            `id`, `receipt_no`, `created_at`, `category`, `title`, `content`,
            `site_name`, `due_date`, `status`, `risk_level`, `is_anonymous`,
            `reporter_name`, `reporter_contact`, `reporter_email`, `attachment_urls`,
            `action_owner`, `action_note`, `action_attachment_urls`, `updated_at`
        FROM `tb_reports`
        WHERE `receipt_no` = ? OR `id` LIKE ?
        ORDER BY `created_at` DESC
    ');
    $stmt->execute([$receiptNo, '%' . $receiptNo]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 불리언 문자열 포맷팅
    foreach ($items as &$it) {
        $it['is_anonymous'] = ((int)$it['is_anonymous'] === 1) ? 'TRUE' : 'FALSE';
    }

    return [
        'ok'    => true,
        'count' => count($items),
        'items' => $items
    ];
}

/**
 * 3. 이메일로 접수번호 찾기
 */
function handleFindReceipt(array $p): array {
    $email = strtolower(safeText($p['email'] ?? ''));
    if ($email === '' || !isValidEmail($email)) {
        throw new Exception('유효한 이메일을 입력해 주세요.');
    }

    $pdo = getDb();
    $stmt = $pdo->prepare('
        SELECT `id`, `receipt_no`, `title`, `site_name`, `status`, `created_at`
        FROM `tb_reports`
        WHERE LOWER(`reporter_email`) = ? AND `is_anonymous` = 0
        ORDER BY `created_at` DESC
    ');
    $stmt->execute([$email]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        throw new Exception('해당 이메일로 접수된 실명 내역이 없습니다. (익명 접수건은 조회가 불가능합니다)');
    }

    sendReceiptRecoveryMail($email, $items);

    return [
        'ok'      => true,
        'message' => '입력하신 이메일로 접수번호 안내 메일을 발송했습니다.'
    ];
}

/**
 * 4. 관리자 제보 목록 조회
 */
function handleSiteList(array $p): array {
    $contactEmail = strtolower(safeText($p['contact_email'] ?? ''));
    $otp = safeText($p['site_otp'] ?? '');

    if ($contactEmail === '' || !isValidEmail($contactEmail)) {
        throw new Exception('유효한 관리자 이메일이 필요합니다.');
    }
    if (!verifySiteOtp($contactEmail, $otp)) {
        throw new Exception('인증 코드가 올바르지 않거나 만료되었습니다.');
    }

    $isMaster = isMasterEmail($contactEmail);
    $allowedSites = getAllowedSitesByEmail($contactEmail);

    if (empty($allowedSites)) {
        return ['ok' => true, 'count' => 0, 'items' => [], 'allowed_sites' => []];
    }

    $status = safeText($p['status'] ?? '');
    $category = safeText($p['category'] ?? '');

    $pdo = getDb();

    // 조건식 빌드
    $where = [];
    $params = [];

    // 마스터가 아닌 경우 권한 있는 현장만
    if (!$isMaster) {
        $inPlaceholders = implode(',', array_fill(0, count($allowedSites), '?'));
        $where[] = "`site_name` IN ({$inPlaceholders})";
        $params = array_merge($params, $allowedSites);
    }

    if ($status !== '') {
        $where[] = "`status` = ?";
        $params[] = $status;
    }

    if ($category !== '') {
        $where[] = "`category` = ?";
        $params[] = $category;
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    $sql = "
        SELECT 
            `id`, `receipt_no`, `created_at`, `category`, `title`, `content`,
            `site_name`, `due_date`, `status`, `risk_level`, `is_anonymous`,
            `reporter_name`, `reporter_contact`, `reporter_email`, `attachment_urls`,
            `action_owner`, `action_note`, `action_attachment_urls`, `updated_at`, `updated_by`
        FROM `tb_reports`
        {$whereSql}
        ORDER BY `created_at` DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as &$it) {
        $it['is_anonymous'] = ((int)$it['is_anonymous'] === 1) ? 'TRUE' : 'FALSE';
    }

    return [
        'ok'            => true,
        'count'         => count($items),
        'items'         => $items,
        'allowed_sites' => $allowedSites,
        'is_master'     => $isMaster
    ];
}

/**
 * 5. 관리자 제보 상세 수정
 */
function handleAdminUpdate(array $p): array {
    $contactEmail = strtolower(safeText($p['contact_email'] ?? ''));
    $otp = safeText($p['site_otp'] ?? '');
    $id = safeText($p['id'] ?? '');

    if ($contactEmail === '' || !isValidEmail($contactEmail)) {
        throw new Exception('유효한 관리자 이메일이 필요합니다.');
    }
    if (!verifySiteOtp($contactEmail, $otp)) {
        throw new Exception('인증 코드가 올바르지 않거나 만료되었습니다.');
    }
    if ($id === '') {
        throw new Exception('수정 대상 ID가 필요합니다.');
    }

    $pdo = getDb();
    $stmtFind = $pdo->prepare('SELECT * FROM `tb_reports` WHERE `id` = ?');
    $stmtFind->execute([$id]);
    $report = $stmtFind->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        throw new Exception('해당 제보 내역을 찾을 수 없습니다.');
    }

    $isMaster = isMasterEmail($contactEmail);
    $allowedSites = getAllowedSitesByEmail($contactEmail);
    if (!$isMaster && !in_array($report['site_name'], $allowedSites, true)) {
        throw new Exception('해당 현장에 대한 수정 권한이 없습니다.');
    }

    // 수정 필드
    $status = safeText($p['status'] ?? $report['status']);
    $riskLevel = safeText($p['risk_level'] ?? $report['risk_level']);
    $actionOwner = safeText($p['action_owner'] ?? $report['action_owner']);
    $dueDate = safeText($p['due_date'] ?? $report['due_date']);
    $actionNote = safeText($p['action_note'] ?? $report['action_note']);
    $notify = boolString($p['notify'] ?? 'FALSE') === 'TRUE';

    // 개선사항 첨부파일 저장
    $actionBase64 = safeText($p['action_attachment_base64'] ?? '');
    $actionUrls = (string)$report['action_attachment_urls'];
    if (!empty($actionBase64)) {
        $mime = safeText($p['action_attachment_mime'] ?? '');
        $origName = safeText($p['action_attachment_name'] ?? 'action_attachment');
        $newUrl = saveBase64File($actionBase64, $mime, $origName, $id, 'action');
        if (!empty($newUrl)) {
            $actionUrls = !empty($actionUrls) ? "{$actionUrls}\n{$newUrl}" : $newUrl;
        }
    }

    $stmtUp = $pdo->prepare('
        UPDATE `tb_reports` SET
            `status` = ?,
            `risk_level` = ?,
            `action_owner` = ?,
            `due_date` = ?,
            `action_note` = ?,
            `action_attachment_urls` = ?,
            `updated_at` = NOW(),
            `updated_by` = ?
        WHERE `id` = ?
    ');
    $stmtUp->execute([
        $status,
        $riskLevel,
        $actionOwner,
        !empty($dueDate) ? $dueDate : null,
        $actionNote,
        $actionUrls,
        $contactEmail,
        $id
    ]);

    // 제보자 결과 통지
    $mailSent = false;
    if ($notify) {
        $report['status'] = $status;
        $report['action_owner'] = $actionOwner;
        $report['action_note'] = $actionNote;
        $mailSent = sendResultMail($report);
    }

    return [
        'ok'        => true,
        'id'        => $id,
        'mail_sent' => $mailSent
    ];
}

/**
 * 6. 관리자 제보 삭제 (마스터 전용)
 */
function handleAdminDelete(array $p): array {
    $contactEmail = strtolower(safeText($p['contact_email'] ?? ''));
    $otp = safeText($p['site_otp'] ?? '');
    $id = safeText($p['id'] ?? '');

    if ($contactEmail === '' || !isValidEmail($contactEmail)) {
        throw new Exception('유효한 관리자 이메일이 필요합니다.');
    }
    if (!isMasterEmail($contactEmail)) {
        throw new Exception('삭제 권한이 없습니다. 마스터 관리자만 삭제할 수 있습니다.');
    }
    if (!verifySiteOtp($contactEmail, $otp)) {
        throw new Exception('인증 코드가 올바르지 않거나 만료되었습니다.');
    }
    if ($id === '') {
        throw new Exception('삭제 대상 ID가 필요합니다.');
    }

    $pdo = getDb();
    $stmt = $pdo->prepare('DELETE FROM `tb_reports` WHERE `id` = ?');
    $stmt->execute([$id]);

    return [
        'ok'      => true,
        'id'      => $id,
        'deleted' => true
    ];
}
