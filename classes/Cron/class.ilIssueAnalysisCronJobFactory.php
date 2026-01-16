<?php declare(strict_types=1);

// Load plugin bootstrap (includes Composer autoloader)
require_once __DIR__ . '/../bootstrap.php';

/**
 * Cron job factory for IssueAnalysis plugin
 * Provides cron jobs for the plugin
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class ilIssueAnalysisCronJobFactory
{
    /**
     * Get cron job instance by ID
     */
    public function getInstance(string $job_id): ?ilCronJob
    {
        switch ($job_id) {
            case ilIssueAnalysisImportJob::CRON_JOB_ID:
                require_once __DIR__ . '/class.ilIssueAnalysisImportJob.php';
                return new ilIssueAnalysisImportJob();

            default:
                return null;
        }
    }

    /**
     * Get all available cron jobs
     */
    public function getAll(): array
    {
        $jobs = [];
        $job = $this->getInstance(ilIssueAnalysisImportJob::CRON_JOB_ID);
        if ($job !== null) {
            $jobs[] = $job;
        }
        return $jobs;
    }
}
