<?php declare(strict_types=1);

// Load plugin bootstrap (includes Composer autoloader)
require_once __DIR__ . '/bootstrap.php';

use ILIAS\Plugin\xial\Service\StringTruncator;

/**
 * Repository class for IssueAnalysis plugin data access
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class ilIssueAnalysisRepo
{
    private ilDBInterface $db;

    public function __construct(?ilDBInterface $db = null)
    {
        global $DIC;
        $this->db = $db ?? $DIC->database();
    }

    /**
     * Insert or update error type in xial_error table
     */
    public function insertOrUpdateErrorType(
        string $stacktraceHash,
        string $message,
        ?string $file,
        ?int $line,
        string $severity,
        string $timestamp
    ): void {
        // Check if error type already exists
        $result = $this->db->queryF(
            "SELECT stacktrace_hash, occurrence_count FROM xial_error WHERE stacktrace_hash = %s",
            ['text'],
            [$stacktraceHash]
        );

        if ($this->db->numRows($result) > 0) {
            // UPDATE: increment occurrence_count, update last_seen
            $row = $this->db->fetchAssoc($result);
            $newCount = ((int) $row['occurrence_count']) + 1;

            $this->db->update('xial_error', [
                'occurrence_count' => ['integer', $newCount],
                'last_seen' => ['timestamp', $timestamp]
            ], [
                'stacktrace_hash' => ['text', $stacktraceHash]
            ]);
        } else {
            // INSERT: new error type
            $this->db->insert('xial_error', [
                'stacktrace_hash' => ['text', $stacktraceHash],
                'message' => ['clob', $message],
                'file' => ['text', StringTruncator::truncateFilePath($file, 500)],
                'line' => ['integer', $line],
                'severity' => ['text', $severity],
                'first_seen' => ['timestamp', $timestamp],
                'last_seen' => ['timestamp', $timestamp],
                'occurrence_count' => ['integer', 1],
                'ignored' => ['integer', 0]
            ]);
        }
    }

    /**
     * Insert error log entry
     */
    public function insertLogEntry(array $data): int
    {
        $id = $this->db->nextId('xial_log');

        // Truncate fields to safe lengths to prevent SQL errors
        // Full stacktrace is in xial_error.message and original file (lazy-loading)
        $truncated = StringTruncator::truncateFields($data, [
            'message' => 2000,
            'file' => 500,
            'context' => 1000,
            'code' => 50
        ]);

        $this->db->insert('xial_log', [
            'id' => ['integer', $id],
            'timestamp' => ['timestamp', $data['timestamp']],
            'severity' => ['text', $data['severity']],
            'message' => ['text', $truncated['message'] ?? ''],
            'file' => ['text', $truncated['file'] ?? null],
            'line' => ['integer', $data['line'] ?? null],
            'code' => ['text', $truncated['code'] ?? null],
            'stacktrace_hash' => ['text', $data['stacktrace_hash'] ?? null],
            'user_id' => ['integer', $data['user_id'] ?? null],
            'session_id' => ['text', $data['session_id'] ?? null],
            'ip_address' => ['text', $data['ip_address'] ?? null],
            'user_agent_hash' => ['text', $data['user_agent_hash'] ?? null],
            'context' => ['text', $truncated['context'] ?? null],
            'analyzed' => ['integer', 0],
            'created_at' => ['timestamp', date('Y-m-d H:i:s')]
        ]);

        return $id;
    }

    /**
     * Get log entries with optional filtering and sorting
     */
    public function getLogEntries(array $filter = [], int $offset = 0, int $limit = 50, array $order = [], bool $showIgnored = false): array
    {
        $sql = "SELECT l.*, e.ignored as error_ignored
                FROM xial_log l
                LEFT JOIN xial_error e ON l.stacktrace_hash = e.stacktrace_hash
                WHERE 1=1";

        $params = [];
        $types = [];

        // Filter ignored errors (unless showIgnored is true)
        if (!$showIgnored) {
            $sql .= " AND (e.ignored IS NULL OR e.ignored = 0)";
        }

        // Apply filters
        if (!empty($filter['severity'])) {
            $sql .= " AND l.severity = %s";
            $params[] = $filter['severity'];
            $types[] = 'text';
        }

        if (!empty($filter['from_date'])) {
            $sql .= " AND l.timestamp >= %s";
            $params[] = $filter['from_date'];
            $types[] = 'timestamp';
        }

        if (!empty($filter['to_date'])) {
            $sql .= " AND l.timestamp <= %s";
            $params[] = $filter['to_date'];
            $types[] = 'timestamp';
        }

        if (!empty($filter['user_id'])) {
            $sql .= " AND l.user_id = %s";
            $params[] = (int) $filter['user_id'];
            $types[] = 'integer';
        }

        if (!empty($filter['search'])) {
            $sql .= " AND (l.message LIKE %s OR l.file LIKE %s OR l.code LIKE %s)";
            $searchTerm = '%' . $filter['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $types[] = 'text';
            $types[] = 'text';
            $types[] = 'text';
        }

        if (!empty($filter['error_code'])) {
            $sql .= " AND l.code LIKE %s";
            $errorCodeTerm = '%' . $filter['error_code'] . '%';
            $params[] = $errorCodeTerm;
            $types[] = 'text';
        }

        if (isset($filter['analyzed'])) {
            $sql .= " AND l.analyzed = %s";
            $params[] = (int) $filter['analyzed'];
            $types[] = 'integer';
        }

        // Add sorting
        $orderBy = " ORDER BY ";
        if (!empty($order)) {
            $orderParts = [];
            $validColumns = ['timestamp', 'code', 'severity', 'message', 'file', 'line'];
            foreach ($order as $column => $direction) {
                if (in_array($column, $validColumns)) {
                    $direction = ($direction === 'DESC') ? 'DESC' : 'ASC';
                    $orderParts[] = "l.$column $direction";
                }
            }
            if (!empty($orderParts)) {
                $orderBy .= implode(', ', $orderParts);
            } else {
                $orderBy .= "l.timestamp DESC";  // default
            }
        } else {
            $orderBy .= "l.timestamp DESC";  // default
        }

        $sql .= $orderBy . " LIMIT %s OFFSET %s";
        $params[] = $limit;
        $params[] = $offset;
        $types[] = 'integer';
        $types[] = 'integer';

        $result = $this->db->queryF($sql, $types, $params);
        $entries = [];

        while ($row = $this->db->fetchAssoc($result)) {
            $entries[] = $row;
        }

        return $entries;
    }

    /**
     * Get single log entry by ID
     * Note: Stacktrace is loaded via lazy-loading from original file (see getLogEntryWithFullContent)
     */
    public function getLogEntry(int $id): ?array
    {
        $result = $this->db->queryF(
            "SELECT l.*, e.ignored as error_ignored
             FROM xial_log l
             LEFT JOIN xial_error e ON l.stacktrace_hash = e.stacktrace_hash
             WHERE l.id = %s",
            ['integer'],
            [$id]
        );

        $row = $this->db->fetchAssoc($result);
        return $row ?: null;
    }

    /**
     * Count total log entries with optional filtering
     */
    public function countLogEntries(array $filter = [], bool $showIgnored = false): int
    {
        $sql = "SELECT COUNT(*) as cnt FROM xial_log l
                LEFT JOIN xial_error e ON l.stacktrace_hash = e.stacktrace_hash
                WHERE 1=1";

        // Filter ignored errors (unless showIgnored is true)
        if (!$showIgnored) {
            $sql .= " AND (e.ignored IS NULL OR e.ignored = 0)";
        }

        $params = [];
        $types = [];

        // Apply same filters as getLogEntries
        if (!empty($filter['severity'])) {
            $sql .= " AND l.severity = %s";
            $params[] = $filter['severity'];
            $types[] = 'text';
        }

        if (!empty($filter['from_date'])) {
            $sql .= " AND l.timestamp >= %s";
            $params[] = $filter['from_date'];
            $types[] = 'timestamp';
        }

        if (!empty($filter['to_date'])) {
            $sql .= " AND l.timestamp <= %s";
            $params[] = $filter['to_date'];
            $types[] = 'timestamp';
        }

        if (!empty($filter['user_id'])) {
            $sql .= " AND l.user_id = %s";
            $params[] = (int) $filter['user_id'];
            $types[] = 'integer';
        }

        if (!empty($filter['search'])) {
            $sql .= " AND (l.message LIKE %s OR l.file LIKE %s OR l.code LIKE %s)";
            $searchTerm = '%' . $filter['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $types[] = 'text';
            $types[] = 'text';
            $types[] = 'text';
        }

        if (!empty($filter['error_code'])) {
            $sql .= " AND l.code LIKE %s";
            $errorCodeTerm = '%' . $filter['error_code'] . '%';
            $params[] = $errorCodeTerm;
            $types[] = 'text';
        }

        if (isset($filter['analyzed'])) {
            $sql .= " AND l.analyzed = %s";
            $params[] = (int) $filter['analyzed'];
            $types[] = 'integer';
        }

        $result = $this->db->queryF($sql, $types, $params);
        $row = $this->db->fetchAssoc($result);

        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Mark entries as analyzed
     */
    public function markAsAnalyzed(array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        $placeholders = str_repeat('%s,', count($ids));
        $placeholders = rtrim($placeholders, ',');

        $this->db->queryF(
            "UPDATE xial_log SET analyzed = 1 WHERE id IN ($placeholders)",
            array_fill(0, count($ids), 'integer'),
            $ids
        );
    }

    /**
     * Delete old entries based on retention settings
     */
    public function deleteOldEntries(int $retentionDays, int $maxRecords): int
    {
        $deletedCount = 0;

        // Delete by age
        if ($retentionDays > 0) {
            $cutoffDate = date('Y-m-d H:i:s', time() - ($retentionDays * 24 * 60 * 60));

            $result = $this->db->queryF(
                "SELECT id FROM xial_log WHERE timestamp < %s",
                ['timestamp'],
                [$cutoffDate]
            );

            $idsToDelete = [];
            while ($row = $this->db->fetchAssoc($result)) {
                $idsToDelete[] = $row['id'];
            }

            if (!empty($idsToDelete)) {
                $this->deleteLogEntries($idsToDelete);
                $deletedCount += count($idsToDelete);
            }
        }

        // Delete by count (keep only most recent entries)
        if ($maxRecords > 0) {
            $result = $this->db->queryF(
                "SELECT id FROM xial_log ORDER BY timestamp DESC LIMIT %s OFFSET %s",
                ['integer', 'integer'],
                [999999, $maxRecords]
            );

            $idsToDelete = [];
            while ($row = $this->db->fetchAssoc($result)) {
                $idsToDelete[] = $row['id'];
            }

            if (!empty($idsToDelete)) {
                $this->deleteLogEntries($idsToDelete);
                $deletedCount += count($idsToDelete);
            }
        }

        return $deletedCount;
    }

    /**
     * Delete specific log entries
     */
    public function deleteLogEntries(array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        $placeholders = str_repeat('%s,', count($ids));
        $placeholders = rtrim($placeholders, ',');

        // Delete log entries
        $this->db->queryF(
            "DELETE FROM xial_log WHERE id IN ($placeholders)",
            array_fill(0, count($ids), 'integer'),
            $ids
        );

        // Clean up orphaned error types (xial_error entries with no remaining log entries)
        $this->cleanupOrphanedErrorTypes();
    }

    /**
     * Delete error types that have no remaining log entries
     */
    private function cleanupOrphanedErrorTypes(): void
    {
        // Delete xial_error entries where no xial_log entries reference the hash
        $this->db->query(
            "DELETE FROM xial_error WHERE stacktrace_hash NOT IN (SELECT DISTINCT stacktrace_hash FROM xial_log WHERE stacktrace_hash IS NOT NULL)"
        );
    }

    /**
     * Get comprehensive error analysis statistics
     */
    public function getStatistics(string $timeRange = 'last_week'): array
    {
        return [
            'overview' => $this->getOverviewStats(),
            'timeranges' => $this->getTimerangeStats($timeRange),
            'sources' => $this->getSourceFileStats($timeRange),
            'patterns' => $this->getErrorPatternStats($timeRange)
        ];
    }

    /**
     * Get basic overview statistics
     */
    public function getOverviewStats(): array
    {
        $stats = [
            'total_entries' => 0,
            'by_severity' => [],
            'analyzed_count' => 0,
            'unanalyzed_count' => 0,
            'unique_files' => 0,
            'unique_error_codes' => 0
        ];

        // Total entries
        $result = $this->db->query("SELECT COUNT(*) as cnt FROM xial_log");
        $row = $this->db->fetchAssoc($result);
        $stats['total_entries'] = (int) $row['cnt'];

        // By severity
        $result = $this->db->query("SELECT severity, COUNT(*) as cnt FROM xial_log GROUP BY severity ORDER BY cnt DESC");
        while ($row = $this->db->fetchAssoc($result)) {
            $stats['by_severity'][$row['severity']] = (int) $row['cnt'];
        }

        // Analyzed vs unanalyzed
        $result = $this->db->query("SELECT analyzed, COUNT(*) as cnt FROM xial_log GROUP BY analyzed");
        while ($row = $this->db->fetchAssoc($result)) {
            if ($row['analyzed'] == 1) {
                $stats['analyzed_count'] = (int) $row['cnt'];
            } else {
                $stats['unanalyzed_count'] = (int) $row['cnt'];
            }
        }

        // Unique files with errors
        $result = $this->db->query("SELECT COUNT(DISTINCT file) as cnt FROM xial_log WHERE file IS NOT NULL AND file != ''");
        $row = $this->db->fetchAssoc($result);
        $stats['unique_files'] = (int) $row['cnt'];

        // Unique error codes
        $result = $this->db->query("SELECT COUNT(DISTINCT code) as cnt FROM xial_log WHERE code IS NOT NULL AND code != ''");
        $row = $this->db->fetchAssoc($result);
        $stats['unique_error_codes'] = (int) $row['cnt'];

        return $stats;
    }

    /**
     * Get error statistics by time ranges with flexible periods
     */
    public function getTimerangeStats(string $timeRange = 'last_week'): array
    {
        $stats = [
            'summary' => [],
            'chart_data' => [],
            'time_range' => $timeRange
        ];

        // Define time intervals based on selection
        switch ($timeRange) {
            case 'today':
                $stats['summary'] = $this->getHourlyStats();
                $stats['chart_data'] = $this->getHourlyChartData();
                break;
            case 'yesterday':
                $stats['summary'] = $this->getHourlyStats('yesterday');
                $stats['chart_data'] = $this->getHourlyChartData('yesterday');
                break;
            case 'last_week':
                $stats['summary'] = $this->getDailyStats(7);
                $stats['chart_data'] = $this->getDailyChartData(7);
                break;
            case 'last_month':
                $stats['summary'] = $this->getDailyStats(30);
                $stats['chart_data'] = $this->getDailyChartData(30);
                break;
            case 'last_3_months':
                $stats['summary'] = $this->getWeeklyStats(12);
                $stats['chart_data'] = $this->getWeeklyChartData(12);
                break;
            default:
                $stats['summary'] = $this->getDailyStats(7);
                $stats['chart_data'] = $this->getDailyChartData(7);
        }

        return $stats;
    }

    /**
     * Get hourly statistics for today or yesterday
     */
    private function getHourlyStats(string $day = 'today'): array
    {
        $dateCondition = $day === 'yesterday' ? 'DATE(timestamp) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)' : 'DATE(timestamp) = CURDATE()';

        $result = $this->db->query(
            "SELECT HOUR(timestamp) as hour, COUNT(*) as cnt, severity
             FROM xial_log
             WHERE $dateCondition
             GROUP BY HOUR(timestamp), severity
             ORDER BY hour"
        );

        $stats = [];
        while ($row = $this->db->fetchAssoc($result)) {
            $hour = (int)$row['hour'];
            if (!isset($stats[$hour])) {
                $stats[$hour] = ['total' => 0, 'by_severity' => []];
            }
            $stats[$hour]['total'] += (int)$row['cnt'];
            $stats[$hour]['by_severity'][$row['severity']] = (int)$row['cnt'];
        }

        return $stats;
    }

    /**
     * Get daily statistics for specified number of days
     */
    private function getDailyStats(int $days): array
    {
        $result = $this->db->query(
            "SELECT DATE(timestamp) as date, COUNT(*) as cnt, severity
             FROM xial_log
             WHERE timestamp >= DATE_SUB(NOW(), INTERVAL $days DAY)
             GROUP BY DATE(timestamp), severity
             ORDER BY date DESC"
        );

        $stats = [];
        while ($row = $this->db->fetchAssoc($result)) {
            $date = $row['date'];
            if (!isset($stats[$date])) {
                $stats[$date] = ['total' => 0, 'by_severity' => []];
            }
            $stats[$date]['total'] += (int)$row['cnt'];
            $stats[$date]['by_severity'][$row['severity']] = (int)$row['cnt'];
        }

        return $stats;
    }

    /**
     * Get weekly statistics for specified number of weeks
     */
    private function getWeeklyStats(int $weeks): array
    {
        $result = $this->db->query(
            "SELECT YEAR(timestamp) as year, WEEK(timestamp) as week, COUNT(*) as cnt, severity
             FROM xial_log
             WHERE timestamp >= DATE_SUB(NOW(), INTERVAL $weeks WEEK)
             GROUP BY YEAR(timestamp), WEEK(timestamp), severity
             ORDER BY year DESC, week DESC"
        );

        $stats = [];
        while ($row = $this->db->fetchAssoc($result)) {
            $key = $row['year'] . '-W' . str_pad((string)$row['week'], 2, '0', STR_PAD_LEFT);
            if (!isset($stats[$key])) {
                $stats[$key] = ['total' => 0, 'by_severity' => []];
            }
            $stats[$key]['total'] += (int)$row['cnt'];
            $stats[$key]['by_severity'][$row['severity']] = (int)$row['cnt'];
        }

        return $stats;
    }

    /**
     * Get chart data for hourly view
     */
    private function getHourlyChartData(string $day = 'today'): array
    {
        $dateCondition = $day === 'yesterday' ? 'DATE(timestamp) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)' : 'DATE(timestamp) = CURDATE()';

        $result = $this->db->query(
            "SELECT HOUR(timestamp) as hour, COUNT(*) as cnt
             FROM xial_log
             WHERE $dateCondition
             GROUP BY HOUR(timestamp)
             ORDER BY hour"
        );

        $data = [];
        // Initialize all 24 hours with 0
        for ($i = 0; $i < 24; $i++) {
            $data[str_pad((string)$i, 2, '0', STR_PAD_LEFT) . ':00'] = 0;
        }

        while ($row = $this->db->fetchAssoc($result)) {
            $hour = str_pad((string)$row['hour'], 2, '0', STR_PAD_LEFT) . ':00';
            $data[$hour] = (int)$row['cnt'];
        }

        return $data;
    }

    /**
     * Get chart data for daily view
     */
    private function getDailyChartData(int $days): array
    {
        $result = $this->db->query(
            "SELECT DATE(timestamp) as date, COUNT(*) as cnt
             FROM xial_log
             WHERE timestamp >= DATE_SUB(NOW(), INTERVAL $days DAY)
             GROUP BY DATE(timestamp)
             ORDER BY date"
        );

        $data = [];
        // Initialize all days in the range with 0
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $data[$date] = 0;
        }

        // Fill in actual data
        while ($row = $this->db->fetchAssoc($result)) {
            $data[$row['date']] = (int)$row['cnt'];
        }

        return $data;
    }

    /**
     * Get chart data for weekly view
     */
    private function getWeeklyChartData(int $weeks): array
    {
        $result = $this->db->query(
            "SELECT YEAR(timestamp) as year, WEEK(timestamp) as week, COUNT(*) as cnt
             FROM xial_log
             WHERE timestamp >= DATE_SUB(NOW(), INTERVAL $weeks WEEK)
             GROUP BY YEAR(timestamp), WEEK(timestamp)
             ORDER BY year, week"
        );

        $data = [];
        while ($row = $this->db->fetchAssoc($result)) {
            $key = $row['year'] . '-W' . str_pad((string)$row['week'], 2, '0', STR_PAD_LEFT);
            $data[$key] = (int)$row['cnt'];
        }

        return $data;
    }

    /**
     * Get error statistics by source files with time range
     */
    public function getSourceFileStats(string $timeRange = 'last_week'): array
    {
        // Convert time range to SQL interval
        $interval = $this->getIntervalFromTimeRange($timeRange);

        $stats = [
            'top_error_files' => [],
            'file_chart_data' => [],
            'time_range' => $timeRange
        ];

        // Top error-producing files for selected time range with latest occurrence
        $result = $this->db->query(
            "SELECT file, COUNT(*) as error_count, severity, MAX(timestamp) as latest_timestamp
             FROM xial_log
             WHERE file IS NOT NULL AND file != ''
             AND timestamp >= DATE_SUB(NOW(), INTERVAL $interval)
             GROUP BY file, severity
             ORDER BY error_count DESC"
        );

        $file_data = [];
        while ($row = $this->db->fetchAssoc($result)) {
            $file = $row['file'];
            if (!isset($file_data[$file])) {
                $file_data[$file] = ['total' => 0, 'by_severity' => [], 'latest_timestamp' => null];
            }
            $file_data[$file]['total'] += (int) $row['error_count'];
            $file_data[$file]['by_severity'][$row['severity']] = (int) $row['error_count'];

            // Keep track of the most recent timestamp for this file
            if ($file_data[$file]['latest_timestamp'] === null ||
                $row['latest_timestamp'] > $file_data[$file]['latest_timestamp']) {
                $file_data[$file]['latest_timestamp'] = $row['latest_timestamp'];
            }
        }

        // Sort by latest occurrence (most recent first), then by count
        uasort($file_data, function($a, $b) {
            // First sort by latest timestamp (descending - newest first)
            if ($a['latest_timestamp'] !== $b['latest_timestamp']) {
                return strcmp($b['latest_timestamp'], $a['latest_timestamp']);
            }
            // If timestamps are equal, sort by error count (descending)
            return $b['total'] - $a['total'];
        });

        $counter = 0;
        foreach ($file_data as $file => $data) {
            if ($counter >= 10) break;

            // Get unique messages for this file in the time range
            $messages_result = $this->db->queryF(
                "SELECT DISTINCT message FROM xial_log
                 WHERE file = %s
                 AND timestamp >= DATE_SUB(NOW(), INTERVAL " . $interval . ")
                 ORDER BY message
                 LIMIT 5",
                ['text'],
                [$file]
            );

            $unique_messages = [];
            while ($msg_row = $this->db->fetchAssoc($messages_result)) {
                // Truncate long messages for better display
                $message = $msg_row['message'];
                if (strlen($message) > 120) {
                    $message = substr($message, 0, 120) . '...';
                }
                $unique_messages[] = $message;
            }

            $stats['top_error_files'][] = [
                'file' => $file,
                'count' => $data['total'],
                'by_severity' => $data['by_severity'],
                'latest_occurrence' => $data['latest_timestamp'],
                'unique_messages' => $unique_messages
            ];
            $counter++;
        }

        // Chart data for top 5 files
        $chart_data = [];
        $counter = 0;
        foreach ($file_data as $file => $data) {
            if ($counter >= 5) break;
            $chart_data[basename($file)] = $data['total'];
            $counter++;
        }
        $stats['file_chart_data'] = $chart_data;

        return $stats;
    }

    /**
     * Convert time range string to SQL interval
     */
    private function getIntervalFromTimeRange(string $timeRange): string
    {
        switch ($timeRange) {
            case 'today':
                return '1 DAY';
            case 'yesterday':
                return '2 DAY';
            case 'last_week':
                return '7 DAY';
            case 'last_month':
                return '30 DAY';
            case 'last_3_months':
                return '90 DAY';
            default:
                return '7 DAY';
        }
    }

    /**
     * Get error pattern analysis (without random error codes)
     */
    public function getErrorPatternStats(string $timeRange = 'last_week'): array
    {
        $stats = [
            'critical_errors' => [],
            'severity_chart_data' => []
        ];

        // Get interval for the specified range
        $interval = $this->getIntervalFromTimeRange($timeRange);

        // Critical errors (fatal, parse errors in the selected time range)
        $result = $this->db->query(
            "SELECT severity, message, file, timestamp, code
             FROM xial_log
             WHERE severity IN ('fatal_error', 'parse_error')
             AND timestamp >= DATE_SUB(NOW(), INTERVAL $interval)
             ORDER BY timestamp DESC
             LIMIT 5"
        );
        while ($row = $this->db->fetchAssoc($result)) {
            $stats['critical_errors'][] = [
                'severity' => $row['severity'],
                'message' => substr($row['message'], 0, 100) . (strlen($row['message']) > 100 ? '...' : ''),
                'file' => $row['file'],
                'timestamp' => $row['timestamp'],
                'code' => $row['code']
            ];
        }

        // Severity distribution for pie chart (now respects time range)
        $result = $this->db->query(
            "SELECT severity, COUNT(*) as cnt
             FROM xial_log
             WHERE timestamp >= DATE_SUB(NOW(), INTERVAL $interval)
             GROUP BY severity
             ORDER BY cnt DESC"
        );
        while ($row = $this->db->fetchAssoc($result)) {
            $stats['severity_chart_data'][$row['severity']] = (int) $row['cnt'];
        }

        return $stats;
    }

    /**
     * Update source file tracking
     */
    public function updateSourceTracking(string $filePath, string $fileInode, int $offset): void
    {
        // Check if record exists
        $result = $this->db->queryF(
            "SELECT file_path FROM xial_source WHERE file_path = %s",
            ['text'],
            [$filePath]
        );

        if ($this->db->numRows($result) > 0) {
            // Update existing record
            $this->db->update('xial_source', [
                'file_inode' => ['text', $fileInode],
                'last_offset' => ['integer', $offset],
                'last_check' => ['timestamp', date('Y-m-d H:i:s')]
            ], [
                'file_path' => ['text', $filePath]
            ]);
        } else {
            // Insert new record
            $this->db->insert('xial_source', [
                'file_path' => ['text', $filePath],
                'file_inode' => ['text', $fileInode],
                'last_offset' => ['integer', $offset],
                'last_check' => ['timestamp', date('Y-m-d H:i:s')]
            ]);
        }
    }

    /**
     * Get source file tracking info
     */
    public function getSourceTracking(string $filePath): ?array
    {
        $result = $this->db->queryF(
            "SELECT * FROM xial_source WHERE file_path = %s",
            ['text'],
            [$filePath]
        );

        $row = $this->db->fetchAssoc($result);
        return $row ?: null;
    }

    /**
     * Check if error code already exists (to avoid duplicates)
     */
    public function errorCodeExists(string $errorCode): bool
    {
        $result = $this->db->queryF(
            "SELECT COUNT(*) as cnt FROM xial_log WHERE code = %s",
            ['text'],
            [$errorCode]
        );

        $row = $this->db->fetchAssoc($result);
        return (int)$row['cnt'] > 0;
    }

    /**
     * Clear source tracking
     */
    public function clearSourceTracking(): void
    {
        $this->db->query("DELETE FROM xial_source");
    }

    /**
     * Clear all imported data (including error types)
     */
    public function clearAllData(): void
    {
        $this->db->query("DELETE FROM xial_log");
        $this->db->query("DELETE FROM xial_source");
        $this->db->query("DELETE FROM xial_error");
    }

    /**
     * Export log entries to array for CSV/JSON export
     * Note: Stacktrace available via lazy-loading from xial_error.message or original file
     */
    public function exportLogEntries(array $ids = []): array
    {
        $sql = "SELECT l.*, e.message as stacktrace, e.ignored as error_ignored
                FROM xial_log l
                LEFT JOIN xial_error e ON l.stacktrace_hash = e.stacktrace_hash";
        $params = [];
        $types = [];

        if (!empty($ids)) {
            $placeholders = str_repeat('%s,', count($ids));
            $placeholders = rtrim($placeholders, ',');
            $sql .= " WHERE l.id IN ($placeholders)";
            $params = $ids;
            $types = array_fill(0, count($ids), 'integer');
        }

        $sql .= " ORDER BY l.timestamp DESC";

        $result = $this->db->queryF($sql, $types, $params);
        $entries = [];

        while ($row = $this->db->fetchAssoc($result)) {
            $entries[] = $row;
        }

        return $entries;
    }

    /**
     * Ignore/hide all errors of a specific type (by stacktrace hash)
     */
    public function ignoreHash(string $stacktraceHash, int $userId, ?string $note = null): void
    {
        $this->db->update('xial_error', [
            'ignored' => ['integer', 1],
            'ignored_at' => ['timestamp', date('Y-m-d H:i:s')],
            'ignored_by' => ['integer', $userId],
            'note' => ['text', $note]
        ], [
            'stacktrace_hash' => ['text', $stacktraceHash]
        ]);
    }

    /**
     * Un-ignore/show errors of a specific type again
     */
    public function unignoreHash(string $stacktraceHash): void
    {
        $this->db->update('xial_error', [
            'ignored' => ['integer', 0],
            'ignored_at' => ['timestamp', null],
            'ignored_by' => ['integer', null],
            'note' => ['text', null]
        ], [
            'stacktrace_hash' => ['text', $stacktraceHash]
        ]);
    }

    /**
     * Get all ignored error types
     */
    public function getIgnoredHashes(): array
    {
        $result = $this->db->query(
            "SELECT e.*, COUNT(l.id) as affected_count
             FROM xial_error e
             LEFT JOIN xial_log l ON e.stacktrace_hash = l.stacktrace_hash
             WHERE e.ignored = 1
             GROUP BY e.stacktrace_hash
             ORDER BY e.ignored_at DESC"
        );

        $hashes = [];
        while ($row = $this->db->fetchAssoc($result)) {
            $hashes[] = $row;
        }

        return $hashes;
    }

    /**
     * Get stacktrace hash for a specific error code
     */
    public function getHashForErrorCode(string $errorCode): ?string
    {
        $result = $this->db->queryF(
            "SELECT stacktrace_hash FROM xial_log WHERE code = %s LIMIT 1",
            ['text'],
            [$errorCode]
        );

        $row = $this->db->fetchAssoc($result);
        return $row ? $row['stacktrace_hash'] : null;
    }

    /**
     * Get full error content from original file (lazy-loading)
     */
    public function getLogEntryWithFullContent(int $id, string $errorLogDir): array
    {
        // Get basic entry from DB
        $entry = $this->getLogEntry($id);
        if (!$entry) {
            return [];
        }

        // Try to load full content from original file
        $errorCode = $entry['code'];
        if ($errorCode) {
            $filePath = rtrim($errorLogDir, '/') . '/' . $errorCode . '.log';

            if (file_exists($filePath) && is_readable($filePath)) {
                $fullContent = file_get_contents($filePath);
                if ($fullContent !== false) {
                    // SUCCESS: Full content from original file (with Request-Data)
                    $entry['full_stacktrace'] = $fullContent;
                    $entry['file_available'] = true;
                } else {
                    $entry['file_available'] = false;
                }
            } else {
                $entry['file_available'] = false;
            }
        }

        // FALLBACK: If original file not available, load stacktrace from xial_error
        if (empty($entry['full_stacktrace']) && !empty($entry['stacktrace_hash'])) {
            $result = $this->db->queryF(
                "SELECT message FROM xial_error WHERE stacktrace_hash = %s",
                ['text'],
                [$entry['stacktrace_hash']]
            );

            if ($row = $this->db->fetchAssoc($result)) {
                // Use stacktrace from xial_error as fallback (without Request-Data, but better than nothing)
                $entry['stacktrace'] = $row['message'];
            }
        }

        return $entry;
    }
}
