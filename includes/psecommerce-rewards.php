<?php
if ( ! defined( 'BONIPS_MARKET_VERSION' ) ) exit;

/**
 * PSeCommerce Setup
 * @since 1.0
 * @version 1.0
 */
if ( ! function_exists( 'bonips_psecommerce_load_rewards' ) ) :
	function bonips_psecommerce_load_rewards() {

		if ( ! class_exists( 'PSeCommerce' ) ) return;

		add_action( 'add_meta_boxes_product', 'bonips_markpress_add_product_metabox' );
		add_action( 'save_post',              'bonips_markpress_save_reward_settings' );
		add_action( 'mp_order_paid',          'bonips_markpress_payout_rewards' );

	}
endif;

/**
 * Add Reward Metabox
 * @since 1.0
 * @version 1.0
 */
if ( ! function_exists( 'bonips_markpress_add_product_metabox' ) ) :
	function bonips_markpress_add_product_metabox() {

		add_meta_box(
			'bonips_markpress_sales_setup',
			bonips_label(),
			'bonips_markpress_product_metabox',
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
if ( ! function_exists( 'bonips_markpress_product_metabox' ) ) :
	function bonips_markpress_product_metabox( $post ) {

		if ( ! bonips_is_admin() ) return;

		$types = bonips_get_types();
		$prefs = (array) get_post_meta( $post->ID, 'bonips_reward', true );

		foreach ( $types as $type => $label ) {
			if ( ! isset( $prefs[ $type ] ) )
				$prefs[ $type ] = '';
		}

		$count = 0;
		$cui   = get_current_user_id();
		foreach ( $types as $type => $label ) {

			$count ++;
			$bonips = bonips( $type );

			if ( ! $bonips->can_edit_creds( $cui ) ) continue;

?>
<p class="<?php if ( $count == 1 ) echo 'first'; ?>"><label for="bonips-reward-purchase-with-<?php echo $type; ?>"><input class="toggle-bonips-reward" data-id="<?php echo $type; ?>" <?php if ( $prefs[ $type ] != '' ) echo 'checked="checked"'; ?> type="checkbox" name="bonips_reward[<?php echo $type; ?>][use]" id="bonips-reward-purchase-with-<?php echo $type; ?>" value="<?php echo $prefs[ $type ]; ?>" /> <?php echo $bonips->template_tags_general( __( 'Belohnen mit %plural%', 'bonips' ) ); ?></label></p>
<div class="bonips-mark-wrap" id="reward-<?php echo $type; ?>" style="display:<?php if ( $prefs[ $type ] == '' ) echo 'none'; else echo 'block'; ?>">
	<label><?php echo $bonips->plural(); ?></label> <input type="text" size="8" name="bonips_reward[<?php echo $type; ?>][amount]" value="<?php echo esc_attr( $prefs[ $type ] ); ?>" placeholder="<?php echo $bonips->zero(); ?>" />
</div>
<?php

		}

?>
<script type="text/javascript">
jQuery(function($) {

	$( '.toggle-bonips-reward' ).click(function(){
		var target = $(this).attr( 'data-id' );
		$( '#reward-' + target ).toggle();
	});

});
</script>
<style type="text/css">
#bonips_markpress_sales_setup .inside { margin: 0; padding: 0; }
#bonips_markpress_sales_setup .inside > p { padding: 12px; margin: 0; border-top: 1px solid #ddd; }
#bonips_markpress_sales_setup .inside > p.first { border-top: none; }
#bonips_markpress_sales_setup .inside .bonips-mark-wrap { padding: 6px 12px; line-height: 27px; text-align: right; border-top: 1px solid #ddd; background-color: #F5F5F5; }
#bonips_markpress_sales_setup .inside .bonips-mark-wrap label { display: block; font-weight: bold; float: left; }
#bonips_markpress_sales_setup .inside .bonips-mark-wrap input { width: 50%; }
#bonips_markpress_sales_setup .inside .bonips-mark-wrap p { margin: 0; padding: 0 12px; font-style: italic; text-align: center; }
</style>
<?php

	}
endif;

/**
 * Save Reward Setup
 * @since 1.0
 * @version 1.0
 */
if ( ! function_exists( 'bonips_markpress_save_reward_settings' ) ) :
	function bonips_markpress_save_reward_settings( $post_id ) {

		if ( ! isset( $_POST['bonips_reward'] ) ) return;

		$new_settings = array();
		foreach ( $_POST['bonips_reward'] as $type => $prefs ) {

			$bonips = bonips( $type );
			if ( isset( $prefs['use'] ) )
				$new_settings[ $type ] = $bonips->number( $prefs['amount'] );

		}

		update_post_meta( $post_id, 'bonips_reward', $new_settings );

	}
endif;

/**
 * Payout Rewards
 * @since 1.0
 * @version 1.0
 */
if ( ! function_exists( 'bonips_markpress_payout_rewards' ) ) :
	function bonips_markpress_payout_rewards( $order ) {

		// Payment info
		$payment_info = get_post_meta( $order->ID, 'mp_payment_info', true );
		if ( ! isset( $payment_info['gateway_private_name'] ) || ( $payment_info['gateway_private_name'] == BONIPS_DEFAULT_LABEL && apply_filters( 'bonips_psecommerce_reward_bonips_payment', false ) === false ) )
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
		$types = bonips_get_types();

		// Loop
		foreach ( $types as $type => $label ) {

			// Load type
			$bonips = bonips( $type );

			// Check for exclusions
			if ( $bonips->exclude_user( $user_id ) ) continue;

			// Calculate reward
			$reward = $bonips->zero();
			foreach ( $order->mp_cart_info as $product_id => $variations ) {
				foreach ( $variations as $variation => $data ) {

					$prefs = (array) get_post_meta( (int) $product_id, 'bonips_reward', true );
					if ( isset( $prefs[ $type ] ) && $prefs[ $type ] != '' )
						$reward = ( $reward + ( $prefs[ $type ] * $data['quantity'] ) );

				}
			}

			// Award
			if ( $reward != $bonips->zero() ) {

				// Execute
				$bonips->add_creds(
					'psecommerce_reward',
					$order->user_id,
					apply_filters( 'bonips_psecommerce_reward_log', '%plural% Belohnung für den Kauf im Shop', $order_id, $type ),
					$log,
					$order->ID,
					array( 'ref_type' => 'post' ),
					$type
				);

			}

		}

	}
endif;
