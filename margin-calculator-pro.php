<?php
/**
 * Plugin Name: Margin Calculator Pro for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/woocommerce-margin-calculator/
 * Description: Advanced margin calculation and management for WooCommerce products with Quick Edit, per-category thresholds, and detailed statistics
 * Version: 1.4.0
 * Author: Sascom
 * Author URI: https://sascom.pl
 * License: GPL v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: margin-calculator-pro
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Declare HPOS (High-Performance Order Storage) compatibility
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

// Check if WooCommerce is active
if ( ! wcmc_is_woocommerce_active() ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Margin Calculator Pro for WooCommerce', 'margin-calculator-pro' ) . '</strong> ' . esc_html__( 'requires WooCommerce to be installed and active!', 'margin-calculator-pro' ) . '</p></div>';
	} );
	return;
}

/**
 * Check if WooCommerce is active.
 */
function wcmc_is_woocommerce_active() {
	return in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true ) // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		|| ( is_multisite() && array_key_exists( 'woocommerce/woocommerce.php', get_site_option( 'active_sitewide_plugins', array() ) ) );
}

class WC_Margin_Calculator_Pro {

	private static $instance = null;

	// Supported VAT rates worldwide
	private $vat_rates = array(
		'0'  => '0% (No VAT)',
		'5'  => '5%',
		'7'  => '7%',
		'8'  => '8%',
		'10' => '10%',
		'13' => '13%',
		'15' => '15%',
		'16' => '16%',
		'19' => '19%',
		'20' => '20%',
		'21' => '21%',
		'22' => '22%',
		'23' => '23%',
		'24' => '24%',
		'25' => '25%',
		'27' => '27%',
	);

	/**
	 * Minimum margin threshold (0.20 = 20%).
	 */
	const MIN_MARGIN = 0.20;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		// WordPress 4.6+ loads translations automatically from WordPress.org
		$this->init_default_settings();

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		add_action( 'woocommerce_product_options_pricing', array( $this, 'add_purchase_price_field' ) );
		add_action( 'woocommerce_variation_options_pricing', array( $this, 'add_purchase_price_field_variation' ), 10, 3 );

		add_action( 'woocommerce_process_product_meta', array( $this, 'save_purchase_price' ) );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_purchase_price_variation' ), 10, 2 );

