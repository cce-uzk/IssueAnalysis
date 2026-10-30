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

        $tokens = self::withoutExpiredTokens(self::getTokens());
        $tokens[$action] = [
            'token' => $token,
            'timestamp' => time()
        ];
        self::setTokens($tokens);

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
        $tokens = self::getTokens();
        if (!isset($tokens[$action])) {
            return false;
        }

        $stored = $tokens[$action];

        // Check if token matches and hasn't expired
        if (!hash_equals((string) $stored['token'], $token)) {
            return false;
        }

        // Token is valid or expired - consume it either way (one-time use)
        unset($tokens[$action]);
        self::setTokens($tokens);

        return time() - (int) $stored['timestamp'] <= self::TOKEN_LIFETIME;
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
        $last = \ilSession::get(self::SESSION_KEY . '_last_' . $action);
        if ($last === null) {
            return false;
        }

        return (time() - (int) $last) < $cooldownSeconds;
    }

    /**
     * Mark action as executed (for cooldown tracking)
     *
     * @param string $action Action identifier
     */
    public static function markExecuted(string $action): void
    {
        \ilSession::set(self::SESSION_KEY . '_last_' . $action, time());
    }

    /**
     * @return array<string, array{token: string, timestamp: int}>
     */
    private static function getTokens(): array
    {
        $tokens = \ilSession::get(self::SESSION_KEY);
        return is_array($tokens) ? $tokens : [];
    }

    /**
     * @param array<string, array{token: string, timestamp: int}> $tokens
     */
    private static function setTokens(array $tokens): void
    {
        \ilSession::set(self::SESSION_KEY, $tokens);
    }

    /**
     * @param array<string, array{token: string, timestamp: int}> $tokens
     * @return array<string, array{token: string, timestamp: int}>
     */
    private static function withoutExpiredTokens(array $tokens): array
    {
        $now = time();
        return array_filter(
            $tokens,
            static fn(array $data): bool => $now - (int) $data['timestamp'] <= self::TOKEN_LIFETIME
        );
    }
}
