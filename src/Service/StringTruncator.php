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
 * String truncation service with multibyte support
 *
 * Provides safe string truncation for database fields to prevent SQL errors.
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class StringTruncator
{
    /**
     * Truncate string to maximum length with optional suffix
     *
     * @param string|null $value    The string to truncate
     * @param int         $maxLength Maximum allowed length
     * @param string      $suffix   Suffix to append if truncated (default: '...')
     * @return string|null Truncated string or null if input was null
     */
    public static function truncate(?string $value, int $maxLength, string $suffix = '...'): ?string
    {
        if ($value === null) {
            return null;
        }

        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        $suffixLength = mb_strlen($suffix);
        $truncatedLength = $maxLength - $suffixLength;

        if ($truncatedLength <= 0) {
            return mb_substr($value, 0, $maxLength);
        }

        return mb_substr($value, 0, $truncatedLength) . $suffix;
    }

    /**
     * Truncate file path keeping the end (most important part)
     *
     * @param string|null $path      File path to truncate
     * @param int         $maxLength Maximum length
     * @return string|null Truncated path with '...' prefix
     */
    public static function truncateFilePath(?string $path, int $maxLength): ?string
    {
        if ($path === null) {
            return null;
        }

        if (mb_strlen($path) <= $maxLength) {
            return $path;
        }

        // Keep the end of the path (filename is more important than full path)
        $prefixLength = 3; // '...'
        $keepLength = $maxLength - $prefixLength;

        return '...' . mb_substr($path, -$keepLength);
    }

    /**
     * Truncate multiple fields at once
     *
     * @param array<string, mixed> $data   Field => value
     * @param array<string, int>   $limits Field => maximum length
     * @return array<string, mixed> Truncated data
     */
    public static function truncateFields(array $data, array $limits): array
    {
        $result = $data;

        foreach ($limits as $field => $maxLength) {
            if (isset($result[$field]) && is_string($result[$field])) {
                $result[$field] = self::truncate($result[$field], $maxLength);
            }
        }

        return $result;
    }
}
