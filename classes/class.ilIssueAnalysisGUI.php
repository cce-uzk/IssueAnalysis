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

use ILIAS\Filesystem\Stream\Streams;
use ILIAS\HTTP\Response\ResponseHeader;
use ILIAS\Plugin\xial\Service\DoubleSubmissionGuard;
use ILIAS\Refinery\Factory as Refinery;

/**
 * Main GUI controller for IssueAnalysis plugin
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 *
 * @ilCtrl_IsCalledBy ilIssueAnalysisGUI: ilIssueAnalysisConfigGUI
 * @ilCtrl_IsCalledBy ilIssueAnalysisGUI: ilIssueAnalysisUIHookGUI
 */
class ilIssueAnalysisGUI
{
    /** Session key of the filter values, read by ilIssueAnalysisTableDataRetrieval */
    public const SESSION_FILTER = 'xial_filter';

    private const TIME_RANGES = ['today', 'yesterday', 'last_week', 'last_month', 'last_3_months'];

    private ilCtrl $ctrl;
    private ilGlobalPageTemplate $tpl;
    private ilLanguage $lng;
    private ilTabsGUI $tabs;
    private \ILIAS\UI\Factory $ui_factory;
    private \ILIAS\UI\Renderer $renderer;
    private \ILIAS\HTTP\Services $http;
    private \ILIAS\Data\Factory $data_factory;
    private Refinery $refinery;
    private ilAccessHandler $access;
    private ilIssueAnalysisPlugin $plugin;
    private ilIssueAnalysisRepo $repo;
    private ilIssueAnalysisSettings $settings;
    private ilIssueAnalysisImporter $importer;
    private ilLogger $logger;

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
        $this->refinery = $DIC->refinery();
        $this->logger = $DIC->logger()->xial();
        $this->access = $DIC->access();

