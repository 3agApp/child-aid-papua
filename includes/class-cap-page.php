<?php
/**
 * Serves the standalone Child Aid Papua story page at /child-aid-papua.
 */

defined( 'ABSPATH' ) || exit;

class CAP_Page {

	const QUERY_VAR = 'cap_child_aid_page';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'register_query_var' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_page' ) );
	}

	public static function register_rewrite() {
		add_rewrite_rule(
			'^' . CAP_PAGE_SLUG . '/?$',
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
	}

	public static function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function maybe_render_page() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		$template = CAP_PLUGIN_DIR . 'templates/child-aid-papua-page.html';
		if ( ! file_exists( $template ) ) {
			wp_die( esc_html__( 'Die Child-Aid-Papua-Seite wurde nicht gefunden.', 'child-aid-papua' ), 404 );
		}

		status_header( 200 );
		header( 'Content-Type: text/html; charset=UTF-8' );
		readfile( $template );
		exit;
	}
}
