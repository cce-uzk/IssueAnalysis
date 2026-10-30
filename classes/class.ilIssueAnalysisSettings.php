<?php

/**
 * This file is part of the IssueAnalysis plugin for ILIAS.
 *
 * Copyright (c) University of Cologne, CompetenceCenter E-Learning
 *
 * The plugin is licensed with the GPL-3.0,
 * see https://www.gnu.org/licenses/gpl-3.0.en.html
 * You should have received a copy of said license along with the
 * source code, too.
 */

declare(strict_types=1);

// Load plugin bootstrap (includes Composer autoloader)
require_once __DIR__ . '/bootstrap.php';

/**
 * Settings management for IssueAnalysis plugin
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class ilIssueAnalysisSettings
{
    private ilSetting $settings;

    // Default settings
    private const DEFAULT_RETENTION_DAYS = 30;
    private const DEFAULT_MAX_RECORDS = 10000;
    private const DEFAULT_MASK_SENSITIVE = true;
    private const DEFAULT_IMPORT_TIME_LIMIT = 120;
    private const DEFAULT_IMPORT_LINE_LIMIT = 1000;

    public function __construct()
    {
        $this->settings = new ilSetting('xial');
    }

    /**
     * Get retention period in days
     */
    public function getRetentionDays(): int
    {
        return (int) $this->settings->get('retention_days', (string) self::DEFAULT_RETENTION_DAYS);
    }

    /**
     * Set retention period in days
     */
    public function setRetentionDays(int $days): void
    {
        $oldValue = $this->getRetentionDays();
        $this->settings->set('retention_days', (string) $days);

        if ($oldValue !== $days) {
            $this->applyRetentionChange($oldValue, $days);
        }
    }

    /**
     * Get maximum number of records to keep
     */
    public function getMaxRecords(): int
    {
        return (int) $this->settings->get('max_records', (string) self::DEFAULT_MAX_RECORDS);
    }

    /**
     * Set maximum number of records to keep
     */
    public function setMaxRecords(int $records): void
    {
        $this->settings->set('max_records', (string) $records);
    }

    /**
     * Check if sensitive data should be masked
     */
    public function getMaskSensitive(): bool
    {
        return (bool) $this->settings->get('mask_sensitive', (string) self::DEFAULT_MASK_SENSITIVE);
    }

    /**
     * Set whether sensitive data should be masked
     */
    public function setMaskSensitive(bool $mask): void
    {
        $this->settings->set('mask_sensitive', $mask ? '1' : '0');
    }


    /**
     * Get import time limit in seconds
     */
    public function getImportTimeLimit(): int
    {
        return (int) $this->settings->get('import_time_limit', (string) self::DEFAULT_IMPORT_TIME_LIMIT);
    }

    /**
     * Set import time limit in seconds
     */
    public function setImportTimeLimit(int $seconds): void
    {
        $this->settings->set('import_time_limit', (string) $seconds);
    }

    /**
     * Get import line limit per execution
     */
    public function getImportLineLimit(): int
    {
        return (int) $this->settings->get('import_line_limit', (string) self::DEFAULT_IMPORT_LINE_LIMIT);
    }

    /**
     * Set import line limit per execution
     */
    public function setImportLineLimit(int $lines): void
    {
        $this->settings->set('import_line_limit', (string) $lines);
    }

    /**
     * Get ILIAS error log directory from core settings
     *
     * Only the directory configured in ILIAS (Logging → Error Logging, error_path in
     * ilias.ini.php) is used; '' if it is not configured or not readable.
     */
    public function getErrorLogDirectory(): string
    {
        $folder = ilLoggingErrorSettings::getInstance()->folder();

        return $folder !== '' && is_dir($folder) && is_readable($folder) ? $folder : '';
    }

    /**
     * Get last manual import timestamp
     */
    public function getLastManualImport(): int
    {
        return (int) $this->settings->get('last_manual_import', '0');
    }

    /**
     * Set last manual import timestamp
     */
    public function setLastManualImport(int $timestamp): void
    {
        $this->settings->set('last_manual_import', (string) $timestamp);
    }

    /**
     * Get last cron import timestamp
     */
    public function getLastCronImport(): int
    {
        return (int) $this->settings->get('last_cron_import', '0');
    }

    /**
     * Set last cron import timestamp
     */
    public function setLastCronImport(int $timestamp): void
    {
        $this->settings->set('last_cron_import', (string) $timestamp);
    }

    /**
     * Get all settings as array
     *
     * @return array<string, int|bool|string>
     */
    public function getAllSettings(): array
    {
        return [
            'retention_days' => $this->getRetentionDays(),
            'max_records' => $this->getMaxRecords(),
            'mask_sensitive' => $this->getMaskSensitive(),
            'import_time_limit' => $this->getImportTimeLimit(),
            'import_line_limit' => $this->getImportLineLimit(),
            'error_log_dir' => $this->getErrorLogDirectory(),
            'last_manual_import' => $this->getLastManualImport(),
            'last_cron_import' => $this->getLastCronImport()
        ];
    }

    /**
     * Reset all settings to defaults
     */
    public function resetToDefaults(): void
    {
        $this->setRetentionDays(self::DEFAULT_RETENTION_DAYS);
        $this->setMaxRecords(self::DEFAULT_MAX_RECORDS);
        $this->setMaskSensitive(self::DEFAULT_MASK_SENSITIVE);
        $this->setImportTimeLimit(self::DEFAULT_IMPORT_TIME_LIMIT);
        $this->setImportLineLimit(self::DEFAULT_IMPORT_LINE_LIMIT);
    }

    /**
     * Adjust the imported data to a changed retention period
     *
     * The import tracking is reset, so that files skipped because of the old period
     * are read again. If the period was reduced, older entries are deleted at once.
     */
    private function applyRetentionChange(int $oldDays, int $newDays): void
    {
        $repo = new ilIssueAnalysisRepo();
        $repo->clearSourceTracking();

        if ($newDays > 0 && ($oldDays === 0 || $newDays < $oldDays)) {
            // 0 = do not apply the maximum number of records here
            $repo->deleteOldEntries($newDays, 0);
        }
    }

    /**
     * Validate settings
     * Returns array of language keys for validation errors (use $plugin->txt() to translate)
     *
     * @param array<string, mixed> $settings
     * @return string[] Language keys of the errors
     */
    public function validateSettings(array $settings): array
    {
        $errors = [];

        if (isset($settings['retention_days']) && ($settings['retention_days'] < 1 || $settings['retention_days'] > 365)) {
            $errors[] = 'validation_retention_days';
        }

        if (isset($settings['max_records']) && ($settings['max_records'] < 100 || $settings['max_records'] > 100000)) {
            $errors[] = 'validation_max_records';
        }

        if (isset($settings['import_time_limit']) && ($settings['import_time_limit'] < 30 || $settings['import_time_limit'] > 600)) {
            $errors[] = 'validation_time_limit';
        }

        if (isset($settings['import_line_limit']) && ($settings['import_line_limit'] < 100 || $settings['import_line_limit'] > 10000)) {
            $errors[] = 'validation_line_limit';
        }

        return $errors;
    }
}
