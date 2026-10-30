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

namespace xial\mainbar;

use ILIAS\GlobalScreen\Scope\MainMenu\Factory\isItem;
use ILIAS\GlobalScreen\Scope\MainMenu\Factory\Item\Link;
use ILIAS\GlobalScreen\Scope\MainMenu\Provider\AbstractStaticMainMenuPluginProvider;
use ILIAS\MainMenu\Provider\StandardTopItemsProvider;

/**
 * MainBar Provider for IssueAnalysis plugin
 * Adds Issue Analysis as sub-item under Administration menu
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class ilIssueAnalysisMainBarProvider extends AbstractStaticMainMenuPluginProvider
{
    /**
     * No top-level items - the entry is part of the Administration menu
     *
     * @return isItem[]
     */
    public function getStaticTopItems(): array
    {
        return [];
    }

    /**
     * @return isItem[]
     */
    public function getStaticSubItems(): array
    {
        global $DIC;

        $plugin = $this->plugin;
        if (!$plugin->isActive()) {
            return [];
        }

        $plugin->loadLanguageModule();

        $user = $DIC->user();
        $access = $DIC->access();
        $ctrl = $DIC->ctrl();

        try {
            $ctrl->setParameterByClass(\ilIssueAnalysisAdminGUI::class, 'ref_id', SYSTEM_FOLDER_ID);
            $link = $ctrl->getLinkTargetByClass(
                [\ilAdministrationGUI::class, \ilIssueAnalysisAdminGUI::class],
                'showIssueAnalysis'
            );
        } catch (\Exception $e) {
            $DIC->logger()->xial()->warning('Menu entry could not be created: ' . $e->getMessage());
            return [];
        }

        $icon = $DIC->ui()->factory()->symbol()->icon()->custom(
            $plugin->getRelativeDirectory() . '/templates/images/icon_issueanalysis.svg',
            $plugin->txt('plugin_title')
        );

        // The interfaces of the fluent methods return isItem, the object is still a Link
        /** @var Link $item */
        $item = $this->globalScreen()->mainBar()->link($this->if->identifier('issue_analysis_admin'))
            ->withTitle($plugin->txt('menu_fehleranalyse'))
            ->withAction($link)
            ->withParent(StandardTopItemsProvider::getInstance()->getAdministrationIdentification())
            ->withPosition(100)
            ->withVisibilityCallable(static function () use ($user, $access): bool {
                return $user->getId() !== ANONYMOUS_USER_ID
                    && $access->checkAccess('read', '', SYSTEM_FOLDER_ID);
            });

        return [$item->withSymbol($icon)];
    }
}
