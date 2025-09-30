<?php declare(strict_types=1);

/**
 * Main GUI controller for IssueAnalysis plugin
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 * @version 1.0.0
 *
 * @ilCtrl_IsCalledBy ilIssueAnalysisGUI: ilIssueAnalysisConfigGUI
 * @ilCtrl_IsCalledBy ilIssueAnalysisGUI: ilIssueAnalysisUIHookGUI
 */
class ilIssueAnalysisGUI
{
    private ilCtrl $ctrl;
    private ilGlobalPageTemplate $tpl;
    private ilLanguage $lng;
    private ilTabsGUI $tabs;
    private \ILIAS\UI\Factory $ui_factory;
    private \ILIAS\UI\Renderer $renderer;
    private \ILIAS\HTTP\Services $http;
    private \ILIAS\Data\Factory $data_factory;
    private ilAccessHandler $access;
    private ilIssueAnalysisPlugin $plugin;
    private ilIssueAnalysisRepo $repo;
    private ilIssueAnalysisSettings $settings;
    private ilIssueAnalysisImporter $importer;

    public function __construct()
    {
        global $DIC;

        $this->ctrl = $DIC->ctrl();
        $this->tpl = $DIC->ui()->mainTemplate();
        $this->lng = $DIC->language();
        $this->tabs = $DIC->tabs();
        $this->ui_factory = $DIC->ui()->factory();
        $this->renderer = $DIC->ui()->renderer();
        $this->http = $DIC->http();
        $this->data_factory = new \ILIAS\Data\Factory();
        $this->access = $DIC->access();

        $this->plugin = ilIssueAnalysisPlugin::getInstance();

        // Load required classes
        require_once __DIR__ . '/class.ilIssueAnalysisRepo.php';
        require_once __DIR__ . '/class.ilIssueAnalysisSettings.php';
        require_once __DIR__ . '/class.ilIssueAnalysisImporter.php';
        require_once __DIR__ . '/class.ilIssueAnalysisTableGUI.php';
        require_once __DIR__ . '/class.ilIssueAnalysisSanitizer.php';
        $this->repo = new ilIssueAnalysisRepo();
        $this->settings = new ilIssueAnalysisSettings();
        $this->importer = new ilIssueAnalysisImporter();

        // Load plugin language
        $this->plugin->loadLanguageModule();
    }

    /**
     * Execute command
     */
    public function executeCommand(): void
    {
        // Check administrator access
        if (!$this->checkAccess()) {
            $this->tpl->setOnScreenMessage('failure', $this->plugin->txt('msg_access_denied'));
            return;
        }

        $cmd = $this->ctrl->getCmd('showList');

        // Set up tabs
        $this->setTabs();

        switch ($cmd) {
            case 'showList':
                $this->tabs->activateTab('list');
                $this->showList();
                break;
            case 'importNow':
                $this->tabs->activateTab('list'); // Default to list tab for import
                $this->importNow();
                break;
            case 'clearAllData':
                $this->clearAllData();
                break;
            case 'handleTableActions':
                $this->handleTableActions();
                break;
            case 'viewDetails':
                $this->viewDetails();
                break;
            case 'showStatistics':
                $this->tabs->activateTab('statistics');
                $this->showStatistics();
                break;
            default:
                $this->tabs->activateTab('list');
                $this->showList();
        }
    }

    /**
     * Check administrator access
     */
    private function checkAccess(): bool
    {
        global $DIC;
        $user = $DIC->user();
        return $user->getId() === ANONYMOUS_USER_ID ? false :
               $this->access->checkAccess('read', '', SYSTEM_FOLDER_ID) ||
               $user->getType() === 'admin';
    }

    /**
     * Set up navigation tabs
     */
    private function setTabs(): void
    {
        // Main GUI only shows list and statistics tabs
        // Settings are handled separately in ConfigGUI
        // No back navigation tab to Administration (user preference)
        $this->tabs->addTab(
            'list',
            $this->plugin->txt('tab_list'),
            $this->ctrl->getLinkTarget($this, 'showList')
        );

        $this->tabs->addTab(
            'statistics',
            $this->plugin->txt('tab_statistics'),
            $this->ctrl->getLinkTarget($this, 'showStatistics')
        );
    }

