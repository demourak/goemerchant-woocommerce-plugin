<?php
/*
Plugin Name: GoEmerchant Gateway for WooCommerce
Plugin URI: http://goemerchant.com
Description: Plugin for processing WooCommerce transactions through the goEmerchant gateway.
Version: 1.0
Author: Kevin DeMoura
Author URI: http://goemerchant.com
*/

// don't call the file directly
defined( 'ABSPATH' ) or die();

/**
 * WooCommerce - goEmerchant integration
 *
 * @author Kevin DeMoura
 */
class Goemerchant_Plugin {

    private $db_version = '1.0';
    private $version_key = '_goe_version';

    /**
     * Start plugin
     */
    public function __construct() {
        add_action( 'plugins_loaded', array($this, 'init') );
        add_filter( 'woocommerce_payment_gateways', array($this, 'register_gateway') );

        register_activation_hook( __FILE__, array($this, 'install') );
    }

    /**
     * Start once all plugins are loaded
     *
     * @return void
     */
    function init() {
        if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
            return;
        }

        require_once dirname( __FILE__ ) . '/includes/class-wc-gateway-goe.php';
    }

    /**
     * Register WooCommerce Gateway
     *
     * @param  array  $gateways
     *
     * @return array
     */
    function register_gateway( $gateways ) {
        $gateways[] = 'WC_Gateway_goe';

        return $gateways;
    }

    /**
     * @return void
     */
    function install() {
        global $wpdb;
    }
}

new Goemerchant_Plugin();