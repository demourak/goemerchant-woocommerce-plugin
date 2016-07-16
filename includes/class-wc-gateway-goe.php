<?php

require_once 'debug.php';

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
        //$this->payment_fields();
        $this->currentUserID = wp_get_current_user()->ID;

        $title                    = $this->get_option( 'title' );
        $this->title              = empty( $title ) ? __( 'goEmerchant', 'wc-goe' ) : $title;
        $this->description        = $this->get_option( 'description' );
        $this->instructions       = $this->get_option( 'instructions', $this->description );

        add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
        add_action( 'woocommerce_thankyou_goe', array( $this, 'thank_you_page' ) );
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options') );
//        add_filter( 'woocommerce_new_customer_data', 'update_user_id');
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
            ),
        );
    }
    
//    public function update_user_id($custData) {
//        $this->currentUserID = $custData['user_login'];
//        check("Cust Data: " . print_r($custData, true));
//        return $custData;
//    }

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

        $order = wc_get_order($order_id); // object for woocommerce order
        //grab input from WC default cc form and format appropriately
        $cc = array(
            'cardNumber'   => str_replace(array('-', ' '), '', $_POST['goe-card-number']),
            'cardExpMonth' => substr($_POST['goe-card-expiry'], 0, 2),
            'cardExpYear'  => substr($_POST['goe-card-expiry'], -2),
            'cVV'          => $_POST['goe-card-cvc']
        );

        // get customer billing information from WC
        $cust_info = array(
            // Set order ID to match woocommerce order number
            'orderId'           => $order->get_order_number(),
            // Set IP Address for fraud screening
            //'ipAddress'         => WC_Geolocation::get_ip_address(),
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
        
        //$ip = file_get_contents('https://api.ipify.org');

        // Get merchant info from woocommerce -> settings -> checkout -> goe
        $this->gwid = $this->get_option('gwid');
        $this->pid  = $this->get_option('pid');
        $merchant_info = array(
            'merchantKey' => $this->gwid,
            'processorId' => $this->pid
            //'ipAddress'   => $ip
        );

        $transactionData = array_merge($merchant_info, $cc, $cust_info); // combine all into one array
        
        $saveCard = $_POST['goe-save-card'] == 'on';
        if ($saveCard) {
            $vaultKey = array(
                'vaultKey' => 'wc_g0e_' . $this->currentUserID,
                'cardType' => $cc['cardNumber']
            );
            $vaultData = array_merge($transactionData, $vaultKey);
            $this->save_cc_to_vault($vaultData, $rgw);
        }
        
        $authOnly = $this->get_option('auth-only') == 'yes';
        if ($authOnly) {
            $rgw->createAuth(
                    $transactionData, NULL, NULL);
        } else {
            $rgw->createSale(
                    $transactionData, NULL, NULL);
        }

        $error_msg = $this->get_error_string($rgw);
        if ($error_msg) {
            wc_add_notice(__('Payment error: ', 'woothemes') . $error_msg, 'error');
            $order->update_status( 'failed' );
            return;
        }

        if ($authOnly) {
            $order->update_status( 'on-hold', __( 'Payment authorized. Settle to receive funds.', 'wc-goe') );
        }
        else {
            $order->update_status( 'processing' );
        }

        $order->reduce_order_stock();
        WC()->cart->empty_cart();
        
        // Return thank you redirect
        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($order)
        );
    }
    
    function save_cc_to_vault($requestData, $restGW) {
        $restGW->createVaultCreditCardRecord(
                    $requestData, NULL, NULL);
        $result = $restGW->Result;
        if ($result["isError"] == TRUE) {
            foreach ($result["errorMessages"] as $index => $err) {
                if ($err == "Credit card account already exists") {
                    wc_add_notice( 'Unable to save credit card: Payment method already exists. To modify, please update in My Account.' , 'notice');
                }
            }
        }
        return;
    }
    
    /**
     * Return credit card type if number is valid
     * @return string
     * @param $number string
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
            return 'mc';
        } elseif (preg_match('/^4[0-9]{12}(?:[0-9]{3})?$/', $number)) {
            return 'visa';
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

        $default_fields = array(
            'card-number-field' => '<p class="form-row form-row-wide">
                <label for="' . esc_attr( $this->id ) . '-card-number">' . __( 'Card Number', 'woocommerce' ) . ' <span class="required">*</span></label>
                <input id="' . esc_attr( $this->id ) . '-card-number" class="input-text wc-credit-card-form-card-number" type="text" maxlength="20" autocomplete="off" placeholder="&bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull;" ' . $this->field_name( 'card-number' ) . ' />
            </p>',
            'card-expiry-field' => '<p class="form-row form-row-first">
                <label for="' . esc_attr( $this->id ) . '-card-expiry">' . __( 'Expiry (MM/YY)', 'woocommerce' ) . ' <span class="required">*</span></label>
                <input id="' . esc_attr( $this->id ) . '-card-expiry" class="input-text wc-credit-card-form-card-expiry" type="text" autocomplete="off" placeholder="' . esc_attr__( 'MM / YY', 'woocommerce' ) . '" ' . $this->field_name( 'card-expiry' ) . ' />
            </p>'
        );

        if ( ! $this->supports( 'credit_card_form_cvc_on_saved_method' ) ) {
            $default_fields['card-cvc-field'] = $cvc_field;
        }
        
        if (is_user_logged_in()) {
            array_push(
                    $default_fields, '<p class="form-row form-row-wide hide-if-token">
				<label for="' . esc_attr($this->id) . '-save-card">' . __('Save card to My Account?', 'woocommerce-cardpay-goe') . ' </label>
				<input id="' . esc_attr($this->id) . '-save-card" class="input-text wc-credit-card-form-save-card" type="checkbox" name="' . $this->id . '-save-card' . '" />
			</p>'
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
     * @param type $restGateway
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
                        $errorString .= "Please try again later.<br>";
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
        $this->result = array();
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