    /**
     * Show error log list
     */
    private function showList(): void
    {
        // Set custom page title
        $this->tpl->setTitle($this->plugin->txt('plugin_title'));

        // Import button - handle within current context
        $import_button = $this->ui_factory->button()->primary(
            $this->plugin->txt('btn_import_now'),
            $this->ctrl->getLinkTarget($this, 'importNow')
        );

        // Clear all data button
        $clear_button = $this->ui_factory->button()->standard(
            $this->plugin->txt('btn_clear_all_data'),
            $this->ctrl->getLinkTarget($this, 'clearAllData')
        );

        // Create professional filter using ILIAS Filter Service
        $filter_ui = $this->createProfessionalFilter();

        // Create table
        require_once __DIR__ . '/class.ilIssueAnalysisTableGUI.php';
        $table_gui = new ilIssueAnalysisTableGUI($this);
        $table = $table_gui->getHTML();

        // Render page
        $content = [
            $this->renderer->render([$import_button, $clear_button]),
            $filter_ui,
            $table
        ];

        $this->tpl->setContent(implode('', $content));
    }

    /**
     * Create professional filter using ILIAS Filter Service (like ResourceOverviewGUI)
     */
    private function createProfessionalFilter(): string
    {
        global $DIC;
        $filter_service = $DIC->uiService()->filter();

        // Define filter items
        $filter_items = $this->getFilterItems();

        if (empty($filter_items)) {
            return '';
        }

        // Create the filter using ILIAS Filter Service
        $filter = $filter_service->standard(
            self::class,
            $this->ctrl->getLinkTarget($this, 'showList'),
            $filter_items,
            array_map(fn($filter): bool => true, $filter_items), // all filters are available
            true, // expanded by default
            false  // not sticky - allow toggle on/off
        );

        // Apply filter values to our data source
        $filter_data = $filter_service->getData($filter);

        if ($filter_data) {
            // Filter has data - save to session
            $_SESSION['xial_filter'] = $filter_data;
        } else {
            // No filter data - clear session data to remove filtering
            unset($_SESSION['xial_filter']);
        }

        return $this->renderer->render($filter);
    }

    /**
     * Get filter items for ILIAS Filter Service
     */
    private function getFilterItems(): array
    {
        $filter_items = [];

        // Severity filter
        $severity_options = [
            '' => $this->lng->txt('please_select'),
            'error' => $this->plugin->txt('severity_error'),
            'warning' => $this->plugin->txt('severity_warning'),
            'notice' => $this->plugin->txt('severity_notice'),
            'fatal_error' => $this->plugin->txt('severity_fatal_error'),
            'parse_error' => $this->plugin->txt('severity_parse_error')
        ];

        $filter_items['severity'] = $this->ui_factory->input()->field()->select(
            $this->plugin->txt('filter_severity'),
            $severity_options
        );

        // Error code filter
        $filter_items['error_code'] = $this->ui_factory->input()->field()->text(
            $this->plugin->txt('detail_error_code')
        );

        // Note: DateTime filters not implemented yet due to API complexity

        // Search filter
        $filter_items['search'] = $this->ui_factory->input()->field()->text(
            $this->plugin->txt('filter_search')
        );

        return $filter_items;
    }



    /**
     * Import error logs now
     */
    private function importNow(): void
    {
        try {
            $result = $this->importer->importLogs(false);

            if ($result['success'] || $result['imported'] > 0) {
                if ($result['errors'] > 0) {
                    $message = sprintf(
                        $this->plugin->txt('msg_import_with_errors'),
                        $result['errors'],
                        $result['imported'],
                        $result['skipped']
                    );
                    $this->tpl->setOnScreenMessage('info', $message);
                } else {
                    // Check if limits were reached and provide appropriate message
                    if ($result['line_limit_reached'] ?? false) {
                        $lineLimit = $this->settings->getImportLineLimit();
                        $message = sprintf(
                            $this->plugin->txt('msg_import_success_limit_reached'),
                            $result['imported'],
                            $result['skipped'],
                            $lineLimit
                        );
                        $this->tpl->setOnScreenMessage('info', $message);
                    } elseif ($result['time_limit_reached'] ?? false) {
                        $message = sprintf(
                            $this->plugin->txt('msg_import_success_time_limit'),
                            $result['imported'],
                            $result['skipped']
                        );
                        $this->tpl->setOnScreenMessage('info', $message);
                    } else {
                        $message = sprintf(
                            $this->plugin->txt('msg_import_success'),
                            $result['imported'],
                            $result['skipped']
                        );

                        // Temporary debug: Show first few debug messages
                        if (isset($result['error_messages']) && is_array($result['error_messages'])) {
                            $debugMessages = array_slice($result['error_messages'], 0, 3);
                            $message .= '<br><br>DEBUG: ' . implode('<br>', $debugMessages);
                        }

                        $this->tpl->setOnScreenMessage('success', $message);
                    }
                }
            } else {
                if ($result['skipped'] > 0) {
                    $this->tpl->setOnScreenMessage('info', $this->plugin->txt('msg_import_no_new_entries'));
                } else {
                    $this->tpl->setOnScreenMessage('failure', $this->plugin->txt('msg_import_failed'));
                }
            }

        } catch (Exception $e) {
            $this->tpl->setOnScreenMessage('failure', $this->plugin->txt('msg_import_failed'));
        }

        $this->showList();
    }

