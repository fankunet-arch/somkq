# 数据库迁移说明

## 工作状态选择功能 (2026-01-12)

### 迁移文件
- `change_is_absent_to_work_status.sql`

### 说明
将 `is_absent` 字段改为 `work_status` 字段，支持三种工作状态：
- 0 = 出勤（默认）
- 1 = 短暂出现
- 2 = 未在岗位出现过

### 执行方法
```bash
# 方法1：使用 mysql 命令行
mysql -h your_host -u your_user -p your_database < change_is_absent_to_work_status.sql

# 方法2：登录 phpMyAdmin 后，在 SQL 标签页中执行 SQL 文件内容
```

### 验证
执行后，检查 `somkq_shift_records` 表结构，应该包含 `work_status` 字段而不是 `is_absent`：
```sql
DESC somkq_shift_records;
SELECT work_status, COUNT(*) as count FROM somkq_shift_records GROUP BY work_status;
```

### 回滚（如需要）
```sql
-- Add back is_absent column
ALTER TABLE `somkq_shift_records`
ADD COLUMN `is_absent` TINYINT(1) NOT NULL DEFAULT '0'
COMMENT '1=未在岗位出现过'
AFTER `is_end_at_closing`;

-- Migrate data back
UPDATE `somkq_shift_records`
SET `is_absent` = CASE
    WHEN `work_status` = 2 THEN 1
    ELSE 0
END;

-- Drop work_status column
ALTER TABLE `somkq_shift_records`
DROP COLUMN `work_status`;
```

---

## 未在岗位出现过标记功能 (2026-01-11)

### 迁移文件
- `add_is_absent_to_shift_records.sql`

### 说明
添加 `is_absent` 字段到 `somkq_shift_records` 表，用于标记员工在某个班次"未在岗位出现过"。

### 执行方法
```bash
# 方法1：使用 mysql 命令行
mysql -h your_host -u your_user -p your_database < add_is_absent_to_shift_records.sql

# 方法2：登录 phpMyAdmin 后，在 SQL 标签页中执行 SQL 文件内容
```

### 验证
执行后，检查 `somkq_shift_records` 表结构，应该包含 `is_absent` 字段：
```sql
DESC somkq_shift_records;
```

### 回滚（如需要）
```sql
ALTER TABLE `somkq_shift_records` DROP COLUMN `is_absent`;
```

---

## 特殊标记功能 (2026-01-05)

### 迁移文件
- `add_special_tag_to_shift_records.sql`

### 说明
添加 `special_tag` 字段到 `somkq_shift_records` 表，用于标记班次的特殊状态（如：补货、加班、培训、盘点）。

### 执行方法
```bash
# 方法1：使用 mysql 命令行
mysql -h your_host -u your_user -p your_database < add_special_tag_to_shift_records.sql

# 方法2：登录 phpMyAdmin 后，在 SQL 标签页中执行 SQL 文件内容
```

### 验证
执行后，检查 `somkq_shift_records` 表结构，应该包含 `special_tag` 字段：
```sql
DESC somkq_shift_records;
```

### 回滚（如需要）
```sql
ALTER TABLE `somkq_shift_records` DROP COLUMN `special_tag`;
```
