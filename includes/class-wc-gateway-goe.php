<?php

/**
 * bKash Payment gateway
 *
 * @author Tareq Hasan
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

        if ( $this->instructions ) {
            echo wpautop( wptexturize( wp_kses_post( $this->instructions ) ) );
        }

        $order = wc_get_order( $order_id );

        if ( $order->has_status( 'on-hold' ) ) {
            WC_Goe::tranasaction_form( $order_id );
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
        global $debug;
        require_once 'gateway.php';

        $order = wc_get_order( $order_id ); // object for woocommerce order
       
        
        /*$form = array (
            'merchantKey'       => $this->$settings['gwid'],
            'processorId'       => $this->$settings['pid'],
            'transactionAmount' => $order->$order_total,
            'cardNumber'        => str_replace(array('-', ' '), '', $_POST['goe-card-number']), // from woocommerce default cc form
            'cardExpMonth'      => substr($_POST['goe-card-expiry'], 0, 2),
            'cardExpYear'       => substr($_POST['goe-card-expiry'], -2),
            'cVV'               => $_POST['goe-card-cvc']
        );*/
        $form = array (
            'merchantKey'       => "8854e919-b1a6-475c-803c-121489a35df2",
            'processorId'       => "74640",
            'transactionAmount' => "1.00",
            'cardNumber'        => "4111111111111111", // from woocommerce default cc form
            'cardExpMonth'      => "05",
            'cardExpYear'       => "18",
            'cVV'               => ""
        );
        $debug = print_r($form, true);
        echo "<script>window.alert($debug); console.log($debug);</script>";
        
        $RestGateway->createSale(
                $form,
                'success',
                'errors_and_validation');
        // Remove cart
        WC()->cart->empty_cart();
        
        // Return thankyou redirect
        return array(
            'result'    => 'success',
            'redirect'  => $this->get_return_url( $order )
        );
    }
    
    function success(){
        global $RestGateway;
        //Insert success handler code here.
        //See 'Handling Success'
        echo "<h1> success function called. </h1>";
        echo "<br>";
    }
    
    function errors_and_validation(){
        global $RestGateway;
        echo "<h1> Failure function called. </h1>";
        echo "<br>";
    }

}