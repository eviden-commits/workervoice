-- ========================================================
-- 세방테크 Worker Voice (근로자의 목소리) 초기 시드 데이터
-- ========================================================

USE `workervoice`;

-- 1. 시스템 기본 환경설정
INSERT INTO `tb_config` (`config_key`, `config_value`, `description`)
VALUES
('ADMIN_TOKEN', 'sbtec5809*', '관리자/마스터 즉시 인증 토큰'),
('MASTER_SECOND_KEY', 'sbtec5809*', '마스터 2차 인증키'),
('RECAPTCHA_SECRET_KEY', '6LddEQgtAAAAAKxVEFiDHGlT2LwwtjPpRtrjJ2rp', '구글 reCAPTCHA v2 시크릿 키'),
('ADMIN_EMAILS', 'sb06@sebangtec.com', '통합 마스터 기본 관리자 이메일'),
('SYSTEM_NAME', '세방테크 Worker Voice', '시스템 공식 명칭'),
('SITE_OTP_TTL_SEC', '1800', 'OTP 유효시간(초, 기본 30분)')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

-- 2. 통합 마스터 계정 등록
INSERT INTO `tb_masters` (`email`, `name`)
VALUES
('sb06@sebangtec.com', '품질안전보건실'),
('admin@sebangtec.com', '시스템관리자')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 3. 기본 현장 목록 등록
INSERT INTO `tb_sites` (`site_name`, `active`)
VALUES
('본사', 1),
('화성 소방설비 기계전기 유지보수', 1),
('기흥화성 경영계획 대응공사', 1),
('GH FAB 마감공사 [DS]일반덕트', 1),
('송도 싸토리우스 공장동 HVAC공사', 1),
('이편한세상 남산현장', 1),
('힐스테이트 라군 인테라스2차', 1),
('반포아파트 3주구 재건축현장', 1),
('기흥 NRD-K PH1 그린동 소방기계공사', 1),
('P4 Ph4 그린동 소방기계 공사', 1),
('청계 리버뷰 자이', 1),
('자유CC 9홀증설 및 클럽하우스 리뉴얼 공사', 1),
('평택 FAB 4기 북섭 PH4 배관공사', 1),
('평택 FAB 4기 북섭 PH4 덕트공사', 1),
('평택 P4 복합동 PH4 배관공사 B', 1),
('용인 Cluster 1기 IBL CUB HVAC 및 소방공사 5공구', 1),
('평택 FAB 4기 북섭 PH2 배관 마감공사', 1),
('평택 전자 P4 대기방지 [PH2] 일반덕트(FAB남,동)', 1),
('식사풍동2단지 지역주택조합 공동주택', 1),
('삼성서울병원 리모델링 공사 2차', 1),
('송도자이풍경채 그라노블5단지', 1),
('평촌자이 퍼스니티 1,2단지 설비공사', 1),
('판교 641 PSM 타워 신축공사', 1),
('김포데이터센터 일반설비공사', 1),
('킨텍스 제3전시장 건립공사', 1),
('철산역자이(경기)', 1),
('용인 덕성 AI 데이터센터', 1),
('평택 P5 Ph1 소방공사 1공구', 1),
('평택 P5 PH1 복합동 배관공사', 1),
('평택 P5 PH1 FAB 배관공사', 1),
('평택 P5 PH1 FAB 덕트공사', 1),
('평택 P5 복합동 신축공사 UG 및 가설배관공사', 1),
('평택 P5 복합동 마감 PH1 배관공사 B', 1),
('평택 P5 그린동 PH1 HVAC 덕트공사', 1),
('스타필드 창원 신축공사', 1),
('평택 사무6동 1단계 신축공사 소방공사(기계)', 1),
('화성 데이터센터 2차 일반배관공사', 1),
('기흥 NRD-K2 FAB 공사', 1)
ON DUPLICATE KEY UPDATE `active` = VALUES(`active`);

-- 4. 본사 기본 이메일 매핑
INSERT INTO `tb_site_emails` (`site_name`, `email`)
VALUES
('본사', 'sb06@sebangtec.com')
ON DUPLICATE KEY UPDATE `email` = VALUES(`email`);
