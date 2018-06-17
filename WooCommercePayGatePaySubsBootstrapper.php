<?php
/*
 * Plugin Name: WooCommerce PayGate PaySubs
 * Plugin URI: https://github.com/centricomen/woocommerce-paygate-paysubs
 * Description: Make payments on your site using PayGate's PaySubs service.
 * Author: Thokozani Mhlongo
 * Author URI: http://codexperience.co.za/
 * Version: 1.0
 * Text Domain: woocommerce-paygate-paysubs
 * Domain Path: /languages
 *
 * Copyright (c) 2016
 */

	if ( ! defined ( 'ABSPATH' ) )
		exit;
	
	define ( 'WC_PAYGATE_PLUGIN_URL', untrailingslashit ( 
										plugins_url ( 
											basename ( plugin_dir_path ( __FILE__ ) ), 
											basename ( __FILE__ ) 
										) 
									) );
									
	define ( 'WC_PAYGATE_PLUGIN_PATH', plugin_dir_path ( __FILE__ ) );
	
	# set the include path
	ini_set( 'include_path', WC_PAYGATE_PLUGIN_PATH . '/' );
	
	add_action( 'plugins_loaded', function() {
		
		add_filter( 'woocommerce_payment_gateways', function( $methods ) {
			if( ! class_exists( 'WooCommercePayGatePaySubs' ) )
				include 'gateway/WooCommercePayGatePaySubs.php';
			
			$methods[] = 'WooCommercePayGatePaySubs';
			
			return $methods;
		});
	});
	
	
