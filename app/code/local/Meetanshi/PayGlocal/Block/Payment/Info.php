<?php

class Meetanshi_PayGlocal_Block_Payment_Info extends Mage_Payment_Block_Info
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('payglocal/info/default.phtml');
    }

    public function getMethodCode()
    {
        return $this->getInfo()->getMethodInstance()->getCode();
    }

    public function toPdf()
    {
        $this->setTemplate('payglocal/info/pdf/default.phtml');
        return $this->toHtml();
    }
}

