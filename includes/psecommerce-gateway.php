<?php
if ( ! defined( 'BONIPS_MARKET_VERSION' ) ) exit;

/**
 * PSeCommerce Gateway
 * @since 1.0
 * @version 1.2
 */
if ( ! function_exists( 'bonips_psecommerce_load_gateway' ) ) :
	function bonips_psecommerce_load_gateway() {

		if ( ! defined( 'BONIPS_SLUG' ) ) return;

		if ( version_compare( MP_VERSION, '1.5', '>=' ) ) {

			require_once BONIPS_MARKET_CLASSES_DIR . 'class.boniPS-Gateway3.php';

		}
		else {

			require_once BONIPS_MARKET_CLASSES_DIR . 'class.boniPS-Gateway.php';
			mp_register_gateway_plugin( 'MP_Gateway_boniPS', BONIPS_SLUG, BONIPS_DEFAULT_LABEL );

		}

	}
endif;

/**
 * Filter the boniPS Log
 * Parses the %order_id% and %order_link% template tags.
 * @since 1.0
 * @version 1.2
 */
if ( ! function_exists( 'bonips_psecommerce_parse_log' ) ) :
	function bonips_psecommerce_parse_log( $content, $log_entry ) {

		// Prep
		global $mp;

		$bonips   = bonips( $log_entry->ctype );
		$order    = get_post( $log_entry->ref_id );
		if ( ! isset( $order->post_title ) ) {

			$content  = str_replace( '%order_id%', $log_entry->ref_id, $content );

			return $content;

		}

		$order_id = $order->post_title;
		$user_id  = get_current_user_id();

		// Order ID
		$content  = str_replace( '%order_id%', $order_id, $content );

		// Link to order if we can edit plugin or are the user who made the order
		if ( $user_id == $log_entry->user_id || $bonips->can_edit_plugin( $user_id ) ) {
			$url        = trailingslashit( mp_store_page_url( 'order_status', false ) . $order_id );
			$track_link = '<a href="' . $url . '">#' . $order->post_title . '</a>';
			$content    = str_replace( '%order_link%', $track_link, $content );
		}
		else {
			$content    = str_replace( '%order_link%', '#' . $order_id, $content );
		}

		return $content;

	}
endif;


/**
 * Parse Email Notice
 * @since 1.0
 * @version 1.0
 */
if ( ! function_exists( 'bonips_psecommerce_parse_email' ) ) :
	function bonips_psecommerce_parse_email( $email ) {

		if ( $email['request']['ref'] == 'psecommerce_payment' ) {

			$order = get_post( (int) $email['request']['ref_id'] );
			if ( isset( $order->id ) ) {

				$track_link = '<a href="' . mp_orderstatus_link( false, true ) . $order_id . '/' . '">#' . $order->post_title . '/' . '</a>';
				$content    = str_replace( '%order_id%', $order->post_title, $email['request']['entry'] );

				$email['request']['entry'] = str_replace( '%order_link%', $track_link, $content );

			}

		}

		return $email;

	}
endif;
