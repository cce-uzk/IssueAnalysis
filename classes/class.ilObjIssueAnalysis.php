<?php declare(strict_types=1);

// Load plugin bootstrap (includes Composer autoloader)
require_once __DIR__ . '/bootstrap.php';

/**
 * Application class for IssueAnalysis plugin
 * Creates an administration object that appears in the admin menu
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class ilObjIssueAnalysis extends ilObject
{
    public function __construct(int $id = 0, bool $call_by_reference = true)
    {
        $this->type = 'xial';
        parent::__construct($id, $call_by_reference);
    }

    /**
     * Create object
     */
    public function create(): int
    {
        $id = parent::create();
        return $id;
    }

    /**
     * Delete object
     */
    public function delete(): bool
    {
        return parent::delete();
    }

    /**
     * Get object type
     */
    public function getType(): string
    {
        return 'xial';
    }

    /**
     * Clone object
     */
    public function doClone(int $new_obj, int $a_target_id, ?int $a_copy_id = null): void
    {
        // Not applicable for this object type
    }

    /**
     * Update object
     */
    public function update(): bool
    {
        return parent::update();
    }
}
