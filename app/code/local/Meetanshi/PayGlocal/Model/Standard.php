<?php

require_once(Mage::getBaseDir('lib') . '/payglocal/vendor/autoload.php');

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\Converter\StandardConverter;
use Jose\Component\Encryption\Compression\CompressionMethodManager;
use Jose\Component\Encryption\Compression\Deflate;
use Jose\Component\Encryption\JWEBuilder;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\Serializer\CompactSerializer as SignCompactSerializer;
use Jose\Component\Encryption\Serializer\CompactSerializer;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A128CBCHS256;
use Jose\Component\Encryption\Algorithm\KeyEncryption\RSAOAEP256;

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

    public function refund(Varien_Object $payment, $amount)
    {
        try {
            $order = $payment->getOrder();
            $helper = Mage::helper('payglocal');

            $grandTotal = $order->getGrandTotal();
            $currency = $order->getOrderCurrencyCode();
            $additional = $payment->getAdditionalInformation();

            $publicKeyPath = Mage::getBaseDir('media') . '/payglocal/' . $helper->getPublicPem();
            $privateKeyPath = Mage::getBaseDir('media') . '/payglocal/' . $helper->getPrivatePem();
            $publicKID = $helper->getPublicKey();
            $privateKID = $helper->getPrivateKey();
            $merchantID = $helper->getMerchantId();

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

            $header = [
                'issued-by' => $merchantID,
                'enc' => 'A128CBC-HS256',
                'exp' => 30000,
                'iat' => (string)round(microtime(true) * 1000),
                'alg' => 'RSA-OAEP-256',
                'kid' => $publicKID
            ];

            try {
                $key = JWKFactory::createFromKeyFile(
                    $publicKeyPath,
                    null,
                    [
                        'kid' => $publicKID,
                        'use' => 'enc',
                        'alg' => 'RSA-OAEP-256',
                    ]
                );
            } catch (\Exception $e) {
                Mage::throwException($e->getMessage());
            }

            $merchantUniqueId = $helper->generateRandomString(16);

            $refund = 'P';
            if ($amount == $grandTotal) {
                $refund = 'F';
            }

            $payload = json_encode([
                "merchantTxnId" => $helper->generateRandomString(19),
                "merchantUniqueId" => $merchantUniqueId,
                "refundType" => $refund,
                "paymentData" => array(
                    "totalAmount" => number_format($grandTotal, 2),
                    "txnCurrency" => $currency
                ),
                "merchantCallbackURL" => $helper->getAcceptUrl()
            ]);

            try {
                $jwe = $jweBuilder
                    ->create()
                    ->withPayload($payload)
                    ->withSharedProtectedHeader($header)
                    ->addRecipient($key)
                    ->build();
            } catch (\Exception $e) {
                Mage::throwException($e->getMessage());
            }

            $serializer = new CompactSerializer();
            $token = $serializer->serialize($jwe, 0);

            $algorithmManager = new AlgorithmManager([
                new RS256(),
            ]);

            $jwsBuilder = new JWSBuilder(
                $algorithmManager
            );

            $jwskey = JWKFactory::createFromKeyFile(
                $privateKeyPath,
                null,
                [
                    'kid' => $privateKID,
                    'use' => 'sig'
                ]
            );

            $jwsheader = [
                'issued-by' => $merchantID,
                'is-digested' => 'true',
                'alg' => 'RS256',
                'x-gl-enc' => 'true',
                'x-gl-merchantId' => $merchantID,
                'kid' => $privateKID
            ];

            $hashedPayload = base64_encode(hash('sha256', $token, $BinaryOutputMode = true));

            $jwspayload = json_encode([
                'digest' => $hashedPayload,
                'digestAlgorithm' => "SHA-256",
                'exp' => 300000,
                'iat' => (string)round(microtime(true) * 1000)
            ]);

            try {
                $jws = $jwsBuilder
                    ->create()
                    ->withPayload($jwspayload)
                    ->addSignature($jwskey, $jwsheader)
                    ->build();
            } catch (\Exception $e) {
                Mage::throwException($e->getMessage());
            }

            $jwsserializer = new SignCompactSerializer();
            $jwstoken = $jwsserializer->serialize($jws, 0);

            $url = $helper->getPayGlocalCheckoutUrl() . "/" . $additional['gid'] . '/refund';
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $token,
                CURLOPT_HTTPHEADER => array(
                    'x-gl-token-external: ' . $jwstoken,
                    'Content-Type: text/plain'
                ),
            ));
            $response = curl_exec($curl);
            $data = json_decode($response, true);
            curl_close($curl);

            if (isset($data['status']) && $data['status'] == 'SENT_FOR_REFUND') {
                $payment->setTransactionId($data["data"]['merchantTxnId']);
                $transaction = $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_REFUND, null, true, "");
                $transaction->setIsClosed(true);
                return $this;

            } else {
                Mage::throwException('There is a issue with processing your refund - ' . $data['status']);
            }
            Mage::throwException("Error Processing the request");
        } catch (Exception $e) {
            Mage::throwException($e->getMessage());
        }
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
