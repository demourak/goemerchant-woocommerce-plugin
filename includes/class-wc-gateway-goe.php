<?php

include_once 'debug.php';
require_once 'gateway.php';

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
        add_action( 'woocommerce_thankyou_goe', array( $this, 'thankyou_page' ) );
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options') );
    }

    /**
     * Admin configuration parameters
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
    public function thankyou_page( $order_id ) {
        if ( $this->instructions ) {
            echo wpautop( wptexturize( wp_kses_post( $this->instructions ) ) );
        }
        /*
        $order = wc_get_order( $order_id );

        if ( $order->has_status( 'on-hold' ) ) {
            WC_Goe::tranasaction_form( $order_id );
        }*/
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
        global $debug;

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
        //grab input from WC cc form and format appropriately
        $cc = array(
            'cardNumber' => str_replace(array('-', ' '), '', $_POST['goe-card-number']),
            'cardExpMonth' => substr($_POST['goe-card-expiry'], 0, 2),
            'cardExpYear' => substr($_POST['goe-card-expiry'], -2),
            'cVV' => $_POST['goe-card-cvc']
        );

        // get customer billing information
        $cust_info = array(
            'ownerName' => $order->get_formatted_billing_full_name(),
            'ownerCity' => $order->billing_city,
            'ownerCountry' => $order->billing_country,
            'ownerState' => $order->billing_state,
            'ownerStreet' => $order->billing_address_1,
            'ownerStreet2' => $order->billing_address_2,
            'ownerZip' => $order->billing_postcode,
            'ownerEmail' => $order->billing_email,
            'transactionAmount' => $order->get_total()
        );

        // Get merchant info from woocommerce admin settings
        $this->gwid = $this->get_option('gwid');
        $this->pid = $this->get_option('pid');
        $form = array(
            'merchantKey' => "$this->gwid",
            'processorId' => "$this->pid"
        );

        $transactionData = array_merge($form, $cc, $cust_info); // combine all into one array
        //check("Input: " . print_r($transactionData, true));
        check("Setting for enabled/disabled: " . $this->get_option('auth-only'));
        $authOnly = $this->get_option('auth-only') == 'yes';
        if ($authOnly) {
            $rgw->createAuth(
                    $transactionData, NULL, NULL);
        } else {
            $rgw->createSale(
                    $transactionData, NULL, NULL);
        }

        //check("Result: " . print_r($rgw->result, true));

        $error_msg = $this->get_error_string($rgw);
        //check("error_msg: $error_msg");
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
        $result = $restGateway->result;
        $errorString = "";
        
        if ($result["isError"] == TRUE) {
            $errorString = "There was an error processing your request.<br>";
            $badCard = "Merchant does not accept this card.<br>";
            $tryAgain = "Please try a different card or contact us if you feel this is incorrect.<br>";
            $decline = "The issuing bank has declined this transaction. Please try a different card or contact your issuing bank for more information.<br>";
            foreach ($result["errors"] as $index => $err) {
                switch ($err) {
                    case 'Auth Declined':
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
        
        if ($result['validations']) {
            $errorString .= "Could not process your order. Please correct the following errors: <br>";
            foreach ($result["validations"] as $index => $vError) {
                $errorString .= $vError['message'] . "<br>";
            }
            return $errorString;
        }
        
        return FALSE; // no error
    }
    
    static function testGateway() {
        require_once 'test.php';
    }

}