<?php

class Meetanshi_PayGlocal_Model_Standard extends Mage_Payment_Model_Method_Abstract
{
    protected $_code = 'payglocal';
    protected $_infoBlockType = 'payglocal/payment_info';

    protected $_isInitializeNeeded = true;
    protected $_canUseInternal = true;
    protected $_canUseForMultishipping = false;

    protected $_canOrder = true;
    protected $_canAuthorize = true;
    protected $_canCapture = true;
    protected $_canCapturePartial = true;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_canVoid = true;
    protected $_canUseCheckout = true;

    public function isAvailable($quote = null)
    {
        $isAvailabel = parent::isAvailable();
        if (!$isAvailabel) {
            return false;
        }
        if (!$quote) {
            return false;
        }
        $grandTotal = $quote->getGrandTotal();
        $minOrderAmount = Mage::getStoreConfig("payment/payglocal/min_amount");

        if ($minOrderAmount == "") {
            $minOrderAmount = 0;
        }
        if ($grandTotal < $minOrderAmount) {
            return false;
        }
        return true;
    }

    public function capture(\Varien_Object $payment, $amount)
    {
        $order = $payment->getOrder();
        $payment->setTransactionId($order->getIncrementId());
        $transaction = $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_CAPTURE, null, true, "");
        $transaction->setIsClosed(false);
        return parent::capture($payment, $amount);
    }

    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl('payglocal/payment/redirect', array('_secure' => true));
    }
}
