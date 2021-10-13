<?php
/**
 * Payex Gateway
 *
 * @package     payex_woocommerce_gateway
 *
 * @wordpress-plugin
 * Plugin Name:       Payex Payment Gateway for Woocommerce
 * Plugin URI:        https://payex.io
 * Description:       Accept Online Banking, Cards, EWallets, Instalments, and Subscription payments using Payex
 * Version:           1.2.0
 * Requires at least: 4.7
 * Requires PHP:      7.0
 * Author:            Payex Ventures Sdn Bhd
 * Author URI:        https://payex.io
 * License:           The MIT License (MIT)
 * License URI:       https://opensource.org/licenses/MIT
 */

if (!defined('ABSPATH'))
{
    exit;
}

const PAYEX_AUTH_CODE_SUCCESS = '00';
const PAYEX_AUTH_CODE_PENDING = '09';
const PAYEX_AUTH_CODE_PENDING_2 = '99';

// Registers payment gateway.
add_filter('woocommerce_payment_gateways', 'payex_add_gateway_class');
/**
 * Add Payex Gateway
 *
 * @param  string $gateways Add Gateway.
 * @return mixed
 */
function payex_add_gateway_class($gateways)
{
    $gateways[] = 'WC_Payex_Gateway';
    return $gateways;
}
// add plugin load for init payex gateway.
add_action('plugins_loaded', 'payex_init_gateway_class');
/**
 * Payex Init gateway function
 */