    /**
     * Reset source tracking
     */
    private function resetTracking(): void
    {
        try {
            $this->repo->clearSourceTracking();
            $this->tpl->setOnScreenMessage('success', $this->plugin->txt('msg_tracking_cleared'));
        } catch (Exception $e) {
            $this->tpl->setOnScreenMessage('failure', $this->plugin->txt('msg_clear_tracking_failed'));
        }

        $this->showList();
    }

    /**
     * Clear all imported data
     */
    private function clearAllData(): void
    {
        try {
            $this->repo->clearAllData();
            $this->tpl->setOnScreenMessage('success', $this->plugin->txt('msg_all_data_cleared'));
        } catch (Exception $e) {
            $this->tpl->setOnScreenMessage('failure', $this->plugin->txt('msg_clear_data_failed'));
        }

        $this->showList();
    }

    /**
     * Handle table actions
     */
    public function handleTableActions(): void
    {
        $request = $this->http->request();
        $query_params = $request->getQueryParams();


        // Handle namespaced parameters from URL builder (following CustomMetaBarLinks pattern)
        $action = $query_params['error_entry_table_action'] ?? '';
        $ids = $query_params['error_entry_ids'] ?? [];

        if (!is_array($ids)) {
            $ids = [$ids];
        }
        $ids = array_map('intval', $ids);

        switch ($action) {
            case 'viewDetails':
                if (count($ids) === 1) {
                    $this->ctrl->setParameter($this, 'id', $ids[0]);
                    $this->ctrl->redirect($this, 'viewDetails');
                }
                break;

            case 'copyToClipboard':
                if (count($ids) === 1) {
                    $entry = $this->repo->getLogEntry($ids[0]);
                    if ($entry && $entry['code']) {
                        $this->tpl->setOnScreenMessage('info', 'Error Code: ' . $entry['code'] . ' (use Ctrl+C to copy)');
                    }
                }
                break;

            case 'export_csv':
                $this->exportData($ids, 'csv');
                return;

            case 'export_json':
                $this->exportData($ids, 'json');
                return;
        }

        $this->ctrl->redirect($this, 'showList');
    }

