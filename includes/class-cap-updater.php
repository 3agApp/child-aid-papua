<?php
/**
 * Plugin updater: automatic updates from GitHub Releases
 * (github.com/3agApp/child-aid-papua), no licensing required.
 */

defined( 'ABSPATH' ) || exit;

class CAP_Updater {

	const GITHUB_OWNER     = '3agApp';
	const GITHUB_REPO      = 'child-aid-papua';
	const PRODUCT_SLUG     = 'child-aid-papua';
	const CACHE_KEY        = 'cap_update_data';
	const CACHE_EXPIRATION = 43200; // 12 hours.

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_for_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_post_install', array( __CLASS__, 'after_install' ), 10, 3 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'enable_auto_update' ), 10, 2 );
		add_filter( 'plugin_action_links_' . CAP_PLUGIN_BASENAME, array( __CLASS__, 'action_links' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_check_updates' ) );
	}

	/** Keep this plugin on WordPress auto-updates. */
	public static function enable_auto_update( $update, $item ) {
		if ( isset( $item->plugin ) && CAP_PLUGIN_BASENAME === $item->plugin ) {
			return true;
		}
		return $update;
	}

	private static function get_github_api_url() {
		return sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', self::GITHUB_OWNER, self::GITHUB_REPO );
	}

	private static function get_github_repo_url() {
		return sprintf( 'https://github.com/%s/%s', self::GITHUB_OWNER, self::GITHUB_REPO );
	}

	/** Inject the GitHub release into the WordPress update transient. */
	public static function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$update_data = self::get_update_data();

		if ( ! $update_data || empty( $update_data['version'] ) ) {
			return $transient;
		}

		if ( version_compare( CAP_VERSION, $update_data['version'], '<' ) ) {
			$transient->response[ CAP_PLUGIN_BASENAME ] = (object) array(
				'slug'         => self::PRODUCT_SLUG,
				'plugin'       => CAP_PLUGIN_BASENAME,
				'new_version'  => $update_data['version'],
				'url'          => self::get_github_repo_url(),
				'package'      => $update_data['download_url'],
				'tested'       => '6.8',
				'requires'     => '6.0',
				'requires_php' => '7.4',
			);
		} else {
			$transient->no_update[ CAP_PLUGIN_BASENAME ] = (object) array(
				'slug'        => self::PRODUCT_SLUG,
				'plugin'      => CAP_PLUGIN_BASENAME,
				'new_version' => CAP_VERSION,
				'url'         => self::get_github_repo_url(),
			);
		}

		return $transient;
	}

	/** Latest-release data from the GitHub API, cached in a transient. */
	public static function get_update_data( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$response = wp_remote_get( self::get_github_api_url(), array(
			'timeout' => 30,
			'headers' => array(
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
			),
		) );

		if ( is_wp_error( $response ) ) {
			error_log( 'CAP GitHub Update Check Error: ' . $response->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $data ) ) {
			if ( 404 === $code ) {
				error_log( 'CAP GitHub Update Check: No releases found' );
			} else {
				error_log( 'CAP GitHub Update Check HTTP ' . $code . ': ' . ( isset( $data['message'] ) ? $data['message'] : 'Unknown error' ) );
			}
			return null;
		}

		$version      = isset( $data['tag_name'] ) ? ltrim( $data['tag_name'], 'v' ) : null;
		$download_url = null;

		if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
			foreach ( $data['assets'] as $asset ) {
				if ( isset( $asset['name'] ) && false !== strpos( $asset['name'], '-latest.zip' ) ) {
					$download_url = $asset['browser_download_url'];
					break;
				}
				if ( isset( $asset['name'] ) && preg_match( '/\.zip$/', $asset['name'] ) ) {
					$download_url = $asset['browser_download_url'];
				}
			}
		}

		if ( empty( $download_url ) && ! empty( $data['zipball_url'] ) ) {
			$download_url = $data['zipball_url'];
		}

		$update_data = array(
			'version'      => $version,
			'download_url' => $download_url,
			'changelog'    => isset( $data['body'] ) ? $data['body'] : '',
			'release_date' => isset( $data['published_at'] ) ? $data['published_at'] : null,
			'checked'      => time(),
		);

