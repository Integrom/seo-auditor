ALTER TABLE `audits`
    ADD COLUMN `host`             VARCHAR(255) DEFAULT '' AFTER `url`,
    ADD COLUMN `previous_audit_id` INT UNSIGNED NULL AFTER `host`,
    ADD COLUMN `score`            TINYINT UNSIGNED DEFAULT 0 AFTER `completed_at`,
    ADD INDEX `idx_host_status` (`host`, `status`);

ALTER TABLE `audit_issues`
    ADD COLUMN `issue_key` VARCHAR(64) DEFAULT '' AFTER `url`,
    ADD COLUMN `is_new`    TINYINT DEFAULT 1 AFTER `issue_key`;

ALTER TABLE `audit_reports`
    ADD COLUMN `fixed_count`    INT DEFAULT 0 AFTER `audit_data`,
    ADD COLUMN `new_count`      INT DEFAULT 0 AFTER `fixed_count`,
    ADD COLUMN `unchanged_count` INT DEFAULT 0 AFTER `new_count`;
