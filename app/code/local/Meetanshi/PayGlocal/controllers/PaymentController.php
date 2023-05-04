<?php

require_once(Mage::getBaseDir('lib') . '/payglocal/vendor/autoload.php');

use Jose\Component\Encryption\Algorithm\ContentEncryption\A128CBCHS256;
use Jose\Component\Encryption\Algorithm\KeyEncryption\RSAOAEP256;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Encryption\Compression\CompressionMethodManager;
use Jose\Component\Encryption\Compression\Deflate;
use Jose\Component\Encryption\JWEBuilder;
use Jose\Component\Encryption\Serializer\CompactSerializer;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\Serializer\CompactSerializer as SignCompactSerializer;
use Jose\Component\Signature\Serializer\JWSSerializerManager;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\JWSLoader;

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

            $keyEncryptionAlgorithmManager = new AlgorithmManager([
                new RSAOAEP256(),
            ]);
            $contentEncryptionAlgorithmManager = new AlgorithmManager([
                new A128CBCHS256(),
            ]);
            $compressionMethodManager = new CompressionMethodManager([
                new Deflate(),
            ]);

            $jweBuilder = new JWEBuilder(
                $keyEncryptionAlgorithmManager,
                $contentEncryptionAlgorithmManager,
                $compressionMethodManager
            );

            $helper = Mage::helper('payglocal');

            $publicKeyPath = Mage::getBaseDir('media') . '/payglocal/' . $helper->getPublicPem();
            $privateKeyPath = Mage::getBaseDir('media') . '/payglocal/' . $helper->getPrivatePem();
            $publicKID = $helper->getPublicKey();
            $privateKID = $helper->getPrivateKey();
            $merchantID = $helper->getMerchantId();

            $jweKey = JWKFactory::createFromKeyFile(
                $publicKeyPath,
                null,
                [
                    'kid' => $publicKID,
                    'use' => 'enc',
                    'alg' => 'RSA-OAEP-256',
                ]
            );

            $header = [
                'issued-by' => $merchantID,
                'enc' => 'A128CBC-HS256',
                'exp' => 30000,
                'iat' => (string)round(microtime(true) * 1000),
                'alg' => 'RSA-OAEP-256',
                'kid' => $publicKID
            ];

            $merchantUniqueId = $helper->generateRandomString(16);
            $payload = json_encode([
                "merchantTxnId" => $order->getIncrementId(),
                "merchantUniqueId" => $order->getIncrementId() . '_' . $merchantUniqueId,
                "paymentData" => array(
                    "totalAmount" => round($order->getGrandTotal(), 2),
                    "txnCurrency" => $order->getOrderCurrencyCode()
                ),
                "merchantCallbackURL" => $helper->getAcceptUrl()
            ]);

            try {
                $jwe = $jweBuilder
                    ->create()
                    ->withPayload($payload)
                    ->withSharedProtectedHeader($header)
                    ->addRecipient($jweKey)
                    ->build();
            } catch (\Exception $e) {
                throw new Exception($e->getMessage());
            }


            $serializer = new CompactSerializer();
            $jweToken = $serializer->serialize($jwe, 0);

            $algorithmManager = new AlgorithmManager([
                new RS256(),
            ]);

            $jwsBuilder = new JWSBuilder(
                $algorithmManager
            );

            $jwsKey = JWKFactory::createFromKeyFile(
                $privateKeyPath,
                null,
                [
                    'kid' => $privateKID,
                    'use' => 'sig'

                ]
            );

            $jwsHeader = [
                'issued-by' => $merchantID,
                'is-digested' => 'true',
                'alg' => 'RS256',
                'x-gl-enc' => 'true',
                'x-gl-merchantId' => $merchantID,
                'kid' => $privateKID
            ];

            $hashedPayload = base64_encode(hash('sha256', $jweToken, $BinaryOutputMode = true));


            $jwsPayload = json_encode([
                'digest' => $hashedPayload,
                'digestAlgorithm' => "SHA-256",
                'exp' => 300000,
                'iat' => (string)round(microtime(true) * 1000)
            ]);

            try {
                $jws = $jwsBuilder
                    ->create()
                    ->withPayload($jwsPayload)
                    ->addSignature($jwsKey, $jwsHeader)
                    ->build();
            } catch (\Exception $e) {
                throw new Exception($e->getMessage());
            }

            $jwsSerializer = new SignCompactSerializer();
            $jwsToken = $jwsSerializer->serialize($jws,
                0);

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
                CURLOPT_POSTFIELDS => $jweToken,
                CURLOPT_HTTPHEADER => array(
                    'x-gl-token-external: ' . $jwsToken,
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
        try {
            $helper = Mage::helper('payglocal');
            $params = $this->getRequest()->getParams();

            if (is_array($params) && !empty($params) && isset($params['x-gl-token'])) {
                $token = $params['x-gl-token'];
                $algorithmManager = new AlgorithmManager([
                    new RS256(),
                ]);

                $jwsVerifier = new JWSVerifier(
                    $algorithmManager
                );

                $publicKeyPath = Mage::getBaseDir('media') . '/payglocal/' . $helper->getPublicPem();
                $publicKID = $helper->getPublicKey();
                $jwk = JWKFactory::createFromKeyFile(
                    $publicKeyPath,
                    null,
                    [
                        'kid' => $publicKID,
                        'use' => 'sig'
                    ]
                );
                $serializerManager = new JWSSerializerManager([
                    new SignCompactSerializer(),
                ]);

                $jws = $serializerManager->unserialize($token);
                $isVerified = $jwsVerifier->verifyWithKey($jws, $jwk, 0);

                if ($isVerified) {
                    $headerCheckerManager = $payload = null;

                    try {
                        $jwsLoader = new JWSLoader(
                            $serializerManager,
                            $jwsVerifier,
                            $headerCheckerManager
                        );
                    } catch (\Exception $e) {
                        Mage::getSingleton('core/session')->addError($e->getMessage());
                        if (Mage::getSingleton('checkout/session')->getLastRealOrderId()) {
                            if ($lastQuoteId = Mage::getSingleton('checkout/session')->getLastQuoteId()) {
                                $quote = Mage::getModel('sales/quote')->load($lastQuoteId);
                                $quote->setIsActive(true)->save();
                            }
                        }
                        return $this->_redirect('checkout/cart');
                    }

                    $jws = $jwsLoader->loadAndVerifyWithKey($token, $jwk, $signature, $payload);

                    $payload = json_decode($jws->getPayload(), true);

                    if (array_key_exists('merchantUniqueId', $payload)) {
                        $orderId = explode("_", $payload['merchantUniqueId']);
                        $order = Mage::getModel('sales/order')->loadByIncrementId($orderId['0']);

                        if (isset($payload['status']) && $payload['status'] == 'SENT_FOR_CAPTURE') {

                            $payment = $order->getPayment();
                            $transactionID = $order->getIncrementId();
                            $payment->setTransactionId($transactionID);
                            $payment->setLastTransId($transactionID);
                            $payment->setAdditionalInformation('transId', $transactionID);
                            if (array_key_exists('gid', $payload)) {
                                $payment->setAdditionalInformation('gid', $payload['gid']);
                            }
                            if (array_key_exists('status', $payload)) {
                                $payment->setAdditionalInformation('status', $payload['status']);
                            }
                            if (array_key_exists('statusUrl', $payload)) {
                                $payment->setAdditionalInformation('statusUrl', $payload['statusUrl']);
                            }

                            $payment->setAdditionalInformation((array)$payment->getAdditionalInformation());
                            $payment->setParentTransactionId(null);
                            $payment->save();

                            if ($order->canInvoice()) {
                                $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();
                                $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE);
                                $invoice->register();
                                $invoice->getOrder()->setIsInProcess(true);
                                $transactionSave = Mage::getModel('core/resource_transaction')
                                    ->addObject($invoice)
                                    ->addObject($invoice->getOrder());
                                $transactionSave->save();
                            }

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

                        }
                    } else {
                        Mage::getSingleton('core/session')->addError("There is a processing error with your transaction with status. " . $payload["status"]);
                        if (Mage::getSingleton('checkout/session')->getLastRealOrderId()) {
                            if ($lastQuoteId = Mage::getSingleton('checkout/session')->getLastQuoteId()) {
                                $quote = Mage::getModel('sales/quote')->load($lastQuoteId);
                                $quote->setIsActive(true)->save();
                            }
                        }
                        return $this->_redirect('checkout/cart');
                    }
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