		set_transient( self::CACHE_KEY, $update_data, self::CACHE_EXPIRATION );

		return $update_data;
	}

	/** "View details" popup on the plugins screen. */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( ! isset( $args->slug ) || self::PRODUCT_SLUG !== $args->slug ) {
			return $result;
		}

		$update_data = self::get_update_data();

		return (object) array(
			'name'           => 'Child Aid Papua – 1% Spende',
			'slug'           => self::PRODUCT_SLUG,
			'version'        => isset( $update_data['version'] ) && $update_data['version'] ? $update_data['version'] : CAP_VERSION,
			'author'         => '<a href="https://github.com/' . self::GITHUB_OWNER . '">3ag.education</a>',
			'author_profile' => 'https://github.com/' . self::GITHUB_OWNER,
			'homepage'       => self::get_github_repo_url(),
			'requires'       => '6.0',
			'tested'         => '6.8',
			'requires_php'   => '7.4',
			'last_updated'   => isset( $update_data['release_date'] ) && $update_data['release_date'] ? $update_data['release_date'] : gmdate( 'Y-m-d H:i:s' ),
			'sections'       => array(
				'description' => self::get_plugin_description(),
				'changelog'   => self::get_changelog( $update_data ),
			),
			'download_link'  => isset( $update_data['download_url'] ) ? $update_data['download_url'] : '',
		);
	}

	private static function get_plugin_description() {
		return '<p>' . esc_html__( 'Zeigt die Child-Aid-Papua-Story unter /child-aid-papua, informiert im Checkout über die 1%-Spende vom Umsatz und liefert einen Spendenbericht im Backend.', 'child-aid-papua' ) . '</p>'
			. '<p><a href="' . esc_url( self::get_github_repo_url() ) . '" target="_blank">GitHub</a></p>';
	}

	private static function get_changelog( $update_data = null ) {
		if ( ! empty( $update_data['changelog'] ) ) {
			$changelog = $update_data['changelog'];
			$changelog = preg_replace( '/^### (.+)$/m', '<h4>$1</h4>', $changelog );
			$changelog = preg_replace( '/^## (.+)$/m', '<h3>$1</h3>', $changelog );
			$changelog = preg_replace( '/^[*-] (.+)$/m', '<li>$1</li>', $changelog );
			$changelog = preg_replace( '/(<li>.+<\/li>\n?)+/s', '<ul>$0</ul>', $changelog );
			return nl2br( $changelog );
		}
		return '<p><a href="' . esc_url( self::get_github_repo_url() . '/releases' ) . '" target="_blank">' . esc_html__( 'Änderungsprotokoll auf GitHub ansehen', 'child-aid-papua' ) . '</a></p>';
	}

	/** After install, make sure the plugin ends up in its original directory. */
	public static function after_install( $response, $hook_extra, $result ) {
		global $wp_filesystem;

		$is_our_plugin = false;
		if ( isset( $hook_extra['plugin'] ) && CAP_PLUGIN_BASENAME === $hook_extra['plugin'] ) {
			$is_our_plugin = true;
		} elseif ( isset( $result['destination_name'] ) && dirname( CAP_PLUGIN_BASENAME ) === $result['destination_name'] ) {
			$is_our_plugin = true;
		}

		if ( ! $is_our_plugin ) {
			return $response;
		}

		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}
		if ( empty( $wp_filesystem ) || ! is_object( $wp_filesystem ) ) {
			return $response;
		}

		$plugin_dir = WP_PLUGIN_DIR . '/' . dirname( CAP_PLUGIN_BASENAME );
		if ( $wp_filesystem->exists( $result['destination'] ) && $result['destination'] !== $plugin_dir ) {
			$wp_filesystem->move( $result['destination'], $plugin_dir );
			$result['destination'] = $plugin_dir;
		}

		return $response;
	}

	/** "Nach Updates suchen" link in the plugin row. */
	public static function action_links( $links ) {
		if ( current_user_can( 'update_plugins' ) ) {
			$url     = wp_nonce_url( add_query_arg( 'cap_check_updates', '1', self_admin_url( 'plugins.php' ) ), 'cap_check_updates' );
			$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Nach Updates suchen', 'child-aid-papua' ) . '</a>';
		}
		return $links;
	}

	/** Manual update check (plugins row link or report-page button). */
	public static function maybe_check_updates() {
		if ( ! isset( $_GET['cap_check_updates'] ) ) {
			return;
		}
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		check_admin_referer( 'cap_check_updates' );

		self::force_check();
		$update_data = self::get_update_data();

		add_action( 'admin_notices', function () use ( $update_data ) {
			if ( $update_data && ! empty( $update_data['version'] ) && version_compare( CAP_VERSION, $update_data['version'], '<' ) ) {
				printf(
					'<div class="notice notice-warning is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
					sprintf(
						/* translators: %s: new version number */
						esc_html__( 'Child Aid Papua: Version %s ist verfügbar.', 'child-aid-papua' ),
						esc_html( $update_data['version'] )
					),
					esc_url( self::get_upgrade_url() ),
					esc_html__( 'Jetzt aktualisieren', 'child-aid-papua' )
				);
			} elseif ( $update_data && ! empty( $update_data['version'] ) ) {
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					sprintf(
						/* translators: %s: installed version number */
						esc_html__( 'Child Aid Papua ist aktuell (Version %s).', 'child-aid-papua' ),
						esc_html( CAP_VERSION )
					)
				);
			} else {
				printf(
					'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
					esc_html__( 'Update-Prüfung fehlgeschlagen. Bitte später erneut versuchen.', 'child-aid-papua' )
				);
			}
		} );
	}

	/** Core upgrade URL for this plugin. */
	private static function get_upgrade_url() {
		return wp_nonce_url(
			self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( CAP_PLUGIN_BASENAME ) ),
			'upgrade-plugin_' . CAP_PLUGIN_BASENAME
		);
	}

	/** Update section on the donation report / settings page. */
	public static function render_settings_section() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$update_data = get_transient( self::CACHE_KEY );
		$latest      = ( $update_data && ! empty( $update_data['version'] ) ) ? $update_data['version'] : '';
		$has_update  = $latest && version_compare( CAP_VERSION, $latest, '<' );

		$check_url = wp_nonce_url( add_query_arg( array(
			'page'              => 'cap-donation-report',
			'cap_check_updates' => '1',
		), admin_url( 'admin.php' ) ), 'cap_check_updates' );
		?>
		<h2 style="margin-top:36px;"><?php esc_html_e( 'Plugin-Update', 'child-aid-papua' ); ?></h2>
		<table class="form-table" style="max-width:520px;">
			<tr>
				<th scope="row"><?php esc_html_e( 'Installierte Version', 'child-aid-papua' ); ?></th>
				<td><?php echo esc_html( CAP_VERSION ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Neueste Version', 'child-aid-papua' ); ?></th>
				<td>
					<?php echo $latest ? esc_html( $latest ) : esc_html__( 'Noch nicht geprüft', 'child-aid-papua' ); ?>
					<?php if ( $update_data && ! empty( $update_data['checked'] ) ) : ?>
						<span style="color:#787c82;">
							(<?php
							printf(
								/* translators: %s: human-readable time difference */
								esc_html__( 'geprüft vor %s', 'child-aid-papua' ),
								esc_html( human_time_diff( $update_data['checked'] ) )
							);
							?>)
						</span>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<p>
			<a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php esc_html_e( 'Nach Updates suchen', 'child-aid-papua' ); ?></a>
			<?php if ( $has_update ) : ?>
				<a class="button button-primary" style="margin-left:6px;" href="<?php echo esc_url( self::get_upgrade_url() ); ?>">
					<?php
					printf(
						/* translators: %s: new version number */
						esc_html__( 'Jetzt auf Version %s aktualisieren', 'child-aid-papua' ),
						esc_html( $latest )
					);
					?>
				</a>
			<?php endif; ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Updates werden über GitHub-Releases bereitgestellt und automatisch installiert.', 'child-aid-papua' ); ?>
			<a href="<?php echo esc_url( self::get_github_repo_url() . '/releases' ); ?>" target="_blank" rel="noopener">GitHub</a>
		</p>
		<?php
	}

	public static function clear_cache() {
		delete_transient( self::CACHE_KEY );
	}

	/** Clear caches and let WordPress re-check immediately. */
	public static function force_check() {
		self::clear_cache();
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
	}
}
