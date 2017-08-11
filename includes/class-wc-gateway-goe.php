<?php
require_once 'invalidinputexception_goe.php';
// don't call the file directly
defined( 'ABSPATH' ) or die();

/**
 * Container for URLs used.
 */
class URLPaths_goe
{
    const URL_API = "https://secure.goemerchant.com/secure/RestGW/Gateway/Transaction/";
    const URL_API_VALIDATION = 'https://secure-v.goemerchant.com/secure/RestGW/Gateway/Transaction/';
    const URL_TRANS_CENTER_SUPPORT = 'http://support.goemerchant.com/transaction-center.aspx';
    const URL_GATEWAY_OPTIONS_SUPPORT = "http://support.goemerchant.com/transaction-center.aspx?article=gateway-options";
    const URL_SUBMIT_CC_BATCH_SUPPORT = "http://support.goemerchant.com/transaction-center.aspx?article=submit-credit-card-batch";
}

/**
 * Container for front-end messages.
 */
class Goe_messages
{
    const WC_GATEWAY_ID = "goe";
    const WC_GATEWAY_TITLE = "goEmerchant";
    
    const MSG_AUTH_APPROVED = "Your payment has been approved and is being processed.";
    const DESC_METHOD = "Process transactions using the goEmerchant gateway. Click <a href='http://support.goemerchant.com/transaction-center.aspx'>here</a> to visit our support page for details on viewing transaction history, issuing refunds, and more.";
    const MSG_CARD_ALREADY_EXISTS = "Your payment method was not saved because a card with that number already exists.";
    const MSG_PAYMENT_METHOD_SAVED = "Payment method saved.";
    const MSG_AUTO_RENEW = "If order contains or is part of a subscription, your card will automatically be saved for renewal charges.";
    const ERR_CARD_NUMBER_INVALID = "Credit card number is invalid.";
    const ERR_CARD_EXPIRY_INVALID = "Invalid card expiration date.";
    const ERR_CARD_DELETE_INVALID = "Invalid vault ID received for delete-card operation.";
    const ERR_CARD_SELECT_INVALID = "Invalid vault ID received for select-card operation.";
    const ERR_CARD_CVC_INVALID = "Invalid card CVC.";
    const ERR_EXPIRY_INVALID_FORMAT = "Your expiration date is in an incorrect format.";
    const ERR_TRY_DIFFERENT_CARD = "Please try a different card.<br>";
    const ERR_BAD_CARD = "Merchant does not accept this card.<br>";
    const ERR_CARD_DECLINED = "Authorization declined. Please try a different card or contact your issuing bank for more information.<br>";
    const ERR_MISSING_FIELDS = "Some required fields (*) are invalid or missing. Please check below and try again.";
    const ERR_PROBLEM_PROCESSING = "Please try again later.";
    const ERR_PLEASE_CORRECT = "Could not process your order. Please correct the following errors:";
    const ERR_PARTIAL_VOID = "Partial void not allowed. Please wait for transaction to settle, or enter full amount.";
    const PLEASE_CHOOSE_CARD = "There was an error changing your payment method. If using a new card, ensure that a card with the same account number does not already exist in your account.";
    const PLEASE_ENTER_ID = "Please enter a valid gateway ID and processor ID.";
    const ERR_INVALID_ID = "This merchant account is not active. Please try again later.";
    
    const TITLE_SANDBOX = "Enable Sandbox/Validation";
    const LABEL_SANDBOX = "Configure this plugin to process to a validation environment (secure-v.goemerchant.com).";
    const TITLE_ENABLED = "Enable/Disable";
    const LABEL_ENABLED = 'Enable goEmerchant plugin';
    
    const TITLE_AUTO_RENEW = 'Auto-Renew Subscriptions';
    const LABEL_AUTO_RENEW = 'Automatically charge a customer with a subscription when their renewal payment is due.';
    const TITLE_PAYMENT_METHOD_TITLE = 'Payment Method Title';
    const DESC_PAYMENT_METHOD_TITLE = 'The name of this payment method that your customers will see.';
    const DEFAULT_PAYMENT_METHOD_TITLE = 'Credit Card';
    
    const TITLE_INSTRUCTIONS = "Instructions";
    const DESC_INSTRUCTIONS = 'Instructions that will be added to the thank you page and emails.';
    const DEFAULT_INSTRUCTIONS = 'Thank you for your purchase!';
    
    const TITLE_GATEWAY_ID = "Gateway ID (Merchant Key)";
    const DESC_GATEWAY_ID = "You can find your gateway and processor ID by logging into the transaction center and following the steps listed <a href='http://support.goemerchant.com/transaction-center.aspx?article=gateway-options'>here</a>.";
    
