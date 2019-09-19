<?php
/**
 * Plugin Name: Bambora PayForm Payment Gateway
 * Plugin URI: https://payform.bambora.com/docs
 * Description: Bambora PayForm Payment Gateway Integration for Woocommerce
 * Version: 2.1.6
 * Author: Bambora
 * Author URI: https://www.bambora.com/fi/fi/Verkkokauppa/Payform/
 * Text Domain: bambora-payform-payment-gateway
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action('plugins_loaded', 'init_bambora_payform_gateway', 0);

function woocommerce_add_WC_Gateway_Bambora_PayForm($methods)
{
	$methods[] = 'WC_Gateway_Bambora_PayForm';
	return $methods;
}
add_filter('woocommerce_payment_gateways', 'woocommerce_add_WC_Gateway_Bambora_PayForm');

function init_bambora_payform_gateway()
{
	load_plugin_textdomain('bambora-payform-payment-gateway', false, dirname(plugin_basename(__FILE__)) . '/languages/' );

	if(!class_exists('WC_Payment_Gateway'))
		return;

	class WC_Gateway_Bambora_PayForm extends WC_Payment_Gateway
	{
		function __construct()
		{
			$this->id = 'bambora_payform';
			$this->has_fields = true;
			$this->method_title = __( 'Bambora PayForm', 'bambora-payform-payment-gateway' );
			$this->method_description = __( 'Bambora PayForm w3-API Payment Gateway integration for Woocommerce', 'bambora-payform-payment-gateway' );

			$this->init_form_fields();
			$this->init_settings();

			$this->enabled = $this->settings['enabled'];
			$this->title = $this->get_option('title');

			$this->api_key = $this->get_option('api_key');
			$this->private_key = $this->get_option('private_key');

			$this->ordernumber_prefix = $this->get_option('ordernumber_prefix');
			$this->description = $this->get_option('bambora_payform_description');
			$this->payment_description = $this->get_option('bambora_payform_payment_description');

			$this->banks = $this->get_option('banks');
			$this->wallets = $this->get_option('wallets');
			$this->ccards = $this->get_option('ccards');
			$this->cinvoices = $this->get_option('cinvoices');
			$this->arvato = $this->get_option('arvato');
			$this->laskuyritykselle = $this->get_option('laskuyritykselle');

			$this->send_items = $this->get_option('send_items');
			$this->send_receipt = $this->get_option('send_receipt');
			$this->embed = $this->get_option('embed');

			$this->cancel_url = $this->get_option('cancel_url');
			$this->limit_currencies = $this->get_option('limit_currencies');

			add_action('wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
			add_action('admin_notices', array($this, 'payform_admin_notices'));
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options' ) );
			add_action('woocommerce_api_wc_gateway_bambora_payform', array($this, 'check_bambora_payform_response' ) );
			add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'bambora_payform_settle_payment'), 1, 1);

			if(!$this->is_valid_currency() && $this->limit_currencies == 'yes')
				$this->enabled = false;

			if (!function_exists('curl_init'))
				$this->enabled = false;
		}

		public function payform_admin_notices() 
		{
			if($this->settings['enabled'] == 'no')
				return;
			// Show message if curl is not installed
			if (!function_exists('curl_init'))
				echo '<div class="error"><p>' . sprintf( __( 'PHP cURL is not installed, install cURL to use Bambora PayForm payment gateway.', 'bambora-payform-payment-gateway' ), admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ) . '</p></div>';
		}

		function is_valid_currency()
		{	
			return in_array(get_woocommerce_currency(), array('EUR'));
		}

		function payment_scripts() {
			if (!is_checkout())
				return;

			// CSS Styles
			wp_enqueue_style( 'woocommerce_bambora_payform', untrailingslashit( plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) ) . '/assets/css/payform.css', '', '', 'all');
			// JS SCRIPTS
			wp_enqueue_script( 'woocommerce_bambora_payform', untrailingslashit( plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) ) . '/assets/js/payform.js', array( 'jquery' ), '', true );
		}

		function init_form_fields()
		{
			$this->form_fields = array(
				'general' => array(
					'title' => __( 'General options', 'bambora-payform-payment-gateway' ),
					'type' => 'title',
					'description' => '',
				),
				'enabled' => array(
					'title' => __( 'Enable/Disable', 'bambora-payform-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( 'Enable Bambora PayForm', 'bambora-payform-payment-gateway' ),					
					'default' => 'yes'
				),
				'title' => array(
					'title' => __( 'Title', 'bambora-payform-payment-gateway' ),
					'type' => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'bambora-payform-payment-gateway' ),
					'default' => __( 'Bambora PayForm', 'bambora-payform-payment-gateway' )
				),
				'bambora_payform_description' => array(
					'title' => __( 'Description', 'bambora-payform-payment-gateway' ),
					'description' => __( 'This controls the first part of the description which the user sees during checkout.', 'bambora-payform-payment-gateway' ),
					'type' => 'textarea',
					'default' => __( 'Pay safely with Finnish internet banking, payment cards, wallet services or credit invoices.', 'bambora-payform-payment-gateway')
				),
				'bambora_payform_payment_description' => array(
					'title' => __( 'Second part of description', 'bambora-payform-payment-gateway' ),
					'description' => __( 'This controls the second part of the description which the user sees during checkout.', 'bambora-payform-payment-gateway' ),
					'type' => 'textarea',
					'default' => __( 'Choose your payment method and click Pay for Order', 'bambora-payform-payment-gateway' )
				),				
				'private_key' => array(
					'title' => __( 'Private key', 'bambora-payform-payment-gateway' ),
					'type' => 'text',
					'description' => __( 'Private key of the sub-merchant', 'bambora-payform-payment-gateway' ),
					'default' => ''
				),
				'api_key' => array(
					'title' => __( 'API key', 'bambora-payform-payment-gateway' ),
					'type' => 'text',
					'description' => __( 'API key of the sub-merchant', 'bambora-payform-payment-gateway' ),
					'default' => ''
				),
				'ordernumber_prefix' => array(
					'title' => __( 'Order number prefix', 'bambora-payform-payment-gateway' ),
					'type' => 'text',
					'description' => __( 'Prefix to avoid order number duplication', 'bambora-payform-payment-gateway' ),
					'default' => ''
				),
				'send_items' => array(
					'title' => __( 'Send products', 'bambora-payform-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( "Send product breakdown to Bambora PayForm.", 'bambora-payform-payment-gateway' ),
					'default' => 'yes'
				),
				'send_receipt' => array(
					'title' => __( 'Send payment confirmation', 'bambora-payform-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( "Send Bambora PayForm's payment confirmation email to the customer's billing e-mail.", 'bambora-payform-payment-gateway' ),
					'default' => 'yes',
				)
			);

			if (version_compare(WOOCOMMERCE_VERSION, '2.1.0', '>='))
			{
				$this->form_fields['cancel_url'] = array(
					'title' => __( 'Cancel Page', 'bambora-payform-payment-gateway' ),
					'type' => 'select',
					'description' => 
						__( 'Choose the page where the customer is redirected after a canceled/failed payment.', 'bambora-payform-payment-gateway' ) . '<br>'.
						' - ' . __( 'Order Received: Shows the customer information about their order and a notice that the payment failed. Customer has an opportunity to try payment again.', 'bambora-payform-payment-gateway' ) . '<br>'.
						' - ' .__( 'Pay for Order: Returns user to a page where they can try to pay their unpaid order again. ', 'bambora-payform-payment-gateway' ) . '<br>'.
						' - ' .__( 'Cart: Customer is redirected back to the shopping cart.' , 'bambora-payform-payment-gateway' ) . '<br>'.
						' - ' .__( 'Checkout: Customer is redirected back to the checkout.', 'bambora-payform-payment-gateway' ) . '<br>'.
						'<br>' .__( '(When using Cart or Checkout as the return page for failed orders, the customer\'s cart will not be emptied during checkout.)', 'bambora-payform-payment-gateway' ),
					'default' => 'order_received',
					'options' => array(
						'order_received' => __('Order Received', 'bambora-payform-payment-gateway'),
						'order_pay' => __('Pay for Order', 'bambora-payform-payment-gateway'),
						'order_new_cart' => __('Cart', 'bambora-payform-payment-gateway'),
						'order_new_checkout' => __('Checkout', 'bambora-payform-payment-gateway')
					)
				);
			}

			$this->form_fields = array_merge($this->form_fields, array(
				'limit_currencies' => array(
					'title' => __( 'Only allow payments in EUR', 'bambora-payform-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( "Enable this option if you want to allow payments only in EUR.", 'bambora-payform-payment-gateway' ),
					'default' => 'yes',
				),
				'embed' => array(
					'title' => __( 'Enable payment method embedding', 'bambora-payform-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( "Enable this if you want to embed the payment methods to the checkout-page.", 'bambora-payform-payment-gateway' ),
					'default' => 'yes',
					'checkboxgroup'	=> 'start',
					'show_if_checked' => 'option'
				),
				'limit_options' => array(
					'title' => __( 'Manage payment methods', 'bambora-payform-payment-gateway' ),
					'type' => 'title',
					'description' => '',
				),
				'banks' => array(
					'title' => __( 'Banks', 'bambora-payform-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( 'Enable bank payments in the Bambora PayForm payment page.', 'bambora-payform-payment-gateway' ),
					'default' => 'yes'
				),
				'wallets' => array(
					'title' => __( 'Wallets', 'bambora-payform-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( 'Enable wallet services in the Bambora PayForm payment page.', 'bambora-payform-payment-gateway' ),
					'default' => 'yes'
				),
				'ccards' => array(
					'title' => __( 'Card payments', 'bambora-payform-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( 'Enable credit cards in the Bambora PayForm payment page.', 'bambora-payform-payment-gateway' ),
					'default' => 'yes'
				),
				'cinvoices' => array(
					'title' => __( 'Credit invoices', 'bambora-payform-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( 'Enable credit invoices in the Bambora PayForm payment page.', 'bambora-payform-payment-gateway' ),
					'default' => 'yes'
				),
				'arvato' => array(
					'title' => __( 'Maksukaista Lasku', 'bambora-payform-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( 'Enable Maksukaista Lasku in the Bambora PayForm payment page.', 'bambora-payform-payment-gateway' ),
					'default' => 'no'
				),
				'laskuyritykselle' => array(
					'title' => __( 'Lasku Yritykselle', 'bambora-payform-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( 'Enable Lasku Yritykselle in the Bambora PayForm payment page.', 'bambora-payform-payment-gateway' ),
					'default' => 'no'
				)
			));

		}

		function payment_fields()
		{
			$old_wc = version_compare(WC_VERSION, '3.0.0', '<');

			$total = 0;
			
			$wc_cart_total = WC()->cart->total;
			$cart_total = (int)(round($wc_cart_total*100, 0));

			if(get_query_var('order-pay') != '')
			{
				$order = new WC_Order(get_query_var('order-pay'));
				$wc_order_total = $old_wc ? $order->order_total : $order->get_total();
				$total = (int)(round($wc_order_total*100, 0));
			}

			$plugin_url = untrailingslashit(plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__))) . '/';

			if($this->embed == 'yes')
			{
				$creditcards = $banks = $creditinvoices = $wallets = '';
				include(plugin_dir_path( __FILE__ ).'includes/lib/bambora_payform_loader.php');
				$payment_methods = new Bambora\PayForm($this->api_key, $this->private_key);
				try
				{
					$response = $payment_methods->getMerchantPaymentMethods(get_woocommerce_currency());

					if($response->result == 0)
					{
						if(count($response->payment_methods) > 0)
						{
							foreach ($response->payment_methods as $method)
							{
								$key = $method->selected_value;
								if($method->group == 'creditcards')
									$key = strtolower($method->name);

								$img = $this->bambora_payform_save_img($key, $method->img, $method->img_timestamp);

								if($method->group == 'creditcards'  && $this->ccards == 'yes')
								{
									$creditcards .= '<div id="bambora-payform-button-creditcards" class="bank-button"><img alt="' . $method->name . '" src="' . $plugin_url.$img . '"/></div>';
								}
								else if($method->group == 'wallets' && $this->wallets == 'yes')
								{
									$wallets .= '<div id="bambora-payform-button-' . $method->selected_value . '" class="bank-button"><img alt="' . $method->name . '" src="' . $plugin_url.$img . '"/></div>';
								}
								else if($method->group == 'banks' && $this->banks == 'yes')
								{
									$banks .= '<div id="bambora-payform-button-' . $method->selected_value . '" class="bank-button"><img alt="' . $method->name . '" src="' . $plugin_url.$img . '"/></div>';
								}
								else if($method->group == 'creditinvoices')
								{
									if($method->selected_value == 'arvato' || $method->selected_value == 'laskuyritykselle')
									{
										if(($method->selected_value == 'arvato' && $this->arvato == 'yes') || ($method->selected_value == 'laskuyritykselle' && $this->laskuyritykselle == 'yes'))
											$creditinvoices .= '<div id="bambora-payform-button-' . $method->selected_value . '" class="bank-button"><img alt="' . $method->name . '" src="' . $plugin_url.$img . '"/></div>';
									}
									else if($this->cinvoices == 'yes' && ((!isset($order) && $cart_total >= $method->min_amount && $cart_total <= $method->max_amount) || ($total >= $method->min_amount && $total <= $method->max_amount)))
										$creditinvoices .= '<div id="bambora-payform-button-' . $method->selected_value . '" class="bank-button"><img alt="' . $method->name . '" src="' . $plugin_url.$img . '"/></div>';
								}
							}

						}

						if(empty($creditcards) && empty($banks) && empty($creditinvoices) && empty($wallets))
						{
							echo '<div class="woocommerce-error"><strong>' . __('No payment methods available for the currency: ', 'bambora-payform-payment-gateway') . get_woocommerce_currency() . '</strong></div>';
						}
						else
						{
							if (!empty($this->description))
								echo wpautop(wptexturize($this->description));
							if (!empty($this->payment_description))
								echo wpautop(wptexturize($this->payment_description));
						}
					}
				}
				catch (Bambora\PayFormException $e) 
				{
					$logger = new WC_Logger();
					$logger->add( 'bambora-payform-payment-gateway', 'Bambora PayForm REST::getMerchantPaymentMethods failed, exception: ' . $e->getCode().' '.$e->getMessage());
				}

				$clear_both = '<div style="display: block; clear: both;"></div>';
				
				

				echo '<br/><div id="bambora-payform-bank-payments">';
				if($creditcards != '')
					echo '<div>'.wpautop(wptexturize(__( 'Payment card', 'bambora-payform-payment-gateway' ))) . $creditcards . '</div>' . $clear_both;

				if($wallets != '')
					echo '<div>'.wpautop(wptexturize(__( 'Wallet services', 'bambora-payform-payment-gateway' ))) . $wallets . '</div>' . $clear_both;

				if($banks != '')
					echo '<div>'.wpautop(wptexturize(__( 'Internet banking', 'bambora-payform-payment-gateway' ))) . $banks . '</div>' . $clear_both;

				if($creditinvoices != '')
					echo '<div>'.wpautop(wptexturize(__( 'Invoice or part payment', 'bambora-payform-payment-gateway' ))) . $creditinvoices . '</div>' . $clear_both;

				echo '</div>';

				echo '<div id="bambora_payform_bank_checkout_fields" style="display: none;">';
				echo '<input id="bambora_payform_selected_bank" class="input-hidden" type="hidden" name="bambora_payform_selected_bank" />';
				echo '</div>';
			}
			else if(!empty($this->description)) //Non embed
				echo wpautop(wptexturize($this->description));
		}

		function process_payment($order_id)
		{
			if ($_POST['payment_method'] != 'bambora_payform')
				return false;

			$old_wc = version_compare(WC_VERSION, '3.0.0', '<');

			$order = new WC_Order($order_id);

			$wc_order_id = $old_wc ? $order->id : $order->get_id();
			$wc_order_total = $old_wc ? $order->order_total : $order->get_total();

			$wc_b_first_name = $old_wc ? $order->billing_first_name : $order->get_billing_first_name();
			$wc_b_last_name = $old_wc ? $order->billing_last_name : $order->get_billing_last_name();
			$wc_b_email = $old_wc ? $order->billing_email : $order->get_billing_email();
			$wc_b_address_1 = $old_wc ? $order->billing_address_1 : $order->get_billing_address_1();
			$wc_b_address_2 = $old_wc ? $order->billing_address_2 : $order->get_billing_address_2();
			$wc_b_city = $old_wc ? $order->billing_city : $order->get_billing_city();
			$wc_b_postcode = $old_wc ? $order->billing_postcode : $order->get_billing_postcode();
			$wc_b_country = $old_wc ? $order->billing_country : $order->get_billing_country();
			$wc_s_first_name = $old_wc ? $order->shipping_first_name : $order->get_shipping_first_name();
			$wc_s_last_name = $old_wc ? $order->shipping_last_name : $order->get_shipping_last_name();
			$wc_s_address_1 = $old_wc ? $order->shipping_address_1 : $order->get_shipping_address_1();
			$wc_s_address_2 = $old_wc ? $order->shipping_address_2 : $order->get_shipping_address_2();
			$wc_s_city = $old_wc ? $order->shipping_city : $order->get_shipping_city();
			$wc_s_postcode = $old_wc ? $order->shipping_postcode : $order->get_shipping_postcode();
			$wc_s_country = $old_wc ? $order->shipping_country : $order->get_shipping_country();

			$wc_order_shipping = $old_wc ? $order->order_shipping : $order->get_shipping_total();
			$wc_order_shipping_tax = $old_wc ? $order->order_shipping_tax : $order->get_shipping_tax();
			$bambora_payform_selected_bank = isset( $_POST['bambora_payform_selected_bank'] ) ? wc_clean( $_POST['bambora_payform_selected_bank'] ) : '';

			$order_number = (strlen($this->ordernumber_prefix)  > 0) ?  $this->ordernumber_prefix. '_'  .$order_id : $order_id;
			$order_number .=  '-' . str_pad(time().rand(0,9999), 5, "1", STR_PAD_RIGHT);

			include_once(plugin_dir_path( __FILE__ ).'includes/lib/bambora_payform_loader.php');

			if (version_compare(WOOCOMMERCE_VERSION, '2.1.0', '>='))
				$redirect_url = $this->get_return_url($order);
			else
				$redirect_url = (get_option('woocommerce_thanks_page_id') != '' ) ? get_permalink(get_option('woocommerce_thanks_page_id')): get_site_url().'/' ;

			$return_url = add_query_arg( array('wc-api' => get_class( $this ) ,'order_id' => $order_id), $redirect_url );

			$amount =  (int)(round($wc_order_total*100, 0));

			if($old_wc)
			{
				update_post_meta($order_id, '_bambora_payform_selected_bank_', $bambora_payform_selected_bank );
				update_post_meta($order_id, 'bambora_payform_is_settled', 1);
				update_post_meta($order_id, 'bambora_payform_return_code', 99);
			}
			else
			{
				$order->update_meta_data('_bambora_payform_selected_bank_', $bambora_payform_selected_bank);
				$order->update_meta_data('bambora_payform_is_settled', 1);
				$order->update_meta_data('bambora_payform_return_code', 99);
				$order->save();
			}

			$finn_langs = array('fi-FI', 'fi', 'fi_FI');
			$sv_langs = array('sv-SE', 'sv', 'sv_SE');
			$current_locale = get_locale();

			if(in_array($current_locale, $finn_langs))
				$lang = 'fi';
			else if (in_array($current_locale, $sv_langs))
				$lang = 'sv';
			else
				$lang = 'en';

			$payment = new Bambora\PayForm($this->api_key, $this->private_key);

			if($this->send_receipt == 'yes')
				$receipt_mail = $wc_b_email;
			else
				$receipt_mail = '';

			$payment->addCharge(
				array(
					'order_number' => $order_number,
					'amount' => $amount,
					'currency' => get_woocommerce_currency(),
					'email' =>  $receipt_mail
				)
			);

			$payment->addCustomer(
				array(
					'firstname' => htmlspecialchars($wc_b_first_name),
					'lastname' => htmlspecialchars($wc_b_last_name),
					'email' => htmlspecialchars($wc_b_email),
					'address_street' => trim(htmlspecialchars($wc_b_address_1.' '.$wc_b_address_2)),
					'address_city' => htmlspecialchars($wc_b_city),
					'address_zip' => htmlspecialchars($wc_b_postcode),
					'address_country' => htmlspecialchars($wc_b_country),
					'shipping_firstname' => htmlspecialchars($wc_s_first_name),
					'shipping_lastname' => htmlspecialchars($wc_s_last_name),
					'shipping_address_street' => trim(htmlspecialchars($wc_s_address_1.' '.$wc_s_address_2)),
					'shipping_address_city' => htmlspecialchars($wc_s_city),
					'shipping_address_zip' => htmlspecialchars($wc_s_postcode),
					'shipping_address_country' => htmlspecialchars($wc_s_country)
				)
			);

			$products = array();
			$total_amount = 0;
			$order_items = $order->get_items();
			foreach($order_items as $item) {
				$line_tax = ($item['line_total'] > 0) ? round($item['line_tax']/$item['line_total']*100,0) : 0;
				$product = array(
					'title' => $item['name'],
					'id' => $item['product_id'],
					'count' => $item['qty'],
					'pretax_price' => (int)(round(($item['line_total']/$item['qty'])*100, 0)),
					'price' => (int)(round((($item['line_total'] + $item['line_tax'] ) / $item['qty'])*100, 0)),
					'tax' => $line_tax,
					'type' => 1
				);
				$total_amount += $product['price'] * $product['count'];
				array_push($products, $product);
		 	}

		 	$shipping_items = $order->get_items( 'shipping' );
		 	foreach($shipping_items as $s_method){
				$shipping_method_id = $s_method['method_id'] ;
			}
		 	if($wc_order_shipping > 0){
			 	$product = array(
					'title' => $order->get_shipping_method(),
					'id' => $shipping_method_id,
					'count' => 1,
					'pretax_price' => (int)(round($wc_order_shipping*100, 0)),
					'price' => (int)(round(($wc_order_shipping_tax+$wc_order_shipping)*100, 0)),
					'tax' => round(($wc_order_shipping_tax/$wc_order_shipping)*100,0),
					'type' => 2
				);
				$total_amount += $product['price'] * $product['count'];
				array_push($products, $product);				
			}

			if($this->send_items == 'yes' && $total_amount == $amount)
			{
				foreach($products as $product)
				{
					$payment->addProduct(
						array(
							'id' => htmlspecialchars($product['id']),
							'title' => htmlspecialchars($product['title']),
							'count' => $product['count'],
							'pretax_price' => $product['pretax_price'],
							'tax' => $product['tax'],
							'price' => $product['price'],
							'type' => $product['type']
						)
					);
				}
			}

			$mk_selected = '';

			if($this->embed == 'yes' && !empty($bambora_payform_selected_bank))
				$mk_selected = array($bambora_payform_selected_bank);
			else
			{
				$mk_selected = array();
				if($this->is_valid_currency())
				{
					if($this->banks == 'yes')
						$mk_selected[] = 'banks';
					if($this->wallets == 'yes')
						$mk_selected[] = 'wallets';
					if($this->ccards == 'yes')
						$mk_selected[] = 'creditcards';
					if($this->cinvoices == 'yes')
						$mk_selected[] = 'creditinvoices';
					if($this->arvato == 'yes')
						$mk_selected[] = 'arvato';
					if($this->laskuyritykselle == 'yes')
						$mk_selected[] = 'laskuyritykselle';
				}
				else if($this->limit_currencies == 'no')
				{
					include(plugin_dir_path( __FILE__ ).'includes/lib/bambora_payform_loader.php');
					$payment_methods = new Bambora\PayForm($this->api_key, $this->private_key);
					try
					{
						$response = $payment_methods->getMerchantPaymentMethods(get_woocommerce_currency());

						if($response->result == 0)
						{
							if(count($response->payment_methods) > 0)
							{
								foreach ($response->payment_methods as $method)
								{
									$key = $method->selected_value;
									if($method->group == 'creditcards')
										$key = strtolower($method->name);

									if($method->group == 'creditcards'  && $this->ccards == 'yes')
									{
										$mk_selected[] = $method->group; //creditcards
									}
									else if($method->group == 'wallets' && $this->wallets == 'yes')
									{
										$mk_selected[] = $method->selected_value;
									}
									else if($method->group == 'banks' && $this->banks == 'yes')
									{
										$mk_selected[] = $method->selected_value;
									}
									else if($method->group == 'creditinvoices')
									{
										if($method->selected_value == 'arvato' || $method->selected_value == 'laskuyritykselle')
										{
											if(($method->selected_value == 'arvato' && $this->arvato == 'yes') || ($method->selected_value == 'laskuyritykselle' && $this->laskuyritykselle == 'yes'))
												$mk_selected[] = $method->selected_value;
										}
										else if($this->cinvoices == 'yes' && ((!isset($order) && $cart_total >= $method->min_amount && $cart_total <= $method->max_amount) || ($total >= $method->min_amount && $total <= $method->max_amount)))
											$mk_selected[] = $method->selected_value;
									}
								}
							}

							if(empty($mk_selected))
							{
								$logger = new WC_Logger();
								$logger->add( 'bambora-payform-payment-gateway', 'Bambora PayForm no payment methods available for order: ' . $order_number . ', currency: ' . get_woocommerce_currency());
								wc_add_notice(__('Bambora PayForm: No payment methods available for the currency: ', 'bambora-payform-payment-gateway') . get_woocommerce_currency(), 'error');
								$order_number_text = __('Bambora PayForm: No payment methods available for the currency: ', 'bambora-payform-payment-gateway') .  get_woocommerce_currency();
								$order->add_order_note($order_number_text);
								return;
							}
						}
					}
					catch (Bambora\PayFormException $e) 
					{
						$logger = new WC_Logger();
						$logger->add( 'bambora-payform-payment-gateway', 'Bambora PayForm getMerchantPaymentMethods failed for order: ' . $order_number . ', exception: ' . $e->getCode().' '.$e->getMessage());
					}
				}
				else
				{
					$error_text = __('Bambora PayForm: "Only allow payments in EUR" is enabled and currency was not EUR for order: ', 'bambora-payform-payment-gateway');
					$logger = new WC_Logger();
					$logger->add( $error_text . $order_number);
					wc_add_notice(__('Bambora PayForm: No payment methods available for the currency: ', 'bambora-payform-payment-gateway') . get_woocommerce_currency(), 'notice');
					$order->add_order_note($error_text . $order_number);
					return;
				}
			}

			$payment->addPaymentMethod(
				array(
					'type' => 'e-payment', 
					'return_url' => $return_url,
					'notify_url' => $return_url,
					'lang' => $lang,
					'selected' => $mk_selected,
					'token_valid_until' => strtotime('+1 hour')
				)
			);

			try
			{
				$response = $payment->createCharge();

				if($response->result == 0)
				{
					$order_number_text = __('Bambora PayForm order', 'bambora-payform-payment-gateway') . ": " . $order_number . "<br>-<br>" . __('Payment pending. Waiting for result.', 'bambora-payform-payment-gateway');
					$order->add_order_note($order_number_text);

					if($old_wc)
						update_post_meta($order_id, 'bambora_payform_order_number', $order_number);
					else
					{
						$order->update_meta_data('bambora_payform_order_number', $order_number);
						$order->save();
					}	

					$url = Bambora\PayForm::API_URL."/token/".$response->token;
					
					if(!in_array($this->cancel_url, array('order_new_cart', 'order_new_checkout')))
						WC()->cart->empty_cart();

					return array(
						'result'   => 'success',
						'redirect'  => $url
					);
				}
				else if($response->result == 10)
				{
					$errors = '';
					wc_add_notice(__('Bambora PayForm system is currently in maintenance. Please try again in a few minutes.', 'bambora-payform-payment-gateway'), 'notice');
					$logger = new WC_Logger();
					$logger->add( 'bambora-payform-payment-gateway', 'Bambora PayForm REST::CreateCharge. PayForm system maintenance in progress.');
					return;
				}
				else
				{
					$errors = '';
					wc_add_notice(__('Payment failed due to an error.', 'bambora-payform-payment-gateway'), 'error');
					$logger = new WC_Logger();
					if(isset($response->errors))
					{
						foreach ($response->errors as $error) 
						{
							$errors .= ' '.$error;
						}
					}
					$logger->add( 'bambora-payform-payment-gateway', 'Bambora PayForm REST::CreateCharge failed, response: ' . $response->result . ' - Errors:'.$errors);
					return;
				}
			}
			catch (Bambora\PayFormException $e) 
			{
				wc_add_notice(__('Payment failed due to an error.', 'bambora-payform-payment-gateway'), 'error');
				$logger = new WC_Logger();
				$logger->add( 'bambora-payform-payment-gateway', 'Bambora PayForm REST::CreateCharge failed, exception: ' . $e->getCode().' '.$e->getMessage());
				return;
			}
		}

		function get_order_by_id_and_order_number($order_id, $order_number)
		{
			$order = New WC_Order($order_id);
			if($order_number == get_post_meta($order_id, 'bambora_payform_order_number', true ))
				return $order;

			return null;
		}

		function check_bambora_payform_response()
		{
			$old_wc = version_compare(WC_VERSION, '3.0.0', '<');

			if(count($_GET))
			{
				$return_code = isset($_GET['RETURN_CODE']) ? $_GET['RETURN_CODE'] : -999;
				$incident_id = isset($_GET['INCIDENT_ID']) ? $_GET['INCIDENT_ID'] : null;
				$settled = isset($_GET['SETTLED']) ? $_GET['SETTLED'] : null;
				$authcode = isset($_GET['AUTHCODE']) ? $_GET['AUTHCODE'] : null;
				$contact_id = isset($_GET['CONTACT_ID']) ? $_GET['CONTACT_ID'] : null;
				$order_number = isset($_GET['ORDER_NUMBER']) ? $_GET['ORDER_NUMBER'] : null;

				$authcode_confirm = $return_code .'|'. $order_number;

				if(isset($return_code) && $return_code == 0)
				{
					$authcode_confirm .= '|' . $settled;
					if(isset($contact_id) && !empty($contact_id))
						$authcode_confirm .= '|' . $contact_id;
				}
				else if(isset($incident_id) && !empty($incident_id))
					$authcode_confirm .= '|' . $incident_id;

				$authcode_confirm = strtoupper(hash_hmac('sha256', $authcode_confirm, $this->private_key));

				$order_id = isset($_GET['order_id']) ? $_GET['order_id'] : null;

				if($order_id === null || $order_number === null)
					die ("No order_id nor order_number given.");

				$order = $this->get_order_by_id_and_order_number($order_id, $order_number);
				
				if($order === null)
					die ("Order not found.");

				$mk_on = get_post_meta($order_id, 'bambora_payform_order_number', true );

				$wc_order_id = $old_wc ? $order->id : $order->get_id();
				$wc_order_status = $old_wc ? $order->status : $order->get_status();

				if($authcode_confirm === $authcode && $order)
				{
					$current_return_code = get_post_meta($wc_order_id, 'bambora_payform_return_code', true);

					if($wc_order_status != 'processing' && $current_return_code != 0)
					{
						$pbw_extra_info = '';

						if($old_wc)
							update_post_meta($order_id, 'bambora_payform_return_code', $return_code);
						else
						{
							$order->update_meta_data('bambora_payform_return_code', $return_code);
							$order->save();
						}

						include_once(plugin_dir_path( __FILE__ ).'includes/lib/bambora_payform_loader.php');
						$payment = new Bambora\PayForm($this->api_key, $this->private_key);
						try
						{
							$result = $payment->checkStatusWithOrderNumber($mk_on);
							if(isset($result->source->object) && $result->source->object === 'card')
							{
								$pbw_extra_info .=  "<br>-<br>" . __('Payment method: Card payment', 'bambora-payform-payment-gateway') . "<br>";
								$pbw_extra_info .=  "<br>-<br>" . __('Card payment info: ', 'bambora-payform-payment-gateway') . "<br>";

								if(isset($result->source->card_verified))
								{
									$pbw_verified = $this->bambora_payform_translate_verified_code($result->source->card_verified);
									$pbw_extra_info .= isset($pbw_verified) ? __('Verified: ', 'bambora-payform-payment-gateway') . $pbw_verified . "<br>" : '';
								}

								$pbw_extra_info .= isset($result->source->card_country) ? __('Card country: ', 'bambora-payform-payment-gateway') . $result->source->card_country . "<br>" : '';
								$pbw_extra_info .= isset($result->source->client_ip_country) ? __('Client IP country: ', 'bambora-payform-payment-gateway') . $result->source->client_ip_country . "<br>" : '';

								if(isset($result->source->error_code))
								{
									$pbw_error = $this->bambora_payform_translate_error_code($result->source->error_code);
									$pbw_extra_info .= isset($pbw_error) ? __('Error: ', 'bambora-payform-payment-gateway') . $pbw_error . "<br>" : '';
								}								
							}
							elseif (isset($result->source->brand))
								$pbw_extra_info .=  "<br>-<br>" . __('Payment method: ', 'bambora-payform-payment-gateway') . ' ' . $result->source->brand . "<br>";
						}
						catch(Bambora\PayFormException $e)
						{
							$logger = new WC_Logger();
							$message = $e->getMessage();
							$logger->add( 'bambora-payform-payment-gateway', 'Bambora PayForm REST::checkStatusWithOrderNumber failed, message: ' . $message);
						}

						switch($return_code)
						{							
							case 0:
								if($settled == 0)
								{
									$is_settled = 0;
									if($old_wc)
										update_post_meta($order_id, 'bambora_payform_is_settled', $is_settled);
									else
									{
										$order->update_meta_data('bambora_payform_is_settled', $is_settled);
										$order->save();
									}
									$pbw_note = __('Bambora PayForm order', 'bambora-payform-payment-gateway') . ' ' . $mk_on . "<br>-<br>" . __('Payment is authorized. Use settle option to capture funds.', 'bambora-payform-payment-gateway') . "<br>";
								}
								else
									$pbw_note = __('Bambora PayForm order', 'bambora-payform-payment-gateway') . ' ' . $mk_on . "<br>-<br>" . __('Payment accepted.', 'bambora-payform-payment-gateway') . "<br>";

								$order->add_order_note($pbw_note . $pbw_extra_info);
								$order->payment_complete();
								WC()->cart->empty_cart();
								break;

							case 1:
								$pbw_note = __('Payment was not accepted.', 'bambora-payform-payment-gateway') . $pbw_extra_info;
								if($wc_order_status == 'failed')
									$order->add_order_note($pbw_note);
								else
									$order->update_status('failed', $pbw_note);
								break;

							case 4:
								$note = __('Transaction status could not be updated after customer returned from the web page of a bank. Please use the merchant UI to resolve the payment status.', 'bambora-payform-payment-gateway');
								if($wc_order_status == 'failed')
									$order->add_order_note($note);
								else
									$order->update_status('failed', $note);
								break;

							case 10:
								$note = __('Maintenance break. The transaction is not created and the user has been notified and transferred back to the cancel address.', 'bambora-payform-payment-gateway');
								if($wc_order_status == 'failed')
									$order->add_order_note($note);
								else
									$order->update_status('failed', $note);
								break;
						}
					}
				}
				else
					die ("MAC check failed");

				$cancel_url_option = $this->get_option('cancel_url', '');
				$card = ($result->source->object === 'card') ? true : false;
				$redirect_url = $this->bambora_payform_url($return_code, $order, $cancel_url_option, $card);
				wp_redirect($redirect_url);
				exit('Ok');
			}
		}

		function bambora_payform_url($return_code, $order, $cancel_url_option = '', $card = false)
		{
			if (version_compare(WOOCOMMERCE_VERSION, '2.1.0', '>='))
			{
				if($return_code == 0)
					$redirect_url = $this->get_return_url($order);
				else
				{
					if($card)
						$error_msg = __('Card payment failed. Your card has not been charged.', 'bambora-payform-payment-gateway');
					else
						$error_msg = __('Payment was canceled or charge was not accepted.', 'bambora-payform-payment-gateway');
					switch ($cancel_url_option)
					{
						case 'order_pay':
							do_action( 'woocommerce_set_cart_cookies',  true );
							$redirect_url = $order->get_checkout_payment_url();
							break;
						case 'order_new_cart':
							$redirect_url = wc_get_cart_url();
							break;
						case 'order_new_checkout':
							$redirect_url = wc_get_checkout_url();
							break;
						default:
							do_action( 'woocommerce_set_cart_cookies',  true );
							$redirect_url = $this->get_return_url($order);
							break;
					}
					wc_add_notice($error_msg, 'error');
				}
			}
			else
				$redirect_url = (get_option('woocommerce_thanks_page_id') != '' ) ? get_permalink(get_option('woocommerce_thanks_page_id')): get_site_url().'/' ;
			
			return $redirect_url;
		}

		function bambora_payform_settle_payment($order)
		{
			$old_wc = version_compare(WC_VERSION, '3.0.0', '<');
			$wc_order_id = $old_wc ? $order->id : $order->get_id();			

			$settle_field = get_post_meta( $wc_order_id, 'bambora_payform_is_settled', true );
			$settle_check = empty($settle_field) && $settle_field == "0";

			if(!$settle_check)
				return;

			$url = admin_url('post.php?post=' . absint( $wc_order_id ) . '&action=edit');

			if(isset($_GET['bambora_payform_settle']))
			{
				$order_number = get_post_meta( $wc_order_id, 'bambora_payform_order_number', true );
				$settlement_msg = '';

				if($this->process_settlement($order_number, $settlement_msg))
				{
					$order->add_order_note(__('Payment settled.', 'bambora-payform-payment-gateway'));
					
					if($old_wc)
						update_post_meta($wc_order_id, 'bambora_payform_is_settled', 1);
					else
					{
						$order->update_meta_data('bambora_payform_is_settled', 1);
						$order->save();
					}
					$settlement_result = '1';
				}
				else
					$settlement_result = '0';

				if(!$settlement_result)
					echo '<div id="message" class="error">'.$settlement_msg.' <p class="form-field"><a href="'.$url.'" class="button button-primary">OK</a></p></div>';
				else
				{
					echo '<div id="message" class="updated fade">'.$settlement_msg.' <p class="form-field"><a href="'.$url.'" class="button button-primary">OK</a></p></div>';
					return;
				}
			}


			$text = __('Settle payment', 'bambora-payform-payment-gateway');
			$url .= '&bambora_payform_settle';
			$html = "
				<p class='form-field'>
					<a href='$url' class='button button-primary'>$text</a>
				</p>";

			echo $html;
		}

		function process_settlement($order_number, &$settlement_msg)
		{
			include(plugin_dir_path( __FILE__ ).'includes/lib/bambora_payform_loader.php');
			$successful = false;
			$payment = new Bambora\PayForm($this->api_key, $this->private_key);
			try
			{
				$settlement = $payment->settlePayment($order_number);
				$return_code = $settlement->result;

				switch ($return_code)
				{
					case 0:
						$successful = true;
						$settlement_msg = __('Settlement was successful.', 'bambora-payform-payment-gateway');
						break;
					case 1:
						$settlement_msg = __('Settlement failed. Validation failed.', 'bambora-payform-payment-gateway');
						break;
					case 2:
						$settlement_msg = __('Settlement failed. Either the payment has already been settled or the payment gateway refused to settle payment for given transaction.', 'bambora-payform-payment-gateway');
						break;
					default:
						$settlement_msg = __('Settlement failed. Unkown error.', 'bambora-payform-payment-gateway');
						break;
				}
			}
			catch (Bambora\PayFormException $e) 
			{
				$message = $e->getMessage();
				$settlement_msg = __('Exception, error: ', 'bambora-payform-payment-gateway') . $message;
			}
			return $successful;
		}

		function bambora_payform_save_img($key, $img_url, $img_timestamp)
		{
			$img = 'assets/images/'.$key.'.png';
			$timestamp = file_exists(plugin_dir_path( __FILE__ ) . $img) ? filemtime(plugin_dir_path( __FILE__ ) . $img) : 0;
			if(!file_exists(plugin_dir_path( __FILE__ ) . $img) || $img_timestamp > $timestamp)
			{
				if($file = @fopen($img_url, 'r'))
				{
					if(class_exists('finfo'))
					{
						$finfo = new finfo(FILEINFO_MIME_TYPE);
						if(strpos($finfo->buffer($file_content = stream_get_contents($file)), 'image') !== false)
						{
							@file_put_contents(plugin_dir_path( __FILE__ ) . $img, $file_content);
							touch(plugin_dir_path( __FILE__ ) . $img, $img_timestamp);
						}
					}
					else
					{
						@file_put_contents(plugin_dir_path( __FILE__ ) . $img, $file);
						touch(plugin_dir_path( __FILE__ ) . $img, $img_timestamp);
					}
					@fclose($file);
				}
			}
			return $img;
		}

		function bambora_payform_translate_error_code($pbw_error_code)
		{
			switch ($pbw_error_code)
			{
				case '04':
					return ' 04 - ' . __('The card is reported lost or stolen.', 'bambora-payform-payment-gateway');
				case '05':
					return ' 05 - ' . __('General decline. The card holder should contact the issuer to find out why the payment failed.', 'bambora-payform-payment-gateway');
				case '51':
					return ' 51 - ' . __('Insufficient funds. The card holder should verify that there is balance on the account and the online payments are actived.', 'bambora-payform-payment-gateway');
				case '54':
					return ' 54 - ' . __('Expired card.', 'bambora-payform-payment-gateway');
				case '61':
					return ' 61 - ' . __('Withdrawal amount limit exceeded.', 'bambora-payform-payment-gateway');
				case '62':
					return ' 62 - ' . __('Restricted card. The card holder should verify that the online payments are actived.', 'bambora-payform-payment-gateway');
				case '1000':
					return ' 1000 - ' . __('Timeout communicating with the acquirer. The payment should be tried again later.', 'bambora-payform-payment-gateway');
				default:
					return null;
			}
		}

		function bambora_payform_translate_verified_code($pbw_verified_code)
		{
			switch ($pbw_verified_code)
			{
				case 'Y':
					return ' Y - ' . __('3-D Secure was used.', 'bambora-payform-payment-gateway');
				case 'N':
					return ' N - ' . __('3-D Secure was not used.', 'bambora-payform-payment-gateway');
				case 'A':
					return ' A - ' . __('3-D Secure was attempted but not supported by the card issuer or the card holder is not participating.', 'bambora-payform-payment-gateway');
				default:
					return null;
			}
		}
	}
}
