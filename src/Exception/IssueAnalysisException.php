<?php declare(strict_types=1);

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
