<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * ActiveCampaign Integration
 *
 * Allows integration with ActiveCampaign
 *
 * @class 		ES_WC_Integration_ActiveCampaign
 * @extends		WC_Integration
 * @version		1.0
 * @package		WooCommerce ActiveCampaign
 * @author 		EqualServing
 */

class ES_WC_Integration_ActiveCampaign extends WC_Integration {

	protected $activecampaign_lists, $product_lists;

	/**
	 * Init and hook in the integration.
	 *
	 * @access public
	 * @return void
	 */

	public function __construct() {

		$this->id					= 'activecampaign';
		$this->method_title     	= __( 'ActiveCampaign', 'es_wc_activecampaign' );
		$this->method_description	= __( 'ActiveCampaign is a marketing automation service.', 'es_wc_activecampaign' );
		$this->error_msg            = '';
		$this->dependencies_found = 1;

		if ( !class_exists( 'ActiveCampaignES' ) ) {
			include_once( 'includes/ActiveCampaign.class.php' );
		}

		$this->activecampaign_url = "";
		$this->activecampaign_key  = "";
		$this->set_url();
		$this->set_key();

		$this->activecampaign_lists = array();
		$this->activecampaign_tag_lists = array();

		// Get setting values
		$this->enabled        = $this->get_option( 'enabled' );
		$this->get_ac_lists();

		// Load the settings
		$this->init_form_fields();
		$this->init_settings();

		$this->occurs         = $this->get_option( 'occurs' );
		$this->list           = $this->get_option( 'list' );
		$this->double_optin   = $this->get_option( 'double_optin' );
		$this->groups         = $this->get_option( 'groups' );
		$this->display_opt_in = $this->get_option( 'display_opt_in' );
		$this->opt_in_label   = $this->get_option( 'opt_in_label' );
		$this->tag_purchased_products	= $this->get_option( 'tag_purchased_products' );
		$this->purchased_product_tag_prefix = $this->get_option('purchased_product_tag_prefix');

		// Hooks
		add_action( 'admin_notices', array( &$this, 'checks' ) );
		add_action( 'woocommerce_update_options_integration', array( $this, 'process_admin_options') );

		// We would use the 'woocommerce_new_order' action but first name, last name and email address (order meta) is not yet available, 
		// so instead we use the 'woocommerce_checkout_update_order_meta' action hook which fires at the end of the checkout process after the order meta has been saved
		add_action( 'woocommerce_checkout_update_order_meta', array( &$this, 'order_status_changed' ), 10, 1 );

		// hook into woocommerce order status changed hook to handle the desired subscription event trigger
		add_action( 'woocommerce_order_status_changed', array( &$this, 'order_status_changed' ), 10, 3 );

		// Maybe add an "opt-in" field to the checkout
		add_filter( 'woocommerce_checkout_fields', array( &$this, 'maybe_add_checkout_fields' ) );

		// Maybe save the "opt-in" field on the checkout
		add_action( 'woocommerce_checkout_update_order_meta', array( &$this, 'maybe_save_checkout_fields' ) );

		// Display field value on the order edit page
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( &$this, 'checkout_field_display_admin_order_meta') );
	}

	/**
	 * Check if the user has enabled the plugin functionality, but hasn't provided an api key.
	 *
	 * @access public
	 * @return void
	 */

	public function checks() {
		global $pagenow;

		if ($pagenow == "admin.php" && isset($_REQUEST["page"]) && $_REQUEST["page"] == "wc-settings" && isset($_REQUEST["tab"]) && $_REQUEST["tab"] == "integration") {
			if ( $this->enabled == 'yes' ) {
				// Check required fields
				if(!$this->has_api_info()) { 
					if (!$this->has_key() && !$this->has_url()) {
						echo '<div class="error"><p>' . sprintf( __('ActiveCampaign error: Please enter your API URL and Key <a href="%s">here</a>', 'es_wc_activecampaign'), admin_url('options-general.php?page=activecampaign' ) ) . '</p></div>';
					} elseif (!$this->has_key() ) {
						echo '<div class="error"><p>' . sprintf( __('ActiveCampaign error: Please enter your API Key <a href="%s">here</a>', 'es_wc_activecampaign'), admin_url('options-general.php?page=activecampaign' ) ) . '</p></div>';
					} elseif (!$this->has_url()) {
						echo '<div class="error"><p>' . sprintf( __('ActiveCampaign error: Please enter your API Key <a href="%s">here</a>', 'es_wc_activecampaign'), admin_url('options-general.php?page=activecampaign' ) ) . '</p></div>';
					} 
					return;
				}
			}
			if ($this->error_msg) 
				echo '<div class="error">'.$this->error_msg.'</div>';
		}
	}

	/**
	 * order_status_changed function.
	 *
	 * @access public
	 * @return void
	 */

	public function order_status_changed( $id, $status = 'new', $new_status = 'pending' ) {
		if ( $this->is_valid() && $new_status == $this->occurs ) {
			$order = new WC_Order( $id );
			$item_details = $order->get_items();
			// If the 'es_wc_activecampaign_opt_in' meta value isn't set (because 'display_opt_in' wasn't enabled at the time the order was placed) or the 'es_wc_activecampaign_opt_in' is yes, subscribe the customer
			if ( ($this->display_opt_in == 'yes' && isset($_POST['es_wc_activecampaign_opt_in']) && $_POST['es_wc_activecampaign_opt_in']) || (isset($this->display_opt_in) && $this->display_opt_in == 'no' ) ) {
				$this->subscribe( $order->billing_first_name, $order->billing_last_name, $order->billing_email, $order->billing_address_1, $order->billing_address_2, $order->billing_city, $order->billing_state, $order->billing_postcode, $this->list, $item_details );
			}
		}
	}

	/**
	 * has_list function - have the ActiveCampaign lists been retrieved.
	 *
	 * @access public
	 * @return boolean
	 */

	public function has_list() {
		if ( $this->list )
			return true;
	}

	/**
	 * has_appid function - has the ActiveCampaign URL and Key been entered.
	 *
	 * @access public
	 * @return boolean
	 */

	public function has_api_info() {
		if ( $this->activecampaign_url && $this->activecampaign_key )
			return true;
	}

	/**
	 * has_appid function - has the ActiveCampaign URL been entered.
	 *
	 * @access public
	 * @return boolean
	 */

	public function has_url() {
		if ( $this->activecampaign_url )
			return true;
	}

	/**
	 * set_url function - set the ActiveCampaign URL property.
	 *
	 * @access public
	 * @return void
	 */

	public function set_url() {

		if (get_option("woocommerce_activecampaign_settings")) {
			$ac_settings = get_option("woocommerce_activecampaign_settings");
			$this->activecampaign_url = $ac_settings["activecampaign_url"];
		}
	}

	/**
	 * has_key function - has the ActiveCampaign Key been entered.
	 *
	 * @access public
	 * @return boolean
	 */

	public function has_key() {
		if ( $this->activecampaign_key )
			return true;
	}

	/**
	 * set_key function - set the ActiveCampaign Key property.
	 *
	 * @access public
	 * @return void
	 */

	public function set_key() {

		if (get_option("woocommerce_activecampaign_settings")) {
			$ac_settings = get_option("woocommerce_activecampaign_settings");
			$this->activecampaign_key = $ac_settings["activecampaign_key"];
		}
	}

	/**
	 * is_valid function - is ActiveCampaign ready to accept information from the site.
	 *
	 * @access public
	 * @return boolean
	 */

	public function is_valid() {
		if ( $this->enabled == 'yes' && $this->has_api_info() && $this->has_list() ) {
			return true;
		}
		return false;
	}

	/**
	 * Initialize Settings Form Fields
	 *
	 * @access public
	 * @return void
	 */

	public function init_form_fields() {
		if ( is_admin() ) {
			if ($this->has_api_info()) {
				array_merge( array( '' => __('Select a list...', 'es_wc_activecampaign' ) ), $this->activecampaign_lists );
			} else {
				array( '' => __( 'Enter your key and save to see your lists', 'es_wc_activecampaign' ) );
			}
			if (get_option("woocommerce_activecampaign_settings")) {
				$ac_settings = get_option("woocommerce_activecampaign_settings");
				if ($ac_settings) {
					$default_ac_url = $ac_settings["activecampaign_url"];
					$default_ac_key = $ac_settings["activecampaign_key"];
				} 

			// If ActiveCampaign's plugin is installed and configured, collect the URL and Key from their their plugin for the inital default values.  
			} else if (get_option("settings_activecampaign")) {
				$ac_settings = get_option("settings_activecampaign");
				if ($ac_settings) {
					$default_ac_url = $ac_settings["api_url"];
					$default_ac_key = $ac_settings["api_key"];
				} 
			} else {
				$default_ac_url = "";
				$default_ac_key = "";
			}
			$list_help = 'All customers will be added to this list.';
			if (empty($this->activecampaign_lists)) {
				$list_help .= '<br /><strong>NOTE: If this dowpdown list is empty AND you have entered the API URL and Key correctly, please save your settings and reload the page. <a href="'.$_SERVER['REQUEST_URI'].'">[Click Here]</a></strong>';
			}

			$this->form_fields = array(
				'enabled' => array(
								'title' => __( 'Enable/Disable', 'es_wc_activecampaign' ),
								'label' => __( 'Enable ActiveCampaign', 'es_wc_activecampaign' ),
								'type' => 'checkbox',
								'description' => '',
								'default' => 'no',
							),
				'activecampaign_url' => array(
								'title' => __( 'ActiveCampaign API URL', 'es_wc_activecampaign' ),
								'type' => 'text',
								'description' => __( '<a href="http://www.activecampaign.com/help/using-the-api/" target="_blank">Login to activecampaign</a> to look up your api url.', 'es_wc_activecampaign' ),
								'default' => $default_ac_url
							),
				'activecampaign_key' => array(
								'title' => __( 'ActiveCampaign API Key', 'es_wc_activecampaign' ),
								'type' => 'text',
								'description' => __( '<a href="http://www.activecampaign.com/help/using-the-api/" target="_blank">Login to activecampaign</a> to look up your api key.', 'es_wc_activecampaign' ),
								'default' => $default_ac_key
							),
				'occurs' => array(
								'title' => __( 'Subscribe Event', 'es_wc_activecampaign' ),
								'type' => 'select',
								'description' => __( 'When should customers be subscribed to lists?', 'es_wc_activecampaign' ),
								'default' => 'pending',
								'options' => array(
									'pending' => __( 'Order Created', 'es_wc_activecampaign' ),
									'processing' => __( 'Order Processing', 'es_wc_activecampaign' ),
									'completed'  => __( 'Order Completed', 'es_wc_activecampaign' ),
								),
							),
				'list' => array(
								'title' => __( 'Main List', 'es_wc_activecampaign' ),
								'type' => 'select',
								'description' => __( $list_help, 'es_wc_activecampaign' ),
								'default' => '',
								'options' => $this->activecampaign_lists,
							),
				'display_opt_in' => array(
								'title'       => __( 'Display Opt-In Field', 'es_wc_activecampaign' ),
								'label'       => __( 'Display an Opt-In Field on Checkout', 'es_wc_activecampaign' ),
								'type'        => 'checkbox',
								'description' => __( 'If enabled, customers will be presented with a "Opt-in" checkbox during checkout and will only be added to the <strong>Main List</strong> above if they opt-in.', 'es_wc_activecampaign' ),
								'default'     => 'no',
							),
				'opt_in_label' => array(
								'title'       => __( 'Opt-In Field Label', 'es_wc_activecampaign' ),
								'type'        => 'text',
								'description' => __( 'Optional: customize the label displayed next to the opt-in checkbox.', 'es_wc_activecampaign' ),
								'default'     => __( 'Add me to the newsletter (we will never share your email).', 'es_wc_activecampaign' ),
							),
				'tag_purchased_products' => array(
								'title'       => __( 'Tag Products Purchased', 'es_wc_activecampaign' ),
								'label'       => __( 'Tag all products purchased via Woocommerce.', 'es_wc_activecampaign' ),
								'type'        => 'checkbox',
								'description' => __( 'If enabled, all customers added to ActiveCampaign because they made a purchase via Woocommerce will be tagged with product ids.', 'es_wc_activecampaign' ),
								'default'     => 'no',
							),
				'purchased_product_tag_prefix' => array(
								'title'       => __( 'Purchased Product Tag Prefix', 'es_wc_activecampaign' ),
								'type'        => 'text',
								'description' => __( 'Tag contacts with the product id prefixed with this string. By default the string will be \'Purchased Product ##\'', 'es_wc_activecampaign' ),
								'default'     => __( 'Purchased Product', 'es_wc_activecampaign' ),
								'desc_tip'    => __( 'If Tag Products Purchased is enabled, customers added to ActiveCampaign via WooCommerce will be tagged with this prefix and the product id of all products purchased. By default this tag will be \'Purchased Product ##\'.', 'es_wc_activecampaign'),
							),
			);
		}
	} // End init_form_fields()

	/**
	 * get_ac_lists function - retrieve the active lists created in ActiveCampaign.
	 *
	 * @access public
	 * @return void
	 */

	public function get_ac_lists() {
		if ( is_admin() && $this->has_api_info() ) {
			if ( ! get_transient( 'es_wc_activecampaign_list_' . md5( $this->activecampaign_key ) ) ) {

				$this->activecampaign_lists = array();

				$api = new ActiveCampaign($this->activecampaign_url, $this->activecampaign_key);

				try {

					$retval = $api->api("list/list_", array("ids" => "all"));

					if ($retval && is_object($retval)) {
						foreach ( $retval as $list ) {
							if (is_object($list)) {
								$this->activecampaign_lists["es|".$list->id ] = $list->name;
							}
						}
					}
				
					if ( sizeof( $this->activecampaign_lists ) > 0 )
						set_transient( 'es_wc_activecampaign_list_' . md5( $this->activecampaign_key ), $this->activecampaign_lists, 60*60*1 );
				
				} catch (Exception $oException) { // Catch any exceptions
		       		if ($api->error_msg) {
						$errors = $api->error_msg;
						foreach ($errors->errors as $error) {
							$error_msg .= $error;
						}

						// Email admin
						$error_msg = '<p><strong>' . sprintf( __( 'Unable retrieve lists from ActiveCampaign: %s', 'es_wc_activecampaign' ), $error_msg ) . '</strong></p>';

						wp_mail( get_option('admin_email'), __( 'Retrieve lists failed (ActiveCampaign)', 'es_wc_activecampaign' ), ' ' . $errors );
					}
				}
			} else {
				$this->activecampaign_lists = get_transient( 'es_wc_activecampaign_list_' . md5( $this->activecampaign_key ));
			}
		}
	}

	/**
	 * subscribe function - if enabled, customer will be subscribed to selected list, if the 
	 *                      option to tag the customer with products purchased, that too will
	 *                      be done.
	 *
	 * @access public
	 * @param mixed $first_name
	 * @param mixed $last_name
	 * @param mixed $email
	 * @param mixed $address_1
	 * @param mixed $address_2
	 * @param mixed $city
	 * @param mixed $state
	 * @param mixed $zip
	 * @param string $listid (default: 'false')
	 * @param object $items 
	 * @return void
	 */

	public function subscribe( $first_name, $last_name, $email, $address_1 = null, $address_2 = null, $city = null, $state = null, $zip = null, $listid = false, $items ) {

		if($this->has_api_info()) { 

			if ( $listid == false )
				$listid = $this->list;
				
			if ( !$email || !$listid || !$this->enabled ) 
				return; // Email and listid is required
			
			$api = new ActiveCampaign($this->activecampaign_url, $this->activecampaign_key);

			try {

				$post = array(
				    'email'				=> $email,
				    'first_name'		=> $first_name,
				    'last_name'			=> $last_name
				    );

				$retval = $api->api("contact/sync", $post);
				if ( $retval->success == 1 ) {

					if (isset($listid) && $listid != "") {
						$listid = ltrim($listid, "es|");
		
						$contact = array(
							"email" => $email,
							"p[{$listid}]" => $listid,
							"status[{$listid}]" => 1, // "Active" status
						);

						$retval = $api->api("contact/sync", $contact);
					}

					if ( $this->tag_purchased_products == 'yes' ) {
						if ( !empty($items) ) {

							foreach ( $items as $item ) {
							    $purchased_product_id = $item['product_id'];
							    if ($item['variation_id']) {
							    	$tag = $this->purchased_product_tag_prefix." ".$item['product_id']." / ".$item['variation_id'];
							    } else {
							    	$tag = $this->purchased_product_tag_prefix." ".$item['product_id'];
							    }
								$contact = array(
									"email" => $email,
									"tags" => $tag,
								);
								$retval = $api->api("contact/tag/add", $contact);
							}
						}
					}
				}

			} catch (Exception $oException) { // Catch any exceptions
	       		if ($api->error_msg) {
					$errors = $api->error_msg;
					foreach ($errors->errors as $error) {
						$error_msg .= $error;
					}

					// Email admin

					$error_msg = '<p><strong>' . sprintf( __( 'Unable to subscribe a new contact into ActiveCampaign: %s', 'es_wc_activecampaign' ), $error_msg ) . '</strong></p>';

					wp_mail( get_option('admin_email'), __( 'Email subscription failed (ActiveCampaign)', 'es_wc_activecampaign' ), ' ' . $errors);

				}
			}
		}
	}

	/**
	 * Admin Panel Options
	 */

	function admin_options() {
		echo '<h3>';
		_e( 'ActiveCampaign', 'es_wc_activecampaign' );
		echo '</h3>';
		if ($this->dependencies_found) {
			echo '<p>';
			_e( 'Enter your ActiveCampaign settings below to control how WooCommerce integrates with your ActiveCampaign lists.', 'es_wc_activecampaign' );
			echo '</p>';
    		echo '<table class="form-table">';
	    	$this->generate_settings_html();
			echo '</table><!--/.form-table-->';
		} else {
			echo "<p>".$this->error_msg."</p>";
		}
	}

	/**
	 * opt-in function - Add the opt-in checkbox to the checkout fields (to be displayed on checkout).
	 *
	 * @access public
	 * @param mixed $checkout_fields
	 * @return mixed
	 */

	function maybe_add_checkout_fields( $checkout_fields ) {

		if ($this->enabled == 'yes') {
			if ( 'yes' == $this->display_opt_in ) {
				$checkout_fields['order']['es_wc_activecampaign_opt_in'] = array(
					'type'    => 'checkbox',
					'label'   => esc_attr( $this->opt_in_label ),
					'default' => true,
				);
			}
		}
		return $checkout_fields;
	}

	/**
	 * save opt-in function - When the checkout form is submitted, save opt-in value.
	 *
	 * @access public
	 * @param integer $order_id
	 * @return void
	 */

	function maybe_save_checkout_fields( $order_id ) {
		//if ( 'yes' == $this->display_opt_in ) {
			$opt_in = isset( $_POST['es_wc_activecampaign_opt_in'] ) ? 'yes' : 'no';
			//error_log("maybe_save_checkout_fields() :: opt_in = ".$opt_in, 1, "michele.durst@gmail.com");
			update_post_meta( $order_id, 'es_wc_activecampaign_opt_in', $opt_in );
		//}
	}

	/**
	 * display opt-in function - Display the opt-in value on the order details.
	 *
	 * @access public
	 * @param mixed $order
	 * @return void
	 */

	function checkout_field_display_admin_order_meta($order){
    	echo '<p><strong>'.__('ActiveCampaign Subscribe Opt In').':</strong> <br/>' . get_post_meta( $order->id, 'es_wc_activecampaign_opt_in', true ) . '</p>';
	}
}
