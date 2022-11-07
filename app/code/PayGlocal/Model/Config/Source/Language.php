<?php

namespace Meetanshi\PayGlocal\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;

/**
 * Class Language
 * @package Meetanshi\PayGlocal\Model\Config\Source
 */
class Language implements Arrayinterface
{
    /**
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'EN_CA', 'label' => __('English')],
            ['value' => 'FR_CA', 'label' => __('French')]
        ];
    }
}
