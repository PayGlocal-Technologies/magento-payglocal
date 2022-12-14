<?php

namespace Meetanshi\PayGlocal\Helper;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Session\SessionManager;
use Magento\Framework\View\Asset\Repository;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Filesystem;

/**
 * Class Data
 * @package Meetanshi\PayGlocal\Helper
 */
class Data extends AbstractHelper
{
    const CONFIG_PAYGLOCAL_ACTIVE = 'payment/payglocal/active';
    const CONFIG_PAYGLOCAL_MODE = 'payment/payglocal/mode';

    const CONFIG_PAYGLOCAL_LOGO = 'payment/payglocal/show_logo';

    const CONFIG_PAYGLOCAL_INSTRUCTIONS = 'payment/payglocal/instructions';

    const CONFIG_PAYGLOCAL_SANDBOX_MERCHANT_ID = 'payment/payglocal/sandbox_merchant_id';
    const CONFIG_PAYGLOCAL_LIVE_MERCHANT_ID = 'payment/payglocal/live_merchant_id';

    const CONFIG_PAYGLOCAL_SANDBOX_GATEWAY_URL = 'payment/payglocal/sandbox_gateway_url';
    const CONFIG_PAYGLOCAL_LIVE_GATEWAY_URL = 'payment/payglocal/live_gateway_url';

    const CONFIG_PAYGLOCAL_SANDBOX_PUBLIC_KID = 'payment/payglocal/sandbox_public_kid';
    const CONFIG_PAYGLOCAL_LIVE_PUBLIC_KID = 'payment/payglocal/live_public_kid';

    const CONFIG_PAYGLOCAL_SANDBOX_PRIVATE_KID = 'payment/payglocal/sandbox_private_kid';
    const CONFIG_PAYGLOCAL_LIVE_PRIVATE_KID = 'payment/payglocal/live_private_kid';

    const CONFIG_PAYGLOCAL_SANDBOX_PUBLIC_PEM = 'payment/payglocal/sandbox_public_pem';
    const CONFIG_PAYGLOCAL_LIVE_PUBLIC_PEM = 'payment/payglocal/live_public_pem';

    const CONFIG_PAYGLOCAL_SANDBOX_PRIVATE_PEM = 'payment/payglocal/sandbox_private_pem';
    const CONFIG_PAYGLOCAL_LIVE_PRIVATE_PEM = 'payment/payglocal/live_private_pem';

    const CONFIG_PAYGLOCAL_INVOICE = 'payment/payglocal/allow_invoice';

    const CONFIG_PAYGLOCAL_DEBUG = 'payment/payglocal/debug';

    /**
     * @var DirectoryList
     */
    protected $directoryList;
    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;
    /**
     * @var Http
     */
    protected $request;
    /**
     * @var EncryptorInterface
     */
    protected $encryptor;
    /**
     * @var SessionManager
     */
    protected $sessionManager;
    /**
     * @var CheckoutSession
     */
    protected $checkoutSession;
    /**
     * @var Repository
     */
    protected $repository;

    /**
     * @var Filesystem
     */
    protected $fileSystem;

    /**
     * Data constructor.
     * @param Context $context
     * @param EncryptorInterface $encryptor
     * @param DirectoryList $directoryList
     * @param StoreManagerInterface $storeManager
     * @param Http $request
     * @param SessionManager $sessionManager
     * @param Repository $repository
     * @param CheckoutSession $checkoutSession
     * @param Filesystem $fileSystem
     */
    public function __construct(
        Context $context,
        EncryptorInterface $encryptor,
        DirectoryList $directoryList,
        StoreManagerInterface $storeManager,
        Http $request,
        SessionManager $sessionManager,
        Repository $repository,
        CheckoutSession $checkoutSession,
        Filesystem $fileSystem
    ) {
        parent::__construct($context);
        $this->encryptor = $encryptor;
        $this->directoryList = $directoryList;
        $this->storeManager = $storeManager;
        $this->request = $request;
        $this->sessionManager = $sessionManager;
        $this->repository = $repository;
        $this->checkoutSession = $checkoutSession;
        $this->fileSystem = $fileSystem;
    }

