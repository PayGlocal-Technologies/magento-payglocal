<?php

namespace Meetanshi\PayGlocal\Model;

use Magento\Framework\Exception\LocalizedException;
use Meetanshi\PayGlocal\Helper\Data as PayGlocalHelper;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Payment\Model\Method\Logger;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Model\OrderFactory;
use Magento\Framework\Url;
use Magento\Directory\Model\RegionFactory;
use Magento\Directory\Model\CountryFactory;
use Magento\Checkout\Model\Session;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Quote\Api\Data\CartInterface;
use Meetanshi\PayGlocal\Block\Payment\Info;
use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Model\Order\Payment\Transaction;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A128CBCHS256;
use Jose\Component\Encryption\Algorithm\KeyEncryption\RSAOAEP256;
use Magento\Framework\App\Bootstrap;
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

/**
 * Class Payment
 * @package Meetanshi\PayGlocal\Model
 */
class Payment extends AbstractMethod
{
    /**
     *
     */
    const CODE = 'payglocal';

    const SANDBOX_URL = 'https://api.dev.payglocal.in/gl/v1/payments';
    const LIVE_URL = 'https://api.payglocal.in/gl/v1/payments';

    /**
     * @var string
     */
    protected $_code = self::CODE;
    /**
     * @var string
     */
    protected $_infoBlockType = Info::class;
    /**
     * @var bool
     */
    protected $_isGateway = true;
    /**
     * @var bool
     */
    protected $_canCapture = true;
    /**
     * @var bool
     */
    protected $_canRefund = true;
    /**
     * @var bool
     */
    protected $_canAuthorize = true;
    /**
     * @var bool
     */
    protected $_canUseInternal = false;

    protected $_canRefundInvoicePartial = true;

    /**
     * @var PayGlocalHelper
     */
    protected $payglocal;

