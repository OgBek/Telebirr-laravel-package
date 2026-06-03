<?php
// examples/telebirr-superapp-payment.php

use Bekambeyene\Telebirr\Facades\Telebirr;

/**
 * Initiating a payment when the user is inside the Telebirr SuperApp environment.
 */
function initiateSuperAppPayment()
{
    // The createOrder method automatically detects and formats the payload 
    // for SuperApp payments when used in conjunction with the Telebirr H5 environment.
    $paymentUrl = Telebirr::createOrder('SuperApp Store Purchase', 150.00);
    
    return [
        'status' => 'success',
        'checkout_url' => $paymentUrl
    ];
}
