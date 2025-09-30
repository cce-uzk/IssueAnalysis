<?php declare(strict_types=1);

/**
 * UI Hook GUI class for IssueAnalysis plugin
 * Integrates the plugin into ILIAS administration
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 * @version 1.0.0
 *
 * @ilCtrl_isCalledBy ilIssueAnalysisUIHookGUI: ilUIPluginRouterGUI
 * @ilCtrl_isCalledBy ilIssueAnalysisUIHookGUI: ilAdministrationGUI
 */
class ilIssueAnalysisUIHookGUI extends ilUIHookPluginGUI
{
    /**
     * Get HTML for the UI hook
     */
    public function getHTML(string $component, string $part, array $parameters = []): array
    {
        return ['mode' => ilUIHookPluginGUI::KEEP, 'html' => ''];
    }

    /**
     * Modify GUI objects
     */
    public function modifyGUI(string $component, string $part, array $parameters = []): void
    {
        global $DIC;

        // Hook into administration overview page
        if ($component === 'Services/Administration' && $part === 'system_management') {
            $this->addSystemManagementEntry($parameters);
        }

        // Hook into administration template to add custom menu item
        if ($component === 'Services/Administration' && $part === 'administration_overview') {
            $this->addAdministrationOverviewEntry($parameters);
        }
    }

    /**
     * Add entry to system management section
     */
    private function addSystemManagementEntry(array $parameters): void
    {
        global $DIC;

        $user = $DIC->user();
        $access = $DIC->access();

        // Only show for administrators
        if ($user->getId() === ANONYMOUS_USER_ID || !$access->checkAccess('read', '', SYSTEM_FOLDER_ID)) {
            return;
        }

        $plugin = $this->getPluginObject();
        if (!$plugin || !$plugin->isActive()) {
            return;
        }
    }

    /**
     * Add entry to administration overview
     */
    private function addAdministrationOverviewEntry(array $parameters): void
    {
        global $DIC;

        $user = $DIC->user();
        $access = $DIC->access();
        $ctrl = $DIC->ctrl();

        // Only show for administrators
        if ($user->getId() === ANONYMOUS_USER_ID || !$access->checkAccess('read', '', SYSTEM_FOLDER_ID)) {
            return;
        }

        $plugin = $this->getPluginObject();
        if (!$plugin || !$plugin->isActive()) {
            return;
        }
    }

    /**
     * Execute command
     */
    public function executeCommand(): void
    {
        global $DIC;

        $ctrl = $DIC->ctrl();
        $next_class = $ctrl->getNextClass($this);

        switch ($next_class) {
            case 'ilissueanalysisgui':
                require_once __DIR__ . '/class.ilIssueAnalysisGUI.php';
                $gui = new ilIssueAnalysisGUI();
                $ctrl->forwardCommand($gui);
                break;

            default:
                // Default to main functionality
                require_once __DIR__ . '/class.ilIssueAnalysisGUI.php';
                $gui = new ilIssueAnalysisGUI();
                $ctrl->forwardCommand($gui);
                break;
        }
    }

}
