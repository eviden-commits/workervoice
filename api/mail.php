<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

/**
 * 사내 SMTP 설정 조회
 */
function getSmtpConfig(): ?array {
    try {
        $pdo = getDb();
        $stmt = $pdo->query('
            SELECT `smtp_host`, `smtp_port`, `smtp_user`, `smtp_pass`, `from_email`, `from_name` 
            FROM `kosha_portal`.`mail_settings` 
            WHERE `is_active` = 1 
            ORDER BY `id` ASC LIMIT 1
        ');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    } catch (Throwable $t) {
        // kosha_portal 테이블이 없으면 기본 설정 반환
    }

    return [
        'smtp_host'  => 'mail.sebangtec.com',
        'smtp_port'  => 25,
        'smtp_user'  => 'nschoi@sebangtec.com',
        'smtp_pass'  => '',
        'from_email' => 'nschoi@sebangtec.com',
        'from_name'  => 'Worker Voice 안전보건센터'
    ];
}

/**
 * 소켓 기반 사내 SMTP 메일 전송
 */
function sendSmtpMail(string $to, string $subject, string $htmlBody, ?string &$errorMsg = null): bool {
    $errorMsg = null;
    $cfg = getSmtpConfig();
    if (!$cfg) {
        $errorMsg = 'SMTP 설정이 없습니다.';
        return false;
    }

    $host = !empty($cfg['smtp_host']) ? $cfg['smtp_host'] : 'mail.sebangtec.com';
    $port = !empty($cfg['smtp_port']) ? (int)$cfg['smtp_port'] : 25;
    $from = !empty($cfg['from_email']) ? $cfg['from_email'] : 'nschoi@sebangtec.com';
    $fromName = !empty($cfg['from_name']) ? $cfg['from_name'] : 'Worker Voice 시스템';
    $authUser = !empty($cfg['smtp_user']) ? $cfg['smtp_user'] : $from;
    $authPass = $cfg['smtp_pass'] ?? '';

    $fp = @fsockopen($host, $port, $errno, $errstr, 5);
    if (!$fp) {
        $errorMsg = "SMTP 서버 연결 실패 ({$host}:{$port}) - {$errstr} ({$errno})";
        // 연결 실패 시 PHP mail() fallback 시도
        return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $htmlBody, "Content-Type: text/html; charset=UTF-8\r\nFrom: {$from}");
    }
    stream_set_timeout($fp, 5);

    $read = function(array $codes) use ($fp, &$errorMsg): bool {
        $data = '';
        while ($str = fgets($fp, 515)) {
            $data .= $str;
            if (substr($str, 3, 1) === ' ') break;
        }
        $code = (int)substr($data, 0, 3);
        if (!in_array($code, $codes, true)) {
            $errorMsg = "SMTP 응답 오류 ({$code}) - " . trim($data);
            return false;
        }
        return true;
    };

    $write = function(string $cmd) use ($fp): void {
        fputs($fp, $cmd . "\r\n");
    };

    if (!$read([220])) { fclose($fp); return false; }
    $write("EHLO " . gethostname());
    if (!$read([250])) { fclose($fp); return false; }

    if (!empty($authPass)) {
        $write("AUTH LOGIN");
        if (!$read([334])) { fclose($fp); return false; }
        $write(base64_encode($authUser));
        if (!$read([334])) { fclose($fp); return false; }
        $write(base64_encode($authPass));
        if (!$read([235])) { fclose($fp); return false; }
    }

    $write("MAIL FROM:<{$from}>");
    if (!$read([250])) { fclose($fp); return false; }

    // 다중 수신자 지원
    $recipients = array_filter(array_map('trim', explode(',', $to)));
    foreach ($recipients as $rcpt) {
        if ($rcpt === '') continue;
        $write("RCPT TO:<{$rcpt}>");
        if (!$read([250, 251])) { fclose($fp); return false; }
    }

    $write("DATA");
    if (!$read([354])) { fclose($fp); return false; }

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: =?UTF-8?B?' . base64_encode($fromName) . "?= <{$from}>",
        'To: ' . implode(', ', $recipients),
        'Date: ' . date('r'),
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?='
    ];

    $write(implode("\r\n", $headers) . "\r\n\r\n" . $htmlBody . "\r\n.");
    if (!$read([250])) { fclose($fp); return false; }

    $write("QUIT");
    fclose($fp);
    return true;
}

/**
 * 1. 관리자 OTP 발송 메일
 */
