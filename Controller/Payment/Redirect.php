<?php

namespace Meetanshi\PayGlocal\Controller\Payment;

use Magento\Framework\Exception\LocalizedException;
use Meetanshi\PayGlocal\Controller\Payment as PayGlocalPayment;
use Magento\Sales\Model\Order;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A128CBCHS256;
use Jose\Component\Encryption\Algorithm\KeyEncryption\RSAOAEP256;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\Converter\StandardConverter;
use Jose\Component\Encryption\Compression\CompressionMethodManager;
use Jose\Component\Encryption\Compression\Deflate;
use Jose\Component\Encryption\JWEBuilder;
use Jose\Component\Encryption\Serializer\CompactSerializer;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\Serializer\CompactSerializer as SignCompactSerializer;

/**
 * Class Redirect
 * @package Meetanshi\PayGlocal\Controller\Payment
 */
class Redirect extends PayGlocalPayment
{
    /**
     * @return bool|\Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\Result\Json|\Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $orderIncrementId = $this->checkoutSession->getLastRealOrderId();

        $order = $this->orderFactory->create()->loadByIncrementId($orderIncrementId);

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

        $publicKeyPath = $this->helper->getMediaPath() . 'payglocal/' . $this->helper->getPublicPem();
        $privateKeyPath = $this->helper->getMediaPath() . 'payglocal/' . $this->helper->getPrivatePem();
        $publicKID = $this->helper->getPublicKID();
        $privateKID = $this->helper->getPrivateKID();
        $merchantID = $this->helper->getMerchantID();

        $this->helper->logger('Public Key Path', $publicKeyPath);
        $this->helper->logger('Private Key Path', $privateKeyPath);
        $this->helper->logger('Public KID', $publicKID);
        $this->helper->logger('Private KID', $privateKID);
        $this->helper->logger('Merchant ID', $merchantID);

        $jweKey = JWKFactory::createFromKeyFile(
            $publicKeyPath,
            null,
            [
                'kid' => $publicKID,
                'use' => 'enc',
                'alg' => 'RSA-OAEP-256',
            ]
        );

        $this->helper->logger('JWE Public Key', $jweKey);

        $header = [
            'issued-by' => $merchantID,
            'enc' => 'A128CBC-HS256',
            'exp' => 30000,
            'iat' => (string)round(microtime(true) * 1000),
            'alg' => 'RSA-OAEP-256',
            'kid' => $publicKID
        ];

        $this->helper->logger('JWE Header', $header);

        $merchantUniqueId = $this->generateRandomString(16);

        if (sizeof($order->getBillingAddress()->getStreet()) >= 2) {
            $addressStreetTwo = $order->getBillingAddress()->getStreet()[1];
        } else {
            $addressStreetTwo = "";
        }

        $orderItemData = [];
        foreach ($order->getAllVisibleItems() as $orderItem) {
            $orderItemData[] = [
                "productDescription" => $orderItem->getName(),
                "productSKU" => $orderItem->getSku(),
                "productType" => $orderItem->getProductType(),
                "itemUnitPrice" => round($orderItem->getPrice(),2),
                "itemQuantity" => round($orderItem->getQtyOrdered()),
            ];
        }

        $payload = json_encode([
            "merchantTxnId" => $order->getIncrementId(),
            "merchantUniqueId" => $order->getIncrementId() . '_' . $merchantUniqueId,
            "paymentData" => array(
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
            ),
            "riskData" => [
                "orderData" => $orderItemData,
                "customerData" => [
                    "merchantAssignedCustomerId" => str_pad($order->getCustomerId(),8,"0",STR_PAD_LEFT),
                    "ipAddress" => $order->getRemoteIp(),
                    "httpAccept" => $_SERVER['HTTP_ACCEPT'],
                    "httpUserAgent" => $_SERVER['HTTP_USER_AGENT'],
                ]
            ],
            "merchantCallbackURL" => $this->helper->getCallbackUrl()
        ]);

        $this->helper->logger('JWE Payload', $payload);

        try {
            $jwe = $jweBuilder
                ->create()// We want to create a new JWE
                ->withPayload($payload)// We set the payload
                ->withSharedProtectedHeader($header)
                ->addRecipient($jweKey)
                ->build();
        } catch (\Exception $e) {
            throw new LocalizedException(__($e->getMessage()));
        }

        $this->helper->logger('JWE Builder', $jwe);

        $serializer = new CompactSerializer(); // The serializer
        $jweToken = $serializer->serialize($jwe,
            0); // We serialize the recipient at index 0 (we only have one recipient).

        $this->helper->logger('JWE Token', $jweToken);

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

        $this->helper->logger('JWS Key', $jwsKey);

        $jwsHeader = [
            'issued-by' => $merchantID,
            'is-digested' => 'true',
            'alg' => 'RS256',
            'x-gl-enc' => 'true',
            'x-gl-merchantId' => $merchantID,
            'kid' => $privateKID
        ];

        $this->helper->logger('JWS Header', $jwsHeader);

        $hashedPayload = base64_encode(hash('sha256', $jweToken, $BinaryOutputMode = true));

        $this->helper->logger('JWS Hash Payload', $hashedPayload);

        $jwsPayload = json_encode([
            'digest' => $hashedPayload,
            'digestAlgorithm' => "SHA-256",
            'exp' => 300000,
            'iat' => (string)round(microtime(true) * 1000)
        ]);

        $this->helper->logger('JWS Payload', $jwsPayload);

        try {
            $jws = $jwsBuilder
                ->create()// We want to create a new JWS
                ->withPayload($jwsPayload)// We set the payload
                ->addSignature($jwsKey, $jwsHeader)
                ->build();
        } catch (\Exception $e) {
            throw new LocalizedException(__($e->getMessage()));
        }

        $this->helper->logger('JWS Builder', $jws);

        $jwsSerializer = new SignCompactSerializer(); // The serializer
        $jwsToken = $jwsSerializer->serialize($jws,
            0); // We serialize the recipient at index 0 (we only have one recipient).

        $this->helper->logger('JWs Token', $jwsToken);

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->helper->getGatewayUrl(),
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

        $this->helper->logger('JWS Response', $data);

        curl_close($curl);

        if (isset($data['data']['redirectUrl'])) {
            $resultRedirect = $this->resultRedirectFactory->create();

            $message = 'Customer is redirected to PayGlocal';

            $order->setState(Order::STATE_NEW, true, $message);
            $order->save();

            return $resultRedirect->setUrl($data['data']['redirectUrl']);
        }

        if (isset($data['errors']['displayMessage'])) {
            $error = $data['errors']['displayMessage'];
            if (isset($data['errors']['detailedMessage'])) {
                $error = $error . '' . $data['errors']['detailedMessage'];
            }

            $order->addStatusHistoryComment($error,
                Order::STATE_CANCELED)->setIsCustomerNotified(true);
            $order->cancel();
            $order->save();
            $this->messageManager->addErrorMessage($error);
            $this->checkoutSession->restoreQuote();
            $this->_redirect('checkout/cart');
        }
    }
}
