<?php

require_once 'debug.php';

// don't call the file directly
defined( 'ABSPATH' ) or die();

//WooCommerce id for this gateway
define("WC_GATEWAY_ID", "goe");
define("WC_GATEWAY_TITLE", "goEmerchant");

// 
define("URL_API", 'https://secure.goemerchant.com/secure/RestGW/Gateway/Transaction/');
define("URL_API_VALIDATION", 'https://secure-v.goemerchant.com/secure/RestGW/Gateway/Transaction/');
define("URL_TRANS_CENTER_SUPPORT", 'http://support.goemerchant.com/transaction-center.aspx');
define("URL_SUBMIT_CC_BATCH_SUPPORT", "http://support.goemerchant.com/transaction-center.aspx?article=submit-credit-card-batch");
define("URL_GATEWAY_OPTIONS_SUPPORT", "http://support.goemerchant.com/transaction-center.aspx?article=gateway-options");

define("DESC_METHOD", 'Process transactions using the goEmerchant gateway. '
                . 'Click <a href=' . URL_TRANS_CENTER_SUPPORT . '>here</a> '
                . 'to visit our support page for details on viewing transaction history, issuing refunds, and more.');

// define constants for display
define("MSG_AUTH_APPROVED", "Your payment has been approved.");
define("MSG_CARD_ALREADY_EXISTS", "Your payment method was not saved because a card with that number already exists.");
define("MSG_PAYMENT_METHOD_SAVED", "Payment method saved.");
define("ERR_CARD_NUMBER_INVALID", "Credit card number is invalid.");
define("ERR_CARD_EXPIRY_INVALID", "Invalid card expiration date.");
define("ERR_TRY_DIFFERENT_CARD", "Please try a different card.<br>");
define("ERR_BAD_CARD", "Merchant does not accept this card.<br>");
define("ERR_CARD_DECLINED", "Authorization: DECLINED. Please try a different card or contact your issuing bank for more information.<br>");
define("ERR_MISSING_FIELDS", "Some required fields (*) are missing. Please check below and try again.");
define("ERR_PROBLEM_PROCESSING", "Please try again later.");
define("ERR_PLEASE_CORRECT", "Could not process your order. Please correct the following errors:");
define("ERR_PARTIAL_VOID", "Partial void not allowed. Please wait for transaction to settle, or enter full amount.");
define("PLEASE_CHOOSE_CARD", "Please choose a saved card from the menu below.");
define("PLEASE_ENTER_ID", "Please enter a valid gateway ID and processor ID.");

define("TITLE_SANDBOX", "Enable Sandbox/Validation");
define("LABEL_SANDBOX", "Configure this plugin to process to our validation environment (secure-v.goemerchant.com).");
define("TITLE_ENABLED", 'Enable/Disable');
define("LABEL_ENABLED", 'Enable goEmerchant Gateway');

define("TITLE_AUTO_RENEW", 'Auto-Renew Subscriptions');
define("LABEL_AUTO_RENEW", 'Automatically charge a customer with a subscription when their renewal payment is due.');

define("TITLE_PAYMENT_METHOD_TITLE", 'Title');
define("DESC_PAYMENT_METHOD_TITLE", 'The name of this payment method that your customers will see.');
define("DEFAULT_PAYMENT_METHOD_TITLE", 'Credit Card');

define("TITLE_INSTRUCTIONS", "Instructions");
define("DESC_INSTRUCTIONS", 'Instructions that will be added to the thank you page and emails.');
define("DEFAULT_INSTRUCTIONS", 'Thank you for your purchase!');

define("TITLE_GATEWAY_ID", "Gateway ID (Merchant Key)");
define("DESC_GATEWAY_ID", 'You can find your gateway and processor ID by logging into the transaction center and following the steps listed '
                        . '<a href=' . URL_GATEWAY_OPTIONS_SUPPORT . '>here</a>.');

define('TITLE_PROCESSOR_ID', 'Processor ID');

define("LABEL_AUTH_ONLY", 'If enabled, you must manually submit transactions for settlement in'
                        . ' your Transaction Center in order to capture the funds.'
                        . ' Visit our <a href=' . URL_SUBMIT_CC_BATCH_SUPPORT . '>support page</a>'
                        . ' for a walkthrough of settling transactions.');
define("TITLE_AUTH_ONLY", "Authorize Only");

define("DEFAULT_VAULT_KEY_PREFIX", 'WC-');
define("DESC_VAULT_KEY_PREFIX", 'A vault key is created when a user saves a payment method to your site for future use. '
        . 'This prefix will be prepended to the user ID number to create a unique vault key, viewable in the Transaction Center. '
        . '<b>UPDATING THIS OPTION WILL DISABLE YOUR USERS\' CURRENT SAVED PAYMENT METHODS.</b>');
define("TITLE_VAULT_KEY_PREFIX", 'Vault Key Prefix');

define("DESC_ORDER_PREFIX", 'Text to prepend to the WooCommerce order number. '
                        . 'Can be used to distinguish orders from different WooCommerce sites processing through the same goEmerchant account. '
                        . 'Only visible within the Transaction Center.');
define("TITLE_ORDER_PREFIX", 'Order Number Prefix');
define("DEFAULT_ORDER_PREFIX", 'WC-');

/**
 * goEmerchant Gateway, extending the WooCommerce class.
 *
 * @author Kevin DeMoura
 */
class WC_Gateway_goe extends WC_Payment_Gateway_CC {

    static $apiURL = URL_API; //Base URL for REST API requests
    
