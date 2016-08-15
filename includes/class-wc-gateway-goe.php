<?php

require_once 'debug.php';

// don't call the file directly
defined( 'ABSPATH' ) or die();

/**
 * goEmerchant Gateway, extending the WooCommerce class.
 *
 * @author Kevin DeMoura
 */
class WC_Gateway_goe extends WC_Payment_Gateway_CC {

    /**
     * Initialize the gateway
     */
    function __construct() {
        $this->id                 = 'goe';
        $this->icon               = false;
        $this->has_fields         = true; // checkout fields
        $this->supports           = array( '' ); 
        $this->method_title       = __( 'goEmerchant', 'wc-goe' );
        $this->method_description =
                __( 'Process transactions using the goEmerchant gateway. '
                . 'Click <a href="http://support.goemerchant.com/transaction-center.aspx">here</a> '
                . 'to visit our support page for details on viewing transaction history, issuing refunds, and more.', 'wc-goe' );

        $this->init_form_fields();
        $this->init_settings();
        $this->currentUserID = wp_get_current_user()->ID;

        $title                    = $this->get_option( 'title' );
        $this->title              = empty( $title ) ? __( 'goEmerchant', 'wc-goe' ) : $title;
        $this->description        = $this->get_option( 'description' );
        $this->instructions       = $this->get_option( 'instructions', $this->description );

        add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
        add_action( 'woocommerce_thankyou_goe', array( $this, 'thank_you_page' ) );
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options') );
        add_action( 'woocommerce_after_my_account', array( $this, 'on_my_account_load' ) );
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
                'title'   => __( 'Enable/Disable', 'wc-goe' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable goEmerchant Gateway', 'wc-goe' ),
                'default' => 'yes'
            ),
            'title' => array(
                'title'   => __( 'Title', 'wc-goe' ),
                'type'    => 'text',
                'description' => __( 'The name of this payment method that your customers will see.', 'wc-goe' ),
                'default' => __( 'Credit Card', 'wc-goe' ),
            ),
            'instructions' => array(
                'title'       => __( 'Instructions', 'wc-goe' ),
                'type'        => 'textarea',
                'description' => __( 'Instructions that will be added to the thank you page and emails. '
                        . 'For more email options, check out the "Emails" tab above.', 'wc-goe' ),
                'default'     => __( 'Thank you for your purchase!', 'wc-goe' ),
            ),
            'gwid' => array(
                'title' => __( 'Merchant Key (Gateway ID)', 'wc-goe' ),
                'type'  => 'text',
                'description' => __( 'You can find your gateway and processor ID by logging into the transaction center and following the steps listed '
                        . '<a href="http://support.goemerchant.com/transaction-center.aspx?article=gateway-options">here</a>.')
            ),
            'pid' => array(
                'title' => __( 'Processor ID', 'wc-goe' ),
                'type'  => 'text',
            ),
            'auth-only' => array(
                'title'   => __( 'Authorize Only', 'wc-goe' ),
                'type'    => 'checkbox',
                'label'   => __( 'If enabled, you must manually submit transactions for settlement in'
                        . ' your goEmerchant Transaction Center in order to capture the funds.'
                        . ' Visit our <a href="http://support.goemerchant.com/transaction-center.aspx?article=submit-credit-card-batch">support page</a>'
                        . ' for a walkthrough of settling transactions.', 'wc-goe' ),
                'default' => 'no'
            )
        );
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
        if ( ! $sent_to_admin && 'goe' === $order->payment_method ) {
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

        $order = wc_get_order($order_id);

        // get customer billing information from WC
        $cust_info = array(
            // Set order ID to match woocommerce order number
            'orderId'           => $order->get_order_number(),
            // Set IP Address for fraud screening
            'ipAddress'         => WC_Geolocation::get_ip_address(),
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
        
        $authOnly     = $this->get_option('auth-only') == 'yes';
        $useSavedCard = $_POST["goe-use-saved-card"] == "yes";
        $saveCard     = $_POST['goe-save-card'] == 'on';
        
        $transactionData = array_merge($this->get_merchant_info(), $cust_info);
        
        //process saved or new card based on input
        if ($useSavedCard) {
            $vaultTransactionData = array_merge(
                                $transactionData, 
                                $this->get_vault_info(), 
                                array('vaultId' => $_POST['goe-selected-card']));
            
            $savedCardCvv = $_POST['goe-card-cvc-saved'];
            
            if ($savedCardCvv == "") {
                $this->add_missing_fields_notice();
                return;
            }
            $vaultTransactionData = array_merge(
                    $vaultTransactionData, array('cVV' => $savedCardCvv));
            if ($authOnly) {
                $rgw->createAuthUsing1stPayVault($vaultTransactionData);
            }
            else {
                $rgw->createSaleUsing1stPayVault($vaultTransactionData);
            }
        } else {
            if (!$this->get_cc() || $this->is_cvv_blank()) {
                $this->add_missing_fields_notice();
                return;
            }
            else {
                $saleTransactionData = array_merge($transactionData, $this->get_cc());
            }
            if ($authOnly) {
                $rgw->createAuth($saleTransactionData);
            } else {
                $rgw->createSale($saleTransactionData);
            }
            if ($saveCard && !$useSavedCard) {
                $vaultData = array_merge($saleTransactionData, $this->get_vault_info());
                $this->save_cc_to_vault($vaultData, $rgw);
            }
        }

        $error_msg = $this->get_error_string($rgw);
        if ($error_msg) {
            wc_add_notice(__('Payment error: ', 'woothemes') . $error_msg, 'error');
            $order->update_status( 'failed' );
            return;
        }
        
        //handles order stock, mark as processing
        // pass in refNum as WC transaction_id for possible future refund support
        // through WC
        $order->payment_complete($rgw->Result["data"]["referenceNumber"]);
        
        // Return thank you redirect
        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($order)
        );
    }
    
