<?php
/**
 * QuickBooks Payments Module V1.0 - Language Definitions
 * ZenCart 2.x Format (Array-based)
 *
 * @package languageDefines
 * @version 1.0
 */

$cc_owner_min = defined('CC_OWNER_MIN_LENGTH') ? CC_OWNER_MIN_LENGTH : 3;
$cc_number_min = defined('CC_NUMBER_MIN_LENGTH') ? CC_NUMBER_MIN_LENGTH : 13;

$define = [
    'MODULE_PAYMENT_QUICKBOOKS_TEXT_ADMIN_TITLE' => 'QuickBooks Payments',
    'MODULE_PAYMENT_QUICKBOOKS_TEXT_DESCRIPTION' => '<strong>QuickBooks Payments Gateway</strong> (v1.0)<br /><br /><strong>Requirements:</strong><br />1. QuickBooks Payments Client ID and Client Secret from <a href="https://developer.intuit.com" target="_blank">Intuit Developer Portal</a><br />2. OAuth Access Token and Refresh Token<br />3. cURL must be enabled with SSL support.<br /><br /><strong>For assistance, visit developer.intuit.com</strong>',
    'MODULE_PAYMENT_QUICKBOOKS_TEXT_CATALOG_TITLE' => 'Credit Card',
    'MODULE_PAYMENT_QUICKBOOKS_TEXT_CREDIT_CARD_TYPE' => 'Credit Card Type:',
    'MODULE_PAYMENT_QUICKBOOKS_TEXT_CREDIT_CARD_OWNER' => 'Credit Card Owner:',
    'MODULE_PAYMENT_QUICKBOOKS_TEXT_CREDIT_CARD_NUMBER' => 'Credit Card Number:',
    'MODULE_PAYMENT_QUICKBOOKS_TEXT_CREDIT_CARD_EXPIRES' => 'Credit Card Expiry Date:',
    'MODULE_PAYMENT_QUICKBOOKS_TEXT_CVV' => 'Card Verification Number:',
    'MODULE_PAYMENT_QUICKBOOKS_TEXT_POPUP_CVV_LINK' => 'What\'s this?',
    'MODULE_PAYMENT_QUICKBOOKS_TEXT_JS_CC_OWNER' => '* The owner\'s name of the credit card must be at least ' . $cc_owner_min . ' characters.\n',
    'MODULE_PAYMENT_QUICKBOOKS_TEXT_JS_CC_NUMBER' => '* The credit card number must be at least ' . $cc_number_min . ' characters.\n',
    'MODULE_PAYMENT_QUICKBOOKS_TEXT_JS_CC_CVV' => '* The 3 or 4 digit CVV number must be entered from the back of the credit card.\n',
    'MODULE_PAYMENT_QUICKBOOKS_TEXT_DECLINED_MESSAGE' => 'Your credit card could not be authorized.',
    'MODULE_PAYMENT_QUICKBOOKS_TEXT_DECLINED_POST_AUTH_MESSAGE' => 'The credit card capture (post-auth) was declined. The response from the processing center is',
    'MODULE_PAYMENT_QUICKBOOKS_TEXT_NO_AUTHORIZED_RECORD' => 'Cannot find the pre-authorization record for this order.',
    'MODULE_PAYMENT_QUICKBOOKS_TEXT_POSTAUTH_RECORD_EXISTS' => 'The credit card has already been captured for this order.',
    'MODULE_PAYMENT_QUICKBOOKS_TEXT_TOKEN_ERROR' => 'Payment processing error: Unable to authenticate with payment gateway. Please try again or contact support.',
    'MODULE_PAYMENT_QUICKBOOKS_TEXT_ERROR' => 'Credit Card Error!',
    'MODULE_PAYMENT_QUICKBOOKS_TEXT_TRANS_COMMENT' => 'Credit Card payment. AUTH: ',
    'MODULE_PAYMENT_QUICKBOOKS_TEXT_POST_AUTH_SUCCESS' => 'Post-Authorization (capture) credit card payment. AUTH Code: ',

    // Customer-friendly decline reason messages
    'MODULE_PAYMENT_QUICKBOOKS_DECLINE_GENERAL' => 'Your card was declined. Please try a different card or contact your bank.',
    'MODULE_PAYMENT_QUICKBOOKS_DECLINE_INVALID_CARD' => 'The card number entered is invalid. Please check and re-enter your card details.',
    'MODULE_PAYMENT_QUICKBOOKS_DECLINE_EXPIRED_CARD' => 'Your card has expired. Please use a different card.',
    'MODULE_PAYMENT_QUICKBOOKS_DECLINE_CVV_MISMATCH' => 'The security code (CVV) does not match. Please verify and re-enter.',
    'MODULE_PAYMENT_QUICKBOOKS_DECLINE_AVS_MISMATCH' => 'The billing address does not match the card. Please verify your billing address.',
    'MODULE_PAYMENT_QUICKBOOKS_DECLINE_AVS_STREET_MISMATCH' => 'The street address does not match the card on file. Please verify your billing address.',
    'MODULE_PAYMENT_QUICKBOOKS_DECLINE_AVS_ZIP_MISMATCH' => 'The ZIP code does not match the card on file. Please verify your billing ZIP code.',
    'MODULE_PAYMENT_QUICKBOOKS_DECLINE_INSUFFICIENT_FUNDS' => 'Insufficient funds. Please try a different card or contact your bank.',
    'MODULE_PAYMENT_QUICKBOOKS_DECLINE_LIMIT_EXCEEDED' => 'Transaction limit exceeded. Please try a smaller amount or contact your bank.',
    'MODULE_PAYMENT_QUICKBOOKS_DECLINE_CARD_NOT_SUPPORTED' => 'This card type is not supported. Please try a different card.',
    'MODULE_PAYMENT_QUICKBOOKS_DECLINE_DUPLICATE' => 'This appears to be a duplicate transaction. Please wait a moment before trying again.',
    'MODULE_PAYMENT_QUICKBOOKS_DECLINE_GATEWAY_ERROR' => 'A temporary error occurred. Please try again in a few minutes.',
    'MODULE_PAYMENT_QUICKBOOKS_DECLINE_FRAUD' => 'This transaction could not be completed. Please contact your bank or try a different card.',
];

return $define;
