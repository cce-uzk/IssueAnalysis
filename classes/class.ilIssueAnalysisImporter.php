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
 * Log importer for IssueAnalysis plugin
 * Handles importing error log entries from ILIAS error log files
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class ilIssueAnalysisImporter
{
    private ilIssueAnalysisRepo $repo;
    private ilIssueAnalysisSettings $settings;
    private ilLogger $logger;

    private int $importedCount = 0;
    private int $skippedCount = 0;
    private int $errorCount = 0;
    /** @var string[] */
    private array $errors = [];
    private bool $lineLimitReached = false;
    private bool $timeLimitReached = false;

    public function __construct()
    {
        global $DIC;


        $this->repo = new ilIssueAnalysisRepo();
        $this->settings = new ilIssueAnalysisSettings();
        $this->logger = $DIC->logger()->xial();
    }

    /**
     * Calculate SHA-256 hash of stacktrace for deduplication
     */
    private function calculateStacktraceHash(string $stacktrace): string
    {
        // Normalize stacktrace: trim whitespace
        $normalized = trim($stacktrace);

        // Calculate SHA-256 hash
        return hash('sha256', $normalized);
    }

    /**
     * Import log entries from error log directory
     *
     * @return array<string, mixed> Counters and limit flags, see getImportResult()
     */
    public function importLogs(bool $isCronImport = false): array
    {
        $this->importedCount = 0;
        $this->skippedCount = 0;
        $this->errorCount = 0;
        $this->errors = [];
        $this->lineLimitReached = false;
        $this->timeLimitReached = false;

        $startTime = time();

        $timeLimit = $this->settings->getImportTimeLimit();
        $lineLimit = $this->settings->getImportLineLimit();

        $errorLogDir = $this->settings->getErrorLogDirectory();
        if (empty($errorLogDir) || !is_dir($errorLogDir)) {
            $this->logger->error('Error log directory not configured or not accessible: ' . $errorLogDir);
            $this->errorCount++;
            return $this->getImportResult();
        }

        $logFiles = $this->getLogFiles($errorLogDir);

        foreach ($logFiles as $logFile) {
            if ((time() - $startTime) > $timeLimit) {
                $this->timeLimitReached = true;
                break;
            }

            if ($this->importedCount >= $lineLimit) {
                $this->lineLimitReached = true;
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
     *
     * @return string[] Absolute paths, newest first
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
        usort($logFiles, function ($a, $b) {
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
                return true; // Continue with next file
            }

            $fileInfo = $this->getFileInfo($filePath);
            $tracking = $this->repo->getSourceTracking($filePath);

            // Extract error code from filename early (for duplicate check)
            $errorCode = basename($filePath);
            $errorCode = preg_replace('/\.log$/i', '', $errorCode);

            // FIRST CHECK: File-Tracking - has this physical file been processed?
            if ($tracking && $tracking['file_inode'] === $fileInfo['inode'] && $tracking['last_offset'] >= $fileInfo['size']) {
                $this->skippedCount++;
                return true; // Continue with next file
            }

            // SECOND CHECK: Error-Code - is this error already in the database?
            // (Prevents duplicates if file-tracking was reset but DB still has the entry)
            if ($this->repo->errorCodeExists($errorCode)) {
                $this->skippedCount++;
                // Still mark file as processed to avoid checking it again next time
                $this->repo->updateSourceTracking($filePath, $fileInfo['inode'], $fileInfo['size']);
                return true; // Continue with next file
            }

            // Read ILIAS error file (chunked for large files)
            // Only read first 100 KB - Stacktrace is at the beginning, rest is just Request-Data
            $maxReadSize = 100 * 1024; // 100 KB should be enough for message + stacktrace

            $content = file_get_contents($filePath, false, null, 0, $maxReadSize);
            if ($content === false) {
                $this->errorCount++;
                $this->logger->warning('Could not read error file ' . $filePath);
                return true; // Continue with next file
            }

            // Parse ILIAS error file content
            $logEntry = $this->parseIliasErrorFile($content, $errorCode, $filePath);

            if ($logEntry) {
                try {
                    // Import the entry (duplicate checks already done above)
                    // 1. Insert or update error type in xial_error
                    $stacktraceHash = $logEntry['stacktrace_hash'];
                    $this->repo->insertOrUpdateErrorType(
                        $stacktraceHash,
                        $logEntry['stacktrace_only'],
                        $logEntry['file'],
                        $logEntry['line'],
                        $logEntry['severity'],
                        $logEntry['timestamp']
                    );

                    // 2. Insert log entry in xial_log (with stacktrace_hash)
                    $this->repo->insertLogEntry($logEntry);

                    $this->importedCount++;

                    // Check if line limit reached after importing
                    if ($this->importedCount >= $lineLimit) {
                        $this->lineLimitReached = true;
                        return false; // Stop processing - limit reached
                    }
                } catch (Exception $e) {
                    $this->errorCount++;
                    $this->logger->error('Failed to import error file: ' . $e->getMessage());
                }
            }

            // ALWAYS mark file as completely processed after parsing attempt
            // Rationale: Each error file should only be read once, regardless of retention/rolling window settings
            // - If retention filter rejected it now, it will always be rejected (timestamp doesn't change)
            // - If rolling window deletes entries later, that's intentional cleanup (not a reason to re-import)
            // - This prevents repeatedly parsing old files that will never pass retention filter
            $this->repo->updateSourceTracking($filePath, $fileInfo['inode'], $fileInfo['size']);

        } catch (Exception $e) {
            $this->errorCount++;
            $this->logger->error('Error processing file ' . $filePath . ': ' . $e->getMessage());
        }

        return true; // Continue processing
    }

    /**
     * Parse ILIAS error file content
     *
     * @return array<string, mixed>|null Entry for xial_log, null if empty or outside the retention period
     */
    private function parseIliasErrorFile(string $content, string $errorCode, string $filePath): ?array
    {
        if (empty($content)) {
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

        // Extract ONLY the stacktrace part (before Request-Data section)
        // This is what we'll use for hashing - more precise deduplication
        $stacktraceOnly = $fullContent;
        $requestDataMarkers = ['-- GET Data --', '-- POST Data --', '-- Files --', '-- Cookies --'];
        foreach ($requestDataMarkers as $marker) {
            $pos = strpos($fullContent, $marker);
            if ($pos !== false) {
                $stacktraceOnly = substr($fullContent, 0, $pos);
                break;
            }
        }
        $stacktraceOnly = trim($stacktraceOnly);

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

        // Calculate stacktrace hash ONLY from stacktrace (not Request-Data)
        // This gives us better deduplication - errors with same stacktrace but different request data = same hash
        $stacktraceHash = $this->calculateStacktraceHash($stacktraceOnly);

        $result = [
            'timestamp' => $timestamp,
            'severity' => 'error', // ILIAS error files are always errors
            'message' => $message, // Short first-line message for xial_log
            'stacktrace_only' => $stacktraceOnly, // Stacktrace without Request-Data for xial_error (searchable)
            'file' => $file, // Now extracted from content
            'line' => $lineNumber,
            'code' => $errorCode, // Store the error code
            'context' => 'ilias_error',
            'stacktrace' => $fullContent, // Full content (not stored in DB, only for lazy-loading)
            'stacktrace_hash' => $stacktraceHash, // Hash for deduplication
            'request_data' => null
        ];

        // Check retention period - skip entries that are too old
        $retentionDays = $this->settings->getRetentionDays();
        if ($retentionDays > 0) {
            $cutoffTimestamp = time() - ($retentionDays * 24 * 60 * 60);
            $entryTimestamp = strtotime($result['timestamp']);

            if ($entryTimestamp !== false && $entryTimestamp < $cutoffTimestamp) {
                // Entry is older than retention period, skip it
                return null;
            }
        }

        return $result;
    }

    /**
     * Get file information for tracking
     *
     * @return array{inode: string, size: int, mtime: int}
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
                $this->logger->error('Cleanup error: ' . $e->getMessage());
            }
        }
    }

    /**
     * Get import results
     *
     * @return array<string, mixed>
     */
    private function getImportResult(): array
    {
        return [
            'success' => $this->errorCount === 0,
            'imported' => $this->importedCount,
            'skipped' => $this->skippedCount,
            'errors' => $this->errorCount,
            'error_messages' => $this->errors,
            'total_processed' => $this->importedCount + $this->skippedCount,
            'line_limit_reached' => $this->lineLimitReached,
            'time_limit_reached' => $this->timeLimitReached
        ];
    }

    /**
     * Get current statistics
     *
     * @return array<string, mixed>
     */
    public function getStatistics(): array
    {
        return $this->repo->getStatistics();
    }
}
