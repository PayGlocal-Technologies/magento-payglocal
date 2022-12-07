<?php

namespace Meetanshi\PayGlocal\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;

/**
 * Class PaymentType
 * @package Meetanshi\PayGlocal\Model\Config\Source
 */
class PaymentType implements Arrayinterface
{
    /**
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => '', 'label' => __('Do not pass value')],
            ['value' => 'DB', 'label' => __('DB')],
            ['value' => 'CC', 'label' => __('CC')]
        ];
    }
}
