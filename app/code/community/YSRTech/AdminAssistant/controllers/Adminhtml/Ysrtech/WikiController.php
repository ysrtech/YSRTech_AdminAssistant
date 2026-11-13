<?php
/**
 * The admin wiki: home, a page, search, edit, history.
 *
 * Reading needs "Wiki (read)"; writing, editing, deleting and restoring need
 * "Write, edit and delete pages" as well.
 */
class YSRTech_AdminAssistant_Adminhtml_Ysrtech_WikiController extends Mage_Adminhtml_Controller_Action
{
    protected function _isAllowed(): bool
    {
        return Mage::getSingleton('admin/session')->isAllowed(YSRTech_AdminAssistant_Model_Wiki::ACL_READ);
    }

    private function _canEdit(): bool
    {
        return Mage::getSingleton('admin/session')->isAllowed(YSRTech_AdminAssistant_Model_Wiki::ACL_EDIT);
    }

    private function _wiki(): YSRTech_AdminAssistant_Model_Wiki
    {
        return Mage::getSingleton('ysrtech_adminassistant/wiki');
    }

    /** Render the wiki template in one of its modes */
    private function _show(string $mode, array $data = [], string $title = ''): void
    {
        $this->loadLayout();
        $this->_title($this->__('Wiki'));
        if ($title !== '') {
            $this->_title($title);
        }

        $block = $this->getLayout()->createBlock('ysrtech_adminassistant/adminhtml_wiki')
            ->setMode($mode)->addData($data)->setCanEdit($this->_canEdit());
        $this->getLayout()->getBlock('content')->append($block);
        $this->renderLayout();
    }

    public function indexAction(): void
    {
        $q = trim((string) $this->getRequest()->getParam('q', ''));

        if ($q !== '') {
            $this->_show('search', ['query' => $q, 'results' => $this->_wiki()->search($q, 50)], $this->__('Search'));

            return;
        }

        $this->_show('home');
    }

    public function viewAction(): void
    {
        $key  = (string) $this->getRequest()->getParam('page', '');
        $page = $this->_wiki()->load($key);

        if (!$page) {
            if ($this->_canEdit() && YSRTech_AdminAssistant_Model_Wiki::slug($key) !== '') {
                // A link to a page nobody has written yet: offer to write it
                $this->_redirect('*/*/edit', ['new' => YSRTech_AdminAssistant_Model_Wiki::slug($key)]);

                return;
            }
            $this->_getSession()->addError($this->__('There is no wiki page "%s".', $key));
            $this->_redirect('*/*/index');

            return;
        }

        $this->_show('view', ['page' => $page], $page['title']);
    }

    public function editAction(): void
    {
        if (!$this->_canEdit()) {
            $this->_denied();

            return;
        }

        $id   = (int) $this->getRequest()->getParam('id');
        $page = $id ? $this->_wiki()->loadById($id) : null;

        if ($id && !$page) {
            $this->_redirect('*/*/index');

            return;
        }

        $draft = $this->_getSession()->getData('ysraa_wiki_draft', true);
        $new   = (string) $this->getRequest()->getParam('new', '');

        $this->_show('edit', [
            'page'  => is_array($draft) ? array_merge($page ?: [], $draft) : ($page ?: [
                'url_key'    => $new,
                'title'      => $new !== '' ? ucfirst(str_replace('-', ' ', $new)) : '',
                'category'   => (string) $this->getRequest()->getParam('category', ''),
                'sort_order' => 500,
                'content'    => '',
            ]),
            'page_id' => $page ? (int) $page['page_id'] : null,
        ], $page ? $this->__('Edit: %s', $page['title']) : $this->__('New page'));
    }

    public function saveAction(): void
    {
        if (!$this->_canEdit() || !$this->getRequest()->isPost() || !$this->_validateFormKey()) {
            $this->_denied();

            return;
        }

        $r  = $this->getRequest();
        $id = (int) $r->getPost('page_id') ?: null;

        try {
            $page = $this->_wiki()->save($id, [
                'title'      => $r->getPost('title'),
                'category'   => $r->getPost('category'),
                'url_key'    => $r->getPost('url_key'),
                'sort_order' => $r->getPost('sort_order'),
                'content'    => $r->getPost('content'),
                'note'       => $r->getPost('note'),
            ], Mage::getSingleton('admin/session')->getUser(), $id ? (int) $r->getPost('version') : null);

            $this->_getSession()->addSuccess($this->__('Saved.'));
            $this->_redirect('*/*/view', ['page' => $page['url_key']]);
        } catch (Mage_Core_Exception $e) {
            // Back to the editor with what was typed, so nothing is lost
            $this->_getSession()->setData('ysraa_wiki_draft', [
                'title' => $r->getPost('title'), 'category' => $r->getPost('category'), 'url_key' => $r->getPost('url_key'),
                'sort_order' => $r->getPost('sort_order'), 'content' => $r->getPost('content'),
            ]);
            $this->_getSession()->addError($e->getMessage());
            $this->_redirect('*/*/edit', $id ? ['id' => $id] : []);
        }
    }

    /** The editor's live preview: Markdown in, HTML out */
    public function previewAction(): void
    {
        if (!$this->_canEdit() || !$this->getRequest()->isPost() || !$this->_validateFormKey()) {
            $this->getResponse()->setHttpResponseCode(403);

            return;
        }

        $this->getResponse()
            ->setHeader('Content-Type', 'text/html; charset=UTF-8', true)
            ->setBody($this->_wiki()->markdown()->render((string) $this->getRequest()->getPost('content')));
    }

    public function deleteAction(): void
    {
        if (!$this->_canEdit() || !$this->getRequest()->isPost() || !$this->_validateFormKey()) {
            $this->_denied();

            return;
        }

        $this->_wiki()->delete((int) $this->getRequest()->getPost('id'), Mage::getSingleton('admin/session')->getUser());
        $this->_getSession()->addSuccess($this->__('The page was deleted. Its history is kept, and it can be restored from there.'));
        $this->_redirect('*/*/index');
    }

    public function historyAction(): void
    {
        $db   = Mage::getSingleton('core/resource')->getConnection('core_read');
        $id   = (int) $this->getRequest()->getParam('id');
        $page = $db->fetchRow($db->select()->from(Mage::getSingleton('core/resource')->getTableName('ysrtech_adminassistant/wiki_page'))->where('page_id = ?', $id));

        if (!$page) {
            $this->_redirect('*/*/index');

            return;
        }

        $revision = (int) $this->getRequest()->getParam('revision');
        $this->_show('history', [
            'page'      => $page,
            'revisions' => $this->_wiki()->revisions($id),
            'revision'  => $revision ? $this->_wiki()->revision($revision) : null,
        ], $this->__('History: %s', $page['title']));
    }

    public function restoreAction(): void
    {
        if (!$this->_canEdit() || !$this->getRequest()->isPost() || !$this->_validateFormKey()) {
            $this->_denied();

            return;
        }

        try {
            $page = $this->_wiki()->restore((int) $this->getRequest()->getPost('revision'), Mage::getSingleton('admin/session')->getUser());
            $this->_getSession()->addSuccess($this->__('That version is now the current one.'));
            $this->_redirect('*/*/view', ['page' => $page['url_key']]);
        } catch (Mage_Core_Exception $e) {
            $this->_getSession()->addError($e->getMessage());
            $this->_redirect('*/*/index');
        }
    }

    private function _denied(): void
    {
        $this->_getSession()->addError($this->__('Your role can read the wiki but not change it.'));
        $this->_redirect('*/*/index');
    }
}
