<?php

namespace Meetanshi\PayGlocal\Block\Payment;

use Magento\Payment\Block\ConfigurableInfo;

/**
 * Class Info
 * @package Meetanshi\PayGlocal\Block\Payment
 */
class Info extends ConfigurableInfo
{
    /**
     * @var string
     */
    protected $_template = 'Meetanshi_PayGlocal::info.phtml';

    /**
     * @param string $field
     * @return \Magento\Framework\Phrase|string
     */
    public function getLabel($field)
    {
        switch ($field) {
            case 'gid':
                return __('GID');
            case 'status':
                return __('Status');
            case 'transId':
                return __('Transaction ID');
            default:
                break;
        }
    }
}
