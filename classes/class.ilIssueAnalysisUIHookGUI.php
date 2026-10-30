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
 * UI Hook GUI class for IssueAnalysis plugin
 *
 * The plugin does not modify any ILIAS output; the menu entry is provided by
 * ilIssueAnalysisMainBarProvider. This class only routes requests to ilIssueAnalysisGUI.
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 *
 * @ilCtrl_isCalledBy ilIssueAnalysisUIHookGUI: ilUIPluginRouterGUI
 * @ilCtrl_isCalledBy ilIssueAnalysisUIHookGUI: ilAdministrationGUI
 */
class ilIssueAnalysisUIHookGUI extends ilUIHookPluginGUI
{
    /**
     * Execute command
     */
    public function executeCommand(): void
    {
        global $DIC;

        $DIC->ctrl()->forwardCommand(new ilIssueAnalysisGUI());
    }
}
