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
     * @param $order
     * @return string
     */
    public function getPaymentForm($order)
    {
        $amount = number_format((float)$order->getGrandTotal(), 2, '.', '');
        $merchantID = $this->getMerchantID();
        $paymentType = $this->getPaymentType();
        $currencyCode = $order->getOrderCurrencyCode();
        $cartID = 'Psigate-' . $order->getIncrementId();
        $language = $this->getLanguage();

        $billingAddress = $order->getBillingAddress()->getData();

        $streetData = $billingAddress['street'];
        $streetList = preg_split("/\\r\\n|\\r|\\n/", $streetData);

        $address1 = $streetList[0];

        $shippingAddress = $order->getShippingAddress();
        if ($shippingAddress) {
            $shippingAddress = $order->getShippingAddress()->getData();
        } else {
            $shippingAddress = $order->getBillingAddress()->getData();
        }

        $shippingStreetData = $shippingAddress['street'];
        $shippingStreetList = preg_split("/\\r\\n|\\r|\\n/", $shippingStreetData);

        $daddress1 = $shippingStreetList[0];

        //$amount = 1;

        $html = "<form id='PayGlocalForm' name='payglocalhostedsubmit' action='" . $this->getGatewayUrl() . "' method='POST' accept-charset='utf-8'>";
        $html .= "<input type='hidden' name='MerchantID' value='" . $merchantID . "' />";
        $html .= "<input type='hidden' name='OrderID' value='" . $cartID . "' />";
        $html .= "<input type='hidden' name='SubTotal' value='" . $amount . "' />";
        if ($this->getPaymentType() != '') {
            $html .= "<input type='hidden' name='PaymentType' value='" . $paymentType . "' />";
        }
        if ($this->getMode()) {
            $html .= "<input type='hidden' name='TestResult' value='A' />";
        }
        $html .= "<input type='hidden' name='Email' value='" . $order->getCustomerEmail() . "' />";
        $html .= "<input type='hidden' name='Description' value='" . $this->getPaymentSubject() . "' />";
        $html .= "<input type='hidden' name='Currency' value='" . $currencyCode . "' />";

        $html .= "<input type='hidden' name='Bname' value='" . $order->getBillingAddress()->getName() . "' />";
        $html .= "<input type='hidden' name='Baddress1' value='" . $address1 . "' />";
        $html .= "<input type='hidden' name='Bcompany' value='" . $order->getBillingAddress()->getCompany() . "' />";
        $html .= "<input type='hidden' name='Bpostalcode' value='" . $billingAddress['postcode'] . "' />";
        $html .= "<input type='hidden' name='Bprovince' value='" . $order->getBillingAddress()->getRegion() . "' />";
        $html .= "<input type='hidden' name='Bcountry' value='" . $billingAddress['country_id'] . "' />";
        $html .= "<input type='hidden' name='Bcity' value='" . $billingAddress['city'] . "' />";

        $html .= "<input type='hidden' name='Sname' value='" . $order->getShippingAddress()->getName() . "' />";
        $html .= "<input type='hidden' name='Saddress1' value='" . $daddress1 . "' />";
        $html .= "<input type='hidden' name='Scompany' value='" . $order->getShippingAddress()->getCompany() . "' />";
        $html .= "<input type='hidden' name='Saddress1' value='" . $shippingAddress['postcode'] . "' />";
        $html .= "<input type='hidden' name='Sprovince' value='" . $order->getShippingAddress()->getRegion() . "' />";
        $html .= "<input type='hidden' name='Scountry' value='" . $shippingAddress['country_id'] . "' />";
        $html .= "<input type='hidden' name='Scity' value='" . $shippingAddress['city'] . "' />";

        $html .= "<input type='hidden' name='CardAction' value=0 />";
        $html .= "<input type='hidden' name='CustomerLanguage' value='" . $language . "' />";
        $html .= "<input type='hidden' name='ThanksURL' value='" . $this->getCallbackUrl() . "' />";
        $html .= "<input type='hidden' name='NoThanksURL' value='" . $this->getReturnUrl() . "' />";
        $html .= "</form>";

        $this->logger('HTML FOrm', $html);

        return $html;
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
