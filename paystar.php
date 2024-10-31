<?php

/*
Plugin Name: paystar-payment-for-learnpress
Plugin URI: https://paystar.ir
Description: paystar-payment-for-learnpress
Version: 1.0
Author: ماژول بانک
Author URI: https://www.modulebank.ir
Text Domain: paystar-payment-for-learnpress
Domain Path: /languages
 */

load_plugin_textdomain('paystar-payment-for-learnpress', false, basename(dirname(__FILE__)) . '/languages');
__('paystar-payment-for-learnpress', 'paystar-payment-for-learnpress');

if (isset($_GET['modulebank_paystar_token']) && $_GET['modulebank_paystar_token']) {
	session_write_close();
	echo '<div class="paystar-wc-wait" style="position:fixed; width:100%; height:100%; left:0; top:0; z-index:9999; opacity:0.90; -moz-opacity:0.90; filter:alpha(opacity=90); background-color:#fff;">
			<img src="' . esc_url(plugin_dir_url(__FILE__)) . 'images/wait.gif" style="position:fixed; left:50%; top:50%; width:466px; height:368px; margin:-184px 0 0 -233px;" />
		</div>';
	echo '<form name="frmPayStarPayment" method="post" action="https://core.paystar.ir/api/pardakht/payment"><input type="hidden" name="token" value="'.esc_html($_GET['modulebank_paystar_token']).'" />';
	echo '<input class="paystar_btn btn button" type="submit" value="'.__('Pay', 'paystar-payment-for-learnpress').'" /></form>';
	echo '<script>document.frmPayStarPayment.submit();</script>';
}

add_filter('learn-press/payment-methods', 'learn_press_payment_method_paystar' , 10, 1);
function learn_press_payment_method_paystar($gateways)
{
	$gateways['paystar'] = 'LP_Gateway_PayStar';
	return $gateways;
}

add_filter('learn-press/currencies', 'learn_press_get_payment_currencies_paystar', 10, 1);
function learn_press_get_payment_currencies_paystar($currencies)
{
	$currencies['IRR'] = __('Iranian Rial', 'paystar-payment-for-learnpress');
	$currencies['IRT'] = __('Iranian Toman', 'paystar-payment-for-learnpress');
	return $currencies;
}

add_filter('learn-press/currency-symbol', 'learn_press_currency_symbol_paystar', 10, 2);
function learn_press_currency_symbol_paystar($currency_symbol, $currency)
{
	if ($currency == 'IRR') $currency_symbol = __('Iranian Rial', 'paystar-payment-for-learnpress');
	elseif ($currency == 'IRT') $currency_symbol = __('Iranian Toman', 'paystar-payment-for-learnpress');
	return $currency_symbol;
}

class LP_Gateway_PayStar extends LP_Gateway_Abstract
{
	protected $paystar_terminal = null;
	protected $settings = null;
	public function __construct()
	{
		$this->id = 'paystar';
		$this->method_title = __('PayStar', 'paystar-payment-for-learnpress');
		$this->method_description = __('Pay with PayStar', 'paystar-payment-for-learnpress');
		$this->title = __('PayStar', 'paystar-payment-for-learnpress');
		$this->description = __('Pay with PayStar', 'paystar-payment-for-learnpress');
		$this->settings = LP()->settings()->get_group( 'paystar', '' );
		$this->enabled = $this->settings->get( 'enable' );
		$this->paystar_terminal = $this->settings->get( 'paystar_terminal' );
		$this->init();
		parent::__construct();
	}

	public function get_settings()
	{
		return array(
				array(
					'title' => __('Enabled', 'paystar-payment-for-learnpress'),
					'id' => '[enable]',
					'default' => 'no',
					'type' => 'yes-no',
				),
				array(
					'title' => __('PayStar Terminal', 'paystar-payment-for-learnpress'),
					'id' => '[paystar_terminal]',
					'type' => 'text',
				),
			);
	}

	public function init()
	{
		if ($this->is_enabled())
		{
			if (did_action('init'))
			{
				$this->register_web_hook();
			}
			else
			{
				add_action('init', array($this, 'register_web_hook'));
			}
			add_action('learn_press_web_hook_learn_press_paystar', array($this, 'web_hook_process_paystar'));
		}
	}

