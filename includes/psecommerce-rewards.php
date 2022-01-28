<?php
if ( ! defined( 'BONIPRESS_MARKET_VERSION' ) ) exit;

/**
 * PSeCommerce Setup
 * @since 1.0
 * @version 1.0
 */
if ( ! function_exists( 'bonipress_psecommerce_load_rewards' ) ) :
	function bonipress_psecommerce_load_rewards() {

		if ( ! class_exists( 'PSeCommerce' ) ) return;

		add_action( 'add_meta_boxes_product', 'bonipress_markpress_add_product_metabox' );
		add_action( 'save_post',              'bonipress_markpress_save_reward_settings' );
		add_action( 'mp_order_paid',          'bonipress_markpress_payout_rewards' );

	}
endif;

/**
 * Add Reward Metabox
 * @since 1.0
 * @version 1.0
 */
if ( ! function_exists( 'bonipress_markpress_add_product_metabox' ) ) :
	function bonipress_markpress_add_product_metabox() {

		add_meta_box(
			'bonipress_markpress_sales_setup',
			bonipress_label(),
			'bonipress_markpress_product_metabox',
			'product',
			'side',
			'high'
		);

	}
endif;

/**
 * Product Metabox
 * @since 1.0
 * @version 1.0
 */
if ( ! function_exists( 'bonipress_markpress_product_metabox' ) ) :
	function bonipress_markpress_product_metabox( $post ) {

		if ( ! bonipress_is_admin() ) return;

		$types = bonipress_get_types();
		$prefs = (array) get_post_meta( $post->ID, 'bonipress_reward', true );

		foreach ( $types as $type => $label ) {
			if ( ! isset( $prefs[ $type ] ) )
				$prefs[ $type ] = '';
		}

		$count = 0;
		$cui   = get_current_user_id();
		foreach ( $types as $type => $label ) {

			$count ++;
			$bonipress = bonipress( $type );

			if ( ! $bonipress->can_edit_creds( $cui ) ) continue;

?>
<p class="<?php if ( $count == 1 ) echo 'first'; ?>"><label for="bonipress-reward-purchase-with-<?php echo $type; ?>"><input class="toggle-bonipress-reward" data-id="<?php echo $type; ?>" <?php if ( $prefs[ $type ] != '' ) echo 'checked="checked"'; ?> type="checkbox" name="bonipress_reward[<?php echo $type; ?>][use]" id="bonipress-reward-purchase-with-<?php echo $type; ?>" value="<?php echo $prefs[ $type ]; ?>" /> <?php echo $bonipress->template_tags_general( __( 'Belohnen mit %plural%', 'bonipress' ) ); ?></label></p>
<div class="bonipress-mark-wrap" id="reward-<?php echo $type; ?>" style="display:<?php if ( $prefs[ $type ] == '' ) echo 'none'; else echo 'block'; ?>">
	<label><?php echo $bonipress->plural(); ?></label> <input type="text" size="8" name="bonipress_reward[<?php echo $type; ?>][amount]" value="<?php echo esc_attr( $prefs[ $type ] ); ?>" placeholder="<?php echo $bonipress->zero(); ?>" />
</div>
<?php

		}

?>
<script type="text/javascript">
jQuery(function($) {

	$( '.toggle-bonipress-reward' ).click(function(){
		var target = $(this).attr( 'data-id' );
		$( '#reward-' + target ).toggle();
	});

});
</script>
<style type="text/css">
#bonipress_markpress_sales_setup .inside { margin: 0; padding: 0; }
#bonipress_markpress_sales_setup .inside > p { padding: 12px; margin: 0; border-top: 1px solid #ddd; }
#bonipress_markpress_sales_setup .inside > p.first { border-top: none; }
#bonipress_markpress_sales_setup .inside .bonipress-mark-wrap { padding: 6px 12px; line-height: 27px; text-align: right; border-top: 1px solid #ddd; background-color: #F5F5F5; }
#bonipress_markpress_sales_setup .inside .bonipress-mark-wrap label { display: block; font-weight: bold; float: left; }
#bonipress_markpress_sales_setup .inside .bonipress-mark-wrap input { width: 50%; }
#bonipress_markpress_sales_setup .inside .bonipress-mark-wrap p { margin: 0; padding: 0 12px; font-style: italic; text-align: center; }
</style>
<?php

	}
endif;

/**
 * Save Reward Setup
 * @since 1.0
 * @version 1.0
 */
if ( ! function_exists( 'bonipress_markpress_save_reward_settings' ) ) :
	function bonipress_markpress_save_reward_settings( $post_id ) {

		if ( ! isset( $_POST['bonipress_reward'] ) ) return;

		$new_settings = array();
		foreach ( $_POST['bonipress_reward'] as $type => $prefs ) {

			$bonipress = bonipress( $type );
			if ( isset( $prefs['use'] ) )
				$new_settings[ $type ] = $bonipress->number( $prefs['amount'] );

		}

		update_post_meta( $post_id, 'bonipress_reward', $new_settings );

	}
endif;

/**
 * Payout Rewards
 * @since 1.0
 * @version 1.0
 */
if ( ! function_exists( 'bonipress_markpress_payout_rewards' ) ) :
	function bonipress_markpress_payout_rewards( $order ) {

		// Payment info
		$payment_info = get_post_meta( $order->ID, 'mp_payment_info', true );
		if ( ! isset( $payment_info['gateway_private_name'] ) || ( $payment_info['gateway_private_name'] == BONIPRESS_DEFAULT_LABEL && apply_filters( 'bonipress_psecommerce_reward_bonipress_payment', false ) === false ) )
			return;

		// Get buyer ID
		global $wpdb;

		$meta_id = 'mp_order_history';
		if ( is_multisite() ) {
			global $blog_id;
			$meta_id = 'mp_order_history_' . $blog_id;
		}

		// Get buyer
		$user_id = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s", $meta_id, '%s:2:"id";i:' . $order->ID . ';%' ) );
		if ( $user_id === NULL && ! is_user_logged_in() ) return;
		elseif ( $user_id === NULL ) $user_id = get_current_user_id();

		// Get point types
		$types = bonipress_get_types();

		// Loop
		foreach ( $types as $type => $label ) {

			// Load type
			$bonipress = bonipress( $type );

			// Check for exclusions
			if ( $bonipress->exclude_user( $user_id ) ) continue;

			// Calculate reward
			$reward = $bonipress->zero();
			foreach ( $order->mp_cart_info as $product_id => $variations ) {
				foreach ( $variations as $variation => $data ) {

					$prefs = (array) get_post_meta( (int) $product_id, 'bonipress_reward', true );
					if ( isset( $prefs[ $type ] ) && $prefs[ $type ] != '' )
						$reward = ( $reward + ( $prefs[ $type ] * $data['quantity'] ) );

				}
			}

			// Award
			if ( $reward != $bonipress->zero() ) {

				// Execute
				$bonipress->add_creds(
					'psecommerce_reward',
					$order->user_id,
					apply_filters( 'bonipress_psecommerce_reward_log', '%plural% Belohnung für den Kauf im Shop', $order_id, $type ),
					$log,
					$order->ID,
					array( 'ref_type' => 'post' ),
					$type
				);

			}

		}

	}
endif;
