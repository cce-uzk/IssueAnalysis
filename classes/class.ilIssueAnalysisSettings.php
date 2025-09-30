<?php declare(strict_types=1);

/**
 * Settings management for IssueAnalysis plugin
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 * @version 1.0.0
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

        // If retention days changed, clear source tracking and cleanup existing data
        if ($oldValue !== $days) {
            $this->clearSourceTrackingOnRetentionChange();

            // If retention period was reduced, also clean up existing data that exceeds new limit
            if ($days > 0 && ($oldValue === 0 || $days < $oldValue)) {
                $this->cleanupDataOnRetentionChange($days);
            }
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
     */
    public function getErrorLogDirectory(): string
    {
        try {
            // Use the same method as ILIAS Core Logging Settings
            require_once 'Services/Logging/classes/error/class.ilLoggingErrorSettings.php';
            $errorSettings = ilLoggingErrorSettings::getInstance();
            $errorLogDir = $errorSettings->folder();

            // If we have a valid directory, return it
            if (!empty($errorLogDir) && is_dir($errorLogDir) && is_readable($errorLogDir)) {
                return $errorLogDir;
            }

            // Fallback: try to read directly from ilias.ini.php like ilLoggingErrorSettings does
            global $DIC;
            if (isset($DIC) && $DIC->offsetExists('iliasIni')) {
                $iliasIni = $DIC->iliasIni();
                $errorLogDir = $iliasIni->readVariable("log", "error_path");

                if (!empty($errorLogDir) && is_dir($errorLogDir) && is_readable($errorLogDir)) {
                    return $errorLogDir;
                }
            }

        } catch (Exception $e) {
            // Fallback handling
        }

        // Final fallback to common locations
        $possiblePaths = [
            '/var/www/logs/errors',
            ILIAS_ABSOLUTE_PATH . '/data/errors',
            '/var/log/ilias/errors'
        ];

        foreach ($possiblePaths as $path) {
            if (is_dir($path) && is_readable($path)) {
                return $path;
            }
        }

        return '';
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
     * Clear source tracking when retention changes
     */
    private function clearSourceTrackingOnRetentionChange(): void
    {
        try {
            // We need access to the repo to clear tracking
            require_once __DIR__ . '/class.ilIssueAnalysisRepo.php';
            global $DIC;
            if (isset($DIC) && $DIC->offsetExists('database')) {
                $repo = new ilIssueAnalysisRepo($DIC->database());
                $repo->clearSourceTracking();
            }
        } catch (Exception $e) {
            // Silent fail - tracking will be cleared on next manual action
        }
    }

    /**
     * Clean up existing data when retention period is reduced
     */
    private function cleanupDataOnRetentionChange(int $newRetentionDays): void
    {
        try {
            require_once __DIR__ . '/class.ilIssueAnalysisRepo.php';
            global $DIC;
            if (isset($DIC) && $DIC->offsetExists('database')) {
                $repo = new ilIssueAnalysisRepo($DIC->database());
                // Delete entries older than new retention period
                $repo->deleteOldEntries($newRetentionDays, 0); // 0 = don't apply max records limit
            }
        } catch (Exception $e) {
            // Silent fail - cleanup will happen on next scheduled cleanup
        }
    }

    /**
     * Validate settings
     */
    public function validateSettings(array $settings): array
    {
        $errors = [];

        if (isset($settings['retention_days']) && ($settings['retention_days'] < 1 || $settings['retention_days'] > 365)) {
            $errors[] = 'Retention days must be between 1 and 365';
        }

        if (isset($settings['max_records']) && ($settings['max_records'] < 100 || $settings['max_records'] > 100000)) {
            $errors[] = 'Max records must be between 100 and 100000';
        }

        if (isset($settings['import_time_limit']) && ($settings['import_time_limit'] < 30 || $settings['import_time_limit'] > 600)) {
            $errors[] = 'Import time limit must be between 30 and 600 seconds';
        }

        if (isset($settings['import_line_limit']) && ($settings['import_line_limit'] < 100 || $settings['import_line_limit'] > 10000)) {
            $errors[] = 'Import line limit must be between 100 and 10000';
        }

        return $errors;
    }
}
