<?php
/*
Plugin Name: Woocommerce price based on country
Description: Control country-based pricing, shipping & promos
Version: 1.0
Author: Rajneesh Saini
Plugin URI: https://www.boldertechnologies.net/woo-price-based-country
Requires PHP: 5.6.20
Author URI: https://www.upwork.com/freelancers/rajneeshkumarsaini
*/

if (!defined('ABSPATH')) exit;

class Woo_Country_Switch_Pricing {

    public function __construct() {
        // Hooks for plugin settings and WooCommerce modifications
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('woocommerce_product_options_pricing', [$this, 'add_country_price_fields']);
        add_action('woocommerce_process_product_meta', [$this, 'save_country_price_fields']);
        add_filter('woocommerce_get_price_html', [$this, 'adjust_price_based_on_country'], 10, 2);
		
		// Hook to adjust the mini cart item prices
		add_action('woocommerce_before_mini_cart', [$this, 'adjust_mini_cart_item_prices']);
		
        // Adjust pricing in cart, checkout, and orders
        add_action('woocommerce_before_calculate_totals', [$this, 'adjust_cart_item_prices']);
        add_filter('woocommerce_order_item_get_subtotal', [$this, 'adjust_order_item_prices'], 10, 3);
        add_filter('woocommerce_order_item_get_total', [$this, 'adjust_order_item_prices'], 10, 3);	
		
		// Currency change based on country
        add_filter('woocommerce_currency', [$this, 'set_currency_based_on_country']);

        // Add shortcode for displaying country flags
        add_shortcode('country_switcher', [$this, 'country_switcher_shortcode']);

        // Handle AJAX request to set the selected country
        add_action('wp_ajax_set_country', [$this, 'set_country']);
        add_action('wp_ajax_nopriv_set_country', [$this, 'set_country']);
		add_action('wp_head', [$this, 'output_country_specific_css']);
		add_filter('woocommerce_default_address_fields', [$this, 'woo_price_rename_state_province'], 9999, 2);
		// Set country for shipping dynamically
    	add_action('woocommerce_before_calculate_totals', [$this, 'set_country_for_shipping']);
    } 
	function woo_price_rename_state_province( $fields ) {
		//echo "<pre>";print_r($fields);
		if (isset($_COOKIE['selected_country'])) {
            if($_COOKIE['selected_country'] == "US"){
				$fields['postcode']['label'] = __('Zipcode', 'woocommerce');				
			   //echo $_COOKIE['selected_country'];exit();
			}
        }
		//print_r($fields);
		return $fields;
	}
    // Create admin menu for settings
    public function add_settings_page() {
        add_menu_page('Country Pricing Settings', 'Country Pricing', 'manage_options', 'woo-country-pricing', [$this, 'settings_page_html']);
    }

    // Register settings for manual exchange rates and ipinfo.io API key
    public function register_settings() {
        register_setting('woo_country_pricing', 'woo_country_pricing_exchange_rates');
        register_setting('woo_country_pricing', 'woo_country_pricing_ipinfo_api_key');
		register_setting('woo_country_pricing', 'woo_country_pricing_country_css');
		register_setting('woo_country_pricing', 'woo_country_pricing_currency_map');
    }

