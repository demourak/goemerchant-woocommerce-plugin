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
        $this->method_description = __( 'Process transactions using the goEmerchant gateway.', 'wc-goe' );

        $title                    = $this->get_option( 'title' );
        $this->title              = empty( $title ) ? __( 'goEmerchant', 'wc-goe' ) : $title;
        $this->description        = $this->get_option( 'description' );
        $this->instructions       = $this->get_option( 'instructions', $this->description );

        $this->init_form_fields();
        $this->init_settings();

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
            ),/*
            'description' => array(
                'title'       => __( 'Description', 'wc-goe' ),
                'type'        => 'textarea',
                'description' => __( 'Payment method description that the customer will see on your checkout.', 'wc-goe' ),
                'default'     => __( 'Send your payment directly to +8801****** via bKash. Please use your Order ID as the payment reference. Your order won\'t be shipped until the funds have cleared in our account.', 'wc-bkash' ),
                'desc_tip'    => true,
            ),*/
            'instructions' => array(
                'title'       => __( 'Instructions', 'wc-goe' ),
                'type'        => 'textarea',
                'description' => __( 'Instructions that will be added to the thank you page and emails.', 'wc-goe' ),
                'default'     => __( 'Thank you for your purchase!', 'wc-goe' ),
                'desc_tip'    => true,
            ),/*
            'trans_help' => array(
                'title'       => __( 'Transaction Help Text', 'wc-goe' ),
                'type'        => 'textarea',
                'description' => __( 'Instructions that will be added to the transaction form.', 'wc-goe' ),
                'default'     => __( 'Instructions go here.', 'wc-goe' ),
                'desc_tip'    => true,
            ),*/
            'gwid' => array(
                'title' => __( 'Merchant Key (Gateway ID)', 'wc-goe' ),
                'type'  => 'text',
            ),
            'pid' => array(
                'title' => __( 'Processor ID', 'wc-goe' ),
                'type'  => 'text',
            )
        );
    }

    /**
     * Output for the order received page.
     *
     * @return void
     */
    public function thankyou_page( $order_id ) {
        /*
        if ( $this->instructions ) {
            echo wpautop( wptexturize( wp_kses_post( $this->instructions ) ) );
        }

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

        if ( ! $sent_to_admin && 'bKash' === $order->payment_method && $order->has_status( 'on-hold' ) ) {
            if ( $this->instructions ) {
                echo wpautop( wptexturize( /*$this->instructions*/$debug ) ) . PHP_EOL;
            }
        }

    }

    /**
     * Process the gateway integration
     *
     * @param  int  $order_id
     *
     * @return void
     */
    public function process_payment( $order_id ) {
        
        $rgw = new RestGateway();

        $order = wc_get_order( $order_id ); // object for woocommerce order
        
        //WC_Gateway_goe::testGateway();
       
        //format user cc input
        $cc = array(
            'cardNumber'   => str_replace(array('-', ' '), '', $_POST['goe-card-number']), 
            'cardExpMonth' => substr($_POST['goe-card-expiry'], 0, 2),
            'cardExpYear'  => substr($_POST['goe-card-expiry'], -2),
            'cVV'          => $_POST['goe-card-cvc']
            );
        // get customer billing information
        $cust_info = array (
            'ownerName' => $order->get_formatted_billing_full_name(),
            'ownerCity' => $order->billing_city,
            'ownerCountry' => $order->billing_country,
            'ownerState' => $order->billing_state,
            'ownerStreet' => $order->billing_address_1,
            'ownerStreet2' => $order->billing_address_2,
            'ownerZip' => $order->billing_postcode,
            'ownerEmail' => $order->billing_email
        );
        $this->gwid = $this->get_option('gwid'); // load gateway id from admin settings
        $this->pid  = $this->get_option('pid');  // load processor id
        $form = array( 
            'merchantKey'       => "$this->gwid", 
            'processorId'       => "$this->pid",
            'transactionAmount' => $order->get_total(),
            );
        
        $customer = array_merge($cc, $cust_info);
        
        $transactionData = array_merge($form, $customer); // combine all into one array
        check("Input: " . print_r($transactionData, true));
        $rgw->createSale(
                $transactionData,
                NULL,
                NULL);
        
        check("Result: " . print_r($rgw->result, true));
        
        $error_msg = $this->get_error_string($rgw);
        check("error_msg: $error_msg");
        if ($error_msg) {
            wc_add_notice( __('Payment error: ', 'woothemes') . $error_msg, 'error' );
            return;
        }
        
        // Remove cart
        WC()->cart->empty_cart();
        
        // Return thankyou redirect
        return array(
            'result'    => 'success',
            'redirect'  => $this->get_return_url( $order )
        );
    }
    
    function success(){
        check('Success function called.');
        return;
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
        $code   = $restGateway->status;
        check("Status: $code");
        $errorString = "";
        if ($result["isError"] == TRUE) {
            $errorString .= "There was an error processing your request. Please try again later. <br>";
            foreach ($result["errors"] as $index => $err) {
                $errorString .= $err . "<br>";
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