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
 * Data table component for displaying ILIAS error log entries
 *
 * The table reads the imported entries via ilIssueAnalysisTableDataRetrieval and
 * links each error code to its detail view.
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class ilIssueAnalysisTableGUI
{
    protected \ILIAS\UI\Renderer $renderer;
    protected \ILIAS\UI\Factory $ui_factory;
    protected \ILIAS\Data\Factory $data_factory;
    protected ilLanguage $lng;
    protected ilCtrl $ctrl;
    protected \ILIAS\HTTP\Services $http;
    protected \ILIAS\Refinery\Factory $refinery;
    protected ?object $parent_obj = null;
    protected ilIssueAnalysisPlugin $plugin;

    public function __construct(?object $a_parent_obj)
    {
        global $DIC;

        $this->renderer = $DIC->ui()->renderer();
        $this->ui_factory = $DIC->ui()->factory();
        $this->lng = $DIC->language();
        $this->ctrl = $DIC->ctrl();
        $this->http = $DIC->http();
        $this->refinery = $DIC->refinery();
        $this->data_factory = new \ILIAS\Data\Factory();

        $this->parent_obj = $a_parent_obj;
        $this->plugin = ilIssueAnalysisPlugin::getInstance();
    }

    /**
     * Render the data table of the error entries for the current filter
     */
    public function getHTML(): string
    {
        $data_retrieval = new ilIssueAnalysisTableDataRetrieval($this->parent_obj);

        // The pagination of the data table does not show the number of entries,
        // so the number for the current filter is part of the title
        $count = (int) $data_retrieval->getTotalRowCount(null, null, null);
        $title = sprintf(
            $this->plugin->txt($count === 1 ? 'table_title_one_entry' : 'table_title_entries'),
            $count
        );

        return $this->renderer->render($this->createTable($data_retrieval, $title));
    }

    /**
     * Data table with sortable and optional columns and the row actions
     */
    protected function createTable(ilIssueAnalysisTableDataRetrieval $data_retrieval, string $title): \ILIAS\UI\Component\Table\Data
    {
        // Define columns with appropriate sorting
        $columns = [
            'timestamp' => $this->ui_factory->table()->column()->text($this->plugin->txt('col_timestamp'))
                ->withIsSortable(true),
            'code' => $this->ui_factory->table()->column()->link($this->plugin->txt('detail_error_code'))
                ->withIsSortable(true),
            'status' => $this->ui_factory->table()->column()->statusIcon($this->plugin->txt('col_status'))
                ->withIsSortable(true),
            'level' => $this->ui_factory->table()->column()->text($this->plugin->txt('col_severity'))
                ->withIsOptional(true)
                ->withIsSortable(true),
            'message' => $this->ui_factory->table()->column()->text($this->plugin->txt('col_message'))
                ->withIsOptional(true)
                ->withIsSortable(true),
            'file' => $this->ui_factory->table()->column()->text($this->plugin->txt('col_file'))
                ->withIsOptional(true)
                ->withIsSortable(true)
        ];

        // Build table with actions
        $table = $this->ui_factory->table()->data(
            $data_retrieval,
            $title,
            $columns
        );

        $actions = $this->createActions();
        if (!empty($actions)) {
            $table = $table->withActions($actions);
        }

        return $table->withRequest($this->http->request());
    }

    /**
     * Row actions; they are sent as namespaced query parameters to handleTableActions()
     *
     * @return array<string, \ILIAS\UI\Component\Table\Action\Action>
     */
    protected function createActions(): array
    {
        // Create URL builder for actions (using parent GUI)
        $query_params_namespace = ['error_entry'];
        $url_builder = new \ILIAS\UI\URLBuilder(
            new \ILIAS\Data\URI(
                ILIAS_HTTP_PATH . '/' . $this->ctrl->getLinkTarget(
                    $this->parent_obj,
                    'handleTableActions'
                )
            )
        );

        list($url_builder, $action_parameter_token, $row_id_token) = $url_builder->acquireParameters(
            $query_params_namespace,
            'table_action',
            'ids'
        );

        return [
            'viewDetails' => $this->ui_factory->table()->action()->single(
                $this->plugin->txt('btn_view_details'),
                $url_builder->withParameter($action_parameter_token, 'viewDetails'),
                $row_id_token
            ),
            'ignoreHash' => $this->ui_factory->table()->action()->single(
                $this->plugin->txt('btn_ignore_error_type'),
                $url_builder->withParameter($action_parameter_token, 'ignoreHash'),
                $row_id_token
            ),
            'unignoreHash' => $this->ui_factory->table()->action()->single(
                $this->plugin->txt('btn_unignore_error_type'),
                $url_builder->withParameter($action_parameter_token, 'unignoreHash'),
                $row_id_token
            )
        ];
    }

}

