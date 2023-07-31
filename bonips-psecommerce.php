<?php
/**
 * Plugin Name: BoniPress für PSeCommerce
 * Plugin URI: http://bonips.me
 * Description: Lasse Benutzer mit BoniPress-Punkten in Deinem PSeCommerce-Shop bezahlen.
 * Version: 1.3
 * Tags: bonips, psecommerce, gateway, payment
 * Author: DerN3rd
 * Author URI: https://n3rds.work
 * Author Email: support@bonips.me
 * Requires at least: WP 4.0
 * Tested up to: WP 5.6
 * Text Domain: bonips_market
 * Domain Path: /lang
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

require 'psource/psource-plugin-update/psource-plugin-updater.php';
$MyUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
	'https://n3rds.work//wp-update-server/?action=get_metadata&slug=bonips-psecommerce', 
	__FILE__, 
	'bonips-psecommerce' 
);

if ( ! class_exists( 'boniPS_PSeCommerce_Plugin' ) ) :
	final class boniPS_PSeCommerce_Plugin {

		// Plugin Version
		public $version             = '1.3';

		// Instnace
		protected static $_instance = NULL;

		// Current session
		public $session             = NULL;

		public $slug                = '';
		public $domain              = '';
		public $plugin              = NULL;
		public $plugin_name         = '';
		

		/**
		 * Setup Instance
		 * @since 1.0
		 * @version 1.0
		 */
		public static function instance() {
			if ( is_null( self::$_instance ) ) {
				self::$_instance = new self();
			}
			return self::$_instance;
		}

		/**
		 * Not allowed
		 * @since 1.0
		 * @version 1.0
		 */
		public function __clone() { _doing_it_wrong( __FUNCTION__, 'Cheatin&#8217; huh?', '1.0' ); }

		/**
		 * Not allowed
		 * @since 1.0
		 * @version 1.0
		 */
		public function __wakeup() { _doing_it_wrong( __FUNCTION__, 'Cheatin&#8217; huh?', '1.0' ); }

		/**
		 * Define
		 * @since 1.0
		 * @version 1.0
		 */
		private function define( $name, $value, $definable = true ) {
			if ( ! defined( $name ) )
				define( $name, $value );
		}

		/**
		 * Require File
		 * @since 1.0
		 * @version 1.0
		 */
		public function file( $required_file ) {
			if ( file_exists( $required_file ) )
				require_once $required_file;
		}

		/**
		 * Construct
		 * @since 1.0
		 * @version 1.0
		 */
		public function __construct() {

			$this->slug        = 'bonips-psecommerce';
			$this->plugin      = plugin_basename( __FILE__ );
			$this->domain      = 'bonips_market';
			$this->plugin_name = 'boniPS for PSeCommerce';

			$this->define_constants();
			$this->includes();
			$this->plugin_updates();

			register_activation_hook( BONIPS_PSECOMMERCE, 'bonips_psecommerce_activate_plugin' );

			add_action( 'bonips_init',                                array( $this, 'load_textdomain' ) );
			add_action( 'bonips_all_references',                      array( $this, 'add_badge_support' ) );

			// Payment gateway
			add_action( 'mp_load_gateway_plugins',                    'bonips_psecommerce_load_gateway' );
			add_action( 'psecommerce/load_plugins/mp_include',        'bonips_psecommerce_load_gateway' );
			add_filter( 'mp_gateway_api/get_gateways',                array( $this, 'load_gateway' ) );

			add_filter( 'mp_format_currency',                         array( $this, 'adjust_currency_format' ), 10, 4 );

			add_filter( 'bonips_parse_log_entry_psecommerce_payment', 'bonips_psecommerce_parse_log', 90, 2 );
			add_filter( 'bonips_email_before_send',                   'bonips_psecommerce_parse_email', 20 );

			// Rewards
			add_action( 'bonips_load_hooks',                          'bonips_psecommerce_load_rewards', 79 );

		}

		/**
		 * Define Constants
		 * @since 1.0
		 * @version 1.0.1
		 */
		public function define_constants() {

			$this->define( 'BONIPS_MARKET_VERSION',    $this->version );
			$this->define( 'BONIPS_MARKET_SLUG',       $this->slug );

			$this->define( 'BONIPS_SLUG',              'bonips' );
			$this->define( 'BONIPS_DEFAULT_LABEL',     'BoniPress' );

			$this->define( 'BONIPS_PSECOMMERCE',        __FILE__ );
			$this->define( 'BONIPS_MARKET_ROOT_DIR',    plugin_dir_path( BONIPS_PSECOMMERCE ) );
			$this->define( 'BONIPS_MARKET_CLASSES_DIR', BONIPS_MARKET_ROOT_DIR . 'classes/' );
			$this->define( 'BONIPS_MARKET_INC_DIR',     BONIPS_MARKET_ROOT_DIR . 'includes/' );

		}

		/**
		 * Includes
		 * @since 1.0
		 * @version 1.0
		 */
		public function includes() {

			$this->file( BONIPS_MARKET_INC_DIR . 'psecommerce-gateway.php' );
			$this->file( BONIPS_MARKET_INC_DIR . 'psecommerce-rewards.php' );

		}

		/**
		 * Includes
		 * @since 1.0.1
		 * @version 1.0
		 */
		public function load_gateway( $list ) {

			if ( ! array_key_exists( BONIPS_SLUG, $list ) && version_compare( MP_VERSION, '1.5', '>=' ) ) {

				$global = ( is_multisite() && bonips_centralize_log() ) ? true : false;
				$list[ BONIPS_SLUG ] = array( 'MP_Gateway_boniPS_New', BONIPS_DEFAULT_LABEL, $global, false );

			}

			return $list;

		}

		/**
		 * Adjust Currency Format
		 * @since 1.1
		 * @version 1.0
		 */
		public function adjust_currency_format( $formatted, $currency, $symbol, $amount ) {

			if ( $currency == 'POINTS' || ( function_exists( 'bonips_point_type_exists' ) && bonips_point_type_exists( $currency ) ) ) {

				$point_type = mp_get_setting( "gateways->" . BONIPS_SLUG . "->{type}", BONIPS_DEFAULT_TYPE_KEY );
				$bonips     = bonips( $point_type );

				return $bonips->format_creds( $amount );

			}

			return $formatted;

		}

		/**
		 * Load Textdomain
		 * @since 1.0
		 * @version 1.0
		 */
		public function load_textdomain() {

			// Load Translation
			$locale = apply_filters( 'plugin_locale', get_locale(), $this->domain );

			load_textdomain( $this->domain, WP_LANG_DIR . '/' . $this->slug . '/' . $this->domain . '-' . $locale . '.mo' );
			load_plugin_textdomain( $this->domain, false, dirname( $this->plugin ) . '/lang/' );

		}

		/**
		 * Plugin Updates
		 * @since 1.0
		 * @version 1.0
		 */
		public function plugin_updates() {

			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_plugin_update' ), 390 );
			add_filter( 'plugins_api',                           array( $this, 'plugin_api_call' ), 390, 3 );
			add_filter( 'plugin_row_meta',                       array( $this, 'plugin_view_info' ), 390, 3 );

		}

		/**
		 * Add Badge Support
		 * @since 1.0
		 * @version 1.0
		 */
		public function add_badge_support( $references ) {

			if ( ! class_exists( 'PSeCommerce' ) ) return $references;

			$references['psecommerce_payment'] = __( 'Shop Zahlung (PSeCommerce)', 'bonips_market' );
			$references['psecommerce_sale']    = __( 'Shop Angebot (PSeCommerce)', 'bonips_market' );
			$references['psecommerce_reward']  = __( 'Shop Belohnung (PSeCommerce)', 'bonips_market' );

			return $references;

		}

		/**
		 * Plugin Update Check
		 * @since 1.0
		 * @version 1.0
		 */
		public function check_for_plugin_update( $checked_data ) {

			global $wp_version;

			if ( empty( $checked_data->checked ) )
				return $checked_data;

			$args = array(
				'slug'    => $this->slug,
				'version' => $this->version,
				'site'    => site_url()
			);
			$request_string = array(
				'body'       => array(
					'action'     => 'version', 
					'request'    => serialize( $args ),
					'api-key'    => md5( get_bloginfo( 'url' ) )
				),
				'user-agent' => 'WordPress/' . $wp_version . '; ' . get_bloginfo( 'url' )
			);

			// Start checking for an update
			$response = wp_remote_post( $this->update_url, $request_string );

			if ( ! is_wp_error( $response ) ) {

				$result = maybe_unserialize( $response['body'] );

				if ( is_object( $result ) && ! empty( $result ) )
					$checked_data->response[ $this->slug . '/' . $this->slug . '.php' ] = $result;

			}

			return $checked_data;

		}

		/**
		 * Plugin View Info
		 * @since 1.0
		 * @version 1.0
		 */
		public function plugin_view_info( $plugin_meta, $file, $plugin_data ) {

			if ( $file != $this->plugin ) return $plugin_meta;

			$plugin_meta[] = sprintf( '<a href="%s" class="thickbox" aria-label="%s" data-title="%s">%s</a>',
				esc_url( network_admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . $this->slug .
				'&TB_iframe=true&width=600&height=550' ) ),
				esc_attr( __( 'Weitere Informationen zu diesem Plugin', 'bonips_market' ) ),
				esc_attr( $this->plugin_name ),
				__( 'Details anzeigen', 'bonips_market' )
			);

			return $plugin_meta;

		}

		/**
		 * Plugin New Version Update
		 * @since 1.0
		 * @version 1.0
		 */
		public function plugin_api_call( $result, $action, $args ) {

			global $wp_version;

			if ( ! isset( $args->slug ) || ( $args->slug != $this->slug ) )
				return $result;

			// Get the current version
			$args = array(
				'slug'    => $this->slug,
				'version' => $this->version,
				'site'    => site_url()
			);
			$request_string = array(
				'body'       => array(
					'action'     => 'info', 
					'request'    => serialize( $args ),
					'api-key'    => md5( get_bloginfo( 'url' ) )
				),
				'user-agent' => 'WordPress/' . $wp_version . '; ' . get_bloginfo( 'url' )
			);

			$request = wp_remote_post( $this->update_url, $request_string );

			if ( ! is_wp_error( $request ) )
				$result = maybe_unserialize( $request['body'] );

			return $result;

		}

	}
