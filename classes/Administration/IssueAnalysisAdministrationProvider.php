<?php declare(strict_types=1);

namespace ILIAS\Plugin\IssueAnalysis\Administration;

use ILIAS\GlobalScreen\Identification\IdentificationInterface;
use ILIAS\GlobalScreen\Scope\MainMenu\Provider\AbstractStaticMainMenuPluginProvider;
use ILIAS\GlobalScreen\Scope\MainMenu\Factory\Item\Link;
use ilIssueAnalysisPlugin;

/**
 * Administration Provider for IssueAnalysis plugin
 * Adds menu entries to ILIAS administration
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class IssueAnalysisAdministrationProvider extends AbstractStaticMainMenuPluginProvider
{
    /**
     * Get static top items (required by interface)
     */
    public function getStaticTopItems(): array
    {
        return [];
    }

    /**
     * Get static sub items for administration
     */
    public function getStaticSubItems(): array
    {
        global $DIC;

        // Debug log - ALWAYS log this, even if other conditions fail
        // error_log("IssueAnalysis AdministrationProvider: getStaticSubItems() called at " . date('Y-m-d H:i:s'));

        $items = [];

        // Only show for administrators
        $user = $DIC->user();
        $access = $DIC->access();

        if ($user->getId() === ANONYMOUS_USER_ID || !$access->checkAccess('read', '', SYSTEM_FOLDER_ID)) {
            return [];
        }

        $plugin = $this->getPluginObject();
        if (!$plugin || !$plugin->isActive()) {
            return [];
        }

        $plugin->loadLanguageModule();

        // Get the Administration parent identifier from StandardTopItemsProvider
        use ILIAS\MainMenu\Provider\StandardTopItemsProvider;
        $administration_parent = StandardTopItemsProvider::getInstance()->getAdministrationIdentification();

        // Create Fehleranalyse menu item under Administration
        $ctrl = $DIC->ctrl();

        // Build link to our configuration GUI
        $link = $ctrl->getLinkTargetByClass([
            'ilAdministrationGUI',
            'ilObjComponentSettingsGUI',
            'ilIssueAnalysisConfigGUI'
        ], 'showList');

        $icon = $DIC->ui()->factory()->symbol()->icon()->standard(
            'logs',
            $plugin->txt('menu_fehleranalyse')
        );

        $fehleranalyse_item = $this->globalScreen()->mainBar()->link(
            $this->if->identifier('issue_analysis_admin')
        )
            ->withTitle($plugin->txt('menu_fehleranalyse'))
            ->withAction($link)
            ->withSymbol($icon)
            ->withParent($administration_parent)
            ->withPosition(500)
            ->withVisibilityCallable(function () use ($user, $access): bool {
                return $user->getId() !== ANONYMOUS_USER_ID &&
                       $access->checkAccess('read', '', SYSTEM_FOLDER_ID);
            });

        $items[] = $fehleranalyse_item;

        // error_log("IssueAnalysis AdministrationProvider: Item created successfully");

        return $items;
    }

}