    // Output the settings page HTML
    public function settings_page_html() {
        ?>
        <div class="wrap">
            <h1>Country Pricing Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('woo_country_pricing');
                do_settings_sections('woo-country-pricing');
                $exchange_rates = get_option('woo_country_pricing_exchange_rates', []);
                $ipinfo_api_key = get_option('woo_country_pricing_ipinfo_api_key', '');
				$country_css = get_option('woo_country_pricing_country_css', '{}');
				$currency_map = get_option('woo_country_pricing_currency_map', '{}');
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Exchange Rates (Country => Rate)</th>
                        <td>
                            <textarea name="woo_country_pricing_exchange_rates" rows="10" cols="50" class="large-text code"><?php echo $exchange_rates; ?></textarea>
                            <p class="description">Add exchange rates in JSON format. Example: {"US":1, "CA":1.25}</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">ipinfo.io API Key</th>
                        <td>
                            <input type="text" name="woo_country_pricing_ipinfo_api_key" value="<?php echo esc_attr($ipinfo_api_key); ?>" class="regular-text" />
                            <p class="description">Enter your ipinfo.io API key for country detection.</p>
                        </td>
                    </tr>
					<tr>
                        <th scope="row">Country-Specific CSS</th>
                        <td>
                            <textarea name="woo_country_pricing_country_css" rows="10" cols="50" class="large-text code"><?php echo esc_textarea($country_css); ?></textarea>
                            <p class="description">Enter country-specific CSS in JSON format. Example: {"US": ".header { background-color: blue; }", "CA": ".header { background-color: red; }"}</p>
                        </td>
                    </tr>
					<tr>
                        <th scope="row">Currency Map (Country => Currency Code)</th>
                        <td>
                            <textarea name="woo_country_pricing_currency_map" rows="10" cols="50" class="large-text code"><?php echo esc_textarea($currency_map); ?></textarea>
                            <p class="description">Add currency codes in JSON format. Example: {"US":"USD", "CA":"CAD", "GB":"GBP"}</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    // Add custom pricing fields on product edit page
    public function add_country_price_fields() {
        global $woocommerce, $post;
        echo '<div class="options_group">';
        $countries_json = get_option('woo_country_pricing_exchange_rates', []);
        $countries = json_decode($countries_json, true);
        foreach ($countries as $country => $value) {
            woocommerce_wp_text_input([
                'id' => '_price_' . $country,
                'label' => __('Price for ' . $country, 'woocommerce') . ' (' . get_woocommerce_currency_symbol() . ')',
                'desc_tip' => true,
                'description' => __('Enter custom price for ' . $country, 'woocommerce')
            ]);
        }
        echo '</div>';
    }

    // Save custom pricing fields for each country
    public function save_country_price_fields($post_id) {
        $countries_json = get_option('woo_country_pricing_exchange_rates', []);
        $countries = json_decode($countries_json, true);
        foreach ($countries as $country => $value) {
            $price = isset($_POST['_price_' . $country]) ? sanitize_text_field($_POST['_price_' . $country]) : '';
            if (!empty($price)) {
                update_post_meta($post_id, '_price_' . $country, $price);
            }
        }
    }
	
	// Function to set the customer's country dynamically for WooCommerce shipping
	public function set_country_for_shipping() {
		$country = $this->get_user_country();
		if (!$country) return;

		// Set the billing and shipping country in WooCommerce customer session
		WC()->customer->set_billing_country($country);
		WC()->customer->set_shipping_country($country);

		// Refresh shipping methods to apply the correct zone
		WC()->session->set('reload_checkout', true);
	}
    // Adjust price based on country
    public function adjust_price_based_on_country($price, $product) {
        $country = $this->get_user_country();
        if (!$country) return $price;
		
        $country_price = get_post_meta($product->get_id(), '_price_' . $country, true);
        if ($country_price) {
            $exchange_rates = get_option('woo_country_pricing_exchange_rates', []);
            $exchange_rate = isset($exchange_rates[$country]) ? floatval($exchange_rates[$country]) : 1;
            $price = wc_price($country_price * $exchange_rate);
			return $price;
        }else{
			$shop_country = wc_get_base_location()['country'];	
			return $price;
		}
    }
	
	// Adjust cart item prices based on selected or detected country
    public function adjust_cart_item_prices($cart) {
        $country = $this->get_user_country();
        if (!$country) return;

        foreach ($cart->get_cart() as $cart_item) {
            $product_id = $cart_item['product_id'];
            $country_price = get_post_meta($product_id, '_price_' . $country, true);

            if ($country_price) {
                $exchange_rates = get_option('woo_country_pricing_exchange_rates', []);
                $exchange_rate = isset($exchange_rates[$country]) ? floatval($exchange_rates[$country]) : 1;
                $cart_item['data']->set_price($country_price * $exchange_rate);
            }
        }
    }
	
	// Adjust mini cart item prices based on selected or detected country
	public function adjust_mini_cart_item_prices() {
		$country = $this->get_user_country();
		if (!$country) return;

		foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
			$product_id = $cart_item['product_id'];
			$country_price = get_post_meta($product_id, '_price_' . $country, true);

			if ($country_price) {
				$exchange_rates = get_option('woo_country_pricing_exchange_rates', []);
				$exchange_rate = isset($exchange_rates[$country]) ? floatval($exchange_rates[$country]) : 1;
				$adjusted_price = $country_price * $exchange_rate;

				// Adjust the item price in the cart object directly
				$cart_item['data']->set_price($adjusted_price);
			}
		}
	}