		add_filter( 'manage_edit-product_columns', array( $this, 'add_margin_column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'display_margin_column' ), 10, 2 );

		add_action( 'quick_edit_custom_box', array( $this, 'add_quick_edit_field' ), 10, 2 );
		add_action( 'woocommerce_product_quick_edit_save', array( $this, 'save_quick_edit_field' ) );

		add_action( 'woocommerce_product_options_pricing', array( $this, 'display_margin_in_product' ), 30 );
		add_action( 'woocommerce_variation_options_pricing', array( $this, 'display_margin_in_variation' ), 35, 3 );

		add_action( 'wp_ajax_wcmc_get_purchase_price', array( $this, 'ajax_get_purchase_price' ) );

		add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widget' ) );

		add_action( 'woocommerce_process_product_meta', array( $this, 'check_margin_on_save' ), 99 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'check_margin_on_variation_save' ), 99, 2 );

		// ── NEW: Margin on order ──────────────────────────────────────────────
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'display_margin_on_order' ) );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_margin_order_column' ) );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_margin_order_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'display_margin_order_column' ), 10, 2 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'display_margin_order_column' ), 10, 2 );

		// ── NEW: CSV Import ───────────────────────────────────────────────────
		add_action( 'wp_ajax_wcmc_import_csv', array( $this, 'ajax_import_csv' ) );
		add_action( 'wp_ajax_wcmc_export_csv', array( $this, 'ajax_export_csv' ) );

		// ── NEW: Reports ─────────────────────────────────────────────────────
		add_action( 'wp_ajax_wcmc_get_report', array( $this, 'ajax_get_report' ) );
	}

	public function check_margin_on_save( $post_id ) {
		$this->enforce_minimum_margin( $post_id );
	}

	public function check_margin_on_variation_save( $variation_id, $i ) {
		$this->enforce_minimum_margin( $variation_id );
	}

	private function init_default_settings() {
		if ( get_option( 'wcmc_settings' ) === false ) {
			update_option( 'wcmc_settings', array(
				'margin_high'   => 40,
				'margin_medium' => 30,
				'vat_rate'      => 23,
			) );
		}
	}

	public function add_admin_menu() {
		add_submenu_page(
			'woocommerce',
			esc_html__( 'Margin Calculator', 'margin-calculator-pro' ),
			esc_html__( 'Margin Calculator', 'margin-calculator-pro' ),
			'manage_woocommerce',
			'margin-calculator-pro',
			array( $this, 'settings_page' )
		);
		add_submenu_page(
			'woocommerce',
			esc_html__( 'Margin Reports', 'margin-calculator-pro' ),
			esc_html__( 'Margin Reports', 'margin-calculator-pro' ),
			'manage_woocommerce',
			'wcmc-margin-reports',
			array( $this, 'reports_page' )
		);
	}

	public function register_settings() {
		register_setting( 'wcmc_settings_group', 'wcmc_settings', array(
			'sanitize_callback' => array( $this, 'sanitize_settings' ),
		) );
		register_setting( 'wcmc_settings_group', 'wcmc_category_margins', array(
			'sanitize_callback' => array( $this, 'sanitize_category_margins' ),
		) );
	}

	public function sanitize_settings( $input ) {
		$output = array();
		$output['margin_high']   = isset( $input['margin_high'] ) ? absint( $input['margin_high'] ) : 40;
		$output['margin_medium'] = isset( $input['margin_medium'] ) ? absint( $input['margin_medium'] ) : 30;
		$output['vat_rate']      = isset( $input['vat_rate'] ) ? sanitize_text_field( $input['vat_rate'] ) : '23';
		return $output;
	}

	public function sanitize_category_margins( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}
		$output = array();
		foreach ( $input as $cat_id => $values ) {
			$cat_id = absint( $cat_id );
			$output[ $cat_id ] = array(
				'high'   => isset( $values['high'] ) && '' !== $values['high'] ? absint( $values['high'] ) : '',
				'medium' => isset( $values['medium'] ) && '' !== $values['medium'] ? absint( $values['medium'] ) : '',
			);
		}
		return $output;
	}

	public function enqueue_admin_scripts( $hook ) {
		if ( 'edit.php' === $hook || 'post.php' === $hook || 'post-new.php' === $hook ) {
			wp_enqueue_script( 'wcmc-admin', plugin_dir_url( __FILE__ ) . 'assets/admin.js', array( 'jquery' ), '1.0.2', true );
			wp_localize_script( 'wcmc-admin', 'wcmc', array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wcmc_nonce' ),
			) );
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( 'margin-calculator-pro' === $page ) {
			wp_enqueue_script( 'wcmc-admin', plugin_dir_url( __FILE__ ) . 'assets/admin.js', array( 'jquery', 'jquery-ui-core' ), '1.0.2', true );
			wp_localize_script( 'wcmc-admin', 'wcmc', array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wcmc_nonce' ),
			) );
		}

		if ( 'wcmc-margin-reports' === $page ) {
			wp_enqueue_script( 'wcmc-reports', plugin_dir_url( __FILE__ ) . 'assets/reports.js', array( 'jquery' ), '1.0.0', true );
			wp_localize_script( 'wcmc-reports', 'wcmc', array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wcmc_nonce' ),
			) );
		}
	}

	// ── PURCHASE PRICE FIELDS ──────────────────────────────────────────────

	public function add_purchase_price_field() {
		woocommerce_wp_text_input( array(
			'id'          => '_purchase_price_net',
			'label'       => esc_html__( 'Purchase price (net)', 'margin-calculator-pro' ) . ' (' . get_woocommerce_currency_symbol() . ')',
			'value'       => get_post_meta( get_the_ID(), '_purchase_price_net', true ),
			'data_type'   => 'price',
			'desc_tip'    => true,
			'description' => esc_html__( 'Product purchase price excluding VAT', 'margin-calculator-pro' ),
		) );
	}

	public function add_purchase_price_field_variation( $loop, $variation_data, $variation ) {
		woocommerce_wp_text_input( array(
			'id'            => '_purchase_price_net[' . $loop . ']',
			'label'         => esc_html__( 'Purchase price (net)', 'margin-calculator-pro' ) . ' (' . get_woocommerce_currency_symbol() . ')',
			'value'         => get_post_meta( $variation->ID, '_purchase_price_net', true ),
			'wrapper_class' => 'form-row form-row-full',
			'data_type'     => 'price',
		) );
	}

	// ── SAVE ──────────────────────────────────────────────────────────────

	public function save_purchase_price( $post_id ) {
		if ( isset( $_POST['_purchase_price_net'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$raw   = wp_unslash( $_POST['_purchase_price_net'] ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$price = wc_clean( sanitize_text_field( $raw ) );
			update_post_meta( $post_id, '_purchase_price_net', $price );
		}
		$this->enforce_minimum_margin( $post_id );
	}

	public function save_purchase_price_variation( $variation_id, $i ) {
		if ( isset( $_POST['_purchase_price_net'][ $i ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$raw   = wp_unslash( $_POST['_purchase_price_net'][ $i ] ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$price = wc_clean( sanitize_text_field( $raw ) );
			update_post_meta( $variation_id, '_purchase_price_net', $price );
		}
		$this->enforce_minimum_margin( $variation_id );
	}

	// ── MINIMUM MARGIN ENFORCEMENT ────────────────────────────────────────

	/**
	 * Enforce minimum margin: margin = (sale_net - purchase_net) / sale_net
	 * => min_sale_net = purchase_net / (1 - margin)
	 */
	public function enforce_minimum_margin( $product_id ) {
		$purchase_price = $this->parse_price( get_post_meta( $product_id, '_purchase_price_net', true ) );
		$regular_price  = floatval( get_post_meta( $product_id, '_regular_price', true ) );

		if ( $purchase_price <= 0 ) {
			return;
		}

		if ( self::MIN_MARGIN >= 1 ) {
			return;
		}

		$settings       = get_option( 'wcmc_settings', array( 'vat_rate' => 23 ) );
		$vat_multiplier = 1 + ( floatval( $settings['vat_rate'] ) / 100 );

		$min_sale_net   = $purchase_price / ( 1 - self::MIN_MARGIN );
		$min_sale_gross = $min_sale_net * $vat_multiplier;
		$min_sale_gross = ceil( $min_sale_gross * 10 ) / 10 - 0.01;

		if ( $regular_price > 0 && $regular_price < $min_sale_gross ) {
			update_post_meta( $product_id, '_regular_price', $min_sale_gross );
			update_post_meta( $product_id, '_price', $min_sale_gross );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( 'WCMC: Auto-corrected price for product ID %d: %s -> %s (min margin: %s%%)', $product_id, $regular_price, $min_sale_gross, ( self::MIN_MARGIN * 100 ) ) );
			}
		}
	}

	// ── MARGIN CALCULATION ────────────────────────────────────────────────

	/**
	 * Calculate margin: (Sale Net - Purchase Net) / Sale Net * 100
	 */
	public function calculate_margin( $product_id, $variation_id = 0 ) {
		$id                 = $variation_id ? $variation_id : $product_id;
		$purchase_price_raw = get_post_meta( $id, '_purchase_price_net', true );

		if ( is_array( $purchase_price_raw ) ) {
			$purchase_price_raw = isset( $purchase_price_raw[0] ) ? $purchase_price_raw[0] : '';
		}
		$purchase_price_raw = strval( $purchase_price_raw );
		$purchase_price     = 0;

		if ( preg_match( '/[\d.,]+/', $purchase_price_raw, $matches ) ) {
			$purchase_price = floatval( str_replace( ',', '.', $matches[0] ) );
		}

		$sale_price = floatval( get_post_meta( $id, '_regular_price', true ) );

		if ( empty( $purchase_price ) || empty( $sale_price ) || $purchase_price <= 0 || $sale_price <= 0 ) {
			return null;
		}

		$settings       = get_option( 'wcmc_settings', array( 'vat_rate' => 23 ) );
		$vat_multiplier = 1 + ( floatval( $settings['vat_rate'] ) / 100 );
		$sale_price_net = $sale_price / $vat_multiplier;

		if ( $sale_price_net <= 0 ) {
			return null;
		}

		$margin = ( ( $sale_price_net - $purchase_price ) / $sale_price_net ) * 100;

		return round( $margin, 2 );
	}

	public function get_margin_color( $margin, $product_id = 0 ) {
		if ( is_null( $margin ) ) {
			return '#999';
		}

		$settings = get_option( 'wcmc_settings', array( 'margin_high' => 40, 'margin_medium' => 30 ) );

		if ( $product_id ) {
			$categories      = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
			$category_margins = get_option( 'wcmc_category_margins', array() );

			foreach ( $categories as $cat_id ) {
				if ( isset( $category_margins[ $cat_id ] ) && ! empty( $category_margins[ $cat_id ]['high'] ) ) {
					$settings['margin_high']   = $category_margins[ $cat_id ]['high'];
					$settings['margin_medium'] = $category_margins[ $cat_id ]['medium'];
					break;
				}
			}
		}

		if ( $margin > 40 ) {
			return '#ee00ff';
		} elseif ( $margin >= $settings['margin_high'] ) {
			return '#4CAF50';
		} elseif ( $margin >= $settings['margin_medium'] ) {
			return '#FB8C00';
		} else {
			return '#C62828';
		}
	}

	// ── PRODUCT LIST COLUMNS ──────────────────────────────────────────────

	public function add_margin_column( $columns ) {
		$new_columns = array();
		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;
			if ( 'price' === $key ) {
				$new_columns['purchase_price'] = esc_html__( 'Purchase Price', 'margin-calculator-pro' );
				$new_columns['margin']         = esc_html__( 'Margin', 'margin-calculator-pro' );
			}
		}
		return $new_columns;
	}

	public function display_margin_column( $column, $post_id ) {
		if ( 'purchase_price' === $column ) {
			$product = wc_get_product( $post_id );

			if ( $product->is_type( 'variable' ) ) {
				$variations = $product->get_available_variations();
				$prices     = array();

				foreach ( $variations as $variation ) {
					$purchase_price = get_post_meta( $variation['variation_id'], '_purchase_price_net', true );
					if ( is_array( $purchase_price ) ) {
						$purchase_price = isset( $purchase_price[0] ) ? $purchase_price[0] : '';
					}
					$purchase_price = strval( $purchase_price );
					if ( ! empty( $purchase_price ) && preg_match( '/[\d.,]+/', $purchase_price, $matches ) ) {
						$prices[] = floatval( str_replace( ',', '.', $matches[0] ) );
					}
				}

				if ( ! empty( $prices ) ) {
					$min_price = min( $prices );
					$max_price = max( $prices );

					if ( $min_price === $max_price ) {
						echo wp_kses_post( wc_price( $min_price ) ) . ' <small>(' . esc_html__( 'net', 'margin-calculator-pro' ) . ')</small>';
					} else {
						echo wp_kses_post( wc_price( $min_price ) ) . ' - ' . wp_kses_post( wc_price( $max_price ) ) . ' <small>(' . esc_html__( 'net', 'margin-calculator-pro' ) . ')</small>';
					}
				} else {
					echo '<span style="color: #999;">&#8212;</span>';
				}
			} else {
				$purchase_price = get_post_meta( $post_id, '_purchase_price_net', true );
				if ( is_array( $purchase_price ) ) {
					$purchase_price = isset( $purchase_price[0] ) ? $purchase_price[0] : '';
				}
				$purchase_price = strval( $purchase_price );
				if ( ! empty( $purchase_price ) && preg_match( '/[\d.,]+/', $purchase_price, $matches ) ) {
					echo wp_kses_post( wc_price( floatval( str_replace( ',', '.', $matches[0] ) ) ) ) . ' <small>(' . esc_html__( 'net', 'margin-calculator-pro' ) . ')</small>';
				} else {
					echo '<span style="color: #999;">&#8212;</span>';
				}
			}
		}

		if ( 'margin' === $column ) {
			$product = wc_get_product( $post_id );

			if ( $product->is_type( 'variable' ) ) {
				$variations = $product->get_available_variations();
				$margins    = array();

				foreach ( $variations as $variation ) {
					$margin = $this->calculate_margin( $post_id, $variation['variation_id'] );
					if ( ! is_null( $margin ) ) {
						$margins[] = array(
							'margin' => $margin,
							'color'  => $this->get_margin_color( $margin, $post_id ),
							'name'   => implode( ', ', array_values( $variation['attributes'] ) ),
						);
					}
				}

				if ( ! empty( $margins ) ) {
					$min_margin = min( array_column( $margins, 'margin' ) );
					$max_margin = max( array_column( $margins, 'margin' ) );
					$avg_margin = round( array_sum( array_column( $margins, 'margin' ) ) / count( $margins ), 2 );
					$color      = $this->get_margin_color( $avg_margin, $post_id );

					echo '<div style="cursor: pointer;" onclick="jQuery(this).next().toggle();">';
					if ( $min_margin === $max_margin ) {
						echo '<strong style="color: ' . esc_attr( $color ) . ';">' . esc_html( $min_margin ) . '%</strong>';
					} else {
						echo '<strong style="color: ' . esc_attr( $color ) . ';">' . esc_html( $min_margin ) . '% - ' . esc_html( $max_margin ) . '%</strong>';
					}
					echo ' <span style="font-size: 10px; color: #666;">(avg: ' . esc_html( $avg_margin ) . '%)</span>';
					echo '</div>';
					echo '<div style="display: none; padding: 5px; background: #f9f9f9; border-radius: 3px; margin-top: 5px; font-size: 11px;">';
					foreach ( $margins as $var ) {
						echo '<div style="margin-bottom: 2px;">';
						echo '<span style="font-size: 11px; color: #666;">' . esc_html( $var['name'] ) . ':</span> ';
						echo '<strong style="color: ' . esc_attr( $var['color'] ) . ';">' . esc_html( $var['margin'] ) . '%</strong>';
						echo '</div>';
					}
					echo '</div></div>';
				} else {
					echo '<span style="color: #999;">&#8212;</span>';
				}
			} else {
				$margin = $this->calculate_margin( $post_id );
				if ( ! is_null( $margin ) ) {
					$color = $this->get_margin_color( $margin, $post_id );
					echo '<strong style="color: ' . esc_attr( $color ) . ';">' . esc_html( $margin ) . '%</strong>';
				} else {
					echo '<span style="color: #999;">&#8212;</span>';
				}
			}
		}
	}

	// ── QUICK EDIT ────────────────────────────────────────────────────────

	public function add_quick_edit_field( $column_name, $post_type ) {
		if ( 'product' !== $post_type || 'purchase_price' !== $column_name ) {
			return;
		}

		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<label>
					<span class="title"><?php esc_html_e( 'Purchase price (net)', 'margin-calculator-pro' ); ?></span>
					<span class="input-text-wrap">
						<input type="text" name="_purchase_price_net" class="text wc_input_price wcmc-purchase-price" value="" placeholder="0.00">
					</span>
				</label>
			</div>
		</fieldset>
		<?php
	}

	public function save_quick_edit_field( $product ) {
		// Verify this is a Quick Edit request
		if ( ! isset( $_REQUEST['_inline_edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		// Verify nonce
		if ( ! check_admin_referer( 'inlineeditnonce', '_inline_edit' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}

		if ( isset( $_REQUEST['_purchase_price_net'] ) && '' !== $_REQUEST['_purchase_price_net'] ) {
			$price = wc_format_decimal( sanitize_text_field( wp_unslash( $_REQUEST['_purchase_price_net'] ) ) );
			update_post_meta( $product->get_id(), '_purchase_price_net', $price );
		}
	}

	// ── AJAX ──────────────────────────────────────────────────────────────

	public function ajax_get_purchase_price() {
		// Verify nonce
		check_ajax_referer( 'wcmc_nonce', 'nonce' );

		// Check capability
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Unauthorized', 'margin-calculator-pro' ) ), 403 );
		}

		$product_id     = isset( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : 0;
		$purchase_price = get_post_meta( $product_id, '_purchase_price_net', true );

		wp_send_json_success( array( 'purchase_price' => $purchase_price ) );
	}

	// ── MARGIN DISPLAY IN PRODUCT EDIT ────────────────────────────────────

	public function display_margin_in_product() {
		global $post;
		$margin = $this->calculate_margin( $post->ID );

		if ( ! is_null( $margin ) ) {
			$color = $this->get_margin_color( $margin, $post->ID );
			echo '<div style="display: inline-block; margin-left: 1em; padding: 5px 10px; background: ' . esc_attr( $color ) . '; color: white; border-radius: 3px;">';
			echo '<strong>' . esc_html__( 'Margin:', 'margin-calculator-pro' ) . ' ' . esc_html( $margin ) . '%</strong>';
			echo '</div>';
		}
	}

	public function display_margin_in_variation( $loop, $variation_data, $variation ) {
		$margin = $this->calculate_margin( $variation->post_parent, $variation->ID );

		if ( ! is_null( $margin ) ) {
			$color = $this->get_margin_color( $margin, $variation->post_parent );
			echo '<div style="display: inline-block; margin-left: 1em; padding: 5px 10px; background: ' . esc_attr( $color ) . '; color: white; border-radius: 3px; font-size: 12px;">';
			echo '<strong>' . esc_html__( 'Margin:', 'margin-calculator-pro' ) . ' ' . esc_html( $margin ) . '%</strong>';
			echo '</div>';
		}
	}

	// ── SETTINGS PAGE ─────────────────────────────────────────────────────

	public function settings_page() {
		if ( isset( $_POST['wcmc_save_settings'] ) && check_admin_referer( 'wcmc_settings' ) ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'You do not have permission to manage these settings.', 'margin-calculator-pro' ) );
			}

			$settings = array(
				'margin_high'   => isset( $_POST['margin_high'] ) ? intval( $_POST['margin_high'] ) : 40,
				'margin_medium' => isset( $_POST['margin_medium'] ) ? intval( $_POST['margin_medium'] ) : 30,
				'vat_rate'      => isset( $_POST['vat_rate'] ) ? sanitize_text_field( wp_unslash( $_POST['vat_rate'] ) ) : '23',
			);

			if ( $settings['margin_high'] < $settings['margin_medium'] ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'High margin must be greater than medium margin!', 'margin-calculator-pro' ) . '</p></div>';
			} else {
				update_option( 'wcmc_settings', $settings );

				if ( isset( $_POST['category_margins'] ) && is_array( $_POST['category_margins'] ) ) {
					$category_margins = array();
					$errors           = array();

					foreach ( $_POST['category_margins'] as $cat_id => $values ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
						$cat_id = absint( $cat_id );
						$high   = ! empty( $values['high'] ) ? intval( $values['high'] ) : '';
						$medium = ! empty( $values['medium'] ) ? intval( $values['medium'] ) : '';

						if ( '' !== $high || '' !== $medium ) {
							if ( '' !== $high && '' !== $medium && $high < $medium ) {
								$term     = get_term( $cat_id );
								$errors[] = sprintf(
									/* translators: %s: category name */
									esc_html__( 'Category "%s": High margin must be greater than medium margin', 'margin-calculator-pro' ),
									esc_html( $term->name )
								);
							} else {
								$category_margins[ $cat_id ] = array(
									'high'   => $high,
									'medium' => $medium,
								);
							}
						}
					}

					if ( ! empty( $errors ) ) {
						echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Warnings:', 'margin-calculator-pro' ) . '</strong><br>' . implode( '<br>', array_map( 'esc_html', $errors ) ) . '</p></div>';
					}

					update_option( 'wcmc_category_margins', $category_margins );
				}

				echo '<div class="notice notice-success"><p><strong>' . esc_html__( 'Success!', 'margin-calculator-pro' ) . '</strong> ' . esc_html__( 'Settings saved.', 'margin-calculator-pro' ) . '</p></div>';
			}
		}

		$settings         = get_option( 'wcmc_settings', array( 'margin_high' => 40, 'margin_medium' => 30, 'vat_rate' => 23 ) );
		$category_margins = get_option( 'wcmc_category_margins', array() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Margin Calculator - Settings', 'margin-calculator-pro' ); ?></h1>

			<form method="post" action="">
				<?php wp_nonce_field( 'wcmc_settings' ); ?>

				<h2><?php esc_html_e( 'Global margin thresholds', 'margin-calculator-pro' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'High margin (green)', 'margin-calculator-pro' ); ?></th>
						<td>
							<input type="number" name="margin_high" value="<?php echo esc_attr( $settings['margin_high'] ); ?>" min="1" max="100" step="1" required>
							<span>%</span>
							<p class="description"><?php esc_html_e( 'Products with margin >= this value will be shown in green', 'margin-calculator-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Medium margin (orange)', 'margin-calculator-pro' ); ?></th>
						<td>
							<input type="number" name="margin_medium" value="<?php echo esc_attr( $settings['margin_medium'] ); ?>" min="1" max="100" step="1" required>
							<span>%</span>
							<p class="description"><?php esc_html_e( 'Products with margin >= this value will be shown in orange', 'margin-calculator-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'VAT rate', 'margin-calculator-pro' ); ?></th>
						<td>
							<select name="vat_rate" required>
								<?php foreach ( $this->vat_rates as $rate => $label ) : ?>
									<option value="<?php echo esc_attr( $rate ); ?>" <?php selected( $settings['vat_rate'], $rate ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Select VAT rate for calculations', 'margin-calculator-pro' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Per-category margin thresholds', 'margin-calculator-pro' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Set custom thresholds per product category (optional)', 'margin-calculator-pro' ); ?></p>

				<?php
				$categories = get_terms( array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
				) );

				if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) :
				?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Category', 'margin-calculator-pro' ); ?></th>
							<th><?php esc_html_e( 'High margin (%)', 'margin-calculator-pro' ); ?></th>
							<th><?php esc_html_e( 'Medium margin (%)', 'margin-calculator-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $categories as $category ) :
						$cat_settings = isset( $category_margins[ $category->term_id ] ) ? $category_margins[ $category->term_id ] : array( 'high' => '', 'medium' => '' );
					?>
						<tr>
							<td><strong><?php echo esc_html( $category->name ); ?></strong></td>
							<td>
								<input type="number" name="category_margins[<?php echo esc_attr( $category->term_id ); ?>][high]"
									   value="<?php echo esc_attr( $cat_settings['high'] ); ?>"
									   min="0" max="100" step="1" style="width: 80px;"
									   placeholder="<?php echo esc_attr( $settings['margin_high'] ); ?>">
							</td>
							<td>
								<input type="number" name="category_margins[<?php echo esc_attr( $category->term_id ); ?>][medium]"
									   value="<?php echo esc_attr( $cat_settings['medium'] ); ?>"
									   min="0" max="100" step="1" style="width: 80px;"
									   placeholder="<?php echo esc_attr( $settings['margin_medium'] ); ?>">
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Leave empty to use global thresholds', 'margin-calculator-pro' ); ?></p>
				<?php endif; ?>

				<p class="submit">
					<input type="submit" name="wcmc_save_settings" class="button button-primary" value="<?php esc_attr_e( 'Save settings', 'margin-calculator-pro' ); ?>">
				</p>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Statistics', 'margin-calculator-pro' ); ?></h2>
			<?php $this->display_statistics(); ?>

			<hr>

			<h2><?php esc_html_e( 'Margin formula', 'margin-calculator-pro' ); ?></h2>
			<p><code>Margin % = (Sale Price Net - Purchase Price Net) / Sale Price Net × 100</code></p>
			<p class="description"><?php esc_html_e( 'Margin calculated as profit share of net sale price (not markup).', 'margin-calculator-pro' ); ?></p>

			<hr>

			<?php $this->render_csv_section(); ?>

		</div>
		<?php
	}

	// ── STATISTICS ────────────────────────────────────────────────────────

	private function display_statistics() {
		global $wpdb;

		$cache_key = 'wcmc_statistics_products';
		$products  = wp_cache_get( $cache_key, 'wcmc' );

		if ( false === $products ) {
			$products = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT p.ID, pm1.meta_value as purchase_price, pm2.meta_value as sale_price
					FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = %s
					LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = %s
					WHERE p.post_type = %s AND p.post_status = %s",
					'_purchase_price_net',
					'_regular_price',
					'product',
					'publish'
				)
			);
			wp_cache_set( $cache_key, $products, 'wcmc', 300 );
		}

		$total      = count( $products );
		$with_margin = 0;
		$avg_margin  = 0;
		$margins     = array();

		foreach ( $products as $product ) {
			$margin = $this->calculate_margin( $product->ID );
			if ( ! is_null( $margin ) ) {
				$with_margin++;
				$margins[] = $margin;
			}
		}

		if ( ! empty( $margins ) ) {
			$avg_margin = round( array_sum( $margins ) / count( $margins ), 2 );
		}
		?>
		<table class="wp-list-table widefat fixed striped">
			<tbody>
				<tr>
					<th style="width: 300px;"><?php esc_html_e( 'All products', 'margin-calculator-pro' ); ?></th>
					<td><strong><?php echo esc_html( $total ); ?></strong></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Products with purchase price', 'margin-calculator-pro' ); ?></th>
					<td><strong><?php echo esc_html( $with_margin ); ?></strong> (<?php echo $total > 0 ? esc_html( round( ( $with_margin / $total ) * 100, 1 ) ) : 0; ?>%)</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Average margin', 'margin-calculator-pro' ); ?></th>
					<td><strong><?php echo esc_html( $avg_margin ); ?>%</strong></td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	// ── DASHBOARD WIDGET ──────────────────────────────────────────────────

	public function add_dashboard_widget() {
		wp_add_dashboard_widget(
			'wcmc_margin_widget',
			esc_html__( 'Product Margins', 'margin-calculator-pro' ),
			array( $this, 'display_dashboard_widget' )
		);
		wp_add_dashboard_widget(
			'wcmc_orders_widget',
			esc_html__( 'Order Margins - Current Month', 'margin-calculator-pro' ),
			array( $this, 'display_orders_dashboard_widget' )
		);
	}

	public function display_orders_dashboard_widget() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$date_from = gmdate( 'Y-m-01' ) . ' 00:00:00';
		$date_to   = gmdate( 'Y-m-d' ) . ' 23:59:59';

		$orders = wc_get_orders( array(
			'type'        => 'shop_order',
			'status'      => array( 'wc-completed', 'wc-processing' ),
			'limit'       => 500,
			'date_after'  => $date_from,
			'date_before' => $date_to,
		) );

		$total_revenue = 0;
		$total_cost    = 0;
		$order_count   = 0;
		$lowest_order  = null;
		$highest_order = null;

		foreach ( $orders as $order ) {
			$data = $this->calculate_order_margin( $order );
			if ( is_null( $data ) ) {
				continue;
			}
			$order_count++;
			$total_revenue += $data['revenue'];
			$total_cost    += $data['cost'];

			if ( is_null( $lowest_order ) || $data['margin'] < $lowest_order['margin'] ) {
				$lowest_order = array(
					'number' => $order->get_order_number(),
					'url'    => $order->get_edit_order_url(),
					'margin' => $data['margin'],
				);
			}
			if ( is_null( $highest_order ) || $data['margin'] > $highest_order['margin'] ) {
				$highest_order = array(
					'number' => $order->get_order_number(),
					'url'    => $order->get_edit_order_url(),
					'margin' => $data['margin'],
				);
			}
		}

		$total_profit = $total_revenue - $total_cost;
		$avg_margin   = $total_revenue > 0 ? round( ( $total_profit / $total_revenue ) * 100, 2 ) : 0;

		$margin_color = $avg_margin >= 30 ? '#4CAF50' : ( $avg_margin >= 15 ? '#FB8C00' : '#C62828' );
		$profit_color = $total_profit >= 0 ? '#4CAF50' : '#C62828';
		?>
		<style>
			.wcmc-ow-cards { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
			.wcmc-ow-card { flex: 1; min-width: 100px; background: #f9f9f9; border: 1px solid #e5e5e5; padding: 10px; text-align: center; border-radius: 4px; }
			.wcmc-ow-card .wcmc-ow-label { font-size: 11px; color: #666; margin-bottom: 4px; }
			.wcmc-ow-card .wcmc-ow-value { font-size: 18px; font-weight: 700; }
			.wcmc-ow-extremes { display: flex; gap: 10px; }
			.wcmc-ow-extremes > div { flex: 1; padding: 8px; background: #f9f9f9; border: 1px solid #e5e5e5; border-radius: 4px; font-size: 13px; }
		</style>

		<?php if ( 0 === $order_count ) : ?>
			<p style="color:#999;"><?php esc_html_e( 'No orders with margin data this month.', 'margin-calculator-pro' ); ?></p>
		<?php else : ?>
			<div class="wcmc-ow-cards">
				<div class="wcmc-ow-card">
					<div class="wcmc-ow-label"><?php esc_html_e( 'Orders', 'margin-calculator-pro' ); ?></div>
					<div class="wcmc-ow-value"><?php echo esc_html( $order_count ); ?></div>
				</div>
				<div class="wcmc-ow-card">
					<div class="wcmc-ow-label"><?php esc_html_e( 'Profit', 'margin-calculator-pro' ); ?></div>
					<div class="wcmc-ow-value" style="color:<?php echo esc_attr( $profit_color ); ?>">
						<?php echo wp_kses_post( wc_price( $total_profit ) ); ?>
					</div>
				</div>
				<div class="wcmc-ow-card">
					<div class="wcmc-ow-label"><?php esc_html_e( 'Average margin', 'margin-calculator-pro' ); ?></div>
					<div class="wcmc-ow-value" style="color:<?php echo esc_attr( $margin_color ); ?>">
						<?php echo esc_html( $avg_margin ); ?>%
					</div>
				</div>
			</div>
			<div class="wcmc-ow-cards">
				<div class="wcmc-ow-card">
					<div class="wcmc-ow-label"><?php esc_html_e( 'Net revenue', 'margin-calculator-pro' ); ?></div>
					<div class="wcmc-ow-value" style="font-size:14px;"><?php echo wp_kses_post( wc_price( $total_revenue ) ); ?></div>
				</div>
				<div class="wcmc-ow-card">
					<div class="wcmc-ow-label"><?php esc_html_e( 'Total cost', 'margin-calculator-pro' ); ?></div>
					<div class="wcmc-ow-value" style="font-size:14px;"><?php echo wp_kses_post( wc_price( $total_cost ) ); ?></div>
				</div>
			</div>

			<?php if ( $lowest_order && $highest_order ) : ?>
				<div class="wcmc-ow-extremes">
					<div>
						<?php esc_html_e( 'Lowest', 'margin-calculator-pro' ); ?>:
						<a href="<?php echo esc_url( $lowest_order['url'] ); ?>">
							#<?php echo esc_html( $lowest_order['number'] ); ?>
						</a>
						<strong style="color:#C62828;"><?php echo esc_html( $lowest_order['margin'] ); ?>%</strong>
					</div>
					<div>
						<?php esc_html_e( 'Highest', 'margin-calculator-pro' ); ?>:
						<a href="<?php echo esc_url( $highest_order['url'] ); ?>">
							#<?php echo esc_html( $highest_order['number'] ); ?>
						</a>
						<strong style="color:#4CAF50;"><?php echo esc_html( $highest_order['margin'] ); ?>%</strong>
					</div>
				</div>
			<?php endif; ?>

			<p style="margin:10px 0 0; text-align:right;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wcmc-margin-reports' ) ); ?>">
					<?php esc_html_e( 'View full report', 'margin-calculator-pro' ); ?> &rarr;
				</a>
			</p>
		<?php endif; ?>
		<?php
	}

	public function display_dashboard_widget() {
		global $wpdb;

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$settings       = get_option( 'wcmc_settings', array( 'vat_rate' => 23 ) );
		$vat_multiplier = 1 + ( floatval( $settings['vat_rate'] ) / 100 );
		$products_data  = array();

		// Simple products (instock only)
		$simple_products = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, pm1.meta_value as purchase_price, pm2.meta_value as sale_price
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = %s
				INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = %s
				INNER JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = %s
				WHERE p.post_type = %s AND p.post_status = %s
				AND pm1.meta_value != '' AND pm2.meta_value != ''
				AND pm_stock.meta_value = %s",
				'_purchase_price_net',
				'_regular_price',
				'_stock_status',
				'product',
				'publish',
				'instock'
			)
		);

		foreach ( $simple_products as $product ) {
			$purchase = $this->parse_price( $product->purchase_price );
			$sale     = floatval( $product->sale_price );

			if ( $purchase > 0 && $sale > 0 ) {
				$sale_net = $sale / $vat_multiplier;
				if ( $sale_net > 0 ) {
					$margin          = round( ( ( $sale_net - $purchase ) / $sale_net ) * 100, 2 );
					$products_data[] = array(
						'id'       => $product->ID,
						'name'     => $product->post_title,
						'margin'   => $margin,
						'purchase' => $purchase,
						'sale'     => $sale,
					);
				}
			}
		}

		// Variations (instock only)
		$variations = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT v.ID, v.post_parent, v.post_title, pm1.meta_value as purchase_price, pm2.meta_value as sale_price,
				       parent.post_title as parent_title
				FROM {$wpdb->posts} v
				INNER JOIN {$wpdb->posts} parent ON v.post_parent = parent.ID
				INNER JOIN {$wpdb->postmeta} pm1 ON v.ID = pm1.post_id AND pm1.meta_key = %s
				INNER JOIN {$wpdb->postmeta} pm2 ON v.ID = pm2.post_id AND pm2.meta_key = %s
				INNER JOIN {$wpdb->postmeta} pm_stock ON v.ID = pm_stock.post_id AND pm_stock.meta_key = %s
				WHERE v.post_type = %s AND v.post_status = %s
				AND pm1.meta_value != '' AND pm2.meta_value != ''
				AND pm_stock.meta_value = %s",
				'_purchase_price_net',
				'_regular_price',
				'_stock_status',
				'product_variation',
				'publish',
				'instock'
			)
		);

		foreach ( $variations as $variation ) {
			$purchase = $this->parse_price( $variation->purchase_price );
			$sale     = floatval( $variation->sale_price );

			if ( $purchase > 0 && $sale > 0 ) {
				$sale_net = $sale / $vat_multiplier;
				if ( $sale_net > 0 ) {
					$margin          = round( ( ( $sale_net - $purchase ) / $sale_net ) * 100, 2 );
					$products_data[] = array(
						'id'        => $variation->ID,
						'parent_id' => $variation->post_parent,
						'name'      => $variation->parent_title . ' - ' . $variation->post_title,
						'margin'    => $margin,
						'purchase'  => $purchase,
						'sale'      => $sale,
					);
				}
			}
		}

		if ( empty( $products_data ) ) {
			echo '<p>' . esc_html__( 'No products with purchase price found.', 'margin-calculator-pro' ) . '</p>';
			return;
		}

		usort( $products_data, function ( $a, $b ) {
			return $a['margin'] <=> $b['margin'];
		} );

		$lowest  = array_slice( $products_data, 0, 10 );
		$highest = array_slice( array_reverse( $products_data ), 0, 10 );

		$margins    = array_column( $products_data, 'margin' );
		$avg_margin = round( array_sum( $margins ) / count( $margins ), 2 );
		$min_margin = min( $margins );
		$max_margin = max( $margins );
		?>
		<style>
			.wcmc-widget-stats { display: flex; gap: 15px; margin-bottom: 15px; }
			.wcmc-widget-stat { flex: 1; text-align: center; padding: 10px; background: #f0f0f1; border-radius: 4px; }
			.wcmc-widget-stat strong { display: block; font-size: 24px; }
			.wcmc-widget-table { width: 100%; border-collapse: collapse; font-size: 12px; }
			.wcmc-widget-table th, .wcmc-widget-table td { padding: 5px 8px; text-align: left; border-bottom: 1px solid #ddd; }
			.wcmc-widget-table th { background: #f0f0f1; }
			.wcmc-widget-section { margin-bottom: 15px; }
			.wcmc-widget-section h4 { margin: 0 0 8px 0; font-size: 13px; }
			.margin-low { color: #C62828; font-weight: bold; }
			.margin-medium { color: #FB8C00; font-weight: bold; }
			.margin-high { color: #4CAF50; font-weight: bold; }
			.margin-very-high { color: #ee00ff; font-weight: bold; }
		</style>

		<div class="wcmc-widget-stats">
			<div class="wcmc-widget-stat">
				<strong><?php echo esc_html( count( $products_data ) ); ?></strong>
				<span><?php esc_html_e( 'With purchase price', 'margin-calculator-pro' ); ?></span>
			</div>
			<div class="wcmc-widget-stat">
				<strong><?php echo esc_html( $avg_margin ); ?>%</strong>
				<span><?php esc_html_e( 'Average margin', 'margin-calculator-pro' ); ?></span>
			</div>
			<div class="wcmc-widget-stat">
				<strong class="margin-low"><?php echo esc_html( $min_margin ); ?>%</strong>
				<span><?php esc_html_e( 'Lowest', 'margin-calculator-pro' ); ?></span>
			</div>
			<div class="wcmc-widget-stat">
				<strong class="margin-very-high"><?php echo esc_html( $max_margin ); ?>%</strong>
				<span><?php esc_html_e( 'Highest', 'margin-calculator-pro' ); ?></span>
			</div>
		</div>

		<div class="wcmc-widget-section">
			<h4>🔴 <?php esc_html_e( 'Lowest margins (TOP 10)', 'margin-calculator-pro' ); ?></h4>
			<table class="wcmc-widget-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Product', 'margin-calculator-pro' ); ?></th>
						<th><?php esc_html_e( 'Margin', 'margin-calculator-pro' ); ?></th>
						<th><?php esc_html_e( 'Purchase', 'margin-calculator-pro' ); ?></th>
						<th><?php esc_html_e( 'Sale', 'margin-calculator-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $lowest as $p ) :
						$margin_class = $p['margin'] < 30 ? 'margin-low' : ( $p['margin'] < 35 ? 'margin-medium' : 'margin-high' );
						$edit_link    = isset( $p['parent_id'] ) ? admin_url( 'post.php?post=' . $p['parent_id'] . '&action=edit' ) : admin_url( 'post.php?post=' . $p['id'] . '&action=edit' );
					?>
					<tr>
						<td><a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( mb_substr( $p['name'], 0, 40 ) ); ?></a></td>
						<td class="<?php echo esc_attr( $margin_class ); ?>"><?php echo esc_html( $p['margin'] ); ?>%</td>
						<td><?php echo wp_kses_post( wc_price( $p['purchase'] ) ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $p['sale'] ) ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<div class="wcmc-widget-section">
			<h4>🟢 <?php esc_html_e( 'Highest margins (TOP 10)', 'margin-calculator-pro' ); ?></h4>
			<table class="wcmc-widget-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Product', 'margin-calculator-pro' ); ?></th>
						<th><?php esc_html_e( 'Margin', 'margin-calculator-pro' ); ?></th>
						<th><?php esc_html_e( 'Purchase', 'margin-calculator-pro' ); ?></th>
						<th><?php esc_html_e( 'Sale', 'margin-calculator-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $highest as $p ) :
						$margin_class = $p['margin'] > 40 ? 'margin-very-high' : ( $p['margin'] >= 35 ? 'margin-high' : 'margin-medium' );
						$edit_link    = isset( $p['parent_id'] ) ? admin_url( 'post.php?post=' . $p['parent_id'] . '&action=edit' ) : admin_url( 'post.php?post=' . $p['id'] . '&action=edit' );
					?>
					<tr>
						<td><a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( mb_substr( $p['name'], 0, 40 ) ); ?></a></td>
						<td class="<?php echo esc_attr( $margin_class ); ?>"><?php echo esc_html( $p['margin'] ); ?>%</td>
						<td><?php echo wp_kses_post( wc_price( $p['purchase'] ) ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $p['sale'] ) ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php
		// Products WITHOUT purchase price (simple, instock only)
		$without_purchase = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, pm_sku.meta_value as sku, pm_price.meta_value as price
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
				INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
				LEFT JOIN {$wpdb->postmeta} pm_purchase ON p.ID = pm_purchase.post_id AND pm_purchase.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} pm_price ON p.ID = pm_price.post_id AND pm_price.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = %s
				WHERE p.post_type = %s AND p.post_status = %s
				AND tt.taxonomy = %s AND t.slug = %s
				AND (pm_purchase.meta_value IS NULL OR pm_purchase.meta_value = '')
				AND pm_price.meta_value != '' AND pm_price.meta_value > 0
				AND pm_stock.meta_value = %s
				ORDER BY pm_sku.meta_value ASC
				LIMIT 500",
				'_purchase_price_net', '_sku', '_regular_price', '_stock_status',
				'product', 'publish',
				'product_type', 'simple',
				'instock'
			)
		);

		$variations_without = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT v.ID, v.post_parent, parent.post_title as parent_title,
				       pm_sku.meta_value as sku, pm_price.meta_value as price
				FROM {$wpdb->posts} v
				INNER JOIN {$wpdb->posts} parent ON v.post_parent = parent.ID
				LEFT JOIN {$wpdb->postmeta} pm_purchase ON v.ID = pm_purchase.post_id AND pm_purchase.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} pm_sku ON v.ID = pm_sku.post_id AND pm_sku.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} pm_price ON v.ID = pm_price.post_id AND pm_price.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} pm_stock ON v.ID = pm_stock.post_id AND pm_stock.meta_key = %s
				WHERE v.post_type = %s AND v.post_status = %s
				AND (pm_purchase.meta_value IS NULL OR pm_purchase.meta_value = '')
				AND pm_price.meta_value != '' AND pm_price.meta_value > 0
				AND pm_stock.meta_value = %s
				ORDER BY pm_sku.meta_value ASC
				LIMIT 500",
				'_purchase_price_net', '_sku', '_regular_price', '_stock_status',
				'product_variation', 'publish',
				'instock'
			)
		);

		$all_without = array();
		foreach ( $without_purchase as $p ) {
			$all_without[] = array(
				'id'        => $p->ID,
				'parent_id' => null,
				'name'      => $p->post_title,
				'sku'       => $p->sku,
				'price'     => floatval( $p->price ),
			);
		}
		foreach ( $variations_without as $v ) {
			$all_without[] = array(
				'id'        => $v->ID,
				'parent_id' => $v->post_parent,
				'name'      => $v->parent_title,
				'sku'       => $v->sku,
				'price'     => floatval( $v->price ),
			);
		}

		$count_without = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm_purchase ON p.ID = pm_purchase.post_id AND pm_purchase.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} pm_price ON p.ID = pm_price.post_id AND pm_price.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = %s
				WHERE p.post_type = %s AND p.post_status = %s
				AND (pm_purchase.meta_value IS NULL OR pm_purchase.meta_value = '')
				AND pm_price.meta_value != '' AND pm_price.meta_value > 0
				AND pm_stock.meta_value = %s",
				'_purchase_price_net', '_regular_price', '_stock_status',
				'product_variation', 'publish', 'instock'
			)
		);

		if ( ! empty( $all_without ) ) :
		?>
		<div class="wcmc-widget-section" style="margin-top: 20px; padding-top: 15px; border-top: 2px solid #ddd;">
			<h4>⚠️ <?php esc_html_e( 'Products WITHOUT purchase price', 'margin-calculator-pro' ); ?> (<?php echo esc_html( $count_without ); ?>)</h4>
			<table class="wcmc-widget-table" id="wcmc-without-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'SKU', 'margin-calculator-pro' ); ?></th>
						<th><?php esc_html_e( 'Product', 'margin-calculator-pro' ); ?></th>
						<th><?php esc_html_e( 'Price', 'margin-calculator-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $all_without as $index => $p ) :
						$edit_link = $p['parent_id'] ? admin_url( 'post.php?post=' . $p['parent_id'] . '&action=edit' ) : admin_url( 'post.php?post=' . $p['id'] . '&action=edit' );
						$hidden    = $index >= 10 ? 'style="display:none;" class="wcmc-hidden-row"' : '';
					?>
					<tr <?php echo $hidden ? 'style="display:none;" class="wcmc-hidden-row"' : ''; ?>>
						<td><code><?php echo esc_html( $p['sku'] ); ?></code></td>
						<td><a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( mb_substr( $p['name'], 0, 30 ) ); ?></a></td>
						<td><?php echo wp_kses_post( wc_price( $p['price'] ) ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $count_without > 10 ) : ?>
			<p style="text-align: center; margin-top: 8px;">
				<button type="button" id="wcmc-show-all" class="button" onclick="document.querySelectorAll('.wcmc-hidden-row').forEach(r => r.style.display = ''); this.style.display='none'; document.getElementById('wcmc-hide-all').style.display='inline-block';">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of products */
							__( 'Show all (%d)', 'margin-calculator-pro' ),
							$count_without
						)
					);
					?>
				</button>
				<button type="button" id="wcmc-hide-all" class="button" style="display:none;" onclick="document.querySelectorAll('.wcmc-hidden-row').forEach(r => r.style.display = 'none'); this.style.display='none'; document.getElementById('wcmc-show-all').style.display='inline-block';">
					<?php esc_html_e( 'Collapse list', 'margin-calculator-pro' ); ?>
				</button>
			</p>
			<?php endif; ?>
		</div>
		<?php endif; ?>
		<?php
	}

	// ── ORDER MARGIN ──────────────────────────────────────────────────────

	/**
	 * Calculate total margin for a WooCommerce order.
	 */
	public function calculate_order_margin( $order ) {
		$settings       = get_option( 'wcmc_settings', array( 'vat_rate' => 23 ) );
		$vat_multiplier = 1 + ( floatval( $settings['vat_rate'] ) / 100 );

		$total_revenue = 0;
		$total_cost    = 0;

		foreach ( $order->get_items() as $item ) {
			$product_id   = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
			$qty          = $item->get_quantity();
			$line_total   = floatval( $item->get_total() ); // net line total
			$purchase_raw = get_post_meta( $product_id, '_purchase_price_net', true );
			$purchase     = $this->parse_price( $purchase_raw );

			$total_revenue += $line_total;
			if ( $purchase > 0 ) {
				$total_cost += $purchase * $qty;
			}
		}

		if ( $total_revenue <= 0 || $total_cost <= 0 ) {
			return null;
		}

		$profit = $total_revenue - $total_cost;
		$margin = ( $profit / $total_revenue ) * 100;

		return array(
			'revenue' => round( $total_revenue, 2 ),
			'cost'    => round( $total_cost, 2 ),
			'profit'  => round( $profit, 2 ),
			'margin'  => round( $margin, 2 ),
		);
	}

	/**
	 * Show margin meta box on order edit page.
	 */
	public function display_margin_on_order( $order ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$data = $this->calculate_order_margin( $order );

		if ( is_null( $data ) ) {
			return;
		}

		$color = $data['margin'] >= 30 ? '#4CAF50' : ( $data['margin'] >= 15 ? '#FB8C00' : '#C62828' );
		?>
		<div class="order_data_column" style="margin-top: 16px;">
			<h4><?php esc_html_e( 'Margin summary', 'margin-calculator-pro' ); ?></h4>
			<table class="wc-order-totals" style="font-size:13px;">
				<tr>
					<td><?php esc_html_e( 'Net revenue', 'margin-calculator-pro' ); ?>:</td>
					<td><strong><?php echo wp_kses_post( wc_price( $data['revenue'] ) ); ?></strong></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Total cost', 'margin-calculator-pro' ); ?>:</td>
					<td><strong><?php echo wp_kses_post( wc_price( $data['cost'] ) ); ?></strong></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Profit', 'margin-calculator-pro' ); ?>:</td>
					<td><strong style="color:<?php echo esc_attr( $data['profit'] >= 0 ? '#4CAF50' : '#C62828' ); ?>">
						<?php echo wp_kses_post( wc_price( $data['profit'] ) ); ?>
					</strong></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Margin', 'margin-calculator-pro' ); ?>:</td>
					<td><strong style="color:<?php echo esc_attr( $color ); ?>; font-size:16px;">
						<?php echo esc_html( $data['margin'] ); ?>%
					</strong></td>
				</tr>
			</table>
			<?php if ( $data['profit'] < 0 ) : ?>
			<p style="color:#C62828; font-weight:bold; margin-top:8px;">
				⚠️ <?php esc_html_e( 'Warning: This order is unprofitable!', 'margin-calculator-pro' ); ?>
			</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Add margin column to orders list.
	 */
	public function add_margin_order_column( $columns ) {
		$new = array();
		foreach ( $columns as $key => $val ) {
			$new[ $key ] = $val;
			if ( 'order_total' === $key ) {
				$new['wcmc_margin'] = esc_html__( 'Margin', 'margin-calculator-pro' );
			}
		}
		return $new;
	}

	/**
	 * Display margin in orders list column.
	 */
	public function display_margin_order_column( $column, $order_or_id ) {
		if ( 'wcmc_margin' !== $column ) {
			return;
		}

		$order = is_object( $order_or_id ) ? $order_or_id : wc_get_order( $order_or_id );
		if ( ! $order ) {
			return;
		}

		$data = $this->calculate_order_margin( $order );

		if ( is_null( $data ) ) {
			echo '<span style="color:#999;">&#8212;</span>';
			return;
		}

		$color = $data['margin'] >= 30 ? '#4CAF50' : ( $data['margin'] >= 15 ? '#FB8C00' : '#C62828' );
		echo '<strong style="color:' . esc_attr( $color ) . ';">' . esc_html( $data['margin'] ) . '%</strong>';

		if ( $data['profit'] < 0 ) {
			echo ' <span title="' . esc_attr__( 'Unprofitable order!', 'margin-calculator-pro' ) . '">⚠️</span>';
		}
	}

	// ── CSV IMPORT / EXPORT ───────────────────────────────────────────────

	/**
	 * Render CSV import/export section (called from settings page).
	 */
	public function render_csv_section() {
		?>
		<div class="wcmc-csv-section">
			<h2><?php esc_html_e( 'Import / Export purchase prices (CSV)', 'margin-calculator-pro' ); ?></h2>

			<h3><?php esc_html_e( 'Export', 'margin-calculator-pro' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Download all products with their purchase prices as a CSV file.', 'margin-calculator-pro' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
				<?php wp_nonce_field( 'wcmc_export_csv', 'wcmc_nonce' ); ?>
				<input type="hidden" name="action" value="wcmc_export_csv">
				<button type="submit" class="button button-secondary">
					⬇ <?php esc_html_e( 'Export CSV', 'margin-calculator-pro' ); ?>
				</button>
			</form>

			<h3 style="margin-top:1.5rem;"><?php esc_html_e( 'Import', 'margin-calculator-pro' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Upload a CSV file with columns:', 'margin-calculator-pro' ); ?>
				<code>sku, purchase_price</code> <?php esc_html_e( 'or', 'margin-calculator-pro' ); ?> <code>product_id, purchase_price</code>
			</p>
			<div id="wcmc-import-form">
				<input type="file" id="wcmc-csv-file" accept=".csv" style="margin-bottom:8px;display:block;">
				<button type="button" class="button button-primary" id="wcmc-import-btn">
					⬆ <?php esc_html_e( 'Import CSV', 'margin-calculator-pro' ); ?>
				</button>
				<span id="wcmc-import-status" style="margin-left:12px;font-family:monospace;font-size:13px;"></span>
			</div>

			<div id="wcmc-import-results" style="display:none;margin-top:1rem;">
				<h4><?php esc_html_e( 'Import results', 'margin-calculator-pro' ); ?></h4>
				<div id="wcmc-import-log" style="background:#f9f9f9;border:1px solid #ddd;padding:12px;max-height:200px;overflow-y:auto;font-family:monospace;font-size:12px;"></div>
			</div>
		</div>

		<script>
		jQuery(document).ready(function($){
			$('#wcmc-import-btn').on('click', function(){
				var file = $('#wcmc-csv-file')[0].files[0];
				if(!file){ alert('<?php echo esc_js( __( 'Please select a CSV file.', 'margin-calculator-pro' ) ); ?>'); return; }

				var reader = new FileReader();
				reader.onload = function(e){
					var csv = e.target.result;
					$('#wcmc-import-status').text('<?php echo esc_js( __( 'Importing...', 'margin-calculator-pro' ) ); ?>');
					$('#wcmc-import-btn').prop('disabled', true);

					$.ajax({
						url: wcmc.ajax_url,
						type: 'POST',
						data: {
							action: 'wcmc_import_csv',
							nonce: wcmc.nonce,
							csv_data: csv
						},
						success: function(response){
							$('#wcmc-import-btn').prop('disabled', false);
							if(response.success){
								$('#wcmc-import-status').css('color','#4CAF50').text('✓ ' + response.data.message);
								$('#wcmc-import-results').show();
								var log = response.data.log.join('\n');
								$('#wcmc-import-log').text(log);
							} else {
								$('#wcmc-import-status').css('color','#C62828').text('✗ ' + response.data.message);
							}
						},
						error: function(){
							$('#wcmc-import-btn').prop('disabled', false);
							$('#wcmc-import-status').css('color','#C62828').text('<?php echo esc_js( __( 'Connection error.', 'margin-calculator-pro' ) ); ?>');
						}
					});
				};
				reader.readAsText(file);
			});
		});
		</script>
		<?php
	}

	/**
	 * AJAX: Import CSV.
	 */
	public function ajax_import_csv() {
		check_ajax_referer( 'wcmc_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Unauthorized', 'margin-calculator-pro' ) ), 403 );
		}

		$csv_data = isset( $_POST['csv_data'] ) ? sanitize_textarea_field( wp_unslash( $_POST['csv_data'] ) ) : '';

		if ( empty( $csv_data ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'No data received.', 'margin-calculator-pro' ) ) );
		}

		$lines   = explode( "\n", trim( $csv_data ) );
		$header  = str_getcsv( array_shift( $lines ) );
		$header  = array_map( 'trim', array_map( 'strtolower', $header ) );

		// Detect columns
		$col_sku   = array_search( 'sku', $header, true );
		$col_id    = array_search( 'product_id', $header, true );
		$col_price = array_search( 'purchase_price', $header, true );

		if ( false === $col_price ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Column "purchase_price" not found in CSV.', 'margin-calculator-pro' ) ) );
		}

		if ( false === $col_sku && false === $col_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Column "sku" or "product_id" not found in CSV.', 'margin-calculator-pro' ) ) );
		}

		$log     = array();
		$updated = 0;
		$skipped = 0;

		foreach ( $lines as $line_num => $line ) {
			$line = trim( $line );
			if ( empty( $line ) ) {
				continue;
			}

			$cols  = str_getcsv( $line );
			$price = isset( $cols[ $col_price ] ) ? floatval( str_replace( ',', '.', trim( $cols[ $col_price ] ) ) ) : 0;

			if ( $price <= 0 ) {
				$log[] = sprintf( 'Line %d: skipped (invalid price)', $line_num + 2 );
				$skipped++;
				continue;
			}

			$product_id = 0;

			if ( false !== $col_sku && isset( $cols[ $col_sku ] ) ) {
				$sku        = trim( $cols[ $col_sku ] );
				$product_id = wc_get_product_id_by_sku( $sku );
				if ( ! $product_id ) {
					$log[] = sprintf( 'Line %d: SKU "%s" not found — skipped', $line_num + 2, $sku );
					$skipped++;
					continue;
				}
			} elseif ( false !== $col_id && isset( $cols[ $col_id ] ) ) {
				$product_id = absint( $cols[ $col_id ] );
			}

			if ( ! $product_id ) {
				$log[] = sprintf( 'Line %d: no product ID — skipped', $line_num + 2 );
				$skipped++;
				continue;
			}

			update_post_meta( $product_id, '_purchase_price_net', $price );
			$log[] = sprintf( 'Line %d: product #%d → purchase price set to %s', $line_num + 2, $product_id, $price );
			$updated++;
		}

		$message = sprintf(
			/* translators: 1: updated count, 2: skipped count */
			__( 'Done: %1$d updated, %2$d skipped.', 'margin-calculator-pro' ),
			$updated,
			$skipped
		);

		wp_send_json_success( array(
			'message' => $message,
			'log'     => $log,
		) );
	}

	/**
	 * AJAX: Export CSV.
	 */
	public function ajax_export_csv() {
		if ( ! isset( $_POST['wcmc_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wcmc_nonce'] ), 'wcmc_export_csv' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'margin-calculator-pro' ) );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'margin-calculator-pro' ) );
		}

		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.ID, pm_sku.meta_value as sku, pm_purchase.meta_value as purchase_price, pm_price.meta_value as regular_price
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} pm_purchase ON p.ID = pm_purchase.post_id AND pm_purchase.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} pm_price ON p.ID = pm_price.post_id AND pm_price.meta_key = %s
				WHERE p.post_type IN (%s, %s) AND p.post_status = %s
				ORDER BY pm_sku.meta_value ASC",
				'_sku', '_purchase_price_net', '_regular_price',
				'product', 'product_variation', 'publish'
			)
		);

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="purchase-prices-' . gmdate( 'Y-m-d' ) . '.csv"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// Build CSV using output buffering - no direct filesystem calls
		ob_start();
		$columns = array( 'product_id', 'sku', 'purchase_price', 'regular_price' );
		echo esc_html( implode( ',', $columns ) ) . "\n";

		foreach ( $rows as $row ) {
			$line = array(
				intval( $row->ID ),
				'"' . str_replace( '"', '""', (string) $row->sku ) . '"',
				'"' . str_replace( '"', '""', (string) $row->purchase_price ) . '"',
				'"' . str_replace( '"', '""', (string) $row->regular_price ) . '"',
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo implode( ',', $line ) . "\n";
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo ob_get_clean();
		exit;
	}

	// ── REPORTS ──────────────────────────────────────────────────────────

	/**
	 * Render the margin reports page.
	 */
	public function reports_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view reports.', 'margin-calculator-pro' ) );
		}

		$months = array(
			1  => __( 'January', 'margin-calculator-pro' ),
			2  => __( 'February', 'margin-calculator-pro' ),
			3  => __( 'March', 'margin-calculator-pro' ),
			4  => __( 'April', 'margin-calculator-pro' ),
			5  => __( 'May', 'margin-calculator-pro' ),
			6  => __( 'June', 'margin-calculator-pro' ),
			7  => __( 'July', 'margin-calculator-pro' ),
			8  => __( 'August', 'margin-calculator-pro' ),
			9  => __( 'September', 'margin-calculator-pro' ),
			10 => __( 'October', 'margin-calculator-pro' ),
			11 => __( 'November', 'margin-calculator-pro' ),
			12 => __( 'December', 'margin-calculator-pro' ),
		);

		$current_year  = (int) gmdate( 'Y' );
		$current_month = (int) gmdate( 'n' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Margin Reports', 'margin-calculator-pro' ); ?></h1>

			<style>
				.wcmc-report-filters { background: #fff; padding: 15px 20px; border: 1px solid #ccd0d4; margin-bottom: 20px; }
				.wcmc-filter-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
				.wcmc-filter-row label { font-weight: 600; font-size: 13px; }
				.wcmc-filter-separator { color: #ccd0d4; font-size: 18px; margin: 0 2px; }
				.wcmc-quick-filter.active { background: #2271b1; color: #fff; border-color: #2271b1; }
				.wcmc-summary-cards { display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; }
				.wcmc-card { background: #fff; border: 1px solid #ccd0d4; padding: 15px 20px; min-width: 180px; flex: 1; }
				.wcmc-card h3 { margin: 0 0 5px; font-size: 13px; color: #666; font-weight: normal; }
				.wcmc-card .wcmc-card-value { font-size: 24px; font-weight: 700; }
				.wcmc-card .wcmc-card-value.positive { color: #4CAF50; }
				.wcmc-card .wcmc-card-value.negative { color: #C62828; }
				.wcmc-card .wcmc-card-value.neutral { color: #333; }
				.wcmc-report-table { width: 100%; border-collapse: collapse; background: #fff; }
				.wcmc-report-table th { background: #f0f0f1; padding: 10px 12px; text-align: left; font-weight: 600; border-bottom: 1px solid #ccd0d4; }
				.wcmc-sortable { cursor: pointer; user-select: none; }
				.wcmc-sortable:hover { background: #e5e5e5; }
				.wcmc-sortable::after { content: '\25B2\25BC'; font-size: 9px; margin-left: 5px; color: #999; letter-spacing: -2px; }
				.wcmc-sortable.wcmc-sort-asc::after { content: '\25B2'; color: #2271b1; letter-spacing: 0; }
				.wcmc-sortable.wcmc-sort-desc::after { content: '\25BC'; color: #2271b1; letter-spacing: 0; }
				.wcmc-report-table td { padding: 10px 12px; border-bottom: 1px solid #f0f0f1; }
				.wcmc-report-table tr:hover td { background: #f9f9f9; }
				#wcmc-report-loading { display: none; padding: 20px; text-align: center; color: #666; }
				#wcmc-report-empty { display: none; padding: 20px; text-align: center; color: #999; background: #fff; border: 1px solid #ccd0d4; }
			</style>

			<div class="wcmc-report-filters">
				<div class="wcmc-filter-row">
					<button type="button" class="button wcmc-quick-filter" data-filter="current_month">
						<?php esc_html_e( 'Current month', 'margin-calculator-pro' ); ?>
					</button>
					<button type="button" class="button wcmc-quick-filter" data-filter="previous_month">
						<?php esc_html_e( 'Previous month', 'margin-calculator-pro' ); ?>
					</button>

					<span class="wcmc-filter-separator">|</span>

					<select id="wcmc-month">
						<?php foreach ( $months as $num => $name ) : ?>
							<option value="<?php echo esc_attr( $num ); ?>" <?php selected( $num, $current_month ); ?>>
								<?php echo esc_html( $name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<select id="wcmc-year">
						<?php for ( $y = $current_year; $y >= 2026; $y-- ) : ?>
							<option value="<?php echo esc_attr( $y ); ?>"><?php echo esc_html( $y ); ?></option>
						<?php endfor; ?>
					</select>
					<button type="button" class="button button-primary" id="wcmc-apply-month">
						<?php esc_html_e( 'Show', 'margin-calculator-pro' ); ?>
					</button>

					<span class="wcmc-filter-separator">|</span>

					<label for="wcmc-date-from"><?php esc_html_e( 'From', 'margin-calculator-pro' ); ?></label>
					<input type="date" id="wcmc-date-from" value="<?php echo esc_attr( gmdate( 'Y-m-01' ) ); ?>">
					<label for="wcmc-date-to"><?php esc_html_e( 'To', 'margin-calculator-pro' ); ?></label>
					<input type="date" id="wcmc-date-to" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>">
					<button type="button" class="button button-primary" id="wcmc-apply-range">
						<?php esc_html_e( 'Show', 'margin-calculator-pro' ); ?>
					</button>
				</div>
			</div>

			<div id="wcmc-report-loading">
				<span class="spinner is-active" style="float:none;"></span>
				<?php esc_html_e( 'Loading report...', 'margin-calculator-pro' ); ?>
			</div>

			<div id="wcmc-report-empty">
				<?php esc_html_e( 'No orders found for the selected period.', 'margin-calculator-pro' ); ?>
			</div>

			<div id="wcmc-report-results" style="display:none;">
				<div class="wcmc-summary-cards">
					<div class="wcmc-card">
						<h3><?php esc_html_e( 'Orders', 'margin-calculator-pro' ); ?></h3>
						<div class="wcmc-card-value neutral" id="wcmc-total-orders">0</div>
					</div>
					<div class="wcmc-card">
						<h3><?php esc_html_e( 'Net revenue', 'margin-calculator-pro' ); ?></h3>
						<div class="wcmc-card-value neutral" id="wcmc-total-revenue">0</div>
					</div>
					<div class="wcmc-card">
						<h3><?php esc_html_e( 'Total cost', 'margin-calculator-pro' ); ?></h3>
						<div class="wcmc-card-value neutral" id="wcmc-total-cost">0</div>
					</div>
					<div class="wcmc-card">
						<h3><?php esc_html_e( 'Profit', 'margin-calculator-pro' ); ?></h3>
						<div class="wcmc-card-value" id="wcmc-total-profit">0</div>
					</div>
					<div class="wcmc-card">
						<h3><?php esc_html_e( 'Average margin', 'margin-calculator-pro' ); ?></h3>
						<div class="wcmc-card-value" id="wcmc-avg-margin">0%</div>
					</div>
				</div>

				<table class="wcmc-report-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Order', 'margin-calculator-pro' ); ?></th>
							<th><?php esc_html_e( 'Date', 'margin-calculator-pro' ); ?></th>
							<th><?php esc_html_e( 'Customer', 'margin-calculator-pro' ); ?></th>
							<th><?php esc_html_e( 'Net revenue', 'margin-calculator-pro' ); ?></th>
							<th><?php esc_html_e( 'Cost', 'margin-calculator-pro' ); ?></th>
							<th class="wcmc-sortable" data-sort="profit"><?php esc_html_e( 'Profit', 'margin-calculator-pro' ); ?></th>
							<th class="wcmc-sortable" data-sort="margin"><?php esc_html_e( 'Margin', 'margin-calculator-pro' ); ?></th>
						</tr>
					</thead>
					<tbody id="wcmc-report-tbody"></tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler for margin reports.
	 */
	public function ajax_get_report() {
		check_ajax_referer( 'wcmc_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$date_from = isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : '';
		$date_to   = isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : '';

		if ( empty( $date_from ) || empty( $date_to ) ) {
			wp_send_json_error( 'Invalid dates' );
		}

		// Validate date format.
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			wp_send_json_error( 'Invalid date format' );
		}

		$args = array(
			'type'       => 'shop_order',
			'status'     => array( 'wc-completed', 'wc-processing' ),
			'limit'      => 500,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'date_after' => $date_from . ' 00:00:00',
			'date_before' => $date_to . ' 23:59:59',
		);

		$orders = wc_get_orders( $args );
		$rows   = array();

		foreach ( $orders as $order ) {
			$data = $this->calculate_order_margin( $order );

			$order_number = $order->get_order_number();
			$edit_url     = $order->get_edit_order_url();
			$customer     = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

			if ( empty( $customer ) ) {
				$customer = $order->get_billing_email();
			}

			$date_created = $order->get_date_created();
			$date_display = $date_created ? $date_created->date_i18n( get_option( 'date_format' ) ) : '';

			if ( is_null( $data ) ) {
				$rows[] = array(
					'order_number' => $order_number,
					'edit_url'     => $edit_url,
					'date'         => $date_display,
					'customer'     => $customer,
					'revenue'      => floatval( $order->get_total() ),
					'cost'         => 0,
					'profit'       => 0,
					'margin'       => null,
				);
				continue;
			}

			$rows[] = array(
				'order_number' => $order_number,
				'edit_url'     => $edit_url,
				'date'         => $date_display,
				'customer'     => $customer,
				'revenue'      => $data['revenue'],
				'cost'         => $data['cost'],
				'profit'       => $data['profit'],
				'margin'       => $data['margin'],
			);
		}

		// Calculate totals.
		$total_revenue = 0;
		$total_cost    = 0;
		$total_profit  = 0;

		foreach ( $rows as $row ) {
			$total_revenue += $row['revenue'];
			$total_cost    += $row['cost'];
			$total_profit  += $row['profit'];
		}

		$avg_margin = $total_revenue > 0 ? round( ( $total_profit / $total_revenue ) * 100, 2 ) : 0;

		wp_send_json_success( array(
			'orders'        => $rows,
			'total_orders'  => count( $rows ),
			'total_revenue' => round( $total_revenue, 2 ),
			'total_cost'    => round( $total_cost, 2 ),
			'total_profit'  => round( $total_profit, 2 ),
			'avg_margin'    => $avg_margin,
			'currency'      => get_woocommerce_currency_symbol(),
		) );
	}

	// ── HELPERS ───────────────────────────────────────────────────────────

	private function parse_price( $price ) {
		if ( is_array( $price ) ) {
			$price = isset( $price[0] ) ? $price[0] : '';
		}
		$price = strval( $price );
		if ( preg_match( '/[\d.,]+/', $price, $matches ) ) {
			return floatval( str_replace( ',', '.', $matches[0] ) );
		}
		return 0;
	}
}

function wcmc_init() {
	return WC_Margin_Calculator_Pro::instance();
}

add_action( 'plugins_loaded', 'wcmc_init' );