    /**
     * View error details
     */
    private function viewDetails(): void
    {
        // Set custom page title
        $this->tpl->setTitle($this->plugin->txt('detail_title'));

        // Get ID from various possible sources - use standard ILIAS methods
        $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

        if (!$id) {
            $this->tpl->setOnScreenMessage('failure', $this->plugin->txt('msg_no_id_provided'));
            $this->ctrl->redirect($this, 'showList');
            return;
        }

        // Context-aware tab navigation (CustomMetaBarLinks pattern)
        $this->tabs->clearTargets();
        $this->tabs->setBackTarget(
            $this->plugin->txt('back_to_list'),
            $this->ctrl->getLinkTarget($this, 'showList')
        );

        $this->tabs->addTab(
            'view_details',
            $this->plugin->txt('tab_view_details'),
            $this->ctrl->getLinkTarget($this, 'viewDetails')
        );
        $this->tabs->setTabActive('view_details');

        $entry = $this->repo->getLogEntry($id);
        if (!$entry) {
            $this->tpl->setOnScreenMessage('failure', $this->plugin->txt('msg_entry_not_found'));
            $this->ctrl->redirect($this, 'showList');
            return;
        }

        // Create detail view
        $sections = [];

        // Basic information for ILIAS error files
        $basic_info = [
            $this->plugin->txt('detail_error_code') => $entry['code'] ?: '-',
            $this->plugin->txt('detail_timestamp') => $entry['timestamp'],
            $this->plugin->txt('detail_severity') => strtoupper($entry['severity']),
            $this->plugin->txt('detail_context_label') => $entry['context'] ?: '-'
        ];

        if (!empty($entry['file'])) {
            $basic_info[$this->plugin->txt('detail_file')] = $entry['file'] . ($entry['line'] ? ':' . $entry['line'] : '');
        }

        $basic_info_items = [];
        foreach ($basic_info as $label => $value) {
            $basic_info_items[$label] = (string) $value;
        }

        $sections[] = $this->ui_factory->panel()->standard(
            $this->plugin->txt('detail_error_information'),
            [$this->ui_factory->listing()->descriptive($basic_info_items)]
        );

        // Determine full error content from available data
        $fullErrorContent = '';

        // Use stacktrace first (contains complete error file content), fallback to message
        if (!empty($entry['stacktrace'])) {
            $fullErrorContent = $entry['stacktrace'];
        } elseif (!empty($entry['message'])) {
            $fullErrorContent = $entry['message'];
        }

        if (!empty($fullErrorContent)) {
            // Generate unique ID for this error content
            $contentId = 'error_content_' . $entry['id'];
            $sanitizedContentId = 'sanitized_content_' . $entry['id'];

            // Create sanitized version for safe sharing
            $sanitizer = new ilIssueAnalysisSanitizer($this->settings->getMaskSensitive());
            $sanitizedContent = $sanitizer->sanitizeForSharing($fullErrorContent);

            // Also apply sanitization to displayed content if mask_sensitive is enabled
            $displayContent = $this->settings->getMaskSensitive()
                ? $sanitizer->sanitizeTextContent($fullErrorContent)
                : $fullErrorContent;

            $error_content = $this->ui_factory->legacy(
                '<div style="background: #f5f5f5; padding: 15px; border-radius: 5px; margin: 10px 0;">' .
                '<div style="margin-bottom: 10px;">' .
                '<button onclick="copyErrorContent(\'' . $contentId . '\', this)" ' .
                'style="background: #007cba; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; margin-right: 10px;">' . htmlspecialchars($this->plugin->txt('detail_copy_original')) . '</button>' .
                '<button onclick="copyErrorContent(\'' . $sanitizedContentId . '\', this)" ' .
                'style="background: #28a745; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer;" ' .
                'title="' . htmlspecialchars($this->plugin->txt('detail_copy_safe_tooltip')) . '">' . htmlspecialchars($this->plugin->txt('detail_copy_safe_sharing')) . '</button>' .
                '</div>' .
                '<pre id="' . $contentId . '" style="max-height: 500px; overflow-y: auto; white-space: pre-wrap; margin: 0; font-family: monospace; font-size: 12px; line-height: 1.4;">' .
                htmlspecialchars($displayContent) .
                '</pre>' .
                '<pre id="' . $sanitizedContentId . '" style="display: none;">' .
                htmlspecialchars($sanitizedContent) .
                '</pre>' .
                '</div>' .
                '<script>
                function copyErrorContent(contentId, button) {
                    var content = document.getElementById(contentId);
                    if (!content) {
                        console.error("Element not found: " + contentId);
                        button.textContent = "Element not found";
                        return;
                    }

                    console.log("Copying content from element:", contentId, "Length:", content.textContent.length);

                    // Store original button text and style
                    var originalText = button.textContent;
                    var originalBackground = button.style.background;

                    // Try modern clipboard API first
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(content.textContent).then(function() {
                            console.log("Clipboard API success");
                            button.textContent = "' . htmlspecialchars($this->plugin->txt('detail_copied')) . '";
                            button.style.background = "#28a745";
                            setTimeout(function() {
                                button.textContent = originalText;
                                button.style.background = originalBackground;
                            }, 2000);
                        }).catch(function(err) {
                            console.error("Clipboard API failed:", err);
                            // Fallback to selection method
                            fallbackCopy(content, button, originalText, originalBackground);
                        });
                    } else {
                        console.log("Clipboard API not available, using fallback");
                        // Fallback for older browsers
                        fallbackCopy(content, button, originalText, originalBackground);
                    }
                }

                function fallbackCopy(content, button, originalText, originalBackground) {
                    try {
                        console.log("Using fallback copy method");

                        // Make element temporarily visible for selection
                        var wasHidden = content.style.display === "none";
                        if (wasHidden) {
                            content.style.display = "block";
                            content.style.position = "absolute";
                            content.style.left = "-9999px";
                        }

                        var range = document.createRange();
                        range.selectNodeContents(content);
                        var selection = window.getSelection();
                        selection.removeAllRanges();
                        selection.addRange(range);

                        var success = document.execCommand("copy");
                        selection.removeAllRanges();

                        // Hide element again if it was hidden
                        if (wasHidden) {
                            content.style.display = "none";
                            content.style.position = "";
                            content.style.left = "";
                        }

                        console.log("Fallback copy result:", success);

                        if (success) {
                            button.textContent = "' . htmlspecialchars($this->plugin->txt('detail_copied')) . '";
                            button.style.background = "#28a745";
                            setTimeout(function() {
                                button.textContent = originalText;
                                button.style.background = originalBackground;
                            }, 2000);
                        } else {
                            button.textContent = "' . htmlspecialchars($this->plugin->txt('detail_copy_failed')) . '";
                            setTimeout(function() {
                                button.textContent = originalText;
                                button.style.background = originalBackground;
                            }, 3000);
                        }
                    } catch (e) {
                        console.error("Fallback copy failed:", e);
                        button.textContent = "Copy failed - select text manually";
                        setTimeout(function() {
                            button.textContent = originalText;
                            button.style.background = originalBackground;
                        }, 3000);
                    }
                }
                </script>'
            );
            $sections[] = $this->ui_factory->panel()->standard(
                $this->plugin->txt('detail_full_error_content') . ' (' . strlen($fullErrorContent) . ' ' . $this->plugin->txt('detail_characters') . ')',
                [$error_content]
            );
        } else {
            // No error content available to display
        }