function payex_init_gateway_class()
{
    /**
     * Class WC_PAYEX_GATEWAY
     */
    class WC_PAYEX_GATEWAY extends WC_Payment_Gateway
    {

        const API_URL = 'https://api.payex.io/';
        const API_URL_SANDBOX = 'https://sandbox-payexapi.azurewebsites.net/';
        const API_GET_TOKEN_PATH = 'api/Auth/Token';
        const API_PAYMENT_FORM = 'api/v1/PaymentIntents';
        const API_MANDATE_FORM = 'api/v1/Mandates';
        const API_COLLECTIONS = 'api/v1/Mandates/Collections';
        const HOOK_NAME = 'payex_hook';

        /**
         * Class constructor
         */
        public function __construct()
        {

            $this->id = 'payex'; // payment gateway plugin ID.
            $this->icon = 'https://payexpublic.blob.core.windows.net/storage/payex_woocommerce.jpg'; // URL of the icon that will be displayed on checkout page near your gateway name.
            $this->has_fields = true; // in case you need a custom credit card form.
            $this->method_title = 'Payex Payment Gateway';
            $this->method_description = 'Accept Online Banking, Cards, EWallets and Instalments using Payex Payment Gateway (https://www.payex.io/)'; // will be displayed on the options page.
            $this->order_button_text = 'Pay via Payex';

            // Method with all the options fields.
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');
            $this->testmode = 'yes' === $this->get_option('testmode');

            $this->supports = array(
                'products',
                'subscriptions',
                'subscription_amount_changes',
                'subscription_date_changes',
                'subscription_cancellation',
                'subscription_suspension',
                'subscription_reactivation',
                'multiple_subscriptions',
            );

            // This action hook saves the settings.
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ));
            add_action('woocommerce_api_wc_payex_gateway', array(&$this, 'webhook'));
            if (class_exists('WC_Subscriptions_Order'))
            {
                add_action('woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2);
			    add_action('woocommerce_subscription_failing_payment_method_updated_' . $this->id, array( $this, 'update_failing_payment_method' ), 10, 2);
                add_action('wcs_resubscribe_order_created', array( $this, 'delete_resubscribe_meta' ), 10);
			    add_action('wcs_renewal_order_created', array( $this, 'delete_renewal_meta' ), 10);
            }
        }

        /**
         * Plugin options, we deal with it in Step 3 too
         */
        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'label' => 'Enable Payex Payment Gateway',
                    'type' => 'checkbox',
                    'description' => '',
                    'default' => 'no',
                ) ,
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'description' => 'This controls the title which the user sees during checkout',
                    'default' => 'Payex',
                ) ,
                'description' => array(
                    'title' => 'Description',
                    'type' => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout',
                    'default' => 'Pay via Payex using Online Banking, Cards, EWallets and Instalments',
                ) ,
                'testmode' => array(
                    'title' => 'Sandbox environment',
                    'label' => 'Enable sandbox environment',
                    'type' => 'checkbox',
                    'description' => 'Test our payment gateway in the sandbox environment using the sandbox Secret and the same email address',
                    'default' => 'no',
                    'desc_tip' => true,
                ) ,
                'email' => array(
                    'title' => 'Payex Email',
                    'type' => 'text',
                    'description' => 'This email where by you used to sign up and login to Payex Portal',
                    'default' => null,
                ) ,
                'secret_key' => array(
                    'title' => 'Payex Secret',
                    'type' => 'password',
                    'description' => 'This secret should be used when you are ready to go live. Obtain the secret from Payex Portal',
                ) ,
            );
        }

        /**
         * Custom checkout fields
         */
        public function payment_fields()
        {
            if ($this->description)
            {
                echo wpautop(wp_kses_post($this->description));
            }
        }

        /**
         * Custom CSS and JS
         */
        public function payment_scripts()
        {
        }

        /**
         * Fields validation for payment_fields()
         */
        public function validate_fields()
        {
            return true;
        }

        /**
         * Process Payment & generate Payex form link
         *
         * @param  string $order_id Woocommerce order id.
         * @return null|array
         */
        public function process_payment($order_id)
        {
            global $woocommerce;

            // we need it to get any order details.
            $order = wc_get_order($order_id);
            $url = self::API_URL;

            if ($this->get_option('testmode') === 'yes')
            {
                $url = self::API_URL_SANDBOX;
            }

            $token = $this->get_payex_token($url);

            if ($token)
            {
                // generate payex payment link.
                if (class_exists('WC_Subscriptions_Order') && WC_Subscriptions_Order::order_contains_subscription($order_id))
                {
                    $payment_link = $this->get_payex_mandate_link($url, $order, WC()->api_request_url(get_class($this)) , $token);
                }
                else
                {
                    $payment_link = $this->get_payex_payment_link($url, $order, WC()->api_request_url(get_class($this)) , $token);
                }

                // Redirect to checkout page on Payex.
                return array(
                    'result' => 'success',
                    'redirect' => $payment_link,
                );
            }
            else
            {
                wc_add_notice('Payment gateway is temporary down, we are checking on it, please try again later.', 'error');
                return;
            }
            // get token.
        }

        /**
         * Webhook
         */
        public function webhook()
        {
            $verified = $this->verify_payex_response($_POST); // phpcs:ignore
            if ($verified && isset($_POST['reference_number']) && (isset($_POST['auth_code']) || isset($_POST['collection_status'])))
            { 
                // phpcs:ignore
                if (isset($_POST['collection_reference_number']))
                {
                    $order = wc_get_order(sanitize_text_field(wp_unslash($_POST['collection_reference_number']))); // phpcs:ignore
                    $response_code = sanitize_text_field(wp_unslash($_POST['collection_status'])); // phpcs:ignore
                }
                else
                {
                    $order = wc_get_order(sanitize_text_field(wp_unslash($_POST['reference_number']))); // phpcs:ignore
                    $response_code = sanitize_text_field(wp_unslash($_POST['auth_code'])); // phpcs:ignore
                }
                
                // verify the payment is successful.
                if (PAYEX_AUTH_CODE_SUCCESS == $response_code)
                {
                    if (!$order->is_paid())
                    {
                        // only mark order as completed if the order was not paid before.
                        $order->payment_complete(sanitize_text_field(wp_unslash($_POST['txn_id'])));
                        $order->reduce_order_stock();
                        WC_Subscriptions_Manager::activate_subscriptions_for_order($order);
                        update_post_meta($order->get_id() , 'payex_mandate_number', sanitize_text_field(wp_unslash($_POST['mandate_reference_number'])));
                    }
                }
            }
        }

        /**
         * Generate Payment form link to allow users to Pay
         *
         * @param  string      $url             Payex API URL.
         * @param  string      $order           Customer order.
         * @param  string      $callback_url    Callback URL when customer completed payment.
         * @param  string|null $token           Payex token.
         * @return string
         */
        private function get_payex_payment_link($url, $order, $callback_url, $token = null)
        {
            $order_data = $order->get_data();
            $order_items = $order->get_items();
            $accept_url = $this->get_return_url($order);
            $reject_url = $order->get_checkout_payment_url();

            if (!$token)
            {
                $token = $this->getToken() ['token'];
            }

            $items = array();

            foreach ($order_items as $item_id => $item)
            {
                // order item data as an array
                $item_data = $item->get_data();
                array_push($items, $item_data);
            }

            if ($token)
            {
                $body = wp_json_encode(array(
                    array(
                        "amount" => round($order_data['total'] * 100, 0) ,
                        "currency" => $order_data['currency'],
                        "customer_id" => $order_data['customer_id'],
                        "description" => 'Payment for Order Reference:' . $order_data['order_key'],
                        "reference_number" => $order_data['id'],
                        "customer_name" => $order_data['billing']['first_name'] . ' ' . $order_data['billing']['last_name'],
                        "contact_number" => $order_data['billing']['phone'],
                        "email" => $order_data['billing']['email'],
                        "address" => $order_data['billing']['company'] . ' ' . $order_data['billing']['address_1'] . ',' . $order_data['billing']['address_2'],
                        "postcode" => $order_data['billing']['postcode'],
                        "city" => $order_data['billing']['city'],
                        "state" => $order_data['billing']['state'],
                        "country" => $order_data['billing']['country'],
                        "shipping_name" => $order_data['shipping']['first_name'] . ' ' . $order_data['shipping']['last_name'],
                        "shipping_address" => $order_data['shipping']['company'] . ' ' . $order_data['shipping']['address_1'] . ',' . $order_data['shipping']['address_2'],
                        "shipping_postcode" => $order_data['shipping']['postcode'],
                        "shipping_city" => $order_data['shipping']['city'],
                        "shipping_state" => $order_data['shipping']['state'],
                        "shipping_country" => $order_data['shipping']['country'],
                        "return_url" => $accept_url,
                        "accept_url" => $accept_url,
                        "reject_url" => $reject_url,
                        "callback_url" => $callback_url,
                        "items" => $items,
                        "source" => "wordpress"
                    )
                ));

                $request = wp_remote_post($url . self::API_PAYMENT_FORM, array(
                    'method' => 'POST',
                    'timeout' => 45,
                    'headers' => array(
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer ' . $token,
                    ) ,
                    'cookies' => array() ,
                    'body' => $body
                ));

                if (is_wp_error($request) || 200 !== wp_remote_retrieve_response_code($request))
                {
                    error_log(print_r($request, true));
                }
                else
                {
                    $response = wp_remote_retrieve_body($request);
                    $response = json_decode($response, true);
                    if ($response['status'] == '99' || count($response['result']) == 0) error_log(print_r($request, true));
                    return $response['result'][0]['url'];
                }
            }

            return false;
        }

        /**
         * Generate Mandate form link to allow users to Pay
         *
         * @param  string      $url             Payex API URL.
         * @param  string      $order           Customer order.
         * @param  string      $callback_url    Callback URL when customer completed payment.
         * @param  string|null $token           Payex token.
         * @return string
         */
        private function get_payex_mandate_link($url, $order, $callback_url, $token = null)
        {
            $order_data = $order->get_data();
            $order_items = $order->get_items();
            $accept_url = $this->get_return_url($order);
            $reject_url = $order->get_checkout_payment_url();

            if (!$token)
            {
                $token = $this->getToken() ['token'];
            }

            $items = array();
            $subscriptions = array();

            foreach ($order_items as $item_id => $item)
            {
                // order item data as an array
                $item_data = $item->get_data();
                array_push($items, $item_data);

                $product_id = $item_data['product_id'];
                if (WC_Subscriptions_Product::is_subscription($product_id))
                {
                    $subscriptions[$product_id] = array(
                        "price" => get_post_meta($product_id, '_subscription_price', true),
                        "sign_up_fee" => get_post_meta($product_id, '_subscription_sign_up_fee', true),
                        "period" => get_post_meta($product_id, '_subscription_period', true),
                        "interval" => get_post_meta($product_id, '_subscription_period_interval', true),
                        "length" => get_post_meta($product_id, '_subscription_length', true),
                        "trial_period" => get_post_meta($product_id, '_subscription_trial_period', true),
                        "trial_length" => get_post_meta($product_id, '_subscription_trial_length', true),
                        "sync_date" => get_post_meta($product_id, '_subscription_payment_sync_date', true),
                    );
                }
            }

            $initial_payment = WC_Subscriptions_Order::get_total_initial_payment($order);
            $amount = WC_Subscriptions_Order::get_recurring_total($order);

            if ($initial_payment > 0) $debit_type = "AD";

            if ($token)
            {
                $body = wp_json_encode(array(
                    array(
                        "max_amount" => max(3000000, round($amount * 100, 0)) ,
                        "initial_amount" => round($initial_payment * 100, 0) ,
                        "currency" => $order_data['currency'],
                        "customer_id" => $order_data['customer_id'],
                        "purpose" => 'Payment for Order Reference:' . $order_data['order_key'],
                        "merchant_reference_number" => $order_data['id'],
                        "frequency" => 'DL',
                        "effective_date" => date("Ymd"),
                        "max_frequency" => 999,
                        "debit_type" => $debit_type,
                        "auto" => false,
                        "customer_name" => $order_data['billing']['first_name'] . ' ' . $order_data['billing']['last_name'],
                        "contact_number" => $order_data['billing']['phone'],
                        "email" => $order_data['billing']['email'],
                        "address" => $order_data['billing']['company'] . ' ' . $order_data['billing']['address_1'] . ',' . $order_data['billing']['address_2'],
                        "postcode" => $order_data['billing']['postcode'],
                        "city" => $order_data['billing']['city'],
                        "state" => $order_data['billing']['state'],
                        "country" => $order_data['billing']['country'],
                        "shipping_name" => $order_data['shipping']['first_name'] . ' ' . $order_data['shipping']['last_name'],
                        "shipping_address" => $order_data['shipping']['company'] . ' ' . $order_data['shipping']['address_1'] . ',' . $order_data['shipping']['address_2'],
                        "shipping_postcode" => $order_data['shipping']['postcode'],
                        "shipping_city" => $order_data['shipping']['city'],
                        "shipping_state" => $order_data['shipping']['state'],
                        "shipping_country" => $order_data['shipping']['country'],
                        "return_url" => $accept_url,
                        "accept_url" => $accept_url,
                        "reject_url" => $reject_url,
                        "callback_url" => $callback_url,
                        "items" => $items,
                        "metadata" => $subscriptions,
                        "source" => "wordpress"
                    )
                ));

                $request = wp_remote_post($url . self::API_MANDATE_FORM, array(
                    'method' => 'POST',
                    'timeout' => 45,
                    'headers' => array(
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer ' . $token,
                    ) ,
                    'cookies' => array() ,
                    'body' => $body
                ));

                if (is_wp_error($request) || 200 !== wp_remote_retrieve_response_code($request))
                {
                    error_log(print_r($request, true));
                }
                else
                {
                    $response = wp_remote_retrieve_body($request);
                    $response = json_decode($response, true);
                    if ($response['status'] == '99' || count($response['result']) == 0) error_log(print_r($request, true));
                    return $response['result'][0]['url'];
                }
            }

            return false;
        }

        /**
	     * process_subscription_payment function.
	     * @param mixed $order
	     * @param int $amount (default: 0)
	     */
	    public function process_subscription_payment($amount_to_charge, $renewal_order) 
        {
            $url = self::API_URL;

            if ($this->get_option('testmode') === 'yes')
            {
                $url = self::API_URL_SANDBOX;
            }

            $token = $this->get_payex_token($url);

            if ($token)
            {
                $order_id = $renewal_order->get_id();
                $subscription_id = get_post_meta($order_id, '_subscription_renewal', true);
                $subscription_order = wc_get_order($subscription_id);
                $parent_id = $subscription_order->get_parent_id();
                $mandate_number = get_post_meta($parent_id, 'payex_mandate_number', true);

                $body = wp_json_encode(array(
                    array(
                        "reference_number" => $mandate_number,
                        "collection_reference_number" => $order_id,
                        "amount" => round($amount_to_charge * 100, 0) ,
                        "collection_date" => date("Ymd")
                    )
                ));

                $request = wp_remote_post($url . self::API_COLLECTIONS, array(
                    'method' => 'POST',
                    'timeout' => 45,
                    'headers' => array(
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer ' . $token,
                    ) ,
                    'cookies' => array() ,
                    'body' => $body
                ));

                if (is_wp_error($request) || 200 !== wp_remote_retrieve_response_code($request))
                {
                    $renewal_order->update_status('failed', 'Invalid Request');
                    error_log(print_r($request, true));
                }
                else
                {
                    $response = wp_remote_retrieve_body($request);
                    $response = json_decode($response, true);
                    if ($response['status'] == '99' || count($response['result']) == 0 || (count($response['result']) != 0 && $response['result'][0]['status'] == '99'))
                    {
                        $error = $response['message'];
                        if (count($response['result']) != 0) $error = $response['result'][0]['error'];
                        $renewal_order->update_status('failed', $error);
                        error_log(print_r($error, true));
                    }

                    update_post_meta($order_id, 'payex_collection_number', $response['result'][0]['collection_number']);
                }
            }
            else
            {
                $renewal_order->update_status('failed', 'Invalid Token');
                error_log(print_r($request, true));
            }
	    }

        /**
         * scheduled_subscription_payment function.
         *
         * This function is called when renewal order is triggered.
         *
         * @param $amount_to_charge float The amount to charge.
         * @param $renewal_order WC_Order A WC_Order object created to record the renewal payment.
         */
        public function scheduled_subscription_payment($amount_to_charge, $renewal_order)
        {
            $this->process_subscription_payment( $amount_to_charge, $renewal_order );
        }

        /**
	     * @param int $resubscribe_order The order created for the customer to resubscribe to the old expired/cancelled subscription
	     */
	    public function delete_resubscribe_meta( $resubscribe_order ) 
        {
		    $this->delete_renewal_meta( $resubscribe_order );
	    }

	    /**
	     * @param int $resubscribe_order The order created for the customer to resubscribe to the old expired/cancelled subscription
	     */
	    public function delete_renewal_meta( $renewal_order ) 
        {
		    return $renewal_order;
	    }

        /**
	     * an automatic renewal payment which previously failed.
	     *
	     * @access public
	     * @param WC_Subscription $subscription The subscription for which the failing payment method relates.
	     * @param WC_Order $renewal_order The order which recorded the successful payment (to make up for the failed automatic payment).
	     * @return void
	     */
	    public function update_failing_payment_method( $subscription, $renewal_order ) 
        {
	    }

        /**
         * Get Payex Token
         *
         * @param   string $url  Payex API Url.
         * @return bool|mixed
         */
        private function get_payex_token($url)
        {
            $email = $this->get_option('email');
            $secret = $this->get_option('secret_key');

            $request = wp_remote_post($url . self::API_GET_TOKEN_PATH, array(
                'method' => 'POST',
                'timeout' => 45,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Basic ' . base64_encode($email . ':' . $secret) ,
                ) ,
                'cookies' => array() ,
            ));

            if (is_wp_error($request) || 200 !== wp_remote_retrieve_response_code($request))
            {
                error_log(print_r($request, true));
            }
            else
            {
                $response = wp_remote_retrieve_body($request);
                $response = json_decode($response, true);
                return $response['token'];
            }
            return false;
        }

        /**
         * Verify Response
         *
         * Used to verify response data integrity
         * Signature: implode all returned data pipe separated then hash with sha512
         *
         * @param  array $response  Payex response after checkout.
         * @return bool
         */
        public function verify_payex_response($response)
        {
            if (isset($response['signature']) && isset($response['txn_id']))
            {
                ksort($response); // sort array keys ascending order.
                $host_signature = sanitize_text_field(wp_unslash($response['signature']));
                $signature = $this->get_option('secret_key') . '|' . sanitize_text_field(wp_unslash($response['txn_id'])); // append secret key infront.
                $hash = hash('sha512', $signature);

                if ($hash == $host_signature)
                {
                    return true;
                }
            }
            return false;
        }
    }
}
