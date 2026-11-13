<?php
/**
 * The audit log page: questions newest first, each with the look-ups made
 * for it and what the provider was asked to do.
 */
class YSRTech_AdminAssistant_Block_Adminhtml_Audit extends Mage_Adminhtml_Block_Template
{
    public const PER_PAGE = 30;

    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('ysrtech_adminassistant/audit.phtml');
    }

    public function filters(): array
    {
        $r = $this->getRequest();

        return [
            'user' => trim((string) $r->getParam('user', '')),
            'q'    => trim((string) $r->getParam('q', '')),
            'page' => max(1, (int) $r->getParam('p', 1)),
        ];
    }

    /**
     * @return array{questions:array, total:int, totals:array}
     */
    public function data(): array
    {
        $f        = $this->filters();
        $resource = Mage::getSingleton('core/resource');
        $db       = $resource->getConnection('core_read');
        $table    = $resource->getTableName('ysrtech_adminassistant/audit');

        $select = $db->select()->from($table)->where('kind = ?', 'question');

        if ($f['user'] !== '') {
            $select->where('username = ?', $f['user']);
        }

        if ($f['q'] !== '') {
            $select->where('question LIKE ?', '%' . addcslashes($f['q'], '%_\\') . '%');
        }

        $total     = (int) $db->fetchOne((clone $select)->reset(Zend_Db_Select::COLUMNS)->columns(new Zend_Db_Expr('COUNT(*)')));
        $questions = $db->fetchAll($select->order('audit_id DESC')->limitPage($f['page'], self::PER_PAGE));
        $ids       = array_map('intval', array_column($questions, 'audit_id'));
        $children  = $ids ? $db->fetchAll($db->select()->from($table)->where('question_id IN (?)', $ids)->order('audit_id ASC')) : [];

        foreach ($questions as &$q) {
            $q['tools']  = array_values(array_filter($children, static fn ($c) => (int) $c['question_id'] === (int) $q['audit_id'] && $c['kind'] === 'tool'));
            $models      = array_filter($children, static fn ($c) => (int) $c['question_id'] === (int) $q['audit_id'] && $c['kind'] === 'model');
            $q['calls']  = count($models);
            $q['tokens'] = array_sum(array_map(static fn ($m) => (int) $m['prompt_tokens'] + (int) $m['completion_tokens'], $models));
            $costs       = array_filter(array_map(static fn ($m) => $m['cost_usd'], $models), static fn ($c) => $c !== null);
            $q['cost']   = $costs ? array_sum($costs) : null;
            $q['failed'] = count(array_filter($models, static fn ($m) => !(int) $m['ok']));
        }
        unset($q);

        $since  = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $totals = $db->fetchRow($db->select()->from($table, [
            'questions' => new Zend_Db_Expr("SUM(kind = 'question')"),
            'lookups'   => new Zend_Db_Expr("SUM(kind = 'tool')"),
            'calls'     => new Zend_Db_Expr("SUM(kind = 'model')"),
            'cost'      => new Zend_Db_Expr("SUM(IF(kind = 'model', cost_usd, NULL))"),
            'users'     => new Zend_Db_Expr("COUNT(DISTINCT IF(kind = 'question', username, NULL))"),
        ])->where('created_at >= ?', $since));

        $users = $db->fetchCol($db->select()->distinct()->from($table, ['username'])->where('kind = ?', 'question')->order('username'));

        return ['questions' => $questions, 'total' => $total, 'totals' => $totals, 'users' => $users];
    }

    public function local(?string $utc): string
    {
        return (string) YSRTech_AdminAssistant_Model_Tool_Abstract::local($utc);
    }
}
