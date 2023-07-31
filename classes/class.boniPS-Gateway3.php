<?php
if ( ! defined( 'BONIPS_MARKET_VERSION' ) ) exit;

/**
 * PSeCommerce Gateway 1.5x
 * @since 1.1
 * @version 1.0
 */
if ( ! class_exists( 'MP_Gateway_boniPS_New' ) && class_exists( 'MP_Gateway_API' ) ) :
	class MP_Gateway_boniPS_New extends MP_Gateway_API {

		var $plugin_name           = BONIPS_SLUG;
		var $admin_name            = BONIPS_DEFAULT_LABEL;
		var $public_name           = BONIPS_DEFAULT_LABEL;
		var $bonips_type           = BONIPS_DEFAULT_TYPE_KEY;
		var $method_img_url        = '';
		var $method_button_img_url = '';
		var $force_ssl             = false;
		var $ipn_url;
		var $skip_form             = false;

		/**
		 * Custom Constructor
		 * @since 1.1
		 * @version 1.0
		 */
		function on_creation() {

			$this->admin_name            = BONIPS_DEFAULT_LABEL;
			$this->public_name           = $this->get_setting( 'name', BONIPS_DEFAULT_LABEL );
			$this->method_img_url        = $this->get_setting( 'logo', plugins_url( 'assets/images/bonips-token-icon.png', BONIPS_PSECOMMERCE ) );
			$this->method_button_img_url = $this->public_name;

			$this->bonips_type           = $this->get_setting( 'type', BONIPS_DEFAULT_TYPE_KEY );
			$this->bonips                = bonips( $this->bonips_type );

		}
	
		/**
		 * Use Exchange
		 * Checks to see if exchange is needed.
		 * @since 1.1
		 * @version 1.0
		 */
		function use_exchange() {

			return ( mp_get_setting( 'currency' ) != 'POINTS' ) ? true : false;

		}

		/**
		 * Get Points Cost
		 * Returns the carts total cost in points.
		 * @since 1.1
		 * @version 1.0
		 */
		function get_point_cost( $cart_total ) {

			$cost       = $cart_total;
			$exchange   = $this->get_setting( 'exchange', 1 );

			if ( $this->use_exchange() )
				$cost = $cart_total / $exchange;

			return apply_filters( 'bonips_psecommerce_cart_cost', $cost, $exchange, $this );

		}

		/**
		 * Payment Form
		 * Used to show a user how much their order would cost in points, assuming the can use the selected point type.
		 * @since 1.1
		 * @version 1.0
		 */
		public function payment_form( $cart, $shipping_info ) {

			global $mp;
		
			if ( ! is_user_logged_in() ) {

				$message = str_replace( '%login_url_here%', wp_login_url( mp_checkout_step_url( 'checkout' ) ), $this->get_setting( 'visitors', __( 'Bitte melde Dich an, um diese Zahlungsoption zu nutzen.', 'bonips_market' ) ) );
				$message = $this->bonips->template_tags_general( $message );

				return '<div id="mp-bonips-balance">' . $message . '</div>';

			}

			$user_id  = get_current_user_id();
			$balance  = $this->bonips->get_users_balance( $user_id, $this->bonips_type );
			$total    = $this->get_point_cost( $cart->total() );
		
			// Low balance
			if ( $balance < $total ) {

				$message      = $this->bonips->template_tags_user( $this->get_setting( 'lowfunds', __( 'Unzureichende Mittel', 'bonips_market' ), false, wp_get_current_user() ) );
				$instructions = '<div id="mp-bonips-balance">' . wpautop( wptexturize( $message ) ) . '</div>';
				$warn         = true;

			}
			else {

				$message      = '';
				$instructions = $this->bonips->template_tags_general( $this->get_setting( 'instructions', '' ) );
				$warn         = false;

			}
		
			// Return Cost
			return '
<div id="mp-bonips-balance">' . $instructions . '</div>
<div id="mp-bonips-cost">
	<table style="width:100%;">
		<tbody>
			<tr class="bonips-current-balance">
				<td class="info">' . __( 'Aktuelles Guthaben', 'bonips_market' ) . '</td>
				<td class="amount">' . $this->bonips->format_creds( $balance ) . '</td>
			</tr>
			<tr class="bonips-total-cost">
				<td class="info">' . __( 'Gesamtkosten', 'bonips_market' ) . '</td>
				<td class="amount">' . $this->bonips->format_creds( $total ) . '</td>
			</tr>
			<tr class="bonips-balance-after-payment">
				<td class="info">' . __( 'Guthaben nach dem Kauf', 'bonips_market' ) . '</td>
				<td class="amount' . ( $warn ? ' text-danger' : '' ) . '"' . ( $warn ? ' style="color: red;"' : '' ) . '>' . $this->bonips->format_creds( $balance - $total ) . '</td>
			</tr>
		</tbody>
	</table>
</div>';

		}

		/**
		 * Process Payment
		 * Will check a buyers eligibility to use this gateway, their account solvency and charge the payment
		 * if the user can afford it.
		 * @since 1.1
		 * @version 1.0
		 */
		function process_payment( $cart, $billing_info, $shipping_info ) {

			$user_id   = get_current_user_id();
			$timestamp = time();

			// This gateway requires buyer to be logged in
			if ( ! is_user_logged_in() ) {

				$message = str_replace( '%login_url_here%', wp_login_url( mp_store_page_url( 'checkout', false ) ), $this->get_setting( 'visitors', __( 'Bitte melde Dich an, um diese Zahlungsoption zu nutzen.', 'bonips_market' ) ) );
				mp_checkout()->add_error( $this->bonips->template_tags_general( $message ) );
				return;

			}

			// Make sure current user is not excluded from using boniPS
			if ( $this->bonips->exclude_user( $user_id ) ) {
				mp_checkout()->add_error( sprintf( __( 'Leider kannst Du dieses Gateway nicht verwenden, da Dein Konto ausgeschlossen ist. Bitte <a href="%s">wähle eine andere Zahlungsmethode</a>.', 'bonips_market' ), mp_store_page_url( 'checkout', false ) ) );
				return;
			}

			// Get users balance
			$balance = $this->bonips->get_users_balance( $user_id, $this->bonips_type );
			$total   = $this->get_point_cost( $cart->total() );
		
			// Check solvency
			if ( $balance < $total ) {

				$message = str_replace( '%login_url_here%', wp_login_url( mp_checkout_step_url( 'checkout' ) ), $this->get_setting( 'lowfunds', __( 'Unzureichendes Guthaben', 'bonips_market' ) ) );
				mp_checkout()->add_error( $message . ' <a href="' . mp_store_page_url( 'checkout', false ) . '">' . __( 'Gehe zurück', 'bonips_market' ) . '</a>' );
				return;

			}

			// Let others decline a store order
			$decline = apply_filters( 'bonips_decline_store_purchase', false, $cart, $this );
			if ( $decline !== false ) {

				mp_checkout()->add_error( $decline );
				return;

			}

			// Create payment info
			$payment_info                         = array();
			$payment_info['gateway_public_name']  = $this->public_name;
			$payment_info['gateway_private_name'] = $this->admin_name;
			$payment_info['status'][ $timestamp ] = __( 'Bezahlt', 'bonips_market' );
			$payment_info['total']                = $cart->total();
			$payment_info['currency']             = mp_get_setting( 'currency' );
			$payment_info['method']               = $this->public_name;
			$payment_info['transaction_id']       = 'MMP' . $timestamp;

			// Generate and save new order
			$order    = new MP_Order();
			$order->save( array(
				'cart'         => $cart,
				'payment_info' => $payment_info,
				'paid'         => true
			) );
			$order_id = $order->ID;

			// Charge users account
			$this->bonips->add_creds(
				'psecommerce_payment',
				$user_id,
				0 - $total,
				$this->get_setting( 'paymentlog', __( 'Zahlung für Bestellung: #%order_id%', 'bonips_market' ) ),
				$order_id,
				array( 'ref_type' => 'post' ),
				$this->bonips_type
			);

			// Profit Sharing
			$this->process_profit_sharing( $cart, $order_id );

			wp_redirect( $order->tracking_url( false ) );
			exit;

		}

		/**
		 * Process Profit Sharing
		 * If used.
		 * @since 1.1
		 * @version 1.0
		 */
		function process_profit_sharing( $cart = NULL, $order_id = 0 ) {

			$payouts      = array();
			$profit_share = apply_filters( 'bonips_psecommerce_profit_share', $this->get_setting( 'profitshare', 0 ), $cart, $this );

			// If profit share is enabled
			if ( $cart !== NULL && $profit_share > 0 ) {

				if ( $cart->is_global ) {

					$carts = $cart->get_all_items();
					foreach ( $carts as $blog_id => $items ) {
						if ( count( $items ) > 0 ) {

							foreach ( $items as $item ) {

								$price = $this->get_point_cost( $item->get_price( 'lowest' ) * $item->qty );
								$share = ( $profit_share / 100 ) * $price;

								$post  = get_post( $item->ID );
								if ( isset( $post->post_author ) )
									$payouts[ $post->post_author ] = array(
										'product_id' => $item->ID,
										'payout'     => $share
									);

							}

						}
					}

				}
				else {

					$items = $cart->get_items();
					if ( count( $items ) > 0 ) {

						foreach ( $items as $item => $qty ) {

							$product = new MP_Product( $item );
							// we will have to check this product exists or not
							if ( ! $product->exists() ) {
								continue;
							}

							$price = $this->get_point_cost( $product->get_price( 'lowest' ) * $qty );
							$share = ( $profit_share / 100 ) * $price;

							$post  = get_post( $item->ID );
							if ( isset( $post->post_author ) )
								$payouts[ $post->post_author ] = array(
									'product_id' => $item->ID,
									'payout'     => $share
								);

						}

					}

				}

				if ( ! empty( $payouts ) ) {

					$log_template = $this->get_setting( 'sharelog', __( 'Bestellung #%order_id% payout', 'bonips_market' ) );

					foreach ( $payouts as $user_id => $payout ) {

						$data = array( 'ref_type' => 'post', 'postid' => $payout['product_id'] );

						// Make sure we only payout once for each order
						if ( ! $this->bonips->has_entry( 'psecommerce_sale', $order_id, $user_id, $data, $this->bonips_type ) )
							$this->bonips->add_creds(
								'psecommerce_sale',
								$user_id,
								$payout['payout'],
								$log_template,
								$order_id,
								$data,
								$this->bonips_type
							);

					}

				}

			}

		}

		/**
		 * Order Confirmation
		 * Not used
		 * @since 1.1
		 * @version 1.0
		 */
		function order_confirmation( $order ) { }

		/**
		 * Email Confirmation Message
		 * @since 1.1
		 * @version 1.0
		 */
		function order_confirmation_email( $msg, $order ) {

			if ( $email_text = $this->get_setting( 'email' ) ) {
				$msg = mp_filter_email( $order, $email_text );
			}

			return $msg;

		}

		/**
		 * Setup Gateway Settings
		 * @since 1.1
		 * @version 1.0
		 */
		public function init_settings_metabox() {

			$metabox = new PSOURCE_Metabox( array(
				'id'          => $this->generate_metabox_id(),
				'page_slugs'  => array( 'shop-einstellungen-payments', 'shop-einstellungen_page_shop-einstellungen-payments' ),
				'title'       => sprintf( __( '%s Einstellungen', 'bonips_market' ), $this->admin_name ),
				'option_name' => 'mp_settings',
				'desc'        => __( 'Lasse Benutzer mit Deinem Punkteguthaben bezahlen.', 'bonips_market' ),
				'conditional' => array(
					'name'        => 'gateways[allowed][' . $this->plugin_name . ']',
					'value'       => 1,
					'action'      => 'show',
				),
			) );
			$metabox->add_field( 'text', array(
				'name'          => $this->get_field_name( 'name' ),
				'default_value' => $this->public_name,
				'label'         => array( 'text' => __( 'Methodenname', 'bonips_market' ) ),
				'desc'          => __( 'Gib einen öffentlichen Namen für diese Zahlungsmethode ein, der den Benutzern angezeigt wird - Kein HTML', 'bonips_market' ),
				'save_callback' => array( 'strip_tags' ),
			) );

			$metabox->add_field( 'select', array(
				'name'          => $this->get_field_name( 'type' ),
				'label'         => array( 'text' => __( 'Point Type', 'bonips_market' ) ),
				'options'       => bonips_get_types(),
				'desc'          => __( 'Wähle den Punkttyp aus, den Du als Zahlung in Deinem Shop akzeptieren möchtest. Wenn dieser Shop nur Punkte als Zahlungsmittel akzeptiert, stelle sicher, dass Du denselben Punktetyp auswählst, den Du als Geschäftswährung festgelegt hast!', 'bonips_market' )
			) );
			$metabox->add_field( 'text', array(
				'name'          => $this->get_field_name( 'exchange' ),
				'default_value' => 1,
				'label'         => array( 'text' => __( 'Wechselkurs', 'bonips_market' ) ),
				'desc'          => __( 'Der Wechselkurs zwischen der ausgewählten Währung Deines Geschäfts und dem ausgewählten Punkttyp. Wenn dies ein Punkte-Shop ist, stelle sicher, dass dieser auf 1 gesetzt ist. Beispiel: 100 Punkte = 1 EUR Der Wechselkurs wäre: 0,01', 'bonips_market' ),
				'save_callback' => array( 'strip_tags' ),
			) );

			$metabox->add_field( 'wysiwyg', array(
				'name'	 => $this->get_field_name( 'visitors' ),
				'label'	 => array( 'text' => __( 'Besucher', 'bonips_market' ) ),
				'desc'	 => __( 'Meldung, die anzeigt, wann das Gateway von jemandem angezeigt wird, der nicht auf Deiner Webseite angemeldet ist. Nur angemeldete Benutzer können dieses Gateway verwenden!', 'bonips_market' ),
			) );
			$metabox->add_field( 'wysiwyg', array(
				'name'	 => $this->get_field_name( 'excluded' ),
				'label'	 => array( 'text' => __( 'Ausgeschlossen', 'bonips_market' ) ),
				'desc'	 => __( 'Meldung, die anzeigt, wann das Gateway von jemandem angezeigt wird, der von der Verwendung des ausgewählten Punkttyps ausgeschlossen wurde.', 'bonips_market' ),
			) );
			$metabox->add_field( 'wysiwyg', array(
				'name'	 => $this->get_field_name( 'lowfunds' ),
				'label'	 => array( 'text' => __( 'Unzureichende Mittel', 'bonips_market' ) ),
				'desc'	 => __( 'Meldung, die anzeigt, wann das Gateway von jemandem angezeigt wird, der es sich nicht leisten kann, mit Punkten zu bezahlen.', 'bonips_market' ),
			) );

			$metabox->add_field( 'text', array(
				'name'          => $this->get_field_name( 'paymentlog' ),
				'default_value' => __( 'Zahlung für Bestellung: #%order_id%', 'bonips_market' ),
				'label'         => array( 'text' => __( 'Zahlungsprotokollvorlage', 'bonips_market' ) ),
				'desc'          => __( 'Die Protokolleintragsvorlage, die für jede Punktezahlung verwendet wird. Diese Vorlage wird dem Käufer in seiner Punktehistorie angezeigt. KANN NICHT LEER SEIN!', 'bonips_market' )
			) );

			$metabox->add_field( 'text', array(
				'name'          => $this->get_field_name( 'profitshare' ),
				'default_value' => 0,
				'label'         => array( 'text' => __( 'Gewinnbeteiligung', 'bonips_market' ) ),
				'desc'          => __( 'Option, bei jedem Kauf eines Produkts mit Punkten einen Prozentsatz des Produktpreises mit dem Produktbesitzer zu teilen. Verwende Null zum Deaktivieren.', 'bonips_market' )
			) );
			$metabox->add_field( 'text', array(
				'name'          => $this->get_field_name( 'sharelog' ),
				'default_value' => 1,
				'label'         => array( 'text' => __( 'Protokollvorlage für Gewinnbeteiligungen', 'bonips_market' ) ),
				'desc'          => __( 'Die Protokolleintragsvorlage, die für jede Gewinnbeteiligung verwendet wird. Diese Vorlage wird dem Verkäufer in seiner Punktehistorie angezeigt. Wird ignoriert, wenn die Gewinnbeteiligung deaktiviert ist.', 'bonips_market' )
			) );

			$metabox->add_field( 'wysiwyg', array(
				'name'	 => $this->get_field_name( 'instruction' ),
				'label'	 => array( 'text' => __( 'Benutzeranleitung', 'bonips_market' ) ),
				'desc'	 => __( 'Optionale Anweisungen zum Anzeigen der Benutzer bei der Auswahl der Zahlung mit Punkten. Ihr aktueller Saldo, die Gesamtkosten der Bestellung in Punkten sowie ihr zukünftiger Saldo, falls sie zahlen möchten, sind ebenfalls enthalten.', 'bonips_market' ),
			) );
			$metabox->add_field( 'textarea', array(
				'name'			 => $this->get_field_name( 'email' ),
				'label'			 => array( 'text' => __( 'Bestellbestätigungs-E-Mail', 'bonips_market' ) ),
				'desc'			 => __( 'Dies ist der E-Mail-Text, der an diejenigen gesendet werden soll, die mit Punkten bezahlt haben. Es überschreibt die Standard-Bestell-Checkout-E-Mail. Diese Codes werden durch Bestelldetails ersetzt: CUSTOMERNAME, ORDERID, ORDERINFO, SHIPPINGINFO, PAYMENTINFO, TOTAL, TRACKINGURL. Kein HTML erlaubt.', 'bonips_market' ),
				'custom'		 => array( 'rows' => 10 ),
				'save_callback'	 => array( 'strip_tags' ),
			) );

		}

		/**
		 * IPN Return
		 * not used
		 * @since 1.1
		 * @version 1.0
		 */
		function process_ipn_return() { }

	}
endif;