    const TITLE_PROCESSOR_ID = 'Processor ID';
    
    const LABEL_AUTH_ONLY = "If enabled, you must manually submit transactions for settlement in your Transaction Center in order to capture the funds. Visit our <a href='http://support.goemerchant.com/transaction-center.aspx?article=submit-credit-card-batch'>support page</a> for a walkthrough of settling transactions.";
    const TITLE_AUTH_ONLY = "Authorize Only";
    
    const DEFAULT_VAULT_KEY_PREFIX = '';
    const DESC_VAULT_KEY_PREFIX = "A vault key is created when a user saves a payment method to your site for future use. This prefix will be prepended to the user ID number to create a unique vault key, viewable in the Transaction Center. <b>Updating this option will disable your users' current saved payment methods.</b>";
    const TITLE_VAULT_KEY_PREFIX = 'Vault Key Prefix';
    
    const DESC_ORDER_PREFIX = "Text to prepend to the WooCommerce order number. Can be used to distinguish orders from different WooCommerce sites processing through the same goEmerchant account. Only visible within the Transaction Center.";
    const TITLE_ORDER_PREFIX = "Order Number Prefix";
    const DEFAULT_ORDER_PREFIX = '';
    
}

/**
 * goEmerchant Gateway
 *
 * @author Kevin DeMoura
 * @version 1.0.0
 */
class WC_Gateway_goe extends WC_Payment_Gateway_CC {

    static $apiURL = URLPaths_goe::URL_API; //Base URL for REST API requests
    
    /**
     * Initialize the gateway
     */
    function __construct() {
        
        $this->id                 = Goe_messages::WC_GATEWAY_ID;
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
            'subscription_payment_method_change'
            ); 
        if ($this->get_option( 'auto-renew' ) == 'yes') {
            $this->supports[] = 'subscription_payment_method_change_customer';
        }
        $this->method_title       = __(Goe_messages::WC_GATEWAY_TITLE, 'wc-goe' );
        $this->method_description =
                __(Goe_messages::DESC_METHOD, 'wc-goe' );

        $this->init_form_fields();
        $this->init_settings();
        $this->currentUserID = wp_get_current_user()->ID;

        $title                    = $this->get_option( 'payment-method-title' );
        $this->title              = empty( $title ) ? __(Goe_messages::WC_GATEWAY_TITLE, 'wc-goe' ) : $title;
        $this->description        = $this->get_option( 'description' );
        $this->instructions       = $this->get_option( 'instructions', $this->description );
        self::$apiURL             = $this->get_option( 'sandbox' ) == 'yes' ? URLPaths_goe::URL_API_VALIDATION : URLPaths_goe::URL_API;

        //register hooks
        add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thank_you_page' ) );
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options') );
        add_action( 'woocommerce_after_my_account', array( $this, 'on_my_account_load' ) );
        add_action( 'woocommerce_order_details_after_order_table', array($this, 'print_payment_details'), 10, 1);
        
        add_action( 'update_option', array( $this, 'validate_goe_option'), 10, 3);
        add_action( 'admin_notices', array( $this, 'print_admin_error'), 10 );
        
