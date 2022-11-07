<?php

namespace Meetanshi\PayGlocal\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Store\Model\StoreManagerInterface;
use Meetanshi\PayGlocal\Helper\Data;

/**
 * Class PayGlocalConfigProvider
 * @package Meetanshi\PayGlocal\Model
 */
class PayGlocalConfigProvider implements ConfigProviderInterface
{
    /**
     * @var Data
     */
    protected $helper;
    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var array
     */
    protected $methodCodes = ['payglocal'];
    /**
     * @var array
     */
    protected $methods = [];

    /**
     * PayGlocalConfigProvider constructor.
     * @param Data $helper
     * @param PaymentHelper $paymentHelper
     * @param StoreManagerInterface $storeManager
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function __construct(Data $helper, PaymentHelper $paymentHelper, StoreManagerInterface $storeManager)
    {
        $this->helper = $helper;
        $this->storeManager = $storeManager;
        foreach ($this->methodCodes as $code) {
            $this->methods[$code] = $paymentHelper->getMethodInstance($code);
        }
    }

    /**
     * @return array
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getConfig()
    {
        $redirectUrl = $this->storeManager->getStore()->getBaseUrl() . 'payglocal/payment/redirect';
        $showLogo = $this->helper->showLogo();
        $imageUrl = $this->helper->getPaymentLogo();

        $config = [];
        $config['payment']['payglocal_payment']['imageurl'] = ($showLogo) ? $imageUrl : '';
        $config['payment']['payglocal_payment']['is_active'] = $this->helper->isActive();
        $config['payment']['payglocal_payment']['payment_instruction'] = trim($this->helper->getPaymentInstructions());
        $config['payment']['payglocal_payment']['redirect_url'] = $redirectUrl;

        return $config;
    }
}
