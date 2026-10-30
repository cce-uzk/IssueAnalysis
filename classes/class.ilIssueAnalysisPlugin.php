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
 * Class ilIssueAnalysisPlugin
 * IssueAnalysis Plugin - ILIAS Error Log Analysis for Administrators
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class ilIssueAnalysisPlugin extends ilUserInterfaceHookPlugin implements ilCronJobProvider
{
    private const PLUGIN_ID = "xial";
    private const PLUGIN_NAME = "IssueAnalysis";
    private const CTYPE = "Services";
    private const CNAME = "UIComponent";
    private const SLOT_ID = "uihk";

    private static ?ilIssueAnalysisPlugin $instance = null;

    /**
     * Registers the main menu provider when GlobalScreen is available
     */
    public function __construct(
        ilDBInterface $db,
        ilComponentRepositoryWrite $component_repository,
        string $id
    ) {
        global $DIC;

        parent::__construct($db, $component_repository, $id);

        if (!isset($DIC["global_screen"])) {
            return;
        }

        // Add MainBarProvider to provider collection
        $this->addPluginProviders();
    }

    /**
     * Add the main menu provider to the provider collection of the plugin
     */
    private function addPluginProviders(): void
    {
        global $DIC;

        $this->getGlobalScreenProviderCollection()->setMainBarProvider(
            new \xial\mainbar\ilIssueAnalysisMainBarProvider($DIC, $this)
        );
    }

    /**
     * Get plugin instance
     */
    public static function getInstance(): ?ilIssueAnalysisPlugin
    {
        global $DIC;

        if (self::$instance instanceof ilIssueAnalysisPlugin) {
            return self::$instance;
        }

        /** @var ilComponentRepository $component_repository */
        $component_repository = $DIC['component.repository'];
        /** @var ilComponentFactory $component_factory */
        $component_factory = $DIC['component.factory'];

        $plugin_info = $component_repository->getComponentByTypeAndName(
            self::CTYPE,
            self::CNAME
        )->getPluginSlotById(self::SLOT_ID)->getPluginByName(self::PLUGIN_NAME);

        $plugin = $component_factory->getPlugin($plugin_info->getId());
        if (!$plugin instanceof self) {
            return null;
        }

        self::$instance = $plugin;
        return self::$instance;
    }

    /**
     * Get plugin name
     */
    public function getPluginName(): string
    {
        return self::PLUGIN_NAME;
    }

    /**
     * Get plugin ID
     */
    public static function getPluginId(): string
    {
        return self::PLUGIN_ID;
    }

    /**
     * Plugin has configuration
     */
    public function hasConfigOptions(): bool
    {
        return true;
    }

    /**
     * Get configuration GUI class name
     */
    public function getConfigGUIClassName(): string
    {
        return 'ilIssueAnalysisConfigGUI';
    }

    /**
     * Load plugin language module
     */
    public function loadLanguageModule(): void
    {
        global $DIC;
        $lng = $DIC->language();
        $lng->loadLanguageModule('xial');
    }

    /**
     * Get UI Hook GUI
     */
    public function getUIClassInstance(): ilIssueAnalysisUIHookGUI
    {
        return new ilIssueAnalysisUIHookGUI();
    }

    /**
     * Get cron job instances
     */
    public function getCronJobInstances(): array
    {
        $factory = new ilIssueAnalysisCronJobFactory();
        return $factory->getAll();
    }

    /**
     * Get cron job instance by ID
     */
    public function getCronJobInstance(string $jobId): ilCronJob
    {
        $factory = new ilIssueAnalysisCronJobFactory();
        $job = $factory->getInstance($jobId);

        if ($job === null) {
            throw new OutOfBoundsException("Cron job with ID '$jobId' not found");
        }

        return $job;
    }

    /**
     * Remove cron job, tables and settings; languages and the plugin state
     * are removed by ilPlugin::uninstall() afterwards
     */
    protected function beforeUninstall(): bool
    {
        $this->cleanupCronJobs();
        $this->dropTables();
        $this->removeSettings();

        return true;
    }


    /**
     * Drop plugin database tables
     */
    private function dropTables(): void
    {
        global $DIC;
        $db = $DIC->database();

        if ($db->tableExists('xial_log')) {
            $db->dropSequence('xial_log');
            $db->dropTable('xial_log');
        }
        if ($db->tableExists('xial_source')) {
            $db->dropTable('xial_source');
        }
        if ($db->tableExists('xial_error')) {
            $db->dropTable('xial_error');
        }
        // Removed by dbupdate step 5, but still present if that step never ran
        if ($db->tableExists('xial_detail')) {
            $db->dropTable('xial_detail');
        }
    }


    /**
     * Remove plugin settings
     */
    private function removeSettings(): void
    {
        $settings = new ilSetting('xial');
        $settings->deleteAll();
    }

    /**
     * Clean up cron jobs on uninstall
     */
    private function cleanupCronJobs(): void
    {
        global $DIC;

        try {
            $cron_manager = $DIC->cron()->manager();
            $job_id = ilIssueAnalysisImportJob::CRON_JOB_ID;

            if ($cron_manager->isJobActive($job_id)) {
                $cron_manager->deactivateJob($this->getCronJobInstance($job_id), $DIC->user());
            }
        } catch (Exception $e) {
            // Do not fail the uninstall because of the cron job, but leave a trace
            $DIC->logger()->root()->warning('IssueAnalysis: could not deactivate cron job: ' . $e->getMessage());
        }
    }
}