    /**
     * @return mixed
     */
    public function isDebug()
    {
        return $this->scopeConfig->getValue(self::CONFIG_PAYGLOCAL_DEBUG, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return mixed
     */
    public function isAutoInvoice()
    {
        return $this->scopeConfig->getValue(self::CONFIG_PAYGLOCAL_INVOICE, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return mixed
     */
    public function isActive()
    {
        return $this->scopeConfig->getValue(self::CONFIG_PAYGLOCAL_ACTIVE, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return mixed
     */
    public function getPaymentInstructions()
    {
        return $this->scopeConfig->getValue(self::CONFIG_PAYGLOCAL_INSTRUCTIONS, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return bool
     */
    public function isPaymentAvailable()
    {
        $merchantID = trim($this->getMerchantID());
        if (!$merchantID) {
            return false;
        }
        return true;
    }

    /**
     * @return string
     */
    public function getPublicPem()
    {
        if ($this->getMode()) {
            return $this->scopeConfig->getValue(self::CONFIG_PAYGLOCAL_SANDBOX_PUBLIC_PEM,
                ScopeInterface::SCOPE_STORE);
        } else {
            return $this->scopeConfig->getValue(self::CONFIG_PAYGLOCAL_LIVE_PUBLIC_PEM,
                ScopeInterface::SCOPE_STORE);
        }
    }

    /**
     * @return string
     */
    public function getPrivatePem()
    {
        if ($this->getMode()) {
            return $this->scopeConfig->getValue(self::CONFIG_PAYGLOCAL_SANDBOX_PRIVATE_PEM,
                ScopeInterface::SCOPE_STORE);
        } else {
            return $this->scopeConfig->getValue(self::CONFIG_PAYGLOCAL_LIVE_PRIVATE_PEM,
                ScopeInterface::SCOPE_STORE);
        }
    }

    /**
     * @return string
     */
    public function getPublicKID()
    {
        if ($this->getMode()) {
            return $this->encryptor->decrypt($this->scopeConfig->getValue(self::CONFIG_PAYGLOCAL_SANDBOX_PUBLIC_KID,
                ScopeInterface::SCOPE_STORE));
        } else {
            return $this->encryptor->decrypt($this->scopeConfig->getValue(self::CONFIG_PAYGLOCAL_LIVE_PUBLIC_KID,
                ScopeInterface::SCOPE_STORE));
        }
    }

    /**
     * @return string
     */
    public function getPrivateKID()
    {
        if ($this->getMode()) {
            return $this->encryptor->decrypt($this->scopeConfig->getValue(self::CONFIG_PAYGLOCAL_SANDBOX_PRIVATE_KID,
                ScopeInterface::SCOPE_STORE));
        } else {
            return $this->encryptor->decrypt($this->scopeConfig->getValue(self::CONFIG_PAYGLOCAL_LIVE_PRIVATE_KID,
                ScopeInterface::SCOPE_STORE));
        }
    }

    /**
     * @return string
     */
    public function getMerchantID()
    {
        if ($this->getMode()) {
            return $this->scopeConfig->getValue(self::CONFIG_PAYGLOCAL_SANDBOX_MERCHANT_ID,
                ScopeInterface::SCOPE_STORE);
        } else {
            return $this->scopeConfig->getValue(self::CONFIG_PAYGLOCAL_LIVE_MERCHANT_ID,
                ScopeInterface::SCOPE_STORE);
        }
    }

    /**
     * @return mixed
     */
    public function getMode()
    {
        return $this->scopeConfig->getValue(self::CONFIG_PAYGLOCAL_MODE, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return mixed
     */
    public function getGatewayUrl()
    {
        if ($this->getMode()) {
            return $this->scopeConfig->getValue(self::CONFIG_PAYGLOCAL_SANDBOX_GATEWAY_URL,
                ScopeInterface::SCOPE_STORE);
        } else {
            return $this->scopeConfig->getValue(self::CONFIG_PAYGLOCAL_LIVE_GATEWAY_URL, ScopeInterface::SCOPE_STORE);
        }
    }

    /**
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getCallbackUrl()
    {
        $baseUrl = $this->storeManager->getStore()->getBaseUrl();
        return $baseUrl . "payglocal/payment/success";
    }

    /**
     * @return string
     */
    public function getPaymentSubject()
    {
        $subject = trim($this->scopeConfig->getValue('general/store_information/name', ScopeInterface::SCOPE_STORE));
        if (!$subject) {
            return "Magento 2 order";
        }

        return $subject;
    }

    /**
     * @return mixed
     */
    public function showLogo()
    {
        return $this->scopeConfig->getValue(self::CONFIG_PAYGLOCAL_LOGO, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return string
     */
    public function getPaymentLogo()
    {
        $params = ['_secure' => $this->request->isSecure()];
        return $this->repository->getUrlWithParams('Meetanshi_PayGlocal::images/payglocal.png', $params);
    }

    public function getMediaPath()
    {
        return $this->fileSystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath();
    }

    /**
     * @param $data
     */
    public function logger($message = '', $data)
    {
        if ($this->isDebug()) {
            $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/payglocal.log');
            $logger = new \Zend_Log();
            $logger->addWriter($writer);
            if (!is_array($data)) {
                $data = (array)$data;
            }
            $logger->info($message);
            $logger->info(print_r($data, true));
        }
    }
}
