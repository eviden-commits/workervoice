<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db_config.php';

// 시간대 한국 표준시 설정
date_default_timezone_set('Asia/Seoul');

/**
 * 안전한 문자열 정제 (앞뒤 공백 제거 및 null 처리)
 */
function safeText(?string $val): string {
    return trim($val ?? '');
}

/**
 * 불리언 문자열 ("TRUE" / "FALSE")
 */
function boolString($val): string {
    if (is_bool($val)) return $val ? 'TRUE' : 'FALSE';
    $s = strtoupper(trim((string)$val));
    return ($s === 'TRUE' || $s === '1' || $s === 'YES' || $s === 'Y') ? 'TRUE' : 'FALSE';
}

/**
 * 이메일 유효성 검사
 */
function isValidEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * 날짜 유효성 검사 (YYYY-MM-DD)
 */
function isValidDate(string $d): bool {
    $t = strtotime($d);
    return $t !== false && date('Y-m-d', $t) === $d;
}

/**
 * 현재 시각 문자열 (YYYY-MM-DD HH:mm:ss)
 */
function nowString(): string {
    return date('Y-m-d H:i:s');
}

/**
 * 오늘 날짜 문자열 (YYYY-MM-DD)
 */
function todayString(): string {
    return date('Y-m-d');
}

/**
 * JSON 응답 출력 (JSONP 지원)
 */
function ok_(array $data, ?string $callback = null): void {
    $data = array_merge(['ok' => true], $data);
    sendResponse_($data, 200, $callback);
}

/**
 * 에러 응답 출력 (JSONP 지원)
 */
function fail_(string $error, int $statusCode = 400, ?string $callback = null): void {
    $data = ['ok' => false, 'error' => $error];
    sendResponse_($data, $statusCode, $callback);
}

/**
 * 응답 전송 헬퍼
 */
function sendResponse_(array $data, int $statusCode = 200, ?string $callback = null): void {
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    }

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($callback !== null && preg_match('/^[a-zA-Z0-9_]+$/', $callback)) {
        header('Content-Type: application/javascript; charset=utf-8');
        echo "{$callback}({$json});";
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo $json;
    }
    exit;
}

/**
 * 요청 본문 파싱 (JSON 또는 FormData)
 */
function getRequestData(): array {
    $raw = file_get_contents('php://input');
    $data = [];
    if (!empty($raw)) {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $data = $json;
        }
    }
    // POST 폼 필드와 합치기
    return array_merge($_GET, $_POST, $data);
}

/**
 * 시스템 설정값 조회
 */
function getConfigValue(string $key, string $default = ''): string {
    try {
        $pdo = getDb();
        $stmt = $pdo->prepare('SELECT `config_value` FROM `tb_config` WHERE `config_key` = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? (string)$row['config_value'] : $default;
    } catch (Throwable $t) {
        return $default;
    }
}
