<?php declare(strict_types=1);

namespace xial\mainbar;

use ILIAS\GlobalScreen\Identification\IdentificationInterface;
use ILIAS\GlobalScreen\Scope\MainMenu\Factory\Item\AbstractChildItem;
use ILIAS\GlobalScreen\Scope\MainMenu\Provider\AbstractStaticMainMenuPluginProvider;
use ILIAS\MainMenu\Provider\StandardTopItemsProvider;

/**
 * MainBar Provider for IssueAnalysis plugin
 * Adds Issue Analysis as sub-item under Administration menu
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 * @version 1.0.0
 */
class ilIssueAnalysisMainBarProvider extends AbstractStaticMainMenuPluginProvider
{
    /**
     * @return array No top-level items - we integrate into existing Administration
     */
    public function getStaticTopItems(): array
    {
        return []; // No top-level items, we integrate into Administration
    }

    /**
     * @return AbstractChildItem[]
     */
    public function getStaticSubItems(): array
    {
        global $DIC;

        $user = $DIC->user();
        $access = $DIC->access();
        $ctrl = $DIC->ctrl();

        // Only show for administrators
        if ($user->getId() === ANONYMOUS_USER_ID || !$access->checkAccess('read', '', SYSTEM_FOLDER_ID)) {
            return [];
        }

        $plugin = $this->plugin;
        if (!$plugin || !$plugin->isActive()) {
            return [];
        }

        $plugin->loadLanguageModule();

        try {
            // Create simple admin link
            $ctrl->setParameterByClass('ilIssueAnalysisAdminGUI', 'ref_id', SYSTEM_FOLDER_ID);
            $link = $ctrl->getLinkTargetByClass([
                'ilAdministrationGUI',
                'ilIssueAnalysisAdminGUI'
            ], 'showIssueAnalysis');

            // Get administration identifier
            $admin_id = StandardTopItemsProvider::getInstance()->getAdministrationIdentification();

            // Create icon
            $icon = $DIC->ui()->factory()->symbol()->icon()
                       ->custom(
                           $plugin->getDirectory() . '/templates/images/icon_issueanalysis.svg',
                           $plugin->txt('plugin_title')
                       );

            // Create menu item
            $item = $this->globalScreen()->mainBar()->link($this->if->identifier('issue_analysis_admin'))
                ->withTitle($plugin->txt('menu_fehleranalyse'))
                ->withAction($link)
                ->withSymbol($icon)
                ->withParent($admin_id)
                ->withPosition(100)
                ->withVisibilityCallable(function () use ($user, $access): bool {
                    return $user->getId() !== ANONYMOUS_USER_ID &&
                           $access->checkAccess('read', '', SYSTEM_FOLDER_ID);
                });

            return [$item];

        } catch (Exception $e) {
            return [];
        }
    }


}
