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

    /** @var array{host: string, ilias_root: string, document_root: string, client_id: string, session_prefix: string}|null */
    private ?array $environment;

    /**
     * @param array{host: string, ilias_root: string, document_root: string, client_id: string, session_prefix: string}|null $environment
     *        System values replaced by placeholders; read from the current request if null
     */
    public function __construct(bool $maskSensitive = true, ?array $environment = null)
    {
        $this->maskSensitive = $maskSensitive;
        $this->environment = $environment;
    }

    /**
     * System values of the current installation and request
     *
     * @return array{host: string, ilias_root: string, document_root: string, client_id: string, session_prefix: string}
     */
    private function getEnvironment(): array
    {
        if ($this->environment !== null) {
            return $this->environment;
        }

        global $DIC;
        $server = [];
        $cookies = [];
        if (isset($DIC) && $DIC->offsetExists('http')) {
            $request = $DIC->http()->request();
            $server = $request->getServerParams();
            $cookies = $request->getCookieParams();
        }

        $session_id = (string) ($cookies[session_name()] ?? '');

        $this->environment = [
            'host' => (string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? ''),
            'ilias_root' => defined('ILIAS_ABSOLUTE_PATH') ? (string) ILIAS_ABSOLUTE_PATH : '',
            'document_root' => (string) ($server['DOCUMENT_ROOT'] ?? ''),
            'client_id' => defined('CLIENT_ID') ? (string) CLIENT_ID : (string) ($cookies['ilClientId'] ?? ''),
            'session_prefix' => strlen($session_id) >= 5 ? substr($session_id, 0, 5) : ''
        ];

        return $this->environment;
    }

    /**
     * Sanitize request data array
     *
     * @param array<mixed> $data
     * @return array<mixed>
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
     *
     * @param array<mixed> $data
     * @return array<mixed>
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
        $environment = $this->getEnvironment();
        $host = $environment['host'];
        $iliasRoot = $environment['ilias_root'];
        $serverRoot = $iliasRoot !== '' ? dirname($iliasRoot) : '';
        $documentRoot = $environment['document_root'];
        $clientId = $environment['client_id'];
        $sessionPrefix = $environment['session_prefix'];

        // Values identified by their key in the error file (request data, cookies, session)
        $content = $this->maskSensitiveValues($content);
        $content = $this->maskSessionIds($content);
        $content = $this->maskClientIds($content, $clientId);
        $content = $this->maskHostNames($content, $host);

        if ($iliasRoot !== '') {
            $content = str_replace($iliasRoot, '[ILIAS_ROOT]', $content);
        }

        if ($serverRoot !== '' && $serverRoot !== '/' && $serverRoot !== $iliasRoot) {
            $content = str_replace($serverRoot, '[SERVER_ROOT]', $content);
        }

        // Replace web document root
        if ($documentRoot !== '') {
            $content = str_replace($documentRoot, '[DOC_ROOT]', $content);
        }

        // Replace common server paths
        $content = str_replace('/var/www/html', '[DOC_ROOT]', $content);
        $content = str_replace('/var/www', '[WEB_ROOT]', $content);

        // Replace session IDs starting with the prefix of the current session
        if ($sessionPrefix !== '') {
            $content = preg_replace('/\b' . preg_quote($sessionPrefix, '/') . '[a-zA-Z0-9_-]*/', '[SESSION_ID]', $content);
        }

        $content = $this->maskIpAddresses($content);

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
     * Mask the values of sensitive keys in "KEY : value" and "[key] => value" lines,
     * as written by ILIAS for request data, cookies, session and server variables
     */
    private function maskSensitiveValues(string $content): string
    {
        return preg_replace_callback(
            '/^([ \t]*\[?([A-Za-z0-9_.-]+)\]?[ \t]*(?::|=>)[ \t]*)(\S.*)$/m',
            fn(array $match): string => $this->isSensitiveKey($match[2])
                ? $match[1] . '[MASKED]'
                : $match[0],
            $content
        );
    }

    /**
     * Mask session IDs in cookies, independent of the session of the current user
     */
    private function maskSessionIds(string $content): string
    {
        $names = array_unique(['PHPSESSID', session_name() ?: 'PHPSESSID']);
        foreach ($names as $name) {
            $quoted = preg_quote($name, '/');
            $content = preg_replace(
                '/(\[' . $quoted . '\]\s*=>\s*|\b' . $quoted . '=)[^;\s]+(?: \(SHORTENED FOR SECURITY\))?/',
                '$1[SESSION_ID]',
                $content
            );
        }

        return $content;
    }

    /**
     * Mask the ILIAS client ID in cookies and URLs and as path segment (e.g. data/<client>/)
     *
     * The client ID is not replaced everywhere in the text, as short IDs like "uk"
     * are also part of ordinary words.
     */
    private function maskClientIds(string $content, string $currentClientId): string
    {
        $pattern = '/(\[ilClientId\]\s*=>\s*|\bilClientId=|[?&]client_id=)([A-Za-z0-9_-]+)/';

        $client_ids = $currentClientId !== '' ? [$currentClientId] : [];
        if (preg_match_all($pattern, $content, $matches)) {
            $client_ids = array_merge($client_ids, $matches[2]);
        }

        $content = preg_replace($pattern, '$1[CLIENT_ID]', $content);

        foreach (array_unique($client_ids) as $client_id) {
            $content = preg_replace('#/' . preg_quote($client_id, '#') . '(?=/)#', '/[CLIENT_ID]', $content);
        }

        return $content;
    }

    /**
     * Mask the host names of the installation wherever they occur
     *
     * The names are taken from the server variables and URLs of the error file, so
     * that errors of other installations are masked, too.
     */
    private function maskHostNames(string $content, string $currentHost): string
    {
        $hosts = $currentHost !== '' ? [$currentHost] : [];

        if (preg_match_all(
            '/^[ \t]*(?:HTTP_HOST|SERVER_NAME|HTTP_X_FORWARDED_HOST|HTTP_X_FORWARDED_SERVER|HTTP_ORIGIN)[ \t]*(?::|=>)[ \t]*(\S+)/mi',
            $content,
            $matches
        )) {
            $hosts = array_merge($hosts, $matches[1]);
        }
        if (preg_match_all('/\bhttps?:\/\/([^\/\s:\'"<>?#]+)/i', $content, $matches)) {
            $hosts = array_merge($hosts, $matches[1]);
        }

        $names = [];
        foreach ($hosts as $host) {
            $host = strtolower(preg_replace(['#^[a-z][a-z0-9+.-]*://#i', '/:\d+$/', '#/.*$#'], '', $host));
            // Only real domain names; IP addresses are masked separately
            if (!str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
                continue;
            }
            $names[] = $host;
            if (str_starts_with($host, 'www.')) {
                $names[] = substr($host, 4);
            }
        }

        $names = array_unique($names);
        // Longest names first, so that "www.example.org" is not left as "www.[HOSTNAME]"
        usort($names, static fn(string $a, string $b): int => strlen($b) - strlen($a));

        return $names === [] ? $content : str_ireplace($names, '[HOSTNAME]', $content);
    }

    /**
     * Replace all IPv4 and IPv6 addresses by a placeholder
     *
     * Candidates are validated with filter_var(), so that version numbers after a
     * slash (e.g. "Chrome/143.0.0.0"), times ("07:51:00") and static calls
     * ("ilCtrl::getCmd") are kept.
     */
    private function maskIpAddresses(string $content): string
    {
        $content = preg_replace_callback(
            '/(?<![\w.\/-])(?:\d{1,3}\.){3}\d{1,3}(?![\w.])/',
            static fn(array $match): string => filter_var($match[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                ? '[IP_ADDRESS]'
                : $match[0],
            $content
        );

        return preg_replace_callback(
            '/(?<![\w:.])[0-9A-Fa-f]{0,4}(?::[0-9A-Fa-f]{0,4}){2,7}(?![\w:])/',
            static fn(array $match): string => preg_match('/[0-9A-Fa-f]/', $match[0]) === 1
                && filter_var($match[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                ? '[IP_ADDRESS]'
                : $match[0],
            $content
        );
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