function sendOtpMail(string $to, string $code): bool {
    $subject = "[Worker Voice] 관리자 인증번호 안내";
    $body = "
    <div style='font-family:sans-serif; max-width:500px; padding:24px; border:1px solid #e2e8f0; border-radius:12px;'>
      <h2 style='color:#1e293b; margin-top:0;'>관리자 인증번호</h2>
      <p style='color:#475569;'>Worker Voice 관리자 대시보드 로그인을 위한 인증번호입니다.</p>
      <div style='background:#f1f5f9; padding:16px; border-radius:8px; text-align:center; font-size:28px; font-weight:800; letter-spacing:4px; color:#2563eb;'>
        {$code}
      </div>
      <p style='color:#64748b; font-size:13px; margin-top:16px;'>인증번호 유효시간은 30분입니다.</p>
    </div>";
    return sendSmtpMail($to, $subject, $body);
}

/**
 * 2. 신규 제보 접수 알림 (현장 관리자 및 본사 마스터용)
 */
function sendIntakeMailToSite(array $report): bool {
    $to = $report['site_email'] ?? '';
    if (empty($to)) return false;

    $subject = sprintf('[Worker Voice] 신규 제보 접수 - %s (%s)', $report['title'], $report['site_name']);
    $receipt = $report['receipt_no'] ?? substr($report['id'] ?? '', -4);
    $reporter = ($report['is_anonymous'] === 'TRUE' || $report['is_anonymous'] === 1) 
        ? '익명 제보' 
        : sprintf('%s (%s)', $report['reporter_name'], $report['reporter_contact']);

    $body = "
    <div style='font-family:sans-serif; max-width:600px; padding:24px; border:1px solid #e2e8f0; border-radius:12px;'>
      <h2 style='color:#1e293b; margin-top:0;'>Worker Voice 신규 제보 알림</h2>
      <table style='width:100%; border-collapse:collapse; margin-top:16px;'>
        <tr><td style='padding:8px; border-bottom:1px solid #eee; width:120px; font-weight:bold;'>접수번호</td><td style='padding:8px; border-bottom:1px solid #eee;'>#{$receipt} ({$report['id']})</td></tr>
        <tr><td style='padding:8px; border-bottom:1px solid #eee; font-weight:bold;'>현장명</td><td style='padding:8px; border-bottom:1px solid #eee;'>{$report['site_name']}</td></tr>
        <tr><td style='padding:8px; border-bottom:1px solid #eee; font-weight:bold;'>카테고리</td><td style='padding:8px; border-bottom:1px solid #eee;'>{$report['category']}</td></tr>
        <tr><td style='padding:8px; border-bottom:1px solid #eee; font-weight:bold;'>위험도</td><td style='padding:8px; border-bottom:1px solid #eee;'>{$report['risk_level']}</td></tr>
        <tr><td style='padding:8px; border-bottom:1px solid #eee; font-weight:bold;'>제보자</td><td style='padding:8px; border-bottom:1px solid #eee;'>{$reporter}</td></tr>
        <tr><td style='padding:8px; border-bottom:1px solid #eee; font-weight:bold;'>제목</td><td style='padding:8px; border-bottom:1px solid #eee; font-weight:bold;'>{$report['title']}</td></tr>
        <tr><td style='padding:8px; border-bottom:1px solid #eee; font-weight:bold;'>상세내용</td><td style='padding:8px; border-bottom:1px solid #eee; white-space:pre-wrap;'>{$report['content']}</td></tr>
      </table>
      <div style='margin-top:20px; text-align:center;'>
        <a href='http://setec2000:8000/workervoice/admin.html' style='background:#1e293b; color:#fff; padding:10px 20px; border-radius:6px; text-decoration:none; font-weight:bold;'>관리자 대시보드 바로가기</a>
      </div>
    </div>";

    return sendSmtpMail($to, $subject, $body);
}

/**
 * 3. 제보자 확인증 메일 (실명 제보자용)
 */
