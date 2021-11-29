<?php
/**
 * Plugin Name: WooCommerce Easypay Gateway
 * Plugin URI: https://www.site.com
 * Description: Easy pay Gateway
 * Author: Tornike Sarsevanidze
 * Author URI:
 * Version: 1.0.2
 * Text Domain: wc-gateway-easypay
 * Domain Path: /i18n/languages/
 *
 * Copyright: (c) 2021 Sarse, Inc. (t.sarsevanidze@gmail.com) and WooCommerce
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package   WC-Gateway-easypay
 * @author    Sarse
 * @category  Admin
 * @copyright Copyright (c) 2021, Sarse, Inc. and WooCommerce
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 *
 * This Easypay gateway forks the WooCommerce core "Cheque" payment gateway to create another Easypay payment method.
 */

defined( 'ABSPATH' ) or exit;


// Make sure WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	return;
}


/**
 * Add the gateway to WC Available Gateways
 *
 * @since 1.0.0
 * @param array $gateways all available WC gateways
 * @return array $gateways all WC gateways + Easypay gateway
 */
function wc_easypay_add_to_gateways( $gateways ) {
	$gateways[] = 'WC_Gateway_Easypay';
	return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'wc_easypay_add_to_gateways' );


/**
 * Adds plugin page links
 *
 * @since 1.0.0
 * @param array $links all plugin links
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 */
function wc_easypay_gateway_plugin_links( $links ) {

	$plugin_links = array(
		'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=easypay_gateway' ) . '">' . __( 'Configure', 'wc-gateway-easypay' ) . '</a>'
	);

	return array_merge( $plugin_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_easypay_gateway_plugin_links' );


/**
 * Easypay Payment Gateway
 *
 * Provides an Easypay Payment Gateway; mainly for testing purposes.
 * We load it later to ensure WC is loaded first since we're extending it.
 *
 * @class 		WC_Gateway_Easypay
 * @extends		WC_Payment_Gateway
 * @version		1.0.0
 * @package		WooCommerce/Classes/Payment
 * @author 		Sarse
 */
add_action( 'plugins_loaded', 'wc_easypay_gateway_init', 11 );

function wc_easypay_gateway_init() {

	class WC_Gateway_Easypay extends WC_Payment_Gateway {

		/**
		 * Constructor for the gateway.
		 */
		public function __construct() {

			$this->id                 = 'easypay_gateway';
			$this->icon               = apply_filters('woocommerce_easypay_icon', '');
			$this->has_fields         = false;
			$this->method_title       = __( 'Easypay', 'wc-gateway-easypay' );
			$this->method_description = __( 'Allows payments. Very handy if you use your cheque gateway for another payment method, and can help with testing. Orders are marked as "on-hold" when received.', 'wc-gateway-easypay' );

			// Load the settings.
			$this->init_form_fields();
			$this->init_settings();

			// Define user set variables
			$this->title        = $this->get_option( 'title' );
			$this->description  = $this->get_option( 'description' );
			$this->instructions = $this->get_option( 'instructions', $this->description );

			// Actions
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );

			// Customer Emails
			add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
		}


		/**
		 * Initialize Gateway Settings Form Fields
		 */
		public function init_form_fields() {

			$this->form_fields = apply_filters( 'wc_easypay_form_fields', array(

				'enabled' => array(
					'title'   => __( 'Enable/Disable', 'wc-gateway-easypay' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable Easypay Payment', 'wc-gateway-easypay' ),
					'default' => 'yes'
				),

				'title' => array(
					'title'       => __( 'Title', 'wc-gateway-easypay' ),
					'type'        => 'text',
					'description' => __( 'This controls the title for the payment method the customer sees during checkout.', 'wc-gateway-easypay' ),
					'default'     => __( 'Easypay Payment', 'wc-gateway-easypay' ),
					'desc_tip'    => true,
				),

				'description' => array(
					'title'       => __( 'Description', 'wc-gateway-easypay' ),
					'type'        => 'textarea',
					'description' => __( 'Payment method description that the customer will see on your checkout.', 'wc-gateway-easypay' ),
					'default'     => __( 'Please remit payment to Store Name upon pickup or delivery.', 'wc-gateway-easypay' ),
					'desc_tip'    => true,
				),

				'instructions' => array(
					'title'       => __( 'Instructions', 'wc-gateway-easypay' ),
					'type'        => 'textarea',
					'description' => __( 'Instructions that will be added to the thank you page and emails.', 'wc-gateway-easypay' ),
					'default'     => '',
					'desc_tip'    => true,
				),
			) );
		}


		/**
		 * Output for the order received page.
		 */
		public function thankyou_page() {
			if ( $this->instructions ) {
				echo wpautop( wptexturize( $this->instructions ) );
			}
		}


		/**
		 * Add content to the WC emails.
		 *
		 * @access public
		 * @param WC_Order $order
		 * @param bool $sent_to_admin
		 * @param bool $plain_text
		 */
		public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {

			if ( $this->instructions && ! $sent_to_admin && $this->id === $order->payment_method && $order->has_status( 'on-hold' ) ) {
				echo wpautop( wptexturize( $this->instructions ) ) . PHP_EOL;
			}
		}


		/**
		 * Process the payment and return the result
		 *
		 * @param int $order_id
		 * @return array
		 */
		public function process_payment( $order_id ) {

			$order = wc_get_order( $order_id );

			// Mark as on-hold (we're awaiting the payment)
			$order->update_status( 'on-hold', __( 'Awaiting payment', 'wc-gateway-easypay' ) );


			$url = 'https://tuenti:A8969CF3@easypay.ge/api/create-payment-url';


			// paymentId=15&amount=52.75

			$dataArray = [
				'paymentId' => $order_id,
				'amount' => $order->get_total(),
			];
			$data = http_build_query($dataArray);
			$getUrl = $url."?".$data;
			$ch = curl_init();

			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
			curl_setopt($ch, CURLOPT_URL, $getUrl);
			curl_setopt($ch, CURLOPT_TIMEOUT, 80);

			$response = curl_exec($ch);
			curl_close($ch);

			if(curl_error($ch)){

			}
			else
			{
				$res = json_decode($response);
				$order->reduce_order_stock();
				WC()->cart->empty_cart();
				$order->reduce_order_stock();
				return  array(
					'result' 	=> 'success',
					'redirect'	=> $res->url
				);
			}




			// Reduce stock levels
			//

			// Remove cart
			//

			// Return thankyou redirect

		}

  } // end \WC_Gateway_Easypay class
}