        $this->plugin = ilIssueAnalysisPlugin::getInstance();

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
        return $user->getId() !== ANONYMOUS_USER_ID
            && $this->access->checkAccess('read', '', SYSTEM_FOLDER_ID);
    }

    /**
     * Set up navigation tabs
     */
    private function setTabs(): void
    {
        // Settings are part of the plugin configuration (ilIssueAnalysisConfigGUI);
        // the breadcrumb leads back to the administration
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

        // Import button - handle within current context with double-submission prevention
        $import_token = DoubleSubmissionGuard::generateToken('import');
        $this->ctrl->setParameter($this, 'token', $import_token);
        $import_button = $this->ui_factory->button()->primary(
            $this->plugin->txt('btn_import_now'),
            $this->ctrl->getLinkTarget($this, 'importNow')
        );
        $this->ctrl->setParameter($this, 'token', '');

        // Clear all data button with double-submission prevention
        $clear_token = DoubleSubmissionGuard::generateToken('clearData');
        $this->ctrl->setParameter($this, 'token', $clear_token);
        $clear_button = $this->ui_factory->button()->standard(
            $this->plugin->txt('btn_clear_all_data'),
            $this->ctrl->getLinkTarget($this, 'clearAllData')
        );
        $this->ctrl->setParameter($this, 'token', '');

        $filter_ui = $this->createFilter();

        // Create table
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
     * Filter of the list; its values are kept in the session for the data retrieval
     */
    private function createFilter(): string
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
            ilSession::set(self::SESSION_FILTER, $filter_data);
        } else {
            // No filter data - clear session data to remove filtering
            ilSession::clear(self::SESSION_FILTER);
        }

        return $this->renderer->render($filter);
    }

    /**
     * Get filter items for ILIAS Filter Service
     *
     * @return array<string, \ILIAS\UI\Component\Input\Container\Filter\FilterInput>
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

        // A select instead of a checkbox, as checkboxes are not available as filter inputs
        $show_ignored_options = [
            'hide' => $this->plugin->txt('filter_show_ignored_no'),
            'show' => $this->plugin->txt('filter_show_ignored_yes')
        ];

        $filter_items['show_ignored'] = $this->ui_factory->input()->field()->select(
            $this->plugin->txt('filter_show_ignored'),
            $show_ignored_options
        )->withValue('hide'); // Default: don't show ignored

        return $filter_items;
    }



    /**
     * Import error logs now
     */
    private function importNow(): void
    {
        // Prevent double submission
        $token = $this->getQueryString('token');
        if (!DoubleSubmissionGuard::validateAndConsumeToken('import', $token)) {
            $this->tpl->setOnScreenMessage('failure', $this->plugin->txt('msg_invalid_token'));
            $this->ctrl->redirect($this, 'showList');
        }

        // Additional cooldown check (prevents rapid re-clicks even with new tokens)
        if (DoubleSubmissionGuard::isInCooldown('import', 5)) {
            $this->tpl->setOnScreenMessage('info', $this->plugin->txt('msg_import_running'));
            $this->ctrl->redirect($this, 'showList');
        }

        try {
            DoubleSubmissionGuard::markExecuted('import');
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
            $this->logger->error('Manual import failed: ' . $e->getMessage());
            $this->tpl->setOnScreenMessage('failure', $this->plugin->txt('msg_import_failed'));
        }

        $this->showList();
    }


    /**
     * Clear all imported data
     */
    private function clearAllData(): void
    {
        // Prevent double submission
        $token = $this->getQueryString('token');
        if (!DoubleSubmissionGuard::validateAndConsumeToken('clearData', $token)) {
            $this->tpl->setOnScreenMessage('failure', $this->plugin->txt('msg_invalid_token'));
            $this->ctrl->redirect($this, 'showList');
        }

        // Additional cooldown check
        if (DoubleSubmissionGuard::isInCooldown('clearData', 5)) {
            $this->tpl->setOnScreenMessage('info', $this->plugin->txt('msg_operation_running'));
            $this->ctrl->redirect($this, 'showList');
        }

        try {
            DoubleSubmissionGuard::markExecuted('clearData');
            $this->repo->clearAllData();
            $this->tpl->setOnScreenMessage('success', $this->plugin->txt('msg_all_data_cleared'));
        } catch (Exception $e) {
            $this->logger->error('Clearing all data failed: ' . $e->getMessage());
            $this->tpl->setOnScreenMessage('failure', $this->plugin->txt('msg_clear_data_failed'));
        }

        $this->showList();
    }

    /**
     * Handle table actions
     */
    public function handleTableActions(): void
    {
        // Parameters are namespaced by the URL builder of ilIssueAnalysisTableGUI::createActions()
        $action = $this->getQueryString('error_entry_table_action');
        $ids = $this->getTableRowIds();

        switch ($action) {
            case 'viewDetails':
                if (count($ids) === 1) {
                    $this->ctrl->setParameter($this, 'id', $ids[0]);
                    $this->ctrl->redirect($this, 'viewDetails');
                }
                break;

            case 'ignoreHash':
                // Hide all entries with the stacktrace hash of the selected entry
                if (count($ids) === 1) {
                    global $DIC;
                    $userId = $DIC->user()->getId();

                    // Get stacktrace_hash for this error code
                    $hash = $this->repo->getHashForErrorCode($this->repo->getLogEntry($ids[0])['code'] ?? '');

                    if ($hash) {
                        $this->repo->ignoreHash($hash, $userId);
                        $this->tpl->setOnScreenMessage('success', $this->plugin->txt('msg_error_type_ignored'));
                    } else {
                        $this->tpl->setOnScreenMessage('failure', $this->plugin->txt('msg_error_type_ignore_failed'));
                    }
                }
                break;

            case 'unignoreHash':
                // Show all entries with the stacktrace hash of the selected entry again
                if (count($ids) === 1) {
                    // Get stacktrace_hash for this error code
                    $hash = $this->repo->getHashForErrorCode($this->repo->getLogEntry($ids[0])['code'] ?? '');

                    if ($hash) {
                        $this->repo->unignoreHash($hash);
                        $this->tpl->setOnScreenMessage('success', $this->plugin->txt('msg_error_type_unignored'));
                    } else {
                        $this->tpl->setOnScreenMessage('failure', $this->plugin->txt('msg_error_type_unignore_failed'));
                    }
                }
                break;

            case 'copyToClipboard':
                if (count($ids) === 1) {
                    $entry = $this->repo->getLogEntry($ids[0]);
                    if ($entry && $entry['code']) {
                        $this->tpl->setOnScreenMessage('info', $this->plugin->txt('detail_error_code') . ': ' . $entry['code']);
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

        $id = $this->getQueryInt('id');

        if (!$id) {
            $this->tpl->setOnScreenMessage('failure', $this->plugin->txt('msg_no_id_provided'));
            $this->ctrl->redirect($this, 'showList');
        }

        // The detail view replaces the tabs by a back link to the list
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
        $this->tabs->activateTab('view_details');

        // Stacktrace and request data are read from the original error file
        $errorLogDir = $this->settings->getErrorLogDirectory();
        $entry = $this->repo->getLogEntryWithFullContent($id, $errorLogDir);

        if (!$entry) {
            $this->tpl->setOnScreenMessage('failure', $this->plugin->txt('msg_entry_not_found'));
            $this->ctrl->redirect($this, 'showList');
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

        // Add file availability indicator
        if (isset($entry['file_available'])) {
            $basic_info[$this->plugin->txt('detail_original_file')] = $entry['file_available']
                ? $this->plugin->txt('detail_file_available')
                : $this->plugin->txt('detail_file_not_available');
        }

        $basic_info_items = [];
        foreach ($basic_info as $label => $value) {
            // The descriptive listing outputs its values as HTML; file and message
            // can contain HTML from the failed request
            $basic_info_items[$label] = htmlspecialchars((string) $value);
        }

        $sections[] = $this->ui_factory->panel()->standard(
            $this->plugin->txt('detail_error_information'),
            [$this->ui_factory->listing()->descriptive($basic_info_items)]
        );

        // Determine full error content from available data (with lazy-loading)
        $fullErrorContent = '';

        // Prefer full_stacktrace from lazy-loaded file, fallback to database
        if (!empty($entry['full_stacktrace'])) {
            $fullErrorContent = $entry['full_stacktrace'];
        } elseif (!empty($entry['stacktrace'])) {
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

            $sections[] = $this->ui_factory->panel()->standard(
                $this->plugin->txt('detail_full_error_content') . ' (' . strlen($fullErrorContent) . ' ' . $this->plugin->txt('detail_characters') . ')',
                $this->createErrorContent($contentId, $displayContent, $sanitizedContentId, $sanitizedContent)
            );
        }

        // No back button needed - using tab navigation now
        $this->tpl->setContent($this->renderer->render($sections));
    }




    /**
     * Show comprehensive error analysis statistics
     */
    private function showStatistics(): void
    {
        // Set custom page title
        $this->tpl->setTitle($this->plugin->txt('stats_title'));


        $time_range = $this->getQueryString('time_range');
        if (!in_array($time_range, self::TIME_RANGES, true)) {
            $time_range = 'last_week';
        }

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
                $file_panels = [];
                foreach ($sources['top_error_files'] as $file_data) {
                    $file_panels[] = $this->createErrorFilePanel($file_data);
                }

                $panels[] = $this->ui_factory->panel()->standard(
                    $this->plugin->txt('stats_top_error_files_list'),
                    $file_panels
                );
            }
        }

        // Critical errors
        if (!empty($stats['patterns']['critical_errors'])) {
            $items = [];
            foreach ($stats['patterns']['critical_errors'] as $error) {
                $items[] = $this->createCriticalErrorItem($error);
            }

            $panels[] = $this->ui_factory->panel()->listing()->standard(
                $this->plugin->txt('stats_critical_errors') . ' (' . $this->plugin->txt('stats_time_' . $time_range) . ')',
                [$this->ui_factory->item()->group('', $items)]
            );
        }

        // Render time range selector and panels
        $content = [
            $time_range_selector,
            $this->renderer->render($panels)
        ];

        $this->tpl->setContent(implode('', $content));
    }

    /**
     * Content of the error file with buttons to copy the original or the sanitized text
     *
     * The text is shown with a plugin template, as the UI framework of ILIAS 9 has
     * no component for preformatted text.
     *
     * @return \ILIAS\UI\Component\Component[]
     */
    private function createErrorContent(
        string $content_id,
        string $content,
        string $share_id,
        string $share_content
    ): array {
        $this->tpl->addCss($this->plugin->getRelativeDirectory() . '/css/xial.css');
        $this->tpl->addJavaScript($this->plugin->getRelativeDirectory() . '/js/xial.js');

        $template = $this->plugin->getTemplate('default/tpl.xial_error_content.html');
        $template->setVariable('CONTENT_ID', $content_id);
        $template->setVariable('CONTENT', htmlspecialchars($content));
        $template->setVariable('SHARE_ID', $share_id);
        $template->setVariable('SHARE_CONTENT', htmlspecialchars($share_content));

        return [
            $this->createCopyButton($this->plugin->txt('detail_copy_original'), $content_id),
            $this->createCopyButton($this->plugin->txt('detail_copy_safe_sharing'), $share_id),
            $this->ui_factory->legacy($template->get())
        ];
    }

    /**
     * Button that copies the text of an element to the clipboard, see js/xial.js
     */
    private function createCopyButton(string $label, string $content_id): \ILIAS\UI\Component\Button\Standard
    {
        // No action, so that the button does not navigate; the click is handled by the script
        $arguments = implode(', ', array_map(
            static fn(string $value): string => (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
            [$content_id, $this->plugin->txt('detail_copied'), $this->plugin->txt('detail_copy_failed')]
        ));

        return $this->ui_factory->button()->standard($label, '')
            ->withOnLoadCode(static fn(string $id): string => "il.xial.bindCopy('$id', $arguments);");
    }

    /**
     * Sub panel of a file with errors: number, last occurrence and the distinct messages
     *
     * Texts are escaped, as the UI components output them as HTML and the messages
     * can contain HTML from the failed request.
     *
     * @param array<string, mixed> $file_data
     */
    private function createErrorFilePanel(array $file_data): \ILIAS\UI\Component\Panel\Sub
    {
        $values = [
            $this->plugin->txt('stats_errors') => (string) $file_data['count']
        ];
        if (!empty($file_data['latest_occurrence'])) {
            $values[$this->plugin->txt('stats_latest_occurrence')] = $this->formatTimeAgo((string) $file_data['latest_occurrence']);
        }

        $content = [$this->ui_factory->listing()->characteristicValue()->text($values)];
        if (!empty($file_data['unique_messages'])) {
            $content[] = $this->ui_factory->listing()->unordered(
                array_map(static fn($message): string => htmlspecialchars((string) $message), $file_data['unique_messages'])
            );
        }

        return $this->ui_factory->panel()->sub(htmlspecialchars(basename((string) $file_data['file'])), $content);
    }

    /**
     * Item of a critical error, linked to its detail view
     *
     * @param array<string, mixed> $error
     */
    private function createCriticalErrorItem(array $error): \ILIAS\UI\Component\Item\Item
    {
        $title = htmlspecialchars((string) $error['message']);

        $properties = [
            $this->plugin->txt('col_severity') => htmlspecialchars(strtoupper((string) $error['severity'])),
            $this->plugin->txt('detail_error_code') => htmlspecialchars((string) ($error['code'] ?: '-')),
            $this->plugin->txt('col_timestamp') => htmlspecialchars((string) $error['timestamp'])
        ];
        if (!empty($error['file'])) {
            $properties[$this->plugin->txt('col_file')] = htmlspecialchars(basename((string) $error['file']));
        }

        $item_title = !empty($error['id'])
            ? $this->ui_factory->link()->standard($title, $this->getDetailsUrl((int) $error['id']))
            : $title;

        return $this->ui_factory->item()->standard($item_title)->withProperties($properties);
    }

    /**
     * Link target of the detail view of an entry
     */
    private function getDetailsUrl(int $id): string
    {
        $this->ctrl->setParameter($this, 'id', $id);
        $url = $this->ctrl->getLinkTarget($this, 'viewDetails');
        $this->ctrl->clearParameterByClass(self::class, 'id');

        return $url;
    }

    /**
     * Relative time like "3 days ago" for a timestamp
     */
    private function formatTimeAgo(string $timestamp): string
    {
        $diff = (new DateTime())->diff(new DateTime($timestamp));

        if ($diff->days > 0) {
            return sprintf($this->plugin->txt('time_days_ago'), $diff->days);
        }
        if ($diff->h > 0) {
            return sprintf($this->plugin->txt('time_hours_ago'), $diff->h);
        }
        return sprintf($this->plugin->txt('time_minutes_ago'), $diff->i);
    }

    /**
     * Create time range selector dropdown
     */
    private function createTimeRangeSelector(string $selectedRange): string
    {
        $time_ranges = [];
        foreach (self::TIME_RANGES as $range) {
            $time_ranges[$range] = $this->plugin->txt('stats_time_' . $range);
        }

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
     *
     * @param array<string, int> $data Number of errors per period
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
     * Create file error distribution using ILIAS Bar Chart
     *
     * @param array<string, int> $data Number of errors per file
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
     *
     * @param int[] $ids
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
     *
     * @param list<array<string, mixed>> $entries
     */
    private function exportCSV(array $entries): void
    {
        $output = fopen('php://temp', 'w+');

        // CSV headers (translated)
        $headers = [
            'ID',
            $this->plugin->txt('col_timestamp'),
            $this->plugin->txt('col_severity'),
            $this->plugin->txt('col_message'),
            $this->plugin->txt('col_file'),
            $this->plugin->txt('col_line'),
            $this->plugin->txt('col_user'),
            $this->plugin->txt('detail_context_label'),
            $this->plugin->txt('col_analyzed')
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
                $entry['analyzed'] ? $this->lng->txt('yes') : $this->lng->txt('no')
            ];
            fputcsv($output, $row);
        }

        rewind($output);
        $content = (string) stream_get_contents($output);
        fclose($output);

        $this->deliver($content, 'text/csv; charset=utf-8', 'csv');
    }

    /**
     * Export as JSON
     *
     * @param list<array<string, mixed>> $entries
     */
    private function exportJSON(array $entries): void
    {
        $this->deliver(
            (string) json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'application/json; charset=utf-8',
            'json'
        );
    }

    /**
     * Send an export file via the ILIAS HTTP service and end the request
     */
    private function deliver(string $content, string $content_type, string $extension): void
    {
        $filename = 'error_log_' . date('Y-m-d_H-i-s') . '.' . $extension;

        $this->http->saveResponse(
            $this->http->response()
                ->withHeader(ResponseHeader::CONTENT_TYPE, $content_type)
                ->withHeader(ResponseHeader::CONTENT_DISPOSITION, 'attachment; filename="' . $filename . '"')
                ->withBody(Streams::ofString($content))
        );
        $this->http->sendResponse();
        $this->http->close();
    }

    /**
     * Read a string parameter from the query, '' if not set
     */
    private function getQueryString(string $key): string
    {
        $query = $this->http->wrapper()->query();
        if (!$query->has($key)) {
            return '';
        }
        return $query->retrieve($key, $this->refinery->byTrying([
            $this->refinery->kindlyTo()->string(),
            $this->refinery->always('')
        ]));
    }

    /**
     * Read an integer parameter from the query, 0 if not set or invalid
     */
    private function getQueryInt(string $key): int
    {
        $query = $this->http->wrapper()->query();
        if (!$query->has($key)) {
            return 0;
        }
        return $query->retrieve($key, $this->refinery->byTrying([
            $this->refinery->kindlyTo()->int(),
            $this->refinery->always(0)
        ]));
    }

    /**
     * Row IDs sent by the data table, as a single value or a list
     *
     * @return int[]
     */
    private function getTableRowIds(): array
    {
        $query = $this->http->wrapper()->query();
        if (!$query->has('error_entry_ids')) {
            return [];
        }
        return $query->retrieve('error_entry_ids', $this->refinery->byTrying([
            $this->refinery->kindlyTo()->listOf($this->refinery->kindlyTo()->int()),
            $this->refinery->in()->series([
                $this->refinery->kindlyTo()->int(),
                $this->refinery->custom()->transformation(static fn(int $id): array => [$id])
            ]),
            $this->refinery->always([])
        ]));
    }




    /**
     * Get plugin object
     */
    public function getPluginObject(): ilIssueAnalysisPlugin
    {
        return $this->plugin;
    }
}
