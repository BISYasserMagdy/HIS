<?php
/**
 * stripe_config.php
 * ════════════════════════════════════════════════════════════════════════════
 * Stripe API keys. Get TEST keys from https://dashboard.stripe.com/test/apikeys
 * (use TEST keys while developing — they start with sk_test_ / pk_test_ and
 * never move real money). Switch to live keys only when you're ready to accept
 * real payments, and never commit live keys to source control.
 *
 * Place this file in the same Back End/ folder as subscribe_ehr.php and
 * create_payment_intent.php.
 * ════════════════════════════════════════════════════════════════════════════
 */

define('STRIPE_SECRET_KEY', 'sk_test_51TktifGczofZfLq9H0slpdq0uyG75PWnBby4QdCGogV9gdDptIZGqMtMp6AnwEwu0f4Hw5fukUNceIVLdxNrYeoy00juFA4eu6');

// Plan -> price in USD cents. Kept server-side so the amount charged can
// never be tampered with from the browser.
define('EHR_PLAN_PRICES_CENTS', [
    'monthly'    => 500,   // $5.00
    'quarterly'  => 1500,  // $15.00
    'semiannual' => 3000,  // $30.00
    'annual'     => 6000,  // $60.00
]);
