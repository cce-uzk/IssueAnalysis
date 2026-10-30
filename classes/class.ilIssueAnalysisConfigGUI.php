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
 * Configuration GUI class for IssueAnalysis plugin
 * Provides the administration interface accessible via plugin configuration
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 *
 * @ilCtrl_IsCalledBy ilIssueAnalysisConfigGUI: ilObjComponentSettingsGUI
 */
class ilIssueAnalysisConfigGUI extends ilPluginConfigGUI
{
    private ilCtrl $ctrl;
    private ilGlobalPageTemplate $tpl;
    private ilTabsGUI $tabs;
    private ilIssueAnalysisPlugin $plugin;

    public function __construct()
    {
        global $DIC;

        $this->ctrl = $DIC->ctrl();
        $this->tpl = $DIC->ui()->mainTemplate();
        $this->tabs = $DIC->tabs();
    }

    /**
     * Handles commands - ConfigGUI only handles plugin settings
     */
    public function performCommand(string $cmd): void
    {
        $plugin = $this->getPluginObject();
        if (!$plugin instanceof ilIssueAnalysisPlugin) {
            throw new LogicException('ilIssueAnalysisConfigGUI requires the IssueAnalysis plugin object');
        }
        $this->plugin = $plugin;

        switch ($cmd) {
            case 'configure':
            case '':
            case 'showSettings':
                $this->showSettings();
                break;

            case 'saveSettings':
                $this->saveSettings();
                break;

            default:
                $this->showSettings();
                break;
        }
    }

    /**
     * Show settings (only functionality in ConfigGUI)
     */
    private function showSettings(): void
    {

        // Set page title, description and icon
        $this->tpl->setTitle($this->plugin->txt('plugin_title'));
        $this->tpl->setDescription($this->plugin->txt('plugin_description'));
        $this->tpl->setTitleIcon(
            $this->plugin->getRelativeDirectory() . '/templates/images/icon_issueanalysis.svg',
            $this->plugin->txt('plugin_title')
        );

        // Only show settings tab in plugin configuration
        $this->tabs->addTab(
            'settings',
            $this->plugin->txt('tab_settings'),
            $this->ctrl->getLinkTarget($this, 'showSettings')
        );
        $this->tabs->activateTab('settings');

        $settings = new ilIssueAnalysisSettings();
        $form = $this->buildSettingsForm($settings);

        $this->tpl->setContent($this->renderCronInfo() . $form);
    }

    /**
     * Info message box about the cron job, with a link to the cron job administration
     */
    private function renderCronInfo(): string
    {
        global $DIC;

        $ui_factory = $DIC->ui()->factory();

        $message_box = $ui_factory->messageBox()->info(
            sprintf(
                $this->plugin->txt('settings_cron_info_text'),
                $this->plugin->txt('cron_job_title'),
                ilIssueAnalysisImportJob::CRON_JOB_ID
            )
        );

        // The link needs the ref_id of the system folder; the ref_id of the plugin
        // administration is restored afterwards, so that later links of this page keep it
        $query = $DIC->http()->wrapper()->query();
        if ($query->has('ref_id')) {
            $current_ref_id = $query->retrieve('ref_id', $DIC->refinery()->kindlyTo()->int());

            $this->ctrl->setParameterByClass(ilAdministrationGUI::class, 'ref_id', SYSTEM_FOLDER_ID);
            $cron_link = $this->ctrl->getLinkTargetByClass(
                [ilAdministrationGUI::class, ilObjSystemFolderGUI::class, ilCronManagerGUI::class],
                'render'
            );
            $this->ctrl->setParameterByClass(ilAdministrationGUI::class, 'ref_id', $current_ref_id);

            $message_box = $message_box->withLinks([
                $ui_factory->link()->standard($this->plugin->txt('settings_cron_info_link'), $cron_link)
            ]);
        }

        return $DIC->ui()->renderer()->render($message_box);
    }

    /**
     * Save settings
     */
    private function saveSettings(): void
    {

        global $DIC;
        $ui_factory = $DIC->ui()->factory();

        $settings = new ilIssueAnalysisSettings();

        // Rebuild form to process input
        $inputs = [];

        $inputs['retention_days'] = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('settings_retention_days'),
            $this->plugin->txt('settings_retention_days_info')
        )->withValue($settings->getRetentionDays());

        $inputs['max_entries'] = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('settings_max_records'),
            $this->plugin->txt('settings_max_records_info')
        )->withValue($settings->getMaxRecords());

        $inputs['sanitize_data'] = $ui_factory->input()->field()->checkbox(
            $this->plugin->txt('settings_mask_sensitive'),
            $this->plugin->txt('settings_mask_sensitive_info')
        )->withValue($settings->getMaskSensitive());


        $inputs['import_time_limit'] = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('settings_import_time_limit'),
            $this->plugin->txt('settings_import_time_limit_info')
        )->withValue($settings->getImportTimeLimit());

        $inputs['import_line_limit'] = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('settings_import_line_limit'),
            $this->plugin->txt('settings_import_line_limit_info')
        )->withValue($settings->getImportLineLimit());

        $form = $ui_factory->input()->container()->form()->standard(
            $this->ctrl->getFormAction($this, 'saveSettings'),
            $inputs
        );

        $request = $DIC->http()->request();
        $form = $form->withRequest($request);
        $result = $form->getData();

        if ($result !== null) {
            // A changed retention period also adjusts the imported data, see setRetentionDays()
            $settings->setRetentionDays((int) $result['retention_days']);
            $settings->setMaxRecords((int) $result['max_entries']);
            $settings->setMaskSensitive((bool) $result['sanitize_data']);
            $settings->setImportTimeLimit((int) $result['import_time_limit']);
            $settings->setImportLineLimit((int) $result['import_line_limit']);

            $this->tpl->setOnScreenMessage('success', $this->plugin->txt('msg_settings_saved'));
        } else {
            $this->tpl->setOnScreenMessage('failure', $this->plugin->txt('msg_invalid_form_data'));
        }

        $this->showSettings();
    }

    /**
     * Rendered settings form with the current values
     */
    private function buildSettingsForm(ilIssueAnalysisSettings $settings): string
    {
        global $DIC;

        $ui_factory = $DIC->ui()->factory();
        $renderer = $DIC->ui()->renderer();

        $inputs = [];

        // Retention days
        $inputs['retention_days'] = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('settings_retention_days'),
            $this->plugin->txt('settings_retention_days_info')
        )->withValue($settings->getRetentionDays());

        // Max records
        $inputs['max_entries'] = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('settings_max_records'),
            $this->plugin->txt('settings_max_records_info')
        )->withValue($settings->getMaxRecords());

        // Mask sensitive data
        $inputs['sanitize_data'] = $ui_factory->input()->field()->checkbox(
            $this->plugin->txt('settings_mask_sensitive'),
            $this->plugin->txt('settings_mask_sensitive_info')
        )->withValue($settings->getMaskSensitive());


        // Import time limit
        $inputs['import_time_limit'] = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('settings_import_time_limit'),
            $this->plugin->txt('settings_import_time_limit_info')
        )->withValue($settings->getImportTimeLimit());

        // Import line limit
        $inputs['import_line_limit'] = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('settings_import_line_limit'),
            $this->plugin->txt('settings_import_line_limit_info')
        )->withValue($settings->getImportLineLimit());

        // Create form
        $form = $ui_factory->input()->container()->form()->standard(
            $this->ctrl->getFormAction($this, 'saveSettings'),
            $inputs
        );

        return $renderer->render($form);
    }
}
