<?php
if ( ! defined( 'BONIPS_MARKET_VERSION' ) ) exit;

/**
 * PSeCommerce Gateway
 * @since 1.0
 * @version 1.0
 */
if ( ! class_exists( 'MP_Gateway_boniPS' ) && class_exists( 'MP_Gateway_API' ) ) :
	class MP_Gateway_boniPS extends MP_Gateway_API {

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
		 * Runs when your class is instantiated. Use to setup your plugin instead of __construct()
		 */
		function on_creation() {

			global $mp;

			$settings                    = get_option( 'mp_settings' );
			$this->admin_name            = BONIPS_DEFAULT_LABEL;
			$this->public_name           = BONIPS_DEFAULT_LABEL;

			if ( isset( $settings['gateways']['bonips']['name'] ) && ! empty( $settings['gateways']['bonips']['name'] ) )
				$this->public_name = $settings['gateways']['bonips']['name'];

			$this->method_img_url        = plugins_url( 'assets/images/bonips-token-icon.png', BONIPS_PSECOMMERCE );
			if ( isset( $settings['gateways']['bonips']['logo'] ) && ! empty( $settings['gateways']['bonips']['logo'] ) )
				$this->method_img_url = $settings['gateways']['bonips']['logo'];

			$this->method_button_img_url = $this->public_name;

			if ( ! isset( $settings['gateways']['bonips']['type'] ) )
				$this->bonips_type = BONIPS_DEFAULT_TYPE_KEY;
			else
				$this->bonips_type = $settings['gateways']['bonips']['type'];

			$this->bonips               = bonips( $this->bonips_type );

		}
	
		/**
		 * Use Exchange
		 * Checks to see if exchange is needed.
		 * @since 1.0
		 * @version 1.0
		 */
		function use_exchange() {

			global $mp;

			$settings = get_option( 'mp_settings' );
			if ( $settings['currency'] == 'POINTS' ) return false;

			return true;

		}

		/**
		 * Returns the current carts total.
		 * @since 1.0
		 * @version 1.0
		 */
		function get_cart_total( $cart = NULL ) {

			global $mp;

			// Get total
			$totals = array();
			foreach ( $cart as $product_id => $variations ) {
				foreach ( $variations as $data ) {
					$totals[] = $mp->before_tax_price( $data['price'], $product_id ) * $data['quantity'];
				}
			}
			$total  = array_sum( $totals );

			// Apply Coupons
			if ( $coupon = $mp->coupon_value( $mp->get_coupon_code(), $total ) )
				$total = $coupon['new_total'];

			// Shipping Cost
			if ( ( $shipping_price = $mp->shipping_price() ) !== false )
				$total = $total + $shipping_price;

			// Tax
			if ( ( $tax_price = $mp->tax_price() ) !== false )
				$total = $total + $tax_price;
		
			$settings = get_option( 'mp_settings' );
			if ( $this->use_exchange() )
				$cost = $this->bonips->apply_exchange_rate( $total, $settings['gateways']['bonips']['exchange'] );
			else
				$cost = $this->bonips->number( $total );

			return apply_filters( 'bonips_psecommerce_order_cost', $cost, $total, $settings, $cart, $this );

		}

		/**
		 * Return fields you need to add to the payment screen, like your credit card info fields
		 *
		 * @param array $cart. Contains the cart contents for the current blog, global cart if $mp->global_cart is true
		 * @param array $shipping_info. Contains shipping info and email in case you need it
		 * @since 1.0
		 * @version 1.0
		 */
		function payment_form( $cart, $shipping_info ) {

			global $mp;
		
			$settings = get_option( 'mp_settings' );
		
			if ( ! is_user_logged_in() ) {

				$message = str_replace( '%login_url_here%', wp_login_url( mp_checkout_step_url( 'checkout' ) ), $settings['gateways']['bonips']['visitors'] );
				$message = $this->bonips->template_tags_general( $message );

				return '<div id="mp-bonips-balance">' . $message . '</div>';

			}
		
			$balance  = $this->bonips->get_users_balance( get_current_user_id(), $this->bonips_type );
			$total    = $this->get_cart_total( $cart );
		
			// Low balance
			if ( $balance-$total < 0 ) {

				$message      = $this->bonips->template_tags_user( $settings['gateways']['bonips']['lowfunds'], false, wp_get_current_user() );
				$instructions = '<div id="mp-bonips-balance">' . $message . '</div>';
				$red          = ' style="color: red;"';

			}
			else {

				$message      = '';
				$instructions = $this->bonips->template_tags_general( $settings['gateways']['bonips']['instructions'] );
				$red          = '';

			}
		
			// Return Cost
			return '
<div id="mp-bonips-balance">' . $instructions . '</div>
<div id="mp-bonips-cost">
<table style="width:100%;">
	<tr>
		<td class="info">' . __( 'Aktuelles Guthaben', 'bonips_market' ) . '</td>
		<td class="amount">' . $this->bonips->format_creds( $balance ) . '</td>
	</tr>
	<tr>
		<td class="info">' . __( 'Gesamtkosten', 'bonips_market' ) . '</td>
		<td class="amount">' . $this->bonips->format_creds( $total ) . '</td>
	</tr>
	<tr>
		<td class="info">' . __( 'Guthaben nach dem Kauf', 'bonips_market' ) . '</td>
		<td class="amount"' . $red . '>' . $this->bonips->format_creds( $balance-$total ) . '</td>
	</tr>
</table>
</div>';

		}

		/**
		 * Return the chosen payment details here for final confirmation. You probably don't need
		 * to post anything in the form as it should be in your $_SESSION var already.
		 *
		 * @param array $cart. Contains the cart contents for the current blog, global cart if $mp->global_cart is true
		 * @param array $shipping_info. Contains shipping info and email in case you need it
		 * @since 1.0
		 * @version 1.0
		 */
		function confirm_payment_form( $cart, $shipping_info ) {

			global $mp;

			$settings = get_option( 'mp_settings' );
			$user_id  = get_current_user_id();
			$balance  = $this->bonips->get_users_balance( get_current_user_id(), $this->bonips_type );
			$total    = $this->get_cart_total( $cart );
		
			$table    = '<table class="bonips-cart-cost"><thead><tr><th>' . __( 'Zahlung', 'bonips_market' ) . '</th></tr></thead>';
			if ( $balance-$total < 0 ) {

				$message = $this->bonips->template_tags_user( $settings['gateways']['bonips']['lowfunds'], false, wp_get_current_user() );
				$table .= '<tr><td id="mp-bonips-cost" style="color: red; font-weight: bold;"><p>' . $message . ' <a href="' . mp_checkout_step_url( 'checkout' ) . '">' . __( 'Gehe zurück', 'bonips_market' ) . '</a></td></tr>';

			}

			else
				$table .= '<tr><td id="mp-bonips-cost" class="bonips-ok">' . $this->bonips->format_creds( $total ) . ' ' . __( 'wird von Deinem Konto abgebucht.', 'bonips_market' ) . '</td></tr>';
		
			return $table . '</table>';

		}

		function process_payment_form( $cart, $shipping_info ) { }

		/**
		 * Use this to do the final payment. Create the order then process the payment. If
		 * you know the payment is successful right away go ahead and change the order status
		 * as well.
		 * Call $mp->cart_checkout_error($msg, $context); to handle errors. If no errors
		 * it will redirect to the next step.
		 *
		 * @param array $cart. Contains the cart contents for the current blog, global cart if $mp->global_cart is true
		 * @param array $shipping_info. Contains shipping info and email in case you need it
		 * @since 1.0
		 * @version 1.0
		 */
		function process_payment( $cart, $billing_info, $shipping_info ) {

			global $mp;
		
			$settings  = get_option('mp_settings');
			$user_id   = get_current_user_id();
			$insolvent = $this->bonips->template_tags_user( $settings['gateways']['bonips']['lowfunds'], false, wp_get_current_user() );
			$timestamp = time();

			// This gateway requires buyer to be logged in
			if ( ! is_user_logged_in() ) {

				$message = str_replace( '%login_url_here%', wp_login_url( mp_checkout_step_url( 'checkout' ) ), $settings['gateways']['bonips']['visitors'] );
				$mp->cart_checkout_error( $this->bonips->template_tags_general( $message ) );

			}

			// Make sure current user is not excluded from using boniPS
			if ( $this->bonips->exclude_user( $user_id ) )
				$mp->cart_checkout_error(
					sprintf( __( 'Leider kannst Du dieses Gateway nicht verwenden, da Dein Konto ausgeschlossen ist. Bitte <a href="%s">wähle eine andere Zahlungsmethode</a>.', 'bonips_market' ), mp_checkout_step_url( 'checkout' ) )
				);

			// Get users balance
			$balance = $this->bonips->get_users_balance( $user_id, $this->bonips_type );
			$total   = $this->get_cart_total( $cart );
		
			// Low balance or Insolvent
			if ( $balance <= $this->bonips->zero() || $balance-$total < $this->bonips->zero() ) {

				$mp->cart_checkout_error(
					$insolvent . ' <a href="' . mp_checkout_step_url( 'checkout' ) . '">' . __( 'Gehe zurück', 'bonips_market' ) . '</a>'
				);
				return;

			}

			// Let others decline a store order
			$decline = apply_filters( 'bonips_decline_store_purchase', false, $cart, $this );
			if ( $decline !== false ) {

				$mp->cart_checkout_error( $decline );
				return;

			}

			// Create PSeCommerce order
			$order_id                             = $mp->generate_order_id();
			$payment_info['gateway_public_name']  = $this->public_name;
			$payment_info['gateway_private_name'] = $this->admin_name;
			$payment_info['status'][ $timestamp ] = __( 'Bezahlt', 'bonips_market' );
			$payment_info['total']                = $total;
			$payment_info['currency']             = $settings['currency'];
			$payment_info['method']               = BONIPS_DEFAULT_LABEL;
			$payment_info['transaction_id']       = $order_id;

			$paid   = true;
			$result = $mp->create_order( $order_id, $cart, $shipping_info, $payment_info, $paid );
			
			$order  = get_page_by_title( $result, 'OBJECT', 'mp_order' );

			// Deduct cost
			$this->bonips->add_creds(
				'psecommerce_payment',
				$user_id,
				0-$total,
				$settings['gateways']['bonips']['log_template'],
				$order->ID,
				array( 'ref_type' => 'post' ),
				$this->bonips_type
			);
			
			// Profit Sharing
			if ( $settings['gateways']['bonips']['profit_share_percent'] > 0 ) {
				foreach ( $cart as $product_id => $variations ) {

					// Get Product
					$product = get_post( (int) $product_id );

					// Continue if product has just been deleted or owner is buyer
					if ( $product === NULL || $product->post_author == $cui ) continue;

					foreach ( $variations as $data ) {

						$price      = $data['price'];
						$quantity   = $data['quantity'];
						$cost       = $price*$quantity;

						// Get profit share
						$percentage = apply_filters( 'bonips_psecommerce_profit_share', $settings['gateways']['bonips']['profit_share_percent'], $order, $product, $this );
						if ( $percentage == 0 ) continue;

						// Calculate Share
						$share      = ( $percentage / 100 ) * $cost;

						// Payout
						$this->bonips->add_creds(
							'psecommerce_sale',
							$product->post_author,
							$share,
							$settings['gateways']['bonips']['profit_share_log'],
							$product->ID,
							array( 'ref_type' => 'post' ),
							$this->bonips_type
						);

					}

				}
			}

		}

		function order_confirmation( $order ) { }

		/**
		 * Filters the order confirmation email message body. You may want to append something to
		 * the message. Optional
		 * @since 1.0
		 * @version 1.0
		 */
		function order_confirmation_email( $msg, $order ) {

			global $mp;

			$settings = get_option('mp_settings');

			if ( isset( $settings['gateways']['bonips']['email'] ) )
				$msg = $mp->filter_email( $order, $settings['gateways']['bonips']['email'] );
			else
				$msg = $settings['email']['new_order_txt'];

			return $msg;

		}

		/**
		 * Return any html you want to show on the confirmation screen after checkout. This
		 * should be a payment details box and message.
		 * @since 1.0
		 * @version 1.0
		 */
		function order_confirmation_msg( $content, $order ) {

			global $mp;

			$settings = get_option( 'mp_settings' );
			$bonips   = bonips();
			$user_id  = get_current_user_id();

			return $content . str_replace(
				'TOTAL',
				$mp->format_currency( $order->mp_payment_info['currency'], $order->mp_payment_info['total'] ),
				$bonips->template_tags_user( $settings['gateways']['bonips']['confirmation'], false, wp_get_current_user() )
			);

		}

		/**
		 * boniPS Gateway Settings
		 * @since 1.0
		 * @version 1.0
		 */
		function gateway_settings_box( $settings ) {

			global $mp;

			$settings = get_option( 'mp_settings' );
			$bonips   = bonips();
			$name     = bonips_label( true );

			$settings['gateways']['bonips'] = shortcode_atts( array(
				'name'                 => $name . ' ' . $bonips->template_tags_general( __( '%_singular% Guthaben', 'bonips_market' ) ),
				'logo'                 => $this->method_button_img_url,
				'type'                 => BONIPS_DEFAULT_TYPE_KEY,
				'log_template'         => __( 'Zahlung für Bestellung: #%order_id%', 'bonips_market' ),
				'exchange'             => 1,
				'profit_share_percent' => 0,
				'profit_share_log'     => __( 'Produktverkauf: %post_title%', 'bonips_market' ),
				'instructions'         => __( 'Zahle mit Deinem Kontostand.', 'bonips_market' ),
				'confirmation'         => __( 'Der Gesamtbetrag wurde von Deinem Konto abgebucht. Dein aktueller Kontostand beträgt: %balance_f%', 'bonips_market' ),
				'lowfunds'             => __( 'Unzureichendes Guthaben.', 'bonips' ),
				'visitors'             => __( 'Du musst angemeldet sein, um mit %_plural% bezahlen zu können. Bitte <a href="%login_url_here%">anmelden</a>.', 'bonips_market' ),
				'email'                => $settings['email']['new_order_txt']
			), ( ( array_key_exists( 'bonips', $settings['gateways'] ) ) ? $settings['gateways']['bonips'] : array() ) );

?>
<div id="mp_bonips_payments" class="postbox mp-pages-msgs">
	<h3 class="handle"><span><?php printf( __( '%s Einstellungen', 'bonips_market' ), BONIPS_DEFAULT_LABEL ); ?></span></h3>
	<div class="inside">
		<span class="description"><?php echo sprintf( __( 'Lasse Deine Benutzer Artikel in ihrem Warenkorb mit ihrem %s Konto bezahlen. Hinweis! Für dieses Gateway müssen Deine Benutzer beim Kauf angemeldet sein!', 'bonips_market' ), $name ); ?></span>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="bonips-method-name"><?php _e( 'Methodenname', 'bonips_market' ); ?></label></th>
				<td>
					<span class="description"><?php _e( 'Gib einen öffentlichen Namen für diese Zahlungsmethode ein, der den Benutzern angezeigt wird - Kein HTML', 'bonips_market' ); ?></span>
					<p><input value="<?php echo esc_attr( $settings['gateways']['bonips']['name'] ); ?>" style="width: 100%;" name="mp[gateways][bonips][name]" id="bonips-method-name" type="text" /></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bonips-method-logo"><?php _e( 'Gateway Logo URL', 'bonips_market' ); ?></label></th>
				<td>
					<p><input value="<?php echo esc_attr( $settings['gateways']['bonips']['logo'] ); ?>" style="width: 100%;" name="mp[gateways][bonips][logo]" id="bonips-method-logo" type="text" /></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bonips-method-type"><?php _e( 'Punkttyp', 'bonips_market' ); ?></label></th>
				<td>
					<?php bonips_types_select_from_dropdown( 'mp[gateways][bonips][type]', 'bonips-method-type', $settings['gateways']['bonips']['type'] ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bonips-log-template"><?php _e( 'Protokollvorlage', 'bonips_market' ); ?></label></th>
				<td>
					<span class="description"><?php echo $bonips->available_template_tags( array( 'general' ), '%order_id%, %order_link%' ); ?></span>
					<p><input value="<?php echo esc_attr( $settings['gateways']['bonips']['log_template'] ); ?>" style="width: 100%;" name="mp[gateways][bonips][log_template]" id="bonips-log-template" type="text" /></p>
				</td>
			</tr>
<?php

				// Exchange rate
				if ( $this->use_exchange() ) :

					$exchange_desc = __( 'Wie viel ist 1 %_singular% in %currency% wert?', 'bonips_market' );
					$exchange_desc = $bonips->template_tags_general( $exchange_desc );
					$exchange_desc = str_replace( '%currency%', $settings['currency'], $exchange_desc );

?>
			<tr>
				<th scope="row"><label for="bonips-exchange-rate"><?php _e( 'Wechselkurs', 'bonips_market' ); ?></label></th>
				<td>
					<span class="description"><?php echo $exchange_desc; ?></span>
					<p><input value="<?php echo esc_attr( $settings['gateways']['bonips']['exchange'] ); ?>" size="8" name="mp[gateways][bonips][exchange]" id="bonips-exchange-rate" type="text" /></p>
				</td>
			</tr>
<?php

				endif;

?>
			<tr>
				<td colspan="2"><h4><?php _e( 'Gewinnbeteiligung', 'bonips' ); ?></h4></td>
			</tr>
			<tr>
				<th scope="row"><label for="bonips-profit-sharing"><?php _e( 'Percentage', 'bonips_market' ); ?></label></th>
				<td>
					<span class="description"><?php _e( 'Option, Verkäufe mit dem Produktbesitzer zu teilen. Verwende Null zum Deaktivieren.', 'bonips_market' ); ?></span>
					<p><input value="<?php echo esc_attr( $settings['gateways']['bonips']['profit_share_percent'] ); ?>" size="8" name="mp[gateways][bonips][profit_share_percent]" id="bonips-profit-sharing" type="text" /> %</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bonips-profit-sharing-log"><?php _e( 'Protokollvorlage', 'bonips_market' ); ?></label></th>
				<td>
					<span class="description"><?php echo $bonips->available_template_tags( array( 'general', 'post' ) ); ?></span>
					<p><input value="<?php echo esc_attr( $settings['gateways']['bonips']['profit_share_log'] ); ?>" style="width: 100%;" name="mp[gateways][bonips][profit_share_log]" id="bonips-profit-sharing-log" type="text" /></p>
				</td>
			</tr>
			<tr>
				<td colspan="2"><h4><?php _e( 'Mitteilungen', 'bonips' ); ?></h4></td>
			</tr>
			<tr>
				<th scope="row"><label for="bonips-lowfunds"><?php _e( 'Unzureichendes Guthaben', 'bonips_market' ); ?></label></th>
				<td>
					<span class="description"><?php _e( 'Meldung, die anzeigt, wann der Benutzer dieses Gateway nicht verwenden kann.', 'bonips_market' ); ?></span>
					<p><input type="text" name="mp[gateways][bonips][lowfunds]" id="bonips-lowfunds" style="width: 100%;" value="<?php echo esc_attr( $settings['gateways']['bonips']['lowfunds'] ); ?>"><br />
					<span class="description"><?php echo $bonips->available_template_tags( array( 'general' ) ); ?></span></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bonips-visitors"><?php _e( 'Besucher', 'bonips_market' ); ?></label></th>
				<td>
					<span class="description"><?php _e( 'Nachricht, die Käufern angezeigt werden soll, die nicht angemeldet sind.', 'bonips_market' ); ?></span>
					<p><input type="text" name="mp[gateways][bonips][visitors]" id="bonips-visitors" style="width: 100%;" value="<?php echo esc_attr( $settings['gateways']['bonips']['visitors'] ); ?>"><br />
					<span class="description"><?php echo $bonips->available_template_tags( array( 'general' ) ); ?></span></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bonips-instructions"><?php _e( 'Benutzeranleitung', 'bonips_market' ); ?></label></th>
				<td>
					<span class="description"><?php _e( 'Informationen, die den Benutzern vor der Zahlung angezeigt werden sollen.', 'bonips_market' ); ?></span>
					<p><?php wp_editor( $settings['gateways']['bonips']['instructions'] , 'bonipsinstructions', array( 'textarea_name' => 'mp[gateways][bonips][instructions]' ) ); ?><br />
					<span class="description"><?php echo $bonips->available_template_tags( array( 'general' ), '%balance% or %balance_f%' ); ?></span></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bonips-confirmation"><?php _e( 'Bestätigungsinformationen', 'bonips_market' ); ?></label></th>
				<td>
					<span class="description"><?php _e( 'Informationen, die auf der Bestellbestätigungsseite angezeigt werden sollen. - HTML erlaubt', 'bonips_market' ); ?></span>
					<p><?php wp_editor( $settings['gateways']['bonips']['confirmation'], 'bonipsconfirmation', array( 'textarea_name' => 'mp[gateways][bonips][confirmation]' ) ); ?><br />
					<span class="description"><?php echo $bonips->available_template_tags( array( 'general' ), '%balance% or %balance_f%' ); ?></span></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bonips-email"><?php _e( 'Bestellbestätigungs-E-Mail', 'bonips_market' ); ?></label></th>
				<td>
					<span class="description"><?php echo sprintf( __( 'Dies ist der E-Mail-Text, der an diejenigen gesendet werden soll, die% s ausgecheckt haben. Es überschreibt die Standard-Bestell-Checkout-E-Mail. Diese Codes werden durch Bestelldetails ersetzt: CUSTOMERNAME, ORDERID, ORDERINFO, SHIPPINGINFO, PAYMENTINFO, TOTAL, TRACKINGURL. Kein HTML erlaubt.', 'bonips_market' ), $name ); ?></span>
					<p><textarea id="bonips-email" name="mp[gateways][bonips][email]" class="mp_emails_txt"><?php echo esc_textarea( $settings['gateways']['bonips']['email'] ); ?></textarea></p>
					<span class="description"><?php echo $bonips->available_template_tags( array( 'general' ), '%balance% or %balance_f%' ); ?></span>
				</td>
			</tr>
		</table>
	</div>
</div>
<?php

		}

		/**
		 * Filter Gateway Settings
		 * @since 1.0
		 * @version 1.0
		 */
		function process_gateway_settings( $settings ) {

			if ( ! array_key_exists( 'bonips', $settings['gateways'] ) ) return $settings;

			// Name (no html)
			$settings['gateways']['bonips']['name']                 = stripslashes( wp_filter_nohtml_kses( $settings['gateways']['bonips']['name'] ) );

			// Log Template (no html)
			$settings['gateways']['bonips']['log_template']         = stripslashes( wp_filter_nohtml_kses( $settings['gateways']['bonips']['log_template'] ) );
			$settings['gateways']['bonips']['type']                 = sanitize_text_field( $settings['gateways']['bonips']['type'] );
			$settings['gateways']['bonips']['logo']                 = stripslashes( wp_filter_nohtml_kses( $settings['gateways']['bonips']['logo'] ) );

			// Exchange rate (if used)
			if ( $this->use_exchange() ) {

				// Decimals must start with a zero
				if ( $settings['gateways']['bonips']['exchange'] != 1 && substr( $settings['gateways']['bonips']['exchange'], 0, 1 ) != '0' )
					$settings['gateways']['bonips']['exchange'] = (float) '0' . $settings['gateways']['bonips']['exchange'];

				// Decimal seperator must be punctuation and not comma
				$settings['gateways']['bonips']['exchange'] = str_replace( ',', '.', $settings['gateways']['bonips']['exchange'] );

			}
			else
				$settings['gateways']['bonips']['exchange'] = 1;
		
			$settings['gateways']['bonips']['profit_share_percent'] = stripslashes( sanitize_text_field( $settings['gateways']['bonips']['profit_share_percent'] ) );
			$settings['gateways']['bonips']['profit_share_log']     = stripslashes( wp_filter_nohtml_kses( $settings['gateways']['bonips']['profit_share_log'] ) );
		
			$settings['gateways']['bonips']['lowfunds']             = stripslashes( wp_filter_post_kses( $settings['gateways']['bonips']['lowfunds'] ) );
			$settings['gateways']['bonips']['visitors']             = stripslashes( wp_filter_post_kses( $settings['gateways']['bonips']['visitors'] ) );

			$settings['gateways']['bonips']['instructions']         = wp_kses_post( $settings['gateways']['bonips']['instructions'] );
			$settings['gateways']['bonips']['confirmation']         = wp_kses_post( $settings['gateways']['bonips']['confirmation'] );

			// Email (no html)
			$settings['gateways']['bonips']['email']                = stripslashes( wp_filter_nohtml_kses( $settings['gateways']['bonips']['email'] ) );

			return $settings;

		}

	}
endif;
