<?php declare(strict_types=1);

// Load plugin bootstrap (includes Composer autoloader)
require_once __DIR__ . '/bootstrap.php';

/**
 * Data table component for displaying ILIAS error log entries
 *
 * Provides a modern ILIAS UI table with filtering, sorting, and actions
 * for viewing imported error log data with clickable error codes.
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
     * Simple getHTML method following CustomMetaBarLinks pattern
     */
    public function getHTML(): string
    {
        $data_retrieval = new ilIssueAnalysisTableDataRetrieval();
        $table = $this->createTable($data_retrieval);

        // Get total count for current filter
        $total_count = $data_retrieval->getTotalRowCount(null, null);

        // Create info text about total entries
        $info_text = $this->plugin->txt('table_total_entries') . ': ' . $total_count;
        $info_component = $this->ui_factory->legacy('<div style="margin-bottom: 10px; font-weight: bold; color: #666;">' . $info_text . '</div>');

        return $this->renderer->render([$info_component, $table]);
    }

    /**
     * Create the error analysis table following CustomMetaBarLinks pattern
     */
    protected function createTable(ilIssueAnalysisTableDataRetrieval $data_retrieval): \ILIAS\UI\Component\Table\Data
    {
        // Define columns with appropriate sorting
        $columns = [
            'timestamp' => $this->ui_factory->table()->column()->text($this->plugin->txt('col_timestamp'))
                ->withIsSortable(true),
            'code' => $this->ui_factory->table()->column()->text($this->plugin->txt('detail_error_code'))
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
            '',
            $columns,
            $data_retrieval
        );

        // Add actions following CustomMetaBarLinks pattern
        $actions = $this->createActions();
        if (!empty($actions)) {
            $table = $table->withActions($actions);
        }

        return $table->withRequest($this->http->request());
    }

    /**
     * Create table actions following CustomMetaBarLinks pattern
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

    public function __construct()
    {
        global $DIC;

        require_once __DIR__ . '/class.ilIssueAnalysisRepo.php';
        $this->repo = new ilIssueAnalysisRepo();
        $this->ui_factory = $DIC->ui()->factory();
        $this->lng = $DIC->language();
    }

    public function getRows(
        \ILIAS\UI\Component\Table\DataRowBuilder $row_builder,
        array $visible_column_ids,
        \ILIAS\Data\Range $range,
        \ILIAS\Data\Order $order,
        ?array $filter_data,
        ?array $additional_parameters
    ): \Generator {
        // Convert filter data to repo format
        $filter = [];
        $showIgnored = false;

        // Check for session filter data (from filter form)
        $session_filter = $_SESSION['xial_filter'] ?? null;
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
            // NEW: Check for show_ignored filter (select with '0' or '1')
            if (isset($session_filter['show_ignored'])) {
                $showIgnored = ($session_filter['show_ignored'] === '1' || $session_filter['show_ignored'] === 1);
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
            // Create details link using administration context
            $detailsLink = 'ilias.php?baseClass=iladministrationgui&cmdClass=ilIssueAnalysisAdminGUI&cmd=viewDetails&ref_id=' . SYSTEM_FOLDER_ID . '&id=' . $entry['id'];

            // Status icon: checkmark if visible, X if ignored/hidden using ILIAS standard icons
            $isIgnored = isset($entry['error_ignored']) && $entry['error_ignored'] == 1;
            $statusIcon = $this->ui_factory->symbol()->icon()->custom(
                $isIgnored ?
                    ilUtil::getImagePath('standard/icon_not_ok.svg') :
                    ilUtil::getImagePath('standard/icon_ok.svg'),
                $isIgnored ? $this->lng->txt('inactive') : $this->lng->txt('active'),
                \ILIAS\UI\Component\Symbol\Icon\Icon::SMALL
            );

            $row_data = [
                'timestamp' => $entry['timestamp'],
                'code' => '<a href="' . $detailsLink . '" style="color: #007cba; text-decoration: underline;">' . ($entry['code'] ?: '-') . '</a>',
                'status' => $statusIcon,
                'level' => strtoupper($entry['severity']),
                'message' => mb_substr($entry['message'], 0, 100) . (mb_strlen($entry['message']) > 100 ? '...' : ''),
                'file' => $entry['file'] ? $entry['file'] . ($entry['line'] ? ':' . $entry['line'] : '') : '-'
            ];

            yield $row_builder->buildDataRow(
                (string)$entry['id'],
                $row_data
            );
        }
    }

    public function getTotalRowCount(
        ?array $filter_data,
        ?array $additional_parameters
    ): ?int {
        // Convert filter data to repo format (same logic as getRows)
        $filter = [];
        $showIgnored = false;

        // Check for session filter data (from filter form)
        $session_filter = $_SESSION['xial_filter'] ?? null;
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
            // NEW: Check for show_ignored filter (select with '0' or '1')
            if (isset($session_filter['show_ignored'])) {
                $showIgnored = ($session_filter['show_ignored'] === '1' || $session_filter['show_ignored'] === 1);
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