endif;

function bonips_for_psecommerce_plugin() {
	return boniPS_PSeCommerce_Plugin::instance();
}
bonips_for_psecommerce_plugin();

/**
 * Plugin Activation
 * @since 1.0
 * @version 1.0
 */
function bonips_psecommerce_activate_plugin() {

	global $wpdb;

	$message = array();

	// WordPress check
	$wp_version = $GLOBALS['wp_version'];
	if ( version_compare( $wp_version, '4.0', '<' ) )
		$message[] = __( 'Diese BoniPress Erweiterung erfordert WordPress 4.0 oder höher. Erkannte Version: ', 'bonips_market' ) . ' ' . $wp_version;

	// PHP check
	$php_version = phpversion();
	if ( version_compare( $php_version, '5.3', '<' ) )
		$message[] = __( 'Diese BoniPress Erweiterung erfordert PHP 5.3 oder höher. Erkannte Version: ', 'bonips_market' ) . ' ' . $php_version;

	// SQL check
	$sql_version = $wpdb->db_version();
	if ( version_compare( $sql_version, '5.0', '<' ) )
		$message[] = __( 'Diese BoniPress Erweiterung erfordert SQL 5.0 oder höher. Erkannte Version: ', 'bonips_market' ) . ' ' . $sql_version;

	// boniPS Check
	if ( defined( 'boniPS_VERSION' ) && version_compare( boniPS_VERSION, '1.7', '<' ) )
		$message[] = __( 'Diese Erweiterung erfordert BoniPress 1.7 oder höher. Ältere Versionen von BoniPress bieten integrierte Unterstützung für PSeCommerce, wodurch dieses Plugin überflüssig wird.', 'bonips_market' );

	// Not empty $message means there are issues
	if ( ! empty( $message ) ) {

		$error_message = implode( "\n", $message );
		die( __( 'Leider erreicht Deine WordPress-Installation nicht die Mindestanforderungen für die Ausführung dieser Erweiterung. Folgende Fehler wurden angegeben:', 'bonips_market' ) . "\n" . $error_message );

	}

}
