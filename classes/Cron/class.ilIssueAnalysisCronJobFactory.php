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
                return new ilIssueAnalysisImportJob();

            default:
                return null;
        }
    }

    /**
     * Get all available cron jobs
     *
     * @return ilCronJob[]
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
