<?php
/**
 * Admin report: donation total for a date range, per-order breakdown,
 * CSV export and the percentage setting.
 */

defined( 'ABSPATH' ) || exit;

class CAP_Report {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 60 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_export_csv' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_save_settings' ) );
	}

	public static function register_menu() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		add_submenu_page(
			'woocommerce',
			__( 'Child Aid Papua Spenden', 'child-aid-papua' ),
			__( 'Child Aid Spenden', 'child-aid-papua' ),
			'manage_woocommerce',
			'cap-donation-report',
			array( __CLASS__, 'render_page' )
		);
	}

	/** Date range from the request, defaulting to the current month. */
	private static function get_range() {
		$from = isset( $_GET['cap_from'] ) ? sanitize_text_field( wp_unslash( $_GET['cap_from'] ) ) : '';
		$to   = isset( $_GET['cap_to'] ) ? sanitize_text_field( wp_unslash( $_GET['cap_to'] ) ) : '';

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
			$from = gmdate( 'Y-m-01' );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			$to = gmdate( 'Y-m-d' );
		}
		return array( $from, $to );
	}

	/**
	 * Orders in the range that count towards the donation.
	 * Statuses: processing + completed. Filter 'cap_report_order_statuses'.
	 */
	private static function get_orders( $from, $to ) {
		$statuses = apply_filters( 'cap_report_order_statuses', array( 'processing', 'completed' ) );

		return wc_get_orders( array(
			'limit'        => -1,
			'type'         => 'shop_order',
			'status'       => $statuses,
			'date_created' => $from . '...' . $to . ' 23:59:59',
			'orderby'      => 'date',
			'order'        => 'ASC',
		) );
	}

	/**
	 * Report rows. The donation is recalculated from the current order total
	 * minus refunds, using the percentage stored at checkout time (falls back
	 * to the current setting for orders placed before the plugin existed).
	 */
	private static function build_rows( $orders ) {
		$rows = array();
		foreach ( $orders as $order ) {
			$stored_pct = $order->get_meta( CAP_Checkout::META_PERCENTAGE );
			$percentage = '' !== $stored_pct ? (float) $stored_pct : cap_get_donation_percentage();
			$refunded   = (float) $order->get_total_refunded();
			$base       = (float) $order->get_total() - $refunded;
			$rows[]     = array(
				'order'      => $order,
				'number'     => $order->get_order_number(),
				'date'       => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '',
				'customer'   => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'status'     => wc_get_order_status_name( $order->get_status() ),
				'total'      => (float) $order->get_total(),
				'refunded'   => $refunded,
				'percentage' => $percentage,
				'donation'   => cap_calculate_donation( $base, $percentage ),
				'currency'   => $order->get_currency(),
			);
		}
		return $rows;
	}

	public static function maybe_save_settings() {
		if ( ! isset( $_POST['cap_save_settings'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		check_admin_referer( 'cap_save_settings' );

		$pct = isset( $_POST['cap_donation_percentage'] ) ? (float) wp_unslash( $_POST['cap_donation_percentage'] ) : CAP_DEFAULT_PERCENTAGE;
		$pct = max( 0.01, min( 100, $pct ) );
		update_option( 'cap_donation_percentage', $pct );

		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Einstellungen gespeichert.', 'child-aid-papua' ) . '</p></div>';
		} );
	}

	public static function maybe_export_csv() {
		if ( ! isset( $_GET['cap_export'] ) || '1' !== $_GET['cap_export'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'child-aid-papua' ) );
		}
		check_admin_referer( 'cap_export_csv' );

		list( $from, $to ) = self::get_range();
		$rows = self::build_rows( self::get_orders( $from, $to ) );

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="child-aid-papua-spenden-' . $from . '-bis-' . $to . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		// UTF-8 BOM so Excel opens umlauts correctly.
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'Bestellung', 'Datum', 'Kunde', 'Status', 'Bestelltotal', 'Rückerstattet', 'Prozentsatz', 'Spende', 'Währung' ), ';' );

		$sum = 0;
		foreach ( $rows as $row ) {
			$sum += $row['donation'];
			fputcsv( $out, array(
				$row['number'],
				$row['date'],
				$row['customer'],
				$row['status'],
				number_format( $row['total'], 2, '.', '' ),
				number_format( $row['refunded'], 2, '.', '' ),
				$row['percentage'] . '%',
				number_format( $row['donation'], 2, '.', '' ),
				$row['currency'],
			), ';' );
		}
		fputcsv( $out, array( 'TOTAL', '', '', '', '', '', '', number_format( $sum, 2, '.', '' ), $rows ? $rows[0]['currency'] : '' ), ';' );
		fclose( $out );
		exit;
	}

	public static function render_page() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			echo '<div class="wrap"><p>' . esc_html__( 'WooCommerce ist nicht aktiv.', 'child-aid-papua' ) . '</p></div>';
			return;
		}

		list( $from, $to ) = self::get_range();
		$rows  = self::build_rows( self::get_orders( $from, $to ) );
		$total = 0;
		foreach ( $rows as $row ) {
			$total += $row['donation'];
		}
		$currency = $rows ? $rows[0]['currency'] : get_woocommerce_currency();

		$export_url = wp_nonce_url( add_query_arg( array(
			'page'       => 'cap-donation-report',
			'cap_from'   => $from,
			'cap_to'     => $to,
			'cap_export' => '1',
		), admin_url( 'admin.php' ) ), 'cap_export_csv' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Child Aid Papua – Spendenbericht', 'child-aid-papua' ); ?></h1>
			<p><?php
				printf(
					/* translators: %s: link to the story page */
					esc_html__( 'Spenden aus dem 3ag.education-Shop (Prozentsatz vom Bestelltotal abzüglich Rückerstattungen). Story-Seite: %s', 'child-aid-papua' ),
					'<a href="' . esc_url( cap_get_page_url() ) . '" target="_blank">' . esc_html( cap_get_page_url() ) . '</a>'
				);
			?></p>

			<form method="get" style="margin:16px 0;">
				<input type="hidden" name="page" value="cap-donation-report">
				<label><?php esc_html_e( 'Von:', 'child-aid-papua' ); ?>
					<input type="date" name="cap_from" value="<?php echo esc_attr( $from ); ?>"></label>
				<label style="margin-left:10px;"><?php esc_html_e( 'Bis:', 'child-aid-papua' ); ?>
					<input type="date" name="cap_to" value="<?php echo esc_attr( $to ); ?>"></label>
				<button class="button button-primary" style="margin-left:10px;"><?php esc_html_e( 'Bericht anzeigen', 'child-aid-papua' ); ?></button>
				<a class="button" style="margin-left:6px;" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'CSV exportieren', 'child-aid-papua' ); ?></a>
			</form>

			<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #0e7c86;padding:14px 20px;margin-bottom:20px;max-width:520px;">
				<p style="margin:0;font-size:14px;color:#50575e;">
					<?php
					printf(
						/* translators: 1: from date, 2: to date, 3: order count */
						esc_html__( 'Spendenbetrag %1$s bis %2$s (%3$d Bestellungen):', 'child-aid-papua' ),
						esc_html( $from ),
						esc_html( $to ),
						count( $rows )
					);
					?>
				</p>
				<p style="margin:4px 0 0;font-size:28px;font-weight:600;color:#0e7c86;">
					<?php echo wp_kses_post( wc_price( $total, array( 'currency' => $currency ) ) ); ?>
				</p>
			</div>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Bestellung', 'child-aid-papua' ); ?></th>
						<th><?php esc_html_e( 'Datum', 'child-aid-papua' ); ?></th>
						<th><?php esc_html_e( 'Kunde', 'child-aid-papua' ); ?></th>
						<th><?php esc_html_e( 'Status', 'child-aid-papua' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Bestelltotal', 'child-aid-papua' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Rückerstattet', 'child-aid-papua' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Spende', 'child-aid-papua' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'Keine Bestellungen im gewählten Zeitraum.', 'child-aid-papua' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( $row['order']->get_edit_order_url() ); ?>">#<?php echo esc_html( $row['number'] ); ?></a></td>
							<td><?php echo esc_html( $row['date'] ); ?></td>
							<td><?php echo esc_html( $row['customer'] ); ?></td>
							<td><?php echo esc_html( $row['status'] ); ?></td>
							<td style="text-align:right;"><?php echo wp_kses_post( wc_price( $row['total'], array( 'currency' => $row['currency'] ) ) ); ?></td>
							<td style="text-align:right;"><?php echo $row['refunded'] > 0 ? wp_kses_post( wc_price( $row['refunded'], array( 'currency' => $row['currency'] ) ) ) : '–'; ?></td>
							<td style="text-align:right;"><strong><?php echo wp_kses_post( wc_price( $row['donation'], array( 'currency' => $row['currency'] ) ) ); ?></strong></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2 style="margin-top:36px;"><?php esc_html_e( 'Einstellungen', 'child-aid-papua' ); ?></h2>
			<form method="post" style="max-width:520px;">
				<?php wp_nonce_field( 'cap_save_settings' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="cap_donation_percentage"><?php esc_html_e( 'Spende in % vom Umsatz', 'child-aid-papua' ); ?></label></th>
						<td>
							<input type="number" step="0.01" min="0.01" max="100" name="cap_donation_percentage" id="cap_donation_percentage"
								value="<?php echo esc_attr( cap_get_donation_percentage() ); ?>" style="width:90px;"> %
							<p class="description"><?php esc_html_e( 'Wird im Checkout angezeigt und pro Bestellung gespeichert. Bereits erfasste Bestellungen behalten ihren Prozentsatz.', 'child-aid-papua' ); ?></p>
						</td>
					</tr>
				</table>
				<p><button class="button button-primary" name="cap_save_settings" value="1"><?php esc_html_e( 'Speichern', 'child-aid-papua' ); ?></button></p>
			</form>

			<?php CAP_Updater::render_settings_section(); ?>
		</div>
		<?php
	}
}
