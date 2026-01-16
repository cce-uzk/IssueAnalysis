<?php declare(strict_types=1);

// Load plugin bootstrap (includes Composer autoloader)
require_once __DIR__ . '/bootstrap.php';

/**
 * Data sanitizer for secure sharing of error logs
 *
 * Automatically detects and replaces sensitive information like server paths,
 * IP addresses, session IDs, and other system-specific data with placeholders
 * to enable safe sharing of error logs in public forums or issue trackers.
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class ilIssueAnalysisSanitizer
{
    private const SENSITIVE_KEYS = [
        'password', 'passwd', 'pass', 'pwd',
        'token', 'auth', 'authorization', 'bearer',
        'cookie', 'session', 'sessionid',
        'key', 'secret', 'private',
        'csrf', 'xsrf',
        'email', 'mail',
        'credit', 'card', 'cvv', 'ccv',
        'ssn', 'social', 'security'
    ];

    private const MASK_CHAR = '*';
    private const MIN_VISIBLE_CHARS = 3;

    private bool $maskSensitive;

    public function __construct(bool $maskSensitive = true)
    {
        $this->maskSensitive = $maskSensitive;
    }

    /**
     * Sanitize request data array
     */
    public function sanitizeRequestData(array $data): array
    {
        if (!$this->maskSensitive) {
            return $data;
        }

        return $this->sanitizeArray($data);
    }

    /**
     * Sanitize user agent string
     */
    public function sanitizeUserAgent(string $userAgent): string
    {
        if (!$this->maskSensitive) {
            return hash('sha256', $userAgent);
        }

        // Always hash user agent for privacy
        return hash('sha256', $userAgent);
    }

    /**
     * Sanitize IP address
     */
    public function sanitizeIpAddress(string $ipAddress): string
    {
        if (!$this->maskSensitive) {
            return $ipAddress;
        }

        // IPv4: mask last octet
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ipAddress);
            if (count($parts) === 4) {
                $parts[3] = '***';
                return implode('.', $parts);
            }
        }

        // IPv6: mask last 64 bits
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ipAddress);
            if (count($parts) >= 4) {
                for ($i = 4; $i < count($parts); $i++) {
                    $parts[$i] = '***';
                }
                return implode(':', $parts);
            }
        }

        // Fallback: mask half of the string
        return $this->maskString($ipAddress);
    }

    /**
     * Sanitize stacktrace
     */
    public function sanitizeStacktrace(string $stacktrace): string
    {
        if (!$this->maskSensitive) {
            return $stacktrace;
        }

        // Use comprehensive sanitization for stacktraces too
        return $this->performComprehensiveSanitization($stacktrace);
    }

    /**
     * Sanitize array recursively
     */
    private function sanitizeArray(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $sanitizedKey = $this->sanitizeKey($key);

            if (is_array($value)) {
                $sanitized[$sanitizedKey] = $this->sanitizeArray($value);
            } elseif (is_string($value)) {
                if ($this->isSensitiveKey($key)) {
                    // For sensitive keys, mask the value
                    $sanitized[$sanitizedKey] = $this->maskString($value);
                } else {
                    // For non-sensitive keys, still apply comprehensive sanitization to remove paths/IPs
                    $sanitized[$sanitizedKey] = $this->performComprehensiveSanitization($value);
                }
            } else {
                $sanitized[$sanitizedKey] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Check if key is considered sensitive
     */
    private function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (strpos($key, $sensitive) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mask sensitive string values
     */
    private function maskString(string $value): string
    {
        $length = strlen($value);

        if ($length <= self::MIN_VISIBLE_CHARS) {
            return str_repeat(self::MASK_CHAR, $length);
        }

        $visibleChars = min(self::MIN_VISIBLE_CHARS, (int) ($length * 0.3));
        $maskedLength = $length - $visibleChars;

        return substr($value, 0, $visibleChars) . str_repeat(self::MASK_CHAR, $maskedLength);
    }

    /**
     * Sanitize key names
     */
    private function sanitizeKey(string $key): string
    {
        // Keep keys as-is for debugging purposes, only sanitize values
        return $key;
    }

    /**
     * Sanitize file paths in stacktraces
     */
    private function sanitizeFilePath(string $filePath): string
    {
        // Keep only the filename and immediate parent directory
        $pathParts = explode('/', $filePath);

        if (count($pathParts) > 2) {
            $fileName = array_pop($pathParts);
            $parentDir = array_pop($pathParts);
            return '.../' . $parentDir . '/' . $fileName;
        }

        return $filePath;
    }

    /**
     * Sanitize stacktrace arguments
     */
    private function sanitizeStacktraceArgs(string $args): string
    {
        // Remove content within parentheses that might contain sensitive data
        return preg_replace_callback('/\(([^)]*)\)/', function($matches) {
            $content = $matches[1];

            // If arguments contain sensitive patterns, mask them
            if (preg_match('/password|token|secret|key/i', $content)) {
                return '(***)';
            }

            // Limit argument length to prevent exposure of large data
            if (strlen($content) > 100) {
                return '(' . substr($content, 0, 50) . '...[truncated]...)';
            }

            return $matches[0];
        }, $args);
    }

    /**
     * Sanitize session ID
     */
    public function sanitizeSessionId(?string $sessionId): ?string
    {
        if ($sessionId === null || !$this->maskSensitive) {
            return $sessionId;
        }

        // Show only first and last 4 characters
        if (strlen($sessionId) > 8) {
            return substr($sessionId, 0, 4) . str_repeat(self::MASK_CHAR, strlen($sessionId) - 8) . substr($sessionId, -4);
        }

        return str_repeat(self::MASK_CHAR, strlen($sessionId));
    }

    /**
     * Sanitize generic text content
     */
    public function sanitizeTextContent(string $content): string
    {
        if (!$this->maskSensitive) {
            return $content;
        }

        // Use comprehensive sanitization when mask_sensitive is enabled
        return $this->performComprehensiveSanitization($content);
    }

    /**
     * Perform comprehensive sanitization (shared by import and sharing functions)
     */
    private function performComprehensiveSanitization(string $content): string
    {
        // Get dynamic values from ILIAS Core
        global $DIC;

        // Get current IP address
        $currentIp = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';

        // Get ILIAS paths
        $iliasRoot = defined('ILIAS_ABSOLUTE_PATH') ? ILIAS_ABSOLUTE_PATH : getcwd();
        $serverRoot = dirname($iliasRoot);

        // Get client ID
        $clientId = defined('CLIENT_ID') ? CLIENT_ID : ($_COOKIE['ilClientId'] ?? 'unknown');

        // Get session prefix (from PHPSESSID cookie)
        $sessionPrefix = 'unknown';
        if (isset($_COOKIE['PHPSESSID']) && strlen($_COOKIE['PHPSESSID']) >= 5) {
            $sessionPrefix = substr($_COOKIE['PHPSESSID'], 0, 5);
        }

        // Dynamic replacements using actual system values
        if ($currentIp !== 'localhost') {
            $content = str_replace($currentIp, '[IP_ADDRESS]', $content);
        }

        if ($iliasRoot) {
            $content = str_replace($iliasRoot, '[ILIAS_ROOT]', $content);
        }

        if ($serverRoot && $serverRoot !== $iliasRoot) {
            $content = str_replace($serverRoot, '[SERVER_ROOT]', $content);
        }

        // Replace web document root
        if (isset($_SERVER['DOCUMENT_ROOT']) && !empty($_SERVER['DOCUMENT_ROOT'])) {
            $content = str_replace($_SERVER['DOCUMENT_ROOT'], '[DOC_ROOT]', $content);
        }

        // Replace common server paths
        $content = str_replace('/var/www/html', '[DOC_ROOT]', $content);
        $content = str_replace('/var/www', '[WEB_ROOT]', $content);

        // Replace client ID
        if ($clientId !== 'unknown') {
            $content = str_replace($clientId, '[CLIENT_ID]', $content);
        }

        // Replace session prefix patterns
        $content = preg_replace('/\b' . preg_quote($sessionPrefix, '/') . '[a-zA-Z0-9_-]*/', '[SESSION_ID]', $content);

        // Static IP patterns
        $content = preg_replace('/\b(?:10|127|172|192)\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', '[IP_ADDRESS]', $content);

        // File system paths
        $content = preg_replace('/\/[a-zA-Z0-9\/._-]*\/ilias[a-zA-Z0-9\/._-]*/', '[ILIAS_PATH]', $content);
        $content = preg_replace('/[A-Z]:\\\\[a-zA-Z0-9\\\\._-]*/', '[WIN_PATH]', $content);

        // Email addresses
        $content = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[EMAIL]', $content);

        // URLs
        $content = preg_replace('/https?:\/\/[^\s\'"<>]+/', '[URL]', $content);

        // Database connection strings
        $content = preg_replace('/mysql:host=[^;]+;/', 'mysql:host=[DB_HOST];', $content);
        $content = preg_replace('/host=[^;]+;/', 'host=[DB_HOST];', $content);

        // Common server info
        $content = preg_replace('/Server at [^\s]+ Port \d+/', 'Server at [HOSTNAME] Port [PORT]', $content);

        return $content;
    }

    /**
     * Sanitize error content for safe public sharing
     *
     * This method always performs sanitization regardless of plugin settings,
     * as it's specifically designed for sharing logs in public contexts.
     *
     * @param string $content Raw error log content
     * @return string Sanitized content with sensitive data replaced by placeholders
     */
    public function sanitizeForSharing(string $content): string
    {
        // Always sanitize when sharing - ignore general maskSensitive setting
        return $this->performComprehensiveSanitization($content);
    }
}
