<?php declare(strict_types=1);

use ILIAS\GlobalScreen\Provider\ProviderCollection;


/**
 * Class ilIssueAnalysisPlugin
 * IssueAnalysis Plugin - ILIAS Error Log Analysis for Administrators
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 * @version 1.0.0
 */
class ilIssueAnalysisPlugin extends ilUserInterfaceHookPlugin implements ilCronJobProvider
{
    private const PLUGIN_ID = "xial";
    private const PLUGIN_NAME = "IssueAnalysis";
    private const CTYPE = "Services";
    private const CNAME = "UIComponent";
    private const SLOT_ID = "uihk";

    private static ?ilIssueAnalysisPlugin $instance = null;

    protected ProviderCollection $provider_collection;

    /**
     * Constructor - Initialize plugin and GlobalScreen providers (exact GuidedTour pattern)
     */
    public function __construct(
        ilDBInterface $db,
        ilComponentRepositoryWrite $component_repository,
        string $id
    ) {
        global $DIC;

        // Initialize plugin
        $this->db = $db;
        $this->component_repository = $component_repository;
        $this->id = $id;
        parent::__construct($db, $component_repository, $id);

        // Debug log
        error_log("=== PLUGIN CONSTRUCTOR CALLED ===");
        error_log("IssueAnalysis Plugin: Constructor called at " . date('Y-m-d H:i:s'));

        if (!isset($DIC["global_screen"])) {
            error_log("IssueAnalysis Plugin: No global_screen available");
            return;
        }

        // Add MainBarProvider to provider collection (exact GuidedTour pattern)
        $this->addPluginProviders();

        // Debug log
        error_log("IssueAnalysis Plugin: GlobalScreen providers added at " . date('Y-m-d H:i:s'));
    }

    /**
     * Add plugin providers to provider collection (exact GuidedTour pattern)
     */
    private function addPluginProviders(): void
    {
        global $DIC;

        require_once __DIR__ . '/MainBar/ilIssueAnalysisMainBarProvider.php';
        $this->provider_collection->setMainBarProvider(new \xial\mainbar\ilIssueAnalysisMainBarProvider($DIC, $this));

        error_log("=== MAINBAR PROVIDER REGISTERED ===");
        error_log("IssueAnalysis Plugin: MainBarProvider registered successfully");
    }

    /**
     * Get plugin instance
     */
    public static function getInstance(): ?ilIssueAnalysisPlugin
    {
        global $DIC;

        // Debug log
        error_log("IssueAnalysis Plugin: getInstance() called");

        if (self::$instance instanceof ilIssueAnalysisPlugin) {
            // error_log("IssueAnalysis Plugin: getInstance() returning existing instance");
            return self::$instance;
        }

        /** @var ilComponentRepository $component_repository */
        $component_repository = $DIC['component.repository'];
        /** @var ilComponentFactory $component_factory */
        $component_factory = $DIC['component.factory'];

        if (isset($component_factory) && isset($component_repository)) {
            $plugin_info = $component_repository->getComponentByTypeAndName(
                self::CTYPE,
                self::CNAME
            )->getPluginSlotById(self::SLOT_ID)->getPluginByName(self::PLUGIN_NAME);

            self::$instance = $component_factory->getPlugin($plugin_info->getId());
            error_log("IssueAnalysis Plugin: getInstance() created new instance");
            return self::$instance;
        }

        return null;
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
     * Plugin initialization
     */
    protected function init(): void
    {
        parent::init();

        // Debug log
        error_log("IssueAnalysis Plugin: init() called at " . date('Y-m-d H:i:s'));
    }

    /**
     * Get UI Hook GUI
     */
    public function getUIClassInstance(): ilIssueAnalysisUIHookGUI
    {
        require_once __DIR__ . '/class.ilIssueAnalysisUIHookGUI.php';
        return new ilIssueAnalysisUIHookGUI();
    }

    /**
     * Install plugin
     */
    public function install(): void
    {
        parent::install();
    }

    /**
     * After install hook
     */
    protected function afterInstall(): void
    {
        parent::afterInstall();
    }

    /**
     * Get cron job instances
     */
    public function getCronJobInstances(): array
    {
        require_once __DIR__ . '/Cron/class.ilIssueAnalysisCronJobFactory.php';
        $factory = new ilIssueAnalysisCronJobFactory();
        return $factory->getAll();
    }

    /**
     * Get cron job instance by ID
     */
    public function getCronJobInstance(string $jobId): ilCronJob
    {
        require_once __DIR__ . '/Cron/class.ilIssueAnalysisCronJobFactory.php';
        $factory = new ilIssueAnalysisCronJobFactory();
        $job = $factory->getInstance($jobId);

        if ($job === null) {
            throw new OutOfBoundsException("Cron job with ID '$jobId' not found");
        }

        return $job;
    }

    /**
     * Uninstall plugin
     */
    public function uninstall(): bool
    {
        global $DIC;

        // Uninstall languages
        $this->getLanguageHandler()->uninstall();

        // Deregister from component repository
        $this->component_repository->removeStateInformationOf($this->getId());

        // Drop tables
        $this->dropTables();

        // Remove plugin settings
        $this->removeSettings();

        // Clean up cron jobs
        $this->cleanupCronJobs();

        return true;
    }


    /**
     * Drop plugin database tables
     */
    private function dropTables(): void
    {
        global $DIC;
        $db = $DIC->database();

        if ($db->tableExists('xial_detail')) {
            $db->dropTable('xial_detail');
        }
        if ($db->tableExists('xial_log')) {
            $db->dropSequence('xial_log');
            $db->dropTable('xial_log');
        }
        if ($db->tableExists('xial_source')) {
            $db->dropTable('xial_source');
        }
    }


    /**
     * Remove plugin settings
     */
    private function removeSettings(): void
    {
        global $DIC;
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
            // Get cron manager and deactivate our cron jobs
            $cron_manager = $DIC['cron.manager'];
            if ($cron_manager && method_exists($cron_manager, 'isJobActive')) {
                require_once __DIR__ . '/Cron/class.ilIssueAnalysisImportJob.php';
                $job_id = ilIssueAnalysisImportJob::CRON_JOB_ID;

                if ($cron_manager->isJobActive($job_id)) {
                    $cron_manager->deactivateJob($job_id);
                }
            }
        } catch (Exception $e) {
            // Log error but don't fail uninstall
            error_log("IssueAnalysis Plugin: Error cleaning up cron jobs: " . $e->getMessage());
        }
    }
}
