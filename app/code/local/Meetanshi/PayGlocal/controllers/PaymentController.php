<?php

class Meetanshi_PayGlocal_PaymentController extends Mage_Core_Controller_Front_Action
{
    public function redirectAction()
    {
        try {
            $order = new Mage_Sales_Model_Order();
            $orderId = Mage::getSingleton('checkout/session')->getLastRealOrderId();
            $order->loadByIncrementId($orderId);
            $message = 'Customer is redirected to Pay Glocal';

            $order->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, true, $message);
            $order->setStatus('pending_payment');
            $order->setIsNotified(false);
            $order->save();

            $helper = Mage::helper('payglocal');
            $fp = fopen(Mage::getBaseDir('media') . '/payglocal/' . $helper->getPrivatePem(), "r");
            $priv_key = fread($fp, 8192);
            fclose($fp);
            $privateKey = openssl_get_privatekey($priv_key);
            $merchantUniqueId = $helper->generateRandomString(16);

            $orderItemData = [];
            foreach ($order->getAllVisibleItems() as $orderItem) {
                $orderItemData[] = [
                    "productDescription" => $orderItem->getName(),
                    "productSKU" => $orderItem->getSku(),
                    "productType" => $orderItem->getProductType(),
                    "itemUnitPrice" => round($orderItem->getPrice(), 2),
                    "itemQuantity" => round($orderItem->getQtyOrdered()),
                ];
            }
            if ($order->getIsVirtual()) {
                $shippingAddress = $order->getBillingAddress();
                if (sizeof($shippingAddress->getStreet()) >= 2) {
                    $addressShipStreetTwo = $shippingAddress->getStreet()[1];
                } else {
                    $addressShipStreetTwo = "";
                }
            } else {
                $shippingAddress = $order->getShippingAddress();
                if (sizeof($shippingAddress->getStreet()) >= 2) {
                    $addressShipStreetTwo = $shippingAddress->getStreet()[1];
                } else {
                    $addressShipStreetTwo = "";
                }
            }
            if (sizeof($order->getBillingAddress()->getStreet()) >= 2) {
                $addressStreetTwo = $order->getBillingAddress()->getStreet()[1];
            } else {
                $addressStreetTwo = "";
            }
            $payload = json_encode([
                "merchantTxnId" => $order->getIncrementId(),
                "merchantUniqueId" => $order->getIncrementId() . '_' . $merchantUniqueId,
                "paymentData" => [
                    "totalAmount" => round($order->getGrandTotal(), 2),
                    "txnCurrency" => $order->getOrderCurrencyCode(),
                    "billingData" => [
                        "firstName" => $order->getBillingAddress()->getFirstname(),
                        "lastName" => $order->getBillingAddress()->getLastname(),
                        "addressStreet1" => $order->getBillingAddress()->getStreet()[0],
                        "addressStreet2" => $addressStreetTwo,
                        "addressCity" => $order->getBillingAddress()->getCity(),
                        "addressState" => $order->getBillingAddress()->getRegion(),
                        "addressPostalCode" => $order->getBillingAddress()->getPostcode(),
                        "addressCountry" => $order->getBillingAddress()->getCountryId(),
                        "emailId" => $order->getCustomerEmail(),
                        "phoneNumber" => $order->getBillingAddress()->getTelephone(),
                    ]
                ],
                "riskData" => [
                    "orderData" => $orderItemData,
                    "customerData" => [
                        "merchantAssignedCustomerId" => str_pad($order->getCustomerId(), 8, "0", STR_PAD_LEFT),
                        "customerAccountType" => "1",
                        "ipAddress" => $order->getRemoteIp(),
                        "httpAccept" => $_SERVER['HTTP_ACCEPT'],
                        "httpUserAgent" => $_SERVER['HTTP_USER_AGENT'],
                    ],
                    "shippingData" => [
                        "firstName" => $shippingAddress->getFirstname(),
                        "lastName" => $shippingAddress->getLastname(),
                        "addressStreet1" => $shippingAddress->getStreet()[0],
                        "addressStreet2" => $addressShipStreetTwo,
                        "addressCity" => $shippingAddress->getCity(),
                        "addressState" => $shippingAddress->getRegion(),
                        "addressPostalCode" => $shippingAddress->getPostcode(),
                        "addressCountry" => $shippingAddress->getCountryId(),
                        "emailId" => $order->getCustomerEmail(),
                        "phoneNumber" => $shippingAddress->getTelephone(),
                    ]
                ],
                "clientPlatformDetails" => [
                    "platformName" => "Magento",
                    "platformVersion" => Mage::getVersion()
                ],
                "merchantCallbackURL" => $helper->getAcceptUrl()
            ]);

            openssl_sign($payload, $signature, $privateKey, OPENSSL_ALGO_SHA256);
            $sign = base64_encode($signature);

            $merchantID = $helper->getMerchantId();
            $privateKID = $helper->getPrivateKey();
            $metadata = json_encode([
                "mid" => $merchantID,
                "kid" => $privateKID
            ]);
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $helper->getPayGlocalCheckoutUrl() . "/initiate/paycollect",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => array(
                    'x-gl-sign-external: ' . $sign,
                    'x-gl-authn-metadata: ' . $metadata,
                    'Content-Type: text/plain'
                ),
            ));
            $response = curl_exec($curl);

            $data = json_decode($response, true);
            curl_close($curl);

            if (isset($data['data']['redirectUrl'])) {

                return $this->_redirectUrl($data['data']['redirectUrl']);
            }

            if (isset($data['errors']['displayMessage'])) {
                $error = $data['errors']['displayMessage'];
                if (isset($data['errors']['detailedMessage'])) {
                    $error = $error . '' . $data['errors']['detailedMessage'];
                }

                $order->addStatusHistoryComment($error,
                    Mage_Sales_Model_Order::STATE_CANCELED)->setIsCustomerNotified(true);
                $order->cancel();

                Mage::getSingleton('core/session')
                    ->addError($error);

                if (Mage::getSingleton('checkout/session')->getLastRealOrderId()) {
                    if ($lastQuoteId = Mage::getSingleton('checkout/session')->getLastQuoteId()) {
                        $quote = Mage::getModel('sales/quote')->load($lastQuoteId);
                        $quote->setIsActive(true)->save();
                    }
                }

            } else {
                Mage::getSingleton('core/session')
                    ->addError("Something went wrong, please try again after sometimes.");
            }

            $this->_redirect('checkout/cart');
        } catch (Exception $e) {

            echo $e->getMessage();

            Mage::getSingleton('core/session')->addError($e->getMessage());

            if (Mage::getSingleton('checkout/session')->getLastRealOrderId()) {
                if ($lastQuoteId = Mage::getSingleton('checkout/session')->getLastQuoteId()) {
                    $quote = Mage::getModel('sales/quote')->load($lastQuoteId);
                    $quote->setIsActive(true)->save();
                }
            }
        }
    }

    public function acceptAction()
    {
        Mage::log('aceept call');
        try {
            $params = $this->getRequest()->getParams();

            if (is_array($params) && !empty($params) && isset($params['x-gl-token'])) {
                $token = $params['x-gl-token'];
                $data = explode('.', $token);
                $payload = base64_decode($data[1]);
                $response = json_decode($payload, true);

                $helper = Mage::helper('payglocal');
                $fp = fopen(Mage::getBaseDir('media') . '/payglocal/' . $helper->getPrivatePem(), "r");
                $priv_key = fread($fp, 8192);
                fclose($fp);
                $privateKey = openssl_get_privatekey($priv_key);
                $difPayload = '/gl/v1/payments/' . $response['merchantUniqueId'] . '/status';

                openssl_sign($difPayload, $signature, $privateKey, OPENSSL_ALGO_SHA256);

                $sign = base64_encode($signature);

                $merchantID = $helper->getMerchantId();
                $privateKID = $helper->getPrivateKey();
                $metadata = json_encode([
                    "mid" => $merchantID,
                    "kid" => $privateKID
                ]);

                Mage::log($response);

                $curl = curl_init();
                $url = $helper->getPayGlocalCheckoutUrl() . "/" . $response['merchantUniqueId'] . '/status';
                curl_setopt_array($curl, array(
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_HTTPHEADER => array(
                        'x-gl-sign-external: ' . $sign,
                        'x-gl-authn-metadata: ' . $metadata,
                        'Content-Type: text/plain'
                    ),
                ));
                $statusResponse = curl_exec($curl);
                $statusData = json_decode($statusResponse, true);
                curl_close($curl);

                $orderId = explode("_", $response['merchantUniqueId']);
                $order = Mage::getModel('sales/order')->loadByIncrementId($orderId['0']);


                Mage::log($statusData);

                if (isset($statusData['status']) && $statusData['status'] == 'SENT_FOR_CAPTURE') {

                    $payment = $order->getPayment();
                    $transactionID = $order->getIncrementId();
                    $payment->setTransactionId($transactionID);
                    $payment->setLastTransId($transactionID);
                    $payment->setAdditionalInformation('transId', $transactionID);
                    if (array_key_exists('gid', $response)) {
                        $payment->setAdditionalInformation('gid', $response['gid']);
                    }
                    if (array_key_exists('status', $response)) {
                        $payment->setAdditionalInformation('status', $response['status']);
                    }
                    if (array_key_exists('statusUrl', $response)) {
                        $payment->setAdditionalInformation('statusUrl', $response['statusUrl']);
                    }

                    $payment->setAdditionalInformation((array)$payment->getAdditionalInformation());
                    $payment->setParentTransactionId(null);
                    $payment->save();

                    if ($order->canInvoice()) {
                        $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();
                        $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE);
                        $invoice->register();
                        $invoice->getOrder()->setIsInProcess(true);
                        $transactionSave = Mage::getModel('core/resource_transaction')
                            ->addObject($invoice)
                            ->addObject($invoice->getOrder());
                        $transactionSave->save();
                    }

                    $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true, 'Transaction approved.', true);

                    $order->sendNewOrderEmail();
                    $order->setEmailSent(true);

                    $order->save();

                    $quote = Mage::getModel('sales/quote')
                        ->load($order->getQuoteId());

                    $quote->setIsActive(false)
                        ->save();

                    $session = $this->_getCheckoutSession();
                    $session->clearHelperData();

                    $session->setLastQuoteId($order->getQuoteId())->setLastSuccessQuoteId($order->getQuoteId());

                    $orderId = $order->getId();
                    $realOrderId = $order->getIncrementId();
                    $session->setLastOrderId($orderId)->setLastRealOrderId($realOrderId);

                    return $this->_redirect('checkout/onepage/success', array('_secure' => true));
                } else {
                    Mage::getSingleton('core/session')->addError("There is a processing error with your transaction with status. " . $response["status"]);
                    $order->cancel()->setState(Mage_Sales_Model_Order::STATE_CANCELED, true, 'Transaction is not approved.', true);
                    $order->setStatus('canceled');
                    $order->save();
                    if (Mage::getSingleton('checkout/session')->getLastRealOrderId()) {
                        if ($lastQuoteId = Mage::getSingleton('checkout/session')->getLastQuoteId()) {
                            $quote = Mage::getModel('sales/quote')->load($lastQuoteId);
                            $quote->setIsActive(true)->save();
                        }
                    }
                    return $this->_redirect('checkout/cart');
                }

            }
        } catch (Exception $e) {
            Mage::getSingleton('core/session')->addError($e->getMessage());
            return $this->_redirect('checkout/cart/');
        }
        return $this->_redirect('checkout/cart/');
    }

    protected function _getCheckoutSession()
    {
        return Mage::getSingleton('checkout/session');
    }
}
