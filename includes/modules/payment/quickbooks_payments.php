<?php
/**
 * QuickBooks Payments Module V1.0
 * For ZenCart V2.1.0
 *
 * @package paymentMethod
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

/**
 * QuickBooks Payments Module
 * You must have SSL active on your server to be compliant with merchant TOS
 */
class quickbooks_payments extends base {
    /**
     * $code determines the internal 'code' name used to designate "this" payment module
     * @var string
     */
    var $code;
    /**
     * $title is the displayed name for this payment method
     * @var string
     */
    var $title;
    /**
     * $description is a soft name for this payment method
     * @var string
     */
    var $description;
    /**
     * $enabled determines whether this module shows or not... in catalog.
     * @var boolean
     */
    var $enabled;
    /**
     * $response tracks response information returned from the QuickBooks gateway
     * @var string/array
     */
    var $response;
    /**
     * log file folder
     */
    var $_logDir = '';
    /**
     * order id
     */
    var $order_id;

    public $sort_order, $form_action_url, $order_status, $_check;
    public $cc_card_type, $cc_card_number, $cc_expiry_month, $cc_expiry_year;
    public $auth_code;
    public $_charge_request_id = '';

    /**
     * Constructor
     */
    function __construct() {
        global $order;
        $this->code = 'quickbooks_payments';

        // Check if module is installed (constants defined)
        $is_installed = defined('MODULE_PAYMENT_QUICKBOOKS_STATUS');

        if (IS_ADMIN_FLAG === true) {
            // Payment module title in Admin
            $this->title = defined('MODULE_PAYMENT_QUICKBOOKS_TEXT_ADMIN_TITLE') ? MODULE_PAYMENT_QUICKBOOKS_TEXT_ADMIN_TITLE : 'QuickBooks Payments';
            if ($is_installed && MODULE_PAYMENT_QUICKBOOKS_STATUS == 'True') {
                $client_id = defined('MODULE_PAYMENT_QUICKBOOKS_CLIENT_ID') ? MODULE_PAYMENT_QUICKBOOKS_CLIENT_ID : '';
                $client_secret = defined('MODULE_PAYMENT_QUICKBOOKS_CLIENT_SECRET') ? $this->decryptValue(MODULE_PAYMENT_QUICKBOOKS_CLIENT_SECRET) : '';
                if ($client_id == '' || $client_secret == '') {
                    $this->title .= '<span class="alert"> (Not Configured)</span>';
                }
            } elseif ($is_installed && MODULE_PAYMENT_QUICKBOOKS_STATUS == 'False') {
                $this->title .= '<span class="alert"> (Not Enabled)</span>';
            }
        } else {
            $this->title = defined('MODULE_PAYMENT_QUICKBOOKS_TEXT_CATALOG_TITLE') ? MODULE_PAYMENT_QUICKBOOKS_TEXT_CATALOG_TITLE : 'Credit Card';
        }
        $this->description = defined('MODULE_PAYMENT_QUICKBOOKS_TEXT_DESCRIPTION') ? MODULE_PAYMENT_QUICKBOOKS_TEXT_DESCRIPTION : 'QuickBooks Payments Gateway';
        $this->enabled = ($is_installed && MODULE_PAYMENT_QUICKBOOKS_STATUS == 'True') ? true : false;
        $this->sort_order = defined('MODULE_PAYMENT_QUICKBOOKS_SORT_ORDER') ? MODULE_PAYMENT_QUICKBOOKS_SORT_ORDER : 0;
        $this->form_action_url = zen_href_link(FILENAME_CHECKOUT_PROCESS, '', 'SSL', false);

        if (defined('MODULE_PAYMENT_QUICKBOOKS_ORDER_STATUS_ID') && (int)MODULE_PAYMENT_QUICKBOOKS_ORDER_STATUS_ID > 0) {
            $this->order_status = MODULE_PAYMENT_QUICKBOOKS_ORDER_STATUS_ID;
        }

        // Set log directory
        $this->_logDir = defined('DIR_FS_SQL_CACHE') ? DIR_FS_SQL_CACHE : '/tmp';

        // Auto-add missing configuration options (for upgrades)
        if ($is_installed) {
            $this->checkAndAddMissingConfig();
        }

        if (is_object($order)) $this->update_status();
    }

    /**
     * Check for and add any missing configuration options (used during module upgrades)
     */
    function checkAndAddMissingConfig() {
        global $db;

        // Define new config options that may not exist in older installations
        $new_configs = array(
            'MODULE_PAYMENT_QUICKBOOKS_DECLINE_EMAIL' => array(
                'title' => 'Decline Email Notification',
                'value' => 'True',
                'description' => 'Send an email notification when a transaction is declined?',
                'set_function' => "zen_cfg_select_option(array('True', 'False'), "
            ),
            'MODULE_PAYMENT_QUICKBOOKS_AVS_CHECK' => array(
                'title' => 'Require AVS Match',
                'value' => 'True',
                'description' => 'Reject transactions where Address Verification (AVS) fails? This adds an extra layer of fraud protection.',
                'set_function' => "zen_cfg_select_option(array('True', 'False'), "
            )
        );

        foreach ($new_configs as $key => $config) {
            if (!defined($key)) {
                $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) VALUES ('" . zen_db_input($config['title']) . "', '" . zen_db_input($key) . "', '" . zen_db_input($config['value']) . "', '" . zen_db_input($config['description']) . "', '6', '0', '" . zen_db_input($config['set_function']) . "', now())");
            }
        }

        // Add request_id index if missing (needed for after_process UPDATE correlation)
        $index_check = $db->Execute("SHOW INDEX FROM quickbooks_payments WHERE Key_name = 'request_id'");
        if ($index_check->RecordCount() == 0) {
            $db->Execute("ALTER TABLE quickbooks_payments ADD INDEX request_id (request_id)");
        }