/**
 * Data retrieval class for real log entries
 */
class ilIssueAnalysisTableDataRetrieval implements \ILIAS\UI\Component\Table\DataRetrieval
{
    private ilIssueAnalysisRepo $repo;
    private \ILIAS\UI\Factory $ui_factory;
    private ilLanguage $lng;
    private ilCtrl $ctrl;

    /**
     * @param object|null $parent_gui GUI that handles the command viewDetails
     */
    public function __construct(private ?object $parent_gui = null)
    {
        global $DIC;

        $this->repo = new ilIssueAnalysisRepo();
        $this->ui_factory = $DIC->ui()->factory();
        $this->lng = $DIC->language();
        $this->ctrl = $DIC->ctrl();
    }

    /**
     * Link of the error code to the detail view of the entry
     */
    private function buildDetailsLink(int $id, string $code): \ILIAS\UI\Component\Link\Standard
    {
        // The link component outputs its label as HTML
        $label = htmlspecialchars($code !== '' ? $code : '-');
        if ($this->parent_gui === null) {
            return $this->ui_factory->link()->standard($label, '#');
        }

        $this->ctrl->setParameter($this->parent_gui, 'id', $id);
        $url = $this->ctrl->getLinkTarget($this->parent_gui, 'viewDetails');
        $this->ctrl->clearParameterByClass(get_class($this->parent_gui), 'id');

        return $this->ui_factory->link()->standard($label, $url);
    }