        if (class_exists("WC_Subscription")) {
            add_action("woocommerce_scheduled_subscription_payment_{$this->id}", array($this, 'process_subscription_payment'), 10, 2);
            add_filter('wcs_renewal_order_created', array($this, 'link_recurring_child'), 10, 2);
        }
    }

    /**
     * Admin configuration parameters. Reflected in WooCommerce -> Settings ->
     * Checkout -> goEmerchant
     *
     * @return void
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __(Goe_messages::TITLE_ENABLED, 'wc-goe'),
                'type' => 'checkbox',
                'label' => __(Goe_messages::LABEL_ENABLED, 'wc-goe'),
                'default' => 'no'
            ),
            'sandbox' => array(
                'title' => __(Goe_messages::TITLE_SANDBOX, 'wc-goe'),
                'type' => 'checkbox',
                'label' => __(Goe_messages::LABEL_SANDBOX, 'wc-goe'),
                'default' => 'no'
        ));
        if (class_exists("WC_Subscription")) {
            $this->form_fields['auto-renew'] = array(
                'title' => __(Goe_messages::TITLE_AUTO_RENEW, 'wc-goe'),
                'type' => 'checkbox',
                'label' => __(Goe_messages::LABEL_AUTO_RENEW, 'wc-goe'),
                'default' => 'yes');
        }
        $this->form_fields['payment-method-title'] = array(
            'title' => __(Goe_messages::TITLE_PAYMENT_METHOD_TITLE, 'wc-goe'),
            'type' => 'text',
            'description' => __(Goe_messages::DESC_PAYMENT_METHOD_TITLE, 'wc-goe'),
            'default' => __(Goe_messages::DEFAULT_PAYMENT_METHOD_TITLE, 'wc-goe'),
        );
        $this->form_fields['instructions'] = array(
            'title' => __(Goe_messages::TITLE_INSTRUCTIONS, 'wc-goe'),
            'type' => 'textarea',
            'description' => __(Goe_messages::DESC_INSTRUCTIONS, 'wc-goe'),
            'default' => __(Goe_messages::DEFAULT_INSTRUCTIONS, 'wc-goe'),
        );
        $this->form_fields['gateway-id'] = array(
            'title' => __(Goe_messages::TITLE_GATEWAY_ID, 'wc-goe'),
            'type' => 'text',
            'description' => Goe_messages::DESC_GATEWAY_ID
        );
        $this->form_fields['processor-id'] = array(
            'title' => __(Goe_messages::TITLE_PROCESSOR_ID, 'wc-goe'),
            'type' => 'text'
        );
        $this->form_fields['auth-only'] = array(
            'title' => __(Goe_messages::TITLE_AUTH_ONLY, 'wc-goe'),
            'type' => 'checkbox',
            'label' => __(Goe_messages::LABEL_AUTH_ONLY, 'wc-goe'),
            'default' => 'no'
        );
        $this->form_fields['vault-key-prefix'] = array(
            'title' => __(Goe_messages::TITLE_VAULT_KEY_PREFIX, 'wc-goe'),
            'type' => 'text',
            'description' => __(Goe_messages::DESC_VAULT_KEY_PREFIX, 'wc-goe'),
            'default' => Goe_messages::DEFAULT_VAULT_KEY_PREFIX
        );
        $this->form_fields['order-prefix'] = array(
            'title' => __(Goe_messages::TITLE_ORDER_PREFIX, 'wc-goe'),
            'type' => 'text',
            'description' => __(Goe_messages::DESC_ORDER_PREFIX, 'wc-goe'),
            'default' => Goe_messages::DEFAULT_ORDER_PREFIX
        );
    }

    /**
     * Display err msg for admin on WP dashboard.
     * @param boolean $print To prevent WP from printing on every WC settings page
     */
    public function print_admin_error($print = false) {
        if (!$print) {return;}
        
        $class = 'notice notice-error';
        $message = __(Goe_messages::PLEASE_ENTER_ID, 'wc-goe');

        printf('<div class="%1$s"><p>%2$s</p></div>', $class, $message);
        return;
    }
    
    /**
     * 
     * @param WC_order $order
     * @return boolean true if payment details are succesfully printed,
     * false otherwise
     */
    public function print_payment_details($order) {
        echo "<header><h2>Payment Details</h2></header>";
        
        $rgw = new RestGateway();
        $data = array_merge($this->get_merchant_info(), array('queryReferenceNo' => $order->get_transaction_id()));
        $rgw->query($data); // get query info for transaction
        $result = $rgw->Result;
        if (empty($result['data']['orders'])) {
            return flase;
        }
        
        echo "<table><tr><th>Card Type</th><th>Card Number</th></tr>";
        
        foreach ($result['data']['orders'] as $index => $txn) {
            $ccrec = $txn['ccInfo'];
            $cardType = ucfirst(strtolower($ccrec['cardType']));
            if ($cardType == "Vi") {
                $cardType = "Visa";
            }
            echo "<tr>
                <td>{$cardType}</td>
                <td>{$ccrec['cardNumber']}</td>
                </tr>";
            break;
        }
        
        echo "</table>";
        
        return true;  
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
    public function email_instructions($order, $sent_to_admin, $plain_text = false) {
        if (!$sent_to_admin && $this->id === $order->payment_method) {
            if ($this->instructions) {
                echo wpautop(wptexturize($this->instructions)) . PHP_EOL;
            }
        }
    }

    /**
     * For customer to change payment method of existing subscription
     * @param boolean $useSavedCard true if the customer has selected "Use Existing Card"
     * @param WC_Subscription $subscription The subscription object
     * @return array|NULL If the change was successful, null otherwise
     */
    function change_subscription_payment_method($useSavedCard, $subscription) {
        if ($useSavedCard) {
            $newVaultId = $this->get_selected_card_id();
            update_post_meta(
                    $subscription->id, 'vault_id', $newVaultId); // update subscription with chosen card
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($subscription)
            );
        } else {
            
            $cust_info = array(// if entering a new card, grab default billing address info
                // Set IP Address for fraud screening
                'ipAddress'    => get_ip_address(),
                'ownerName'    => get_user_meta(get_current_user_id(), "billing_first_name", true) . " " . get_user_meta(get_current_user_id(), "billing_last_name", true),
                'ownerCity'    => get_user_meta(get_current_user_id(), "billing_city", true),
                'ownerCountry' => get_user_meta(get_current_user_id(), "billing_country", true),
                'ownerState'   => get_user_meta(get_current_user_id(), "billing_state", true),
                'ownerStreet'  => get_user_meta(get_current_user_id(), "billing_address_1", true),
                'ownerStreet2' => get_user_meta(get_current_user_id(), "billing_address_2", true),
                'ownerZip'     => get_user_meta(get_current_user_id(), "billing_postcode", true)
            );

            $requestData = array_merge(
                    $this->get_merchant_info(), $cust_info, $this->get_cc(), array(
                        'vaultKey' => get_post_meta($subscription->id, 'vault_key', true)
                        ));
            $newVaultId = $this->save_cc_to_vault($requestData, new RestGateway(), true);
            if ($newVaultId) {
                update_post_meta(
                    $subscription->id, 'vault_id', $newVaultId);
                return array(
                    'result'   => 'success',
                    'redirect' => $this->get_return_url($subscription)
                );
            } else {
                new WP_Error( 'saveCCError', "There was a problem saving your payment method." );
            }
        }
        return;
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
        
        $parent_subscription_id = get_post_meta($order->id, 'subscription_id', true); // will be non-null if this is a renewal order
        $authOnly = $this->get_option('auth-only') === 'yes';
        $useSavedCard = $_POST[$this->id . "-use-saved-card"] === "yes";
        $saveCard = // save card if desired or if auto-renew subscriptions is on
                $_POST[$this->id . '-save-card'] === 'on' || 
                (class_exists('WC_Subscription') && wcs_order_contains_subscription($order) && $this->get_option('auto-renew') == 'yes') ||
                ($parent_subscription_id && $this->get_option('auto-renew') === 'yes');
        
        
        // if amt is 0, user is just changing the payment method for their subscription
        if ($order->get_total() == 0) {
            return $this->change_subscription_payment_method($useSavedCard, $order);
        }
        
        // get customer billing information from WC
        $cust_info = array(
            // Set order ID to match woocommerce order number
            'orderId' => $this->get_option('order-prefix') . $order->get_order_number(),
            // Set IP Address for fraud screening
            'ipAddress'         => get_ip_address(),
            'ownerName'         => $order->get_formatted_billing_full_name(),
            'ownerCity'         => $order->billing_city,
            'ownerCountry'      => $order->billing_country,
            'ownerState'        => $order->billing_state,
            'ownerStreet'       => $order->billing_address_1,
            'ownerStreet2'      => $order->billing_address_2,
            'ownerZip'          => $order->billing_postcode,
            'ownerEmail'        => $order->billing_email,
            'ownerPhone'        => $order->billing_phone,
            'transactionAmount' => $order->get_total()
        );

        $transactionData = array_merge($this->get_merchant_info(), $cust_info);
        $vaultId = "";
        //process saved or new card based on input
        if ($useSavedCard) {
            $vaultId = $this->get_selected_card_id();
            $vaultTransactionData = array_merge(
                    $transactionData, $this->get_vault_key(), array('vaultId' => $vaultId));

            $savedCardCvv = $this->get_card_cvc(true);
            $vaultTransactionData = array_merge(
                    $vaultTransactionData, array('cVV' => $savedCardCvv));
            if ($authOnly) {
                $rgw->createAuthUsing1stPayVault($vaultTransactionData);
            } else {
                $rgw->createSaleUsing1stPayVault($vaultTransactionData);
            }
        } else {
            $saleTransactionData = array_merge($transactionData, $this->get_cc());

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
            return;
        }

        if ($rgw->Result['isSuccess']) {
            $refNumber = $rgw->Result["data"]["referenceNumber"]; //handles order stock, marks status as 'processing'
            // pass in refNum as WC transaction_id 
            $order->payment_complete($refNumber);
            wc_add_notice(Goe_messages::MSG_AUTH_APPROVED, 'success');
            $order->add_order_note(Goe_messages::MSG_AUTH_APPROVED);

            if ($saveCard && !$useSavedCard) {
                $vaultData = array_merge($vaultData, $this->get_vault_key());
                $vaultId = $this->save_cc_to_vault($vaultData, new RestGateway());
            }
            if (class_exists('WC_Subscription') && wcs_order_contains_subscription($order)) {
                $subscriptions = wcs_get_subscriptions_for_order($order); // only one subscription allowed per order
                update_post_meta(
                        $order->id, 'vault_id', $vaultId);
                foreach ($subscriptions as $subscription_id => $subscription) {
                    update_post_meta(
                            $subscription->id, 'vault_id', $vaultId);
                    update_post_meta(
                            $subscription->id, 'vault_key', $this->get_vault_key(false, true));
                }
            }
            elseif ($parent_subscription_id) { // the order is a failed renewal order that the customer is trying to pay
                // the payment was successful so the card should be saved for renewal charges
                update_post_meta($parent_subscription_id, 'vault_id', $vaultId); 
                update_post_meta($parent_subscription_id, 'vault_key', $this->get_vault_key(false, true));
            }
            // Return thank you redirect
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order)
            );
        } else { // we shouldn't ever make it this far, but just in case, bail out
            wc_add_notice(Goe_messages::ERR_PROBLEM_PROCESSING, "error");
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
            return; // let the customer manually pay the renewal
        }
        
        $subscriptionId = get_post_meta($order->id, 'subscription_id', true);
        
        $transactionData = array(
            'orderId'  => $this->get_option('order-prefix') . $order->get_order_number(),
            'vaultId'  => get_post_meta($subscriptionId, 'vault_id', true),
            'vaultKey' => get_post_meta($subscriptionId, 'vault_key', true),
            'transactionAmount' => $totalAmount
        );
        $transactionData = array_merge($transactionData,  $this->get_merchant_info());
        $rgw = new RestGateway();
        
        if ($this->get_option('auth-only') == 'yes') {
            $rgw->createAuthUsing1stPayVault($transactionData);
        } else {
            $rgw->createSaleUsing1stPayVault($transactionData);
        }

        $errMsg = $this->get_error_string($rgw);

        if ($errMsg) {
            $order->update_status('failed');
            $order->add_order_note($errMsg);
            if (class_exists('WC_Subscription')) {
                WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($order);
            }
            $order->set_payment_method($this);
        } else {
            $refNumber = $rgw->Result["data"]["referenceNumber"];
            $order->payment_complete($refNumber);
            $order->add_order_note(Goe_messages::MSG_AUTH_APPROVED);
            if (class_exists('WC_Subscription')) {
                WC_Subscriptions_Manager::process_subscription_payments_on_order($order);
            }
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
               return new WP_Error( 'partialVoidError', Goe_messages::ERR_PARTIAL_VOID );
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
        update_post_meta($renewal_order->id, 'subscription_id', $subscription->id);
        return $renewal_order;
    }

    /**
     * Add WooCommerce error notice to session
     * @param bool $printNotice If true, print the notice immediately
     * @return 
     */
    function add_missing_fields_notice($printNotice = false) {
        if ($printNotice) {
            wc_print_notice(__('Payment error: ', 'woothemes') .
                    Goe_messages::ERR_MISSING_FIELDS, 'error');
        }
        else {
            wc_add_notice(__('Payment error: ', 'woothemes') .
                    Goe_messages::ERR_MISSING_FIELDS, 'error');
        }
        return;
    }

    /**
     * Save credit card to the signed-in user's vault.
     * @param array $requestData data array to send to REST gateway
     * @param ResrGateway $restGW
     * @return Vault ID of cc if save is successful, null otherwise
     */
    function save_cc_to_vault($requestData, $restGW, $isPaymentChange = false) {
        // include default billing info with card record
        $requestData = array_merge($requestData, $this->get_customer_billing());
        
        $restGW->createVaultCreditCardRecord($requestData);
        $result = $restGW->Result;
        $unable = "Unable to save credit card: ";
        if ($result["isError"] == TRUE) {
            foreach ($result["errorMessages"] as $index => $err) {
                if ($err == "Credit card account already exists") {
                    if (is_account_page()) {
                        wc_print_notice(Goe_messages::MSG_CARD_ALREADY_EXISTS, 'notice');
                    } elseif($isPaymentChange) {
                        wc_add_notice(Goe_messages::MSG_CARD_ALREADY_EXISTS, 'error');
                    }
                    else {
                        wc_add_notice(Goe_messages::MSG_CARD_ALREADY_EXISTS, 'notice');
                    }
                } else {
                    if (is_account_page()) {
                        wc_print_notice($unable . $err, 'error');
                    } else {
                        wc_add_notice($unable . $err, 'error');
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
                wc_print_notice(Goe_messages::MSG_PAYMENT_METHOD_SAVED, 'success');
            } else {
                wc_add_notice(Goe_messages::MSG_PAYMENT_METHOD_SAVED, 'success');
            }
            return $result["data"]["id"];
        }
        return false;
    }
    
    /**
     * Delete cc from this user's vault.
     * 
     * @param int $ccid The vault ID for the cc record.
     */
    function delete_cc_from_vault($ccid) {
        $rgw = new RestGateway();
        $data = array_merge($this->get_merchant_info(), $this->get_vault_key(), array("id" => $ccid));
        $rgw->deleteVaultCreditCardRecord($data);
    }

    /**
     * 
     * @param boolean $isQuery If the vault info is for a REST query or a normal
     * transaction.
     * @return array Array with a single item, either the vaultKey or queryVaultKey
     */
    function get_vault_key($isQuery = false, $single = false) {
        $vaultKey = $this->get_option('vault-key-prefix') . $this->currentUserID;
        if ($isQuery) {
            return array(
                'queryVaultKey' => $vaultKey
            );
        }
        elseif ($single) {
            return $vaultKey;
        }
        else {
            return array(
                'vaultKey' => $vaultKey
            );
        }
    }
    
    function is_valid_GUID($string) {
        $guid = str_replace('-', '', $string);
        return strlen($guid) == 32 && ctype_xdigit($guid);
    }
    
    function get_merchant_info() {
        // Get merchant info from woocommerce -> settings -> checkout -> goe
        $this->gateway_id = $this->get_option('gateway-id');
        $this->processor_id  = $this->get_option('processor-id');
        
        if (!$this->is_valid_GUID($this->gateway_id) || 
                strlen($this->processor_id) <= 0 || 
                !ctype_digit($this->processor_id)) {
            throw new InvalidInputException_goe(Goe_messages::ERR_INVALID_ID);
        }
        
        $merchant_info = array(
            'merchantKey' => $this->gateway_id,
            'processorId' => $this->processor_id
        );
        
        return $merchant_info;
    }
    
    /**
     * 
     * @return array array of users default WC billing address
     */
    function get_customer_billing() {
        return array(// if entering a new card, grab default billing address info
                // Set IP Address for fraud screening
                'ipAddress'    => get_ip_address(),
                'ownerName'    => get_user_meta(get_current_user_id(), "billing_first_name", true) . " " . get_user_meta(get_current_user_id(), "billing_last_name", true),
                'ownerCity'    => get_user_meta(get_current_user_id(), "billing_city", true),
                'ownerCountry' => get_user_meta(get_current_user_id(), "billing_country", true),
                'ownerState'   => get_user_meta(get_current_user_id(), "billing_state", true),
                'ownerStreet'  => get_user_meta(get_current_user_id(), "billing_address_1", true),
                'ownerStreet2' => get_user_meta(get_current_user_id(), "billing_address_2", true),
                'ownerZip'     => get_user_meta(get_current_user_id(), "billing_postcode", true)
            );
    }
    
    function get_card_number() {
        $cardNumber = sanitize_text_field( $_POST[$this->id . '-card-number'] );
        $cardNumber = str_replace(array('-', ' '), '', $cardNumber);
        if (
                !ctype_digit($cardNumber)   ||
                strlen( $cardNumber ) < 15  ||
                strlen( $cardNumber ) > 16  ||
                !$this->mod10Check($cardNumber)
            ) {
            throw new InvalidInputException_goe(Goe_messages::ERR_CARD_NUMBER_INVALID);
        }
        else {
            return $cardNumber;
        }
    }
    
    /**
     * 
     * @return array Array containing the keys 'cardExpMonth' and 'cardExpYear'
     * @throws InvalidInputException_goe
     */
    function get_card_expiry() {
        $cardExpiry = sanitize_text_field( $_POST[$this->id . '-card-expiry'] );
        if (strlen( $cardExpiry ) == 7 || strlen( $cardExpiry ) == 9) {
            $cardExpMonth = substr($cardExpiry, 0, 2);
            $cardExpYear  = substr($cardExpiry, -2);
        }
        else {
            throw new InvalidInputException_goe(Goe_messages::ERR_EXPIRY_INVALID_FORMAT);
        }
        
        if (
                !ctype_digit($cardExpMonth) ||
                !ctype_digit($cardExpYear)  ||
                $cardExpMonth < 1           ||
                $cardExpMonth > 12          ||
                $cardExpYear  < 0           ||
                $cardExpYear  > 99          ||
                $this->is_date_expired($cardExpMonth, $cardExpYear)
                ) {
            throw new InvalidInputException_goe(Goe_messages::ERR_CARD_EXPIRY_INVALID);
        }
        else {
            return array (
                'cardExpMonth' => $cardExpMonth,
                'cardExpYear'  => $cardExpYear
            );
        }
    }
    
    function get_card_cvc($forSavedCard) {
        $cvc = $forSavedCard ? sanitize_text_field( $_POST[$this->id . '-card-cvc-saved'] ) : sanitize_text_field( $_POST[$this->id . '-card-cvc'] );
        if (
                !ctype_digit($cvc) ||
                strlen( $cvc ) < 3 ||
                strlen( $cvc ) > 4
                ) {
            throw new InvalidInputException_goe(Goe_messages::ERR_CARD_CVC_INVALID);
        }
        else {
            return $cvc;
        }
    }
    
    /**
     * Get array with credit card info to be sent to REST gateway
     * @return array Array of cc info
     * Raises exceptions for invalid input.
     */
    function get_cc() {
        $cardNumber = $this->get_card_number();
        $result = array(
            'cardNumber'   => $cardNumber,
            'cVV'          => $this->get_card_cvc(),
            'cardType'     => $this->getCardType($cardNumber)
        );
        $result = array_merge($result, $this->get_card_expiry());
        return $result;
        
        
    }
    
    function is_date_expired($month, $year) {
        if ($year == date("y")) {
            if ($month < date("m")) {
                return true;
            }
            else {
                return false;
            }
        }
        elseif ($year < date("y")) {
            return true;
        }
        else {
            return false;
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
        $html = '<br><select name="' . esc_attr( $this->id ) . '-selected-card" id="' . esc_attr( $this->id ) . '-selected-card-id" style="color:gray" disabled>' . 
                '<option value="DEFAULT">Choose saved card</option>';
        // query for cards using REST
        $rgw = new RestGateway();
        $data = array_merge($this->get_merchant_info(), $this->get_vault_key(TRUE));
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
        wp_enqueue_script( 'goe-form-handler', plugins_url('/goe-form-handler.js',__FILE__) );

        $fields = array();

        
        
        $existingCardChoice = (
                is_user_logged_in() && !is_account_page() && $this->get_existing_cards_menu()) ? '<input onclick="disableUnusedFields()" type="radio" name="' . esc_attr( $this->id ) . '-use-saved-card" id = "' . esc_attr( $this->id ) . '-use-existing-card-id" value="yes"><label for="' . esc_attr( $this->id ) . '-use-existing-card-id" style="vertical-align: middle"><font size="4"><strong>Use Existing Card</strong></font></label><br>' : '';
        $newCardChoice = (is_user_logged_in() && !is_account_page()) ? '<input onclick="disableUnusedFields()" type="radio" name="' . esc_attr( $this->id ) . '-use-saved-card" id = "' . esc_attr( $this->id ) . '-use-saved-card-id" value="no" checked><label for="' . esc_attr( $this->id ) . '-use-saved-card-id" style="vertical-align: middle"><font size="4"><strong>Use New Card</strong></font></label>' : '';
        
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
        
        $cvc_field = '<p class="form-row form-row-last">
            <label for="' . esc_attr( $this->id ) . '-card-cvc">' . __( 'Card Code', 'woocommerce' ) . ' <span class="required">*</span></label>
            <input id="' . esc_attr( $this->id ) . '-card-cvc" class="input-text wc-credit-card-form-card-cvc" type="text" autocomplete="off" placeholder="' . esc_attr__( 'CVC', 'woocommerce' ) . '" ' . $this->field_name( 'card-cvc' ) . ' style="width:100px" />
        </p><br>';

        if ( !is_account_page()) {
            $default_fields['card-cvc-field'] = $cvc_field;
        }
        
        if (is_user_logged_in() && !is_account_page()) {
                $cvc_field_saved =  $this->get_existing_cards_menu() ?
                        '<p class="form-row form-row-first"><label for="' . esc_attr( $this->id ) . '-card-cvc-saved">' . 
                        __( 'Card Code', 'woocommerce' ) . ' <span class="required">*</span></label><input id="' . 
                        esc_attr( $this->id ) . '-card-cvc-saved" class="input-text wc-credit-card-form-card-cvc" type="text" autocomplete="off" placeholder="' . 
                        esc_attr__( 'CVC', 'woocommerce' ) . '" ' . $this->field_name( 'card-cvc-saved' ) . 
                        ' style="width:100px" disabled/></p>' : "";
                
                $checkbox = '<input id="' . esc_attr($this->id) . '-save-card" class="input-text wc-credit-card-form-save-card" style="vertical-align: middle" type="checkbox" name="' . $this->id . '-save-card' . '"/><label for="' . esc_attr($this->id) . '-save-card" >' . __("Save card to My Account?", 'wc-' . $this->id) . ' </label><br>';
                
            if ($this->get_option('auto-renew') == 'yes') {// show message if subscriptions & auto-renew is on
                $sub_msg = "<br><br><br><br>{$checkbox}" . Goe_messages::MSG_AUTO_RENEW . "<br><br><br>";
            }
            else {
                $sub_msg = "<br><br><br><br>{$checkbox}<br><br><br></p>";
            }

            array_push(
                    $default_fields,
                    $sub_msg,
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
            foreach ($result["errorMessages"] as $index => $err) {
                switch (strtolower($err)) {
                    case 'pick up card':
                    case 'Declined':
                    case 'Auth Declined':
                    case 'Do Not Honor':
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
                    case 'CVD Data Error' : $errorString .= Goe_messages::ERR_CARD_DECLINED; break;
                    case 'CVC2/CID ERROR' :
                        $errorString .= "Invalid CVC.<br>"; break;
                    case 'Card Not Allowed' :
                    case 'Dscv Not Allowed' :
                    case 'DC Not Allowed' :
                    case 'CB Not Allowed' :
                    case 'AX Not Allowed' :
                        $errorString .= Goe_messages::ERR_BAD_CARD;
                        $errorString .= Goe_messages::ERR_TRY_DIFFERENT_CARD; break;
                    default: // Hard error (Payment server unavailable, etc...)
                        $errorString .= "RESPONSE: " . $err;
                        break 2;
                }
            }
            return $errorString;
        }

        //check for validation errors
        if ($result['validationHasFailed']) {
            $errorString .= Goe_messages::ERR_PLEASE_CORRECT . "<br>";
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
        $my_account_url = esc_url( get_permalink( $pageID ) );
        
        if (!is_user_logged_in()) {
            return;
        }
        
        try {
            if (isset($_POST[$this->id . '-card-number'], $_POST[$this->id . '-card-expiry'])) {
                $cardNumber = $this->get_card_number();
                $cardExpiryArray = $this->get_card_expiry();
                $vaultRequest = array_merge(
                        array('cardNumber' => $cardNumber), 
                        array('cardType'   => $this->getCardType($cardNumber)), 
                        $cardExpiryArray, 
                        $this->get_merchant_info(), 
                        $this->get_vault_key()
                );
                $this->save_cc_to_vault($vaultRequest, new RestGateway());
            } elseif (isset($_POST[$this->id . "-delete-card"])) {
                $this->delete_cc_from_vault($this->get_delete_card_id());
            }
        } catch (InvalidInputException_goe $exception) {
            wc_print_notice($exception, 'error');
        }

        echo $this->print_cc_table(); // show saved credit cards
        
        echo "<form action=\"{$my_account_url}\" method=\"post\">";
        echo "<h2>Add New Credit Card</h2>";

        $this->form();

        echo '<input type="submit" value="Add Card"></form>';
    }
    
    function get_selected_card_id() {
        $vaultId = sanitize_text_field( $_POST[$this->id . "-selected-card"] );
        if ($vaultId == 'DEFAULT') {
            throw new InvalidInputException_goe(Goe_messages::PLEASE_CHOOSE_CARD);
        }
        elseif (!ctype_digit($vaultId) ||
                $vaultId <= 0
                ) {
            throw new InvalidInputException_goe(Goe_messages::ERR_CARD_SELECT_INVALID);
        }
        else {
            return $vaultId;
        }
    }
    
    function get_delete_card_id() {
        $vaultId = sanitize_text_field( $_POST[$this->id . "-delete-card"] );
        if (ctype_digit($vaultId)) {
            return $vaultId;
        }
        else {
            throw new InvalidInputException_goe(Goe_messages::ERR_CARD_DELETE_INVALID);
        }
    }

    function get_ip_address() {
        $ipAddress = WC_Geolocation::get_ip_address();
        if ($ipAddress === "::1" || $ipAddress === "localhost") {
            $ipAddress = "";
        }
        echo "IP: " + $ipAddress;
        return $ipAddress;
    }
    
    /**
     * Print table to display user's saved cards.
     */
    function print_cc_table() {
        $pageID = get_option( 'woocommerce_myaccount_page_id' );
        $my_account_url = get_permalink( $pageID );
        $rgw = new RestGateway();
        $data = array_merge($this->get_merchant_info(), $this->get_vault_key(true));
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
     * RestGateway Class: A library of functions used to call the REST web service.
     * This class is required for every PHP web page making a call to REST.
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
