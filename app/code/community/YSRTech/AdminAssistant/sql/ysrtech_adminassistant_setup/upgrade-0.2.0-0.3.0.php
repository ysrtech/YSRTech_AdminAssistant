<?php
/**
 * The admin wiki: pages and every revision of them, then the starter pages
 * the module ships (wiki/<category>/<page>.md).
 */
/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();
$connection = $installer->getConnection();

$pages     = $installer->getTable('ysrtech_adminassistant/wiki_page');
$revisions = $installer->getTable('ysrtech_adminassistant/wiki_revision');

if (!$connection->isTableExists($pages)) {
    $table = $connection->newTable($pages)
        ->addColumn('page_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true])
        ->addColumn('url_key', Varien_Db_Ddl_Table::TYPE_VARCHAR, 120, ['nullable' => false])
        ->addColumn('title', Varien_Db_Ddl_Table::TYPE_VARCHAR, 255, ['nullable' => false])
        ->addColumn('category', Varien_Db_Ddl_Table::TYPE_VARCHAR, 120, ['nullable' => false, 'default' => ''])
        ->addColumn('sort_order', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['nullable' => false, 'default' => 500])
        ->addColumn('content', Varien_Db_Ddl_Table::TYPE_TEXT, '16M', ['nullable' => false])
        // For a starter page: the fingerprint of the version shipped, so an
        // upgrade can tell an untouched page from an edited one
        ->addColumn('seed_hash', Varien_Db_Ddl_Table::TYPE_VARCHAR, 40, ['nullable' => true])
        // Goes up with every save; the editor sends back the one it loaded
        ->addColumn('version', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => false, 'default' => 1])
        ->addColumn('is_deleted', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, ['unsigned' => true, 'nullable' => false, 'default' => 0])
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, ['nullable' => false])
        ->addColumn('updated_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, ['nullable' => false])
        ->addColumn('updated_by', Varien_Db_Ddl_Table::TYPE_VARCHAR, 64, ['nullable' => false, 'default' => ''])
        ->addIndex($installer->getIdxName($pages, ['url_key'], Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE), ['url_key'], ['type' => Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE])
        ->addIndex($installer->getIdxName($pages, ['category', 'sort_order']), ['category', 'sort_order'])
        ->setComment('Admin wiki pages');
    $connection->createTable($table);
}

if (!$connection->isTableExists($revisions)) {
    $table = $connection->newTable($revisions)
        ->addColumn('revision_id', Varien_Db_Ddl_Table::TYPE_BIGINT, null, ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true])
        ->addColumn('page_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => false])
        ->addColumn('title', Varien_Db_Ddl_Table::TYPE_VARCHAR, 255, ['nullable' => false])
        ->addColumn('category', Varien_Db_Ddl_Table::TYPE_VARCHAR, 120, ['nullable' => false, 'default' => ''])
        ->addColumn('content', Varien_Db_Ddl_Table::TYPE_TEXT, '16M', ['nullable' => false])
        // Empty for a starter page the module added or updated
        ->addColumn('username', Varien_Db_Ddl_Table::TYPE_VARCHAR, 64, ['nullable' => false, 'default' => ''])
        ->addColumn('note', Varien_Db_Ddl_Table::TYPE_VARCHAR, 255, ['nullable' => false, 'default' => ''])
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, ['nullable' => false])
        ->addIndex($installer->getIdxName($revisions, ['page_id']), ['page_id'])
        ->addForeignKey(
            $installer->getFkName($revisions, 'page_id', $pages, 'page_id'),
            'page_id', $pages, 'page_id',
            Varien_Db_Ddl_Table::ACTION_CASCADE, Varien_Db_Ddl_Table::ACTION_CASCADE
        )
        ->setComment('Admin wiki page revisions');
    $connection->createTable($table);
}

$installer->endSetup();

Mage::getModel('ysrtech_adminassistant/wiki')->seed();
