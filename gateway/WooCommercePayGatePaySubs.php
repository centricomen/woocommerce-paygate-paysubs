<?php


	class WooCommercePayGatePaySubs extends WC_Payment_Gateway_CC {

		public $testmodeEnabled;

		public $paygateId;

		public $paysubsApiVersion;

		public $paygatePassword;

        static $enableLogs;

		# Failed transaction codes
		public static $TRANSACTION_CANCELLED			= 990028;
		public static $TRANSACTION_MUST_BE_RESTARTED	= 900210;
		public static $TRANSACTION_FAILED				= 900209;
		public static $TRANSACTION_BANK_UNAVAILABLE		= 990022;
		public static $TRANSACTION_BLACKLISTED			= 900015;
		public static $TRANSACTION_INSUFFICIENT_FUNDS	= 900003;
		public static $TRANSACTION_BANK_TIMEDOUT		= 900005;
		public static $TRANSACTION_DECLINED				= 900007;
		public static $TRANSACTION_INVALID_EXPIRY		= 991001;

		# success transaction codes
		public static $TRANSACTION_SUCCESSFUL			= 990017;

		public function __construct ( ) {

			$this -> id                   	= 'paygate_paysubs';
			$this -> method_title         	= __( 'PayGate PaySubs', 'woocommerce-paygate-paysubs' );
			$this -> method_description   	= __( 'Makes Visa / MasterCard credit card payments via PayGate.', 'woocommerce-paygate-paysubs' );
			$this -> title					= __( 'Credit Card | PayGate Subscriptions', 'woocommerce-paygate-paysubs' );
			$this -> has_fields           	= true;
			$this -> paysubsApiVersion		= 21;

			$this -> supports             	= array (
				'subscriptions',
				'products',
				'refunds',
				'default_credit_card_form',
				'subscription_cancellation',
				'subscription_reactivation',
				'subscription_suspension',
				'subscription_amount_changes',
				'subscription_payment_method_change_customer',
				'subscription_payment_method_change_admin',
				'subscription_date_changes',
				'multiple_subscriptions',
				'pre-orders',
				'tokenization'
			);

			# Load the form fields.
			$this -> init_form_fields ( );	/** @see inherited classes **/

			# Load the settings.
			$this -> init_settings ( );		/** @see inherited classes **/

			$this -> title                  = $this -> get_option ( 'title' );
			$this -> description            = $this -> get_option ( 'description' );
			$this -> enabled                = $this -> get_option ( 'enabled' );
            self::$enableLogs               = 'yes' === $this -> get_option ( 'logs' );
			$this -> testmodeEnabled        = 'yes' === $this -> get_option ( 'testmode' );
			$this -> paygateId				= ( $this -> testmodeEnabled ) ? '10011072130'	: $this -> get_option ( 'paygate_id' ); // Test-mode id
			# $this -> paygateId				= ( $this -> testmodeEnabled ) ? '10011064270'	: $this -> get_option ( 'paygate_id' ); // Test-mode id
			$this -> paygatePassword		= ( $this -> testmodeEnabled ) ? 'secret' 		: $this -> get_option ( 'paygate_password' ); // Test-mode password

			# Save settings
			add_action( 'woocommerce_update_options_payment_gateways_' . $this -> id, array ( $this, 'process_admin_options' ) );

			# Adding scripts for the card
			add_action( 'wp_enqueue_scripts', array( &$this, 'paymentCardScripts' ) );

			# add function to redirect to PayGate and a response "action"
			add_action( 'woocommerce_receipt_' . $this -> id, array( &$this, 'receiptPage' ) );
			add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'checkPaymentResponse' ) );

			# adding subscriptions SCHEDULED / AUTOMATIC payments
			if ( class_exists( 'WC_Subscriptions_Order' ) ) {
				add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'processScheduledPayment' ), 10, 2 );
				# add_action( 'wcs_resubscribe_order_created', array( $this, 'delete_resubscribe_meta' ), 10 );
				add_action( 'wcs_renewal_order_created', array( $this, 'reactivatedSubscription' ), 10 );
				/*add_action( 'woocommerce_subscription_failing_payment_method_updated_stripe', array( $this, 'update_failing_payment_method' ), 10, 2 );*/

				// display the credit card used for a subscription in the "My Subscriptions" table
				//add_filter( 'woocommerce_my_subscriptions_payment_method', array( $this, 'maybe_render_subscription_payment_method' ), 10, 2 );

				// allow store managers to manually set Stripe as the payment method on a subscription
				//add_filter( 'woocommerce_subscription_payment_meta', array( $this, 'add_subscription_payment_meta' ), 10, 2 );
				//add_filter( 'woocommerce_subscription_validate_payment_meta', array( $this, 'validate_subscription_payment_meta' ), 10, 2 );
			}
		}

		/**
		 * Creates a settings panel for the payment gateway in the backend
		 * @see inherited class
		 */
		public function init_form_fields ( ) {

			$this -> form_fields = array (
				'enabled' 		  => array (
					'title'       => __( 'Enable/Disable', 'woocommerce' ),
					'label'       => __( 'Enable Payment Gateway', 'woocommerce' ),
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'no'
				),
				'title' 		  => array (
					'title'       => __( 'Title', 'woocommerce' ),
					'type'        => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
					'default'     => __( 'Credit Card | PayGate', 'woocommerce' ),
					'desc_tip'    => true,
				),
				'description' 	  => array (
					'title'       => __( 'Description', 'woocommerce' ),
					'type'        => 'text',
					'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
					'default'     => __( 'Pay with your credit card via PayGate.', 'woocommerce'),
					'desc_tip'    => true,
				),
				'testmode' 		  => array (
					'title'       => __( 'Test mode', 'woocommerce' ),
					'label'       => __( 'Enable Test Mode', 'woocommerce' ),
					'type'        => 'checkbox',
					'description' => __( 'Place the payment gateway in test mode.', 'woocommerce' ),
					'default'     => 'yes',
					'desc_tip'    => true,
				),
				'paygate_id' 	  => array (
					'title'       => __( 'PayGate ID (Live mode)', 'woocommerce' ),
					'type'        => 'text',
					'description' => __( 'Get your API keys from your stripe account.', 'woocommerce' ),
					'default'     => '',
					'desc_tip'    => true,
				),
				'paygate_password' => array (
					'title'       => __( 'PayGate Password (Live mode)', 'woocommerce' ),
					'type'        => 'text',
					'description' => __( 'Get your API keys from your stripe account.', 'woocommerce' ),
					'default'     => '',
					'desc_tip'    => true,
				),
                'logs' => array (
                    'label'       => __( 'Enable Transaction Logging.<br><br><i>Logs can be found here: <code>' . str_replace( '\\', '/', WC_PAYGATE_PLUGIN_PATH ) . 'gateway/logs</code></i>' ),
                    'title'       => __( 'Logging' ),
                    'type'        => 'checkbox',
                    'default'     => 'yes',
                    'desc_tip'    => false,
                )
			);

		}


		/**
		 * This function processes the a scheduled payment.
		 */

		public function processScheduledPayment( $amountToPay, $order ) {
			ob_start();
			print_r( $order );
			$content	= ob_get_contents();
			ob_end_clean();

			# let's get the saved credit card information
			if( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
				$parentOrderId 	= WC_Subscriptions_Renewal_Order::get_parent_order_id( $order -> id );
				$parentOrder	= wc_get_order( $parentOrderId );

				$subscriptions	= wcs_get_subscriptions_for_order( $parentOrder, array('order_type' => 'parent') );
				$keys			= array_keys( $subscriptions );


				# TODO 2: Get the payload. Use the parent order because that's where the PaySubs data is stored
				$payload		= $this -> getSoapPayload( $parentOrder );

				ob_start();
				print_r( $payload );
				$payloaddump		= ob_get_contents();
				ob_end_clean();

				# TODO 3: Do the checks for this subscription
				$this -> doPaySubsSubscriptionStatusCheck( $parentOrder, $order, $payload );

				self::log( $order -> id, $payloaddump );
			}

			self::log( $order -> id, $content );
		}

		public function reactivatedSubscription( $order ) {
			ob_start();
			print_r( $order );
			$content	= ob_get_contents();
			ob_end_clean();

			self::log( $order -> id, "Activated Order\n" );
			self::log( $order -> id, $content );

            $this -> processScheduledPayment( 0, $order );
		}

		/**
		 * This function checks the status of this subscription
		 */
		public function doPaySubsSubscriptionStatusCheck( $parentOrder, $order, $payload ) {
			$transactionRef 	= get_post_meta( $parentOrder -> id, 'transaction_ref', true );
			if( empty( $transactionRef ) ) {
				# try to get the id from the data
				$transactionData 		= get_post_meta(  $order -> id, 'transaction_data', true );
				if( ! is_array( $transactionData ) )
					$transactionData	= unserialize( $transactionData );

				$transactionRef 	= $transactionData[ 'REFERENCE' ];
			}

			$soapClient = new SoapClient( 'https://secure.paygate.co.za/PayHost/process.trans?wsdl',
										  array ( 'trace' => 1 )
										); # point to WSDL and set trace value to debug
			try {
				self::log( $order -> id, "-------- Beginning query. PaySubs transaction Ref: {$transactionRef} (Order: #{$parentOrder -> id}) -------------\n" );  # Important
				self::log( $order -> id, "Send payload to PayGate\n" );

				# Sending request
				$result = $soapClient -> __soapCall( 'SingleFollowUp', array (
					new SoapVar( $payload, XSD_ANYXML )
				) );

				# TODO 1: Log the result data
				ob_start();
				print_r( $result );
				$content	= ob_get_contents();
				ob_end_clean();

				# record the payload
                self::log( $order -> id, "\n[----- PAYLOAD -----]\n" );

                ob_start();
                print_r( $payload );
                $payloadData	= ob_get_contents();
                ob_end_clean();

                self::log( $order -> id, "\n{$payloadData}\n" ); # Important
                self::log( $order -> id, "\n[----- PAYLOAD END -----]\n" );

				self::log( $order -> id, $content );
				self::log( $order -> id, "\nResult received\n" );

				# TODO 2: Check the result data and update the order and subscription accordingly.
				# get them by date range (a week )

                if( is_array( $result -> QueryResponse ) && count( $result -> QueryResponse ) > 0 ) {

                    # $subscription = wcs_get_subscriptions_for_order( $order );

                    $mostRecentTransaction = $result -> QueryResponse[0];
                    /*if( strtolower( $mostRecentTransaction -> Status -> TransactionStatusDescription ) == 'approved' ) {
                    } else {
                        # the transaction did not go through. De-activate the membership
                    }*/

                    # serialize the response for order metedata
                    $orderMetadata  = json_decode( json_encode( $mostRecentTransaction -> Status ), true );

                    switch( $mostRecentTransaction -> Status -> ResultCode ) {
                        case self::$TRANSACTION_CANCELLED:
                        case self::$TRANSACTION_FAILED:
                        case self::$TRANSACTION_BANK_UNAVAILABLE:
                        case self::$TRANSACTION_DECLINED:
                        case self::$TRANSACTION_BANK_TIMEDOUT:
                        case self::$TRANSACTION_BLACKLISTED:
                        case self::$TRANSACTION_INSUFFICIENT_FUNDS:
                        case self::$TRANSACTION_INVALID_EXPIRY:
                            # transaction was unsuccessful. try again page
                            $order -> update_status( 'failed', 'PayGate: ' . $mostRecentTransaction -> Status -> ResultDescription );
                            die();
                            break;
                        case self::$TRANSACTION_SUCCESSFUL:

                            $order -> update_status( 'processing', 'PayGate: ' . $mostRecentTransaction -> Status -> ResultDescription );
                            update_post_meta( $order -> id, 'transaction_ref', $transactionRef );
                            update_post_meta( $order -> id, 'transaction_id', $mostRecentTransaction -> Status -> TransactionId );
                            update_post_meta( $order -> id, 'transaction_data', serialize( $orderMetadata ) );
                            //header( 'Location: ' . $order -> get_checkout_order_received_url() );
                            die();
                            break;
                        default:
                            $order -> update_status( 'pending', 'PayGate: ' . $mostRecentTransaction -> Status -> ResultDescription );
                            die();
                            break;
                    }
                }

				/*if( is_array( $result -> QueryResponse -> Status ) ) {
					foreach( $result -> QueryResponse -> Status as $transaction ) {
					    if( $transaction -> TransactionId == $transactionRef ) {
					        # check the TransactionStatusDescription. If its Approved then subscription is active

					        break;
                        }
					}
				} else {

				}
				if( $result -> SingleFollowUpResponse -> QueryResponse -> Status -> TransactionStatusCode == 1 ) {

				}*/

			}  catch ( SoapFault $sf ) {
				# Log error and do not redirect the page. Show error on Checkout instead
				self::log( $order -> id, "SoapFault: [ {$sf -> getMessage()} ]\n" );
				self::log( $order -> id, "SoapFault: [ {$sf -> getTraceAsString()} ]\n" );
				# self::log( $orderId, "Payload : [ {$payload} ]" );
			}
		}


		/**
		 * This is the response method that takes care of the response from PayGate
		 */
		public function checkPaymentResponse() {

			$key		= $_REQUEST[ 'order' ];
			$orderId	= wc_get_order_id_by_order_key( $key );

			if ( $orderId != '' ) {
				$order	= wc_get_order( $orderId );

				$resultCode	= $_POST[ 'RESULT_CODE' ];
				switch( $resultCode ) {
					case self::$TRANSACTION_CANCELLED:
					case self::$TRANSACTION_FAILED:
					case self::$TRANSACTION_BANK_UNAVAILABLE:
					case self::$TRANSACTION_DECLINED:
					case self::$TRANSACTION_BANK_TIMEDOUT:
					case self::$TRANSACTION_BLACKLISTED:
					case self::$TRANSACTION_INSUFFICIENT_FUNDS:
					case self::$TRANSACTION_INVALID_EXPIRY:
						# transaction was unsuccessful. try again page
						$order -> update_status( 'failed', 'PayGate: ' . $_POST[ 'RESULT_DESC' ] );
						header( 'Location: ' . $order -> get_checkout_order_received_url() );
						die();
						break;
					case self::$TRANSACTION_SUCCESSFUL:
						$order -> update_status( 'processing', 'PayGate: ' . $_POST[ 'RESULT_DESC' ] );
						update_post_meta( $order -> id, 'transaction_ref', $_POST[ 'REFERENCE' ] );
                        update_post_meta( $order -> id, 'transaction_id', $_POST[ 'TRANSACTION_ID' ] );
						update_post_meta( $order -> id, 'transaction_data', serialize( $_POST ) );
						header( 'Location: ' . $order -> get_checkout_order_received_url() );
						die();
						break;
					default:
						header( 'Location: ' . $order -> get_checkout_order_received_url() );
						die();
						break;
				}
				/* */

			}

			die();


		}

		public function receiptPage( $orderId ) {

			$order				= new WC_Order( $orderId );
			$returnUrl			= rtrim( home_url( '/' ), '/' ) . '/wc-api/' . get_class( $this ) . '?order=' . $order -> order_key;
			$transactionDate	= date( 'Y-m-d h:i', strtotime( $order -> order_date ) );

			# get subscriptions
			$subscriptions		= wcs_get_subscriptions_for_order( $orderId );

			# get subscription period
			$subscriptionIds	= array_keys( $subscriptions );
			$reference			= $order -> id; # order key - subscription id
			$subStartDate		= date( 'Y-m-d', strtotime( $subscriptions[ $subscriptionIds[0] ] -> get_date( 'start' ) ) );
			$subEndDate			= $subscriptions[ $subscriptionIds[0] ] -> get_date( 'end' );
			$subEndDate			= ( $subEndDate == 0 || $subEndDate == '0' ) ? date( 'Y-m-d', strtotime( $subStartDate . '00:00:00 + 10 years' ) ) : date( 'Y-m-d', strtotime( $subEndDate ) );

			$amount				= number_format( $order -> order_total, 2, '', '' );
			$processNow			= 'YES';
			$currency			= 'ZAR';
			$subsFrequency		= '229'; # TODO [CHANGES]: change this to 229
			$processNowAmount	= $amount; # leave blank if PROCESS_NOW IS NO

			$checksumElements	= array (
				$this -> paysubsApiVersion, # VERSION
				$this -> paygateId, # PAYGATE_ID
				$reference, # REFERENCE
				$amount, # AMOUNT
				$currency, # CURRENCY
				$returnUrl, # RETURN_URL
				$transactionDate, # TRANSACTION_DATE
				$subStartDate, # SUBS_START_DATE
				$subEndDate, # SUBS_END_DATE
				$subsFrequency, # SUBS_FREQUENCY
				$processNow, # PROCESS_NOW
				$processNowAmount, # PROCESS_NOW_AMOUNT
				$this -> paygatePassword # APPEND THE PASSWORD
			);

			$checksum	= md5( implode( '|', $checksumElements ) );

			if( isset( $_GET[ 'request' ] ) ) {
				?>
					<style type="text/css">
						.order_details {
							/*visibility: hidden;
							margin-bottom: 0 !important;*/
                            margin-top: 0 !important;
						}

                        #paygate-dialog-cover {
                            background: #00000099;
                            position: fixed;
                            left: 0;
                            top: 0;
                            width: 100%;
                            height: 100%;
                            z-index: 100;
                        }

                        #paygate-dialog {
                            background: white;
                            display: none;
                            position: fixed;
                            text-align: center;
                            z-index: 101;
                            max-width: 90%;
                            max-height: 90%;
                            border-radius: 0 !important;
                            padding: 1em;
                        }

                        #paygate-dialog img {
                            display: inline-block;
                        }

                        #paygate-dialog img.logo {
                            width: 50%;
                        }
					</style>

                    <!-- Dialog -->
                    <div id="paygate-dialog-cover">
                        <div id="paygate-dialog">
                            <img class="logo" src="<?php echo WC_PAYGATE_PLUGIN_URL . '/assets/images/paygate.png' ?>" />

                            <br>

                            <img width="40" style="margin-top: -30px" src="<?php echo WC_PAYGATE_PLUGIN_URL . '/assets/images/dual-ring-loader.gif' ?>" />

                            <br style="clear:both;"><br>

                            <h1 class="mh_h2_heading my_orange">Redirecting...</h1>
                            <p>
                                You are now being redirected to PayGate in order to finish your transaction. <br>
                                This might take a moment</p>
                        </div>
                    </div>
                    <!-- Dialog End -->


					<div class="row">
						<div class="col-xs-12">
							<h1 class="mh_h2_heading my_orange">Subscription Payment</h1>
							<p>
								You are now being redirected to PayGate in order to finish your transaction. <br>
								This might take a moment. If it is taking too long the use the button below<br><br>

								<form id="<?php echo $this -> id ?>" action="https://www.paygate.co.za/paysubs/process.trans" method="POST" >
									<input type="hidden" name="VERSION" value="<?php echo $this -> paysubsApiVersion ?>">
									<input type="hidden" name="PAYGATE_ID" value="<?php echo $this -> paygateId ?>">
									<input type="hidden" name="REFERENCE" value="<?php echo $reference ?>">
									<input type="hidden" name="AMOUNT" value="<?php echo $amount ?>">
									<input type="hidden" name="CURRENCY" value="<?php echo $currency ?>">
									<input type="hidden" name="RETURN_URL" value="<?php echo $returnUrl ?>">
									<input type="hidden" name="TRANSACTION_DATE" value="<?php echo $transactionDate ?>">
									<input type="hidden" name="SUBS_START_DATE" value="<?php echo $subStartDate ?>">
									<input type="hidden" name="SUBS_END_DATE" value="<?php echo $subEndDate ?>">
									<input type="hidden" name="SUBS_FREQUENCY" value="<?php echo $subsFrequency ?>">
									<input type="hidden" name="PROCESS_NOW" value="<?php echo $processNow ?>">
									<input type="hidden" name="PROCESS_NOW_AMOUNT" value="<?php echo $processNowAmount ?>">
									<input type="hidden" name="CHECKSUM" value="<?php echo $checksum ?>">
									<input type="submit" class="button paygate-redirect-btn" value="GO TO PAYMENT">
								</form>
							</p>
						</div>
					</div>

					<script type="text/javascript">
						jQuery( function( $ ) {

                            $( window ).on( 'load resize', function() {
                                var windowWidth     = document.documentElement.clientWidth;
                                var windowHeight    = document.documentElement.clientHeight;
                                var dialogWidth     = $( '#paygate-dialog' ).width();
                                var dialogHeight    = $( '#paygate-dialog' ).height();
                                var dailogPaddingLeft = parseInt( $( '#paygate-dialog' ).css( 'padding-left' ) );

                                var top     = ((windowHeight / 2) - (dialogHeight / 2));
                                var left    = ((windowWidth / 2) - (dialogWidth / 2)) - dailogPaddingLeft;

                                $( '#paygate-dialog' ).css({
                                    left    : left,
                                    top     : top
                                }).show();
                            });

                            setTimeout( function() {
                                $( '#<?php echo $this -> id ?>' ).submit();
                            }, 200 );

						});
					</script>
				<?php

				//self::log( $orderId, "-------- Ending payment transaction for order #{$orderId}: -------------\n\n" );

				//self::log( $orderId, $init );

				die(); # end the string
			}

		}

		/**
		 * This function is called whenever a payment is being made
		 * @see inherited class
		 */
		public function process_payment ( $orderId, $retry = true, $forceCustomer = false ) {

			$order = new WC_Order( $orderId );

			return array(
				'result'   => 'success',
				'redirect' => $order -> get_checkout_payment_url( true ) . '&request=true'
			);

		}

		/**
		 * This functions displays the credit card form in the front-end
		 * @see inherited class
		 */
		public function payment_fields ( ) {

			?>

			<div class="paygate-PaySubs-container">
				<!--div class="card-wrapper"></div>-->

		        <div class="form-container active">
		            <div class="row">
		            	<p class="col-xs-12">
		            	<?php

                            if( ! empty( $this -> description ) )
                                echo $this -> description;
                            else echo 'Pay with your credit card using PayGate secure payment gateway.';

		            		if( $this -> testmodeEnabled ) {
		            			?>
                                <br><b>TEST MODE ENABLED.</b><br>Subscription payment credit card details are handled by PayGate.
		            			<?php
		            		}
		            	?>
		            	</p>

                        <br style="clear:both">
		                <div class="col-xs-12 badge-row">
		                	<img src="<?php echo WC_PAYGATE_PLUGIN_URL . '/assets/images/visa.svg' ?>" width="32" alt="Visa" />
		                	<img src="<?php echo WC_PAYGATE_PLUGIN_URL . '/assets/images/mastercard.svg' ?>" width="32" alt="Mastercard" />
		                </div>
                        <br style="clear:both">

		            </div>
		        </div>
	        </div>

			<?php
		}

		public function paymentCardScripts ( ) {

			# include stylesheets for the form
			wp_enqueue_style( $this -> id . '-style', WC_PAYGATE_PLUGIN_URL . '/assets/css/paygate-paysubs-form.css' );

		}


		/**
		 * Validates the credit card data entered on form during checkout
		 * @see inherited class
		 */
		public function validate_fields ( ) {
			return true;
		}

		private function getSoapPayload ( $order ) {

			$password 			= ( $this -> testmodeEnabled ) ? 'test' : $this -> paygatePassword;
			$transactionRef 	= get_post_meta( $order -> id, 'transaction_ref', true );
			if( empty( $transactionRef ) ) {
				# try to get the id from the data
				$transactionData 		= get_post_meta(  $order -> id, 'transaction_data', true );
				if( ! is_array( $transactionData ) )
					$transactionData	= unserialize( $transactionData );

				$transactionRef 	= $transactionData[ 'REFERENCE' ];
			}

			# use reference to retrieve the last 5 transactions
			$payload = "<ns1:SingleFollowUpRequest>
							<ns1:QueryRequest>
								<ns1:Account>
									<ns1:PayGateId>{$this -> paygateId}</ns1:PayGateId>
									<ns1:Password>{$password}</ns1:Password>
								</ns1:Account>
								<ns1:MerchantOrderId>{$transactionRef}</ns1:MerchantOrderId>
							</ns1:QueryRequest>

						</ns1:SingleFollowUpRequest>";

			return $payload;
		}


		public static function log( $orderId, $message ) {

		    if( self::$enableLogs ) {
                date_default_timezone_set( 'Africa/Johannesburg' );
                $logFilename = date( 'Y-F-d' ) . '.log';

                $dateTime	= date( 'Y-F-d' ) . ' at ' . date('h:i A');
                $message	= "[{$orderId}] - {$dateTime} >>> {$message}";

                $handle		= fopen( plugin_dir_path ( __FILE__ ) . '/logs/' . $logFilename, 'a' );
                fwrite( $handle, $message );
                fclose( $handle );
            }

		}

	}
