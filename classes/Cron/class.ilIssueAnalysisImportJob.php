<?php declare(strict_types=1);

use ILIAS\Cron\Schedule\CronJobScheduleType;

/**
 * Cron job class for IssueAnalysis plugin
 * Handles automated import of error log entries
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 * @version 1.0.0
 */
class ilIssueAnalysisImportJob extends ilCronJob
{
    public const CRON_JOB_ID = "xial_import";

    private ilIssueAnalysisSettings $settings;
    private ilIssueAnalysisImporter $importer;
    private ilLogger $logger;

    public function __construct()
    {
        global $DIC;

        require_once __DIR__ . '/../class.ilIssueAnalysisSettings.php';
        require_once __DIR__ . '/../class.ilIssueAnalysisImporter.php';

        $this->settings = new ilIssueAnalysisSettings();
        $this->importer = new ilIssueAnalysisImporter();
        $this->logger = $DIC->logger()->xial();
    }

    /**
     * Get cron job ID
     */
    public function getId(): string
    {
        return self::CRON_JOB_ID;
    }

    /**
     * Get cron job title
     */
    public function getTitle(): string
    {
        return 'IssueAnalysis Log Import';
    }

    /**
     * Get cron job description
     */
    public function getDescription(): string
    {
        return 'Imports error log entries from ILIAS error log files into the IssueAnalysis plugin database.';
    }

    /**
     * Check if cron job is currently active
     */
    public function isActive(): bool
    {
        // Cron job activation is now managed through ILIAS Cron Manager only
        return true;
    }

    /**
     * Check if cron job has flexible schedule
     */
    public function hasFlexibleSchedule(): bool
    {
        return true;
    }

    /**
     * Get default schedule type
     */
    public function getDefaultScheduleType(): CronJobScheduleType
    {
        return CronJobScheduleType::SCHEDULE_TYPE_IN_MINUTES;
    }

    /**
     * Get default schedule value
     */
    public function getDefaultScheduleValue(): ?int
    {
        return 30; // Run every 30 minutes by default
    }

    /**
     * Check if auto-activation is possible
     */
    public function hasAutoActivation(): bool
    {
        return false;
    }

    /**
     * Check if custom settings are available
     */
    public function hasCustomSettings(): bool
    {
        return false;
    }

    /**
     * Run the cron job
     */
    public function run(): ilCronJobResult
    {
        $result = new ilCronJobResult();

        try {
            $this->logger->info('Starting IssueAnalysis cron import');

            // Run the import
            $import_result = $this->importer->importLogs(true);

            if ($import_result['success']) {
                $result->setStatus(ilCronJobResult::STATUS_OK);
                $message = sprintf(
                    'Import successful: %d new entries imported, %d skipped, %d processed total',
                    $import_result['imported'],
                    $import_result['skipped'],
                    $import_result['total_processed']
                );
                $result->setMessage($message);
                $this->logger->info($message);
            } else {
                $result->setStatus(ilCronJobResult::STATUS_CRASHED);
                $message = sprintf(
                    'Import completed with errors: %d imported, %d errors. Error messages: %s',
                    $import_result['imported'],
                    $import_result['errors'],
                    implode('; ', $import_result['error_messages'])
                );
                $result->setMessage($message);
                $this->logger->error($message);
            }

        } catch (Exception $e) {
            $result->setStatus(ilCronJobResult::STATUS_CRASHED);
            $error_message = 'IssueAnalysis cron import failed: ' . $e->getMessage();
            $result->setMessage($error_message);
            $this->logger->error($error_message, ['exception' => $e]);
        }

        return $result;
    }

    /**
     * Add custom settings to cron job form (if needed in future)
     */
    public function addCustomSettingsToForm(ilPropertyFormGUI $form): void
    {
        // No custom settings currently needed
        // Settings are managed through the plugin's own settings interface
    }

    /**
     * Save custom settings from cron job form (if needed in future)
     */
    public function saveCustomSettings(ilPropertyFormGUI $form): bool
    {
        // No custom settings currently needed
        return true;
    }

    /**
     * Get custom settings values (if needed in future)
     */
    public function addToExternalSettingsForm(int $form_id, array &$fields, bool $is_active): void
    {
        // No external settings needed
    }

    /**
     * Get cron job status information
     */
    public function getStatusInfo(): string
    {
        $info = [];

        // Add plugin settings info
        $info[] = 'Time limit: ' . $this->settings->getImportTimeLimit() . ' seconds';
        $info[] = 'Line limit: ' . $this->settings->getImportLineLimit() . ' lines';
        $info[] = 'Error log directory: ' . ($this->settings->getErrorLogDirectory() ?: 'Not configured');

        // Add last run info
        $last_cron_import = $this->settings->getLastCronImport();
        if ($last_cron_import > 0) {
            $info[] = 'Last run: ' . date('Y-m-d H:i:s', $last_cron_import);
        } else {
            $info[] = 'Last run: Never';
        }

        return implode("\n", $info);
    }

    /**
     * Check if we can run this cron job
     */
    protected function canRun(): bool
    {
        // Check if error log directory is configured
        $error_log_dir = $this->settings->getErrorLogDirectory();
        if (empty($error_log_dir) || !is_dir($error_log_dir)) {
            return false;
        }

        // Check if we have read permissions
        if (!is_readable($error_log_dir)) {
            return false;
        }

        return true;
    }

    /**
     * Get activation description for admin interface
     */
    public function getActivationDescription(): string
    {
        return 'This cron job imports error log entries from the configured ILIAS error log directory. ' .
               'It can be enabled/disabled through the IssueAnalysis plugin settings. ' .
               'When active, it runs periodically to import new error entries into the plugin database for analysis.';
    }

    /**
     * Get manual start description
     */
    public function getManualStartDescription(): string
    {
        return 'Manually start the error log import process. This will import new error entries from the ' .
               'configured log directory according to the plugin settings (time limits, line limits, etc.).';
    }
}