    /**
     * Add an error message to session 
     */
    function add_missing_fields_notice($printNotice = FALSE) {
        wc_add_notice(__('Payment error: ', 'woothemes') .
                "Some required fields (*) are missing. Please check below and try again.", 'error');
        return;
    }
    
    /**
     * Print error message immediately
     */
    function print_missing_fields_notice() {
        wc_print_notice(__('Payment error: ', 'woothemes') .
                "Some required fields (*) are missing. Please check below and try again.", 'error');
        return;
    }

    /**
     * Save credit card to the signed-in user's vault.
     * @param array $requestData data array to send to REST gateway
     * @param type $restGW
     */
    function save_cc_to_vault($requestData, $restGW) {
        $restGW->createVaultCreditCardRecord(
                $requestData, NULL, NULL);
        $result = $restGW->Result;
        $unable = "Unable to save credit card: ";
        if ($result["isError"] == TRUE) {
            foreach ($result["errorMessages"] as $index => $err) {
                if ($err == "Credit card account already exists") {
                    $save_err_msg = $unable . 'Payment method already exists. Please delete exisiting card in My Account first.';
                    if (is_account_page()) {
                        wc_print_notice($save_err_msg, 'error');
                    } else {
                        wc_add_notice($save_err_msg, 'error');
                    }
                } else {
                    if (is_account_page()) {
                        wc_print_notice($err, 'error');
                    } else {
                        wc_add_notice($err, 'error');
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
            $save_msg = "Payment method saved.";
            if (is_account_page()) {
                wc_print_notice($save_msg, 'success');
            } else {
                wc_add_notice($save_msg, 'success');
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
     * @param type $isQuery
     * @return array Array with a single item, either the vaultKey or queryVaultKey
     */
    function get_vault_info($isQuery = FALSE) {
        if ($isQuery) {
            return array(
                'queryVaultKey' => 'wc_g0e_' . $this->currentUserID
            );
        } else {
            return array(
                'vaultKey' => 'wc_g0e_' . $this->currentUserID
            );
        }
    }
    
    function get_merchant_info() {
        // Get merchant info from woocommerce -> settings -> checkout -> goe
        $this->gwid = $this->get_option('gwid');
        $this->pid  = $this->get_option('pid');
        $merchant_info = array(
            'merchantKey' => $this->gwid,
            'processorId' => $this->pid
        );
        
        return $merchant_info;
    }
    
    function validate_expiry($isAccountPage) {
        
    }
    
    /**
     * Get array with credit card info to be sent to REST gateway
     * @return array Array of cc info, or FALSE if any required field is blank.
     */
    function get_cc() {
        if (
                $_POST['goe-card-number'] == "" ||
                $_POST['goe-card-expiry'] == ""
            ) {
            return FALSE;
        }
            
        $ccnum = str_replace(array('-', ' '), '', $_POST['goe-card-number']);
        return array(
            'cardNumber'   => $ccnum,
            'cardExpMonth' => substr($_POST['goe-card-expiry'], 0, 2),
            'cardExpYear'  => substr($_POST['goe-card-expiry'], -2),
            'cVV'          => $_POST['goe-card-cvc'],
            'cardType'     => $this->cardType($ccnum)
        );
    }
    
    function is_cvv_blank() {
        return $_POST['goe-card-cvc'] == "";
    }
    
    function is_cvv_saved_blank() {
        return $_POST['goe-card-cvc-saved'] == "";
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
        $html = '<select name="' . esc_attr( $this->id ) . '-selected-card" >';
        // query for cards using REST
        $rgw = new RestGateway();
        $data = array_merge($this->get_merchant_info(), $this->get_vault_info(TRUE));
        $rgw->queryVaultForCreditCardRecords($data, NULL, NULL);
        $result = $rgw->Result;
        
        if (empty($result['data']['creditCardRecords'])) {
            return FALSE;
        }
        
        foreach ($result['data']['creditCardRecords'] as $index => $ccrec) {
            $cardType = $this->cardTypePretty($ccrec["cardNoFirst6"] . "000000" . $ccrec["cardNoLast4"]);
            $html.= "<option value=\"{$ccrec["id"]}\">************{$ccrec["cardNoLast4"]} - {$cardType} - {$ccrec["cardExpMM"]}/{$ccrec["cardExpYY"]}</option>";
        }
        
        $html .= "</select><br><br>";
        return $html;
    }
    
    /**
     * Return credit card type if number is valid.
     * @return string
     * @param $number string The credit card number in full.
     * */
    function cardType($number) {
        $number = preg_replace('/[^\d]/', '', $number);
        if (preg_match('/^3[47][0-9]{13}$/', $number)) {
            return 'amex';
        } elseif (preg_match('/^3(?:0[0-5]|[68][0-9])[0-9]{11}$/', $number)) {
            return 'dc';
        } elseif (preg_match('/^6(?:011|5[0-9][0-9])[0-9]{12}$/', $number)) {
            return 'disc';
        } elseif (preg_match('/^(?:2131|1800|35\d{3})\d{11}$/', $number)) {
            return 'JCB';
        } elseif (preg_match('/^5[1-5][0-9]{14}$/', $number)) {
            return 'mastercard';
        } elseif (preg_match('/^4[0-9]{12}(?:[0-9]{3})?$/', $number)) {
            return 'visa';
        } else {
            return 'Unknown';
        }
    }
    
    function cardTypePretty($number) {
        $number = preg_replace('/[^\d]/', '', $number);
        if (
                preg_match('/^3[47][0-9]{13}$/', $number) || 
                substr($number, 0, 2) == "34" || 
                substr($number, 0, 2) == "37") {
            return 'American Express';
        } elseif (preg_match('/^3(?:0[0-5]|[68][0-9])[0-9]{11}$/', $number)) {
            return 'Diner\'s Club';
        } elseif (preg_match('/^6(?:011|5[0-9][0-9])[0-9]{12}$/', $number)) {
            return 'Discover';
        } elseif (preg_match('/^(?:2131|1800|35\d{3})\d{11}$/', $number)) {
            return 'JCB';
        } elseif (preg_match('/^5[1-5][0-9]{14}$/', $number)) {
            return 'MasterCard';
        } elseif (preg_match('/^4[0-9]{12}(?:[0-9]{3})?$/', $number)) {
            return 'Visa';
        } else {
            return 'Unknown';
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

        if ( ! $this->supports( 'credit_card_form_cvc_on_saved_method' && !is_account_page()) ) {
            $default_fields['card-cvc-field'] = $cvc_field;
        }
        
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
				<label for="' . esc_attr($this->id) . '-save-card">' . __('Save card to My Account?', 'woocommerce-cardpay-goe') . ' </label>
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
            $badCard = "Merchant does not accept this card.<br>";
            $tryAgain = "Please try a different card.<br>";
            $decline = "The issuing bank has declined this transaction. Please try a different card or contact your issuing bank for more information.<br>";
            foreach ($result["errorMessages"] as $index => $err) {
                switch ($err) {
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
                    case 'CVD Data Error' : $errorString .= $decline; break;
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
                        $errorString .= $err;
                        break 2;
                }
            }
            return $errorString;
        }

        //check for validation errors
        if ($result['validationHasFailed']) {
            $errorString .= "Could not process your order. Please correct the following errors: <br>";
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
        
        if (isset($_POST['goe-card-number'], $_POST['goe-card-expiry'])) {
            if ($this->get_cc()) {

                $vaultRequest = array_merge($this->get_cc(), $this->get_merchant_info(), $this->get_vault_info());
                $this->save_cc_to_vault($vaultRequest, new RestGateway());
            }
            else {
                $this->print_missing_fields_notice();
            }
        }
        elseif (isset($_POST["goe-delete-card"])){
            $this->delete_cc_from_vault($_POST["goe-delete-card"]);
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
        $data = array_merge($this->get_merchant_info(), $this->get_vault_info(TRUE));
        $rgw->queryVaultForCreditCardRecords($data, NULL, NULL);
        $result = $rgw->Result;
        
        echo "<h2>Saved Credit Cards</h2>";
        echo "<form action=\"{$my_account_url}\" method=\"post\"><table border=\"1\">";
        echo "<tr><th>Card Type</th><th>Card Number</th><th>Expiry</th></tr>";
        foreach ($result['data']['creditCardRecords'] as $index => $ccrec) {
            $cardType = $this->cardTypePretty($ccrec["cardNoFirst6"] . "000000" . $ccrec["cardNoLast4"]);
            echo "<tr>
                <td>{$cardType}</td>
                <td>************{$ccrec['cardNoLast4']}</td>
                <td>{$ccrec['cardExpMM']} / {$ccrec['cardExpYY']}</td>
                <td><button type=\"submit\" name=\"goe-delete-card\" value=\"{$ccrec['id']}\">Delete</button></td>
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
        $this->apiUrl = "https://secure.1stpaygateway.net/secure/RestGW/Gateway/Transaction/";
        $this->Result = array();
        $this->status = "";
        //apiUrl, result, status have to be declared globally this way, otherwise not all the functions can see it.
    }

    public function createSale($transactionData, $callBackSuccess, $callBackFailure) {
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

    public function createAuth($transactionData, $callBackSuccess, $callBackFailure) {
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

    public function createVaultCreditCardRecord($transactionData, $callBackSuccess, $callBackFailure) {
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
    
    public function queryVaultForCreditCardRecords($transactionData, $callBackSuccess, $callBackFailure) {
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
