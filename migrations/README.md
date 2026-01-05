# 数据库迁移说明

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
