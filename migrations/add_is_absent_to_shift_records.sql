-- 添加 is_absent 字段到 somkq_shift_records 表
-- 用于标记"未在岗位出现过"的班次记录

ALTER TABLE `somkq_shift_records`
ADD COLUMN `is_absent` TINYINT(1) NOT NULL DEFAULT '0' COMMENT '1=未在岗位出现过'
AFTER `is_end_at_closing`;
