<?php declare(strict_types=1);

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
    private ilLanguage $lng;
    private ilTabsGUI $tabs;
    private ilIssueAnalysisPlugin $plugin;

    public function __construct()
    {
        global $DIC;

        $this->ctrl = $DIC->ctrl();
        $this->tpl = $DIC->ui()->mainTemplate();
        $this->lng = $DIC->language();
        $this->tabs = $DIC->tabs();
    }

    /**
     * Handles commands - ConfigGUI only handles plugin settings
     */
    public function performCommand(string $cmd): void
    {
        $this->plugin = $this->getPluginObject();

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
        require_once __DIR__ . '/class.ilIssueAnalysisSettings.php';

        // Set page title, description and icon
        $this->tpl->setTitle($this->plugin->txt('plugin_title'));
        $this->tpl->setDescription($this->plugin->txt('plugin_description'));
        $this->tpl->setTitleIcon(
            $this->plugin->getDirectory() . '/templates/images/icon_issueanalysis.svg',
            $this->plugin->txt('plugin_title')
        );

        // Only show settings tab in plugin configuration
        $this->tabs->addTab(
            'settings',
            $this->plugin->txt('tab_settings'),
            $this->ctrl->getLinkTarget($this, 'showSettings')
        );
        $this->tabs->activateTab('settings');

        // Create cron job information as simple HTML before form
        $cron_info_text = sprintf(
            $this->plugin->txt('settings_cron_info_text'),
            $this->plugin->txt('cron_job_title'),
            'xial_import'
        );

        $cron_info_html = '<div style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px; padding: 15px; margin-bottom: 20px;">' .
                         '<h4 style="margin-top: 0;">' . $this->plugin->txt('settings_cron_info_title') . '</h4>' .
                         '<p style="margin-bottom: 0;"><strong>ℹ️ ' . $cron_info_text . '</strong></p>' .
                         '</div>';

        // Create settings form using modern UI
        $settings = new ilIssueAnalysisSettings();
        $form = $this->buildModernSettingsForm($settings);

        $this->tpl->setContent($cron_info_html . $form);
    }

    /**
     * Save settings
     */
    private function saveSettings(): void
    {
        require_once __DIR__ . '/class.ilIssueAnalysisSettings.php';

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
            // Check if retention days are changing
            $oldRetentionDays = $settings->getRetentionDays();
            $newRetentionDays = (int)$result['retention_days'];

            // Save the validated data
            $settings->setRetentionDays($newRetentionDays);
            $settings->setMaxRecords((int)$result['max_entries']);
            $settings->setMaskSensitive((bool)$result['sanitize_data']);
            $settings->setImportTimeLimit((int)$result['import_time_limit']);
            $settings->setImportLineLimit((int)$result['import_line_limit']);

            // If retention changed, clear source tracking and cleanup existing data
            if ($oldRetentionDays !== $newRetentionDays) {
                try {
                    require_once __DIR__ . '/class.ilIssueAnalysisRepo.php';
                    global $DIC;
                    if (isset($DIC) && $DIC->offsetExists('database')) {
                        $repo = new ilIssueAnalysisRepo($DIC->database());
                        $repo->clearSourceTracking();

                        // If retention period was reduced, also clean up existing data
                        if ($newRetentionDays > 0 && ($oldRetentionDays === 0 || $newRetentionDays < $oldRetentionDays)) {
                            $repo->deleteOldEntries($newRetentionDays, 0); // 0 = don't apply max records limit
                        }
                    }
                } catch (Exception $e) {
                    // Silent fail - tracking will be cleared on next manual action
                }
            }

            $this->tpl->setOnScreenMessage('success', $this->plugin->txt('msg_settings_saved'));
        } else {
            $this->tpl->setOnScreenMessage('failure', $this->plugin->txt('msg_invalid_form_data'));
        }

        $this->showSettings();
    }

    /**
     * Build modern settings form using ILIAS 9 UI controls
     */
    private function buildModernSettingsForm(ilIssueAnalysisSettings $settings): string
    {
        global $DIC;

        $ui_factory = $DIC->ui()->factory();
        $renderer = $DIC->ui()->renderer();

        // Create form inputs using modern ILIAS 9 UI
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
