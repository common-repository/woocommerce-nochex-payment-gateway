<?php
/*
Plugin Name: Nochex Payment Gateway for Woocommerce
Description: Accept Nochex Payments, orders are updated using APC.
Version: 0.1
Author: Nochex Ltd - Matthew Iveson
License: GPL2
*/
add_action('plugins_loaded', 'woocommerce_nochex_init', 0);

function woocommerce_nochex_init() {

class wc_nochex extends WC_Payment_Gateway {

    public function __construct() { 
	global $woocommerce;
	
		$this->id				= 'nochex';
		$this->icon 			=  WP_PLUGIN_URL . "/" . plugin_basename( dirname(__FILE__)) . '/images/nochex-logo.png';
		$this->has_fields 		= false;
		$this->method_title     = __( 'Nochex', 'woocommerce' );
		// Load the form fields.
		$this->init_form_fields();
		
		// Load the settings.
		$this->init_settings();
		
		// Define user set variables
		$this->title 			         = $this->settings['title'];
		$this->description               = $this->settings['description'];
		$this->merchant_id               = $this->settings['merchant_id'];
		$this->hide_billing_details      = $this->settings['hide_billing_details'];
		$this->test_mode                 = $this->settings['test_mode'];
		$this->debug			         = $this->settings['debug'];
		
		// Logging
		if ( 'yes' == $this->debug )
			$this->log = $woocommerce->logger();
		
		$this->callback_url   = str_replace( 'https:', 'http:', add_query_arg( 'wc-api', 'wc_nochex', home_url( '/' ) ) );
		
		// Actions
		// Update admin options
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
    	
		// APC Handler
		add_action( 'woocommerce_api_wc_nochex', array( $this, 'apc' ) );

		// Success Page
		add_action('woocommerce_receipt_nochex', array( $this, 'receipt_page'));

    } 

	/**
     * Initialise Gateway Settings Form Fields
     */
    function init_form_fields() {
    
	$this->form_fields = array(
			'enabled' => array(
							'title' => __( 'Enable/Disable', 'woocommerce' ), 
							'type' => 'checkbox', 
							'label' => __( 'Enable Nochex', 'woocommerce' ), 
							'default' => 'yes'
						), 
			'title' => array(
							'title' => __( 'Title', 'woocommerce' ), 
							'type' => 'text', 
							'description' => __( 'Title of the Nochex payment option, visible by customers at the checkout.', 'woocommerce' ), 
							'default' => __( 'Nochex', 'woocommerce' )
						),
			'description' => array(
							'title' => __( 'Checkout Message', 'woocommerce' ), 
							'type' => 'textarea', 
							'description' => __( 'Message the customer will see after selecting Nochex as their payment option.', 'woocommerce' ), 
							'default' => __('Pay securely using Nochex. You can pay using your credit or debit card if you do not have a Nochex account.', 'woocommerce')
						),
			'merchant_id' => array(
							'title' => __( 'Nochex Merchant ID/Email', 'woocommerce' ), 
							'type' => 'text', 
							'description' => '', 
							'default' => ''
						),
			'hide_billing_details' => array(
							'title' => __( 'Hide Billing Details', 'woocommerce' ), 
							'type' => 'checkbox', 
							'label' => __( 'Hide Customer Billing Details', 'woocommerce' ), 
							'description' => __( 'Hide the customer\'s billing details so they cannot be changed when the customer is sent to Nochex.', 'woocommerce' ),
							'default' => 'no'
						), 
			'test_mode' => array(
							'title' => __( 'Nochex Test Mode', 'woocommerce' ), 
							'type' => 'checkbox', 
							'label' => __( 'Enable Nochex Test Mode', 'woocommerce' ), 
							'description' => __( 'If test mode is selected test transaction can be made.', 'woocommerce' ),
							'default' => 'no'
						), 
			'debug' => array(
							'title' => __( 'Debug Log', 'woocommerce' ),
							'type' => 'checkbox',
							'label' => __( 'Enable logging', 'woocommerce' ),
							'default' => 'no',
							'description' => sprintf( __( 'Log Nochex actions, such as APC requests, inside <code>woocommerce/logs/nochex-%s.txt</code>', 'woocommerce' ), sanitize_file_name( wp_hash( 'nochex' ) ) ),
						)

			);
    
    } // End init_form_fields()
    
	/**
	 * Admin Panel Options 
	 * - Options for bits like 'title' and availability on a country-by-country basis
	 *
	 * @since 1.0.0
	 */
	public function admin_options() {
    	?>
    	<h3><?php _e('Nochex', 'woocommerce'); ?></h3>
    	<p><?php _e('After selecting Nochex customers will be sent to Nochex to enter their payment information.', 'woocommerce'); ?></p>
    	<table class="form-table">
    	<?php
    		// Generate the HTML For the settings form.
    		$this->generate_settings_html();
    	?>
		</table><!--/.form-table-->
    	<?php
    } // End admin_options()


	/**
	 * Get Nochex Args for passing to Nochex
	 *
	 * @access public
	 * @param mixed $order
	 * @return array
	 */
	function get_nochex_args( $order ) {
		global $woocommerce;

		$order_id = $order->id;

		if ( 'yes' == $this->debug )
			$this->log->add( 'nochex', 'Generating payment form for order ' . $order->get_order_number() . '. APC URL: ' . $this->callback_url );

			
		// Nochex Args
		$nochex_args = array(
				'merchant_id' 			=> $this->merchant_id,
				'cancel_url'			=> $order->get_cancel_order_url(),

				// Order key + ID
				'order_id'				=> $this->invoice_prefix . ltrim( $order->get_order_number(), '#' ),
				'custom' 				=> serialize( array( $order_id, $order->order_key ) ),

				// APC
				'callback_url'			=> $this->callback_url,
				
				// Total
				'amount'			    => $order->order_total,

				// Billing Address info
				'billing_fullname'		=> $order->billing_first_name . " " . $order->billing_last_name,
				'billing_address'		=> str_replace(", , ", ", ", $order->billing_address_1 . ", " . $order->billing_address_2 . ", " . $order->billing_city . ", " . $order->billing_state . ", " . $order->billing_country),
				'billing_postcode'      => $order->billing_postcode,
				'email_address'			=> $order->billing_email,
			
				'customer_phone_number' => $order->billing_phone
		);
		
		if ($this->hide_billing_details == 'yes'){
			$nochex_args['hide_billing_details'] = 'true';
		}
		
		if ($this->test_mode == 'yes'){
			$nochex_args['test_transaction'] = '100';
			$nochex_args['test_success_url'] = $this->get_return_url( $order );
		}else{
			$nochex_args['success_url'] = $this->get_return_url( $order );
		}

		// Shipping
		$nochex_args['delivery_fullname'] = $order->shipping_first_name . " " . $order->shipping_last_name;
		$nochex_args['delivery_address']  = str_replace(", , ", ", ", $order->shipping_address_1 . ", " . $order->shipping_address_2 . ", " . $order->shipping_city . ", " . $order->shipping_state . ", " . $order->shipping_country);
		$nochex_args['delivery_postcode']  = $order->shipping_postcode;

		// Cart Contents
		$item_loop = 0;
		$description = '';
		if ( sizeof( $order->get_items() ) > 0 ) {
			foreach ( $order->get_items() as $item ) {
				if ( $item['qty'] ) {
						$item_loop++;
						$product = $order->get_product_from_item( $item );
						$item_name 	= $item['name'];
						$item_meta = new WC_Order_Item_Meta( $item['item_meta'] );
						$description .= $item_name . " qty " . $item['qty'] . " x " . $order->get_item_subtotal( $item, false ) . ", ";
				}
			}
		}
		
		if (strlen($description) > 1){
			$description = rtrim($description, ", ");
		}
		
		$nochex_args['description'] = $description;

		$nochex_args = apply_filters( 'woocommerce_nochex_args', $nochex_args );

		if ( 'yes' == $this->debug )
			$this->log->add( 'nochex', 'Form values: ' . print_r( $nochex_args, true ));


		return $nochex_args;
	}



	/**
	 * receipt_page
	**/
	function receipt_page( $order ) {
		
		echo '<p>'.__('Thank you for your order.', 'woocommerce').'</p>';
		
	}


    /**
    * Process the payment and return the result
    **/
    function process_payment( $order_id ) {
    	global $woocommerce;
    	
		$order = new WC_Order( $order_id );
		
		$nochex_args = $this->get_nochex_args( $order );

		$nochex_args = http_build_query( $nochex_args, '', '&' );

		$nochex_adr = 'https://secure.nochex.com?';

		return array(
			'result' 	=> 'success',
			'redirect'	=> $nochex_adr . $nochex_args
		);
    }
	
	/**
	 * Perform Automatic Payment Confirmation (APC)
	 *
	 * @access public
	 * @return void
	 */
	function apc() {
		global $woocommerce;

		if(isset($_POST['order_id'])){
			$order = new WC_Order ( $_POST['order_id'] );
			
			if ( $order->get_total() != $_POST['amount'] ) {

				if ('yes' == $this->debug )
				$this->log->add( 'nochex', 'Payment error: Amounts do not match (total ' . $posted['amount'] . ')' );

				// Put this order on-hold for manual checking
				$order->update_status( 'on-hold', sprintf( __( 'Validation error: Nochex amounts do not match (total %s).', 'woocommerce' ), $_POST['amount'] ) );

				return;
			}
			
			$postvars = http_build_query($_POST);

			$nochex_apc_url = "http://www.nochex.com/nochex.dll/apc/apc";
			
			$params = array(
        	'body' 			=> $postvars,
        	'sslverify' 	=> true,
        	'timeout' 		=> 60,
			'Content-Type'	=> 'application/x-www-form-urlencoded',
			'Content-Length'	=> strlen($postvars),
        	'user-agent'	=> 'WooCommerce/' . $woocommerce->version
			);

			if ( 'yes' == $this->debug )
				$this->log->add( 'nochex', 'APC Request: ' . print_r( $params, true ) );


			// Post back to get a response
			$output = wp_remote_retrieve_body(wp_remote_post($nochex_apc_url, $params));
			
			if( $output == 'AUTHORISED' ) {
				if ( 'yes' == $this->debug )
					$this->log->add( 'nochex', 'APC Passed, Response: ' . $output );
				
				$order->add_order_note( sprintf( __('Nochex APC Passed, Response: %s', 'wc_nochex' ), $output ) );
				$order->add_order_note( sprintf( __('Nochex Payment Status: %s', 'wc_nochex' ), $_POST['status'] ) );
				$order->payment_complete();
				$woocommerce->cart->empty_cart();
			} else {
				if ('yes' == $this->debug )
					$this->log->add( 'nochex', 'APC Failed, Response: ' . $output );
				
				$order->add_order_note( sprintf( __('Nochex APC Failed, Response: %s', 'wc_nochex' ), $output ) );
				$order->add_order_note( sprintf( __('Nochex Payment Status: %s', 'wc_nochex' ), $_POST['status'] ) );
			}
		
		}else wp_die( "Nochex APC Request Failure" );

	}

}

	/**
 	* Add the Gateway to WooCommerce
 	**/
	function woocommerce_add_nochex_gateway($methods) {
		$methods[] = 'wc_nochex';
		return $methods;
	}

	add_filter('woocommerce_payment_gateways', 'woocommerce_add_nochex_gateway' );
}
