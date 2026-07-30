CREATE TABLE IF NOT EXISTS `audits` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `uuid`          VARCHAR(36) NOT NULL UNIQUE,
    `url`           VARCHAR(2048) NOT NULL,
    `email`         VARCHAR(255) NOT NULL,
    `status`        ENUM('pending','crawling','checking','reporting','done','error') DEFAULT 'pending',
    `progress`      TINYINT UNSIGNED DEFAULT 0,
    `progress_text` VARCHAR(255) DEFAULT '',
    `pages_total`   INT DEFAULT 0,
    `pages_crawled` INT DEFAULT 0,
    `error_message` TEXT NULL,
    `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `completed_at`  TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_pages` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `audit_id`    INT UNSIGNED NOT NULL,
    `url`         VARCHAR(2048) NOT NULL,
    `status_code` SMALLINT DEFAULT 0,
    `title`       VARCHAR(500) DEFAULT '',
    `crawled`     TINYINT DEFAULT 0,
    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`audit_id`) REFERENCES `audits`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_issues` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `audit_id`       INT UNSIGNED NOT NULL,
    `page_id`        INT UNSIGNED NULL,
    `check_type`     VARCHAR(50) NOT NULL,
    `severity`       ENUM('critical','warning','info') DEFAULT 'warning',
    `title`          VARCHAR(500) NOT NULL,
    `description`    TEXT,
    `recommendation` TEXT,
    `url`            VARCHAR(2048) DEFAULT '',
    FOREIGN KEY (`audit_id`) REFERENCES `audits`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_reports` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `audit_id`    INT UNSIGNED NOT NULL UNIQUE,
    `html_report` LONGTEXT,
    `pdf_path`    VARCHAR(500) DEFAULT '',
    `audit_data`  LONGTEXT,
    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`audit_id`) REFERENCES `audits`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
