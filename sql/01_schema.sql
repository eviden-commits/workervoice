-- ========================================================
-- 세방테크 Worker Voice (근로자의 목소리) 데이터베이스 스키마
-- ========================================================

CREATE DATABASE IF NOT EXISTS `workervoice` 
DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `workervoice`;

-- 1. 현장 정보 테이블
CREATE TABLE IF NOT EXISTS `tb_sites` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `site_name` VARCHAR(100) NOT NULL UNIQUE COMMENT '현장명',
    `active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '사용 여부 (1: 사용, 0: 미사용)',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '등록일시',
    INDEX `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. 현장별 관리자 이메일 매핑 테이블
CREATE TABLE IF NOT EXISTS `tb_site_emails` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `site_name` VARCHAR(100) NOT NULL COMMENT '현장명',
    `email` VARCHAR(100) NOT NULL COMMENT '담당 관리자 이메일',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '등록일시',
    UNIQUE KEY `uk_site_email` (`site_name`, `email`),
    INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. 통합 마스터 관리자 이메일 테이블
CREATE TABLE IF NOT EXISTS `tb_masters` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(100) NOT NULL UNIQUE COMMENT '마스터 이메일',
    `name` VARCHAR(50) DEFAULT '마스터 관리자' COMMENT '관리자명',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '등록일시'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. 제보 및 고충 처리 테이블
CREATE TABLE IF NOT EXISTS `tb_reports` (
    `id` VARCHAR(50) PRIMARY KEY COMMENT '접수 ID (예: WV-20261012-0001)',
    `receipt_no` CHAR(4) NOT NULL COMMENT '접수번호 4자리 (사용자 조회용)',
    `created_at` DATETIME NOT NULL COMMENT '접수 일시',
    `category` VARCHAR(50) NOT NULL COMMENT '카테고리 (안전관리/노무관리/기타관리)',
    `title` VARCHAR(255) NOT NULL COMMENT '제목',
    `content` TEXT NOT NULL COMMENT '내용',
    `site_name` VARCHAR(100) NOT NULL COMMENT '현장명',
    `due_date` DATE DEFAULT NULL COMMENT '조치 목표일 (YYYY-MM-DD)',
    `status` ENUM('active', 'in_review', 'done', 'hold') NOT NULL DEFAULT 'active' COMMENT '상태 (처리중/검토중/완료/보류)',
    `site_email` TEXT DEFAULT NULL COMMENT '통지 대상 현장 관리자 이메일 목록',
    `risk_level` ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium' COMMENT '위험도 (낮음/보통/높음/긴급)',
    `is_anonymous` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '익명 제출 여부 (1: 익명, 0: 실명)',
    `reporter_name` VARCHAR(50) DEFAULT '' COMMENT '제보자 이름',
    `reporter_contact` VARCHAR(50) DEFAULT '' COMMENT '제보자 전화번호',
    `reporter_email` VARCHAR(100) DEFAULT '' COMMENT '제보자 이메일',
    `attachment_urls` TEXT DEFAULT NULL COMMENT '제보 첨부파일 URL (줄바꿈 구분)',
    `action_owner` VARCHAR(50) DEFAULT '' COMMENT '조치 담당자',
    `action_note` TEXT DEFAULT NULL COMMENT '개선 조치 내용',
    `action_attachment_urls` TEXT DEFAULT NULL COMMENT '개선사항 첨부파일 URL',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '최종 수정일시',
    `updated_by` VARCHAR(50) DEFAULT 'system' COMMENT '최종 수정자',
    INDEX `idx_receipt_no` (`receipt_no`),
    INDEX `idx_site_name` (`site_name`),
    INDEX `idx_status` (`status`),
    INDEX `idx_risk_level` (`risk_level`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. 관리자 OTP 인증 테이블
CREATE TABLE IF NOT EXISTS `tb_otp` (
    `email` VARCHAR(100) PRIMARY KEY COMMENT '관리자 이메일',
    `code` CHAR(6) NOT NULL COMMENT '6자리 인증코드',
    `expires_at` DATETIME NOT NULL COMMENT '만료일시',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '발급일시'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. 시스템 전역 설정 테이블
CREATE TABLE IF NOT EXISTS `tb_config` (
    `config_key` VARCHAR(50) PRIMARY KEY COMMENT '설정 키',
    `config_value` TEXT NOT NULL COMMENT '설정 값',
    `description` VARCHAR(255) DEFAULT '' COMMENT '설명',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일시'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
