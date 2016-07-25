<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * WC_Cardpay_Authnet_Credit_Cards
 */
class WC_Gateway_goe_Credit_Cards {
	/**
	* Constructor
	*/
	public function __construct() {
		add_action( 'woocommerce_after_my_account', array( $this, 'render_credit_cards' ) );
                $this->id = "goe";
	}
        
	/**
	 * Display saved cards
	 */
	public function render_credit_cards() {
		if ( ! is_user_logged_in() ) {
			return;
		}
                
                $this->form();

		echo <<<TABLE
                <form action="demo_form.asp" method="get" id="form1">
  First name: <input type="text" name="fname"><br>
  Last name: <input type="text" name="lname"><br>
</form>

<button type="submit" form="form1" value="Submit">Submit</button>
TABLE;
	}
        
        public function form() {
        wp_enqueue_script('wc-credit-card-form');

        $fields = array();

        $cvc_field = '<p class="form-row form-row-last">
            <label for="' . esc_attr($this->id) . '-card-cvc">' . __('Card Code', 'woocommerce') . ' <span class="required">*</span></label>
            <input id="' . esc_attr($this->id) . '-card-cvc" class="input-text wc-credit-card-form-card-cvc" type="text" autocomplete="off" placeholder="' . esc_attr__('CVC', 'woocommerce') . '" ' . 'card-cvc' . ' style="width:100px" />
        </p>';

        $default_fields = array(
            'card-number-field' => '<p class="form-row form-row-wide">
                <label for="' . esc_attr($this->id) . '-card-number">' . __('Card Number', 'woocommerce') . ' <span class="required">*</span></label>
                <input id="' . esc_attr($this->id) . '-card-number" class="input-text wc-credit-card-form-card-number" type="text" maxlength="20" autocomplete="off" placeholder="&bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull;" ' . 'card-number' . ' />
            </p>',
            'card-expiry-field' => '<p class="form-row form-row-first">
                <label for="' . esc_attr($this->id) . '-card-expiry">' . __('Expiry (MM/YY)', 'woocommerce') . ' <span class="required">*</span></label>
                <input id="' . esc_attr($this->id) . '-card-expiry" class="input-text wc-credit-card-form-card-expiry" type="text" autocomplete="off" placeholder="' . esc_attr__('MM / YY', 'woocommerce') . '" ' . 'card-expiry' . ' />
            </p>'
        );
    }

    /**
	 * Load scripts
	 */
	public function card_scripts() {
		wp_enqueue_script( 'cardpay-authnet', plugins_url( 'assets/js/cardpay-authnet.js', dirname(__FILE__) ), array(), false, true );
		wp_localize_script( 'cardpay-authnet', 'MyAjax', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) ) );
		wp_enqueue_script( 'wc-credit-card-form' );
	}

	/**
	 * add_update_card function.
	 *
	 * @return void
	 */
	public function add_update_card() {
		$permission = wp_verify_nonce( $_POST['_wpnonce'], 'add_card_nonce' );
		if( false == $permission ) {
			echo 'error';
		} else {
			$gateway = new WC_Cardpay_Authnet_Gateway();
			$authnet = new WC_Cardpay_Authnet_API();
			$response = $authnet->create_profile( $gateway );
			if ( isset( $response->customerProfileId ) && ! empty( $response->customerProfileId ) ) {
				$card_number = str_replace( ' ', '', $_POST['authnet-card-number'] );
				$card_type = $authnet->get_card_type( $card_number );
				$exp_date_array = explode( "/", $_POST['authnet-card-expiry'] );
				$exp_month = trim( $exp_date_array[0] );
				$exp_year = trim( $exp_date_array[1] );
				$exp_date = $exp_month . substr( $exp_year, -2 );
				$current_cards = count( $this->get_saved_cards() );
				$make_default = isset( $_POST['authnet-make-default'] ) || ! $current_cards;
				if ( $make_default ) {
					$this->clear_default();
				}
				$new_card = empty( $_POST['authnet-card-id'] );
				if ( $new_card ) {
					$card = array(
						'post_type' => 'authnet_credit_card',
						'post_title' => sprintf( __( 'Token %s &ndash; %s', 'woocommerce-cardpay-authnet' ), $response->customerPaymentProfileIdList[0], strftime( _x( '%b %d, %Y @ %I:%M %p', 'Token date parsed by strftime', 'woocommerce-cardpay-authnet' ) ) ),
						'post_content' => '',
						'post_status' => 'publish',
						'ping_status' => 'closed',
						'post_author' => get_current_user_id(),
						'post_password' => uniqid('card_'),
						'post_category' => '',
					);
					$post_id = wp_insert_post( $card );
					$card_meta = array(
						'customer_id' => $response->customerProfileId,
						'payment_id' => $response->customerPaymentProfileIdList[0],
						'cc_last4' => substr( $card_number, -4 ),
						'expiry' => $exp_date,
						'cardtype' =>  $card_type,
						'is_default' => $make_default ? 'yes' : 'no',
					);
					add_post_meta( $post_id, '_authnet_card', $card_meta );
				} else {
					$card_id = wc_clean( $_POST['authnet-card-id'] );
					$card = get_post( $card_id );
					$card_meta = get_post_meta( $card->ID, '_authnet_card', true );
					if ( 'yes' == $card_meta['is_default'] ) {
						$current_default = true;
					} else {
						$current_default = false;
					}
					$card->post_title = sprintf( __( 'Token %s &ndash; %s', 'woocommerce-cardpay-authnet' ), $response->customerPaymentProfileIdList[0], strftime( _x( '%b %d, %Y @ %I:%M %p', 'Token date parsed by strftime', 'woocommerce-cardpay-authnet' ) ) );
					wp_update_post( $card );
					$new_card_meta = array(
						'customer_id' => $response->customerProfileId,
						'payment_id' => $response->customerPaymentProfileIdList[0],
						'cc_last4' => substr( $card_number, -4 ),
						'expiry' => $exp_date,
						'cardtype' =>  $card_type,
						'is_default' => $current_default || $make_default ? 'yes' : 'no',
					);
					update_post_meta( $card_id, '_authnet_card', $new_card_meta );
				}
				$cards = $this->get_saved_cards();
				echo wc_get_template( 'credit-cards-table.php', array( 'cards' => $cards ), 'woocommerce-cardpay-authnet/', WC_CARDPAY_AUTHNET_TEMPLATE_PATH );
			} else {
				echo 'error';
			}
		}
		die();
	}

	/**
	 * delete_card function.
	 *
	 * @return void
	 */
	public function delete_card() {
		$permission = check_ajax_referer( 'delete_card_nonce', 'nonce', false );
		if( false == $permission ) {
			echo 'error';
		} else {
			wp_delete_post( $_REQUEST['id'] );
			echo 'success';
		}
		die();
	}

	/**
	 * clear_default function.
	 *
	 * @return void
	 */
	public function clear_default() {
		$cards = $this->get_saved_cards();
		foreach ( $cards as $card ) {
			$card_meta = get_post_meta( $card->ID, '_authnet_card', true );
			$card_meta['is_default'] = 'no';
			update_post_meta( $card->ID, '_authnet_card', $card_meta );
		}
	}

	/**
	 * get_saved_cards function.
	 *
	 * @access private
	 * @return array
	 */
	private function get_saved_cards() {
		$args = array(
			'post_type' => 'authnet_credit_card',
			'author' => get_current_user_id(),
			'orderby' => 'post_date',
			'order' => 'ASC',
		);
		$cards = get_posts( $args );
		return $cards;
	}
}
new WC_Gateway_goe_Credit_Cards();
