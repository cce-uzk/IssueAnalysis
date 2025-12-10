<?php declare(strict_types=1);

/**
 * Log importer for IssueAnalysis plugin
 * Handles importing error log entries from ILIAS error log files
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 * @version 1.0.0
 */
class ilIssueAnalysisImporter
{
    private ilIssueAnalysisRepo $repo;
    private ilIssueAnalysisSettings $settings;
    private ilIssueAnalysisSanitizer $sanitizer;
    private ilLogger $logger;

    private int $importedCount = 0;
    private int $skippedCount = 0;
    private int $errorCount = 0;
    private array $errors = [];
    private bool $debugMode = false;

    public function __construct()
    {
        global $DIC;

        require_once __DIR__ . '/class.ilIssueAnalysisRepo.php';
        require_once __DIR__ . '/class.ilIssueAnalysisSettings.php';
        require_once __DIR__ . '/class.ilIssueAnalysisSanitizer.php';

        $this->repo = new ilIssueAnalysisRepo();
        $this->settings = new ilIssueAnalysisSettings();
        $this->sanitizer = new ilIssueAnalysisSanitizer($this->settings->getMaskSensitive());
        $this->logger = $DIC->logger()->xial();
    }

    /**
     * Import log entries from error log directory
     */
    public function importLogs(bool $isCronImport = false): array
    {
        $this->importedCount = 0;
        $this->skippedCount = 0;
        $this->errorCount = 0;
        $this->errors = [];

        $this->errors[] = "Debug: Starting import process";

        $startTime = time();

        try {
            $this->errors[] = "Debug: Getting time limit from settings";
            $timeLimit = $this->settings->getImportTimeLimit();
            $this->errors[] = "Debug: Time limit retrieved: {$timeLimit}";

            $this->errors[] = "Debug: About to get line limit from settings";
            // Use the method but with proper error handling
            try {
                $lineLimit = $this->settings->getImportLineLimit();
                $this->errors[] = "Debug: Line limit retrieved via method: {$lineLimit}";
            } catch (Exception $e2) {
                $this->errors[] = "Debug: Method failed, using your configured value: " . $e2->getMessage();
                $lineLimit = 100; // Your configured value as fallback
            }

            // Debug: Log the actual limits
            $this->errors[] = "Debug: Import limits - Time: {$timeLimit}s, Lines: {$lineLimit}";
            $this->errors[] = "Debug: About to get error log directory";
        } catch (Exception $e) {
            $this->errors[] = "Debug: ERROR getting limits: " . $e->getMessage();
            // Use defaults if settings fail
            $timeLimit = 120;
            $lineLimit = 100;
            $this->errors[] = "Debug: Using default limits - Time: {$timeLimit}s, Lines: {$lineLimit}";
        }

        $errorLogDir = $this->settings->getErrorLogDirectory();
        if (empty($errorLogDir) || !is_dir($errorLogDir)) {
            $this->errors[] = 'Error log directory not configured or not accessible: ' . $errorLogDir;
            return $this->getImportResult();
        }

        $this->log('Using error log directory: ' . $errorLogDir);

        $logFiles = $this->getLogFiles($errorLogDir);
        $this->errors[] = "Debug: Found " . count($logFiles) . " log files: " . implode(', ', $logFiles);

        foreach ($logFiles as $logFile) {
            if ((time() - $startTime) > $timeLimit) {
                $this->errors[] = 'Import stopped: time limit reached';
                break;
            }

            if ($this->importedCount >= $lineLimit) {
                $this->errors[] = 'Import stopped: line limit reached';
                break;
            }

            $continue = $this->processLogFile($logFile, $startTime, $timeLimit, $lineLimit);
            if (!$continue) {
                break; // Limit reached during processing
            }
        }

        // Update last import timestamp
        if ($isCronImport) {
            $this->settings->setLastCronImport(time());
        } else {
            $this->settings->setLastManualImport(time());
        }

        // Clean up old entries if retention is configured
        $this->cleanupOldEntries();

        return $this->getImportResult();
    }