    /**
     * @param array<string, mixed>|null $filter_data
     * @param array<string, mixed>|null $additional_parameters
     */
    public function getRows(
        \ILIAS\UI\Component\Table\DataRowBuilder $row_builder,
        array $visible_column_ids,
        \ILIAS\Data\Range $range,
        \ILIAS\Data\Order $order,
        mixed $additional_viewcontrol_data,
        mixed $filter_data,
        mixed $additional_parameters
    ): \Generator {
        // Convert filter data to repo format
        $filter = [];
        $showIgnored = false;

        // Check for session filter data (from filter form)
        $session_filter = ilSession::get(ilIssueAnalysisGUI::SESSION_FILTER);
        if ($session_filter) {
            if (!empty($session_filter['severity'])) {
                $filter['severity'] = $session_filter['severity'];
            }
            if (!empty($session_filter['search'])) {
                $filter['search'] = $session_filter['search'];
            }
            if (!empty($session_filter['error_code'])) {
                $filter['error_code'] = $session_filter['error_code'];
            }
            if (!empty($session_filter['from_date'])) {
                $filter['from_date'] = $session_filter['from_date'];
            }
            if (!empty($session_filter['to_date'])) {
                $filter['to_date'] = $session_filter['to_date'];
            }
            if (isset($session_filter['show_ignored'])) {
                $showIgnored = $session_filter['show_ignored'] === 'show';
            }
        }

        // Also check table filter_data parameter (for future compatibility)
        if ($filter_data) {
            if (!empty($filter_data['severity'])) {
                $filter['severity'] = $filter_data['severity'];
            }
            if (!empty($filter_data['search'])) {
                $filter['search'] = $filter_data['search'];
            }
        }

        // Convert order to repo format
        $repoOrder = [];
        foreach ($order->get() as $aspect_name => $direction) {
            // Map UI table column names to database columns
            $columnMap = [
                'timestamp' => 'timestamp',
                'code' => 'code',
                'level' => 'severity',
                'message' => 'message',
                'file' => 'file'
            ];

            if (isset($columnMap[$aspect_name])) {
                $repoOrder[$columnMap[$aspect_name]] = $direction === \ILIAS\Data\Order::ASC ? 'ASC' : 'DESC';
            }
        }

        // Get entries from database (with showIgnored parameter)
        $entries = $this->repo->getLogEntries(
            $filter,
            $range->getStart(),
            $range->getLength(),
            $repoOrder,
            $showIgnored
        );

        foreach ($entries as $entry) {
            // Status icon: checkmark if visible, X if ignored/hidden using ILIAS standard icons
            $isIgnored = isset($entry['error_ignored']) && $entry['error_ignored'] == 1;
            $statusIcon = $this->ui_factory->symbol()->icon()->custom(
                $isIgnored ?
                    ilUtil::getImagePath('standard/icon_not_ok.svg') :
                    ilUtil::getImagePath('standard/icon_ok.svg'),
                $isIgnored ? $this->lng->txt('inactive') : $this->lng->txt('active'),
                \ILIAS\UI\Component\Symbol\Icon\Icon::SMALL
            );

            // Text columns of the data table are output as HTML; error messages can
            // contain HTML from the failed request and must be escaped
            $message = mb_substr($entry['message'], 0, 100) . (mb_strlen($entry['message']) > 100 ? '...' : '');
            $file = $entry['file'] ? $entry['file'] . ($entry['line'] ? ':' . $entry['line'] : '') : '-';

            $row_data = [
                'timestamp' => $entry['timestamp'],
                'code' => $this->buildDetailsLink((int) $entry['id'], (string) $entry['code']),
                'status' => $statusIcon,
                'level' => htmlspecialchars(strtoupper((string) $entry['severity'])),
                'message' => htmlspecialchars($message),
                'file' => htmlspecialchars($file)
            ];

            yield $row_builder->buildDataRow(
                (string) $entry['id'],
                $row_data
            );
        }
    }

    /**
     * @param array<string, mixed>|null $filter_data
     * @param array<string, mixed>|null $additional_parameters
     */
    public function getTotalRowCount(
        mixed $additional_viewcontrol_data,
        mixed $filter_data,
        mixed $additional_parameters
    ): ?int {
        // Convert filter data to repo format (same logic as getRows)
        $filter = [];
        $showIgnored = false;

        // Check for session filter data (from filter form)
        $session_filter = ilSession::get(ilIssueAnalysisGUI::SESSION_FILTER);
        if ($session_filter) {
            if (!empty($session_filter['severity'])) {
                $filter['severity'] = $session_filter['severity'];
            }
            if (!empty($session_filter['search'])) {
                $filter['search'] = $session_filter['search'];
            }
            if (!empty($session_filter['error_code'])) {
                $filter['error_code'] = $session_filter['error_code'];
            }
            if (!empty($session_filter['from_date'])) {
                $filter['from_date'] = $session_filter['from_date'];
            }
            if (!empty($session_filter['to_date'])) {
                $filter['to_date'] = $session_filter['to_date'];
            }
            if (isset($session_filter['show_ignored'])) {
                $showIgnored = $session_filter['show_ignored'] === 'show';
            }
        }

        // Also check table filter_data parameter (for future compatibility)
        if ($filter_data) {
            if (!empty($filter_data['severity'])) {
                $filter['severity'] = $filter_data['severity'];
            }
            if (!empty($filter_data['search'])) {
                $filter['search'] = $filter_data['search'];
            }
        }

        return $this->repo->countLogEntries($filter, $showIgnored);
    }
}
