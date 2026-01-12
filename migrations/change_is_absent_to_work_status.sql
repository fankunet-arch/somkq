-- Migration: Change is_absent field to work_status with multiple options
-- Date: 2026-01-12
-- Description: Replace boolean is_absent field with work_status field that supports:
--              0 = Present (出勤) - default
--              1 = Brief appearance (短暂出现)
--              2 = Absent (未在岗位出现过)

-- Step 1: Add new work_status column
ALTER TABLE `somkq_shift_records`
ADD COLUMN `work_status` TINYINT(1) NOT NULL DEFAULT '0'
COMMENT '工作状态: 0=出勤, 1=短暂出现, 2=未在岗位出现过'
AFTER `is_end_at_closing`;

-- Step 2: Migrate existing data from is_absent to work_status
-- If is_absent = 1, set work_status = 2 (absent)
-- If is_absent = 0, keep work_status = 0 (present)
UPDATE `somkq_shift_records`
SET `work_status` = CASE
    WHEN `is_absent` = 1 THEN 2
    ELSE 0
END;

-- Step 3: Drop the old is_absent column
ALTER TABLE `somkq_shift_records`
DROP COLUMN `is_absent`;

-- Verification query (run after migration to check):
-- SELECT work_status, COUNT(*) as count FROM somkq_shift_records GROUP BY work_status;
