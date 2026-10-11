<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sites.php';
require_once __DIR__ . '/reports.php';

// CORS Preflight 처리
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    exit;
}

$params = getRequestData();
$action = (string)($params['action'] ?? $_GET['action'] ?? $_POST['action'] ?? 'health');
$callback = isset($params['callback']) ? (string)$params['callback'] : (isset($_GET['callback']) ? (string)$_GET['callback'] : null);
$isIframeMode = (string)($params['response_mode'] ?? '') === 'iframe';

/**
 * iframe 응답용 postMessage HTML 출력
 */
function outputIframeResponse(array $payload): void {
    header('Content-Type: text/html; charset=utf-8');
    $safeJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    echo "<!doctype html><html><body><script>window.top && window.top.postMessage({$safeJson}, '*');</script></body></html>";
    exit;
}

try {
    switch ($action) {
        // 헬스체크
        case 'health':
            $res = [
                'ok'      => true,
                'service' => 'workervoice-api',
                'system'  => getConfigValue('SYSTEM_NAME', '세방테크 Worker Voice'),
                'now'     => nowString()
            ];
            break;

        // 현장 목록 (근로자/관리자 공용)
        case 'sites':
            $res = handleListSites();
            break;

        // 신규 제보 접수 (근로자용)
        case 'create':
            $res = handleCreateReport($params);
            if ($isIframeMode) outputIframeResponse($res);
            break;

        // 접수번호 4자리 조회 (근로자용)
        case 'receipt_lookup':
            $res = handleReceiptLookup($params);
            break;

        // 접수번호 찾기 이메일 발송 (근로자용)
        case 'find_receipt':
            $res = handleFindReceipt($params);
            break;

        // 관리자 로그인 OTP 발송
        case 'send_site_otp':
            $res = handleSendSiteOtp($params);
            break;

        // 관리자 제보 목록 필터 조회
        case 'site_list':
            $res = handleSiteList($params);
            break;

        // 관리자 제보 상태 수정
        case 'admin_update':
            $res = handleAdminUpdate($params);
            if ($isIframeMode) outputIframeResponse($res);
            break;

        // 관리자 제보 삭제 (마스터 전용)
        case 'admin_delete':
            $res = handleAdminDelete($params);
            break;

        // 관리자 이메일 목록 조회
        case 'list_site_emails':
            $res = handleListSiteEmails($params);
            break;

        // 관리자 이메일 추가/제거
        case 'admin_manage_emails':
            $res = handleAdminManageEmails($params);
            break;

        default:
            throw new Exception('알 수 없는 action입니다: ' . htmlspecialchars($action));
    }

    ok_($res, $callback);

} catch (Throwable $t) {
    $errPayload = ['ok' => false, 'error' => $t->getMessage()];
    if ($isIframeMode) {
        outputIframeResponse($errPayload);
    }
    fail_($t->getMessage(), 400, $callback);
}
