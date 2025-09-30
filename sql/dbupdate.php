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