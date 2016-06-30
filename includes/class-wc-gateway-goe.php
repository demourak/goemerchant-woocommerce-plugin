<?php

/**
 * goEmerchant Gateway, extending the WooCommerce class.
 *
 * @author Kevin DeMoura
 */
class WC_Gateway_goe extends WC_Payment_Gateway {

    /**
     * Initialize the gateway
     */
    function __construct() {
        $this->id                 = 'goe';
        $this->icon               = false;
        $this->has_fields         = true; // checkout fields
        $this->supports           = array( 'default_credit_card_form' ); // show WooCommerce's default form on checkout
        $this->method_title       = __( 'goEmerchant', 'wc-goe' );
        $this->method_description = 
                __( 'Process transactions using the goEmerchant gateway. '
                . 'Click <a href="http://support.goemerchant.com/transaction-center.aspx">here</a> '
                . 'to visit our support page for details on viewing transaction history, issuing refunds, and more.', 'wc-goe' );

        $this->init_form_fields();
        $this->init_settings();
        
        $title                    = $this->get_option( 'title' );
        $this->title              = empty( $title ) ? __( 'goEmerchant', 'wc-goe' ) : $title;
        $this->description        = $this->get_option( 'description' );
        $this->instructions       = $this->get_option( 'instructions', $this->description );

        add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
        add_action( 'woocommerce_thankyou_goe', array( $this, 'thank_you_page' ) );
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options') );
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
                'label'   => __( 'When this is enabled, transactions processed through this gateway'
                        . ' will only be authorized and will have to be manually submitted for settlement.'
                        . ' Visit our <a href="http://support.goemerchant.com/transaction-center.aspx?article=submit-credit-card-batch">support page</a>'
                        . ' for a walkthrough of settling transactions. Leave this option unchecked to automatically settle transactions after approval.', 'wc-goe' ),
                'default' => 'no'
            ),
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
            'ownerName'         => $order->get_formatted_billing_full_name(),
            'ownerCity'         => $order->billing_city,
            'ownerCountry'      => $order->billing_country,
            'ownerState'        => $order->billing_state,
            'ownerStreet'       => $order->billing_address_1,
            'ownerStreet2'      => $order->billing_address_2,
            'ownerZip'          => $order->billing_postcode,
            'ownerEmail'        => $order->billing_email,
            'transactionAmount' => $order->get_total()
        );

        // Get merchant info from woocommerce -> settings -> checkout -> goe
        $this->gwid = $this->get_option('gwid');
        $this->pid  = $this->get_option('pid');
        $merchant_info = array(
            'merchantKey' => "$this->gwid",
            'processorId' => "$this->pid"
        );

        $transactionData = array_merge($merchant_info, $cc, $cust_info); // combine all into one array
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

    /**
     * Generates a string to show the customer if an error is encountered when
     * processing with the given RestGateway object.
     * 
     * @param type $restGateway
     * @return string|boolean Returns error string with line breaks if there is an error,
     * otherwise returns false.
     */
    function get_error_string($restGateway){
        $result = $restGateway->Result;
        $errorString = "";
        
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
          $jsondata = json_encode(new Transaction($data),JSON_PRETTY_PRINT);
          $jsondata = utf8_encode($jsondata);
          $jsondata = substr($jsondata, 9); // remove weird inner array
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
            //$case = strtolower($jresult["action"]);
            $this->Result = $jresult;
            return $this->Result;
          }
        }
        catch (Exception $e){
          return $e->getMessage();
        }
    }
    
}

class Transaction implements JsonSerializable {

    /**
     * Transaction class: Ties into the PHP JSON Functions & makes them easily available to the RestGateway class.
     * Using the class like so: $a = json_encode(new Transaction($txnarray), JSON_PRETTY_PRINT)
     * Will produce json data that the gateway should understand.
     */
    public function __construct(array $array) {
        $this->array = $array;
    }

    public function jsonSerialize() {
        return $this->array;
    }

}
