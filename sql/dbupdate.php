<#1>
<?php
global $DIC;
$db = $DIC->database();

// Create xial_log table
if (!$db->tableExists('xial_log')) {
    $fields = [
        'id' => [
            'type' => 'integer',
            'length' => 8,
            'notnull' => true
        ],
        'timestamp' => [
            'type' => 'timestamp',
            'notnull' => true
        ],
        'severity' => [
            'type' => 'text',
            'length' => 20,
            'notnull' => true
        ],
        'message' => [
            'type' => 'text',
            'length' => 4000,
            'notnull' => true
        ],
        'file' => [
            'type' => 'text',
            'length' => 500,
            'notnull' => false
        ],
        'line' => [
            'type' => 'integer',
            'length' => 4,
            'notnull' => false
        ],
        'code' => [
            'type' => 'text',
            'length' => 50,
            'notnull' => false
        ],
        'user_id' => [
            'type' => 'integer',
            'length' => 4,
            'notnull' => false
        ],
        'session_id' => [
            'type' => 'text',
            'length' => 100,
            'notnull' => false
        ],
        'ip_address' => [
            'type' => 'text',
            'length' => 45,
            'notnull' => false
        ],
        'user_agent_hash' => [
            'type' => 'text',
            'length' => 64,
            'notnull' => false
        ],
        'context' => [
            'type' => 'text',
            'length' => 1000,
            'notnull' => false
        ],
        'analyzed' => [
            'type' => 'integer',
            'length' => 1,
            'notnull' => true,
            'default' => 0
        ],
        'created_at' => [
            'type' => 'timestamp',
            'notnull' => true
        ]
    ];

    $db->createTable('xial_log', $fields);
    $db->addPrimaryKey('xial_log', ['id']);
    $db->createSequence('xial_log');
    $db->addIndex('xial_log', ['timestamp'], 'i1');
    $db->addIndex('xial_log', ['severity'], 'i2');
    $db->addIndex('xial_log', ['code'], 'i3');
}
?>

<#2>
<?php
global $DIC;
$db = $DIC->database();

// Create xial_detail table
if (!$db->tableExists('xial_detail')) {
    $fields = [
        'log_id' => [
            'type' => 'integer',
            'length' => 8,
            'notnull' => true
        ],
        'stacktrace' => [
            'type' => 'clob',
            'notnull' => false
        ],
        'request_data' => [
            'type' => 'clob',
            'notnull' => false
        ]
    ];

    $db->createTable('xial_detail', $fields);
    $db->addPrimaryKey('xial_detail', ['log_id']);
}
?>

<#3>
<?php
global $DIC;
$db = $DIC->database();

// Create xial_source table
if (!$db->tableExists('xial_source')) {
    $fields = [
        'file_path' => [
            'type' => 'text',
            'length' => 500,
            'notnull' => true
        ],
        'file_inode' => [
            'type' => 'text',
            'length' => 50,
            'notnull' => true
        ],
        'last_offset' => [
            'type' => 'integer',
            'length' => 8,
            'notnull' => true,
            'default' => 0
        ],
        'last_check' => [
            'type' => 'timestamp',
            'notnull' => true
        ]
    ];

    $db->createTable('xial_source', $fields);
    $db->addPrimaryKey('xial_source', ['file_path']);
}
?>

<#4>
<?php
global $DIC;
$db = $DIC->database();

// ============================================
// Migration: Hash-based Deduplication & Lazy-Loading
// ============================================

// Add stacktrace_hash column to xial_log
if ($db->tableExists('xial_log') && !$db->tableColumnExists('xial_log', 'stacktrace_hash')) {
    $db->addTableColumn('xial_log', 'stacktrace_hash', [
        'type' => 'text',
        'length' => 64,
        'notnull' => false
    ]);
    $db->addIndex('xial_log', ['stacktrace_hash'], 'i4');
}

// Modify message column to TEXT (fix truncation error)
if ($db->tableExists('xial_log') && $db->tableColumnExists('xial_log', 'message')) {
    try {
        $db->manipulate("ALTER TABLE xial_log MODIFY COLUMN message TEXT");
    } catch (Exception $e) {
        // If fails, log but continue
        if (isset($DIC) && $DIC->offsetExists('logger')) {
            $DIC->logger()->xial()->warning('Could not modify message column: ' . $e->getMessage());
        }
    }
}

// Create xial_error table (unique error)
if (!$db->tableExists('xial_error')) {
    $fields = [
        'stacktrace_hash' => [
            'type' => 'text',
            'length' => 64,
            'notnull' => true
        ],
        'message' => [
            'type' => 'clob',
            'notnull' => false
        ],
        'file' => [
            'type' => 'text',
            'length' => 500,
            'notnull' => false
        ],
        'line' => [
            'type' => 'integer',
            'length' => 4,
            'notnull' => false
        ],
        'severity' => [
            'type' => 'text',
            'length' => 20,
            'notnull' => false
        ],
        'first_seen' => [
            'type' => 'timestamp',
            'notnull' => false
        ],
        'last_seen' => [
            'type' => 'timestamp',
            'notnull' => false
        ],
        'occurrence_count' => [
            'type' => 'integer',
            'length' => 4,
            'notnull' => true,
            'default' => 0
        ],
        'ignored' => [
            'type' => 'integer',
            'length' => 1,
            'notnull' => true,
            'default' => 0
        ],
        'ignored_at' => [
            'type' => 'timestamp',
            'notnull' => false
        ],
        'ignored_by' => [
            'type' => 'integer',
            'length' => 4,
            'notnull' => false
        ],
        'note' => [
            'type' => 'text',
            'length' => 500,
            'notnull' => false
        ]
    ];

    $db->createTable('xial_error', $fields);
    $db->addPrimaryKey('xial_error', ['stacktrace_hash']);
    $db->addIndex('xial_error', ['ignored'], 'i1');
    $db->addIndex('xial_error', ['last_seen'], 'i2');
}

?>

<#5>
<?php
global $DIC;
$db = $DIC->database();

// ============================================
// Migration: Remove deprecated xial_detail table
// Stacktrace is now loaded via lazy-loading from original error files
// or from xial_error.message as fallback
// ============================================

if ($db->tableExists('xial_detail')) {
    $db->dropTable('xial_detail');
}

?>
