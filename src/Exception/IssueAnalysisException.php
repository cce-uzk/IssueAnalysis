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

use Exception;

/**
 * Base exception class for IssueAnalysis plugin
 *
 * All plugin-specific exceptions should extend this class.
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class IssueAnalysisException extends Exception
{
    // Base exception class - can be extended for specific error types
}
