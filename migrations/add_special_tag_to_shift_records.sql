-- 添加特殊标记字段到班次记录表
-- 执行时间：2026-01-05

ALTER TABLE `somkq_shift_records`
ADD COLUMN `special_tag` VARCHAR(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '特殊标记（如：补货、加班等）'
AFTER `is_end_at_closing`;