	public function register_web_hook()
	{
		learn_press_register_web_hook('paystar', 'learn_press_paystar');
	}

	public function web_hook_process_paystar($request)
	{
		if (isset($_POST['status'],$_POST['order_id'],$_POST['ref_num']))
		{
			$post_status = sanitize_text_field($_POST['status']);
			$post_order_id = sanitize_text_field($_POST['order_id']);
			$post_ref_num = sanitize_text_field($_POST['ref_num']);
			$post_tracking_code = sanitize_text_field($_POST['tracking_code']);
			list($order_id, $nothing) = explode('#', $post_order_id);
			$order = LP_Order::instance($order_id);
			$amount = 0;
			if ($items = LP()->get_cart()->get_items()) foreach ($items as $item) $amount += $item['total'] * $item['quantity'];
			$amount2 = $amount = intval(ceil($amount));
			if (learn_press_get_currency() != 'IRR') $amount2 *= 10;
			require_once(dirname(__FILE__) . '/paystar_payment_helper.class.php');
			$p = new PayStar_Payment_Helper($this->paystar_terminal);
			$r = $p->paymentVerify($x = array(
					'status' => $post_status,
					'order_id' => $post_order_id,
					'ref_num' => $post_ref_num,
					'tracking_code' => $post_tracking_code,
					'amount' => $amount2
				));
			if ($r)
			{
				if (!$order->has_status('completed'))
				{
					$this->payment_complete($order, esc_html($p->txn_id));
					update_post_meta($order_id, 'amount', learn_press_format_price($amount, true));
					update_post_meta($order_id, 'refnum', esc_html($p->txn_id));
					update_post_meta($order_id, '_transaction_fee', $amount);
				}
				wp_redirect($this->get_return_url($order));
			}
			else
			{
				update_post_meta($order_id, 'payment_error', esc_html($p->error));
				wp_redirect(learn_press_is_enable_cart() ? learn_press_get_page_link('cart') : get_site_url());
			}
			exit;die;
		}
	}

	public function payment_method_name($slug)
	{
		return $slug == 'paystar' ? __('PayStar', 'paystar-payment-for-learnpress') : $slug;
	}


	public function get_payment_form()
	{
		$output = $this->get_description();
		if ($this->paystar_terminal == '')
		{
			$output .= learn_press_get_message(__('PayStar settings is not setup', 'paystar-payment-for-learnpress'), 'error');
			$output .= '<input type="hidden" name="payment_method_paystar-error" value="yes" />';
		}
		return $output;
	}

	public function payment_complete($order, $txn_id = '', $note = '')
	{
		$order->payment_complete($txn_id);
	}

	public function process_payment($order_id)
	{
		$order = LP_Order::instance($order_id);
		$amount = 0;
		if ($items = LP()->get_cart()->get_items()) foreach ($items as $item) $amount += $item['total'] * $item['quantity'];
		$user = learn_press_get_current_user();
		$amount = intval(ceil($amount));
		if (learn_press_get_currency() != 'IRR') $amount *= 10;
		require_once(dirname(__FILE__) . '/paystar_payment_helper.class.php');
		$p = new PayStar_Payment_Helper($this->paystar_terminal);
		$r = $p->paymentRequest(array(
				'amount'   => $amount,
				'order_id' => $order_id . '#' . time(),
				'name'     => $user->get_data('display_name'),
				'mail'     => $user->get_data('email'),
				'callback' => add_query_arg(array('learn_press_paystar' => 1), get_site_url().'/'),
			));
		if ($r)
		{
			return array('result' => 'success', 'redirect' => add_query_arg(array('modulebank_paystar_token' => esc_html($p->data->token)), get_site_url().'/'));
		}
		else
		{
			$message = esc_html($p->error);
			throw new exception($message);
			return array('result' => 'fail');
		}
	}

	public function get_icon()
	{
		if ( empty( $this->icon ) )
		{
			$this->icon = plugin_dir_url(__FILE__) . '/images/logo.png';
		}
		return parent::get_icon();
	}

	public function __toString()
	{
		return __('PayStar', 'paystar-payment-for-learnpress');
	}

}

?>