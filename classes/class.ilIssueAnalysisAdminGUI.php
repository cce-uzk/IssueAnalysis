<?php declare(strict_types=1);

/**
 * Administration GUI for IssueAnalysis plugin
 * Integrates the plugin into ILIAS administration menu under System
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 * @version 1.0.0
 *
 * @ilCtrl_isCalledBy ilIssueAnalysisAdminGUI: ilAdministrationGUI
 * @ilCtrl_Calls ilIssueAnalysisAdminGUI: ilIssueAnalysisGUI
 */
class ilIssueAnalysisAdminGUI extends ilObjectGUI
{
    private ilIssueAnalysisPlugin $plugin;

    public function __construct()
    {
        global $DIC;

        // Call parent constructor with system folder as reference
        parent::__construct([], SYSTEM_FOLDER_ID, false, false);

        $this->plugin = ilIssueAnalysisPlugin::getInstance();
        if ($this->plugin) {
            $this->plugin->loadLanguageModule();
        }
    }

    /**
     * Execute command
     */
    public function executeCommand(): void
    {
        $cmd = $this->ctrl->getCmd('showIssueAnalysis');

        switch ($cmd) {
            case 'showIssueAnalysis':
            case 'showList':
            case 'importNow':
            case 'clearAllData':
            case 'handleTableActions':
            case 'viewDetails':
            case 'showStatistics':
                $this->forwardToMainGUI();
                break;
            default:
                $this->showIssueAnalysis();
                break;
        }
    }

    /**
     * Forward to main GUI
     */
    private function forwardToMainGUI(): void
    {
        // Set up custom breadcrumb and icon (without prepareOutput since we're not a real object)
        $this->setAdministrationBreadcrumb();

        // Forward to main GUI
        require_once __DIR__ . '/class.ilIssueAnalysisGUI.php';
        $gui = new ilIssueAnalysisGUI();
        $gui->executeCommand();
    }

    /**
     * Show issue analysis - forward to main GUI
     */
    private function showIssueAnalysis(): void
    {
        $this->forwardToMainGUI();
    }

    /**
     * Set administration breadcrumb navigation
     */
    private function setAdministrationBreadcrumb(): void
    {
        global $DIC;

        if ($this->plugin) {
            // Set page title, description and icon
            $this->tpl->setTitle($this->plugin->txt('plugin_title'));
            $this->tpl->setDescription($this->plugin->txt('plugin_description'));
            $this->tpl->setTitleIcon(
                $this->plugin->getDirectory() . '/templates/images/icon_issueanalysis.svg',
                $this->plugin->txt('plugin_title')
            );

            // Manually create breadcrumb navigation
            $locator = $DIC['ilLocator'];
            $locator->clearItems();

            // Add Administration root
            $this->ctrl->setParameterByClass('ilAdministrationGUI', 'ref_id', SYSTEM_FOLDER_ID);
            $this->ctrl->setParameterByClass('ilObjSystemFolderGUI', 'ref_id', SYSTEM_FOLDER_ID);
            $locator->addItem(
                $this->lng->txt('administration'),
                $this->ctrl->getLinkTargetByClass(['ilAdministrationGUI', 'ilObjSystemFolderGUI'], 'view')
            );

            // Add Fehleranalyse link
            $this->ctrl->setParameterByClass('ilIssueAnalysisAdminGUI', 'ref_id', SYSTEM_FOLDER_ID);
            $locator->addItem(
                $this->plugin->txt('menu_fehleranalyse'),
                $this->ctrl->getLinkTargetByClass(['ilAdministrationGUI', 'ilIssueAnalysisAdminGUI'], 'showIssueAnalysis')
            );

            // Set the locator in the template
            $this->tpl->setLocator();
        }
    }

    /**
     * Get administration menu entry data
     */
    public static function getAdminMenuEntry(): array
    {
        global $DIC;

        $plugin = ilIssueAnalysisPlugin::getInstance();
        if (!$plugin) {
            return [];
        }

        $ctrl = $DIC->ctrl();
        $ctrl->setParameterByClass('ilAdministrationGUI', 'ref_id', SYSTEM_FOLDER_ID);

        // Create ILIAS icon object
        $icon = $DIC->ui()->factory()->symbol()->icon()
                   ->custom(
                       $plugin->getDirectory() . '/templates/images/icon_issueanalysis.svg',
                       $plugin->txt('plugin_title')
                   );

        return [
            'title' => $plugin->txt('menu_fehleranalyse'),
            'link' => $ctrl->getLinkTargetByClass(['ilAdministrationGUI', 'ilIssueAnalysisAdminGUI'], 'showIssueAnalysis'),
            'description' => $plugin->txt('plugin_description'),
            'icon' => $icon,
            'permission' => 'read',
            'ref_id' => SYSTEM_FOLDER_ID
        ];
    }
}
