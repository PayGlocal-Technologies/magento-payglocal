<?php

class Meetanshi_PayGlocal_Helper_Data extends Mage_Core_Helper_Abstract
{
    const CONFIG_PAYGLOCAL_MODE = 'payment/payglocal/mode';

    const CONFIG_PAYGLOCAL_SANDBOX_PUBLIC_KEY = 'payment/payglocal/sandbox_public_key';
    const CONFIG_PAYGLOCAL_LIVE_PUBLIC_KEY = 'payment/payglocal/live_public_key';

    const CONFIG_PAYGLOCAL_SANDBOX_PRIVATE_KEY = 'payment/payglocal/sandbox_private_key';
    const CONFIG_PAYGLOCAL_LIVE_PRIVATE_KEY = 'payment/payglocal/live_private_key';

    const CONFIG_PAYGLOCAL_SANDBOX_MERCHANT_ID = 'payment/payglocal/sandbox_merchant_id';
    const CONFIG_PAYGLOCAL_LIVE_MERCHANT_ID = 'payment/payglocal/live_merchant_id';

    const CONFIG_PAYGLOCAL_SANDBOX_PUBLIC_PEM = 'payment/payglocal/sandbox_public_pem';
    const CONFIG_PAYGLOCAL_LIVE_PUBLIC_PEM = 'payment/payglocal/live_public_pem';

    const CONFIG_PAYGLOCAL_SANDBOX_PRIVATE_PEM = 'payment/payglocal/sandbox_private_pem';
    const CONFIG_PAYGLOCAL_LIVE_PRIVATE_PEM = 'payment/payglocal/live_private_pem';

    private $checkoutUrlLive = 'https://api.prod.payglocal.in/gl/v1/payments';
    private $checkoutUrlSandbox = 'https://api.uat.payglocal.in/gl/v1/payments';

    public function getMerchantId()
    {
        if ($this->getMode()) {
            return Mage::helper('core')->decrypt(Mage::getStoreConfig(self::CONFIG_PAYGLOCAL_SANDBOX_MERCHANT_ID));
        } else {
            return Mage::helper('core')->decrypt(Mage::getStoreConfig(self::CONFIG_PAYGLOCAL_LIVE_MERCHANT_ID));
        }
    }

    public function getPublicKey()
    {
        if ($this->getMode()) {
            return Mage::getStoreConfig(self::CONFIG_PAYGLOCAL_SANDBOX_PUBLIC_KEY);
        } else {
            return Mage::getStoreConfig(self::CONFIG_PAYGLOCAL_LIVE_PUBLIC_KEY);
        }
    }

    public function getPrivateKey()
    {
        if ($this->getMode()) {
            return Mage::getStoreConfig(self::CONFIG_PAYGLOCAL_SANDBOX_PRIVATE_KEY);
        } else {
            return Mage::getStoreConfig(self::CONFIG_PAYGLOCAL_LIVE_PRIVATE_KEY);
        }
    }

    public function getPrivatePem()
    {
        if ($this->getMode()) {
            return Mage::getStoreConfig(self::CONFIG_PAYGLOCAL_SANDBOX_PRIVATE_PEM);
        } else {
            return Mage::getStoreConfig(self::CONFIG_PAYGLOCAL_LIVE_PRIVATE_PEM);
        }
    }

    public function getPublicPem()
    {
        if ($this->getMode()) {
            return Mage::getStoreConfig(self::CONFIG_PAYGLOCAL_SANDBOX_PUBLIC_PEM);
        } else {
            return Mage::getStoreConfig(self::CONFIG_PAYGLOCAL_LIVE_PUBLIC_PEM);
        }
    }

    public function getMode()
    {
        return Mage::getStoreConfig(self::CONFIG_PAYGLOCAL_MODE);
    }


    public function getPayGlocalCheckoutUrl()
    {
        if ($this->getMode()) {
            return $this->checkoutUrlSandbox;
        } else {
            return $this->checkoutUrlLive;
        }
    }

    public function getAcceptUrl()
    {
        return Mage::getUrl('payglocal/payment/accept');
    }

    public function generateRandomString($length = 16)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

}
