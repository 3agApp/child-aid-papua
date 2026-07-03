<?php
/**
 * Checkout integration: shows the donation notice and stores the donation
 * amount on every order so it can be reported later.
 */

defined( 'ABSPATH' ) || exit;

class CAP_Checkout {

	const META_AMOUNT     = '_cap_donation_amount';
	const META_PERCENTAGE = '_cap_donation_percentage';

	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) && ! defined( 'WC_PLUGIN_FILE' ) ) {
			add_action( 'plugins_loaded', array( __CLASS__, 'init_hooks' ) );
			return;
		}
		self::init_hooks();
	}

	public static function init_hooks() {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		// Classic checkout: row below the order total in the review table.
		add_action( 'woocommerce_review_order_after_order_total', array( __CLASS__, 'render_checkout_row' ) );
		// Cart page, below the totals.
		add_action( 'woocommerce_cart_totals_after_order_total', array( __CLASS__, 'render_checkout_row' ) );
		// Block-based checkout has no PHP render hooks, so also print the
		// notice above the checkout form / via shortcode as a fallback.
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'render_notice_box' ) );
		add_shortcode( 'child_aid_donation_notice', array( __CLASS__, 'shortcode_notice' ) );

		// Persist the donation on the order (classic + Store API / block checkout).
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'store_donation_meta' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'store_donation_meta_and_save' ) );

		// Thank-you page and order emails.
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'render_thankyou_notice' ), 5 );
		add_action( 'woocommerce_email_order_meta', array( __CLASS__, 'render_email_notice' ), 10, 3 );
	}

	/**
	 * Donation for the current cart, based on the cart total (incl. shipping
	 * and taxes). Filter 'cap_donation_cart_base' to change the base.
	 */
	public static function get_cart_donation() {
		if ( ! WC()->cart ) {
			return 0.0;
		}
		$base = (float) apply_filters( 'cap_donation_cart_base', WC()->cart->get_total( 'edit' ) );
		return cap_calculate_donation( $base );
	}

	public static function get_order_donation( $order ) {
		$stored = $order->get_meta( self::META_AMOUNT );
		if ( '' !== $stored ) {
			return (float) $stored;
		}
		return cap_calculate_donation( $order->get_total() - $order->get_total_refunded() );
	}

	private static function notice_text( $amount, $currency = '' ) {
		$price = wc_price( $amount, $currency ? array( 'currency' => $currency ) : array() );
		return sprintf(
			/* translators: 1: donation amount, 2: link to the story page */
			__( 'Mit dieser Bestellung spenden wir %1$s an das Child-Aid-Papua-Schulprojekt in Raja Ampat. %2$s', 'child-aid-papua' ),
			'<strong>' . $price . '</strong>',
			'<a href="' . esc_url( cap_get_page_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Lies hier die Geschichte dahinter →', 'child-aid-papua' ) . '</a>'
		);
	}

	/** Row inside the order-review / cart-totals table. */
	public static function render_checkout_row() {
		$amount = self::get_cart_donation();
		if ( $amount <= 0 ) {
			return;
		}
		echo '<tr class="cap-donation-row"><td colspan="2" style="padding-top:14px;font-size:.92em;line-height:1.5;background:#f0f7f7;border-radius:8px;">';
		echo '<span style="margin-right:.4em;">💙</span>' . wp_kses_post( self::notice_text( $amount ) );
		echo '</td></tr>';
	}

	/** Standalone notice box (block checkout fallback + shortcode). */
	public static function render_notice_box() {
		$amount = self::get_cart_donation();
		if ( $amount <= 0 ) {
			return;
		}
		echo self::notice_box_html( $amount ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function shortcode_notice() {
		$amount = self::get_cart_donation();
		if ( $amount <= 0 ) {
			return '';
		}
		return self::notice_box_html( $amount );
	}

	private static function notice_box_html( $amount ) {
		return '<div class="cap-donation-notice" style="display:flex;gap:.7em;align-items:flex-start;background:#f0f7f7;border:1px solid #cde5e6;border-radius:10px;padding:14px 18px;margin:0 0 20px;font-size:.95em;line-height:1.55;">'
			. '<span aria-hidden="true" style="font-size:1.3em;line-height:1.2;">💙</span>'
			. '<span>' . wp_kses_post( self::notice_text( $amount ) ) . '</span>'
			. '</div>';
	}

	/** Classic checkout: order object is saved by WooCommerce afterwards. */
	public static function store_donation_meta( $order ) {
		if ( '' !== $order->get_meta( self::META_AMOUNT ) ) {
			return; // Already set (e.g. Store API hook ran first).
		}
		$percentage = cap_get_donation_percentage();
		$base       = (float) apply_filters( 'cap_donation_order_base', $order->get_total(), $order );
		$order->update_meta_data( self::META_AMOUNT, cap_calculate_donation( $base, $percentage ) );
		$order->update_meta_data( self::META_PERCENTAGE, $percentage );
	}

	/** Store API / block checkout: we must save ourselves. */
	public static function store_donation_meta_and_save( $order ) {
		self::store_donation_meta( $order );
		$order->save();
	}

	public static function render_thankyou_notice( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$amount = self::get_order_donation( $order );
		if ( $amount <= 0 ) {
			return;
		}
		echo self::notice_box_html( $amount ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function render_email_notice( $order, $sent_to_admin, $plain_text ) {
		$amount = self::get_order_donation( $order );
		if ( $amount <= 0 ) {
			return;
		}
		if ( $plain_text ) {
			printf(
				/* translators: 1: donation amount, 2: story page URL */
				esc_html__( 'Mit dieser Bestellung spenden wir %1$s an das Child-Aid-Papua-Schulprojekt: %2$s', 'child-aid-papua' ) . "\n\n",
				esc_html( wp_strip_all_tags( wc_price( $amount, array( 'currency' => $order->get_currency() ) ) ) ),
				esc_url( cap_get_page_url() )
			);
			return;
		}
		echo self::notice_box_html( $amount ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
