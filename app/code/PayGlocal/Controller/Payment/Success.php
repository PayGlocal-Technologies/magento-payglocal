<?php

namespace Meetanshi\PayGlocal\Controller\Payment;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction;
use Meetanshi\PayGlocal\Controller\Payment as PayGlocalPayment;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\Converter\StandardConverter;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\Serializer\JWSSerializerManager;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\JWSLoader;

/**
 * Class Success
 * @package Meetanshi\PayGlocal\Controller\Payment
 */
class Success extends PayGlocalPayment implements CsrfAwareActionInterface
{
    /**
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\MailException
     */
    public function execute()
    {
        $params = $this->getRequest()->getParams();
        $this->helper->logger("Success from payglocal", $params);
        if (is_array($params) && !empty($params) && isset($params['x-gl-token'])) {
            $token = $params['x-gl-token'];

            $algorithmManager = new AlgorithmManager([
                new RS256(),
            ]);

            $jwsVerifier = new JWSVerifier(
                $algorithmManager
            );

            $publicKeyPath = $this->helper->getMediaPath() . 'payglocal/' . $this->helper->getPublicPem();
            $publicKID = $this->helper->getPublicKID();

            $jwk = JWKFactory::createFromKeyFile(
                $publicKeyPath,
                // The filename
                null,
                [
                    'kid' => $publicKID,
                    'use' => 'sig'
                ]
            );
            $this->helper->logger('JWK Public Key', $jwk);

            $serializerManager = new JWSSerializerManager([
                new CompactSerializer(),
            ]);

            $jws = $serializerManager->unserialize($token);
            $isVerified = $jwsVerifier->verifyWithKey($jws, $jwk, 0);

            $this->helper->logger('JWK Verification', $isVerified);

            if ($isVerified) {
                $headerCheckerManager = $payload = null;

                try {
                    $jwsLoader = new JWSLoader(
                        $serializerManager,
                        $jwsVerifier,
                        $headerCheckerManager
                    );
                } catch (\Exception $e) {
                    $this->messageManager->addErrorMessage($e->getMessage());
                    $this->checkoutSession->restoreQuote();
                    $this->_redirect('checkout/cart');
                }

                $jws = $jwsLoader->loadAndVerifyWithKey($token, $jwk, $signature, $payload);

                $payload = json_decode($jws->getPayload(), true);
                $this->helper->logger('JWK payload', $payload);
                if (array_key_exists('merchantUniqueId', $payload)) {
                    $orderId = explode("_", $payload['merchantUniqueId']);
                    $order = $this->orderFactory->create()->loadByIncrementId($orderId['0']);
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
                        $trans = $this->transactionBuilder;
                        $transaction = $trans->setPayment($payment)->setOrder($order)->setTransactionId($transactionID)->setAdditionalInformation((array)$payment->getAdditionalInformation())->setFailSafe(true)->build(Transaction::TYPE_CAPTURE);

                        $payment->addTransactionCommentsToOrder($transaction, 'Transaction is approved by the bank');
                        $payment->setParentTransactionId(null);

                        $payment->save();

                        $this->orderSender->notify($order);

                        $order->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);
                        $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);

                        $order->addStatusHistoryComment(__('Transaction is approved by the bank'),
                            Order::STATE_PROCESSING)->setIsCustomerNotified(true);

                        $order->save();

                        $transaction->save();

                        if ($this->helper->isAutoInvoice()) {
                            if (!$order->canInvoice()) {
                                $order->addStatusHistoryComment('Sorry, Order cannot be invoiced.', false);
                            }
                            $invoice = $this->invoiceService->prepareInvoice($order);
                            if (!$invoice) {
                                $order->addStatusHistoryComment('Can\'t generate the invoice right now.', false);
                            }

                            if (!$invoice->getTotalQty()) {
                                $order->addStatusHistoryComment('Can\'t generate an invoice without products.', false);
                            }
                            $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
                            $invoice->register();
                            $invoice->getOrder()->setCustomerNoteNotify(true);
                            $invoice->getOrder()->setIsInProcess(true);
                            $transactionSave = $this->transactionFactory->create()->addObject($invoice)->addObject($invoice->getOrder());
                            $transactionSave->save();

                            try {
                                $this->invoiceSender->send($invoice);
                            } catch (LocalizedException $e) {
                                $order->addStatusHistoryComment('Can\'t send the invoice Email right now.', false);
                            }

                            $order->addStatusHistoryComment('Automatically Invoice Generated.', false);
                            $order->save();
                        }
                        $this->_redirect('checkout/onepage/success');
                    } else {
                        $error = 'There is a processing error with your transaction ' . $payload['status'];
                        $order->addStatusHistoryComment($error,
                            Order::STATE_CANCELED)->setIsCustomerNotified(true);
                        $order->cancel();
                        $order->save();
                        $this->messageManager->addErrorMessage($error);
                        $this->checkoutSession->restoreQuote();
                        $this->_redirect('checkout/cart');
                    }
                } else {
                    $error = 'There is a processing error with your transaction with status ' . $payload['status'];
                    $this->messageManager->addErrorMessage($error);
                    $this->checkoutSession->restoreQuote();
                    $this->_redirect('checkout/cart');
                }

            } else {
                $errorMsg = __('There is a processing error with your Payglocal payment response verification.');
                $this->messageManager->addErrorMessage($errorMsg);
                $this->checkoutSession->restoreQuote();
                $this->_redirect('checkout/cart');
            }
        } else {
            $errorMsg = __('There is a processing error with your Payglocal payment response token.');
            $this->messageManager->addErrorMessage($errorMsg);
            $this->checkoutSession->restoreQuote();
            $this->_redirect('checkout/cart');
        }
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