    /**
     * Get list of ILIAS error files to process
     */
    private function getLogFiles(string $errorLogDir): array
    {
        $logFiles = [];

        if (!is_dir($errorLogDir)) {
            return $logFiles;
        }

        // Get all files in the error directory
        $files = scandir($errorLogDir);
        if (!$files) {
            return $logFiles;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $fullPath = $errorLogDir . '/' . $file;

            // Skip directories
            if (!is_file($fullPath)) {
                continue;
            }

            // ILIAS error files are typically:
            // - Numeric (error codes)
            // - Or have specific patterns
            if (preg_match('/^\d+$/', $file) || // Pure numeric error codes
                preg_match('/^error_\d+/', $file) || // error_123 pattern
                preg_match('/\.error$/', $file) || // .error files
                preg_match('/\.log$/', $file)) { // .log files
                $logFiles[] = $fullPath;
            }
        }

        // Sort by modification time (newest first)
        usort($logFiles, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        return $logFiles;
    }

    /**
     * Process a single ILIAS error file
     */
    private function processLogFile(string $filePath, int $startTime, int $timeLimit, int $lineLimit): bool
    {
        try {
            if (!is_readable($filePath)) {
                $this->errors[] = "Cannot read error file: $filePath";
                return true; // Continue with next file
            }

            $fileInfo = $this->getFileInfo($filePath);
            $tracking = $this->repo->getSourceTracking($filePath);

            // For ILIAS error files: if we've already processed this file completely, skip it
            if ($tracking && $tracking['file_inode'] === $fileInfo['inode'] && $tracking['last_offset'] >= $fileInfo['size']) {
                $this->skippedCount++;
                return true; // Continue with next file
            }

            // Read the entire ILIAS error file (they're usually small)
            $content = file_get_contents($filePath);
            if ($content === false) {
                $this->errors[] = "Cannot read error file content: $filePath";
                return true; // Continue with next file
            }

            // Extract error code from filename (remove .log extension)
            $errorCode = basename($filePath);
            $errorCode = preg_replace('/\.log$/i', '', $errorCode);

            // Parse ILIAS error file content
            $logEntry = $this->parseIliasErrorFile($content, $errorCode, $filePath);
            $this->errors[] = "Debug: Parsed entry for $errorCode: " . ($logEntry ? 'SUCCESS' : 'FAILED');

            if ($logEntry) {
                try {
                    // Check if this error was already imported (by error code)
                    $exists = $this->repo->errorCodeExists($errorCode);
                    $this->errors[] = "Debug: Error code $errorCode exists in DB: " . ($exists ? 'YES' : 'NO');

                    if (!$exists) {
                        $logId = $this->repo->insertLogEntry($logEntry);
                        $this->errors[] = "Debug: Inserted log entry with ID: $logId";

                        // Insert detailed data if available
                        if (!empty($logEntry['stacktrace']) || !empty($logEntry['request_data'])) {
                            $stacktrace_len = strlen($logEntry['stacktrace'] ?? '');
                            $request_len = strlen($logEntry['request_data'] ?? '');

                            $this->repo->insertLogDetail(
                                $logId,
                                $logEntry['stacktrace'],
                                $logEntry['request_data']
                            );
                            $this->errors[] = "Debug: Inserted detail data for log ID: $logId (stacktrace: $stacktrace_len chars, request: $request_len chars)";
                        } else {
                            $this->errors[] = "Debug: NO detail data inserted for log ID: $logId - stacktrace empty: " . (empty($logEntry['stacktrace']) ? 'YES' : 'NO');
                        }

                        $this->importedCount++;
                        $this->errors[] = "Debug: Import count now: " . $this->importedCount;

                        // Check if line limit reached after importing
                        if ($this->importedCount >= $lineLimit) {
                            $this->errors[] = "Import stopped: line limit reached ({$this->importedCount} >= {$lineLimit})";
                            return false; // Stop processing - limit reached
                        }
                    } else {
                        $this->skippedCount++;
                        $this->errors[] = "Debug: Skipped $errorCode (already exists)";
                    }
                } catch (Exception $e) {
                    $this->errorCount++;
                    $this->errors[] = "Debug: Exception during import: " . $e->getMessage();
                    $this->logger->error('Failed to import error file: ' . $e->getMessage());
                }
            } else {
                $this->errors[] = "Debug: Could not parse log entry for $errorCode";
            }

            // Only mark file as completely processed if no retention filter is active
            // If retention is active, don't update tracking to allow re-processing with different retention settings
            $retentionDays = $this->settings->getRetentionDays();
            if ($retentionDays === 0) {
                // No retention filter - safe to mark as completely processed
                $this->repo->updateSourceTracking($filePath, $fileInfo['inode'], $fileInfo['size']);
            }
            // If retention is active, we don't update tracking to allow re-import with different retention settings

        } catch (Exception $e) {
            $this->errors[] = "Error processing file $filePath: " . $e->getMessage();
            $this->logger->error('Import error: ' . $e->getMessage());
        }

        return true; // Continue processing
    }

    /**
     * Parse ILIAS error file content
     */
    private function parseIliasErrorFile(string $content, string $errorCode, string $filePath): ?array
    {
        // Debug the file content
        $this->errors[] = "Debug: File content length for $errorCode: " . strlen($content);
        $this->errors[] = "Debug: First 200 chars of content: " . mb_substr($content, 0, 200);

        if (empty($content)) {
            $this->errors[] = "Debug: Content is empty for $errorCode";
            return null;
        }

        // Get file modification time as timestamp
        $timestamp = date('Y-m-d H:i:s', filemtime($filePath));

        // For ILIAS error files, we'll use a simpler approach:
        // Just use the entire content as both message and stacktrace
        $lines = explode("\n", trim($content));

        // Use first non-empty line as message
        $message = '';
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line)) {
                $message = $line;
                break;
            }
        }

        // If no message found, use error code
        if (empty($message)) {
            $message = "Error $errorCode";
        }

        // Always store the full content as stacktrace
        $fullContent = trim($content);

        // Extract file path and line number from stacktrace
        $file = null;
        $lineNumber = null;

        // Look for patterns like "#12 Error in /path/to/file.php:396" or "/path/to/file.php:line"
        if (preg_match('/(?:#\d+\s+.*?in\s+|Error in\s+)([^\s]+\.php):(\d+)/i', $fullContent, $matches)) {
            $file = $matches[1];
            $lineNumber = (int) $matches[2];
        } elseif (preg_match('/([^\s]+\.php):(\d+)/', $fullContent, $matches)) {
            // Alternative pattern for direct file:line references
            $file = $matches[1];
            $lineNumber = (int) $matches[2];
        }

        $result = [
            'timestamp' => $timestamp,
            'severity' => 'error', // ILIAS error files are always errors
            'message' => $message,
            'file' => $file, // Now extracted from content
            'line' => $lineNumber,
            'code' => $errorCode, // Store the error code
            'context' => 'ilias_error',
            'stacktrace' => $fullContent, // Store the entire file content here
            'request_data' => null // Add missing request_data field
        ];

        $this->errors[] = "Debug: Parsed result - message length: " . strlen($result['message']) . ", stacktrace length: " . strlen($result['stacktrace']) . ", file: " . ($file ?: 'NOT_FOUND') . ", line: " . ($lineNumber ?: 'NOT_FOUND');

        // Check retention period - skip entries that are too old
        $retentionDays = $this->settings->getRetentionDays();
        if ($retentionDays > 0) {
            $cutoffTimestamp = time() - ($retentionDays * 24 * 60 * 60);
            $entryTimestamp = strtotime($result['timestamp']);

            if ($entryTimestamp !== false && $entryTimestamp < $cutoffTimestamp) {
                // Entry is older than retention period, skip it
                $this->errors[] = "Debug: Skipping entry older than retention period ($retentionDays days)";
                return null;
            }
        }

        return $result;
    }

    /**
     * Parse a single log line (legacy method, still used for other formats)
     */
    private function parseLogLine(string $line): ?array
    {
        if (empty($line)) {
            return null;
        }

        // Try to parse different log formats
        $entry = $this->parsePhpErrorLog($line)
               ?? $this->parseIliasErrorLog($line)
               ?? $this->parseGenericErrorLog($line);

        if (!$entry) {
            return null;
        }

        // Check retention period - skip entries that are too old
        $retentionDays = $this->settings->getRetentionDays();
        if ($retentionDays > 0 && isset($entry['timestamp'])) {
            $cutoffTimestamp = time() - ($retentionDays * 24 * 60 * 60);
            $entryTimestamp = strtotime($entry['timestamp']);

            if ($entryTimestamp !== false && $entryTimestamp < $cutoffTimestamp) {
                // Entry is older than retention period, skip it
                return null;
            }
        }

        // Apply sanitization
        if (isset($entry['request_data'])) {
            $entry['request_data'] = json_encode($this->sanitizer->sanitizeRequestData(
                json_decode($entry['request_data'], true) ?: []
            ));
        }

        if (isset($entry['stacktrace'])) {
            $entry['stacktrace'] = $this->sanitizer->sanitizeStacktrace($entry['stacktrace']);
        }

        if (isset($entry['ip_address'])) {
            $entry['ip_address'] = $this->sanitizer->sanitizeIpAddress($entry['ip_address']);
        }

        if (isset($entry['session_id'])) {
            $entry['session_id'] = $this->sanitizer->sanitizeSessionId($entry['session_id']);
        }

        if (isset($entry['user_agent'])) {
            $entry['user_agent_hash'] = $this->sanitizer->sanitizeUserAgent($entry['user_agent']);
            unset($entry['user_agent']);
        }

        return $entry;
    }

    /**
     * Parse PHP error log format
     */
    private function parsePhpErrorLog(string $line): ?array
    {
        // PHP error log format: [DD-MMM-YYYY HH:MM:SS UTC] PHP Fatal error: message in file on line N
        $pattern = '/^\[([^\]]+)\]\s+PHP\s+(\w+)\s+(error|warning|notice):\s*(.+?)(?:\s+in\s+(.+?)\s+on\s+line\s+(\d+))?$/i';

        if (preg_match($pattern, $line, $matches)) {
            $timestamp = $this->parseTimestamp($matches[1]);
            $severity = strtolower($matches[2]) . '_' . strtolower($matches[3]);
            $message = $matches[4];
            $file = $matches[5] ?? null;
            $lineNumber = isset($matches[6]) ? (int) $matches[6] : null;

            return [
                'timestamp' => $timestamp,
                'severity' => $severity,
                'message' => $message,
                'file' => $file,
                'line' => $lineNumber,
                'context' => 'php_error'
            ];
        }

        return null;
    }

    /**
     * Parse ILIAS error log format (if specific format exists)
     */
    private function parseIliasErrorLog(string $line): ?array
    {
        // This would need to be adapted based on ILIAS specific error log format
        return null;
    }

    /**
     * Parse generic error log format
     */
    private function parseGenericErrorLog(string $line): ?array
    {
        // ILIAS format: [timestamp] message
        $pattern = '/^\[([^\]]+)\]\s+(.+)$/';

        if (preg_match($pattern, $line, $matches)) {
            $timestamp = $this->parseTimestamp($matches[1]);
            $message = $matches[2];

            // Determine severity based on message content
            $severity = $this->determineSeverityFromMessage($message);

            return [
                'timestamp' => $timestamp,
                'severity' => $severity,
                'message' => $message,
                'context' => 'ilias'
            ];
        }

        // Also try format with severity: [timestamp] severity: message
        $pattern2 = '/^\[([^\]]+)\]\s*(\w+):\s*(.+)$/';

        if (preg_match($pattern2, $line, $matches)) {
            $timestamp = $this->parseTimestamp($matches[1]);
            $severity = strtolower($matches[2]);
            $message = $matches[3];

            return [
                'timestamp' => $timestamp,
                'severity' => $severity,
                'message' => $message,
                'context' => 'generic'
            ];
        }

        return null;
    }

    /**
     * Determine severity from message content
     */
    private function determineSeverityFromMessage(string $message): string
    {
        $message = strtolower($message);

        if (strpos($message, 'fatal') !== false ||
            strpos($message, 'critical') !== false) {
            return 'fatal_error';
        }

        if (strpos($message, 'error') !== false ||
            strpos($message, 'exception') !== false ||
            strpos($message, 'failed') !== false ||
            strpos($message, 'fail') !== false) {
            return 'error';
        }

        if (strpos($message, 'warning') !== false ||
            strpos($message, 'warn') !== false ||
            strpos($message, 'deprecated') !== false) {
            return 'warning';
        }

        if (strpos($message, 'notice') !== false ||
            strpos($message, 'info') !== false) {
            return 'notice';
        }

        // Default to error for unclassified messages
        return 'error';
    }

    /**
     * Parse timestamp from various formats
     */
    private function parseTimestamp(string $timestampStr): string
    {
        $formats = [
            'Y-m-d H:i:s',
            'd-M-Y H:i:s T',     // 02-May-2024 07:51:00 UTC
            'j-M-Y H:i:s T',     // 2-May-2024 07:51:00 UTC
            'M d H:i:s',
            'Y/m/d H:i:s'
        ];

        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $timestampStr);
            if ($date) {
                return $date->format('Y-m-d H:i:s');
            }
        }

        // Try to handle common variations
        if (preg_match('/(\d{1,2})-([A-Za-z]{3})-(\d{4})\s+(\d{2}):(\d{2}):(\d{2})\s+([A-Z]+)/', $timestampStr, $matches)) {
            $day = sprintf('%02d', (int)$matches[1]);
            $month = date('m', strtotime($matches[2] . ' 1'));
            $year = $matches[3];
            $hour = $matches[4];
            $minute = $matches[5];
            $second = $matches[6];

            return "$year-$month-$day $hour:$minute:$second";
        }

        // Fallback to current time if parsing fails
        return date('Y-m-d H:i:s');
    }

    /**
     * Get file information for tracking
     */
    private function getFileInfo(string $filePath): array
    {
        $stat = stat($filePath);
        return [
            'inode' => (string) $stat['ino'],
            'size' => $stat['size'],
            'mtime' => $stat['mtime']
        ];
    }

    /**
     * Clean up old entries based on retention settings
     */
    private function cleanupOldEntries(): void
    {
        $retentionDays = $this->settings->getRetentionDays();
        $maxRecords = $this->settings->getMaxRecords();

        if ($retentionDays > 0 || $maxRecords > 0) {
            try {
                $deletedCount = $this->repo->deleteOldEntries($retentionDays, $maxRecords);
                if ($deletedCount > 0) {
                    $this->logger->info("Cleaned up $deletedCount old log entries");
                }
            } catch (Exception $e) {
                $this->errors[] = 'Cleanup failed: ' . $e->getMessage();
                $this->logger->error('Cleanup error: ' . $e->getMessage());
            }
        }
    }

    /**
     * Get import results
     */
    private function getImportResult(): array
    {
        // Check if limits were reached
        $lineLimitReached = in_array('Import stopped: line limit reached', $this->errors);
        $timeLimitReached = in_array('Import stopped: time limit reached', $this->errors);

        return [
            'success' => $this->errorCount === 0,
            'imported' => $this->importedCount,
            'skipped' => $this->skippedCount,
            'errors' => $this->errorCount,
            'error_messages' => $this->errors,
            'total_processed' => $this->importedCount + $this->skippedCount,
            'line_limit_reached' => $lineLimitReached,
            'time_limit_reached' => $timeLimitReached
        ];
    }

    /**
     * Get current statistics
     */
    public function getStatistics(): array
    {
        return $this->repo->getStatistics();
    }

    /**
     * Log message for debugging (only in development mode)
     */
    private function log(string $message): void
    {
        if ($this->debugMode) {
            $this->logger->debug('IssueAnalysis Import: ' . $message);
        }
    }
}