    /**
     * Initialize the gateway
     */
    function __construct() {
        
        $this->id                 = WC_GATEWAY_ID;
        $this->icon               = false;
        $this->has_fields         = true; // checkout fields
        $this->supports           = array( 
            'refunds',
            'subscriptions', 
            'subscription_cancellation',
            'subscription_suspension', 
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change',
            'subscription_payment_method_change_customer'
            ); 
        $this->method_title       = __( WC_GATEWAY_TITLE, 'wc-goe' );
        $this->method_description =
                __( DESC_METHOD, 'wc-goe' );

        $this->init_form_fields();
        $this->init_settings();
        $this->currentUserID = wp_get_current_user()->ID;

        $title                    = $this->get_option( 'payment-method-title' );
        $this->title              = empty( $title ) ? __( WC_GATEWAY_TITLE, 'wc-goe' ) : $title;
        $this->description        = $this->get_option( 'description' );
        $this->instructions       = $this->get_option( 'instructions', $this->description );
        self::$apiURL             = $this->get_option( 'sandbox' ) == 'yes' ? URL_API_VALIDATION : URL_API;

        //register hooks
        add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thank_you_page' ) );
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options') );
        add_action( 'woocommerce_after_my_account', array( $this, 'on_my_account_load' ) );
        add_action( 'woocommerce_order_details_after_order_table', array($this, 'print_payment_details'), 10, 1);
        
        add_action( 'update_option', array( $this, 'validate_goe_option'), 10, 3);
        add_action( 'admin_notices', array( $this, 'print_admin_error'), 10 );
        
        //add_action( 'woocommerce_scheduled_subscription_payment', array( $this, 'prepare_renewal'), 10, 1);
        add_action( "woocommerce_scheduled_subscription_payment_{$this->id}", array( $this, 'process_subscription_payment'), 10, 2);
        add_action( "woocommerce_subscriptions_changed_failing_payment_method_{$this->id}", 'goe_update_failed_payment_method', 10, 2);
        add_action( "woocommerce_subscription_renewal_payment_failed", 'goe_renewal_payment_failed', 10, 1);
        add_filter( 'wcs_renewal_order_created', array( $this, 'link_recurring_child'), 10, 2);
        //add_filter( 'woocommerce_subscription_periods', array( $this, 'filter_recurring_frequencies'), 10, 1 );
        //add_filter( 'woocommerce_subscription_period_interval_strings', 'goe_do_not_allow_non_single_billing_intervals', 10 );
    }

