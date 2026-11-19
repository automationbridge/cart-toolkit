<?php
/**
 * GitHub updates: a plugin updates from its own repo's releases, through
 * WordPress's normal update screens.
 *
 * Drop-in, the same file in every plugin. The first copy loaded serves them all,
 * and the access key is saved once per site. A plugin whose releases repo is
 * public passes 'public' => true and needs no key at all: it reads the release
 * from github.com's own pages instead of the API, which has no hourly limit
 * per server. A plugin needs this header:
 *
 *     Update URI: https://github.com/OWNER/REPO
 *
 * and one call while it boots:
 *
 *     AB_GitHub_Updater::register( array(
 *         'file'  => __FILE__,
 *         'repo'  => 'OWNER/REPO',
 *         'asset' => 'plugin-folder.zip',
 *     ) );
 *
 * A release's tag is its version (v1.2.3) and it carries the zip as an asset,
 * with the plugin folder at the top of the zip. The asset is used instead of
 * GitHub's source zip because that one's folder is named after the commit.
 *
 * Pass 'own_screen' => false if the plugin shows render_panel() on its own page.
 * Otherwise the panel is at Settings > GitHub Updates.
 *
 * 'public' needs updater 1.1.0. If another plugin on the site loads an older
 * copy first, that copy serves everyone and still asks for a key.
 *
 * @package AB_GitHub_Updater
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'AB_GitHub_Updater' ) ) {

	class AB_GitHub_Updater {

		const VERSION = '1.1.0';

		/** Shared by every plugin on the site: { enabled: bool, token: string }. */
		const OPTION = 'ab_github_updater';

		/** Result of the last look at each repo, keyed by repo. */
		const STATUS_OPTION = 'ab_github_updater_status';

		const API = 'https://api.github.com/repos/';

		/** Public repos are read here, without a key. */
		const WEB = 'https://github.com/';

		const CACHE_SECONDS = 900;

		const CAPABILITY = 'update_plugins';

		const SCREEN = 'ab-github-updates';

		private static $plugins = array();

		private static $booted = false;

		private static $force = false;

		/** Repos already fetched this request, so a forced check calls GitHub once. */
		private static $fetched = array();

		/**
		 * @param array $args { file: string, repo: string, asset: string, own_screen?: bool, public?: bool }
		 */
		public static function register( $args ) {
			self::$plugins[ plugin_basename( $args['file'] ) ] = wp_parse_args( $args, array(
				'own_screen' => true,
				'public'     => false,
			) );

			if ( self::$booted ) {
				return;
			}

			self::$booted = true;

			add_filter( 'update_plugins_github.com', array( __CLASS__, 'offer_update' ), 10, 3 );
			add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
			add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 2 );
			add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
			add_action( 'admin_menu', array( __CLASS__, 'register_screen' ) );
			add_action( 'admin_post_ab_ghu_save', array( __CLASS__, 'handle_save' ) );
			add_action( 'admin_post_ab_ghu_check', array( __CLASS__, 'handle_check' ) );
		}

		/* ── Settings ───────────────────────────────────────────── */

		public static function get_settings() {
			return wp_parse_args( (array) get_option( self::OPTION, array() ), array(
				'enabled' => true,
				'token'   => '',
			) );
		}

		public static function save_settings( $settings ) {
			update_option( self::OPTION, array_merge( self::get_settings(), $settings ), false );
		}

		public static function is_enabled() {
			return ! empty( self::get_settings()['enabled'] );
		}

		public static function has_token() {
			return '' !== (string) self::get_settings()['token'];
		}

		/**
		 * True when any plugin on this site updates from a private repo.
		 */
		public static function needs_token() {
			foreach ( self::$plugins as $plugin ) {
				if ( empty( $plugin['public'] ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * True once the key is saved (when one is needed) and no plugin's last check failed.
		 */
		public static function is_ready() {
			if ( self::needs_token() && ! self::has_token() ) {
				return false;
			}

			$statuses = self::get_statuses();

			foreach ( self::$plugins as $plugin ) {
				if ( isset( $statuses[ $plugin['repo'] ] ) && empty( $statuses[ $plugin['repo'] ]['ok'] ) ) {
					return false;
				}
			}

			return true;
		}

		public static function get_statuses() {
			return (array) get_option( self::STATUS_OPTION, array() );
		}

		private static function set_status( $repo, $ok, $message, $version = '' ) {
			$statuses          = self::get_statuses();
			$statuses[ $repo ] = array(
				'ok'      => $ok,
				'message' => $message,
				'version' => $version,
				'checked' => time(),
			);

			update_option( self::STATUS_OPTION, $statuses, false );
		}

		/* ── Asking GitHub ──────────────────────────────────────── */

		/**
		 * The token only ever goes to api.github.com, never to the signed download link.
		 */
		private static function headers( $accept ) {
			return array(
				'Accept'        => $accept,
				'Authorization' => 'Bearer ' . self::get_settings()['token'],
				'User-Agent'    => 'AB-GitHub-Updater/' . self::VERSION,
			);
		}

		private static function cache_key( $repo ) {
			return 'ab_ghu_' . md5( $repo );
		}

		private static function is_forced() {
			return self::$force || ( ! empty( $_GET['force-check'] ) && current_user_can( self::CAPABILITY ) ); // phpcs:ignore WordPress.Security.NonceVerification -- read-only, WordPress's own "Check again" link
		}

		/**
		 * The newest published release, or null if there isn't a usable one.
		 *
		 * @return array|null { version: string, package: string, notes: string, url: string }
		 */
		public static function latest( $plugin ) {
			$repo = $plugin['repo'];

			if ( ! self::is_enabled() ) {
				return null;
			}

			if ( empty( $plugin['public'] ) && ! self::has_token() ) {
				self::set_status( $repo, false, 'No GitHub access key has been saved on this site.' );
				return null;
			}

			if ( isset( self::$fetched[ $repo ] ) ) {
				return self::$fetched[ $repo ]['release'];
			}

			if ( ! self::is_forced() ) {
				$cached = get_transient( self::cache_key( $repo ) );

				if ( is_array( $cached ) ) {
					return $cached['release'];
				}
			}

			$release = empty( $plugin['public'] ) ? self::fetch_release( $plugin ) : self::fetch_public_release( $plugin );

			self::$fetched[ $repo ] = array( 'release' => $release );
			set_transient( self::cache_key( $repo ), self::$fetched[ $repo ], self::CACHE_SECONDS );

			return $release;
		}

		private static function fetch_release( $plugin ) {
			$repo     = $plugin['repo'];
			$response = wp_remote_get( self::API . $repo . '/releases/latest', array(
				'timeout' => 10,
				'headers' => self::headers( 'application/vnd.github+json' ),
			) );

			if ( is_wp_error( $response ) ) {
				self::set_status( $repo, false, 'Could not reach GitHub: ' . $response->get_error_message() );
				return null;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( 200 !== $code ) {
				self::set_status( $repo, false, self::describe_failure( $code ) );
				return null;
			}

			$body    = json_decode( wp_remote_retrieve_body( $response ), true );
			$version = ltrim( (string) ( $body['tag_name'] ?? '' ), 'vV' );

			foreach ( (array) ( $body['assets'] ?? array() ) as $asset ) {
				if ( $plugin['asset'] === ( $asset['name'] ?? '' ) && '' !== $version ) {
					self::set_status( $repo, true, 'Connected.', $version );

					return array(
						'version' => $version,
						'package' => self::API . $repo . '/releases/assets/' . (int) $asset['id'],
						'notes'   => (string) ( $body['body'] ?? '' ),
						'url'     => (string) ( $body['html_url'] ?? '' ),
					);
				}
			}

			self::set_status( $repo, false, 'The latest release has no file named ' . $plugin['asset'] . '.' );

			return null;
		}

		/**
		 * A public repo, without the API: github.com/OWNER/REPO/releases/latest
		 * redirects to the newest release's tag, and the asset downloads from a
		 * fixed address. No key is sent, so none can leak.
		 */
		private static function fetch_public_release( $plugin ) {
			$repo     = $plugin['repo'];
			$response = wp_remote_head( self::WEB . $repo . '/releases/latest', array(
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => array( 'User-Agent' => 'AB-GitHub-Updater/' . self::VERSION ),
			) );

			if ( is_wp_error( $response ) ) {
				self::set_status( $repo, false, 'Could not reach GitHub: ' . $response->get_error_message() );
				return null;
			}

			$code     = (int) wp_remote_retrieve_response_code( $response );
			$location = (string) wp_remote_retrieve_header( $response, 'location' );

			if ( ! preg_match( '#/releases/tag/(v?(\d+(?:\.\d+)*))$#', $location, $match ) ) {
				self::set_status( $repo, false, 404 === $code || 302 === $code ? 'Nothing found. The repo may be private, or it has no published release yet.' : self::describe_failure( $code ) );
				return null;
			}

			$tag     = $match[1];
			$version = $match[2];
			$package = self::WEB . $repo . '/releases/download/' . $tag . '/' . $plugin['asset'];
			$asset   = wp_remote_head( $package, array(
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => array( 'User-Agent' => 'AB-GitHub-Updater/' . self::VERSION ),
			) );

			if ( is_wp_error( $asset ) || 302 !== (int) wp_remote_retrieve_response_code( $asset ) ) {
				self::set_status( $repo, false, 'The latest release has no file named ' . $plugin['asset'] . '.' );
				return null;
			}

			self::set_status( $repo, true, 'Connected.', $version );

			return array(
				'version' => $version,
				'package' => $package,
				'notes'   => '',
				'url'     => self::WEB . $repo . '/releases/tag/' . $tag,
			);
		}

		private static function describe_failure( $code ) {
			if ( 401 === $code ) {
				return 'GitHub rejected the access key. It may have expired.';
			}

			if ( 403 === $code ) {
				return 'GitHub refused the request. The key may lack access, or the site is checking too often.';
			}

			if ( 404 === $code ) {
				return 'Nothing found. Either the key cannot see this repo, or it has no published release yet.';
			}

			return 'GitHub answered with an error (' . $code . ').';
		}

		/**
		 * Ask again now instead of waiting for WordPress's twice-daily check.
		 *
		 * @return array[] One row per plugin. See get_rows().
		 */
		public static function check_now() {
			self::$force   = true;
			self::$fetched = array();

			delete_site_transient( 'update_plugins' );
			wp_update_plugins();

			self::$force = false;

			return self::get_rows();
		}

		/* ── WordPress's update screens ─────────────────────────── */

		/**
		 * Core calls this for every plugin whose Update URI is on github.com and
		 * decides for itself whether the version is newer.
		 */
		public static function offer_update( $update, $plugin_data, $plugin_file ) {
			if ( ! isset( self::$plugins[ $plugin_file ] ) ) {
				return $update;
			}

			$release = self::latest( self::$plugins[ $plugin_file ] );

			if ( ! $release ) {
				return $update;
			}

			return array(
				'slug'    => dirname( $plugin_file ),
				'version' => $release['version'],
				'url'     => 'https://github.com/' . self::$plugins[ $plugin_file ]['repo'],
				'package' => $release['package'],
			);
		}

		/**
		 * The "View details" popup, which would otherwise look on wordpress.org.
		 */
		public static function plugin_info( $result, $action, $args ) {
			if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
				return $result;
			}

			foreach ( self::$plugins as $plugin_file => $plugin ) {
				if ( dirname( $plugin_file ) !== $args->slug ) {
					continue;
				}

				$release = self::latest( $plugin );

				if ( ! $release ) {
					return $result;
				}

				$data = get_file_data( $plugin['file'], array( 'Name' => 'Plugin Name', 'Author' => 'Author' ) );

				return (object) array(
					'name'     => $data['Name'],
					'slug'     => $args->slug,
					'version'  => $release['version'],
					'author'   => esc_html( $data['Author'] ),
					'homepage' => $release['url'],
					'sections' => array( 'changelog' => '' !== $release['notes'] ? nl2br( esc_html( $release['notes'] ) ) : 'No notes for this release.' ),
				);
			}

			return $result;
		}

		/**
		 * GitHub answers an asset request with a short-lived signed link, so the key
		 * is used to get the link and the file comes from the link without it.
		 *
		 * @return false|string|WP_Error False leaves other downloads to WordPress.
		 */
		public static function download( $reply, $package ) {
			if ( ! self::owns_package( $package ) ) {
				return $reply;
			}

			$response = wp_remote_get( $package, array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => self::headers( 'application/octet-stream' ),
			) );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$link = (string) wp_remote_retrieve_header( $response, 'location' );

			if ( '' === $link ) {
				return new WP_Error( 'ab_ghu_download', 'GitHub did not provide the update file (' . (int) wp_remote_retrieve_response_code( $response ) . ').' );
			}

			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			return download_url( $link, 300 );
		}

		private static function owns_package( $package ) {
			foreach ( self::$plugins as $plugin ) {
				if ( 0 === strpos( (string) $package, self::API . $plugin['repo'] . '/releases/assets/' ) ) {
					return true;
				}
			}

			return false;
		}

		/* ── Checking from outside WordPress ────────────────────── */

		public static function register_rest() {
			register_rest_route( 'ab-github-updater/v1', '/check', array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( self::CAPABILITY );
				},
				'callback'            => function () {
					return rest_ensure_response( array(
						'updater' => self::VERSION,
						'plugins' => self::check_now(),
					) );
				},
			) );

			register_rest_route( 'ab-github-updater/v1', '/install', array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( self::CAPABILITY );
				},
				'callback'            => function ( $request ) {
					return rest_ensure_response( self::install_latest( (string) $request['repo'] ) );
				},
			) );
		}

		/**
		 * Install the repo's latest release now. It can only install what is
		 * already published on GitHub, never code sent in this request.
		 *
		 * bulk_upgrade() is what WordPress's own update button uses. upgrade()
		 * would deactivate the plugin and expect a browser to turn it back on.
		 *
		 * @return array|WP_Error { installed: bool, from: string, to: string, message: string }
		 */
		public static function install_latest( $repo ) {
			$plugin_file = '';

			foreach ( self::$plugins as $file => $plugin ) {
				if ( $repo === $plugin['repo'] ) {
					$plugin_file = $file;
				}
			}

			if ( '' === $plugin_file ) {
				return new WP_Error( 'ab_ghu_repo', 'This site has no plugin updating from that repo.', array( 'status' => 404 ) );
			}

			if ( ! self::is_enabled() ) {
				return new WP_Error( 'ab_ghu_off', 'GitHub updates are turned off on this site.', array( 'status' => 409 ) );
			}

			$file = self::$plugins[ $plugin_file ]['file'];
			$from = self::installed_version( $file );

			self::check_now();

			$status = self::get_statuses()[ $repo ] ?? array();

			if ( empty( $status['ok'] ) ) {
				return new WP_Error( 'ab_ghu_check', (string) ( $status['message'] ?? 'GitHub could not be checked.' ), array( 'status' => 502 ) );
			}

			$updates = get_site_transient( 'update_plugins' );

			if ( empty( $updates->response[ $plugin_file ] ) ) {
				return array(
					'installed' => false,
					'from'      => $from,
					'to'        => $from,
					'message'   => 'Already on the latest release.',
				);
			}

			foreach ( array( 'file', 'plugin', 'misc', 'update', 'class-wp-upgrader' ) as $include ) {
				require_once ABSPATH . 'wp-admin/includes/' . $include . '.php';
			}

			$skin     = new WP_Ajax_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );
			$result   = $upgrader->bulk_upgrade( array( $plugin_file ) );

			if ( is_wp_error( $skin->result ) ) {
				return new WP_Error( 'ab_ghu_install', $skin->result->get_error_message(), array( 'status' => 500 ) );
			}

			if ( $skin->get_errors()->has_errors() ) {
				return new WP_Error( 'ab_ghu_install', $skin->get_error_messages(), array( 'status' => 500 ) );
			}

			if ( ! is_array( $result ) || empty( $result[ $plugin_file ] ) || true === $result[ $plugin_file ] ) {
				return new WP_Error( 'ab_ghu_install', 'WordPress could not write the update to the plugin folder.', array( 'status' => 500 ) );
			}

			$to = self::installed_version( $file );

			if ( $to !== $status['version'] ) {
				return new WP_Error( 'ab_ghu_install', 'The update ran, but the plugin reports version ' . $to . ' instead of ' . $status['version'] . '.', array( 'status' => 500 ) );
			}

			return array(
				'installed' => true,
				'from'      => $from,
				'to'        => $to,
				'message'   => 'Updated.',
			);
		}

		private static function installed_version( $file ) {
			clearstatcache( true, $file );

			return get_file_data( $file, array( 'Version' => 'Version' ) )['Version'];
		}

		/* ── The panel ──────────────────────────────────────────── */

		public static function register_screen() {
			foreach ( self::$plugins as $plugin ) {
				if ( $plugin['own_screen'] ) {
					add_options_page( 'GitHub Updates', 'GitHub Updates', self::CAPABILITY, self::SCREEN, array( __CLASS__, 'render_screen' ) );
					return;
				}
			}
		}

		public static function render_screen() {
			echo '<div class="wrap"><h1>GitHub Updates</h1>';
			self::render_panel();
			echo '</div>';
		}

		/**
		 * @return array[] { plugin, name, installed, latest, ok, message, checked, update_url }
		 */
		public static function get_rows() {
			$rows     = array();
			$statuses = self::get_statuses();

			foreach ( self::$plugins as $plugin_file => $plugin ) {
				$data   = get_file_data( $plugin['file'], array( 'Name' => 'Plugin Name', 'Version' => 'Version' ) );
				$status = $statuses[ $plugin['repo'] ] ?? array();
				$latest = (string) ( $status['version'] ?? '' );
				$newer  = ! empty( $status['ok'] ) && version_compare( $latest, $data['Version'], '>' );

				$rows[] = array(
					'plugin'     => $plugin_file,
					'name'       => $data['Name'],
					'installed'  => $data['Version'],
					'latest'     => $latest,
					'ok'         => ! empty( $status['ok'] ),
					'message'    => (string) ( $status['message'] ?? 'Not checked yet.' ),
					'checked'    => (int) ( $status['checked'] ?? 0 ),
					'update_url' => $newer ? wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $plugin_file ) ), 'upgrade-plugin_' . $plugin_file ) : '',
				);
			}

			return $rows;
		}

		public static function render_panel() {
			$settings = self::get_settings();
			$message  = isset( $_GET['ab_ghu_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['ab_ghu_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only
			$notices  = array(
				'saved'   => 'Saved.',
				'checked' => 'Checked GitHub just now.',
			);

			if ( isset( $notices[ $message ] ) ) {
				echo '<div class="notice notice-success inline"><p>' . esc_html( $notices[ $message ] ) . '</p></div>';
			}
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ab_ghu_save' ); ?>
				<input type="hidden" name="action" value="ab_ghu_save" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Updates from GitHub</th>
						<td><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> /> Offer new releases on this site's Plugins screen</label></td>
					</tr>
					<tr>
						<th scope="row"><label for="ab-ghu-token">GitHub access key</label></th>
						<td>
							<input type="password" class="regular-text" id="ab-ghu-token" name="token" value="" autocomplete="off" placeholder="<?php echo self::has_token() ? esc_attr( 'A key is saved' ) : ''; ?>" />
							<p class="description">A read-only key for the plugin repos. Saved once per site and never shown again. Leave blank to keep the saved key.</p>
							<?php if ( self::has_token() ) : ?>
								<p><label><input type="checkbox" name="remove_token" value="1" /> Remove the saved key</label></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save', 'primary', 'submit', true ); ?>
			</form>

			<table class="widefat striped">
				<thead>
					<tr><th>Plugin</th><th>Installed</th><th>On GitHub</th><th>State</th><th></th></tr>
				</thead>
				<tbody>
					<?php foreach ( self::get_rows() as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['name'] ); ?></td>
							<td><?php echo esc_html( $row['installed'] ); ?></td>
							<td><?php echo esc_html( '' !== $row['latest'] ? $row['latest'] : '-' ); ?></td>
							<td>
								<?php echo esc_html( $row['message'] ); ?>
								<?php if ( $row['checked'] ) : ?>
									<br /><small>Checked <?php echo esc_html( human_time_diff( $row['checked'] ) ); ?> ago</small>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( '' !== $row['update_url'] ) : ?>
									<a class="button button-primary" href="<?php echo esc_url( $row['update_url'] ); ?>">Update now</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
				<?php wp_nonce_field( 'ab_ghu_check' ); ?>
				<input type="hidden" name="action" value="ab_ghu_check" />
				<button type="submit" class="button">Check GitHub now</button>
			</form>
			<?php
		}

		private static function require_access( $nonce ) {
			if ( ! current_user_can( self::CAPABILITY ) ) {
				wp_die( 'Permission denied.' );
			}

			check_admin_referer( $nonce );
		}

		private static function back( $message ) {
			$from = wp_get_referer();

			wp_safe_redirect( add_query_arg( 'ab_ghu_msg', $message, remove_query_arg( 'ab_ghu_msg', $from ? $from : admin_url( 'options-general.php?page=' . self::SCREEN ) ) ) );
			exit;
		}

		public static function handle_save() {
			self::require_access( 'ab_ghu_save' );

			$settings = array( 'enabled' => ! empty( $_POST['enabled'] ) );
			$token    = trim( sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized here

			if ( ! empty( $_POST['remove_token'] ) ) {
				$settings['token'] = '';
			} elseif ( '' !== $token ) {
				$settings['token'] = $token;
			}

			self::save_settings( $settings );
			self::check_now();
			self::back( 'saved' );
		}

		public static function handle_check() {
			self::require_access( 'ab_ghu_check' );

			self::check_now();
			self::back( 'checked' );
		}
	}
}