        // Auto-encrypt any plain-text OAuth tokens (migrates existing installations)
        $this->encryptStoredTokens();
    }

    /**
     * Calculate zone matches and flag settings to determine whether this module should display to customers or not
     */
    function update_status() {
        global $order, $db;

        if (($this->enabled == true) && defined('MODULE_PAYMENT_QUICKBOOKS_ZONE') && ((int)MODULE_PAYMENT_QUICKBOOKS_ZONE > 0)) {
            $check_flag = false;
            $check = $db->Execute("select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where geo_zone_id = '" . (int)MODULE_PAYMENT_QUICKBOOKS_ZONE . "' and zone_country_id = '" . (int)$order->billing['country']['id'] . "' order by zone_id");
            while (!$check->EOF) {
                if ($check->fields['zone_id'] < 1) {
                    $check_flag = true;
                    break;
                } elseif ($check->fields['zone_id'] == $order->billing['zone_id']) {
                    $check_flag = true;
                    break;
                }
                $check->MoveNext();
            }

            if ($check_flag == false) {
                $this->enabled = false;
            }
        }
    }

    /**
     * JS validation which does error-checking of data-entry if this module is selected for use
     * @return string
     */
    function javascript_validation() {
        $cc_owner_min = defined('CC_OWNER_MIN_LENGTH') ? CC_OWNER_MIN_LENGTH : 3;
        $cc_number_min = defined('CC_NUMBER_MIN_LENGTH') ? CC_NUMBER_MIN_LENGTH : 13;
        $use_cvv = defined('MODULE_PAYMENT_QUICKBOOKS_USE_CVV') && MODULE_PAYMENT_QUICKBOOKS_USE_CVV == 'True';
        $js_cc_owner = defined('MODULE_PAYMENT_QUICKBOOKS_TEXT_JS_CC_OWNER') ? MODULE_PAYMENT_QUICKBOOKS_TEXT_JS_CC_OWNER : '* Please enter the credit card owner name.\n';
        $js_cc_number = defined('MODULE_PAYMENT_QUICKBOOKS_TEXT_JS_CC_NUMBER') ? MODULE_PAYMENT_QUICKBOOKS_TEXT_JS_CC_NUMBER : '* Please enter a valid credit card number.\n';
        $js_cc_cvv = defined('MODULE_PAYMENT_QUICKBOOKS_TEXT_JS_CC_CVV') ? MODULE_PAYMENT_QUICKBOOKS_TEXT_JS_CC_CVV : '* Please enter the CVV number.\n';

        $js = '  if (payment_value == "' . $this->code . '") {' . "\n" .
            '    var cc_owner = document.checkout_payment.cc_owner.value;' . "\n" .
            '    var cc_number = document.checkout_payment.cc_number.value.replace(/\\s/g, "");' . "\n";
        if ($use_cvv) {
            $js .= '    var cc_cvv = document.checkout_payment.cc_cvv.value;' . "\n";
        }
        $js .= '    if (cc_owner == "" || cc_owner.length < ' . $cc_owner_min . ') {' . "\n" .
            '      error_message = error_message + "' . $js_cc_owner . '";' . "\n" .
            '      error = 1;' . "\n" .
            '    }' . "\n" .
            '    if (cc_number == "" || cc_number.length < ' . $cc_number_min . ') {' . "\n" .
            '      error_message = error_message + "' . $js_cc_number . '";' . "\n" .
            '      error = 1;' . "\n" .
            '    }' . "\n";
        if ($use_cvv) {
            $js .= '    if (cc_cvv == "" || cc_cvv.length < 3 || cc_cvv.length > 4) {' . "\n" .
                '      error_message = error_message + "' . $js_cc_cvv . '";' . "\n" .
                '      error = 1;' . "\n" .
                '    }' . "\n";
        }
        $js .= '  }' . "\n";

        return $js;
    }

    /**
     * Display Credit Card Information Submission Fields on the Checkout Payment Page
     * @return array
     */
    function selection() {
        global $order;

        $use_cvv = defined('MODULE_PAYMENT_QUICKBOOKS_USE_CVV') && MODULE_PAYMENT_QUICKBOOKS_USE_CVV == 'True';
        $catalog_title = defined('MODULE_PAYMENT_QUICKBOOKS_TEXT_CATALOG_TITLE') ? MODULE_PAYMENT_QUICKBOOKS_TEXT_CATALOG_TITLE : 'Credit Card';
        $text_cc_owner = defined('MODULE_PAYMENT_QUICKBOOKS_TEXT_CREDIT_CARD_OWNER') ? MODULE_PAYMENT_QUICKBOOKS_TEXT_CREDIT_CARD_OWNER : 'Credit Card Owner:';
        $text_cc_number = defined('MODULE_PAYMENT_QUICKBOOKS_TEXT_CREDIT_CARD_NUMBER') ? MODULE_PAYMENT_QUICKBOOKS_TEXT_CREDIT_CARD_NUMBER : 'Credit Card Number:';
        $text_cc_expires = defined('MODULE_PAYMENT_QUICKBOOKS_TEXT_CREDIT_CARD_EXPIRES') ? MODULE_PAYMENT_QUICKBOOKS_TEXT_CREDIT_CARD_EXPIRES : 'Expiry Date:';
        $text_cvv = defined('MODULE_PAYMENT_QUICKBOOKS_TEXT_CVV') ? MODULE_PAYMENT_QUICKBOOKS_TEXT_CVV : 'CVV:';
        $text_cvv_link = defined('MODULE_PAYMENT_QUICKBOOKS_TEXT_POPUP_CVV_LINK') ? MODULE_PAYMENT_QUICKBOOKS_TEXT_POPUP_CVV_LINK : 'What\'s this?';

        $expires_month = [];
        for ($i = 1; $i < 13; $i++) {
            $expires_month[] = array('id' => sprintf('%02d', $i), 'text' => date('M - (m)', mktime(0, 0, 0, $i, 1, 2000)));
        }

        $expires_year = [];
        $today = getdate();
        for ($i = $today['year']; $i < $today['year'] + 10; $i++) {
            $expires_year[] = array('id' => date('y', mktime(0, 0, 0, 1, 1, $i)), 'text' => date('Y', mktime(0, 0, 0, 1, 1, $i)));
        }
        $onFocus = ' onfocus="methodSelect(\'pmt-' . $this->code . '\')"';

        // Checkout enhancements: card formatting, type detection, and inline validation
        $checkout_js = '<script>
document.addEventListener("DOMContentLoaded", function() {
    var ccNum = document.getElementById("cc_number");
    var ccCvv = document.getElementById("cc_cvv");
    var ccOwner = document.getElementById("cc_owner");
    var cardPatterns = {Visa:/^4/, Mastercard:/^(5[1-5]|2[2-7])/, Amex:/^3[47]/, Discover:/^(6011|65|64[4-9])/};

    if (ccNum) {
        var wrapper = document.createElement("div");
        wrapper.style.cssText = "position:relative;display:inline-block;width:100%";
        ccNum.parentNode.insertBefore(wrapper, ccNum);
        wrapper.appendChild(ccNum);
        var badge = document.createElement("span");
        badge.id = "qb-card-type";
        badge.style.cssText = "display:none;position:absolute;right:8px;top:50%;transform:translateY(-50%);padding:2px 8px;border-radius:4px;font-size:11px;font-weight:bold;color:#fff;background:#1a73e8;pointer-events:none";
        wrapper.appendChild(badge);
        ccNum.style.paddingRight = "70px";
    }

    function luhnCheck(num) {
        var sum = 0, alt = false;
        for (var i = num.length - 1; i >= 0; i--) {
            var d = parseInt(num[i], 10);
            if (alt) { d *= 2; if (d > 9) d -= 9; }
            sum += d; alt = !alt;
        }
        return sum % 10 === 0;
    }

    function setStatus(el, state) {
        if (!el) return;
        el.style.transition = "border-color 0.3s, box-shadow 0.3s";
        if (state === "valid") {
            el.style.borderColor = "#28a745";
            el.style.boxShadow = "0 0 0 2px rgba(40,167,69,0.2)";
        } else if (state === "invalid") {
            el.style.borderColor = "#dc3545";
            el.style.boxShadow = "0 0 0 2px rgba(220,53,69,0.2)";
        } else {
            el.style.borderColor = "";
            el.style.boxShadow = "";
        }
    }

    if (ccNum) {
        ccNum.addEventListener("input", function() {
            var cursorPos = this.selectionStart;
            var digitsBeforeCursor = this.value.substring(0, cursorPos).replace(/\D/g, "").length;
            var raw = this.value.replace(/\D/g, "");
            var type = "";
            for (var k in cardPatterns) {
                if (cardPatterns[k].test(raw)) { type = k; break; }
            }
            var maxLen = (type === "Amex") ? 15 : 16;
            raw = raw.substring(0, maxLen);
            var f = "";
            if (type === "Amex") {
                for (var i = 0; i < raw.length; i++) {
                    if (i === 4 || i === 10) f += " ";
                    f += raw[i];
                }
            } else {
                for (var i = 0; i < raw.length; i++) {
                    if (i > 0 && i % 4 === 0) f += " ";
                    f += raw[i];
                }
            }
            this.value = f;
            var newPos = 0, count = 0;
            for (var i = 0; i < f.length && count < digitsBeforeCursor; i++) {
                if (f[i] !== " ") count++;
                newPos = i + 1;
            }
            this.setSelectionRange(newPos, newPos);
            var badge = document.getElementById("qb-card-type");
            if (badge) {
                badge.textContent = type;
                badge.style.display = type ? "inline-block" : "none";
            }
            if (raw.length >= 13) {
                setStatus(this, luhnCheck(raw) ? "valid" : "invalid");
            } else {
                setStatus(this, null);
            }
        });
    }

    if (ccCvv) {
        ccCvv.addEventListener("input", function() {
            this.value = this.value.replace(/\D/g, "");
            setStatus(this, this.value.length >= 3 ? "valid" : null);
        });
    }

    if (ccOwner) {
        ccOwner.addEventListener("blur", function() {
            setStatus(this, this.value.trim().length >= 3 ? "valid" : null);
        });
    }
});
</script>';

        if ($use_cvv) {
            $selection = array('id' => $this->code,
                'module' => $catalog_title,
                'fields' => array(
                    array('title' => $text_cc_owner,
                        'field' => zen_draw_input_field('cc_owner', $order->billing['firstname'] . ' ' . $order->billing['lastname'], 'id="cc_owner" autocomplete="cc-name"' . $onFocus),
                        'tag' => 'cc_owner'),
                    array('title' => $text_cc_number,
                        'field' => zen_draw_input_field('cc_number', '', 'id="cc_number" inputmode="numeric" pattern="[0-9]*" maxlength="19" autocomplete="off"' . $onFocus),
                        'tag' => 'cc_number'),
                    array('title' => $text_cc_expires,
                        'field' => zen_draw_pull_down_menu('cc_expires_month', $expires_month, '', 'id="cc_expires_month"' . $onFocus) . '&nbsp;' . zen_draw_pull_down_menu('cc_expires_year', $expires_year, '', 'id="cc_expires_year"' . $onFocus),
                        'tag' => 'cc_expires_month'),
                    array('title' => $text_cvv,
                        'field' => zen_draw_input_field('cc_cvv', '', 'size="4" maxlength="4" id="cc_cvv" inputmode="numeric" pattern="[0-9]*" autocomplete="off"' . $onFocus) . ' ' . '<a href="javascript:popupWindow(\'' . zen_href_link(FILENAME_POPUP_CVV_HELP) . '\')">' . $text_cvv_link . '</a>',
                        'tag' => 'cc_cvv'),
                    array('title' => '',
                        'field' => $checkout_js,
                        'tag' => '')
                ));
        } else {
            $selection = array('id' => $this->code,
                'module' => $catalog_title,
                'fields' => array(
                    array('title' => $text_cc_owner,
                        'field' => zen_draw_input_field('cc_owner', $order->billing['firstname'] . ' ' . $order->billing['lastname'], 'id="cc_owner" autocomplete="cc-name"' . $onFocus),
                        'tag' => 'cc_owner'),
                    array('title' => $text_cc_number,
                        'field' => zen_draw_input_field('cc_number', '', 'id="cc_number" inputmode="numeric" pattern="[0-9]*" maxlength="19" autocomplete="off"' . $onFocus),
                        'tag' => 'cc_number'),
                    array('title' => $text_cc_expires,
                        'field' => zen_draw_pull_down_menu('cc_expires_month', $expires_month, '', 'id="cc_expires_month"' . $onFocus) . '&nbsp;' . zen_draw_pull_down_menu('cc_expires_year', $expires_year, '', 'id="cc_expires_year"' . $onFocus),
                        'tag' => 'cc_expires_month'),
                    array('title' => '',
                        'field' => $checkout_js,
                        'tag' => '')
                ));
        }

        return $selection;
    }

    /**
     * Evaluates the Credit Card Type for acceptance and the validity of the Credit Card Number & Expiration Date
     */
    function pre_confirmation_check() {
        global $_POST, $messageStack;
        include(DIR_FS_CATALOG . DIR_WS_CLASSES . 'cc_validation.php');

        // Strip spaces from formatted card number
        $_POST['cc_number'] = str_replace(' ', '', $_POST['cc_number']);

        $cc_validation = new cc_validation();
        $result = $cc_validation->validate($_POST['cc_number'], $_POST['cc_expires_month'], $_POST['cc_expires_year'], $_POST['cc_cvv'] ?? '');
        $error = '';
        switch ($result) {
            case -1:
                $error = sprintf(TEXT_CCVAL_ERROR_UNKNOWN_CARD, substr($cc_validation->cc_number, 0, 4));
                break;
            case -2:
            case -3:
            case -4:
                $error = TEXT_CCVAL_ERROR_INVALID_DATE;
                break;
            case false:
                $error = TEXT_CCVAL_ERROR_INVALID_NUMBER;
                break;
        }

        if (($result == false) || ($result < 1)) {
            $payment_error_return = 'payment_error=' . $this->code . '&cc_owner=' . urlencode($_POST['cc_owner']) . '&cc_expires_month=' . $_POST['cc_expires_month'] . '&cc_expires_year=' . $_POST['cc_expires_year'];
            $messageStack->add_session('checkout_payment', $error . '<!-- [' . $this->code . '] -->', 'error');
            zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, $payment_error_return, 'SSL', true, false));
        }

        $this->cc_card_type = $cc_validation->cc_type;
        $this->cc_card_number = $cc_validation->cc_number;
        $this->cc_expiry_month = $cc_validation->cc_expiry_month;
        $this->cc_expiry_year = $cc_validation->cc_expiry_year;
    }

    /**
     * Display Credit Card Information on the Checkout Confirmation Page
     * @return array
     */
    function confirmation() {
        global $_POST;

        $use_cvv = defined('MODULE_PAYMENT_QUICKBOOKS_USE_CVV') && MODULE_PAYMENT_QUICKBOOKS_USE_CVV == 'True';
        $text_cc_type = defined('MODULE_PAYMENT_QUICKBOOKS_TEXT_CREDIT_CARD_TYPE') ? MODULE_PAYMENT_QUICKBOOKS_TEXT_CREDIT_CARD_TYPE : 'Card Type:';
        $text_cc_owner = defined('MODULE_PAYMENT_QUICKBOOKS_TEXT_CREDIT_CARD_OWNER') ? MODULE_PAYMENT_QUICKBOOKS_TEXT_CREDIT_CARD_OWNER : 'Card Owner:';
        $text_cc_number = defined('MODULE_PAYMENT_QUICKBOOKS_TEXT_CREDIT_CARD_NUMBER') ? MODULE_PAYMENT_QUICKBOOKS_TEXT_CREDIT_CARD_NUMBER : 'Card Number:';
        $text_cc_expires = defined('MODULE_PAYMENT_QUICKBOOKS_TEXT_CREDIT_CARD_EXPIRES') ? MODULE_PAYMENT_QUICKBOOKS_TEXT_CREDIT_CARD_EXPIRES : 'Expiry:';
        $text_cvv = defined('MODULE_PAYMENT_QUICKBOOKS_TEXT_CVV') ? MODULE_PAYMENT_QUICKBOOKS_TEXT_CVV : 'CVV:';

        if ($use_cvv) {
            $confirmation = array(
                'fields' => array(
                    array('title' => $text_cc_type,
                        'field' => $this->cc_card_type),
                    array('title' => $text_cc_owner,
                        'field' => htmlspecialchars($_POST['cc_owner'], ENT_QUOTES, 'UTF-8')),
                    array('title' => $text_cc_number,
                        'field' => substr($this->cc_card_number, 0, 4) . str_repeat('X', max(0, strlen($this->cc_card_number) - 8)) . substr($this->cc_card_number, -4)),
                    array('title' => $text_cc_expires,
                        'field' => date('M, Y', mktime(0, 0, 0, $_POST['cc_expires_month'], 1, '20' . $_POST['cc_expires_year']))),
                    array('title' => $text_cvv,
                        'field' => str_repeat('*', strlen($_POST['cc_cvv'] ?? '')))
                ));
        } else {
            $confirmation = array(
                'fields' => array(
                    array('title' => $text_cc_type,
                        'field' => $this->cc_card_type),
                    array('title' => $text_cc_owner,
                        'field' => htmlspecialchars($_POST['cc_owner'], ENT_QUOTES, 'UTF-8')),
                    array('title' => $text_cc_number,
                        'field' => substr($this->cc_card_number, 0, 4) . str_repeat('X', max(0, strlen($this->cc_card_number) - 8)) . substr($this->cc_card_number, -4)),
                    array('title' => $text_cc_expires,
                        'field' => date('M, Y', mktime(0, 0, 0, $_POST['cc_expires_month'], 1, '20' . $_POST['cc_expires_year'])))
                ));
        }

        return $confirmation;
    }

    /**
     * Build the data and actions to process when the "Submit" button is pressed on the order-confirmation screen.
     * @return string
     */
    function process_button() {
        $use_cvv = defined('MODULE_PAYMENT_QUICKBOOKS_USE_CVV') && MODULE_PAYMENT_QUICKBOOKS_USE_CVV == 'True';

        // Store sensitive card data in session (encrypted) instead of hidden form fields (PCI-DSS compliance)
        $_SESSION['qbp_cc_number'] = $this->encryptValue($this->cc_card_number);
        if ($use_cvv) {
            $_SESSION['qbp_cc_cvv'] = $this->encryptValue($_POST['cc_cvv']);
        }

        $process_button_string = zen_draw_hidden_field('cc_owner', $_POST['cc_owner']) .
            zen_draw_hidden_field('cc_expires', $this->cc_expiry_month . substr($this->cc_expiry_year, -2)) .
            zen_draw_hidden_field('cc_expires_month', $this->cc_expiry_month) .
            zen_draw_hidden_field('cc_expires_year', $this->cc_expiry_year) .
            zen_draw_hidden_field('cc_type', $this->cc_card_type);

        $process_button_string .= zen_draw_hidden_field(zen_session_name(), zen_session_id());

        return $process_button_string;
    }

    /**
     * Get a valid access token, refreshing if necessary
     * @return string|false
     */
    function getAccessToken() {
        global $db;

        $access_token = $this->decryptValue(MODULE_PAYMENT_QUICKBOOKS_ACCESS_TOKEN);
        $token_expiry = defined('MODULE_PAYMENT_QUICKBOOKS_TOKEN_EXPIRY') ? MODULE_PAYMENT_QUICKBOOKS_TOKEN_EXPIRY : 0;

        // Check if token is expired or will expire in the next 5 minutes
        if (time() >= ($token_expiry - 300)) {
            // Acquire database lock to prevent concurrent refresh attempts
            $lock_result = $db->Execute("SELECT GET_LOCK('qb_token_refresh', 10) as locked");
            $locked = $lock_result->fields['locked'];

            // GET_LOCK returns: 1 = acquired, 0 = timeout (held by another), NULL = error
            if ($locked === null) {
                $this->logError('Token Refresh', 'GET_LOCK returned NULL (MySQL error). Cannot safely refresh token.');
                return false;
            }

            if ($locked == 0) {
                // Another process held the lock for the full timeout — verify the token is actually fresh
                $expiry_row = $db->Execute("SELECT configuration_value FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = 'MODULE_PAYMENT_QUICKBOOKS_TOKEN_EXPIRY'");
                if (time() < ((int)$expiry_row->fields['configuration_value'] - 300)) {
                    $fresh_token = $db->Execute("SELECT configuration_value FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = 'MODULE_PAYMENT_QUICKBOOKS_ACCESS_TOKEN'");
                    return $this->decryptValue($fresh_token->fields['configuration_value']);
                }
                // Token still expired after waiting — surface the error rather than use a stale token
                $this->logError('Token Refresh', 'Lock timeout and token still expired. Another process may have failed to refresh.');
                return false;
            }

            // Lock acquired — wrap in try/finally to guarantee lock release
            try {
                // Double-check: another process may have refreshed while we waited for the lock
                $expiry_check = $db->Execute("SELECT configuration_value FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = 'MODULE_PAYMENT_QUICKBOOKS_TOKEN_EXPIRY'");
                if (time() < ((int)$expiry_check->fields['configuration_value'] - 300)) {
                    $fresh_token = $db->Execute("SELECT configuration_value FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = 'MODULE_PAYMENT_QUICKBOOKS_ACCESS_TOKEN'");
                    return $this->decryptValue($fresh_token->fields['configuration_value']);
                }

                // Token still expired — re-read refresh token from DB (not the stale constant)
                $fresh_refresh = $db->Execute("SELECT configuration_value FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = 'MODULE_PAYMENT_QUICKBOOKS_REFRESH_TOKEN'");
                $refresh_token = $this->decryptValue($fresh_refresh->fields['configuration_value']);

                $new_tokens = $this->refreshAccessToken($refresh_token);
                if ($new_tokens) {
                    $access_token = $new_tokens['access_token'];
                    // Encrypt and store new tokens
                    $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '" . zen_db_input($this->encryptValue($new_tokens['access_token'])) . "' WHERE configuration_key = 'MODULE_PAYMENT_QUICKBOOKS_ACCESS_TOKEN'");
                    $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '" . zen_db_input($this->encryptValue($new_tokens['refresh_token'])) . "' WHERE configuration_key = 'MODULE_PAYMENT_QUICKBOOKS_REFRESH_TOKEN'");
                    $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '" . (time() + $new_tokens['expires_in']) . "' WHERE configuration_key = 'MODULE_PAYMENT_QUICKBOOKS_TOKEN_EXPIRY'");
                } else {
                    return false;
                }
            } finally {
                $db->Execute("SELECT RELEASE_LOCK('qb_token_refresh')");
            }
        }

        return $access_token;
    }

    /**
     * Refresh the access token using the refresh token
     * @param string $refresh_token
     * @return array|false
     */
    function refreshAccessToken($refresh_token) {
        $token_url = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';

        $post_data = array(
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh_token
        );

        // Decrypt client secret for API authentication
        $client_secret = $this->decryptValue(MODULE_PAYMENT_QUICKBOOKS_CLIENT_SECRET);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $token_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Basic ' . base64_encode(MODULE_PAYMENT_QUICKBOOKS_CLIENT_ID . ':' . $client_secret),
            'Content-Type: application/x-www-form-urlencoded'
        ));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code == 200) {
            $result = json_decode($response, true);
            return $result;
        }

        // Mask response before logging to prevent token leakage
        $response_data = json_decode($response, true);
        $masked_response = is_array($response_data) ? print_r($this->maskSensitiveData($response_data), true) : 'HTTP ' . $http_code;
        $this->logError('Token Refresh Failed', $masked_response);
        return false;
    }

    /**
     * Store the CC info to the order and process any results that come back from the payment gateway
     */
    function before_process() {
        global $_POST, $db, $order, $messageStack;

        // Read and decrypt sensitive card data from session (stored by process_button)
        $cc_number = isset($_SESSION['qbp_cc_number']) ? $this->decryptValue($_SESSION['qbp_cc_number']) : '';
        $cc_cvv = isset($_SESSION['qbp_cc_cvv']) ? $this->decryptValue($_SESSION['qbp_cc_cvv']) : '';
        // Immediately clear sensitive data from session
        unset($_SESSION['qbp_cc_number'], $_SESSION['qbp_cc_cvv']);

        // Store only masked card number (PCI DSS - never persist full PAN)
        $order->info['cc_number'] = substr($cc_number, 0, 4) . str_repeat('X', max(0, strlen($cc_number) - 8)) . substr($cc_number, -4);
        $order->info['cc_expires'] = $_POST['cc_expires'];
        $order->info['cc_type'] = $_POST['cc_type'];
        $order->info['cc_owner'] = $_POST['cc_owner'];
        // CVV intentionally NOT stored on order object (PCI DSS Req 3.2 - never store CVV after authorization)

        // Get valid access token
        $access_token = $this->getAccessToken();
        if (!$access_token) {
            $messageStack->add_session('checkout_payment', MODULE_PAYMENT_QUICKBOOKS_TEXT_TOKEN_ERROR, 'error');
            zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
        }

        // Generate a unique request ID for this transaction (used to correlate with actual order in after_process)
        // Uses uniqid with entropy (26 chars) — session ID omitted to stay within varchar(100)
        $this->_charge_request_id = uniqid('qb_', true);

        // Determine transaction type
        $capture = (MODULE_PAYMENT_QUICKBOOKS_AUTHORIZATION_TYPE == 'Authorize') ? false : true;

        // Format expiration year - ensure 4-digit format
        $exp_year = $_POST['cc_expires_year'];
        if (strlen($exp_year) == 2) {
            $exp_year = '20' . $exp_year;
        }

        // Format expiration month - ensure 2-digit format
        $exp_month = str_pad($_POST['cc_expires_month'], 2, '0', STR_PAD_LEFT);

        // Build card data for tokenization
        $card_data = array(
            'number' => $cc_number,
            'expMonth' => $exp_month,
            'expYear' => $exp_year,
            'name' => str_replace("'", " ", $_POST['cc_owner']),
            'address' => array(
                'streetAddress' => str_replace("'", " ", $order->billing['street_address']),
                'city' => $order->billing['city'],
                'region' => $order->billing['state'],
                'country' => $order->billing['country']['iso_code_2'],
                'postalCode' => $order->billing['postcode']
            )
        );

        if (MODULE_PAYMENT_QUICKBOOKS_USE_CVV == 'True' && !empty($cc_cvv)) {
            $card_data['cvc'] = $cc_cvv;
        }

        // STEP 1: Tokenize the card data first (required by Intuit for PA-DSS compliance)
        $token_request = array('card' => $card_data);

        $token_url = (MODULE_PAYMENT_QUICKBOOKS_TESTMODE == 'Sandbox')
            ? 'https://sandbox.api.intuit.com/quickbooks/v4/payments/tokens'
            : 'https://api.intuit.com/quickbooks/v4/payments/tokens';

        $token_request_id = uniqid('qb_tok_', true);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $token_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($token_request));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
            'Accept: application/json',
            'Request-Id: ' . $token_request_id
        ));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $token_response = curl_exec($ch);
        $token_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $token_error = curl_error($ch);
        curl_close($ch);

        $token_data = json_decode($token_response, true);

        // Check if tokenization was successful
        if ($token_http_code != 200 && $token_http_code != 201) {
            $token_error_msg = '';
            $token_error_code = '';
            $token_error_detail = '';
            if (isset($token_data['errors']) && is_array($token_data['errors'])) {
                foreach ($token_data['errors'] as $error) {
                    $token_error_msg .= isset($error['message']) ? $error['message'] . ' ' : '';
                    if (isset($error['code']) && empty($token_error_code)) {
                        $token_error_code = $error['code'];
                    }
                    // Extract detail and moreInfo fields per Intuit API spec
                    if (isset($error['detail']) && empty($token_error_detail)) {
                        $token_error_detail = $error['detail'];
                    }
                    if (isset($error['moreInfo']) && empty($token_error_detail)) {
                        $token_error_detail = $error['moreInfo'];
                    }
                }
            } elseif (isset($token_data['message'])) {
                $token_error_msg = $token_data['message'];
            }
            // Also check top-level fields
            if (empty($token_error_code) && isset($token_data['code'])) {
                $token_error_code = $token_data['code'];
            }
            if (empty($token_error_detail) && isset($token_data['detail'])) {
                $token_error_detail = $token_data['detail'];
            }
            if (empty($token_error_detail) && isset($token_data['moreInfo'])) {
                $token_error_detail = $token_data['moreInfo'];
            }

            $this->logError('Tokenization Failed', print_r($this->maskSensitiveData($token_data), true));

            // Get customer-friendly decline message (pass detail for better pattern matching)
            $friendly_message = $this->getFriendlyDeclineMessage($token_error_code, $token_error_msg, $token_error_detail);

            // Send decline notification email to store owner
            $order_info = array(
                'billing_name' => $order->billing['firstname'] . ' ' . $order->billing['lastname'],
                'customers_email_address' => $order->customer['email_address'],
                'total' => $order->info['total'],
                'currency' => $order->info['currency']
            );
            $this->sendDeclineNotification($order_info, $friendly_message, $token_error_code, $token_data);

            $messageStack->add_session('checkout_payment', MODULE_PAYMENT_QUICKBOOKS_TEXT_DECLINED_MESSAGE . ' ' . $friendly_message, 'error');
            zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
        }

        $card_token = isset($token_data['value']) ? $token_data['value'] : '';
        if (empty($card_token)) {
            $this->logError('Tokenization Failed', 'No token value in response: ' . print_r($this->maskSensitiveData($token_data), true));

            // Send decline notification email to store owner
            $order_info = array(
                'billing_name' => $order->billing['firstname'] . ' ' . $order->billing['lastname'],
                'customers_email_address' => $order->customer['email_address'],
                'total' => $order->info['total'],
                'currency' => $order->info['currency']
            );
            $this->sendDeclineNotification($order_info, MODULE_PAYMENT_QUICKBOOKS_DECLINE_INVALID_CARD, '', $token_data);

            $messageStack->add_session('checkout_payment', MODULE_PAYMENT_QUICKBOOKS_TEXT_DECLINED_MESSAGE . ' ' . MODULE_PAYMENT_QUICKBOOKS_DECLINE_INVALID_CARD, 'error');
            zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
        }

        // STEP 2: Use the token to create a charge
        $request_data = array(
            'amount' => number_format($order->info['total'], 2, '.', ''),
            'currency' => $order->info['currency'],
            'capture' => $capture,
            'token' => $card_token,
            'context' => array(
                'mobile' => false,
                'isEcommerce' => true
            )
        );

        // Use the class-level request ID for idempotency and after_process correlation
        $request_id = $this->_charge_request_id;

        // Prepare for logging (mask sensitive data)
        $reportable_data = $request_data;
        $reportable_data['token'] = substr($card_token, 0, 10) . '...';

        // Determine API URL based on mode
        $api_url = (MODULE_PAYMENT_QUICKBOOKS_TESTMODE == 'Sandbox')
            ? 'https://sandbox.api.intuit.com/quickbooks/v4/payments/charges'
            : 'https://api.intuit.com/quickbooks/v4/payments/charges';

        // Send request to QuickBooks Payments API
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
            'Accept: application/json',
            'Request-Id: ' . $request_id
        ));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        if (defined('CURL_PROXY_REQUIRED') && CURL_PROXY_REQUIRED == 'True') {
            $proxy_tunnel_flag = (defined('CURL_PROXY_TUNNEL_FLAG') && strtoupper(CURL_PROXY_TUNNEL_FLAG) == 'FALSE') ? false : true;
            curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, $proxy_tunnel_flag);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            curl_setopt($ch, CURLOPT_PROXY, CURL_PROXY_SERVER_DETAILS);
        }

        $response = curl_exec($ch);
        $commError = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $commInfo = curl_getinfo($ch);
        curl_close($ch);

        // Parse the JSON response
        $response_data = json_decode($response, true);

        // Extract response fields
        $charge_id = isset($response_data['id']) ? $response_data['id'] : '';
        $status = isset($response_data['status']) ? $response_data['status'] : '';
        $auth_code = isset($response_data['authCode']) ? $response_data['authCode'] : '';
        $capture_id = isset($response_data['captureId']) ? $response_data['captureId'] : '';
        $card_last_four = isset($response_data['card']['number']) ? $response_data['card']['number'] : '';

        $this->auth_code = $auth_code;

        // Determine success based on HTTP code and status
        $success = ($http_code == 201 || $http_code == 200) && in_array($status, array('CAPTURED', 'AUTHORIZED'));

        // Extract AVS results from response
        $avs_street = isset($response_data['avsStreet']) ? $response_data['avsStreet'] : '';
        $avs_zip = isset($response_data['avsZip']) ? $response_data['avsZip'] : '';

        // Check AVS if enabled and transaction was initially successful
        $avs_failed = false;
        $avs_error_message = '';
        if ($success && defined('MODULE_PAYMENT_QUICKBOOKS_AVS_CHECK') && MODULE_PAYMENT_QUICKBOOKS_AVS_CHECK == 'True') {
            // Check if AVS failed - "Fail" means mismatch, "Pass" means match
            if ($avs_street == 'Fail' && $avs_zip == 'Fail') {
                $avs_failed = true;
                $avs_error_message = MODULE_PAYMENT_QUICKBOOKS_DECLINE_AVS_MISMATCH;
            } elseif ($avs_street == 'Fail') {
                $avs_failed = true;
                $avs_error_message = MODULE_PAYMENT_QUICKBOOKS_DECLINE_AVS_STREET_MISMATCH;
            } elseif ($avs_zip == 'Fail') {
                $avs_failed = true;
                $avs_error_message = MODULE_PAYMENT_QUICKBOOKS_DECLINE_AVS_ZIP_MISMATCH;
            }

            // If AVS failed, we need to void/refund the transaction
            if ($avs_failed && !empty($charge_id)) {
                // Use void for auth-only, refund for captured transactions
                $base_url = (MODULE_PAYMENT_QUICKBOOKS_TESTMODE == 'Sandbox')
                    ? 'https://sandbox.api.intuit.com/quickbooks/v4/payments/charges/' . $charge_id
                    : 'https://api.intuit.com/quickbooks/v4/payments/charges/' . $charge_id;

                if ($capture) {
                    // Charge was captured - must use refund endpoint
                    $void_url = $base_url . '/refunds';
                    $void_data = array(
                        'amount' => number_format($order->info['total'], 2, '.', ''),
                        'description' => 'AVS verification failed - automatic refund'
                    );
                } else {
                    // Charge was authorized only - use void endpoint
                    $void_url = $base_url . '/void';
                    $void_data = new stdClass(); // empty JSON {}
                }

                $void_ch = curl_init();
                curl_setopt($void_ch, CURLOPT_URL, $void_url);
                curl_setopt($void_ch, CURLOPT_POST, 1);
                curl_setopt($void_ch, CURLOPT_POSTFIELDS, json_encode($void_data));
                curl_setopt($void_ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($void_ch, CURLOPT_HTTPHEADER, array(
                    'Authorization: Bearer ' . $access_token,
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Request-Id: ' . uniqid('void_' . $charge_id . '_', true)
                ));
                curl_setopt($void_ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($void_ch, CURLOPT_SSL_VERIFYHOST, 2);
                curl_setopt($void_ch, CURLOPT_TIMEOUT, 60);
                curl_setopt($void_ch, CURLOPT_CONNECTTIMEOUT, 10);
                $void_response = curl_exec($void_ch);
                $void_http_code = curl_getinfo($void_ch, CURLINFO_HTTP_CODE);
                curl_close($void_ch);

                // Verify the void/refund succeeded — if not, the customer was charged despite AVS failure
                $void_response_data = json_decode($void_response, true);
                $void_status = isset($void_response_data['status']) ? $void_response_data['status'] : '';
                if (!in_array($void_http_code, array(200, 201)) || !in_array($void_status, array('VOIDED', 'ISSUED', 'PENDING', 'SUCCEEDED'))) {
                    error_log('QuickBooks Payments CRITICAL: AVS auto-void/refund FAILED for Charge ID: ' . $charge_id . '. Customer may have been charged. HTTP: ' . $void_http_code . ' Status: ' . $void_status);
                    // Notify store owner via email about the failed void
                    if (defined('STORE_OWNER_EMAIL_ADDRESS')) {
                        zen_mail(STORE_OWNER, STORE_OWNER_EMAIL_ADDRESS, 'URGENT: QuickBooks AVS Void Failed', 'AVS verification failed for Charge ID: ' . $charge_id . ' but the automatic void/refund also failed (HTTP ' . $void_http_code . '). The customer may have been charged. Please check the QuickBooks dashboard and issue a manual refund if needed.', STORE_OWNER, STORE_OWNER_EMAIL_ADDRESS);
                    }
                }

                $this->logError('AVS Failed - Transaction Void Attempted', 'Charge ID: ' . $charge_id . ' AVS Street: ' . $avs_street . ' AVS Zip: ' . $avs_zip . ' Void HTTP: ' . $void_http_code . ' Void Status: ' . $void_status);

                // Mark as failed since AVS check didn't pass
                $success = false;
                $error_code = 'AVS_FAIL';
                $error_message = $avs_error_message;
            }
        }

        // DEBUG LOGGING (sensitive data masked for PCI compliance)
        if (strstr(MODULE_PAYMENT_QUICKBOOKS_DEBUGGING, 'Log') || strstr(MODULE_PAYMENT_QUICKBOOKS_DEBUGGING, 'Email')) {
            $masked_request = $this->maskSensitiveData($reportable_data);
            $masked_response = $this->maskSensitiveData($response_data);

            $errorMessage = date('M-d-Y h:i:s') . "\n=================================\n\n" .
                ($commError != '' ? 'Comm Error: ' . $commError . "\n\n" : '') .
                'HTTP Code: ' . $http_code . "\n" .
                'Status: ' . $status . "\n" .
                'Request ID: ' . $request_id . "\n" .
                'Sent to QuickBooks: ' . print_r($masked_request, true) . "\n\n" .
                'Response: ' . print_r($masked_response, true) . "\n\n" .
                'CURL info: ' . print_r($commInfo, true) . "\n";

            if (strstr(MODULE_PAYMENT_QUICKBOOKS_DEBUGGING, 'Log')) {
                $key = time() . '_' . zen_create_random_value(4);
                $file = $this->_logDir . '/' . 'QuickBooks_Debug_' . $key . '.log';
                $this->writeLogFile($file, $errorMessage);
            }
            if (strstr(MODULE_PAYMENT_QUICKBOOKS_DEBUGGING, 'Email')) {
                zen_mail(STORE_OWNER, STORE_OWNER_EMAIL_ADDRESS, 'QuickBooks Payments Debug Data', $errorMessage, STORE_OWNER, STORE_OWNER_EMAIL_ADDRESS);
            }
        }

        // Prepare error message for failed transactions (only if not already set by AVS check)
        if (!isset($error_message) || empty($error_message)) {
            $error_message = '';
        }
        if (!isset($error_code) || empty($error_code)) {
            $error_code = '';
        }
        if (!isset($error_detail) || empty($error_detail)) {
            $error_detail = '';
        }
        if (!$success && empty($error_message)) {
            if (isset($response_data['errors']) && is_array($response_data['errors'])) {
                foreach ($response_data['errors'] as $error) {
                    $error_message .= isset($error['message']) ? $error['message'] . ' ' : '';
                    if (isset($error['code']) && empty($error_code)) {
                        $error_code = $error['code'];
                    }
                    // Extract detail and moreInfo fields per Intuit API spec
                    if (isset($error['detail']) && empty($error_detail)) {
                        $error_detail = $error['detail'];
                    }
                    if (isset($error['moreInfo']) && empty($error_detail)) {
                        $error_detail = $error['moreInfo'];
                    }
                }
            } elseif (isset($response_data['message'])) {
                $error_message = $response_data['message'];
            }
            // Also check for error code at response level
            if (empty($error_code) && isset($response_data['code'])) {
                $error_code = $response_data['code'];
            }
            // Check for type field (e.g., "fraud_error", "transaction_declined", "invalid_request")
            if (empty($error_code) && isset($response_data['type'])) {
                $error_code = $response_data['type'];
            }
            // Check for detail/moreInfo at top level
            if (empty($error_detail) && isset($response_data['detail'])) {
                $error_detail = $response_data['detail'];
            }
            if (empty($error_detail) && isset($response_data['moreInfo'])) {
                $error_detail = $response_data['moreInfo'];
            }
        }

        // Fallback: if transaction failed but no error message was extracted, store HTTP code and status
        if (!$success && empty($error_message)) {
            if (!empty($status)) {
                $error_message = 'Transaction ' . $status . '. HTTP ' . $http_code;
            } else {
                $error_message = 'Transaction failed. HTTP ' . $http_code;
            }
        }

        // DATABASE SECTION - Insert transaction record
        $db_trans_type = $capture ? 'Sale' : 'Auth';
        $db_response_code = $success ? '0' : '1';
        $db_session_id = zen_session_id();

        $db->Execute("INSERT INTO quickbooks_payments (
            id, customer_id, order_id, trans_type, response_code,
            status, message, auth_code, charge_id, capture_id,
            request_id, sent, received, dtime, session_id
        ) VALUES (
            NULL,
            '" . (int)$_SESSION['customer_id'] . "',
            0,
            '" . zen_db_input($db_trans_type) . "',
            '" . zen_db_input($db_response_code) . "',
            '" . zen_db_input($status) . "',
            '" . zen_db_input($error_message) . "',
            '" . zen_db_input($auth_code) . "',
            '" . zen_db_input($charge_id) . "',
            '" . zen_db_input($capture_id) . "',
            '" . zen_db_input($request_id) . "',
            '',
            '',
            now(),
            '" . zen_db_input($db_session_id) . "'
        )");

        // If the transaction failed, redirect back to the payment page with the error message
        if (!$success) {
            // Get customer-friendly decline message (pass error_detail for better pattern matching)
            $friendly_message = $this->getFriendlyDeclineMessage($error_code, $error_message, $error_detail);

            // Send decline notification email to store owner
            $order_info = array(
                'billing_name' => $order->billing['firstname'] . ' ' . $order->billing['lastname'],
                'customers_email_address' => $order->customer['email_address'],
                'total' => $order->info['total'],
                'currency' => $order->info['currency']
            );
            $this->sendDeclineNotification($order_info, $friendly_message, $error_code, $response_data);

            // Display friendly message to customer
            $messageStack->add_session('checkout_payment', MODULE_PAYMENT_QUICKBOOKS_TEXT_DECLINED_MESSAGE . ' ' . $friendly_message, 'error');
            zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
        }
    }

    /**
     * Post-process activities.
     * @return boolean
     */
    function after_process() {
        global $insert_id, $db;
        // Set the actual order_id on the transaction record using the unique request_id as the correlation key
        // This eliminates the race condition from the old predicted-order-ID approach
        $db->Execute("UPDATE quickbooks_payments SET order_id = " . (int)$insert_id .
            " WHERE request_id = '" . zen_db_input($this->_charge_request_id) . "'" .
            " AND order_id = 0");
        // Verify the UPDATE matched a row — if not, the payment record is orphaned from this order
        if (method_exists($db, 'affectedRows') && $db->affectedRows() == 0) {
            error_log('QuickBooks Payments CRITICAL: after_process() UPDATE matched 0 rows. Order #' . (int)$insert_id . ' has no linked payment record. Request ID: ' . $this->_charge_request_id);
        }
        $this->order_id = $insert_id;
        $trans_type = (MODULE_PAYMENT_QUICKBOOKS_AUTHORIZATION_TYPE == "Authorize") ? 'Pre authorized' : '';
        $db->Execute("INSERT INTO " . TABLE_ORDERS_STATUS_HISTORY . " (comments, orders_id, orders_status_id, date_added) VALUES ('" . zen_db_input($trans_type . " " . MODULE_PAYMENT_QUICKBOOKS_TEXT_TRANS_COMMENT . $this->auth_code . ".") . "', '" . (int)$insert_id . "', '" . (int)$this->order_status . "', now())");
        return false;
    }

    /**
     * Display payment transaction details and action buttons on Admin Order Details page
     * Called by admin/orders.php to render the payment management panel
     * @param int $oID Order ID
     * @return string HTML output
     */
    function admin_notification($oID) {
        global $db;

        $output = '';
        $transactions = $this->getTransactionDetails($oID);
        $can_void = $this->canVoid($oID);
        $can_refund = $this->canRefund($oID);

        // Check if capture is available (auth-only transactions that haven't been captured)
        $can_capture = false;
        if (defined('MODULE_PAYMENT_QUICKBOOKS_AUTHORIZATION_TYPE') && MODULE_PAYMENT_QUICKBOOKS_AUTHORIZATION_TYPE == 'Authorize') {
            $auth_rec = $db->Execute("SELECT id FROM quickbooks_payments WHERE order_id = " . (int)$oID . " AND response_code = '0' AND trans_type = 'Auth' LIMIT 1");
            $capt_rec = $db->Execute("SELECT id FROM quickbooks_payments WHERE order_id = " . (int)$oID . " AND response_code = '0' AND trans_type = 'Capture' LIMIT 1");
            if ($auth_rec->RecordCount() > 0 && $capt_rec->RecordCount() <= 0) {
                $can_capture = true;
            }
        }

        // Get order total for refund default
        $order_query = $db->Execute("SELECT order_total FROM " . TABLE_ORDERS . " WHERE orders_id = " . (int)$oID);
        $order_total = !$order_query->EOF ? number_format($order_query->fields['order_total'], 2, '.', '') : '0.00';

        $output .= '<!-- BOF: QuickBooks Payments admin transaction tools -->' . "\n";

        // Transaction history table
        if (count($transactions) > 0) {
            $output .= '<div style="padding:10px 0;">';
            $output .= '<strong>QuickBooks Payments &mdash; Transaction History</strong>';
            $output .= '<table class="table table-striped table-condensed" style="margin-top:8px; font-size:12px;">';
            $output .= '<thead><tr>';
            $output .= '<th>Date/Time</th><th>Type</th><th>Status</th><th>Charge ID</th><th>Auth Code</th><th>Result</th><th>Decline Reason</th>';
            $output .= '</tr></thead><tbody>';
            foreach ($transactions as $trans) {
                $result_class = ($trans['response_code'] == '0') ? 'text-success' : 'text-danger';
                $result_text = ($trans['response_code'] == '0') ? 'SUCCESS' : 'FAILED';
                $output .= '<tr>';
                $output .= '<td>' . htmlspecialchars($trans['dtime']) . '</td>';
                $output .= '<td>' . htmlspecialchars($trans['trans_type']) . '</td>';
                $output .= '<td>' . htmlspecialchars($trans['status']) . '</td>';
                $output .= '<td style="font-size:11px;">' . htmlspecialchars($trans['charge_id']) . '</td>';
                $output .= '<td>' . htmlspecialchars($trans['auth_code']) . '</td>';
                $output .= '<td class="' . $result_class . '"><strong>' . $result_text . '</strong></td>';
                $output .= '<td>';
                if ($trans['response_code'] != '0' && !empty($trans['message'])) {
                    $output .= '<span style="color:#d9534f;">' . htmlspecialchars($trans['message']) . '</span>';
                }
                $output .= '</td>';
                $output .= '</tr>';
            }
            $output .= '</tbody></table>';
            $output .= '</div>';
        }

        // Action buttons
        $output .= '<table class="noprint"><tr style="background-color:#bbbbbb; border-style:dotted;">' . "\n";

        // Capture button
        if ($can_capture) {
            $output .= '<td valign="top"><table class="noprint">';
            $output .= '<tr style="background-color:#dddddd; border-style:dotted;">';
            $output .= '<td class="main"><strong>Capture Payment</strong><br>' . "\n";
            $output .= zen_draw_form('qbcapture', FILENAME_ORDERS, zen_get_all_get_params(array('action')) . 'action=doCapture', 'post', '', true) . zen_hide_session_id();
            $output .= 'Capture the authorized payment for this order.<br><br>';
            $output .= '<label>' . zen_draw_checkbox_field('captconfirm', '', false) . ' Confirm capture</label><br><br>';
            $output .= '<input type="submit" name="btncapture" value="Capture" class="btn btn-sm btn-info" onclick="if(!document.querySelector(\'[name=captconfirm]\').checked){alert(\'Please check the confirm box.\');return false;}">';
            $output .= '</form>';
            $output .= '</td></tr></table></td>' . "\n";
        }

        // Void button
        if ($can_void) {
            $output .= '<td valign="top"><table class="noprint">';
            $output .= '<tr style="background-color:#dddddd; border-style:dotted;">';
            $output .= '<td class="main"><strong>Void Transaction</strong><br>' . "\n";
            $output .= zen_draw_form('qbvoid', FILENAME_ORDERS, zen_get_all_get_params(array('action')) . 'action=doVoid', 'post', '', true) . zen_hide_session_id();
            $output .= 'Void the authorized/captured payment.<br><br>';
            $output .= '<label>' . zen_draw_checkbox_field('voidconfirm', '', false) . ' Confirm void</label><br><br>';
            $output .= '<input type="submit" name="btnvoid" value="Void" class="btn btn-sm btn-warning" onclick="if(!document.querySelector(\'[name=voidconfirm]\').checked){alert(\'Please check the confirm box.\');return false;}">';
            $output .= '</form>';
            $output .= '</td></tr></table></td>' . "\n";
        }

        // Refund form
        if ($can_refund) {
            $output .= '<td valign="top"><table class="noprint">';
            $output .= '<tr style="background-color:#dddddd; border-style:dotted;">';
            $output .= '<td class="main"><strong>Refund Payment</strong><br>' . "\n";
            $output .= zen_draw_form('qbrefund', FILENAME_ORDERS, zen_get_all_get_params(array('action')) . 'action=doRefund', 'post', '', true) . zen_hide_session_id();
            $output .= 'Refund Amount: $' . zen_draw_input_field('refamt', $order_total, 'size="8" style="width:100px;"') . '<br>';
            $output .= '<small>(Enter partial amount or leave as-is for full refund)</small><br><br>';
            $output .= '<label>' . zen_draw_checkbox_field('refconfirm', '', false) . ' Confirm refund</label><br><br>';
            $output .= '<input type="submit" name="btnrefund" value="Refund" class="btn btn-sm btn-danger" onclick="if(!document.querySelector(\'[name=refconfirm]\').checked){alert(\'Please check the confirm box.\');return false;}">';
            $output .= '</form>';
            $output .= '</td></tr></table></td>' . "\n";
        }

        $output .= '</tr></table>' . "\n";
        $output .= '<!-- EOF: QuickBooks Payments admin transaction tools -->';

        return $output;
    }

    /**
     * Post-process activities after capture.
     * @return boolean
     */
    function after_docapt() {
        global $db;
        $capture_status = defined('MODULE_PAYMENT_QUICKBOOKS_ORDER_STATUS_ID') ? (int)MODULE_PAYMENT_QUICKBOOKS_ORDER_STATUS_ID : 2;
        $db->Execute("INSERT INTO " . TABLE_ORDERS_STATUS_HISTORY . " (orders_id, orders_status_id, date_added, customer_notified, comments) VALUES (" .
            (int)$this->order_id . ", " . $capture_status . ", now(), 1, '" . MODULE_PAYMENT_QUICKBOOKS_TEXT_POST_AUTH_SUCCESS . $this->auth_code . "')");
        $db->Execute("UPDATE " . TABLE_ORDERS . " SET orders_status = " . $capture_status . " WHERE orders_id = " . (int)$this->order_id);
        return false;
    }

    /**
     * Capture (Post_Authorize) a CC transaction.
     * @return string
     */
    function _doCapt($oID, $oTotal, $oCurrency, $oNewTrans = 0) {
        global $db, $messageStack;

        // Server-side confirmation check (admin form submit)
        if (isset($_POST['btncapture']) && (!isset($_POST['captconfirm']) || $_POST['captconfirm'] !== 'on')) {
            $messageStack->add_session('Please check the confirmation box before capturing.', 'error');
            return 'Capture not confirmed.';
        }

        $order = new order($oID);
        $this->order_id = $oID;
        $retValue = '1';

        // Get the charge_id from the original authorization
        $auth_trans_rec = $db->Execute("SELECT charge_id FROM quickbooks_payments WHERE order_id = " . (int)$oID . " AND trans_type = 'Auth' AND response_code = '0' ORDER BY dtime DESC LIMIT 1");

        if ($auth_trans_rec->RecordCount() <= 0) {
            $messageStack->add_session(MODULE_PAYMENT_QUICKBOOKS_TEXT_NO_AUTHORIZED_RECORD, 'error');
            return MODULE_PAYMENT_QUICKBOOKS_TEXT_NO_AUTHORIZED_RECORD;
        }

        $charge_id = $auth_trans_rec->fields['charge_id'];

        // Check if already captured
        $capt_trans_rec = $db->Execute("SELECT id FROM quickbooks_payments WHERE order_id = " . (int)$oID . " AND trans_type = 'Capture' AND response_code = '0' LIMIT 1");
        if ($capt_trans_rec->RecordCount() > 0) {
            $messageStack->add_session(MODULE_PAYMENT_QUICKBOOKS_TEXT_POSTAUTH_RECORD_EXISTS, 'error');
            return MODULE_PAYMENT_QUICKBOOKS_TEXT_POSTAUTH_RECORD_EXISTS;
        }

        // Get valid access token
        $access_token = $this->getAccessToken();
        if (!$access_token) {
            $messageStack->add_session(MODULE_PAYMENT_QUICKBOOKS_TEXT_TOKEN_ERROR, 'error');
            return MODULE_PAYMENT_QUICKBOOKS_TEXT_TOKEN_ERROR;
        }

        // Build capture request
        $request_data = array(
            'amount' => number_format($order->info['total'], 2, '.', ''),
            'context' => array(
                'mobile' => false,
                'isEcommerce' => true
            )
        );

        $request_id = uniqid('qb_cap_' . $oID . '_', true);

        // Determine API URL based on mode
        $api_url = (MODULE_PAYMENT_QUICKBOOKS_TESTMODE == 'Sandbox')
            ? 'https://sandbox.api.intuit.com/quickbooks/v4/payments/charges/' . $charge_id . '/capture'
            : 'https://api.intuit.com/quickbooks/v4/payments/charges/' . $charge_id . '/capture';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
            'Accept: application/json',
            'Request-Id: ' . $request_id
        ));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $response_data = json_decode($response, true);

        $status = isset($response_data['status']) ? $response_data['status'] : '';
        $auth_code = isset($response_data['authCode']) ? $response_data['authCode'] : '';
        $capture_id = isset($response_data['id']) ? $response_data['id'] : '';

        $this->auth_code = $auth_code;

        $success = ($http_code == 200 || $http_code == 201) && $status == 'CAPTURED';

        // Insert capture record
        $db_response_code = $success ? '0' : '1';
        $error_message = '';
        if (!$success && isset($response_data['errors'])) {
            foreach ($response_data['errors'] as $error) {
                $error_message .= isset($error['message']) ? $error['message'] . ' ' : '';
            }
        }

        $db->Execute("INSERT INTO quickbooks_payments (
            id, customer_id, order_id, trans_type, response_code,
            status, message, auth_code, charge_id, capture_id,
            request_id, sent, received, dtime, session_id
        ) VALUES (
            NULL,
            '0',
            '" . (int)$oID . "',
            'Capture',
            '" . zen_db_input($db_response_code) . "',
            '" . zen_db_input($status) . "',
            '" . zen_db_input($error_message) . "',
            '" . zen_db_input($auth_code) . "',
            '" . zen_db_input($charge_id) . "',
            '" . zen_db_input($capture_id) . "',
            '" . zen_db_input($request_id) . "',
            '',
            '',
            now(),
            '" . zen_db_input(zen_session_id()) . "'
        )");

        if (!$success) {
            $messageStack->add_session(MODULE_PAYMENT_QUICKBOOKS_TEXT_DECLINED_POST_AUTH_MESSAGE . ': ' . $error_message, 'error');
            $retValue = MODULE_PAYMENT_QUICKBOOKS_TEXT_DECLINED_POST_AUTH_MESSAGE . ': ' . $error_message;
        }

        return $retValue;
    }

    /**
     * Void a transaction (for authorized but not captured transactions)
     * @param int $oID Order ID
     * @param float $amount Amount (not used for void, but kept for interface consistency)
     * @return string
     */
    function _doVoid($oID, $amount = 0) {
        global $db, $messageStack;

        // Server-side confirmation check (admin form submit)
        if (isset($_POST['btnvoid']) && (!isset($_POST['voidconfirm']) || $_POST['voidconfirm'] !== 'on')) {
            $messageStack->add_session('Please check the confirmation box before voiding.', 'error');
            return 'Void not confirmed.';
        }

        $this->order_id = $oID;
        $retValue = 'success';

        // Get the charge_id and request_id from the transaction record
        $trans_rec = $db->Execute("SELECT charge_id, request_id FROM quickbooks_payments WHERE order_id = " . (int)$oID . " AND response_code = '0' AND trans_type IN ('Auth', 'Sale') ORDER BY dtime DESC LIMIT 1");

        if ($trans_rec->RecordCount() <= 0) {
            $error_msg = 'No transaction found to void for this order.';
            $messageStack->add_session($error_msg, 'error');
            return $error_msg;
        }

        $charge_id = $trans_rec->fields['charge_id'];
        $orig_request_id = $trans_rec->fields['request_id'];

        // Check if already voided
        $void_rec = $db->Execute("SELECT id FROM quickbooks_payments WHERE order_id = " . (int)$oID . " AND trans_type = 'Void' AND response_code = '0' LIMIT 1");
        if ($void_rec->RecordCount() > 0) {
            $error_msg = 'This transaction has already been voided.';
            $messageStack->add_session($error_msg, 'error');
            return $error_msg;
        }

        // Check if already refunded
        $refund_rec = $db->Execute("SELECT id FROM quickbooks_payments WHERE order_id = " . (int)$oID . " AND trans_type = 'Refund' AND response_code = '0' LIMIT 1");
        if ($refund_rec->RecordCount() > 0) {
            $error_msg = 'This transaction has already been refunded. Cannot void.';
            $messageStack->add_session($error_msg, 'error');
            return $error_msg;
        }

        // Get valid access token
        $access_token = $this->getAccessToken();
        if (!$access_token) {
            $error_msg = defined('MODULE_PAYMENT_QUICKBOOKS_TEXT_TOKEN_ERROR') ? MODULE_PAYMENT_QUICKBOOKS_TEXT_TOKEN_ERROR : 'Unable to authenticate with payment gateway.';
            $messageStack->add_session($error_msg, 'error');
            return $error_msg;
        }

        $request_id = uniqid('qb_void_' . $oID . '_', true);

        // Determine API URL based on mode
        $testmode = defined('MODULE_PAYMENT_QUICKBOOKS_TESTMODE') ? MODULE_PAYMENT_QUICKBOOKS_TESTMODE : 'Sandbox';
        $api_url = ($testmode == 'Sandbox')
            ? 'https://sandbox.api.intuit.com/quickbooks/v4/payments/txn-requests/' . $orig_request_id . '/void'
            : 'https://api.intuit.com/quickbooks/v4/payments/txn-requests/' . $orig_request_id . '/void';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
            'Accept: application/json',
            'Request-Id: ' . $request_id
        ));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $response_data = json_decode($response, true);

        $status = isset($response_data['status']) ? $response_data['status'] : '';
        $success = ($http_code == 200 || $http_code == 201) && $status == 'VOIDED';

        // Prepare error message
        $error_message = '';
        if (!$success) {
            if (isset($response_data['errors']) && is_array($response_data['errors'])) {
                foreach ($response_data['errors'] as $error) {
                    $error_message .= isset($error['message']) ? $error['message'] . ' ' : '';
                }
            } elseif (isset($response_data['message'])) {
                $error_message = $response_data['message'];
            } else {
                $error_message = 'Void failed. HTTP Code: ' . $http_code;
            }
        }

        // Insert void record
        $db_response_code = $success ? '0' : '1';
        $db->Execute("INSERT INTO quickbooks_payments (
            id, customer_id, order_id, trans_type, response_code,
            status, message, auth_code, charge_id, capture_id,
            request_id, sent, received, dtime, session_id
        ) VALUES (
            NULL,
            '0',
            '" . (int)$oID . "',
            'Void',
            '" . zen_db_input($db_response_code) . "',
            '" . zen_db_input($status) . "',
            '" . zen_db_input($error_message) . "',
            '',
            '" . zen_db_input($charge_id) . "',
            '',
            '" . zen_db_input($request_id) . "',
            '',
            '',
            now(),
            '" . zen_db_input(zen_session_id()) . "'
        )");

        if ($success) {
            // Add order status history comment
            $db->Execute("INSERT INTO " . TABLE_ORDERS_STATUS_HISTORY . " (orders_id, orders_status_id, date_added, customer_notified, comments) VALUES (" . (int)$oID . ", 1, now(), 0, '" . zen_db_input('QuickBooks Payment VOIDED. Charge ID: ' . htmlspecialchars($charge_id, ENT_QUOTES, 'UTF-8')) . "')");
            $retValue = 'success';
        } else {
            $messageStack->add_session('Void failed: ' . $error_message, 'error');
            $retValue = 'Void failed: ' . $error_message;
        }

        return $retValue;
    }

    /**
     * Refund a transaction (for captured/settled transactions)
     * @param int $oID Order ID
     * @param float $amount Amount to refund (0 = full refund)
     * @return string
     */
    function _doRefund($oID, $amount = 0) {
        global $db, $messageStack;

        // Server-side confirmation check (admin form submit)
        if (isset($_POST['btnrefund']) && (!isset($_POST['refconfirm']) || $_POST['refconfirm'] !== 'on')) {
            $messageStack->add_session('Please check the confirmation box before refunding.', 'error');
            return 'Refund not confirmed.';
        }

        $this->order_id = $oID;
        $retValue = 'success';

        // Get order details
        $order = new order($oID);

        // Get the charge_id from the transaction record
        $trans_rec = $db->Execute("SELECT charge_id FROM quickbooks_payments WHERE order_id = " . (int)$oID . " AND response_code = '0' AND trans_type IN ('Sale', 'Capture') ORDER BY dtime DESC LIMIT 1");

        if ($trans_rec->RecordCount() <= 0) {
            $error_msg = 'No captured transaction found to refund for this order. If this is an authorized-only transaction, use Void instead.';
            $messageStack->add_session($error_msg, 'error');
            return $error_msg;
        }

        $charge_id = $trans_rec->fields['charge_id'];

        // Check if already voided
        $void_rec = $db->Execute("SELECT id FROM quickbooks_payments WHERE order_id = " . (int)$oID . " AND trans_type = 'Void' AND response_code = '0' LIMIT 1");
        if ($void_rec->RecordCount() > 0) {
            $error_msg = 'This transaction has been voided. Cannot refund.';
            $messageStack->add_session($error_msg, 'error');
            return $error_msg;
        }

        // Get valid access token
        $access_token = $this->getAccessToken();
        if (!$access_token) {
            $error_msg = defined('MODULE_PAYMENT_QUICKBOOKS_TEXT_TOKEN_ERROR') ? MODULE_PAYMENT_QUICKBOOKS_TEXT_TOKEN_ERROR : 'Unable to authenticate with payment gateway.';
            $messageStack->add_session($error_msg, 'error');
            return $error_msg;
        }

        // Determine refund amount (check admin form POST field, then method param, then default to full refund)
        if ($amount <= 0 && isset($_POST['refamt']) && (float)$_POST['refamt'] > 0) {
            $amount = (float)$_POST['refamt'];
        }
        // Cap refund amount at the order total
        $max_refund = (float)$order->info['total'];
        if (round($amount, 2) > round($max_refund, 2)) {
            $error_msg = 'Refund amount ($' . number_format($amount, 2) . ') cannot exceed the order total ($' . number_format($max_refund, 2) . ').';
            $messageStack->add_session($error_msg, 'error');
            return $error_msg;
        }
        $refund_amount = ($amount > 0) ? $amount : $order->info['total'];
        $refund_amount = number_format($refund_amount, 2, '.', '');

        // Build refund request
        $request_data = array(
            'amount' => $refund_amount,
            'context' => array(
                'mobile' => false,
                'isEcommerce' => true
            )
        );

        $request_id = uniqid('qb_refund_' . $oID . '_', true);

        // Determine API URL based on mode
        $testmode = defined('MODULE_PAYMENT_QUICKBOOKS_TESTMODE') ? MODULE_PAYMENT_QUICKBOOKS_TESTMODE : 'Sandbox';
        $api_url = ($testmode == 'Sandbox')
            ? 'https://sandbox.api.intuit.com/quickbooks/v4/payments/charges/' . $charge_id . '/refunds'
            : 'https://api.intuit.com/quickbooks/v4/payments/charges/' . $charge_id . '/refunds';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
            'Accept: application/json',
            'Request-Id: ' . $request_id
        ));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $response_data = json_decode($response, true);

        $status = isset($response_data['status']) ? $response_data['status'] : '';
        $refund_id = isset($response_data['id']) ? $response_data['id'] : '';
        $success = ($http_code == 200 || $http_code == 201) && in_array($status, array('ISSUED', 'PENDING', 'SUCCEEDED'));

        // Prepare error message
        $error_message = '';
        if (!$success) {
            if (isset($response_data['errors']) && is_array($response_data['errors'])) {
                foreach ($response_data['errors'] as $error) {
                    $error_message .= isset($error['message']) ? $error['message'] . ' ' : '';
                }
            } elseif (isset($response_data['message'])) {
                $error_message = $response_data['message'];
            } else {
                $error_message = 'Refund failed. HTTP Code: ' . $http_code;
            }
        }

        // Insert refund record
        $db_response_code = $success ? '0' : '1';
        $db->Execute("INSERT INTO quickbooks_payments (
            id, customer_id, order_id, trans_type, response_code,
            status, message, auth_code, charge_id, capture_id,
            request_id, sent, received, dtime, session_id
        ) VALUES (
            NULL,
            '0',
            '" . (int)$oID . "',
            'Refund',
            '" . zen_db_input($db_response_code) . "',
            '" . zen_db_input($status) . "',
            '" . zen_db_input($error_message) . "',
            '',
            '" . zen_db_input($charge_id) . "',
            '" . zen_db_input($refund_id) . "',
            '" . zen_db_input($request_id) . "',
            '',
            '',
            now(),
            '" . zen_db_input(zen_session_id()) . "'
        )");

        if ($success) {
            // Add order status history comment
            $db->Execute("INSERT INTO " . TABLE_ORDERS_STATUS_HISTORY . " (orders_id, orders_status_id, date_added, customer_notified, comments) VALUES (" . (int)$oID . ", 1, now(), 0, '" . zen_db_input('QuickBooks Payment REFUNDED. Amount: $' . htmlspecialchars($refund_amount, ENT_QUOTES, 'UTF-8') . ' Refund ID: ' . htmlspecialchars($refund_id, ENT_QUOTES, 'UTF-8')) . "')");
            $retValue = 'success';
        } else {
            $messageStack->add_session('Refund failed: ' . $error_message, 'error');
            $retValue = 'Refund failed: ' . $error_message;
        }

        return $retValue;
    }

    /**
     * Get transaction details for an order
     * @param int $oID Order ID
     * @return array
     */
    function getTransactionDetails($oID) {
        global $db;

        $transactions = array();
        $trans_rec = $db->Execute("SELECT id, trans_type, status, response_code, charge_id, auth_code, message, dtime FROM quickbooks_payments WHERE order_id = " . (int)$oID . " ORDER BY dtime ASC");

        while (!$trans_rec->EOF) {
            $transactions[] = array(
                'id' => $trans_rec->fields['id'],
                'trans_type' => $trans_rec->fields['trans_type'],
                'status' => $trans_rec->fields['status'],
                'response_code' => $trans_rec->fields['response_code'],
                'charge_id' => $trans_rec->fields['charge_id'],
                'auth_code' => $trans_rec->fields['auth_code'],
                'message' => $trans_rec->fields['message'],
                'dtime' => $trans_rec->fields['dtime']
            );
            $trans_rec->MoveNext();
        }

        return $transactions;
    }

    /**
     * Check if order can be voided
     * @param int $oID Order ID
     * @return boolean
     */
    function canVoid($oID) {
        global $db;

        // Check if there's a successful transaction that hasn't been voided or refunded
        $trans_rec = $db->Execute("SELECT id FROM quickbooks_payments WHERE order_id = " . (int)$oID . " AND response_code = '0' AND trans_type IN ('Auth', 'Sale') LIMIT 1");
        if ($trans_rec->RecordCount() <= 0) {
            return false;
        }

        // Check if already voided
        $void_rec = $db->Execute("SELECT id FROM quickbooks_payments WHERE order_id = " . (int)$oID . " AND trans_type = 'Void' AND response_code = '0' LIMIT 1");
        if ($void_rec->RecordCount() > 0) {
            return false;
        }

        // Check if already refunded
        $refund_rec = $db->Execute("SELECT id FROM quickbooks_payments WHERE order_id = " . (int)$oID . " AND trans_type = 'Refund' AND response_code = '0' LIMIT 1");
        if ($refund_rec->RecordCount() > 0) {
            return false;
        }

        return true;
    }

    /**
     * Check if order can be refunded
     * @param int $oID Order ID
     * @return boolean
     */
    function canRefund($oID) {
        global $db;

        // Check if there's a successful captured/sale transaction (auth-only must use Void, not Refund)
        $trans_rec = $db->Execute("SELECT id FROM quickbooks_payments WHERE order_id = " . (int)$oID . " AND response_code = '0' AND trans_type IN ('Sale', 'Capture') LIMIT 1");
        if ($trans_rec->RecordCount() <= 0) {
            return false;
        }

        // Check if already voided
        $void_rec = $db->Execute("SELECT id FROM quickbooks_payments WHERE order_id = " . (int)$oID . " AND trans_type = 'Void' AND response_code = '0' LIMIT 1");
        if ($void_rec->RecordCount() > 0) {
            return false;
        }

        return true;
    }

    /**
     * Mask sensitive data from arrays before logging (PCI compliance)
     * Removes or redacts card numbers, tokens, CVV, and other cardholder data
     * @param mixed $data Array or string to mask
     * @return mixed Masked data safe for logging
     */
    function maskSensitiveData($data) {
        if (is_string($data)) {
            return $data;
        }
        if (!is_array($data)) {
            return $data;
        }

        $sensitive_keys = array(
            'number', 'card_number', 'cc_number', 'cvc', 'cvv', 'cc_cvv',
            'expMonth', 'expYear', 'exp_date', 'cc_expires',
            'token', 'access_token', 'refresh_token',
            'name', 'cardholderName', 'cc_owner'
        );

        $masked = array();
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $masked[$key] = $this->maskSensitiveData($value);
            } elseif (in_array(strtolower($key), array_map('strtolower', $sensitive_keys))) {
                if (in_array(strtolower($key), array('number', 'card_number', 'cc_number'))) {
                    $masked[$key] = str_pad(substr((string)$value, -4), strlen((string)$value), '*', STR_PAD_LEFT);
                } elseif (in_array(strtolower($key), array('cvc', 'cvv', 'cc_cvv'))) {
                    $masked[$key] = '***';
                } elseif (in_array(strtolower($key), array('token', 'access_token', 'refresh_token'))) {
                    $masked[$key] = substr((string)$value, 0, 8) . '...[REDACTED]';
                } else {
                    $masked[$key] = '[REDACTED]';
                }
            } else {
                $masked[$key] = $value;
            }
        }
        return $masked;
    }

    /**
     * Get the encryption key for OAuth token storage
     * Key is stored in a file outside the webroot (in DIR_FS_SQL_CACHE)
     * @return string|false Binary encryption key, or false on failure
     */
    function getEncryptionKey() {
        // Determine key storage directory — prefer DIR_FS_SQL_CACHE (outside webroot),
        // fall back to Zen Cart cache dir, and warn if using /tmp
        if (defined('DIR_FS_SQL_CACHE') && is_dir(DIR_FS_SQL_CACHE)) {
            $key_dir = DIR_FS_SQL_CACHE;
        } elseif (defined('DIR_FS_CATALOG') && is_dir(DIR_FS_CATALOG . 'cache/')) {
            $key_dir = DIR_FS_CATALOG . 'cache';
            error_log('QuickBooks Payments WARNING: DIR_FS_SQL_CACHE not available. Using catalog cache directory for encryption key. For better security, configure DIR_FS_SQL_CACHE to a directory outside the webroot.');
        } else {
            $key_dir = '/tmp';
            error_log('QuickBooks Payments CRITICAL: Using /tmp for encryption key storage. This is insecure on shared hosting. Configure DIR_FS_SQL_CACHE to a secure directory outside the webroot.');
        }
        $key_file = rtrim($key_dir, '/') . '/qb_payments.key';

        if (file_exists($key_file)) {
            $hex_key = trim(file_get_contents($key_file));
            if (strlen($hex_key) === 64) {
                return hex2bin($hex_key);
            }
        }

        // Generate a new 256-bit key using exclusive create to prevent race conditions
        // fopen with 'x' fails if the file already exists, preventing two concurrent requests
        // from generating different keys and overwriting each other
        $key = random_bytes(32);
        $fp = @fopen($key_file, 'x');
        if ($fp === false) {
            // Another process created the file between our file_exists check and fopen
            // Re-read and use their key
            if (file_exists($key_file)) {
                $hex_key = trim(file_get_contents($key_file));
                if (strlen($hex_key) === 64) {
                    return hex2bin($hex_key);
                }
            }
            error_log('QuickBooks Payments: Unable to create encryption key file at ' . $key_file);
            return false;
        }
        fwrite($fp, bin2hex($key));
        fflush($fp);
        fclose($fp);
        if (!chmod($key_file, 0600)) {
            error_log('QuickBooks Payments: chmod 0600 failed on key file ' . $key_file . ' — verify file permissions');
        }
        return $key;
    }

    /**
     * Encrypt a value using AES-256-CBC
     * Returns prefixed string "ENC:" + base64(IV + ciphertext)
     * @param string $plaintext Value to encrypt
     * @return string Encrypted value with ENC: prefix, or original value on failure
     */
    function encryptValue($plaintext) {
        if (empty($plaintext)) return '';
        $key = $this->getEncryptionKey();
        if ($key === false) {
            error_log('QuickBooks Payments WARNING: Encryption key unavailable — storing value unencrypted. Check key file permissions.');
            return $plaintext;
        }
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            error_log('QuickBooks Payments WARNING: openssl_encrypt failed — storing value unencrypted.');
            return $plaintext;
        }
        return 'ENC:' . base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt a value encrypted with encryptValue()
     * Detects the ENC: prefix — plain text values pass through unchanged
     * @param string $value Encrypted (or plain text) value
     * @return string Decrypted value, or original value if not encrypted/on failure
     */
    function decryptValue($value) {
        if (empty($value) || substr($value, 0, 4) !== 'ENC:') return $value;
        $key = $this->getEncryptionKey();
        if ($key === false) {
            error_log('QuickBooks Payments CRITICAL: Cannot decrypt token — encryption key unavailable. Check that the key file exists in the cache directory.');
            return $value;
        }
        $data = base64_decode(substr($value, 4), true);
        if ($data === false || strlen($data) < 17) return $value;
        $iv = substr($data, 0, 16);
        $ciphertext = substr($data, 16);
        $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($decrypted === false) {
            error_log('QuickBooks Payments CRITICAL: Decryption failed for ENC: value. The encryption key may have been rotated or the key file deleted. Re-enter OAuth tokens in admin to re-encrypt with the current key.');
            return false;
        }
        return $decrypted;
    }

    /**
     * Auto-encrypt any plain-text OAuth tokens found in the configuration table
     * Called during checkAndAddMissingConfig() to migrate existing installations
     */
    function encryptStoredTokens() {
        global $db;
        $keys_to_encrypt = array(
            'MODULE_PAYMENT_QUICKBOOKS_ACCESS_TOKEN',
            'MODULE_PAYMENT_QUICKBOOKS_REFRESH_TOKEN',
            'MODULE_PAYMENT_QUICKBOOKS_CLIENT_SECRET'
        );
        foreach ($keys_to_encrypt as $config_key) {
            if (defined($config_key)) {
                $value = constant($config_key);
                if (!empty($value) && substr($value, 0, 4) !== 'ENC:') {
                    $encrypted = $this->encryptValue($value);
                    if ($encrypted !== $value) {
                        $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '" . zen_db_input($encrypted) . "' WHERE configuration_key = '" . zen_db_input($config_key) . "'");
                    }
                }
            }
        }
    }

    /**
     * Write log message to file with proper error handling
     * @param string $file Full path to log file
     * @param string $message Content to write
     */
    function writeLogFile($file, $message) {
        $fp = fopen($file, 'a');
        if ($fp !== false) {
            fwrite($fp, $message);
            fclose($fp);
        } else {
            error_log('QuickBooks Payments: Unable to write log file: ' . $file);
        }
    }

    /**
     * Log errors
     */
    function logError($context, $message) {
        // Always log to PHP error log for critical visibility regardless of debug setting
        error_log('QuickBooks Payments [' . $context . ']: ' . substr($message, 0, 500));

        if (defined('MODULE_PAYMENT_QUICKBOOKS_DEBUGGING') && strstr(MODULE_PAYMENT_QUICKBOOKS_DEBUGGING, 'Log')) {
            $key = time() . '_' . zen_create_random_value(4);
            $file = $this->_logDir . '/' . 'QuickBooks_Error_' . $key . '.log';
            $this->writeLogFile($file, date('M-d-Y h:i:s') . " - " . $context . "\n" . $message . "\n");
        }
    }

    /**
     * Translate QuickBooks error codes to customer-friendly messages
     * Based on QuickBooks Payments API error codes documentation:
     * - PMT-4000: fraud_error - Incorrect CVC/CVV
     * - PMT-4001: fraud_error - Incorrect address (AVS mismatch)
     * - PMT-5000: transaction_declined - Generic bank decline
     * - PMT-6000: invalid_request - Invalid field in request
     *
     * @param string $error_code The error code from QuickBooks
     * @param string $error_message The original error message
     * @param string $error_detail Optional detail field from API response
     * @return string Customer-friendly error message
     */
    function getFriendlyDeclineMessage($error_code, $error_message, $error_detail = '') {
        // Primary mapping: QuickBooks Payments API error codes (per Intuit documentation)
        $friendly_messages = array(
            // PMT-4xxx: Fraud/Validation errors
            'PMT-4000' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_CVV_MISMATCH,      // Incorrect CVC/security code
            'PMT-4001' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_AVS_MISMATCH,      // Incorrect address (AVS)
            'PMT-4002' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_EXPIRED_CARD,      // Expired card
            'PMT-4003' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_INVALID_CARD,      // Invalid card number
            'PMT-4004' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_CARD_NOT_SUPPORTED, // Card type not supported
            'PMT-4005' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_INSUFFICIENT_FUNDS, // Insufficient funds
            'PMT-4006' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_LIMIT_EXCEEDED,    // Limit exceeded
            'PMT-4007' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_DUPLICATE,         // Duplicate transaction
            'PMT-4008' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_FRAUD,             // Fraud suspected

            // PMT-5xxx: Transaction declined errors
            'PMT-5000' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_GENERAL,           // Generic bank decline
            'PMT-5001' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_GENERAL,           // Do not honor
            'PMT-5002' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_INSUFFICIENT_FUNDS, // Insufficient funds
            'PMT-5003' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_LIMIT_EXCEEDED,    // Exceeds limit
            'PMT-5004' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_EXPIRED_CARD,      // Card expired
            'PMT-5005' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_INVALID_CARD,      // Invalid card
            'PMT-5006' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_CVV_MISMATCH,      // CVV mismatch
            'PMT-5007' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_AVS_MISMATCH,      // AVS mismatch
            'PMT-5008' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_CARD_NOT_SUPPORTED, // Card not supported
            'PMT-5009' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_FRAUD,             // Suspected fraud
            'PMT-5010' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_GENERAL,           // Lost/stolen card
            'PMT-5011' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_GENERAL,           // Restricted card

            // PMT-6xxx: Invalid request errors
            'PMT-6000' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_INVALID_CARD,      // Invalid request field
            'PMT-6001' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_INVALID_CARD,      // Missing required field
            'PMT-6002' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_INVALID_CARD,      // Invalid card data

            // Common status strings
            'declined' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_GENERAL,
            'DECLINED' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_GENERAL,
            'fraud_error' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_FRAUD,
            'transaction_declined' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_GENERAL,
            'invalid_request' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_INVALID_CARD,

            // AVS specific codes
            'AVS_FAIL' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_AVS_MISMATCH,
            'AVS_STREET_FAIL' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_AVS_STREET_MISMATCH,
            'AVS_ZIP_FAIL' => MODULE_PAYMENT_QUICKBOOKS_DECLINE_AVS_ZIP_MISMATCH,
        );

        // Normalize the error code (uppercase, trim)
        $normalized_code = strtoupper(trim($error_code));

        // Check for specific error code first
        if (!empty($error_code) && isset($friendly_messages[$error_code])) {
            return $friendly_messages[$error_code];
        }

        // Check normalized version
        if (!empty($normalized_code) && isset($friendly_messages[$normalized_code])) {
            return $friendly_messages[$normalized_code];
        }

        // Extract PMT code pattern if embedded in message (e.g., "PMT-4000: error message")
        if (preg_match('/PMT-(\d{4})/i', $error_code . ' ' . $error_message, $matches)) {
            $extracted_code = 'PMT-' . $matches[1];
            if (isset($friendly_messages[$extracted_code])) {
                return $friendly_messages[$extracted_code];
            }
        }

        // Combine error message and detail for pattern matching
        $combined_message = strtolower($error_message . ' ' . $error_detail);

        // Check for common patterns in error message/detail
        // CVV/CVC errors
        if (preg_match('/\b(cvv|cvc|cv2|security\s*code|card\s*verification|incorrect\s*cvc)\b/i', $combined_message)) {
            return MODULE_PAYMENT_QUICKBOOKS_DECLINE_CVV_MISMATCH;
        }

        // Address/AVS errors
        if (preg_match('/\b(avs|address\s*(verification|mismatch|incorrect)|incorrect\s*address|billing\s*address)\b/i', $combined_message)) {
            return MODULE_PAYMENT_QUICKBOOKS_DECLINE_AVS_MISMATCH;
        }

        // ZIP code specific
        if (preg_match('/\b(zip|postal)\s*(code)?\s*(mismatch|incorrect|invalid|fail)/i', $combined_message)) {
            return MODULE_PAYMENT_QUICKBOOKS_DECLINE_AVS_ZIP_MISMATCH;
        }

        // Street address specific
        if (preg_match('/\bstreet\s*(address)?\s*(mismatch|incorrect|invalid|fail)/i', $combined_message)) {
            return MODULE_PAYMENT_QUICKBOOKS_DECLINE_AVS_STREET_MISMATCH;
        }

        // Expired card
        if (strpos($combined_message, 'expired') !== false || strpos($combined_message, 'expiration') !== false) {
            return MODULE_PAYMENT_QUICKBOOKS_DECLINE_EXPIRED_CARD;
        }

        // Invalid card
        if (preg_match('/\b(invalid|incorrect)\s*(card|number|account)/i', $combined_message)) {
            return MODULE_PAYMENT_QUICKBOOKS_DECLINE_INVALID_CARD;
        }

        // Insufficient funds
        if (strpos($combined_message, 'insufficient') !== false || strpos($combined_message, 'not enough') !== false) {
            return MODULE_PAYMENT_QUICKBOOKS_DECLINE_INSUFFICIENT_FUNDS;
        }

        // Limit exceeded
        if (preg_match('/\b(limit|exceed|over\s*limit|maximum)/i', $combined_message)) {
            return MODULE_PAYMENT_QUICKBOOKS_DECLINE_LIMIT_EXCEEDED;
        }

        // Fraud/suspicious
        if (preg_match('/\b(fraud|suspicious|security|blocked|restricted)\b/i', $combined_message)) {
            return MODULE_PAYMENT_QUICKBOOKS_DECLINE_FRAUD;
        }

        // Duplicate
        if (strpos($combined_message, 'duplicate') !== false) {
            return MODULE_PAYMENT_QUICKBOOKS_DECLINE_DUPLICATE;
        }

        // Card not supported
        if (preg_match('/\b(not\s*supported|unsupported|card\s*type)/i', $combined_message)) {
            return MODULE_PAYMENT_QUICKBOOKS_DECLINE_CARD_NOT_SUPPORTED;
        }

        // Do not honor / generic decline
        if (preg_match('/\b(do\s*not\s*honor|decline|rejected|denied|refused)\b/i', $combined_message)) {
            return MODULE_PAYMENT_QUICKBOOKS_DECLINE_GENERAL;
        }

        // Gateway/processing errors
        if (preg_match('/\b(gateway|processor|processing|timeout|unavailable|system)\b/i', $combined_message)) {
            return MODULE_PAYMENT_QUICKBOOKS_DECLINE_GATEWAY_ERROR;
        }

        // Default: ALWAYS return a friendly message, never the raw error code/message
        return MODULE_PAYMENT_QUICKBOOKS_DECLINE_GENERAL;
    }

    /**
     * Send email notification when a transaction is declined
     * @param array $order_info Order information
     * @param string $decline_reason The reason for decline
     * @param string $error_code Error code from payment gateway
     * @param array $response_data Full response data from gateway
     */
    function sendDeclineNotification($order_info, $decline_reason, $error_code = '', $response_data = array()) {
        if (!defined('MODULE_PAYMENT_QUICKBOOKS_DECLINE_EMAIL') || MODULE_PAYMENT_QUICKBOOKS_DECLINE_EMAIL != 'True') {
            return;
        }

        $customer_name = $order_info['billing_name'] ?? ($_SESSION['customer_first_name'] . ' ' . $_SESSION['customer_last_name']);
        $customer_email = $order_info['customers_email_address'] ?? $_SESSION['customer_email_address'] ?? 'Unknown';
        $order_total = $order_info['total'] ?? 'Unknown';
        $currency = $order_info['currency'] ?? 'USD';

        $email_subject = 'Payment Declined - ' . STORE_NAME;

        $email_body = "A credit card transaction was declined on your website.\n\n";
        $email_body .= "-\n";
        $email_body .= "TRANSACTION DETAILS\n";
        $email_body .= "-\n\n";
        $email_body .= "Date/Time: " . date('M d, Y h:i:s A') . "\n";
        $email_body .= "Customer Name: " . $customer_name . "\n";
        $email_body .= "Customer Email: " . $customer_email . "\n";
        $email_body .= "Order Total: " . $currency . " " . number_format((float)$order_total, 2) . "\n\n";
        $email_body .= "-\n";
        $email_body .= "DECLINE INFORMATION\n";
        $email_body .= "-\n\n";
        $email_body .= "Decline Reason: " . $decline_reason . "\n";
        if (!empty($error_code)) {
            $email_body .= "Error Code: " . $error_code . "\n";
        }
        $email_body .= "\n-\n";
        $email_body .= "This notification was sent automatically by the QuickBooks Payments module.\n";

        zen_mail(STORE_OWNER, STORE_OWNER_EMAIL_ADDRESS, $email_subject, $email_body, STORE_OWNER, STORE_OWNER_EMAIL_ADDRESS);
    }

    /**
     * Used to display error message details
     * @return array
     */
    function get_error() {
        global $_GET;

        $error = array('title' => MODULE_PAYMENT_QUICKBOOKS_TEXT_ERROR,
            'error' => htmlspecialchars(urldecode($_GET['error'] ?? ''), ENT_QUOTES, 'UTF-8'));

        return $error;
    }

    /**
     * Check to see whether module is installed
     * @return boolean
     */
    function check() {
        global $db;
        if (!isset($this->_check)) {
            $check_query = $db->Execute("SELECT configuration_value FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = 'MODULE_PAYMENT_QUICKBOOKS_STATUS'");
            $this->_check = $check_query->RecordCount();
        }
        return $this->_check;
    }

    /**
     * Install the payment module and its configuration settings
     */
    function install() {
        global $db;
        $db->Execute("DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_key IN ('" . implode("', '", $this->keys()) . "')");

        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) VALUES ('Enable QuickBooks Payments', 'MODULE_PAYMENT_QUICKBOOKS_STATUS', 'True', 'Do you want to accept payments via QuickBooks Payments?', '6', '0', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())");
        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) VALUES ('Test Mode', 'MODULE_PAYMENT_QUICKBOOKS_TESTMODE', 'Sandbox', 'Use Sandbox for testing, Production for live transactions.', '6', '0', 'zen_cfg_select_option(array(\'Sandbox\', \'Production\'), ', now())");
        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Client ID', 'MODULE_PAYMENT_QUICKBOOKS_CLIENT_ID', '', 'Your QuickBooks Payments Client ID from the Intuit Developer Portal', '6', '0', now())");
        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Client Secret', 'MODULE_PAYMENT_QUICKBOOKS_CLIENT_SECRET', '', 'Your QuickBooks Payments Client Secret from the Intuit Developer Portal', '6', '0', now())");
        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Access Token', 'MODULE_PAYMENT_QUICKBOOKS_ACCESS_TOKEN', '', 'OAuth Access Token (auto-refreshed)', '6', '0', now())");
        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Refresh Token', 'MODULE_PAYMENT_QUICKBOOKS_REFRESH_TOKEN', '', 'OAuth Refresh Token (used to get new access tokens)', '6', '0', now())");
        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Token Expiry', 'MODULE_PAYMENT_QUICKBOOKS_TOKEN_EXPIRY', '0', 'Unix timestamp when the access token expires', '6', '0', now())");
        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Sort order of display.', 'MODULE_PAYMENT_QUICKBOOKS_SORT_ORDER', '0', 'Sort order of display. Lowest is displayed first.', '6', '8', now())");
        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) VALUES ('Set Order Status', 'MODULE_PAYMENT_QUICKBOOKS_ORDER_STATUS_ID', '0', 'Set the status of orders made with this payment module to this value', '6', '0', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) VALUES ('Request CVV Number', 'MODULE_PAYMENT_QUICKBOOKS_USE_CVV', 'True', 'Enable to allow for the card\'s CVV number', '6', '0', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())");
        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) VALUES ('Authorization Type', 'MODULE_PAYMENT_QUICKBOOKS_AUTHORIZATION_TYPE', 'Authorize/Capture', 'Use Authorize to authorize only (capture later), or Authorize/Capture to charge immediately.', '6', '0', 'zen_cfg_select_option(array(\'Authorize\', \'Authorize/Capture\'), ', now())");
        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) VALUES ('Debug Mode', 'MODULE_PAYMENT_QUICKBOOKS_DEBUGGING', 'Off', 'Enable debug mode? Log files will be saved and/or emailed.', '6', '0', 'zen_cfg_select_option(array(\'Off\', \'Log File\', \'Log and Email\'), ', now())");
        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) VALUES ('Decline Email Notification', 'MODULE_PAYMENT_QUICKBOOKS_DECLINE_EMAIL', 'True', 'Send an email notification when a transaction is declined?', '6', '0', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())");
        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) VALUES ('Require AVS Match', 'MODULE_PAYMENT_QUICKBOOKS_AVS_CHECK', 'True', 'Reject transactions where Address Verification (AVS) fails? This adds an extra layer of fraud protection.', '6', '0', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())");
        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) VALUES ('Payment Zone', 'MODULE_PAYMENT_QUICKBOOKS_ZONE', '0', 'If a zone is selected, only enable this payment method for that zone.', '6', '2', 'zen_cfg_pull_down_zone_classes(', 'zen_get_zone_class_title', now())");

        // Create the quickbooks_payments database table
        $db->Execute("CREATE TABLE IF NOT EXISTS quickbooks_payments (
            id int(11) unsigned NOT NULL auto_increment,
            customer_id int(11) NOT NULL default '0',
            order_id int(11) NOT NULL default '0',
            trans_type varchar(50) NOT NULL default '',
            response_code varchar(10) NOT NULL default '',
            status varchar(50) NOT NULL default '',
            message text,
            auth_code varchar(50) NOT NULL default '',
            charge_id varchar(100) NOT NULL default '',
            capture_id varchar(100) NOT NULL default '',
            request_id varchar(100) NOT NULL default '',
            sent longtext,
            received longtext,
            dtime datetime NOT NULL,
            session_id varchar(255) NOT NULL default '',
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY charge_id (charge_id),
            KEY request_id (request_id)
        ) Engine=InnoDB;");
    }

    /**
     * Remove the module and all its settings
     */
    function remove() {
        global $db;
        $db->Execute("DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_key IN ('" . implode("', '", $this->keys()) . "')");

        // Backup the transaction table with timestamp to prevent data loss on repeated uninstall
        $backup_table = 'quickbooks_payments_backup_' . date('Ymd_His');
        $db->Execute("CREATE TABLE " . $backup_table . " AS SELECT * FROM quickbooks_payments");
        $db->Execute("DROP TABLE IF EXISTS quickbooks_payments");
    }

    /**
     * Internal list of configuration keys used for configuration of the module
     * @return array
     */
    function keys() {
        return array(
            'MODULE_PAYMENT_QUICKBOOKS_STATUS',
            'MODULE_PAYMENT_QUICKBOOKS_TESTMODE',
            'MODULE_PAYMENT_QUICKBOOKS_CLIENT_ID',
            'MODULE_PAYMENT_QUICKBOOKS_CLIENT_SECRET',
            'MODULE_PAYMENT_QUICKBOOKS_ACCESS_TOKEN',
            'MODULE_PAYMENT_QUICKBOOKS_REFRESH_TOKEN',
            'MODULE_PAYMENT_QUICKBOOKS_TOKEN_EXPIRY',
            'MODULE_PAYMENT_QUICKBOOKS_SORT_ORDER',
            'MODULE_PAYMENT_QUICKBOOKS_ORDER_STATUS_ID',
            'MODULE_PAYMENT_QUICKBOOKS_USE_CVV',
            'MODULE_PAYMENT_QUICKBOOKS_AUTHORIZATION_TYPE',
            'MODULE_PAYMENT_QUICKBOOKS_DEBUGGING',
            'MODULE_PAYMENT_QUICKBOOKS_DECLINE_EMAIL',
            'MODULE_PAYMENT_QUICKBOOKS_AVS_CHECK',
            'MODULE_PAYMENT_QUICKBOOKS_ZONE'
        );
    }
}
?>
