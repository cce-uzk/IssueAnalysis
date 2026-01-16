<?php declare(strict_types=1);

namespace ILIAS\Plugin\xial\Service;

/**
 * Double submission guard service
 *
 * Prevents actions from being executed multiple times through double-clicks or
 * browser back/forward navigation.
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class DoubleSubmissionGuard
{
    private const SESSION_KEY = 'xial_submission_tokens';
    private const TOKEN_LIFETIME = 300; // 5 minutes

    /**
     * Generate a new submission token for an action
     *
     * @param string $action Action identifier (e.g., 'import', 'clearData')
     * @return string Token to be embedded in the request
     */
    public static function generateToken(string $action): string
    {
        $token = bin2hex(random_bytes(16));

        // Store token with timestamp in session
        if (!isset($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }

        // Clean old tokens
        self::cleanExpiredTokens();

        $_SESSION[self::SESSION_KEY][$action] = [
            'token' => $token,
            'timestamp' => time()
        ];

        return $token;
    }

    /**
     * Validate and consume a submission token
     *
     * @param string $action Action identifier
     * @param string $token  Token from request
     * @return bool True if token is valid and consumed, false otherwise
     */
    public static function validateAndConsumeToken(string $action, string $token): bool
    {
        if (!isset($_SESSION[self::SESSION_KEY][$action])) {
            return false;
        }

        $stored = $_SESSION[self::SESSION_KEY][$action];

        // Check if token matches and hasn't expired
        if ($stored['token'] !== $token) {
            return false;
        }

        if (time() - $stored['timestamp'] > self::TOKEN_LIFETIME) {
            unset($_SESSION[self::SESSION_KEY][$action]);
            return false;
        }

        // Token is valid - consume it (one-time use)
        unset($_SESSION[self::SESSION_KEY][$action]);
        return true;
    }

    /**
     * Clean expired tokens from session
     */
    private static function cleanExpiredTokens(): void
    {
        if (!isset($_SESSION[self::SESSION_KEY])) {
            return;
        }

        $now = time();
        foreach ($_SESSION[self::SESSION_KEY] as $action => $data) {
            if ($now - $data['timestamp'] > self::TOKEN_LIFETIME) {
                unset($_SESSION[self::SESSION_KEY][$action]);
            }
        }
    }

    /**
     * Check if an action is currently being processed (within cooldown)
     *
     * @param string $action     Action identifier
     * @param int    $cooldownSeconds Minimum seconds between executions
     * @return bool True if action is in cooldown period
     */
    public static function isInCooldown(string $action, int $cooldownSeconds = 2): bool
    {
        $key = self::SESSION_KEY . '_last_' . $action;

        if (!isset($_SESSION[$key])) {
            return false;
        }

        return (time() - $_SESSION[$key]) < $cooldownSeconds;
    }

    /**
     * Mark action as executed (for cooldown tracking)
     *
     * @param string $action Action identifier
     */
    public static function markExecuted(string $action): void
    {
        $_SESSION[self::SESSION_KEY . '_last_' . $action] = time();
    }
}
