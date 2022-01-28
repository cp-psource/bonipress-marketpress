<?php
if ( ! defined( 'BONIPRESS_MARKET_VERSION' ) ) exit;

/**
 * PSeCommerce Gateway
 * @since 1.0
 * @version 1.0
 */
if ( ! class_exists( 'MP_Gateway_boniPRESS' ) && class_exists( 'MP_Gateway_API' ) ) :
	class MP_Gateway_boniPRESS extends MP_Gateway_API {

		var $plugin_name           = BONIPRESS_SLUG;
		var $admin_name            = BONIPRESS_DEFAULT_LABEL;
		var $public_name           = BONIPRESS_DEFAULT_LABEL;
		var $bonipress_type           = BONIPRESS_DEFAULT_TYPE_KEY;
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
			$this->admin_name            = BONIPRESS_DEFAULT_LABEL;
			$this->public_name           = BONIPRESS_DEFAULT_LABEL;

			if ( isset( $settings['gateways']['bonipress']['name'] ) && ! empty( $settings['gateways']['bonipress']['name'] ) )
				$this->public_name = $settings['gateways']['bonipress']['name'];

			$this->method_img_url        = plugins_url( 'assets/images/bonipress-token-icon.png', BONIPRESS_PSECOMMERCE );
			if ( isset( $settings['gateways']['bonipress']['logo'] ) && ! empty( $settings['gateways']['bonipress']['logo'] ) )
				$this->method_img_url = $settings['gateways']['bonipress']['logo'];

			$this->method_button_img_url = $this->public_name;

			if ( ! isset( $settings['gateways']['bonipress']['type'] ) )
				$this->bonipress_type = BONIPRESS_DEFAULT_TYPE_KEY;
			else
				$this->bonipress_type = $settings['gateways']['bonipress']['type'];

			$this->bonipress               = bonipress( $this->bonipress_type );

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
				$cost = $this->bonipress->apply_exchange_rate( $total, $settings['gateways']['bonipress']['exchange'] );
			else
				$cost = $this->bonipress->number( $total );

			return apply_filters( 'bonipress_psecommerce_order_cost', $cost, $total, $settings, $cart, $this );

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

				$message = str_replace( '%login_url_here%', wp_login_url( mp_checkout_step_url( 'checkout' ) ), $settings['gateways']['bonipress']['visitors'] );
				$message = $this->bonipress->template_tags_general( $message );

				return '<div id="mp-bonipress-balance">' . $message . '</div>';

			}
		
			$balance  = $this->bonipress->get_users_balance( get_current_user_id(), $this->bonipress_type );
			$total    = $this->get_cart_total( $cart );
		
			// Low balance
			if ( $balance-$total < 0 ) {

				$message      = $this->bonipress->template_tags_user( $settings['gateways']['bonipress']['lowfunds'], false, wp_get_current_user() );
				$instructions = '<div id="mp-bonipress-balance">' . $message . '</div>';
				$red          = ' style="color: red;"';

			}
			else {

				$message      = '';
				$instructions = $this->bonipress->template_tags_general( $settings['gateways']['bonipress']['instructions'] );
				$red          = '';

			}
		
			// Return Cost
			return '