    /**
     * Payment constructor.
     * @param Context $context
     * @param Registry $registry
     * @param ExtensionAttributesFactory $extensionFactory
     * @param AttributeValueFactory $customAttributeFactory
     * @param PaymentHelper $paymentData
     * @param ScopeConfigInterface $scopeConfig
     * @param Logger $logger
     * @param ModuleListInterface $moduleList
     * @param TimezoneInterface $localeDate
     * @param OrderFactory $orderFactory
     * @param Url $urlBuilder
     * @param RegionFactory $region
     * @param CountryFactory $country
     * @param Session $checkoutSession
     * @param StoreManagerInterface $storeManager
     * @param PayGlocalHelper $payglocal
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        PaymentHelper $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        ModuleListInterface $moduleList,
        TimezoneInterface $localeDate,
        OrderFactory $orderFactory,
        Url $urlBuilder,
        RegionFactory $region,
        CountryFactory $country,
        Session $checkoutSession,
        StoreManagerInterface $storeManager,
        PayGlocalHelper $payglocal,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->urlBuilder = $urlBuilder;
        $this->moduleList = $moduleList;
        $this->scopeConfig = $scopeConfig;
        $this->checkoutSession = $checkoutSession;
        $this->storeManager = $storeManager;
        $this->region = $region;
        $this->country = $country;
        $this->logger = $logger;
        $this->payglocal = $payglocal;

        parent::__construct($context, $registry, $extensionFactory, $customAttributeFactory, $paymentData, $scopeConfig,
            $logger, $resource, $resourceCollection, $data);
    }

    /**
     * @param CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(CartInterface $quote = null)
    {
        $available = $this->payglocal->isPaymentAvailable();
        if (!$available) {
            return false;
        } else {
            return parent::isAvailable($quote);
        }
    }

    /**
     * @return string
     */
    public function getOrderPlaceRedirectUrl()
    {
        return $this->urlBuilder->getUrl('payglocal/payment/redirect', ['_secure' => true]);
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

    public function refund(InfoInterface $payment, $amount)
    {
        try {
            $order = $payment->getOrder();
            $grandTotal = $order->getGrandTotal();
            $currency = $order->getOrderCurrencyCode();
            $additional = $payment->getAdditionalInformation();

            $publicKeyPath = $this->payglocal->getMediaPath() . 'payglocal/' . $this->payglocal->getPublicPem();
            $privateKeyPath = $this->payglocal->getMediaPath() . 'payglocal/' . $this->payglocal->getPrivatePem();
            $publicKID = $this->payglocal->getPublicKID();
            $privateKID = $this->payglocal->getPrivateKID();
            $merchantID = $this->payglocal->getMerchantID();

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
                    // The filename
                    null,
                    [
                        'kid' => $publicKID,
                        'use' => 'enc',
                        'alg' => 'RSA-OAEP-256',
                    ]
                );
            } catch (\Exception $e) {
                throw new LocalizedException(__('Key Exception: ' . $e->getMessage()));
            }

            $merchantUniqueId = $this->generateRandomString(16);

            $refund = 'P';
            if ($amount == $grandTotal) {
                $refund = 'F';
            }

            $payload = json_encode([
                "merchantTxnId" => $this->generateRandomString(19),
                "merchantUniqueId" => $merchantUniqueId,
                "refundType" => $refund,
                "paymentData" => array(
                    "totalAmount" => number_format($grandTotal, 2),
                    "txnCurrency" => $currency
                ),
                "merchantCallbackURL" => $this->payglocal->getCallbackUrl()
            ]);

            try {
                $jwe = $jweBuilder
                    ->create()              // We want to create a new JWE
                    ->withPayload($payload) // We set the payload
                    ->withSharedProtectedHeader($header)
                    ->addRecipient($key)
                    ->build();
            } catch (\Exception $e) {
                throw new LocalizedException(__($e->getMessage()));
            }

            $serializer = new CompactSerializer(); // The serializer
            $token = $serializer->serialize($jwe,
                0); // We serialize the recipient at index 0 (we only have one recipient).

            $this->payglocal->logger('JWE Token refund', $token);

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

            $this->payglocal->logger('Refund JWE Hash Payload', $hashedPayload);

            $jwspayload = json_encode([
                'digest' => $hashedPayload,
                'digestAlgorithm' => "SHA-256",
                'exp' => 300000,
                'iat' => (string)round(microtime(true) * 1000)
            ]);

            $this->payglocal->logger('Refund JWS Payload', $jwspayload);

            try {
                $jws = $jwsBuilder
                    ->create()              // We want to create a new JWS
                    ->withPayload($jwspayload) // We set the payload
                    ->addSignature($jwskey, $jwsheader)
                    ->build();
            } catch (\Exception $e) {
                throw new LocalizedException(__($e->getMessage()));
            }

            $jwsserializer = new SignCompactSerializer(); // The serializer
            $jwstoken = $jwsserializer->serialize($jws,
                0); // We serialize the recipient at index 0 (we only have one recipient).

            $this->payglocal->logger('Refund JWS Token', $jwstoken);

            if ($this->payglocal->getMode()) {
                $url = self::SANDBOX_URL . '/' . $additional['gid'] . '/refund';
            } else {
                $url = self::LIVE_URL . '/' . $additional['gid'] . '/refund';
            }

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

            $this->payglocal->logger('Refund Payglocal Response', $data);

            curl_close($curl);

            if (isset($data['status']) && $data['status'] == 'SENT_FOR_REFUND') {
                $payment->setParentTransactionId(rand(0,
                        1000) . '-' . Transaction::TYPE_REFUND)->setIsTransactionClosed(true)->registerRefundNotification($amount);
            } else {
                throw new LocalizedException(__('There is a issue with processing your refund - ' . $data['status']));
                return;
            }
        } catch (\Exception $e) {
            throw new LocalizedException(__('Refund Exception: ' . $e->getMessage()));
        }
    }
}
