<?php declare(strict_types=1);

// Load plugin bootstrap (includes Composer autoloader)
require_once __DIR__ . '/../bootstrap.php';

use ILIAS\Cron\Schedule\CronJobScheduleType;

/**
 * Cron job class for IssueAnalysis plugin
 * Handles automated import of error log entries
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class ilIssueAnalysisImportJob extends ilCronJob
{
    public const CRON_JOB_ID = "xial_import";

    private ilIssueAnalysisSettings $settings;
    private ilIssueAnalysisImporter $importer;
    private ilLogger $logger;
    private ?ilIssueAnalysisPlugin $plugin = null;

    public function __construct()
    {
        global $DIC;

        require_once __DIR__ . '/../class.ilIssueAnalysisSettings.php';
        require_once __DIR__ . '/../class.ilIssueAnalysisImporter.php';

        $this->settings = new ilIssueAnalysisSettings();
        $this->importer = new ilIssueAnalysisImporter();
        $this->logger = $DIC->logger()->xial();
        $this->plugin = ilIssueAnalysisPlugin::getInstance();
    }

    /**
     * Get translated text from plugin
     */
    private function txt(string $key): string
    {
        if ($this->plugin !== null) {
            return $this->plugin->txt($key);
        }
        return $key;
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
        return $this->txt('cron_job_title');
    }

    /**
     * Get cron job description
     */
    public function getDescription(): string
    {
        return $this->txt('cron_job_description');
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
        $info[] = $this->txt('cron_status_time_limit') . ': ' . $this->settings->getImportTimeLimit() . ' ' . $this->txt('cron_status_seconds');
        $info[] = $this->txt('cron_status_line_limit') . ': ' . $this->settings->getImportLineLimit() . ' ' . $this->txt('cron_status_lines');
        $info[] = $this->txt('cron_status_error_log_dir') . ': ' . ($this->settings->getErrorLogDirectory() ?: $this->txt('cron_status_not_configured'));

        // Add last run info
        $last_cron_import = $this->settings->getLastCronImport();
        if ($last_cron_import > 0) {
            $info[] = $this->txt('cron_status_last_run') . ': ' . date('Y-m-d H:i:s', $last_cron_import);
        } else {
            $info[] = $this->txt('cron_status_last_run') . ': ' . $this->txt('cron_status_never');
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
        return $this->txt('cron_activation_description');
    }

    /**
     * Get manual start description
     */
    public function getManualStartDescription(): string
    {
        return $this->txt('cron_manual_start_description');
    }
}
