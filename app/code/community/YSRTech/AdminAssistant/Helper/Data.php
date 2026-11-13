<?php
class YSRTech_AdminAssistant_Helper_Data extends Mage_Core_Helper_Abstract
{
    public const ACL_USE = 'ysrtech_adminassistant';

    public const ACL_CONFIG = 'system/config/ysrtech_adminassistant';

    public function getConfig(string $path)
    {
        return Mage::getStoreConfig('ysrtech_adminassistant/' . $path, Mage_Core_Model_App::ADMIN_STORE_ID);
    }

    /**
     * A setting saved through the encrypted backend model. getStoreConfig
     * returns the ciphertext as stored; decrypting is the reader's job.
     */
    public function getSecret(string $path): string
    {
        return $this->decrypt((string) $this->getConfig($path));
    }

    public function decrypt(string $stored): string
    {
        $stored = trim($stored);

        if ($stored === '') {
            return '';
        }

        try {
            return trim((string) Mage::helper('core')->decrypt($stored));
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * The privacy switch. Default off; nothing is sent while it is.
     */
    public function isEnabled(): bool
    {
        return (bool) $this->getConfig('general/enabled');
    }

    public function isEmailCampaignsInstalled(): bool
    {
        return Mage::helper('core')->isModuleEnabled('YSRTech_EmailCampaigns');
    }

    public function adminUser(): ?Mage_Admin_Model_User
    {
        $user = Mage::getSingleton('admin/session')->getUser();

        return $user && $user->getId() ? $user : null;
    }

    public function isAllowed(string $resource): bool
    {
        return (bool) Mage::getSingleton('admin/session')->isAllowed($resource);
    }

    /**
     * Whether this person gets the button at all: they may use the chat and
     * it is on, or it is off and they are the one who could turn it on.
     */
    public function showPanel(): bool
    {
        if (!$this->adminUser()) {
            return false;
        }

        if ($this->isEnabled()) {
            return $this->isAllowed(self::ACL_USE);
        }

        return $this->isAllowed(self::ACL_CONFIG);
    }
}