        // No back button needed - using tab navigation now
        $this->tpl->setContent($this->renderer->render($sections));
    }

    /**
     * Show settings
     */
    private function showSettings(): void
    {
        // Set custom page title
        $this->tpl->setTitle($this->plugin->txt('tab_settings'));

        $form = $this->createSettingsForm();
        $this->tpl->setContent($this->renderer->render($form));
    }

    /**
     * Create settings form
     */
    private function createSettingsForm(): \ILIAS\UI\Component\Input\Container\Form\Standard
    {
        $form_fields = [];

        $form_fields['retention_days'] = $this->ui_factory->input()->field()->numeric(
            $this->plugin->txt('settings_retention_days'),
            $this->plugin->txt('settings_retention_days_info')
        )
            ->withValue($this->settings->getRetentionDays())
            ->withRequired(true);

        $form_fields['max_records'] = $this->ui_factory->input()->field()->numeric(
            $this->plugin->txt('settings_max_records'),
            $this->plugin->txt('settings_max_records_info')
        )
            ->withValue($this->settings->getMaxRecords())
            ->withRequired(true);

        $form_fields['mask_sensitive'] = $this->ui_factory->input()->field()->checkbox(
            $this->plugin->txt('settings_mask_sensitive'),
            $this->plugin->txt('settings_mask_sensitive_info')
        )
            ->withValue($this->settings->getMaskSensitive());

        $form_fields['cron_enabled'] = $this->ui_factory->input()->field()->checkbox(
            $this->plugin->txt('settings_cron_enabled'),
            $this->plugin->txt('settings_cron_enabled_info')
        )
            ->withValue($this->settings->getCronEnabled());

        $form_fields['import_time_limit'] = $this->ui_factory->input()->field()->numeric(
            $this->plugin->txt('settings_import_time_limit'),
            $this->plugin->txt('settings_import_time_limit_info')
        )
            ->withValue($this->settings->getImportTimeLimit())
            ->withRequired(true);

        $form_fields['import_line_limit'] = $this->ui_factory->input()->field()->numeric(
            $this->plugin->txt('settings_import_line_limit'),
            $this->plugin->txt('settings_import_line_limit_info')
        )
            ->withValue($this->settings->getImportLineLimit())
            ->withRequired(true);

        return $this->ui_factory->input()->container()->form()->standard(
            $this->ctrl->getFormAction($this, 'saveSettings'),
            $form_fields
        );
    }

    /**
     * Save settings
     */
    private function saveSettings(): void
    {
        $form = $this->createSettingsForm();
        $request = $this->http->request();

        if ($request->getMethod() === 'POST') {
            $form = $form->withRequest($request);
            $form_data = $form->getData();

            if ($form_data) {
                // Validate settings
                $errors = $this->settings->validateSettings($form_data);

                if (empty($errors)) {
                    // Save settings
                    $this->settings->setRetentionDays((int) $form_data['retention_days']);
                    $this->settings->setMaxRecords((int) $form_data['max_records']);
                    $this->settings->setMaskSensitive((bool) $form_data['mask_sensitive']);
                    $this->settings->setCronEnabled((bool) $form_data['cron_enabled']);
                    $this->settings->setImportTimeLimit((int) $form_data['import_time_limit']);
                    $this->settings->setImportLineLimit((int) $form_data['import_line_limit']);

                    $this->tpl->setOnScreenMessage('success', $this->plugin->txt('msg_settings_saved'));
                    $this->ctrl->redirect($this, 'showSettings');
                } else {
                    foreach ($errors as $error) {
                        $this->tpl->setOnScreenMessage('failure', $error);
                    }
                }
            }
        }

        $this->tpl->setContent($this->renderer->render($form));
    }

    /**
     * Show comprehensive error analysis statistics
     */
    private function showStatistics(): void
    {
        // Set custom page title
        $this->tpl->setTitle($this->plugin->txt('stats_title'));


        // Get selected time range from request (both POST and GET)
        $request = $this->http->request();
        $post_params = $request->getParsedBody();
        $query_params = $request->getQueryParams();
        $time_range = $post_params['time_range'] ?? $query_params['time_range'] ?? 'last_week';

        // Create time range selector
        $time_range_selector = $this->createTimeRangeSelector($time_range);

        $stats = $this->repo->getStatistics($time_range);
        $panels = [];

        // Overview Statistics
        if (!empty($stats['overview'])) {
            $overview = $stats['overview'];
            $overview_items = [
                $this->plugin->txt('stats_total_entries') => (string) $overview['total_entries'],
                $this->plugin->txt('stats_unique_files') => (string) $overview['unique_files']
            ];
            $panels[] = $this->ui_factory->panel()->standard(
                $this->plugin->txt('stats_overview'),
                [$this->ui_factory->listing()->descriptive($overview_items)]
            );
        }

        // Time Range Chart
        if (!empty($stats['timeranges']['chart_data'])) {
            $time_chart = $this->createTimeRangeChart($stats['timeranges']['chart_data'], $time_range);
            $panels[] = $this->ui_factory->panel()->standard(
                $this->plugin->txt('stats_time_activity') . ' (' . $this->plugin->txt('stats_time_' . $time_range) . ')',
                [$time_chart]
            );
        }

        // Severity Distribution Chart (with time range)
        // Currently commented out as there's only one severity type (Error)
        /*
        if (!empty($stats['patterns']['severity_chart_data'])) {
            $severity_chart = $this->createSeverityChart($stats['patterns']['severity_chart_data']);
            $panels[] = $this->ui_factory->panel()->standard(
                $this->plugin->txt('stats_severity_distribution') . ' (' . $this->plugin->txt('stats_time_' . $time_range) . ')',
                [$severity_chart]
            );
        }
        */

        // Source File Analysis with Chart
        if (!empty($stats['sources'])) {
            $sources = $stats['sources'];

            // Top error files chart
            if (!empty($sources['file_chart_data'])) {
                $file_chart = $this->createFileChart($sources['file_chart_data'], $time_range);
                $panels[] = $this->ui_factory->panel()->standard(
                    $this->plugin->txt('stats_top_error_files_chart') . ' (' . $this->plugin->txt('stats_time_' . $time_range) . ')',
                    [$file_chart]
                );
            }

            // Top error files list with detailed information
            if (!empty($sources['top_error_files'])) {
                $file_content = '<div style="font-family: inherit;">';
                foreach ($sources['top_error_files'] as $file_data) {
                    $filename = basename($file_data['file']);

                    // Build filename with count and time info
                    $filename_info = [];
                    $filename_info[] = $file_data['count'] . ' ' . $this->plugin->txt('stats_errors');

                    // Add time information if available
                    if (!empty($file_data['latest_occurrence'])) {
                        $latest = new DateTime($file_data['latest_occurrence']);
                        $now = new DateTime();
                        $diff = $now->diff($latest);

                        if ($diff->days > 0) {
                            $time_ago = 'vor ' . $diff->days . ' Tag' . ($diff->days > 1 ? 'en' : '');
                        } elseif ($diff->h > 0) {
                            $time_ago = 'vor ' . $diff->h . ' Stunde' . ($diff->h > 1 ? 'n' : '');
                        } else {
                            $time_ago = 'vor ' . $diff->i . ' Minute' . ($diff->i > 1 ? 'n' : '');
                        }
                        $filename_info[] = $time_ago;
                    }

                    $file_content .= '<div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-left: 4px solid #007cba; border-radius: 4px;">';
                    $file_content .= '<div style="font-weight: bold; color: #007cba; margin-bottom: 8px;">';
                    $file_content .= htmlspecialchars($filename) . ' <span style="color: #666; font-weight: normal;">(' . implode(', ', $filename_info) . ')</span>';
                    $file_content .= '</div>';

                    // Add unique messages if available
                    if (!empty($file_data['unique_messages'])) {
                        $file_content .= '<div style="font-size: 0.9em; color: #555;">';
                        $file_content .= '<strong>Fehlermeldungen:</strong>';
                        foreach ($file_data['unique_messages'] as $message) {
                            $file_content .= '<div style="margin: 4px 0 4px 16px; padding: 4px 8px; background: #fff; border-radius: 3px; border-left: 2px solid #dc3545;">';
                            $file_content .= '• ' . htmlspecialchars($message);
                            $file_content .= '</div>';
                        }
                        $file_content .= '</div>';
                    }
                    $file_content .= '</div>';
                }
                $file_content .= '</div>';

                $panels[] = $this->ui_factory->panel()->standard(
                    $this->plugin->txt('stats_top_error_files_list'),
                    [$this->ui_factory->legacy($file_content)]
                );
            }
        }

        // Error Pattern Analysis
        if (!empty($stats['patterns'])) {
            $patterns = $stats['patterns'];


            // Critical errors
            if (!empty($patterns['critical_errors'])) {
                $critical_content = '<div style="font-family: inherit;">';
                foreach ($patterns['critical_errors'] as $error) {
                    $critical_content .= '<div style="margin-bottom: 15px; padding: 10px; background: #f8d7da; border-left: 4px solid #dc3545; border-radius: 4px;">';
                    $critical_content .= '<div style="font-weight: bold; color: #721c24;">' .
                                       htmlspecialchars(strtoupper($error['severity'])) .
                                       ($error['code'] ? ' (' . htmlspecialchars($error['code']) . ')' : '') . '</div>';
                    $critical_content .= '<div style="margin: 5px 0; font-size: 0.9em;">' . htmlspecialchars($error['message']) . '</div>';
                    if ($error['file']) {
                        $critical_content .= '<div style="font-size: 0.8em; color: #6c757d;">' .
                                           htmlspecialchars(basename($error['file'])) . ' - ' .
                                           htmlspecialchars($error['timestamp']) . '</div>';
                    }
                    $critical_content .= '</div>';
                }
                $critical_content .= '</div>';

                $panels[] = $this->ui_factory->panel()->standard(
                    $this->plugin->txt('stats_critical_errors') . ' (' . $this->plugin->txt('stats_time_' . $time_range) . ')',
                    [$this->ui_factory->legacy($critical_content)]
                );
            }
        }

        // Render time range selector and panels
        $content = [
            $time_range_selector,
            $this->renderer->render($panels)
        ];

        $this->tpl->setContent(implode('', $content));
    }

    /**
     * Create time range selector dropdown
     */
    private function createTimeRangeSelector(string $selectedRange): string
    {
        $time_ranges = [
            'today' => $this->plugin->txt('stats_time_today'),
            'yesterday' => $this->plugin->txt('stats_time_yesterday'),
            'last_week' => $this->plugin->txt('stats_time_last_week'),
            'last_month' => $this->plugin->txt('stats_time_last_month'),
            'last_3_months' => $this->plugin->txt('stats_time_last_3_months')
        ];

        // Create modern ILIAS 9 UI select input
        $select_input = $this->ui_factory->input()->field()->select(
            $this->plugin->txt('stats_time_range'),
            $time_ranges
        )->withValue($selectedRange);

        // Create form with the select input
        $form = $this->ui_factory->input()->container()->form()->standard(
            $this->ctrl->getFormAction($this, 'showStatistics'),
            ['time_range' => $select_input]
        );

        // Handle form submission
        $request = $this->http->request();
        if ($request->getMethod() === 'POST') {
            $form = $form->withRequest($request);
            $result = $form->getData();
            if ($result !== null && isset($result['time_range'])) {
                // Redirect with new time range to avoid POST resubmission
                $this->ctrl->setParameter($this, 'time_range', $result['time_range']);
                $this->ctrl->redirect($this, 'showStatistics');
            }
        }

        return $this->renderer->render($form);
    }

    /**
     * Create time range chart using ILIAS Bar Chart
     */
    private function createTimeRangeChart(array $data, string $timeRange): \ILIAS\UI\Component\Chart\Bar\Bar
    {
        $c_dimension = $this->data_factory->dimension()->cardinal();
        $errors_label = $this->plugin->txt('chart_errors');
        $dataset = $this->data_factory->dataset([$errors_label => $c_dimension]);

        foreach ($data as $period => $count) {
            $dataset = $dataset->withPoint($period, [$errors_label => $count]);
        }

        return $this->ui_factory->chart()->bar()->horizontal(
            $this->plugin->txt('chart_errors'),
            $dataset
        );
    }

    /**
     * Create severity distribution using ILIAS Bar Chart
     */
    private function createSeverityChart(array $data): \ILIAS\UI\Component\Chart\Bar\Bar
    {
        $c_dimension = $this->data_factory->dimension()->cardinal();
        $count_label = $this->plugin->txt('chart_count');
        $dataset = $this->data_factory->dataset([$count_label => $c_dimension]);

        foreach ($data as $severity => $count) {
            $dataset = $dataset->withPoint($this->formatSeverity($severity), [$count_label => $count]);
        }

        return $this->ui_factory->chart()->bar()->horizontal(
            $this->plugin->txt('stats_severity_title'),
            $dataset
        );
    }

    /**
     * Create file error distribution using ILIAS Bar Chart
     */
    private function createFileChart(array $data, string $timeRange): \ILIAS\UI\Component\Chart\Bar\Bar
    {
        $c_dimension = $this->data_factory->dimension()->cardinal();
        $errors_label = $this->plugin->txt('chart_errors');
        $dataset = $this->data_factory->dataset([$errors_label => $c_dimension]);

        foreach ($data as $filename => $count) {
            $dataset = $dataset->withPoint(basename($filename), [$errors_label => $count]);
        }

        return $this->ui_factory->chart()->bar()->horizontal(
            $this->plugin->txt('chart_errors'),
            $dataset
        );
    }

    /**
     * Export data
     */
    private function exportData(array $ids, string $format): void
    {
        $entries = $this->repo->exportLogEntries($ids);

        switch ($format) {
            case 'csv':
                $this->exportCSV($entries);
                break;
            case 'json':
                $this->exportJSON($entries);
                break;
        }
    }

    /**
     * Export as CSV
     */
    private function exportCSV(array $entries): void
    {
        $filename = 'error_log_' . date('Y-m-d_H-i-s') . '.csv';

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');

        // CSV headers
        $headers = [
            'ID', 'Timestamp', 'Severity', 'Message', 'File', 'Line',
            'User ID', 'Context', 'Analyzed'
        ];
        fputcsv($output, $headers);

        // CSV data
        foreach ($entries as $entry) {
            $row = [
                $entry['id'],
                $entry['timestamp'],
                $entry['severity'],
                $entry['message'],
                $entry['file'] ?: '',
                $entry['line'] ?: '',
                $entry['user_id'] ?: '',
                $entry['context'] ?: '',
                $entry['analyzed'] ? 'Yes' : 'No'
            ];
            fputcsv($output, $row);
        }

        fclose($output);
        exit;
    }

    /**
     * Export as JSON
     */
    private function exportJSON(array $entries): void
    {
        $filename = 'error_log_' . date('Y-m-d_H-i-s') . '.json';

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        echo json_encode($entries, JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Helper methods
     */
    private function formatSeverity(string $severity): string
    {
        $severity_key = 'severity_' . str_replace('_', '_', strtolower($severity));
        return $this->plugin->txt($severity_key) ?: ucfirst($severity);
    }

    private function formatUser(?int $userId): string
    {
        if (!$userId) {
            return '-';
        }

        try {
            $user = ilObjectFactory::getInstanceByObjId($userId);
            if ($user instanceof ilObjUser) {
                return $user->getFullname() . ' (' . $userId . ')';
            }
        } catch (Exception $e) {
            // User might not exist anymore
        }

        return 'User ' . $userId;
    }

    private function formatRequestData(string $requestData): string
    {
        $data = json_decode($requestData, true);
        if ($data) {
            return json_encode($data, JSON_PRETTY_PRINT);
        }
        return $requestData;
    }

    /**
     * Get plugin object
     */
    public function getPluginObject(): ilIssueAnalysisPlugin
    {
        return $this->plugin;
    }
}
