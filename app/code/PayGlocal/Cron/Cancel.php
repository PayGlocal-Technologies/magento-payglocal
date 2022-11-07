<?php

namespace Meetanshi\PayGlocal\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Store\Model\ScopeInterface;

/**
 * Class Cancel
 * @package Meetanshi\PayGlocal\Cron
 */
class Cancel
{
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;
    /**
     * @var OrderFactory
     */
    protected $orderFactory;
    /**
     * @var CollectionFactory
     */
    protected $collectionFactory;
    /**
     * @var OrderManagementInterface
     */
    protected $orderManagement;

    /**
     * Cancel constructor.
     * @param ScopeConfigInterface $configScopeConfigInterface
     * @param CollectionFactory $collectionFactory
     * @param OrderManagementInterface $orderManagement
     * @param OrderFactory $orderFactory
     */
    public function __construct(
        ScopeConfigInterface $configScopeConfigInterface,
        CollectionFactory $collectionFactory,
        OrderManagementInterface $orderManagement,
        OrderFactory $orderFactory
    )
    {

        $this->scopeConfig = $configScopeConfigInterface;
        $this->orderFactory = $orderFactory;
        $this->collectionFactory = $collectionFactory;
        $this->orderManagement = $orderManagement;
    }

    /**
     *
     */
    public function execute()
    {
        $storeScope = ScopeInterface::SCOPE_STORE;
        $payglocalEnable = $this->scopeConfig->getValue('payment/payglocal/active', $storeScope);
        if ($payglocalEnable) {
            $prev_date = date('Y-m-d', strtotime('-0 days'));
            $collection = $this->collectionFactory->create()
                ->addAttributeToFilter('status', ['in' => ['pending', 'pending_payment']])
                ->addAttributeToFilter('created_at', ['gteq' => $prev_date . ' 00:00:00'])
                ->addAttributeToFilter('created_at', ['lteq' => $prev_date . ' 23:59:59']);
            foreach ($collection as $order) {
                $payment = $order->getPayment();
                $method = $payment->getMethodInstance();

                if ($method->getCode() == "payglocal") {
                    $this->orderManagement->cancel($order->getId());
                }
            }
        }
    }
}