    /**
     * Admin configuration parameters. Reflected in WooCommerce -> Settings ->
     * Checkout -> goEmerchant
     *
     * @return void
     */
    public function init_form_fields() {
            $autoRenewOption = class_exists('WC_Subscriptions') ? array(
                'title'   => __( TITLE_AUTO_RENEW, 'wc-goe' ),
                'type'    => 'checkbox',
                'label'   => __( LABEL_AUTO_RENEW, 'wc-goe' ),
                'default' => 'yes')
                    : array();
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( TITLE_ENABLED, 'wc-goe' ),
                'type'    => 'checkbox',
                'label'   => __( LABEL_ENABLED, 'wc-goe' ),
                'default' => 'no'
            ),
            'sandbox' => array(
                'title'   => __( TITLE_SANDBOX, 'wc-goe' ),
                'type'    => 'checkbox',
                'label'   => __( LABEL_SANDBOX, 'wc-goe' ),
                'default' => 'no'
            ),
            'auto-renew' => $autoRenewOption,
            'payment-method-title' => array(
                'title'   => __( TITLE_PAYMENT_METHOD_TITLE, 'wc-goe' ),
                'type'    => 'text',
                'description' => __( DESC_PAYMENT_METHOD_TITLE, 'wc-goe' ),
                'default' => __( DEFAULT_PAYMENT_METHOD_TITLE, 'wc-goe' ),
            ),
            'instructions' => array(
                'title'       => __( TITLE_INSTRUCTIONS, 'wc-goe' ),
                'type'        => 'textarea',
                'description' => __( DESC_INSTRUCTIONS, 'wc-goe' ),
                'default'     => __( DEFAULT_INSTRUCTIONS, 'wc-goe' ),
            ),
            'gateway-id' => array(
                'title' => __( TITLE_GATEWAY_ID, 'wc-goe' ),
                'type'  => 'text',
                'description' => DESC_GATEWAY_ID
            ),
            'processor-id' => array(
                'title' => __( TITLE_PROCESSOR_ID, 'wc-goe' ),
                'type'  => 'text',
            ),
            'auth-only' => array(
                'title' => __(TITLE_AUTH_ONLY, 'wc-goe'),
                'type'  => 'checkbox',
                'label' => __(LABEL_AUTH_ONLY, 'wc-goe'),
                'default' => 'no'
            ),
            'vault-key-prefix' => array(
                'title'   => __( TITLE_VAULT_KEY_PREFIX,  'wc-goe' ),
                'type'    => 'text',
                'description' => __( DESC_VAULT_KEY_PREFIX, 'wc-goe' ),
                'default' => DEFAULT_VAULT_KEY_PREFIX
            ),
            'order-prefix' => array(
                'title'   => __( TITLE_ORDER_PREFIX,  'wc-goe' ),
                'type'    => 'text',
                'description' => __( DESC_ORDER_PREFIX, 'wc-goe' ),
                'default' => DEFAULT_ORDER_PREFIX
            )
        );
    }
    
    /**
     * Display err msg for admin on WP dashboard.
     * @param boolean $print To prevent WP from printing on every WC settings page
     */
    public function print_admin_error($print = false) {
        if (!$print) {return;}
        
        $class = 'notice notice-error';
        $message = __(PLEASE_ENTER_ID, 'wc-goe');

        printf('<div class="%1$s"><p>%2$s</p></div>', $class, $message);
        return;
    }
    
    public function print_payment_details($order) {
        echo "<b>Payment Details</b><br>";
        
        $rgw = new RestGateway();
        $data = array_merge($this->get_merchant_info(), array('queryReferenceNo' => $order->get_transaction_id()));
        $rgw->query($data);
        $result = $rgw->Result;
        if (empty($result['data']['orders'])) {
            return;
        }
        
        foreach ($result['data']['orders'] as $index => $txn) {
            $ccrec = $txn['ccInfo'];
            $cardType = ucfirst(strtolower($ccrec['cardType']));
            if ($cardType == "Vi") {
                $cardType = "Visa";
            }
            echo "<tr>
                <td>{$cardType}</td>
                <td>{$ccrec['cardNumber']}</td>
                </tr><br>";
            break;
        }
        
        return;  
    }

    /**
     * Display an error message in the WP dash if merchant enables gateway with
     * no gateway or processor IDs.
     * @param type $option
     * @param type $old_value
     * @param type $value
     */
    public function validate_goe_option($option, $old_value, $value) {
        if ($option == 'woocommerce_' . $this->id . '_settings') {
            if ($value['enabled'] == 'yes') {
                if ($value['gateway-id'] == "" || $value['processor-id'] == "") {
                    $this->print_admin_error(true);
                }
            }
        }
    }
    
    function filter_recurring_frequencies($subscription_periods) {
        $subscription_periods["TestKey"] = "TestValue";
        
        return $subscription_periods;
    }
    
    function goe_do_not_allow_non_single_billing_intervals($billing_intervals) {

        array_splice($billing_intervals, 1);

        return $billing_intervals;
    }

    /**
     * Output for the order received page.
     *
     * @return void
     */
    public function thank_you_page( $order_id ) {
        if ( $this->instructions ) {
            echo wpautop( wptexturize( wp_kses_post( $this->instructions ) ) );
        }
    }

    /**
     * Add content to the WC emails.
     *
     * @access public
     * @param WC_Order $order
     * @param bool $sent_to_admin
     * @param bool $plain_text
     *
     * @return void
     */
    public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
        if ( ! $sent_to_admin && $this->id === $order->payment_method ) {
            if ( $this->instructions ) {
                echo wpautop( wptexturize( $this->instructions ) ) . PHP_EOL;
            }
        }
    }

    /**
     * Process the gateway integration
     * Triggered upon click of "Place Order".
     * @param  int  $order_id
     *
     * @return void
     */
    public function process_payment($order_id) {
        $rgw = new RestGateway();

        $vaultData = array();

        $order = wc_get_order($order_id);

        // get customer billing information from WC
        $cust_info = array(
            // Set order ID to match woocommerce order number
            'orderId' => $this->get_option('order-prefix') . $order->get_order_number(),
            // Set IP Address for fraud screening
            'ipAddress' => WC_Geolocation::get_ip_address(),
            'ownerName' => $order->get_formatted_billing_full_name(),
            'ownerCity' => $order->billing_city,
            'ownerCountry' => $order->billing_country,
            'ownerState' => $order->billing_state,
            'ownerStreet' => $order->billing_address_1,
            'ownerStreet2' => $order->billing_address_2,
            'ownerZip' => $order->billing_postcode,
            'ownerEmail' => $order->billing_email,
            'ownerPhone' => $order->billing_phone,
            'transactionAmount' => $order->get_total()
        );

        $authOnly = $this->get_option('auth-only') == 'yes';
        $useSavedCard = $_POST[$this->id . "-use-saved-card"] == "yes";
        $saveCard = 
                $_POST[$this->id . '-save-card'] == 'on' || wcs_order_contains_subscription($order);

        $transactionData = array_merge($this->get_merchant_info(), $cust_info);

        //process saved or new card based on input
        if ($useSavedCard) {
            if (!$_POST[$this->id . '-selected-card']) {
                //$this->add_missing_fields_notice();
                wc_add_notice(PLEASE_CHOOSE_CARD, 'error');
                return;
            }
            $vaultTransactionData = array_merge(
                    $transactionData, $this->get_vault_info(), array('vaultId' => $_POST[$this->id . '-selected-card']));

            $savedCardCvv = $_POST[$this->id . '-card-cvc-saved'];

            if ($savedCardCvv == "") {
                $this->add_missing_fields_notice();
                return;
            }
            $vaultTransactionData = array_merge(
                    $vaultTransactionData, array('cVV' => $savedCardCvv));
            if ($authOnly) {
                $rgw->createAuthUsing1stPayVault($vaultTransactionData);
            } else {
                $rgw->createSaleUsing1stPayVault($vaultTransactionData);
            }
        } else {
            if (!$this->get_cc() || $this->is_cvv_blank()) {
                $this->add_missing_fields_notice();
                return;
            } elseif (!$this->is_valid_expiry()) {
                wc_add_notice(ERR_CARD_EXPIRY_INVALID, 'error');
                return;
            } else {
                $cardInfo = $this->get_cc();
                if (!$this->mod10Check($cardInfo['cardNumber'])) {
                    wc_add_notice(ERR_CARD_NUMBER_INVALID, 'error');
                    return;
                }
                $saleTransactionData = array_merge($transactionData, $this->get_cc());
            }
            if ($authOnly) {
                $rgw->createAuth($saleTransactionData);
            } else {
                $rgw->createSale($saleTransactionData);
            }
            $vaultData = $saleTransactionData;
        }

        $error_msg = $this->get_error_string($rgw); // checks for decline/error
        if ($error_msg) {
            wc_add_notice(__('Payment error: ', 'woothemes') . $error_msg, 'error'); // informs user of decline/error
            $order->update_status('failed');
            $order->add_order_note($error_msg);
            check("Order: " . print_r($order, true));
            return;
        }

        if ($rgw->Result['isSuccess']) {
            $refNumber = $rgw->Result["data"]["referenceNumber"];
            //handles order stock, marks status as 'processing'
            // pass in refNum as WC transaction_id 
            $order->payment_complete($refNumber);
            wc_add_notice(MSG_AUTH_APPROVED, 'success');
            $order->add_order_note(MSG_AUTH_APPROVED);
            
            if ($saveCard && !$useSavedCard) { // save user's card if desired
                $vaultData = array_merge($vaultData, $this->get_vault_info());
                $this->save_cc_to_vault($vaultData, new RestGateway());
            }

            if (wcs_order_contains_subscription($order)) {
                $subscriptions = wcs_get_subscriptions_for_order($order); // only one subscription allowed per order
                update_post_meta(
                        $order->id, 'recurring_parent_reference_number', $refNumber);
                foreach ($subscriptions as $subscription_id => $subscription) {
                    update_post_meta(
                            $subscription->id, 'recurring_parent_reference_number', $refNumber);
                }
            }
            

            // Return thank you redirect
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order)
            );
        } else { // we shouldn't ever make it this far, but just in case, bail out
            wc_add_notice(ERR_PROBLEM_PROCESSING, "error");
            return;
        }
    }

    /**
     * Triggered by WooCommerce when a renewal payment is due. Processes the order as a
     * reauthorization of the original order that contained the subscription. WC supplies the correct
     * amount for the renewal. Renewal will always process using the same credit card as the parent order.
     * @param type $totalAmount
     * @param type $order Renewal order to be processed
     */
    function process_subscription_payment($totalAmount, $order) {
        if ($this->get_option('auto-renew') == 'no') {
            return;
        }
        $transactionData = array(
            'refNumber' => get_post_meta($order->id, 'recurring_parent_reference_number', true),
            'transactionAmount' => $totalAmount
        );
        $transactionData = array_merge($transactionData, $this->get_merchant_info());
        $rgw = new RestGateway();

        if ($this->get_option('auth-only') == 'yes') {
            $rgw->createReAuth($transactionData);
        } else {
            $rgw->createReSale($transactionData);
        }

        $errMsg = $this->get_error_string($rgw);

        if ($errMsg) {
            $order->update_status('failed');
            $order->add_order_note($errMsg);
            WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($order);
            $order->set_payment_method($this);
        } else {
            $refNumber = $rgw->Result["data"]["referenceNumber"];
            $order->payment_complete($refNumber);
            $order->add_order_note(MSG_AUTH_APPROVED);
            WC_Subscriptions_Manager::process_subscription_payments_on_order($order);
        }
    }

    /**
     * 
     * @param type $order_id Order to be refunded.
     * @param type $amount Amount to be refunded
     * @param type $reason Reason for refund (visible to customer)
     * @return \WP_Error|boolean 
     */
    public function process_refund( $order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);
        $refundData = array (
            'refNumber' => $order->get_transaction_id(),
            'transactionAmount' => $amount
        );
        $refundData = array_merge($refundData, $this->get_merchant_info());
        $rgw = new RestGateway();
        
        $rgw->createCredit($refundData);
        if ($this->get_error_string($rgw)) { // credit failed, try void
            if ($amount != $order->get_total()) { // disallow partial voids
               return new WP_Error( 'partialVoidError', ERR_PARTIAL_VOID );
            }
            else {
                $rgw->performVoid($refundData);
                if ($this->get_error_string($rgw)) {
                    return false;
                }
            }
        }
        return true; // If no error, report successful refund
    }
    
    /**
     * Triggered on creation of a renewal order for a subscription. This saves the
     * subscription's reference number as the order's meta data so that the original
     * payment method can be referenced.
     * @param type $renewal_order
     * @param type $subscription
     * @return type
     */
    function link_recurring_child($renewal_order, $subscription) {
        $refNum = get_post_meta($subscription->id, 'recurring_parent_reference_number', true);
        update_post_meta($renewal_order->id, 'recurring_parent_reference_number', $refNum);
        return $renewal_order;
    }
    
    function goe_renewal_payment_failed($subscription) {
        check("Sub: " . print_r($subscription, true));
    }
    
    /**
     * Update the customer token IDs for a subscription after a customer used the gateway to successfully complete the payment
     * for an automatic renewal payment which had previously failed.
     *
     * @param WC_Order $original_order The original order in which the subscription was purchased.
     * @param WC_Order $renewal_order The order which recorded the successful payment (to make up for the failed automatic payment).
     * @return void
     */
    function goe_update_failed_payment_method($original_order, $new_renewal_order) {
        update_post_meta($original_order->id, 'recurring_parent_reference_number', get_post_meta($new_renewal_order->id, 'recurring_parent_reference_number', true));
    }

    /**
     * Add WooCommerce error notice to session
     * @param bool $printNotice If true, print the notice immediately
     * @return 
     */
    function add_missing_fields_notice($printNotice = false) {
        if ($printNotice) {
            wc_print_notice(__('Payment error: ', 'woothemes') .
                ERR_MISSING_FIELDS, 'error');
        }
        else {
            wc_add_notice(__('Payment error: ', 'woothemes') .
                ERR_MISSING_FIELDS, 'error');
        }
        return;
    }

    /**
     * Save credit card to the signed-in user's vault.
     * @param array $requestData data array to send to REST gateway
     * @param type $restGW
     */
    function save_cc_to_vault($requestData, $restGW) {
        // perform validation before submitting to gateway
        
        
        $restGW->createVaultCreditCardRecord(
                $requestData);
        $result = $restGW->Result;
        $unable = "Unable to save credit card: ";
        if ($result["isError"] == TRUE) {
            foreach ($result["errorMessages"] as $index => $err) {
                if ($err == "Credit card account already exists") {
                    if (is_account_page()) {
                        wc_print_notice(MSG_CARD_ALREADY_EXISTS, 'notice');
                    } else {
                        wc_add_notice(MSG_CARD_ALREADY_EXISTS, 'notice');
                    }
                } else {
                    if (is_account_page()) {
                        wc_print_notice($unable . $err, 'notice');
                    } else {
                        wc_add_notice($unable . $err, 'notice');
                    }
                }
            }
        } elseif ($result["validationHasFailed"]) {
            $failure_msg = "";
            foreach ($result["validationFailures"] as $index => $vfailure) {
                $failure_msg .= $vfailure["message"] . "<br>";
            }
            if (is_account_page()) {
                wc_print_notice($unable . $failure_msg, 'error');
            } else {
                wc_add_notice($unable . $failure_msg, 'error');
            }
        } else {
            if (is_account_page()) {
                wc_print_notice(MSG_PAYMENT_METHOD_SAVED, 'success');
            } else {
                wc_add_notice(MSG_PAYMENT_METHOD_SAVED, 'success');
            }
        }
        return;
    }
    
    /**
     * Delete cc from this user's vault.
     * 
     * @param int $ccid The vault ID for the cc record.
     */
    function delete_cc_from_vault($ccid) {
        $rgw = new RestGateway();
        $data = array_merge($this->get_merchant_info(), $this->get_vault_info(), array("id" => $ccid));
        $rgw->deleteVaultCreditCardRecord($data);
    }

    /**
     * 
     * @param boolean $isQuery If the vault info is for a REST query or a normal
     * transaction.
     * @return array Array with a single item, either the vaultKey or queryVaultKey
     */
    function get_vault_info($isQuery = false) {
        $vaultKey = $this->get_option('vault-key-prefix') . $this->currentUserID;
        if ($isQuery) {
            return array(
                'queryVaultKey' => $vaultKey
            );
        } else {
            return array(
                'vaultKey' => $vaultKey
            );
        }
    }
    
    function get_merchant_info() {
        // Get merchant info from woocommerce -> settings -> checkout -> goe
        $this->gateway_id = $this->get_option('gateway-id');
        $this->processor_id  = $this->get_option('processor-id');
        $merchant_info = array(
            'merchantKey' => $this->gateway_id,
            'processorId' => $this->processor_id
        );
        
        return $merchant_info;
    }
    
    /**
     * 
     * @param string $expiry in the format MM/YY
     */
    function is_valid_expiry() {
        if (strlen($_POST[$this->id . '-card-expiry']) == 7) {
            $expMonth = substr($_POST[$this->id . '-card-expiry'], 0, 2);
            $expYear = substr($_POST[$this->id . '-card-expiry'], -2);
            
            if ($expYear == date("y")) {
                if ($expMonth <= date("m")) {
                    return true;
                }
            }
            elseif ($expYear > date("y")) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Get array with credit card info to be sent to REST gateway
     * @return array Array of cc info, or FALSE if any required field is blank.
     */
    function get_cc() {
        if (
                $_POST[$this->id . '-card-number'] == "" ||
                $_POST[$this->id . '-card-expiry'] == ""
            ) {
            return FALSE;
        }
            
        $ccnum = str_replace(array('-', ' '), '', $_POST[$this->id . '-card-number']);
        return array(
            'cardNumber'   => $ccnum,
            'cardExpMonth' => substr($_POST[$this->id . '-card-expiry'], 0, 2),
            'cardExpYear'  => substr($_POST[$this->id . '-card-expiry'], -2),
            'cVV'          => $_POST[$this->id . '-card-cvc'],
            'cardType'     => $this->getCardType($ccnum)
        );
    }
    
    function is_cvv_blank() {
        return $_POST[$this->id . '-card-cvc'] == "";
    }
    
    function is_cvv_saved_blank() {
        return $_POST[$this->id . '-card-cvc-saved'] == "";
    }
    
    function isDateExpired($month, $year) {
        if ($year == date("y")) {
            if ($month < date("m")) {
                return true;
            }
            else {
                return false;
            }
        }
        elseif ($year < date("y")) {
            return false;
        }
        else {
            return true;
        }
    }
    
    /**
     *  
     * @return string HTML for a drop down of each existing card, or null if 
     * the list is empty, the user is not logged in, or if this method is run
     * on a page other than the "My Account" page.
     */
    function get_existing_cards_menu() {
        if (!is_user_logged_in() || is_account_page()) {
            return FALSE;
        }
        $html = '<select name="' . esc_attr( $this->id ) . '-selected-card" >' . 
                '<option value="">Choose saved card</option>';
        // query for cards using REST
        $rgw = new RestGateway();
        $data = array_merge($this->get_merchant_info(), $this->get_vault_info(TRUE));
        $rgw->queryVaultForCreditCardRecords($data);
        $result = $rgw->Result;
        
        if (empty($result['data']['creditCardRecords'])) {
            return false;
        }
        
        foreach ($result['data']['creditCardRecords'] as $index => $ccrec) {
            $cardType = ucfirst(strtolower($ccrec['cardType']));
            $html.= "<option value=\"{$ccrec["id"]}\">************{$ccrec["cardNoLast4"]} - {$cardType} - {$ccrec["cardExpMM"]}/{$ccrec["cardExpYY"]}</option>";
        }
        
        $html .= "</select><br><br>";
        return $html;
    }

    /**
     * Determine if a number passes a mod-10 check
     * @param $number string representing card number
     * @return int indicating whether check was passed or not
     */
    function mod10Check($number) {
        $checksum = "";
        foreach (str_split(strrev((string) $number)) as $i => $d) {
            $checksum .= ($i % 2 !== 0) ? $d * 2 : $d;
        }
        return (int) ($checksum && array_sum(str_split($checksum)) % 10 === 0);
    }

    /**
     * Determine credit card type based on number
     * @param $number string representing card number
     * @return string indicating card type (or NULL if invalid or unrecognized)
     */
    function getCardType($number) {
        // If too short or non-numeric, return a null value
        if (preg_match("/[^\d\s]/", $number) || strlen((string) $number) < 13) {
            return 'Unknown';
        }
        // Visa can be 13 or 16 or 19 digits long, always start with 4
        if (preg_match("/^4\d{12}(\d{3,6})?$/", $number)) {
            return "Visa";
        }
        // MasterCard can start with 51-55 and are 16 digits long
        elseif (preg_match("/^5[1-5]\d{14}|222[1-9]\d{12}|22[3-9]\d{13}|2[3-6]\d{14}|27[0-1]\d{13}|2720\d{12}$/", $number)) {
            return "MasterCard";
        }
        // Amex starts with 34 or 37 and is always 15 digits
        elseif (preg_match("/^3[47]\d{13}$/", $number)) {
            return "Amex";
        }
        // Discover can start with 3 or 6 but usually start with 6011 or 64 or 65
        // Discover also processess jcb and diners club
        else {
            return "Discover";
        }
    }

    /**
     * Outputs fields for entering credit card information.
     * @since 2.6.0
     */
     public function form() {
        wp_enqueue_script( 'wc-credit-card-form' );

        $fields = array();

        $cvc_field = '<p class="form-row form-row-last">
            <label for="' . esc_attr( $this->id ) . '-card-cvc">' . __( 'Card Code', 'woocommerce' ) . ' <span class="required">*</span></label>
            <input id="' . esc_attr( $this->id ) . '-card-cvc" class="input-text wc-credit-card-form-card-cvc" type="text" autocomplete="off" placeholder="' . esc_attr__( 'CVC', 'woocommerce' ) . '" ' . $this->field_name( 'card-cvc' ) . ' style="width:100px" />
        </p>';
        
        $existingCardChoice = (
                is_user_logged_in() && !is_account_page() && $this->get_existing_cards_menu()) ? '<input type="radio" name="' . esc_attr( $this->id ) . '-use-saved-card" id = "' . esc_attr( $this->id ) . '-use-existing-card-id" value="yes"><label for="' . esc_attr( $this->id ) . '-use-existing-card-id"><h2>Use Existing Card</h2></label><br>' : '';
        $newCardChoice = (is_user_logged_in() && !is_account_page()) ? '<input type="radio" name="' . esc_attr( $this->id ) . '-use-saved-card" id = "' . esc_attr( $this->id ) . '-use-saved-card-id" value="no" checked><label for="' . esc_attr( $this->id ) . '-use-saved-card-id"><h2>Use New Card</h2></label>' : '';
        
        $default_fields = array(
            'newcard-radio-button2' => $newCardChoice,
            'card-number-field' => '<p class="form-row form-row-wide">
                <label for="' . esc_attr( $this->id ) . '-card-number">' . __( 'Card Number', 'woocommerce' ) . ' <span class="required">*</span></label>
                <input id="' . esc_attr( $this->id ) . '-card-number" class="input-text wc-credit-card-form-card-number" type="text" maxlength="20" autocomplete="off" placeholder="&bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull;" ' . $this->field_name( 'card-number' ) . ' />
            </p>',
            'card-expiry-field' => '<p class="form-row form-row-first">
                <label for="' . esc_attr( $this->id ) . '-card-expiry">' . __( 'Expiry (MM/YY)', 'woocommerce' ) . ' <span class="required">*</span></label>
                <input id="' . esc_attr( $this->id ) . '-card-expiry" class="input-text wc-credit-card-form-card-expiry" type="text" autocomplete="off" placeholder="' . esc_attr__( 'MM / YY', 'woocommerce' ) . '" ' . $this->field_name( 'card-expiry' ) . ' />
            </p>'
        );

        //if ( ! $this->supports( 'credit_card_form_cvc_on_saved_method' && !is_account_page()) ) {
            $default_fields['card-cvc-field'] = $cvc_field;
        //}
        
        if (is_user_logged_in() && !is_account_page()) {
                $cvc_field_saved =  $this->get_existing_cards_menu() ?
                        '<p class="form-row form-row-first"><label for="' . esc_attr( $this->id ) . '-card-cvc-saved">' . 
                        __( 'Card Code', 'woocommerce' ) . ' <span class="required">*</span></label><input id="' . 
                        esc_attr( $this->id ) . '-card-cvc-saved" class="input-text wc-credit-card-form-card-cvc" type="text" autocomplete="off" placeholder="' . 
                        esc_attr__( 'CVC', 'woocommerce' ) . '" ' . $this->field_name( 'card-cvc-saved' ) . 
                        ' style="width:100px" /></p>' : "";
            
            array_push(
                    $default_fields,
                    '<p class="form-row form-row-wide">
				<label for="' . esc_attr($this->id) . '-save-card">' . __('Save card to My Account?', 'woocommerce-cardpay-' . $this->id) . ' </label>
				<input id="' . esc_attr($this->id) . '-save-card" class="input-text wc-credit-card-form-save-card" type="checkbox" name="' . $this->id . '-save-card' . '" />
			</p>',
                    $existingCardChoice,
                    $this->get_existing_cards_menu(),
                    $cvc_field_saved
            );
        }

        $fields = wp_parse_args( $fields, apply_filters( 'woocommerce_credit_card_form_fields', $default_fields, $this->id ) );
        ?>

        <fieldset id="wc-<?php echo esc_attr( $this->id ); ?>-cc-form" class='wc-credit-card-form wc-payment-form'>
            <?php do_action( 'woocommerce_credit_card_form_start', $this->id ); ?>
            <?php
                foreach ( $fields as $field ) {
                    echo $field;
                }
            ?>
            <?php do_action( 'woocommerce_credit_card_form_end', $this->id ); ?>
            <div class="clear"></div>
        </fieldset>
        <?php

        if ( $this->supports( 'credit_card_form_cvc_on_saved_method' ) ) {
            echo '<fieldset>' . $cvc_field . '</fieldset>';
        }
    }

    /**
     * Generates a string to show the customer if an error is encountered when
     * processing with the given RestGateway object.
     *
     * @param RestGateway $restGateway gateway object used to process result
     * @return string|boolean Returns error string with line breaks if there is an error,
     * otherwise returns false.
     */
    function get_error_string($restGateway){
        $errorString = "";
        $result = $restGateway->Result;

        if ($result["isError"] == TRUE) {
            $errorString = "There was an error processing your request.<br>";
            $badCard = ERR_BAD_CARD;
            $tryAgain = ERR_TRY_DIFFERENT_CARD;
            $decline = ERR_CARD_DECLINED;
            foreach ($result["errorMessages"] as $index => $err) {
                switch ($err) {
                    case 'Pick up card':
                    case 'PICK UP CARD':
                    case 'Declined':
                    case 'Auth Declined':
                    case 'Do Not Honor':
                    case 'DO NOT HONOR':
                    case 'Call Voice Oper':
                    case 'Hold - Call' :
                    case 'Invalid Card No' :
                    case 'Invalid Exp. Date' :
                    case 'Invalid PIN No' :
                    case 'Lost/Stolen Card' :
                    case 'Invalid PIN' :
                    case 'Over Credit Flr' :
                    case 'Request Denied' :
                    case 'Invalid Card' :
                    case 'CVD Data Error' : $errorString = $decline; break;
                    case 'Card Not Allowed' :
                        $errorString .= $badCard;
                        $errorString .= $tryAgain; break;
                    case 'CVC2/CID ERROR' :
                        $errorString .= "Invalid CVC.<br>"; break;
                    case 'Dscv Not Allowed' :
                        $errorString .= $badCard;
                        $errorString .= $tryAgain; break;
                    case 'DC Not Allowed' :
                        $errorString .= $badCard;
                        $errorString .= $tryAgain; break;
                    case 'CB Not Allowed' :
                        $errorString .= $badCard;
                        $errorString .= $tryAgain; break;
                    case 'AX Not Allowed' :
                        $errorString .= $badCard;
                        $errorString .= $tryAgain; break;
                    default: // Hard error (Payment server unavailable, etc...)
                        $errorString .= "RESPONSE: " . $err;
                        break 2;
                }
            }
            return $errorString;
        }

        //check for validation errors
        if ($result['validationHasFailed']) {
            $errorString .= ERR_PLEASE_CORRECT . "<br>";
            foreach ($result["validationFailures"] as $index => $vError) {
                $errorString .= $vError['message'] . "<br>";
            }
            return $errorString;
        }

        return FALSE; // no error
    }
    
    /**
     * Handles both submitting and interepreting user actions on My Account page.
     * Should run upon load of My Account page.
     */
    public function on_my_account_load() {
        
        $pageID = get_option( 'woocommerce_myaccount_page_id' );
        $my_account_url = get_permalink( $pageID );
        
        if (!is_user_logged_in()) {
            return;
        }
        
        if (isset($_POST[$this->id . '-card-number'], $_POST[$this->id . '-card-expiry'])) {
            if ($this->get_cc()) {
                $cardInfo = $this->get_cc();
                if ($this->is_valid_expiry()) {
                    if ($this->mod10Check($cardInfo['cardNumber'])) {
                        $vaultRequest = array_merge($this->get_cc(), $this->get_merchant_info(), $this->get_vault_info());
                        $this->save_cc_to_vault($vaultRequest, new RestGateway());
                    } else {
                        wc_print_notice(ERR_CARD_NUMBER_INVALID, 'error');
                    }
                } else {
                    wc_print_notice(ERR_CARD_EXPIRY_INVALID, 'error');
                }
            } else {
                $this->add_missing_fields_notice(true);
            }
        } elseif (isset($_POST[$this->id . "-delete-card"])){
            $this->delete_cc_from_vault($_POST[$this->id . "-delete-card"]);
        }
        
        echo $this->print_cc_table(); // show saved credit cards
        
        echo "<form action=\"{$my_account_url}\" method=\"post\">";
        echo "<h2>Add New Credit Card</h2>";

        $this->form();

        echo <<<BUTTON
                <input type="submit" value="Add Card">
</form>
BUTTON;
    }
    
    /**
     * Print table to display user's saved cards.
     */
    function print_cc_table() {
        $pageID = get_option( 'woocommerce_myaccount_page_id' );
        $my_account_url = get_permalink( $pageID );
        $rgw = new RestGateway();
        $data = array_merge($this->get_merchant_info(), $this->get_vault_info(true));
        $rgw->queryVaultForCreditCardRecords($data);
        $result = $rgw->Result;
        echo "<h2>Saved Credit Cards</h2>";
        echo "<form action=\"{$my_account_url}\" method=\"post\"><table border=\"1\">";
        echo "<tr><th>Card Type</th><th>Card Number</th><th>Expiry</th></tr>";
        if ($result['isError']) {
            return; // do not attempt to print details if request was unsuccessful
        }
        foreach ($result['data']['creditCardRecords'] as $index => $ccrec) {
            $cardType = ucfirst(strtolower($ccrec['cardType']));
            echo "<tr>
                <td>{$cardType}</td>
                <td>************{$ccrec['cardNoLast4']}</td>
                <td>{$ccrec['cardExpMM']} / {$ccrec['cardExpYY']}</td>
                <td><button type=\"submit\" name=\"" . $this->id . "-delete-card\" value=\"{$ccrec['id']}\">Delete</button></td>
                </tr>";
        }
        echo "</form></table>";
    }

}

class RestGateway {

    /**
     * RestGateway Class: A library of functions used to call the 1stPayBlaze web service.
     * This class is required for every PHP web page making a call to 1stPayBlaze.
     * This class/file contains all allowed executable methods.
     * Please refer to the gateway documentation web page for specifics on what parameters to use for each call.
     * Last modified: 6/27/2015
     * @author Patrick Petrino
     * @version 1.0.0
     *
     *
     */
    public function __construct() {
        global $apiUrl, $result, $status;
        $this->version = "1.0.0";
        $this->apiUrl = WC_Gateway_goe::$apiURL;
        $this->Result = array();
        $this->status = "";
        //apiUrl, result, status have to be declared globally this way, otherwise not all the functions can see it.
    }

    public function createSale($transactionData, $callBackSuccess = NULL, $callBackFailure = NULL) {
        $apiRequest = $this->apiUrl . "Sale";
        $this->performRequest($transactionData, $apiRequest, $callBackSuccess, $callBackFailure);
        if ($this->status >= 500 && $this->status <= 599) {
            call_user_func($callBackFailure);
        }
        if ($this->status >= 400 && $this->status <= 499) {
            call_user_func($callBackFailure);
        }
        if ($this->status >= 200 && $this->status <= 299) {
            call_user_func($callBackSuccess);
        }
    }

    public function createAuth($transactionData, $callBackSuccess = NULL, $callBackFailure = NULL) {
        $apiRequest = $this->apiUrl . "Auth";
        $this->performRequest($transactionData, $apiRequest, $callBackSuccess, $callBackFailure);
        if ($this->status >= 500 && $this->status <= 599) {
            call_user_func($callBackFailure);
        }
        if ($this->status >= 400 && $this->status <= 499) {
            call_user_func($callBackFailure);
        }
        if ($this->status >= 200 && $this->status <= 299) {
            call_user_func($callBackSuccess);
        }
    }

    public function createCredit($transactionData, $callBackSuccess = NULL, $callBackFailure = NULL){
        $apiRequest = $this->apiUrl . "Credit";
        $this->performRequest($transactionData, $apiRequest, $callBackSuccess, $callBackFailure);
        if ($this->Status >= 500 && $this->Status <= 599 && isset($callBackFailure)){call_user_func ($callBackFailure);}
        if ($this->Status >= 400 && $this->Status <= 499 && isset($callBackFailure)){call_user_func ($callBackFailure);}
        if ($this->Status >= 200 && $this->Status <= 299 && isset($callBackSuccess)){call_user_func ($callBackSuccess);}
    }
    
    public function createReAuth($transactionData, $callBackSuccess = NULL, $callBackFailure = NULL){
        $apiRequest = $this->apiUrl . "ReAuth";
        $this->performRequest($transactionData, $apiRequest, $callBackSuccess, $callBackFailure);
        if ($this->Status >= 500 && $this->Status <= 599 && isset($callBackFailure)){call_user_func ($callBackFailure);}
        if ($this->Status >= 400 && $this->Status <= 499 && isset($callBackFailure)){call_user_func ($callBackFailure);}
        if ($this->Status >= 200 && $this->Status <= 299 && isset($callBackSuccess)){call_user_func ($callBackSuccess);}
    }
    
    public function createReSale($transactionData, $callBackSuccess = NULL, $callBackFailure = NULL){
        $apiRequest = $this->apiUrl . "ReSale";
        $this->performRequest($transactionData, $apiRequest, $callBackSuccess, $callBackFailure);
        if ($this->Status >= 500 && $this->Status <= 599 && isset($callBackFailure)){call_user_func ($callBackFailure);}
        if ($this->Status >= 400 && $this->Status <= 499 && isset($callBackFailure)){call_user_func ($callBackFailure);}
        if ($this->Status >= 200 && $this->Status <= 299 && isset($callBackSuccess)){call_user_func ($callBackSuccess);}
    }
    
    public function query($transactionData, $callBackSuccess = NULL, $callBackFailure = NULL) {
        $apiRequest = $this->apiUrl . "Query";
        $this->performRequest($transactionData, $apiRequest, $callBackSuccess, $callBackFailure);
        if ($this->Status >= 500 && $this->Status <= 599 && isset($callBackFailure)) {
            call_user_func($callBackFailure);
        }
        if ($this->Status >= 400 && $this->Status <= 499 && isset($callBackFailure)) {
            call_user_func($callBackFailure);
        }
        if ($this->Status >= 200 && $this->Status <= 299 && isset($callBackSuccess)) {
            call_user_func($callBackSuccess);
        }
    }    
    
    public function createSaleUsing1stPayVault($transactionData, $callBackSuccess = NULL, $callBackFailure = NULL) {
        $apiRequest = $this->apiUrl . "SaleUsingVault";
        $this->performRequest($transactionData, $apiRequest, $callBackSuccess, $callBackFailure);
        if ($this->Status >= 500 && $this->Status <= 599 && isset($callBackFailure)) {
            call_user_func($callBackFailure);
        }
        if ($this->Status >= 400 && $this->Status <= 499 && isset($callBackFailure)) {
            call_user_func($callBackFailure);
        }
        if ($this->Status >= 200 && $this->Status <= 299 && isset($callBackSuccess)) {
            call_user_func($callBackSuccess);
        }
    }

    public function createAuthUsing1stPayVault($transactionData, $callBackSuccess = NULL, $callBackFailure = NULL) {
        $apiRequest = $this->apiUrl . "AuthUsingVault";
        $this->performRequest($transactionData, $apiRequest, $callBackSuccess, $callBackFailure);
        if ($this->Status >= 500 && $this->Status <= 599 && isset($callBackFailure)) {
            call_user_func($callBackFailure);
        }
        if ($this->Status >= 400 && $this->Status <= 499 && isset($callBackFailure)) {
            call_user_func($callBackFailure);
        }
        if ($this->Status >= 200 && $this->Status <= 299 && isset($callBackSuccess)) {
            call_user_func($callBackSuccess);
        }
    }

    public function createVaultCreditCardRecord($transactionData, $callBackSuccess = NULL, $callBackFailure = NULL) {
        $apiRequest = $this->apiUrl . "VaultCreateCCRecord";
        $this->performRequest($transactionData, $apiRequest, $callBackSuccess, $callBackFailure);
        if ($this->status >= 500 && $this->status <= 599) {
            call_user_func($callBackFailure);
        }
        if ($this->status >= 400 && $this->status <= 499) {
            call_user_func($callBackFailure);
        }
        if ($this->status >= 200 && $this->status <= 299) {
            call_user_func($callBackSuccess);
        }
    }
    
    public function queryVaultForCreditCardRecords($transactionData, $callBackSuccess = NULL, $callBackFailure = NULL) {
        $apiRequest = $this->apiUrl . "VaultQueryCCRecord";
        $this->performRequest($transactionData, $apiRequest, $callBackSuccess, $callBackFailure);
        if ($this->status >= 500 && $this->status <= 599) {
            call_user_func($callBackFailure);
        }
        if ($this->status >= 400 && $this->status <= 499) {
            call_user_func($callBackFailure);
        }
        if ($this->status >= 200 && $this->status <= 299) {
            call_user_func($callBackSuccess);
        }
    }

    public function deleteVaultCreditCardRecord($transactionData, $callBackSuccess = NULL, $callBackFailure = NULL) {
        $apiRequest = $this->apiUrl . "VaultDeleteCCRecord";
        $this->performRequest($transactionData, $apiRequest, $callBackSuccess, $callBackFailure);
        if ($this->status >= 500 && $this->status <= 599 && isset($callBackFailure)) {
            call_user_func($callBackFailure);
        }
        if ($this->status >= 400 && $this->status <= 499 && isset($callBackFailure)) {
            call_user_func($callBackFailure);
        }
        if ($this->Status >= 200 && $this->Status <= 299 && isset($callBackSuccess)) {
            call_user_func($callBackSuccess);
        }
    }
    
    public function performVoid($transactionData, $callBackSuccess = NULL, $callBackFailure = NULL){
        $apiRequest = $this->apiUrl . "Void";
        $this->performRequest($transactionData, $apiRequest, $callBackSuccess, $callBackFailure);
        if ($this->Status >= 500 && $this->Status <= 599 && isset($callBackFailure)){call_user_func ($callBackFailure);}
        if ($this->Status >= 400 && $this->Status <= 499 && isset($callBackFailure)){call_user_func ($callBackFailure);}
        if ($this->Status >= 200 && $this->Status <= 299 && isset($callBackSuccess)){call_user_func ($callBackSuccess);}
    }

    protected function performRequest($data, $apiRequest, $callBackSuccess = NULL, $callBackFailure = NULL){
            /**
            * performRequest: this function is responsible for actually submitting the gateway request.
            * It also parses the response and sends it back to the original call.
            * The function works as follows:
            * 1. Set up input data so the gateway can understand it
            * 2. Set up cURL request. Note that since this is SOAP we have to pass very specific options.
            * Also note that since cURL is picky, we have to turn off SSL verification. We're still transmitting https, though.
            * 3. Parse the response based on the information returned from the gateway and return it as an array.
            * The resulting array is stored in $this->Result in the RestGateway object.
            */
        try{
          if ($data == NULL){$data = array(); }
          $url = $apiRequest;
          $this->Result = array();
          $jsondata = json_encode($data, JSON_PRETTY_PRINT);
          $jsondata = utf8_encode($jsondata);
          $curl_handle=curl_init();
          curl_setopt($curl_handle, CURLOPT_URL, $url);
          curl_setopt($curl_handle, CURLOPT_CUSTOMREQUEST, "POST");
          curl_setopt($curl_handle, CURLOPT_POSTFIELDS, $jsondata);
          curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, TRUE);
          curl_setopt($curl_handle, CURLOPT_HTTPHEADER, array(
          "Content-type: application/json; charset-utf-8",
          "Content-Length: " . strlen($jsondata)));
          curl_setopt($curl_handle, CURLOPT_SSL_VERIFYPEER, FALSE);
          $response = curl_exec($curl_handle);
          $this->Status = curl_getinfo($curl_handle,CURLINFO_HTTP_CODE);
          if(connection_aborted()){
              //This will handle aborted requests that PHP can detect, returning a result that indicates POST was aborted.
             $this->Result = array(
               "isError" => TRUE,
               "errorMessages" => "Request Aborted",
               "isValid" => FALSE,
               "validations" => array(),
               "action" => "gatewayError");
              return $this->Result;
          }
          if (curl_errno($curl_handle) == 28 ){
            //This will handle timeouts as per cURL error definitions.
            $this->Result = array(
              "isError" => TRUE,
              "errorMessages" => "Request Timed Out",
              "isValid" => FALSE,
              "validations" => array(),
              "action" => "gatewayError");
              return $this->Result;
          }
          else{
            $jresult = (json_decode($response, TRUE));
            $this->Result = $jresult;
            return $this->Result;
          }
        }
        catch (Exception $e){
          return $e->getMessage();
        }
    }

}
