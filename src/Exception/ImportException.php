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

namespace ILIAS\Plugin\xial\Exception;

/**
 * Exception for import-related errors
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class ImportException extends IssueAnalysisException
{
    /**
     * Create exception for missing error log directory
     */
    public static function missingErrorLogDirectory(): self
    {
        return new self('Error log directory is not configured or does not exist');
    }

    /**
     * Create exception for unreadable error log directory
     */
    public static function unreadableErrorLogDirectory(string $path): self
    {
        return new self(sprintf('Error log directory is not readable: %s', $path));
    }

    /**
     * Create exception for file read error
     */
    public static function fileReadError(string $filePath, string $reason = ''): self
    {
        $message = sprintf('Failed to read error file: %s', $filePath);
        if ($reason) {
            $message .= sprintf(' (%s)', $reason);
        }
        return new self($message);
    }
}
