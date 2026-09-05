-- InstaPilot V24
-- Integration secrets are stored in system_settings with is_secret=1 and encrypted at application layer.
-- Ensure media URL fields exist for older installations.
SET @db=DATABASE();
SET @c1=(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='content_items' AND COLUMN_NAME='media_url');
SET @sql=IF(@c1=0,'ALTER TABLE content_items ADD COLUMN media_url TEXT NULL AFTER hashtags','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @c2=(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='content_items' AND COLUMN_NAME='media_type');
SET @sql=IF(@c2=0,'ALTER TABLE content_items ADD COLUMN media_type VARCHAR(30) NULL AFTER media_url','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