<div id="mp-bonipress-balance">' . $instructions . '</div>
<div id="mp-bonipress-cost">
<table style="width:100%;">
	<tr>
		<td class="info">' . __( 'Aktuelles Guthaben', 'bonipress_market' ) . '</td>
		<td class="amount">' . $this->bonipress->format_creds( $balance ) . '</td>
	</tr>
	<tr>
		<td class="info">' . __( 'Gesamtkosten', 'bonipress_market' ) . '</td>
		<td class="amount">' . $this->bonipress->format_creds( $total ) . '</td>
	</tr>
	<tr>
		<td class="info">' . __( 'Guthaben nach dem Kauf', 'bonipress_market' ) . '</td>
		<td class="amount"' . $red . '>' . $this->bonipress->format_creds( $balance-$total ) . '</td>
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
			$balance  = $this->bonipress->get_users_balance( get_current_user_id(), $this->bonipress_type );
			$total    = $this->get_cart_total( $cart );
		
			$table    = '<table class="bonipress-cart-cost"><thead><tr><th>' . __( 'Zahlung', 'bonipress_market' ) . '</th></tr></thead>';
			if ( $balance-$total < 0 ) {

				$message = $this->bonipress->template_tags_user( $settings['gateways']['bonipress']['lowfunds'], false, wp_get_current_user() );
				$table .= '<tr><td id="mp-bonipress-cost" style="color: red; font-weight: bold;"><p>' . $message . ' <a href="' . mp_checkout_step_url( 'checkout' ) . '">' . __( 'Gehe zurück', 'bonipress_market' ) . '</a></td></tr>';

			}

			else
				$table .= '<tr><td id="mp-bonipress-cost" class="bonipress-ok">' . $this->bonipress->format_creds( $total ) . ' ' . __( 'wird von Deinem Konto abgebucht.', 'bonipress_market' ) . '</td></tr>';
		
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
			$insolvent = $this->bonipress->template_tags_user( $settings['gateways']['bonipress']['lowfunds'], false, wp_get_current_user() );
			$timestamp = time();

			// This gateway requires buyer to be logged in
			if ( ! is_user_logged_in() ) {

				$message = str_replace( '%login_url_here%', wp_login_url( mp_checkout_step_url( 'checkout' ) ), $settings['gateways']['bonipress']['visitors'] );
				$mp->cart_checkout_error( $this->bonipress->template_tags_general( $message ) );

			}

			// Make sure current user is not excluded from using boniPRESS
			if ( $this->bonipress->exclude_user( $user_id ) )
				$mp->cart_checkout_error(
					sprintf( __( 'Leider kannst Du dieses Gateway nicht verwenden, da Dein Konto ausgeschlossen ist. Bitte <a href="%s">wähle eine andere Zahlungsmethode</a>.', 'bonipress_market' ), mp_checkout_step_url( 'checkout' ) )
				);

			// Get users balance
			$balance = $this->bonipress->get_users_balance( $user_id, $this->bonipress_type );
			$total   = $this->get_cart_total( $cart );
		
			// Low balance or Insolvent
			if ( $balance <= $this->bonipress->zero() || $balance-$total < $this->bonipress->zero() ) {

				$mp->cart_checkout_error(
					$insolvent . ' <a href="' . mp_checkout_step_url( 'checkout' ) . '">' . __( 'Gehe zurück', 'bonipress_market' ) . '</a>'
				);
				return;

			}

			// Let others decline a store order
			$decline = apply_filters( 'bonipress_decline_store_purchase', false, $cart, $this );
			if ( $decline !== false ) {

				$mp->cart_checkout_error( $decline );
				return;

			}

			// Create PSeCommerce order
			$order_id                             = $mp->generate_order_id();
			$payment_info['gateway_public_name']  = $this->public_name;
			$payment_info['gateway_private_name'] = $this->admin_name;
			$payment_info['status'][ $timestamp ] = __( 'Bezahlt', 'bonipress_market' );
			$payment_info['total']                = $total;
			$payment_info['currency']             = $settings['currency'];
			$payment_info['method']               = BONIPRESS_DEFAULT_LABEL;
			$payment_info['transaction_id']       = $order_id;

			$paid   = true;
			$result = $mp->create_order( $order_id, $cart, $shipping_info, $payment_info, $paid );
			
			$order  = get_page_by_title( $result, 'OBJECT', 'mp_order' );

			// Deduct cost
			$this->bonipress->add_creds(
				'psecommerce_payment',
				$user_id,
				0-$total,
				$settings['gateways']['bonipress']['log_template'],
				$order->ID,
				array( 'ref_type' => 'post' ),
				$this->bonipress_type
			);
			
			// Profit Sharing
			if ( $settings['gateways']['bonipress']['profit_share_percent'] > 0 ) {
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
						$percentage = apply_filters( 'bonipress_psecommerce_profit_share', $settings['gateways']['bonipress']['profit_share_percent'], $order, $product, $this );
						if ( $percentage == 0 ) continue;

						// Calculate Share
						$share      = ( $percentage / 100 ) * $cost;

						// Payout
						$this->bonipress->add_creds(
							'psecommerce_sale',
							$product->post_author,
							$share,
							$settings['gateways']['bonipress']['profit_share_log'],
							$product->ID,
							array( 'ref_type' => 'post' ),
							$this->bonipress_type
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

			if ( isset( $settings['gateways']['bonipress']['email'] ) )
				$msg = $mp->filter_email( $order, $settings['gateways']['bonipress']['email'] );
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
			$bonipress   = bonipress();
			$user_id  = get_current_user_id();

			return $content . str_replace(
				'TOTAL',
				$mp->format_currency( $order->mp_payment_info['currency'], $order->mp_payment_info['total'] ),
				$bonipress->template_tags_user( $settings['gateways']['bonipress']['confirmation'], false, wp_get_current_user() )
			);

		}

		/**
		 * boniPRESS Gateway Settings
		 * @since 1.0
		 * @version 1.0
		 */
		function gateway_settings_box( $settings ) {

			global $mp;

			$settings = get_option( 'mp_settings' );
			$bonipress   = bonipress();
			$name     = bonipress_label( true );

			$settings['gateways']['bonipress'] = shortcode_atts( array(
				'name'                 => $name . ' ' . $bonipress->template_tags_general( __( '%_singular% Guthaben', 'bonipress_market' ) ),
				'logo'                 => $this->method_button_img_url,
				'type'                 => BONIPRESS_DEFAULT_TYPE_KEY,
				'log_template'         => __( 'Zahlung für Bestellung: #%order_id%', 'bonipress_market' ),
				'exchange'             => 1,
				'profit_share_percent' => 0,
				'profit_share_log'     => __( 'Produktverkauf: %post_title%', 'bonipress_market' ),
				'instructions'         => __( 'Zahle mit Deinem Kontostand.', 'bonipress_market' ),
				'confirmation'         => __( 'Der Gesamtbetrag wurde von Deinem Konto abgebucht. Dein aktueller Kontostand beträgt: %balance_f%', 'bonipress_market' ),
				'lowfunds'             => __( 'Unzureichendes Guthaben.', 'bonipress' ),
				'visitors'             => __( 'Du musst angemeldet sein, um mit %_plural% bezahlen zu können. Bitte <a href="%login_url_here%">anmelden</a>.', 'bonipress_market' ),
				'email'                => $settings['email']['new_order_txt']
			), ( ( array_key_exists( 'bonipress', $settings['gateways'] ) ) ? $settings['gateways']['bonipress'] : array() ) );

?>
<div id="mp_bonipress_payments" class="postbox mp-pages-msgs">
	<h3 class="handle"><span><?php printf( __( '%s Einstellungen', 'bonipress_market' ), BONIPRESS_DEFAULT_LABEL ); ?></span></h3>
	<div class="inside">
		<span class="description"><?php echo sprintf( __( 'Lasse Deine Benutzer Artikel in ihrem Warenkorb mit ihrem %s Konto bezahlen. Hinweis! Für dieses Gateway müssen Deine Benutzer beim Kauf angemeldet sein!', 'bonipress_market' ), $name ); ?></span>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="bonipress-method-name"><?php _e( 'Methodenname', 'bonipress_market' ); ?></label></th>
				<td>
					<span class="description"><?php _e( 'Gib einen öffentlichen Namen für diese Zahlungsmethode ein, der den Benutzern angezeigt wird - Kein HTML', 'bonipress_market' ); ?></span>
					<p><input value="<?php echo esc_attr( $settings['gateways']['bonipress']['name'] ); ?>" style="width: 100%;" name="mp[gateways][bonipress][name]" id="bonipress-method-name" type="text" /></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bonipress-method-logo"><?php _e( 'Gateway Logo URL', 'bonipress_market' ); ?></label></th>
				<td>
					<p><input value="<?php echo esc_attr( $settings['gateways']['bonipress']['logo'] ); ?>" style="width: 100%;" name="mp[gateways][bonipress][logo]" id="bonipress-method-logo" type="text" /></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bonipress-method-type"><?php _e( 'Punkttyp', 'bonipress_market' ); ?></label></th>
				<td>
					<?php bonipress_types_select_from_dropdown( 'mp[gateways][bonipress][type]', 'bonipress-method-type', $settings['gateways']['bonipress']['type'] ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bonipress-log-template"><?php _e( 'Protokollvorlage', 'bonipress_market' ); ?></label></th>
				<td>
					<span class="description"><?php echo $bonipress->available_template_tags( array( 'general' ), '%order_id%, %order_link%' ); ?></span>
					<p><input value="<?php echo esc_attr( $settings['gateways']['bonipress']['log_template'] ); ?>" style="width: 100%;" name="mp[gateways][bonipress][log_template]" id="bonipress-log-template" type="text" /></p>
				</td>
			</tr>
<?php

				// Exchange rate
				if ( $this->use_exchange() ) :

					$exchange_desc = __( 'Wie viel ist 1 %_singular% in %currency% wert?', 'bonipress_market' );
					$exchange_desc = $bonipress->template_tags_general( $exchange_desc );
					$exchange_desc = str_replace( '%currency%', $settings['currency'], $exchange_desc );

?>
			<tr>
				<th scope="row"><label for="bonipress-exchange-rate"><?php _e( 'Wechselkurs', 'bonipress_market' ); ?></label></th>
				<td>
					<span class="description"><?php echo $exchange_desc; ?></span>
					<p><input value="<?php echo esc_attr( $settings['gateways']['bonipress']['exchange'] ); ?>" size="8" name="mp[gateways][bonipress][exchange]" id="bonipress-exchange-rate" type="text" /></p>
				</td>
			</tr>
<?php

				endif;

?>
			<tr>
				<td colspan="2"><h4><?php _e( 'Gewinnbeteiligung', 'bonipress' ); ?></h4></td>
			</tr>
			<tr>
				<th scope="row"><label for="bonipress-profit-sharing"><?php _e( 'Percentage', 'bonipress_market' ); ?></label></th>
				<td>
					<span class="description"><?php _e( 'Option, Verkäufe mit dem Produktbesitzer zu teilen. Verwende Null zum Deaktivieren.', 'bonipress_market' ); ?></span>
					<p><input value="<?php echo esc_attr( $settings['gateways']['bonipress']['profit_share_percent'] ); ?>" size="8" name="mp[gateways][bonipress][profit_share_percent]" id="bonipress-profit-sharing" type="text" /> %</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bonipress-profit-sharing-log"><?php _e( 'Protokollvorlage', 'bonipress_market' ); ?></label></th>
				<td>
					<span class="description"><?php echo $bonipress->available_template_tags( array( 'general', 'post' ) ); ?></span>
					<p><input value="<?php echo esc_attr( $settings['gateways']['bonipress']['profit_share_log'] ); ?>" style="width: 100%;" name="mp[gateways][bonipress][profit_share_log]" id="bonipress-profit-sharing-log" type="text" /></p>
				</td>
			</tr>
			<tr>
				<td colspan="2"><h4><?php _e( 'Mitteilungen', 'bonipress' ); ?></h4></td>
			</tr>
			<tr>
				<th scope="row"><label for="bonipress-lowfunds"><?php _e( 'Unzureichendes Guthaben', 'bonipress_market' ); ?></label></th>
				<td>
					<span class="description"><?php _e( 'Meldung, die anzeigt, wann der Benutzer dieses Gateway nicht verwenden kann.', 'bonipress_market' ); ?></span>
					<p><input type="text" name="mp[gateways][bonipress][lowfunds]" id="bonipress-lowfunds" style="width: 100%;" value="<?php echo esc_attr( $settings['gateways']['bonipress']['lowfunds'] ); ?>"><br />
					<span class="description"><?php echo $bonipress->available_template_tags( array( 'general' ) ); ?></span></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bonipress-visitors"><?php _e( 'Besucher', 'bonipress_market' ); ?></label></th>
				<td>
					<span class="description"><?php _e( 'Nachricht, die Käufern angezeigt werden soll, die nicht angemeldet sind.', 'bonipress_market' ); ?></span>
					<p><input type="text" name="mp[gateways][bonipress][visitors]" id="bonipress-visitors" style="width: 100%;" value="<?php echo esc_attr( $settings['gateways']['bonipress']['visitors'] ); ?>"><br />
					<span class="description"><?php echo $bonipress->available_template_tags( array( 'general' ) ); ?></span></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bonipress-instructions"><?php _e( 'Benutzeranleitung', 'bonipress_market' ); ?></label></th>
				<td>
					<span class="description"><?php _e( 'Informationen, die den Benutzern vor der Zahlung angezeigt werden sollen.', 'bonipress_market' ); ?></span>
					<p><?php wp_editor( $settings['gateways']['bonipress']['instructions'] , 'bonipressinstructions', array( 'textarea_name' => 'mp[gateways][bonipress][instructions]' ) ); ?><br />
					<span class="description"><?php echo $bonipress->available_template_tags( array( 'general' ), '%balance% or %balance_f%' ); ?></span></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bonipress-confirmation"><?php _e( 'Bestätigungsinformationen', 'bonipress_market' ); ?></label></th>
				<td>
					<span class="description"><?php _e( 'Informationen, die auf der Bestellbestätigungsseite angezeigt werden sollen. - HTML erlaubt', 'bonipress_market' ); ?></span>
					<p><?php wp_editor( $settings['gateways']['bonipress']['confirmation'], 'bonipressconfirmation', array( 'textarea_name' => 'mp[gateways][bonipress][confirmation]' ) ); ?><br />
					<span class="description"><?php echo $bonipress->available_template_tags( array( 'general' ), '%balance% or %balance_f%' ); ?></span></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bonipress-email"><?php _e( 'Bestellbestätigungs-E-Mail', 'bonipress_market' ); ?></label></th>
				<td>
					<span class="description"><?php echo sprintf( __( 'Dies ist der E-Mail-Text, der an diejenigen gesendet werden soll, die% s ausgecheckt haben. Es überschreibt die Standard-Bestell-Checkout-E-Mail. Diese Codes werden durch Bestelldetails ersetzt: CUSTOMERNAME, ORDERID, ORDERINFO, SHIPPINGINFO, PAYMENTINFO, TOTAL, TRACKINGURL. Kein HTML erlaubt.', 'bonipress_market' ), $name ); ?></span>
					<p><textarea id="bonipress-email" name="mp[gateways][bonipress][email]" class="mp_emails_txt"><?php echo esc_textarea( $settings['gateways']['bonipress']['email'] ); ?></textarea></p>
					<span class="description"><?php echo $bonipress->available_template_tags( array( 'general' ), '%balance% or %balance_f%' ); ?></span>
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

			if ( ! array_key_exists( 'bonipress', $settings['gateways'] ) ) return $settings;

			// Name (no html)
			$settings['gateways']['bonipress']['name']                 = stripslashes( wp_filter_nohtml_kses( $settings['gateways']['bonipress']['name'] ) );

			// Log Template (no html)
			$settings['gateways']['bonipress']['log_template']         = stripslashes( wp_filter_nohtml_kses( $settings['gateways']['bonipress']['log_template'] ) );
			$settings['gateways']['bonipress']['type']                 = sanitize_text_field( $settings['gateways']['bonipress']['type'] );
			$settings['gateways']['bonipress']['logo']                 = stripslashes( wp_filter_nohtml_kses( $settings['gateways']['bonipress']['logo'] ) );

			// Exchange rate (if used)
			if ( $this->use_exchange() ) {

				// Decimals must start with a zero
				if ( $settings['gateways']['bonipress']['exchange'] != 1 && substr( $settings['gateways']['bonipress']['exchange'], 0, 1 ) != '0' )
					$settings['gateways']['bonipress']['exchange'] = (float) '0' . $settings['gateways']['bonipress']['exchange'];

				// Decimal seperator must be punctuation and not comma
				$settings['gateways']['bonipress']['exchange'] = str_replace( ',', '.', $settings['gateways']['bonipress']['exchange'] );

			}
			else
				$settings['gateways']['bonipress']['exchange'] = 1;
		
			$settings['gateways']['bonipress']['profit_share_percent'] = stripslashes( sanitize_text_field( $settings['gateways']['bonipress']['profit_share_percent'] ) );
			$settings['gateways']['bonipress']['profit_share_log']     = stripslashes( wp_filter_nohtml_kses( $settings['gateways']['bonipress']['profit_share_log'] ) );
		
			$settings['gateways']['bonipress']['lowfunds']             = stripslashes( wp_filter_post_kses( $settings['gateways']['bonipress']['lowfunds'] ) );
			$settings['gateways']['bonipress']['visitors']             = stripslashes( wp_filter_post_kses( $settings['gateways']['bonipress']['visitors'] ) );

			$settings['gateways']['bonipress']['instructions']         = wp_kses_post( $settings['gateways']['bonipress']['instructions'] );
			$settings['gateways']['bonipress']['confirmation']         = wp_kses_post( $settings['gateways']['bonipress']['confirmation'] );

			// Email (no html)
			$settings['gateways']['bonipress']['email']                = stripslashes( wp_filter_nohtml_kses( $settings['gateways']['bonipress']['email'] ) );

			return $settings;

		}

	}
endif;
