/*browser:true*/
/*global define*/
define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (Component,
              rendererList) {
        'use strict';

        rendererList.push(
            {
                type: 'payglocal',
                component: 'Meetanshi_PayGlocal/js/view/payment/method-renderer/payglocal-payments'
            }
        );

        /** Add view logic here if needed */
        return Component.extend({});
    }
);

