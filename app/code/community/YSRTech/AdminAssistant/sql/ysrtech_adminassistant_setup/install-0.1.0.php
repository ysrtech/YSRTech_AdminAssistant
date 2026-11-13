<?php
/**
 * Conversations, their messages, and the audit log.
 *
 * The audit log is its own table, not the messages: a conversation can be
 * deleted by the person who had it, and the record of what the assistant
 * read on their behalf must not go with it.
 */
/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();
$connection = $installer->getConnection();

$conversations = $installer->getTable('ysrtech_adminassistant/conversation');
$messages      = $installer->getTable('ysrtech_adminassistant/message');
$audit         = $installer->getTable('ysrtech_adminassistant/audit');

if (!$connection->isTableExists($conversations)) {
    $table = $connection->newTable($conversations)
        ->addColumn('conversation_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true])
        ->addColumn('admin_user_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => false])
        ->addColumn('title', Varien_Db_Ddl_Table::TYPE_VARCHAR, 255, ['nullable' => false, 'default' => ''])
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, ['nullable' => false])
        ->addColumn('updated_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, ['nullable' => false])
        ->addIndex($installer->getIdxName($conversations, ['admin_user_id', 'updated_at']), ['admin_user_id', 'updated_at'])
        ->setComment('AI Assistant conversations');
    $connection->createTable($table);
}

if (!$connection->isTableExists($messages)) {
    $table = $connection->newTable($messages)
        ->addColumn('message_id', Varien_Db_Ddl_Table::TYPE_BIGINT, null, ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true])
        ->addColumn('conversation_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => false])
        // user, assistant or tool, as the provider's chat format names them
        ->addColumn('role', Varien_Db_Ddl_Table::TYPE_VARCHAR, 16, ['nullable' => false])
        ->addColumn('content', Varien_Db_Ddl_Table::TYPE_TEXT, '16M', ['nullable' => true])
        // An assistant turn's tool calls, or a tool turn's call id and name, as JSON
        ->addColumn('tool_data', Varien_Db_Ddl_Table::TYPE_TEXT, '16M', ['nullable' => true])
        // The page the question was asked on, as JSON; user turns only
        ->addColumn('page_context', Varien_Db_Ddl_Table::TYPE_TEXT, '64K', ['nullable' => true])
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, ['nullable' => false])
        ->addIndex($installer->getIdxName($messages, ['conversation_id']), ['conversation_id'])
        ->addForeignKey(
            $installer->getFkName($messages, 'conversation_id', $conversations, 'conversation_id'),
            'conversation_id', $conversations, 'conversation_id',
            Varien_Db_Ddl_Table::ACTION_CASCADE, Varien_Db_Ddl_Table::ACTION_CASCADE
        )
        ->setComment('AI Assistant messages');
    $connection->createTable($table);
}

if (!$connection->isTableExists($audit)) {
    $table = $connection->newTable($audit)
        ->addColumn('audit_id', Varien_Db_Ddl_Table::TYPE_BIGINT, null, ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true])
        // question: what was asked; tool: one look-up; model: one request to the provider
        ->addColumn('kind', Varien_Db_Ddl_Table::TYPE_VARCHAR, 16, ['nullable' => false])
        ->addColumn('admin_user_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => true])
        // Kept as written at the time: the user can be renamed or deleted later
        ->addColumn('username', Varien_Db_Ddl_Table::TYPE_VARCHAR, 64, ['nullable' => false, 'default' => ''])
        ->addColumn('conversation_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => true])
        ->addColumn('question_id', Varien_Db_Ddl_Table::TYPE_BIGINT, null, ['unsigned' => true, 'nullable' => true])
        ->addColumn('question', Varien_Db_Ddl_Table::TYPE_TEXT, '64K', ['nullable' => true])
        ->addColumn('tool', Varien_Db_Ddl_Table::TYPE_VARCHAR, 64, ['nullable' => true])
        ->addColumn('input', Varien_Db_Ddl_Table::TYPE_TEXT, '64K', ['nullable' => true])
        ->addColumn('rows_returned', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => true])
        ->addColumn('ok', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, ['unsigned' => true, 'nullable' => false, 'default' => 1])
        ->addColumn('error', Varien_Db_Ddl_Table::TYPE_VARCHAR, 255, ['nullable' => true])
        ->addColumn('model', Varien_Db_Ddl_Table::TYPE_VARCHAR, 120, ['nullable' => true])
        ->addColumn('prompt_tokens', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => true])
        ->addColumn('completion_tokens', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => true])
        ->addColumn('cost_usd', Varien_Db_Ddl_Table::TYPE_DECIMAL, [12, 6], ['nullable' => true])
        ->addColumn('duration_ms', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => true])
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, ['nullable' => false])
        ->addIndex($installer->getIdxName($audit, ['created_at']), ['created_at'])
        ->addIndex($installer->getIdxName($audit, ['kind', 'created_at']), ['kind', 'created_at'])
        ->addIndex($installer->getIdxName($audit, ['question_id']), ['question_id'])
        ->setComment('AI Assistant audit log');
    $connection->createTable($table);
}

$installer->endSetup();