function sendReceiptMailToReporter(array $report): bool {
    $to = $report['reporter_email'] ?? '';
    if (empty($to) || !isValidEmail($to)) return false;

    $receipt = $report['receipt_no'] ?? substr($report['id'] ?? '', -4);
    $subject = sprintf('[Worker Voice] 제보가 정상 접수되었습니다 (접수번호: #%s)', $receipt);

    $body = "
    <div style='font-family:sans-serif; max-width:550px; padding:24px; border:1px solid #e2e8f0; border-radius:12px;'>
      <h2 style='color:#1e293b; margin-top:0;'>소중한 제보 감사합니다</h2>
      <p style='color:#475569;'>Worker Voice에 접수해주신 제보가 정상적으로 등록되었습니다.</p>
      <div style='background:#eff6ff; border:1px solid #bfdbfe; padding:16px; border-radius:8px; text-align:center; margin:20px 0;'>
        <div style='color:#1e40af; font-size:14px; font-weight:bold;'>나의 접수번호</div>
        <div style='color:#2563eb; font-size:32px; font-weight:800; margin-top:4px;'>#{$receipt}</div>
      </div>
      <p style='color:#64748b; font-size:14px; line-height:1.6;'>
        Worker Voice 사이트 우측 상단 조회란에서 위 <strong>접수번호 4자리(#{$receipt})</strong>를 입력하시면 실시간 조치 진행 상황을 확인하실 수 있습니다.
      </p>
    </div>";

    return sendSmtpMail($to, $subject, $body);
}

/**
 * 4. 접수번호 찾기 메일
 */
function sendReceiptRecoveryMail(string $to, array $items): bool {
    if (empty($to) || !isValidEmail($to) || empty($items)) return false;

    $subject = "[Worker Voice] 등록하신 제보 접수번호 안내";
    $listHtml = '';
    foreach ($items as $it) {
        $receipt = $it['receipt_no'] ?? substr($it['id'] ?? '', -4);
        $listHtml .= sprintf(
            "<li style='margin-bottom:10px;'><strong>#%s</strong> - [%s] %s (%s, 상태: %s)</li>",
            htmlspecialchars($receipt),
            htmlspecialchars($it['site_name'] ?? ''),
            htmlspecialchars($it['title'] ?? ''),
            htmlspecialchars($it['created_at'] ?? ''),
            htmlspecialchars($it['status'] ?? '')
        );
    }

    $body = "
    <div style='font-family:sans-serif; max-width:550px; padding:24px; border:1px solid #e2e8f0; border-radius:12px;'>
      <h2 style='color:#1e293b; margin-top:0;'>접수번호 찾기 안내</h2>
      <p style='color:#475569;'>요청하신 이메일로 등록된 제보 목록 및 접수번호입니다.</p>
      <ul style='background:#f8fafc; padding:16px 24px; border-radius:8px; color:#1e293b;'>
        {$listHtml}
      </ul>
      <p style='color:#64748b; font-size:13px;'>해당 접수번호 4자리를 조회창에 입력하시면 세부 결과를 확인하실 수 있습니다.</p>
    </div>";

    return sendSmtpMail($to, $subject, $body);
}

/**
 * 5. 개선 조치 결과 제보자 통보 메일
 */
function sendResultMail(array $report): bool {
    $to = $report['reporter_email'] ?? '';
    if (empty($to) || !isValidEmail($to)) return false;

    $receipt = $report['receipt_no'] ?? substr($report['id'] ?? '', -4);
    $subject = sprintf('[Worker Voice] 제보건(#%s)에 대한 조치 결과 안내', $receipt);

    $body = "
    <div style='font-family:sans-serif; max-width:550px; padding:24px; border:1px solid #e2e8f0; border-radius:12px;'>
      <h2 style='color:#1e293b; margin-top:0;'>제보 조치 결과 안내</h2>
      <p style='color:#475569;'>접수해주신 제보에 대한 개선 조치가 등록되었습니다.</p>
      <table style='width:100%; border-collapse:collapse; margin:16px 0;'>
        <tr><td style='padding:8px; border-bottom:1px solid #eee; width:120px; font-weight:bold;'>접수번호</td><td style='padding:8px; border-bottom:1px solid #eee;'>#{$receipt}</td></tr>
        <tr><td style='padding:8px; border-bottom:1px solid #eee; font-weight:bold;'>제목</td><td style='padding:8px; border-bottom:1px solid #eee;'>{$report['title']}</td></tr>
        <tr><td style='padding:8px; border-bottom:1px solid #eee; font-weight:bold;'>상태</td><td style='padding:8px; border-bottom:1px solid #eee; font-weight:bold; color:#059669;'>{$report['status']}</td></tr>
        <tr><td style='padding:8px; border-bottom:1px solid #eee; font-weight:bold;'>조치담당자</td><td style='padding:8px; border-bottom:1px solid #eee;'>{$report['action_owner']}</td></tr>
        <tr><td style='padding:8px; border-bottom:1px solid #eee; font-weight:bold;'>조치내용</td><td style='padding:8px; border-bottom:1px solid #eee; white-space:pre-wrap;'>{$report['action_note']}</td></tr>
      </table>
    </div>";

    return sendSmtpMail($to, $subject, $body);
}