    // Adjust order item prices based on country
    public function adjust_order_item_prices($price, $item, $order) {
        $country = $this->get_user_country();
        if (!$country) return $price;

        $product_id = $item->get_product_id();
        $country_price = get_post_meta($product_id, '_price_' . $country, true);

        if ($country_price) {
            $exchange_rates = get_option('woo_country_pricing_exchange_rates', []);
            $exchange_rate = isset($exchange_rates[$country]) ? floatval($exchange_rates[$country]) : 1;
            return $country_price * $exchange_rate;
        }
        return $price;
    }
	
	 // Set currency based on selected or detected country
    public function set_currency_based_on_country($currency) {
        $country = $this->get_user_country();
        if (!$country) return $currency;

        $currency_map = get_option('woo_country_pricing_currency_map', '{}');
        $currency_data = json_decode($currency_map, true);

        if (isset($currency_data[$country])) {
            return $currency_data[$country];
        }
        return $currency;
    }

    // Shortcode to display country flags
    public function country_switcher_shortcode() {
         $countries_json = get_option('woo_country_pricing_exchange_rates', []);
        $countries = json_decode($countries_json, true);
        $output = '<div class="country-switcher">';
        foreach ($countries as $code => $flag) {
            $output .= '<span class="country-flag" data-country="' . esc_attr($code) . '">' . esc_html($flag) . '</span> ';
        }
        $output .= '</div>';
        $output .= '<script type="text/javascript">
            jQuery(document).ready(function($) {
                $(".country-flag").on("click", function() {
                    var country = $(this).data("country");
                    $.post("' . admin_url('admin-ajax.php') . '", {
                        action: "set_country",
                        country: country
                    }, function() {
                        location.reload();
                    });
                });
            });
        </script>';
        return $output;
    }

    // AJAX handler to set selected country in a cookie
    public function set_country() {
        if (isset($_POST['country'])) {
            setcookie('selected_country', sanitize_text_field($_POST['country']), time() + (86400 * 30), '/');
        }
        wp_die();
    }

    // Helper function to get user country using ipinfo.io API or selected country
    private function get_user_country() {
        if (isset($_COOKIE['selected_country'])) {
            return sanitize_text_field($_COOKIE['selected_country']);
        }

        $ipinfo_api_key = get_option('woo_country_pricing_ipinfo_api_key', '');
        if (empty($ipinfo_api_key)) return null;

        $ip = $_SERVER['REMOTE_ADDR'];
		//$ip = "34.21.9.50";
        $url = "https://ipinfo.io/{$ip}/country?token={$ipinfo_api_key}";
        $response = wp_remote_get($url);

        if (is_wp_error($response)) {
            return null;
        }
        $body = wp_remote_retrieve_body($response);
		setcookie('selected_country', sanitize_text_field($body), time() + (86400 * 30), '/');
        return trim($body);
    }
	public function output_country_specific_css() {
        $country = $this->get_user_country();
        if (!$country) return;

        $country_css = get_option('woo_country_pricing_country_css', '{}');
        $css_rules = json_decode($country_css, true);

        if (isset($css_rules[$country]) && $css_rules[$country]!="") {
            echo '<style type="text/css">' . esc_html($css_rules[$country]) . '</style>';
        }else{
			$country = "others_css";
			echo '<style type="text/css">' . esc_html($css_rules[$country]) . '</style>';
		}
    }
	
}

new Woo_Country_Switch_Pricing();
