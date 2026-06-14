<?php
/**
 * Plugin Name: Temporary Site Connector
 * Description: Temporary admin-only WordPress diagnostics connector for remote debugging.
 * Version: 1.4.2
 * Author: Codex
 * License: GPL-2.0-or-later
 * Requires PHP: 7.2
 *
 * @package WpCliSetupHelper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_CLI_Setup_Helper {
	private const PAGE_SLUG    = 'wp-cli-setup-helper';
	private const NONCE_ACTION = 'wp_cli_setup_helper_refresh';
	private const NONCE_NAME   = 'wp_cli_setup_helper_nonce';
	private const REST_NAMESPACE = 'wp-cli-helper/v1';
	private const CONNECTOR_PASSWORD_ACTION = 'wp_cli_setup_helper_generate_password';
	private const DEBUG_ENABLE_ACTION = 'wp_cli_setup_helper_enable_debug';
	private const DEBUG_DISABLE_ACTION = 'wp_cli_setup_helper_disable_debug';
	private const REVOKE_PASSWORD_ACTION = 'wp_cli_setup_helper_revoke_password';
	private const OPTION_PASSWORDS = 'wp_cli_setup_helper_passwords';
	private const OPTION_DEBUG_STATE = 'wp_cli_setup_helper_debug_state';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_activation_redirect' ) );
		add_action( 'admin_post_wp_cli_setup_helper_refresh', array( __CLASS__, 'handle_refresh' ) );
		add_action( 'wp_ajax_wp_cli_setup_helper_generate_connector', array( __CLASS__, 'ajax_generate_connector' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	public static function activate(): void {
		if ( is_user_logged_in() ) {
			set_transient( 'wp_cli_setup_helper_activation_redirect_' . get_current_user_id(), '1', 60 );
		}
	}

	public static function uninstall(): void {
		self::delete_tracked_application_passwords();
		self::restore_debug_logging();

		delete_option( self::OPTION_PASSWORDS );
		delete_option( self::OPTION_DEBUG_STATE );
	}

	public static function maybe_activation_redirect(): void {
		if ( ! current_user_can( 'manage_options' ) || wp_doing_ajax() ) {
			return;
		}

		$key = 'wp_cli_setup_helper_activation_redirect_' . get_current_user_id();

		if ( '1' !== get_transient( $key ) ) {
			return;
		}

		delete_transient( $key );

		if ( isset( $_GET['activate-multi'] ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	public static function register_admin_page(): void {
		add_management_page(
			'Temporary Site Connector',
			'Temporary Site Connector',
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_admin_page' )
		);
	}

	public static function handle_refresh(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-cli-setup-helper' ) );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                   => self::PAGE_SLUG,
					'wp-cli-helper-refreshed' => '1',
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	public static function ajax_generate_connector(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to generate connector credentials.', 'wp-cli-setup-helper' ),
				),
				403
			);
		}

		check_ajax_referer( 'wp_cli_setup_helper_ajax', 'nonce' );

		$result = self::generate_connector_application_password();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				),
				400
			);
		}

		wp_send_json_success( $result );
	}

	public static function register_rest_routes(): void {
		$routes = array(
			'/health'      => array( __CLASS__, 'rest_health' ),
			'/diagnostics' => array( __CLASS__, 'rest_diagnostics' ),
			'/plugins'     => array( __CLASS__, 'rest_plugins' ),
			'/theme'       => array( __CLASS__, 'rest_theme' ),
			'/options'     => array( __CLASS__, 'rest_options' ),
			'/cron'        => array( __CLASS__, 'rest_cron' ),
			'/transients'  => array( __CLASS__, 'rest_transients' ),
			'/debug-log'   => array( __CLASS__, 'rest_debug_log' ),
			'/commands'    => array( __CLASS__, 'rest_commands' ),
		);

		foreach ( $routes as $route => $callback ) {
			register_rest_route(
				self::REST_NAMESPACE,
				$route,
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => $callback,
					'permission_callback' => array( __CLASS__, 'rest_permission_check' ),
				)
			);
		}

		register_rest_route(
			self::REST_NAMESPACE,
			'/context',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_context' ),
				'permission_callback' => array( __CLASS__, 'rest_permission_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/ask',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_ask' ),
				'permission_callback' => array( __CLASS__, 'rest_permission_check' ),
				'args'                => array(
					'question' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'url'      => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'esc_url_raw',
					),
					'error'    => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'include'  => array(
						'type'     => 'array',
						'required' => false,
						'items'    => array(
							'type' => 'string',
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_search' ),
				'permission_callback' => array( __CLASS__, 'rest_permission_check' ),
				'args'                => array(
					'q'        => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'postType' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
					'limit'    => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	public static function rest_permission_check(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function rest_health(): WP_REST_Response {
		return rest_ensure_response(
			array(
				'ok'          => true,
				'plugin'      => 'codex-site-connector',
				'version'     => self::plugin_version(),
				'user_id'     => get_current_user_id(),
				'permissions' => array(
					'manage_options' => current_user_can( 'manage_options' ),
				),
				'timestamp'   => current_time( 'mysql' ),
			)
		);
	}

	public static function rest_diagnostics(): WP_REST_Response {
		return rest_ensure_response( self::get_diagnostics() );
	}

	public static function rest_plugins(): WP_REST_Response {
		return rest_ensure_response( self::get_plugins_report() );
	}

	public static function rest_theme(): WP_REST_Response {
		return rest_ensure_response( self::get_theme_report() );
	}

	public static function rest_options(): WP_REST_Response {
		return rest_ensure_response( self::get_options_report() );
	}

	public static function rest_cron(): WP_REST_Response {
		return rest_ensure_response( self::get_cron_report() );
	}

	public static function rest_transients(): WP_REST_Response {
		return rest_ensure_response( self::get_transients_report() );
	}

	public static function rest_debug_log( WP_REST_Request $request ): WP_REST_Response {
		$lines = absint( $request->get_param( 'lines' ) );

		if ( $lines < 1 ) {
			$lines = 200;
		}

		if ( $lines > 1000 ) {
			$lines = 1000;
		}

		return rest_ensure_response( self::get_debug_log_report( $lines ) );
	}

	public static function rest_commands(): WP_REST_Response {
		$diagnostics = self::get_diagnostics();

		return rest_ensure_response(
			array(
				'debug_commands'   => self::get_debug_commands( $diagnostics['wp_root'] ),
				'alias_template'   => self::get_alias_template( $diagnostics['wp_root'] ),
				'install_commands' => self::get_install_commands(),
				'rest_examples'    => self::get_rest_examples(),
			)
		);
	}

	public static function rest_context(): WP_REST_Response {
		return rest_ensure_response( self::get_context_report() );
	}

	public static function rest_ask( WP_REST_Request $request ): WP_REST_Response {
		$include = $request->get_param( 'include' );

		if ( ! is_array( $include ) || empty( $include ) ) {
			$include = array( 'diagnostics', 'plugins', 'theme', 'options', 'cron', 'debug-log' );
		}

		return rest_ensure_response(
			self::get_conversation_report(
				array(
					'question' => (string) $request->get_param( 'question' ),
					'url'      => (string) $request->get_param( 'url' ),
					'error'    => (string) $request->get_param( 'error' ),
					'include'  => array_map( 'sanitize_key', $include ),
				)
			)
		);
	}

	public static function rest_search( WP_REST_Request $request ): WP_REST_Response {
		$limit = absint( $request->get_param( 'limit' ) );

		if ( $limit < 1 ) {
			$limit = 10;
		}

		if ( $limit > 50 ) {
			$limit = 50;
		}

		return rest_ensure_response(
			self::get_search_report(
				(string) $request->get_param( 'q' ),
				(string) $request->get_param( 'postType' ),
				$limit
			)
		);
	}

	public static function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-cli-setup-helper' ) );
		}

		$post_result = self::handle_admin_page_post();
		$diagnostics      = self::get_diagnostics();
		$refreshed        = isset( $_GET['wp-cli-helper-refreshed'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['wp-cli-helper-refreshed'] ) );

		?>
		<div class="wrap wp-cli-setup-helper">
			<h1><?php echo esc_html__( 'Temporary Site Connector', 'wp-cli-setup-helper' ); ?></h1>

			<?php if ( $refreshed ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'Environment diagnostics refreshed.', 'wp-cli-setup-helper' ); ?></p>
				</div>
			<?php endif; ?>

			<?php self::render_admin_notice( $post_result ); ?>

			<div class="notice notice-warning">
				<p>
					<strong><?php echo esc_html__( 'Live-site safety:', 'wp-cli-setup-helper' ); ?></strong>
					<?php echo esc_html__( 'This temporary connector lets Codex inspect WordPress context through authenticated REST endpoints. It does not run shell commands, install binaries, expose a terminal, or store SSH/database secrets.', 'wp-cli-setup-helper' ); ?>
				</p>
				<p><?php echo esc_html__( 'After debugging, delete the Application Password first, then deactivate and remove this plugin from the client site.', 'wp-cli-setup-helper' ); ?></p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wp-cli-helper-actions">
				<input type="hidden" name="action" value="wp_cli_setup_helper_refresh" />
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<?php submit_button( __( 'Re-check environment', 'wp-cli-setup-helper' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php self::render_connector_tools( isset( $post_result['connector'] ) ? $post_result['connector'] : array() ); ?>

			<?php self::render_debug_tools(); ?>

			<?php self::render_cleanup_status(); ?>

			<?php self::render_info_table( $diagnostics ); ?>
		</div>

		<style>
			.wp-cli-setup-helper {
				max-width: 1180px;
			}

			.wp-cli-helper-actions {
				margin: 16px 0;
			}

			.wp-cli-helper-card {
				background: #fff;
				border: 1px solid #c3c4c7;
				box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
				margin: 18px 0;
				padding: 18px;
			}

			.wp-cli-helper-card h2 {
				margin-top: 0;
			}

			.wp-cli-helper-table th {
				width: 260px;
			}

			.wp-cli-helper-status {
				border-radius: 999px;
				display: inline-block;
				font-size: 12px;
				font-weight: 600;
				line-height: 1;
				padding: 5px 9px;
			}

			.wp-cli-helper-status.is-ok {
				background: #edfaef;
				color: #0a6f20;
			}

			.wp-cli-helper-status.is-warn {
				background: #fcf0f1;
				color: #8a2424;
			}

			.wp-cli-helper-code {
				display: block;
				font-family: Consolas, Monaco, monospace;
				min-height: 190px;
				width: 100%;
			}

			.wp-cli-helper-code-header {
				align-items: center;
				display: flex;
				justify-content: space-between;
				gap: 12px;
				margin-bottom: 10px;
			}

			.wp-cli-helper-card code {
				display: inline-block;
				margin: 4px 8px 4px 0;
			}

			.wp-cli-helper-inline-actions {
				align-items: center;
				display: flex;
				flex-wrap: wrap;
				gap: 8px;
				margin: 14px 0;
			}

			.wp-cli-helper-secret-warning {
				color: #8a2424;
				font-weight: 600;
			}
		</style>

		<script>
			(function () {
				var storageKey = 'wpCliSetupHelperGeneratedCommands';

				function copyText(text, fallbackTarget) {
					if (navigator.clipboard && navigator.clipboard.writeText) {
						return navigator.clipboard.writeText(text);
					}

					if (fallbackTarget) {
						fallbackTarget.select();
						fallbackTarget.setSelectionRange(0, fallbackTarget.value.length);
						document.execCommand('copy');
					}

					return Promise.resolve();
				}

				document.querySelectorAll('[data-wp-cli-helper-copy]').forEach(function (button) {
					button.addEventListener('click', function () {
						var target = document.getElementById(button.getAttribute('data-wp-cli-helper-copy'));

						if (!target) {
							return;
						}

						target.select();
						target.setSelectionRange(0, target.value.length);

						copyText(target.value, target);
					});
				});

				var generator = document.getElementById('wp-cli-helper-generate-copy');
				var generatorStatus = document.getElementById('wp-cli-helper-generate-copy-status');
				var generatedOutput = document.getElementById('wp-cli-helper-live-generated-connector');
				var copyAgain = document.getElementById('wp-cli-helper-copy-again');

				if (generatedOutput) {
					var storedCommands = window.sessionStorage ? window.sessionStorage.getItem(storageKey) : '';

					if (storedCommands) {
						generatedOutput.value = storedCommands;
						if (generatorStatus) {
							generatorStatus.textContent = ' Restored from this browser session.';
						}
					}
				}

				if (generator && generatedOutput) {
					generator.addEventListener('click', function () {
						var formData = new FormData();
						formData.append('action', 'wp_cli_setup_helper_generate_connector');
						formData.append('nonce', generator.getAttribute('data-nonce'));

						generator.disabled = true;
						generatorStatus.textContent = ' Generating...';

						fetch(window.ajaxurl, {
							method: 'POST',
							credentials: 'same-origin',
							body: formData
						})
							.then(function (response) {
								return response.json();
							})
							.then(function (response) {
								if (!response || !response.success || !response.data || !response.data.commands) {
									throw new Error(response && response.data && response.data.message ? response.data.message : 'Could not generate connector commands.');
								}

								generatedOutput.value = response.data.commands;

								if (window.sessionStorage) {
									window.sessionStorage.setItem(storageKey, response.data.commands);
								}

								return copyText(response.data.commands, generatedOutput);
							})
							.then(function () {
								generatorStatus.textContent = ' Generated and copied. Revoke this Application Password after debugging.';
							})
							.catch(function (error) {
								generatorStatus.textContent = ' ' + error.message;
							})
							.finally(function () {
								generator.disabled = false;
							});
					});
				}

				if (copyAgain && generatedOutput) {
					copyAgain.addEventListener('click', function () {
						if (!generatedOutput.value) {
							generatorStatus.textContent = ' Generate commands first.';
							return;
						}

						copyText(generatedOutput.value, generatedOutput).then(function () {
							generatorStatus.textContent = ' Copied again.';
						});
					});
				}
			})();
		</script>
		<?php
	}

	private static function render_info_table( array $diagnostics ): void {
		?>
		<div class="wp-cli-helper-card">
			<h2><?php echo esc_html__( 'Detected Site Info', 'wp-cli-setup-helper' ); ?></h2>
			<table class="widefat striped wp-cli-helper-table">
				<tbody>
					<?php foreach ( $diagnostics['site_info'] as $label => $value ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $label ); ?></th>
							<td><?php echo esc_html( $value ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php
	}

	private static function render_code_block( string $title, string $content, string $id ): void {
		$id = sanitize_key( $id );
		?>
		<div class="wp-cli-helper-card">
			<div class="wp-cli-helper-code-header">
				<h2><?php echo esc_html( $title ); ?></h2>
				<button type="button" class="button button-secondary" data-wp-cli-helper-copy="<?php echo esc_attr( $id ); ?>">
					<?php echo esc_html__( 'Copy', 'wp-cli-setup-helper' ); ?>
				</button>
			</div>
			<textarea id="<?php echo esc_attr( $id ); ?>" class="wp-cli-helper-code" rows="10" readonly><?php echo esc_textarea( trim( $content ) ); ?></textarea>
		</div>
		<?php
	}

	private static function render_status_badge( bool $is_ok, string $label ): void {
		printf(
			'<span class="wp-cli-helper-status %1$s">%2$s</span> ',
			$is_ok ? 'is-ok' : 'is-warn',
			esc_html( $label )
		);
	}

	private static function handle_admin_page_post(): array {
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET' ) ) {
			return array();
		}

		$action = isset( $_POST['wp_cli_helper_action'] ) ? sanitize_key( wp_unslash( $_POST['wp_cli_helper_action'] ) ) : '';

		if ( self::CONNECTOR_PASSWORD_ACTION === $action ) {
			check_admin_referer( self::CONNECTOR_PASSWORD_ACTION );

			$result = self::generate_connector_application_password();

			if ( is_wp_error( $result ) ) {
				return array(
					'type'    => 'error',
					'message' => $result->get_error_message(),
				);
			}

			return array(
				'type'      => 'success',
				'message'   => __( 'Temporary Application Password generated. Copy it now; WordPress will not show it again.', 'wp-cli-setup-helper' ),
				'connector' => $result,
			);
		}

		if ( self::DEBUG_ENABLE_ACTION === $action ) {
			check_admin_referer( self::DEBUG_ENABLE_ACTION );

			$result = self::enable_debug_logging();

			return array(
				'type'    => ! empty( $result['ok'] ) ? 'success' : 'error',
				'message' => isset( $result['message'] ) ? $result['message'] : __( 'Debug logging action finished.', 'wp-cli-setup-helper' ),
				'details' => $result,
			);
		}

		if ( self::DEBUG_DISABLE_ACTION === $action ) {
			check_admin_referer( self::DEBUG_DISABLE_ACTION );

			$result = self::disable_debug_logging();

			return array(
				'type'    => ! empty( $result['ok'] ) ? 'success' : 'error',
				'message' => isset( $result['message'] ) ? $result['message'] : __( 'Debug logging restore action finished.', 'wp-cli-setup-helper' ),
				'details' => $result,
			);
		}

		if ( self::REVOKE_PASSWORD_ACTION === $action ) {
			check_admin_referer( self::REVOKE_PASSWORD_ACTION );

			$uuid = isset( $_POST['application_password_uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['application_password_uuid'] ) ) : '';
			$result = self::revoke_tracked_application_password( $uuid );

			return array(
				'type'    => ! empty( $result['ok'] ) ? 'success' : 'error',
				'message' => isset( $result['message'] ) ? $result['message'] : __( 'Application Password revoke action finished.', 'wp-cli-setup-helper' ),
				'details' => $result,
			);
		}

		return array();
	}

	private static function render_admin_notice( array $result ): void {
		if ( empty( $result['message'] ) ) {
			return;
		}

		$type = isset( $result['type'] ) && 'error' === $result['type'] ? 'error' : 'success';
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
			<p><?php echo esc_html( $result['message'] ); ?></p>
			<?php if ( ! empty( $result['details']['backup'] ) ) : ?>
				<p><?php echo esc_html( sprintf( __( 'Backup created: %s', 'wp-cli-setup-helper' ), $result['details']['backup'] ) ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_connector_tools( array $connector ): void {
		$user     = wp_get_current_user();
		$username = $user && $user->exists() ? $user->user_login : '';
		$base     = untrailingslashit( rest_url( self::REST_NAMESPACE ) );
		?>
		<div class="wp-cli-helper-card">
			<h2><?php echo esc_html__( 'Codex Connector Access', 'wp-cli-setup-helper' ); ?></h2>
			<p><?php echo esc_html__( 'Generate a temporary Application Password for the current admin user, then copy the generated commands into Codex or your terminal.', 'wp-cli-setup-helper' ); ?></p>
			<table class="widefat striped wp-cli-helper-table">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Current username', 'wp-cli-setup-helper' ); ?></th>
						<td><code><?php echo esc_html( $username ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'REST base', 'wp-cli-setup-helper' ); ?></th>
						<td><code><?php echo esc_html( $base ); ?></code></td>
					</tr>
				</tbody>
			</table>
			<div class="wp-cli-helper-inline-actions">
				<button type="button" class="button button-primary" id="wp-cli-helper-generate-copy" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_cli_setup_helper_ajax' ) ); ?>">
					<?php echo esc_html__( 'Generate and copy debug commands', 'wp-cli-setup-helper' ); ?>
				</button>
				<button type="button" class="button button-secondary" id="wp-cli-helper-copy-again">
					<?php echo esc_html__( 'Copy again', 'wp-cli-setup-helper' ); ?>
				</button>
				<span id="wp-cli-helper-generate-copy-status" aria-live="polite"></span>
			</div>
			<textarea id="wp-cli-helper-live-generated-connector" class="wp-cli-helper-code" rows="10" readonly placeholder="<?php echo esc_attr__( 'Generated debug commands will appear here and be copied to your clipboard.', 'wp-cli-setup-helper' ); ?>"></textarea>
			<p class="wp-cli-helper-secret-warning"><?php echo esc_html__( 'Generated commands include a secret Application Password. Do not paste them into public chats or issue trackers.', 'wp-cli-setup-helper' ); ?></p>
			<p><strong><?php echo esc_html__( 'Cleanup:', 'wp-cli-setup-helper' ); ?></strong> <?php echo esc_html__( 'Delete the Application Password and remove this plugin when debugging is complete.', 'wp-cli-setup-helper' ); ?></p>
		</div>
		<?php
	}

	private static function render_debug_tools(): void {
		$status = self::get_debug_config_status();
		$button_disabled = ! $status['writable'] || ( $status['active'] && ! $status['owned'] );
		$action = $status['owned'] ? self::DEBUG_DISABLE_ACTION : self::DEBUG_ENABLE_ACTION;
		$nonce_action = $status['owned'] ? self::DEBUG_DISABLE_ACTION : self::DEBUG_ENABLE_ACTION;
		$button_label = $status['owned'] ? __( 'Disable debug logging', 'wp-cli-setup-helper' ) : __( 'Enable debug logging', 'wp-cli-setup-helper' );
		$status_message = '';

		if ( $status['owned'] ) {
			$status_message = __( 'Debug logging was enabled by this plugin. You can disable it now, or it will be restored on uninstall.', 'wp-cli-setup-helper' );
		} elseif ( $status['active'] ) {
			$status_message = __( 'Debug logging is already enabled outside this plugin. This connector will not disable it.', 'wp-cli-setup-helper' );
		}
		?>
		<div class="wp-cli-helper-card">
			<h2><?php echo esc_html__( 'Debug Logging', 'wp-cli-setup-helper' ); ?></h2>
			<p><?php echo esc_html__( 'Enable temporary debug logging for this session. If this plugin changes wp-config.php, it creates a backup and restores only its own changes on uninstall.', 'wp-cli-setup-helper' ); ?></p>
			<?php if ( $status_message ) : ?>
				<p><strong><?php echo esc_html( $status_message ); ?></strong></p>
			<?php endif; ?>
			<table class="widefat striped wp-cli-helper-table">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'wp-config.php', 'wp-cli-setup-helper' ); ?></th>
						<td><?php echo esc_html( $status['path'] ? $status['path'] : __( 'Not found', 'wp-cli-setup-helper' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Writable', 'wp-cli-setup-helper' ); ?></th>
						<td><?php self::render_status_badge( (bool) $status['writable'], $status['writable'] ? 'Writable' : 'Not writable' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Current loaded constants', 'wp-cli-setup-helper' ); ?></th>
						<td>
							<code>WP_DEBUG=<?php echo esc_html( ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 'true' : 'false' ); ?></code>
							<code>WP_DEBUG_LOG=<?php echo esc_html( ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ? 'true' : 'false' ); ?></code>
							<code>WP_DEBUG_DISPLAY=<?php echo esc_html( ( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ) ? 'true' : 'false' ); ?></code>
						</td>
					</tr>
				</tbody>
			</table>
			<form method="post" action="" class="wp-cli-helper-actions">
				<input type="hidden" name="wp_cli_helper_action" value="<?php echo esc_attr( $action ); ?>" />
				<?php wp_nonce_field( $nonce_action ); ?>
				<?php submit_button( $status['active'] && ! $status['owned'] ? __( 'Debug already enabled', 'wp-cli-setup-helper' ) : $button_label, $status['owned'] ? 'delete' : 'secondary', 'submit', false, $button_disabled ? array( 'disabled' => 'disabled' ) : array() ); ?>
			</form>
		</div>
		<?php
	}

	private static function render_cleanup_status(): void {
		$passwords   = get_option( self::OPTION_PASSWORDS, array() );
		$debug_state = get_option( self::OPTION_DEBUG_STATE, array() );
		$count       = is_array( $passwords ) ? count( $passwords ) : 0;
		$debug_owned = is_array( $debug_state ) && ! empty( $debug_state['owned'] );
		?>
		<div class="wp-cli-helper-card">
			<h2><?php echo esc_html__( 'Cleanup Status', 'wp-cli-setup-helper' ); ?></h2>
			<table class="widefat striped wp-cli-helper-table">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Tracked connector passwords', 'wp-cli-setup-helper' ); ?></th>
						<td><?php echo esc_html( (string) $count ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Debug changes owned by plugin', 'wp-cli-setup-helper' ); ?></th>
						<td><?php self::render_status_badge( $debug_owned, $debug_owned ? 'Yes' : 'No' ); ?></td>
					</tr>
				</tbody>
			</table>
			<?php if ( is_array( $passwords ) && ! empty( $passwords ) ) : ?>
				<h3><?php echo esc_html__( 'Plugin-created Application Passwords', 'wp-cli-setup-helper' ); ?></h3>
				<table class="widefat striped wp-cli-helper-table">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Name', 'wp-cli-setup-helper' ); ?></th>
							<th><?php echo esc_html__( 'User', 'wp-cli-setup-helper' ); ?></th>
							<th><?php echo esc_html__( 'Created', 'wp-cli-setup-helper' ); ?></th>
							<th><?php echo esc_html__( 'UUID', 'wp-cli-setup-helper' ); ?></th>
							<th><?php echo esc_html__( 'Action', 'wp-cli-setup-helper' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $passwords as $record ) : ?>
							<?php
							if ( ! is_array( $record ) || empty( $record['uuid'] ) ) {
								continue;
							}

							$user = ! empty( $record['user_id'] ) ? get_userdata( absint( $record['user_id'] ) ) : false;
							$uuid = (string) $record['uuid'];
							?>
							<tr>
								<td><?php echo esc_html( isset( $record['name'] ) ? (string) $record['name'] : __( 'Connector password', 'wp-cli-setup-helper' ) ); ?></td>
								<td><?php echo esc_html( $user ? $user->user_login . ' (#' . $user->ID . ')' : '#' . absint( isset( $record['user_id'] ) ? $record['user_id'] : 0 ) ); ?></td>
								<td><?php echo esc_html( isset( $record['created_at'] ) ? (string) $record['created_at'] : '' ); ?></td>
								<td><code><?php echo esc_html( substr( $uuid, 0, 8 ) . '...' . substr( $uuid, -8 ) ); ?></code></td>
								<td>
									<form method="post" action="">
										<input type="hidden" name="wp_cli_helper_action" value="<?php echo esc_attr( self::REVOKE_PASSWORD_ACTION ); ?>" />
										<input type="hidden" name="application_password_uuid" value="<?php echo esc_attr( $uuid ); ?>" />
										<?php wp_nonce_field( self::REVOKE_PASSWORD_ACTION ); ?>
										<?php submit_button( __( 'Revoke', 'wp-cli-setup-helper' ), 'delete small', 'submit', false ); ?>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function generate_connector_application_password() {
		if ( ! function_exists( 'wp_is_application_passwords_available' ) || ! wp_is_application_passwords_available() ) {
			return new WP_Error(
				'application_passwords_unavailable',
				__( 'Application Passwords are not available on this site.', 'wp-cli-setup-helper' )
			);
		}

		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-application-passwords.php';
		}

		$user = wp_get_current_user();

		if ( ! $user || ! $user->exists() ) {
			return new WP_Error(
				'no_current_user',
				__( 'Could not resolve the current user.', 'wp-cli-setup-helper' )
			);
		}

		if ( function_exists( 'wp_is_application_passwords_available_for_user' ) && ! wp_is_application_passwords_available_for_user( $user ) ) {
			return new WP_Error(
				'application_passwords_unavailable_for_user',
				__( 'Application Passwords are not available for this user.', 'wp-cli-setup-helper' )
			);
		}

		$name   = 'Codex Debug Connector ' . current_time( 'mysql' );
		$result = WP_Application_Passwords::create_new_application_password(
			$user->ID,
			array(
				'name' => $name,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$password = isset( $result[0] ) ? (string) $result[0] : '';
		$item     = isset( $result[1] ) && is_array( $result[1] ) ? $result[1] : array();
		$uuid     = isset( $item['uuid'] ) ? (string) $item['uuid'] : '';

		if ( '' === $password ) {
			return new WP_Error(
				'application_password_generation_failed',
				__( 'WordPress did not return a generated Application Password.', 'wp-cli-setup-helper' )
			);
		}

		if ( '' !== $uuid ) {
			self::track_connector_password( $user->ID, $uuid, $name );
		}

		return array(
			'username'     => $user->user_login,
			'name'         => $name,
			'generated_at' => current_time( 'mysql' ),
			'commands'     => self::get_connector_commands( $user->user_login, $password ),
		);
	}

	private static function track_connector_password( int $user_id, string $uuid, string $name ): void {
		$records = get_option( self::OPTION_PASSWORDS, array() );

		if ( ! is_array( $records ) ) {
			$records = array();
		}

		$records[ $uuid ] = array(
			'user_id'    => $user_id,
			'uuid'       => $uuid,
			'name'       => $name,
			'created_at' => current_time( 'mysql' ),
		);

		update_option( self::OPTION_PASSWORDS, $records, false );
	}

	private static function delete_tracked_application_passwords(): void {
		$records = get_option( self::OPTION_PASSWORDS, array() );

		if ( ! is_array( $records ) || empty( $records ) ) {
			return;
		}

		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-application-passwords.php';
		}

		if ( ! method_exists( 'WP_Application_Passwords', 'delete_application_password' ) ) {
			return;
		}

		foreach ( $records as $record ) {
			if ( ! is_array( $record ) || empty( $record['user_id'] ) || empty( $record['uuid'] ) ) {
				continue;
			}

			WP_Application_Passwords::delete_application_password( absint( $record['user_id'] ), (string) $record['uuid'] );
		}
	}

	private static function revoke_tracked_application_password( string $uuid ): array {
		if ( '' === $uuid ) {
			return array(
				'ok'      => false,
				'message' => __( 'Missing Application Password UUID.', 'wp-cli-setup-helper' ),
			);
		}

		$records = get_option( self::OPTION_PASSWORDS, array() );

		if ( ! is_array( $records ) || empty( $records[ $uuid ] ) || ! is_array( $records[ $uuid ] ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'This Application Password is not tracked by this plugin.', 'wp-cli-setup-helper' ),
			);
		}

		$record = $records[ $uuid ];

		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-application-passwords.php';
		}

		if ( method_exists( 'WP_Application_Passwords', 'delete_application_password' ) && ! empty( $record['user_id'] ) ) {
			WP_Application_Passwords::delete_application_password( absint( $record['user_id'] ), $uuid );
		}

		unset( $records[ $uuid ] );
		update_option( self::OPTION_PASSWORDS, $records, false );

		return array(
			'ok'      => true,
			'message' => __( 'Application Password revoked and removed from connector tracking.', 'wp-cli-setup-helper' ),
		);
	}

	private static function get_connector_commands( string $username, string $password ): string {
		$base = untrailingslashit( rest_url( self::REST_NAMESPACE ) );
		$auth = $username . ':' . $password;
		$ask_payload = wp_json_encode(
			array(
				'question' => 'Describe the issue here.',
				'url'      => home_url( '/' ),
				'error'    => 'Paste browser console, Network error, or PHP error here.',
				'include'  => array( 'diagnostics', 'plugins', 'theme', 'options', 'cron', 'debug-log' ),
			),
			JSON_PRETTY_PRINT
		);

		return implode(
			"\n",
			array(
				'# Generated temporary connector credentials. Revoke this Application Password after debugging.',
				self::get_debug_status_comment(),
				'USERNAME=' . self::shell_quote( $username ),
				'APPLICATION_PASSWORD=' . self::shell_quote( $password ),
				'',
				'# Quick auth check.',
				'curl -u ' . self::shell_quote( $auth ) . ' ' . self::shell_quote( $base . '/health' ),
				'',
				'# Pull site context.',
				'curl -u ' . self::shell_quote( $auth ) . ' ' . self::shell_quote( $base . '/context' ),
				'curl -u ' . self::shell_quote( $auth ) . ' ' . self::shell_quote( $base . '/diagnostics' ),
				'curl -u ' . self::shell_quote( $auth ) . ' ' . self::shell_quote( $base . '/plugins' ),
				'curl -u ' . self::shell_quote( $auth ) . ' ' . self::shell_quote( $base . '/debug-log?lines=300' ),
				'',
				'# Ask/debug bundle.',
				'curl -u ' . self::shell_quote( $auth ) . ' -H "Content-Type: application/json" -X POST ' . self::shell_quote( $base . '/ask' ) . ' -d ' . self::shell_quote( $ask_payload ),
				'',
				'# Search content.',
				'curl -u ' . self::shell_quote( $auth ) . ' ' . self::shell_quote( $base . '/search?q=pricing&limit=10' ),
			)
		);
	}

	private static function get_debug_status_comment(): string {
		$debug = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 'true' : 'false';
		$log = ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ? 'true' : 'false';
		$display = ( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ) ? 'true' : 'false';

		return '# Current debug status: WP_DEBUG=' . $debug . ', WP_DEBUG_LOG=' . $log . ', WP_DEBUG_DISPLAY=' . $display;
	}

	private static function get_debug_config_status(): array {
		$path = self::find_wp_config_path();
		$owned = get_option( self::OPTION_DEBUG_STATE, array() );
		$constants_loaded = array(
			'WP_DEBUG'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'WP_DEBUG_LOG'     => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
			'WP_DEBUG_DISPLAY' => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
		);

		return array(
			'path'     => $path ? self::normalize_path( $path ) : '',
			'writable' => $path && file_exists( $path ) && is_readable( $path ) && is_writable( $path ),
			'active'   => ! empty( $constants_loaded['WP_DEBUG'] ) && ! empty( $constants_loaded['WP_DEBUG_LOG'] ) && empty( $constants_loaded['WP_DEBUG_DISPLAY'] ),
			'owned'    => is_array( $owned ) && ! empty( $owned['owned'] ),
		);
	}

	private static function enable_debug_logging(): array {
		$current_state = get_option( self::OPTION_DEBUG_STATE, array() );

		if ( is_array( $current_state ) && ! empty( $current_state['owned'] ) ) {
			return array(
				'ok'      => true,
				'message' => __( 'Debug logging is already enabled by this plugin.', 'wp-cli-setup-helper' ),
			);
		}

		$path = self::find_wp_config_path();

		if ( ! $path ) {
			return array(
				'ok'      => false,
				'message' => __( 'Could not find wp-config.php.', 'wp-cli-setup-helper' ),
			);
		}

		if ( ! is_readable( $path ) || ! is_writable( $path ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'wp-config.php is not readable and writable by WordPress.', 'wp-cli-setup-helper' ),
				'path'    => self::normalize_path( $path ),
			);
		}

		$contents = file_get_contents( $path );

		if ( false === $contents ) {
			return array(
				'ok'      => false,
				'message' => __( 'Could not read wp-config.php.', 'wp-cli-setup-helper' ),
				'path'    => self::normalize_path( $path ),
			);
		}

		$updated = $contents;
		$originals = array();
		$targets   = array(
			'WP_DEBUG'         => 'true',
			'WP_DEBUG_LOG'     => 'true',
			'WP_DEBUG_DISPLAY' => 'false',
		);

		foreach ( $targets as $constant => $target_value ) {
			$state = self::get_wp_config_constant_state( $contents, $constant );

			if ( 'WP_DEBUG' === $constant && ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
				continue;
			}

			if ( self::constant_raw_matches( $state, $target_value ) ) {
				continue;
			}

			$originals[ $constant ] = $state;
			$updated = self::upsert_wp_config_constant( $updated, $constant, $target_value );
		}

		if ( $updated === $contents ) {
			return array(
				'ok'      => true,
				'message' => __( 'Debug logging is already enabled. No plugin-owned debug changes were created.', 'wp-cli-setup-helper' ),
				'path'    => self::normalize_path( $path ),
			);
		}

		$backup = $path . '.wp-cli-helper-backup-' . gmdate( 'Ymd-His' );

		if ( ! copy( $path, $backup ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Could not create a wp-config.php backup, so no changes were written.', 'wp-cli-setup-helper' ),
				'path'    => self::normalize_path( $path ),
			);
		}

		$written = file_put_contents( $path, $updated, LOCK_EX );

		if ( false === $written ) {
			return array(
				'ok'      => false,
				'message' => __( 'Could not write wp-config.php. The backup was created but the config was not updated.', 'wp-cli-setup-helper' ),
				'path'    => self::normalize_path( $path ),
				'backup'  => self::normalize_path( $backup ),
			);
		}

		update_option(
			self::OPTION_DEBUG_STATE,
			array(
				'owned'      => true,
				'path'       => self::normalize_path( $path ),
				'backup'     => self::normalize_path( $backup ),
				'constants'  => $originals,
				'created_at' => current_time( 'mysql' ),
			),
			false
		);

		return array(
			'ok'      => true,
			'message' => __( 'Debug logging enabled. Re-check the environment or reload the page to see loaded constants update.', 'wp-cli-setup-helper' ),
			'path'    => self::normalize_path( $path ),
			'backup'  => self::normalize_path( $backup ),
		);
	}

	private static function disable_debug_logging(): array {
		return self::restore_debug_logging( true );
	}

	private static function find_wp_config_path(): string {
		$candidates = array(
			ABSPATH . 'wp-config.php',
			dirname( ABSPATH ) . '/wp-config.php',
		);

		foreach ( $candidates as $candidate ) {
			if ( file_exists( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	private static function upsert_wp_config_constant( string $contents, string $constant, string $value ): string {
		$line    = "define( '" . $constant . "', " . $value . " );";
		$pattern = '/define\s*\(\s*[\'"]' . preg_quote( $constant, '/' ) . '[\'"]\s*,\s*[^;]+?\)\s*;/';

		if ( preg_match( $pattern, $contents ) ) {
			return preg_replace( $pattern, $line, $contents, 1 );
		}

		$markers = array(
			"/* That's all, stop editing!",
			'require_once ABSPATH',
		);

		foreach ( $markers as $marker ) {
			$position = strpos( $contents, $marker );

			if ( false !== $position ) {
				return substr( $contents, 0, $position ) . $line . "\n" . substr( $contents, $position );
			}
		}

		return preg_replace( '/<\?php\s*/', "<?php\n" . $line . "\n", $contents, 1 );
	}

	private static function get_wp_config_constant_state( string $contents, string $constant ): array {
		$pattern = '/define\s*\(\s*[\'"]' . preg_quote( $constant, '/' ) . '[\'"]\s*,\s*([^;]+?)\)\s*;/';

		if ( ! preg_match( $pattern, $contents, $matches ) ) {
			return array(
				'exists' => false,
				'raw'    => '',
			);
		}

		return array(
			'exists' => true,
			'raw'    => trim( $matches[1] ),
		);
	}

	private static function constant_raw_matches( array $state, string $target_value ): bool {
		if ( empty( $state['exists'] ) ) {
			return false;
		}

		$raw = strtolower( trim( (string) $state['raw'] ) );

		return $raw === strtolower( $target_value );
	}

	private static function restore_wp_config_constant( string $contents, string $constant, array $state ): string {
		$pattern = '/define\s*\(\s*[\'"]' . preg_quote( $constant, '/' ) . '[\'"]\s*,\s*[^;]+?\)\s*;\s*/';

		if ( ! empty( $state['exists'] ) ) {
			$line = "define( '" . $constant . "', " . (string) $state['raw'] . " );\n";

			if ( preg_match( $pattern, $contents ) ) {
				return preg_replace( $pattern, $line, $contents, 1 );
			}

			return self::upsert_wp_config_constant( $contents, $constant, (string) $state['raw'] );
		}

		return preg_replace( $pattern, '', $contents, 1 );
	}

	private static function restore_debug_logging( bool $clear_option = false ): array {
		$state = get_option( self::OPTION_DEBUG_STATE, array() );

		if ( ! is_array( $state ) || empty( $state['owned'] ) || empty( $state['constants'] ) || ! is_array( $state['constants'] ) ) {
			return array(
				'ok'      => true,
				'message' => __( 'No plugin-owned debug changes were found.', 'wp-cli-setup-helper' ),
			);
		}

		$path = ! empty( $state['path'] ) ? (string) $state['path'] : self::find_wp_config_path();

		if ( '' === $path || ! file_exists( $path ) || ! is_readable( $path ) || ! is_writable( $path ) ) {
			error_log( 'Temporary Site Connector could not restore debug logging because wp-config.php is unavailable or not writable.' );
			return array(
				'ok'      => false,
				'message' => __( 'Could not restore debug logging because wp-config.php is unavailable or not writable.', 'wp-cli-setup-helper' ),
				'path'    => self::normalize_path( $path ),
			);
		}

		$contents = file_get_contents( $path );

		if ( false === $contents ) {
			error_log( 'Temporary Site Connector could not read wp-config.php during uninstall cleanup.' );
			return array(
				'ok'      => false,
				'message' => __( 'Could not read wp-config.php while restoring debug logging.', 'wp-cli-setup-helper' ),
				'path'    => self::normalize_path( $path ),
			);
		}

		$updated = $contents;

		foreach ( $state['constants'] as $constant => $original ) {
			if ( ! is_array( $original ) ) {
				continue;
			}

			$updated = self::restore_wp_config_constant( $updated, (string) $constant, $original );
		}

		if ( $updated !== $contents ) {
			$written = file_put_contents( $path, $updated, LOCK_EX );

			if ( false === $written ) {
				return array(
					'ok'      => false,
					'message' => __( 'Could not write wp-config.php while restoring debug logging.', 'wp-cli-setup-helper' ),
					'path'    => self::normalize_path( $path ),
				);
			}
		}

		if ( $clear_option ) {
			delete_option( self::OPTION_DEBUG_STATE );
		}

		return array(
			'ok'      => true,
			'message' => __( 'Debug logging restored to the previous plugin-tracked state.', 'wp-cli-setup-helper' ),
			'path'    => self::normalize_path( $path ),
		);
	}

	private static function get_diagnostics(): array {
		$theme       = wp_get_theme();
		$uploads     = wp_upload_dir( null, false );
		$wp_root     = self::normalize_path( ABSPATH );
		$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown';
		$debug_log   = self::get_debug_log_path();

		return array(
			'wp_root'        => $wp_root,
			'generated_at'    => current_time( 'mysql' ),
			'site_info'      => array(
				'ABSPATH'                 => $wp_root,
				'site_url'                => site_url(),
				'home_url'                => home_url(),
				'WordPress version'       => get_bloginfo( 'version' ),
				'PHP version'             => PHP_VERSION,
				'Plugin version'          => self::plugin_version(),
				'Active theme'            => sprintf( '%s %s (%s)', $theme->get( 'Name' ), $theme->get( 'Version' ), $theme->get_stylesheet() ),
				'Multisite'               => is_multisite() ? 'yes' : 'no',
				'WP_DEBUG'                => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 'true' : 'false',
				'WP_DEBUG_LOG'            => ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ? 'true' : 'false',
				'WP_DEBUG_DISPLAY'        => ( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ) ? 'true' : 'false',
				'WP_ENVIRONMENT_TYPE'     => defined( 'WP_ENVIRONMENT_TYPE' ) ? WP_ENVIRONMENT_TYPE : 'not defined',
				'wp_get_environment_type' => $environment,
				'PHP_OS'                  => PHP_OS,
				'REST base'               => esc_url_raw( rest_url( self::REST_NAMESPACE ) ),
				'Debug log path'          => $debug_log,
			),
			'writable_paths' => array(
				'WordPress root' => array(
					'path'     => $wp_root,
					'writable' => is_writable( ABSPATH ),
				),
				'wp-content'     => array(
					'path'     => self::normalize_path( WP_CONTENT_DIR ),
					'writable' => is_writable( WP_CONTENT_DIR ),
				),
				'plugins'        => array(
					'path'     => self::normalize_path( WP_PLUGIN_DIR ),
					'writable' => is_writable( WP_PLUGIN_DIR ),
				),
				'uploads'        => array(
					'path'     => isset( $uploads['basedir'] ) ? self::normalize_path( $uploads['basedir'] ) : 'unavailable',
					'writable' => isset( $uploads['basedir'] ) && is_dir( $uploads['basedir'] ) && is_writable( $uploads['basedir'] ),
				),
				'this plugin'    => array(
					'path'     => self::normalize_path( plugin_dir_path( __FILE__ ) ),
					'writable' => is_writable( plugin_dir_path( __FILE__ ) ),
				),
			),
			'capabilities'    => array(
				'shell_exec()'     => self::function_available( 'shell_exec' ),
				'proc_open()'      => self::function_available( 'proc_open' ),
				'exec()'           => self::function_available( 'exec' ),
				'PHP cURL'         => extension_loaded( 'curl' ) && function_exists( 'curl_version' ),
				'OpenSSL'          => extension_loaded( 'openssl' ) || function_exists( 'openssl_verify' ),
				'Phar support'     => class_exists( 'Phar' ),
				'allow_url_fopen'  => (bool) ini_get( 'allow_url_fopen' ),
			),
		);
	}

	private static function get_plugins_report(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins         = get_plugins();
		$active_plugins  = (array) get_option( 'active_plugins', array() );
		$network_plugins = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
		$report          = array();

		foreach ( $plugins as $plugin_file => $plugin_data ) {
			$report[] = array(
				'file'           => $plugin_file,
				'name'           => isset( $plugin_data['Name'] ) ? $plugin_data['Name'] : '',
				'version'        => isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : '',
				'author'         => isset( $plugin_data['Author'] ) ? wp_strip_all_tags( $plugin_data['Author'] ) : '',
				'plugin_uri'     => isset( $plugin_data['PluginURI'] ) ? $plugin_data['PluginURI'] : '',
				'is_active'      => in_array( $plugin_file, $active_plugins, true ),
				'network_active' => in_array( $plugin_file, $network_plugins, true ),
			);
		}

		return array(
			'count'       => count( $report ),
			'active'      => count( array_filter( $report, array( __CLASS__, 'is_plugin_active_item' ) ) ),
			'plugins'     => $report,
			'generated_at' => current_time( 'mysql' ),
		);
	}

	private static function is_plugin_active_item( array $plugin ): bool {
		return ! empty( $plugin['is_active'] ) || ! empty( $plugin['network_active'] );
	}

	private static function get_theme_report(): array {
		$active_theme = wp_get_theme();
		$themes       = wp_get_themes();
		$installed    = array();

		foreach ( $themes as $stylesheet => $theme ) {
			$installed[] = array(
				'stylesheet' => $stylesheet,
				'name'       => $theme->get( 'Name' ),
				'version'    => $theme->get( 'Version' ),
				'template'   => $theme->get_template(),
				'is_active'  => $stylesheet === $active_theme->get_stylesheet(),
			);
		}

		return array(
			'active'      => array(
				'name'       => $active_theme->get( 'Name' ),
				'version'    => $active_theme->get( 'Version' ),
				'stylesheet' => $active_theme->get_stylesheet(),
				'template'   => $active_theme->get_template(),
			),
			'installed'   => $installed,
			'generated_at' => current_time( 'mysql' ),
		);
	}

	private static function get_options_report(): array {
		$option_names = array(
			'siteurl',
			'home',
			'blogname',
			'admin_email',
			'users_can_register',
			'default_role',
			'timezone_string',
			'gmt_offset',
			'permalink_structure',
			'active_plugins',
			'template',
			'stylesheet',
			'current_theme',
			'upload_path',
			'uploads_use_yearmonth_folders',
			'blog_public',
		);
		$options      = array();

		foreach ( $option_names as $option_name ) {
			$value = get_option( $option_name, null );

			if ( is_array( $value ) ) {
				$options[ $option_name ] = array(
					'type'  => 'array',
					'count' => count( $value ),
					'value' => $value,
				);
				continue;
			}

			$options[ $option_name ] = array(
				'type'  => gettype( $value ),
				'value' => self::redact_sensitive_value( $option_name, $value ),
			);
		}

		return array(
			'options'      => $options,
			'autoload_top' => self::get_autoload_report(),
			'generated_at' => current_time( 'mysql' ),
		);
	}

	private static function get_autoload_report(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT option_name, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE autoload = 'yes' ORDER BY bytes DESC LIMIT 20",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( array $row ): array {
				return array(
					'option_name' => self::redact_sensitive_key( (string) $row['option_name'] ),
					'bytes'       => isset( $row['bytes'] ) ? absint( $row['bytes'] ) : 0,
				);
			},
			$rows
		);
	}

	private static function get_cron_report(): array {
		$cron   = _get_cron_array();
		$events = array();

		if ( ! is_array( $cron ) ) {
			$cron = array();
		}

		foreach ( $cron as $timestamp => $hooks ) {
			foreach ( $hooks as $hook => $hook_events ) {
				foreach ( $hook_events as $event ) {
					$events[] = array(
						'hook'      => $hook,
						'timestamp' => (int) $timestamp,
						'datetime'  => gmdate( 'c', (int) $timestamp ),
						'schedule'  => isset( $event['schedule'] ) ? $event['schedule'] : false,
						'args'      => isset( $event['args'] ) ? self::summarize_value( $event['args'] ) : array(),
						'overdue'   => (int) $timestamp < time(),
					);
				}
			}
		}

		usort(
			$events,
			static function ( array $a, array $b ): int {
				return $a['timestamp'] <=> $b['timestamp'];
			}
		);

		return array(
			'count'       => count( $events ),
			'overdue'     => count(
				array_filter(
					$events,
					static function ( array $event ): bool {
						return ! empty( $event['overdue'] );
					}
				)
			),
			'events'      => array_slice( $events, 0, 100 ),
			'generated_at' => current_time( 'mysql' ),
		);
	}

	private static function get_transients_report(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT option_name, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%' ORDER BY option_id DESC LIMIT 100",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$items = array_map(
			static function ( array $row ): array {
				return array(
					'name'  => self::redact_sensitive_key( (string) $row['option_name'] ),
					'bytes' => isset( $row['bytes'] ) ? absint( $row['bytes'] ) : 0,
				);
			},
			$rows
		);

		return array(
			'count_sample' => count( $items ),
			'items'        => $items,
			'generated_at' => current_time( 'mysql' ),
		);
	}

	private static function get_debug_log_report( int $lines ): array {
		$path = self::get_debug_log_path();

		if ( ! $path || ! file_exists( $path ) || ! is_readable( $path ) ) {
			return array(
				'readable'     => false,
				'path'         => $path,
				'lines'        => array(),
				'message'      => 'Debug log is not available or not readable.',
				'generated_at' => current_time( 'mysql' ),
			);
		}

		return array(
			'readable'     => true,
			'path'         => self::normalize_path( $path ),
			'size_bytes'   => filesize( $path ),
			'lines'        => self::tail_file( $path, $lines ),
			'generated_at' => current_time( 'mysql' ),
		);
	}

	private static function get_context_report(): array {
		return array(
			'identity'    => array(
				'plugin'      => 'codex-site-connector',
				'version'     => self::plugin_version(),
				'description' => 'Temporary authenticated connector for WordPress diagnostics and Codex debugging conversations.',
				'user_id'     => get_current_user_id(),
				'remove_when_done' => true,
			),
			'endpoints'   => array(
				'GET /health'      => 'Auth and connector health.',
				'GET /diagnostics' => 'Environment, paths, debug constants, and server capability flags.',
				'GET /plugins'     => 'Installed plugin inventory with active states.',
				'GET /theme'       => 'Active and installed themes.',
				'GET /options'     => 'Safe option snapshot and largest autoloaded options.',
				'GET /cron'        => 'Upcoming and overdue WP-Cron events.',
				'GET /transients'  => 'Recent transient sample and value sizes.',
				'GET /debug-log'   => 'Tail of wp-content/debug.log or custom WP_DEBUG_LOG file.',
				'GET /search'      => 'Read-only post/content search.',
				'POST /ask'        => 'Bundle a debugging question with selected site context.',
				'GET /commands'    => 'Copy-ready REST, WP-CLI, and SSH command examples.',
			),
			'workflow'    => array(
				'Create a temporary Application Password for an admin user.',
				'Call /health to confirm authentication.',
				'Call /ask with the issue description, URL, browser console error, or failed request.',
				'Use /search for relevant pages, templates, listings, or Elementor content.',
				'Delete the Application Password and remove this plugin when debugging is finished.',
			),
			'generated_at' => current_time( 'mysql' ),
		);
	}

	private static function get_conversation_report( array $request ): array {
		$include = isset( $request['include'] ) && is_array( $request['include'] ) ? $request['include'] : array();
		$context = array();
		$allowed = array( 'diagnostics', 'plugins', 'theme', 'options', 'cron', 'transients', 'debug-log' );

		foreach ( $include as $section ) {
			if ( ! in_array( $section, $allowed, true ) ) {
				continue;
			}

			switch ( $section ) {
				case 'diagnostics':
					$context['diagnostics'] = self::get_diagnostics();
					break;
				case 'plugins':
					$context['plugins'] = self::get_plugins_report();
					break;
				case 'theme':
					$context['theme'] = self::get_theme_report();
					break;
				case 'options':
					$context['options'] = self::get_options_report();
					break;
				case 'cron':
					$context['cron'] = self::get_cron_report();
					break;
				case 'transients':
					$context['transients'] = self::get_transients_report();
					break;
				case 'debug-log':
					$context['debug_log'] = self::get_debug_log_report( 200 );
					break;
			}
		}

		$page_probe = array();

		if ( ! empty( $request['url'] ) ) {
			$page_probe = self::get_url_probe( (string) $request['url'] );
		}

		return array(
			'request'     => array(
				'question' => isset( $request['question'] ) ? $request['question'] : '',
				'url'      => isset( $request['url'] ) ? $request['url'] : '',
				'error'    => isset( $request['error'] ) ? $request['error'] : '',
				'include'  => $include,
			),
			'site_context' => $context,
			'url_probe'    => $page_probe,
			'next_probes'  => self::get_next_probe_suggestions( $request ),
			'safety'      => array(
				'read_only'             => true,
				'shell_execution'       => false,
				'file_write'            => false,
				'secret_storage'        => false,
				'delete_after_debugging' => true,
			),
			'generated_at' => current_time( 'mysql' ),
		);
	}

	private static function get_url_probe( string $url ): array {
		$post_id = url_to_postid( $url );

		if ( ! $post_id ) {
			return array(
				'matched_post' => false,
				'url'          => $url,
			);
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return array(
				'matched_post' => false,
				'url'          => $url,
			);
		}

		return array(
			'matched_post'       => true,
			'id'                 => $post_id,
			'type'               => get_post_type( $post ),
			'status'             => get_post_status( $post ),
			'title'              => get_the_title( $post ),
			'permalink'          => get_permalink( $post ),
			'template'           => get_page_template_slug( $post ),
			'has_elementor_data' => metadata_exists( 'post', $post_id, '_elementor_data' ),
			'elementor_edit_mode' => get_post_meta( $post_id, '_elementor_edit_mode', true ),
			'modified_gmt'       => get_post_modified_time( 'c', true, $post ),
		);
	}

	private static function get_next_probe_suggestions( array $request ): array {
		$suggestions = array(
			'Call /debug-log?lines=300 after reproducing the issue.',
			'Call /plugins and compare active plugin versions with the staging/local site.',
			'Call /options to check URL, permalink, theme, and autoload pressure.',
		);

		$error = isset( $request['error'] ) ? strtolower( (string) $request['error'] ) : '';

		if ( false !== strpos( $error, '500' ) || false !== strpos( $error, 'fatal' ) ) {
			$suggestions[] = 'Reproduce once, then inspect /debug-log for the matching timestamp and stack trace.';
		}

		if ( false !== strpos( $error, 'ajax' ) || false !== strpos( $error, 'admin-ajax' ) || false !== strpos( $error, 'rest' ) ) {
			$suggestions[] = 'Capture the failed Network request URL, response body, status code, and nonce/cookie state.';
		}

		if ( false !== strpos( $error, 'elementor' ) ) {
			$suggestions[] = 'Use /search with the affected page title or slug to confirm Elementor metadata exists.';
		}

		return $suggestions;
	}

	private static function get_search_report( string $query, string $post_type, int $limit ): array {
		$query_args = array(
			's'              => $query,
			'post_status'    => 'any',
			'posts_per_page' => $limit,
			'post_type'      => $post_type ? $post_type : 'any',
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);

		$posts   = get_posts( $query_args );
		$results = array();

		foreach ( $posts as $post ) {
			$results[] = array(
				'id'                 => $post->ID,
				'type'               => get_post_type( $post ),
				'status'             => get_post_status( $post ),
				'title'              => get_the_title( $post ),
				'permalink'          => get_permalink( $post ),
				'modified_gmt'       => get_post_modified_time( 'c', true, $post ),
				'has_elementor_data' => metadata_exists( 'post', $post->ID, '_elementor_data' ),
				'excerpt'            => wp_trim_words( wp_strip_all_tags( $post->post_content ), 40 ),
			);
		}

		return array(
			'query'       => $query,
			'post_type'   => $post_type ? $post_type : 'any',
			'count'       => count( $results ),
			'results'     => $results,
			'generated_at' => current_time( 'mysql' ),
		);
	}

	private static function get_debug_log_path(): string {
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ) {
			return self::normalize_path( WP_DEBUG_LOG );
		}

		return self::normalize_path( WP_CONTENT_DIR . '/debug.log' );
	}

	private static function tail_file( string $path, int $lines ): array {
		$size   = filesize( $path );
		$offset = 0;

		if ( false !== $size && $size > 2097152 ) {
			$offset = $size - 2097152;
		}

		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			return array();
		}

		if ( $offset > 0 ) {
			fseek( $handle, $offset );
		}

		$content = stream_get_contents( $handle );
		fclose( $handle );

		if ( false === $content || '' === $content ) {
			return array();
		}

		$content_lines = preg_split( '/\r\n|\r|\n/', $content );

		if ( ! is_array( $content_lines ) ) {
			return array();
		}

		$content_lines = array_filter(
			$content_lines,
			static function ( string $line ): bool {
				return '' !== trim( $line );
			}
		);

		return array_values( array_slice( $content_lines, -1 * $lines ) );
	}

	private static function get_rest_examples(): string {
		$base = untrailingslashit( rest_url( self::REST_NAMESPACE ) );

		return implode(
			"\n",
			array(
				'# Replace admin_user and APPLICATION_PASSWORD with a temporary Application Password.',
				'curl -u "admin_user:APPLICATION_PASSWORD" ' . self::shell_quote( $base . '/health' ),
				'curl -u "admin_user:APPLICATION_PASSWORD" ' . self::shell_quote( $base . '/diagnostics' ),
				'curl -u "admin_user:APPLICATION_PASSWORD" ' . self::shell_quote( $base . '/plugins' ),
				'curl -u "admin_user:APPLICATION_PASSWORD" ' . self::shell_quote( $base . '/theme' ),
				'curl -u "admin_user:APPLICATION_PASSWORD" ' . self::shell_quote( $base . '/options' ),
				'curl -u "admin_user:APPLICATION_PASSWORD" ' . self::shell_quote( $base . '/cron' ),
				'curl -u "admin_user:APPLICATION_PASSWORD" ' . self::shell_quote( $base . '/transients' ),
				'curl -u "admin_user:APPLICATION_PASSWORD" ' . self::shell_quote( $base . '/debug-log?lines=200' ),
				'curl -u "admin_user:APPLICATION_PASSWORD" ' . self::shell_quote( $base . '/commands' ),
				'curl -u "admin_user:APPLICATION_PASSWORD" ' . self::shell_quote( $base . '/context' ),
				'curl -u "admin_user:APPLICATION_PASSWORD" ' . self::shell_quote( $base . '/search?q=homepage&limit=10' ),
			)
		);
	}

	private static function get_context_examples(): string {
		$base = untrailingslashit( rest_url( self::REST_NAMESPACE ) );
		$ask_payload = wp_json_encode(
			array(
				'question' => 'Elementor page is broken after update. Find likely cause.',
				'url'      => home_url( '/' ),
				'error'    => 'Paste browser console or Network error here.',
				'include'  => array( 'diagnostics', 'plugins', 'theme', 'options', 'cron', 'debug-log' ),
			),
			JSON_PRETTY_PRINT
		);

		return implode(
			"\n",
			array(
				'# Health check.',
				'curl -u "admin_user:APPLICATION_PASSWORD" ' . self::shell_quote( $base . '/health' ),
				'',
				'# Give Codex a bundled site context for a specific issue.',
				'curl -u "admin_user:APPLICATION_PASSWORD" -H "Content-Type: application/json" -X POST ' . self::shell_quote( $base . '/ask' ) . ' -d ' . self::shell_quote( $ask_payload ),
				'',
				'# Search WordPress content without changing anything.',
				'curl -u "admin_user:APPLICATION_PASSWORD" ' . self::shell_quote( $base . '/search?q=pricing&limit=10' ),
			)
		);
	}

	private static function get_debug_commands( string $wp_root ): string {
		$quoted_root = self::shell_quote( $wp_root );

		return implode(
			"\n",
			array(
				'ssh user@host',
				'cd ' . $quoted_root,
				'wp --info',
				'php wp-cli.phar --info',
				'wp plugin list',
				'wp theme list',
				'wp option get home',
				'wp option get siteurl',
				'wp transient list',
				'wp cron event list',
				'wp core verify-checksums',
				'wp plugin verify-checksums --all',
			)
		);
	}

	private static function get_alias_template( string $wp_root ): string {
		$normalized_root = self::normalize_path( $wp_root );

		return implode(
			"\n",
			array(
				'# Add this to ~/.wp-cli/config.yml on your machine, then replace user@host.',
				'@client-site:',
				'  ssh: user@host:' . $normalized_root,
				'',
				'# Example usage after saving the alias:',
				'wp @client-site --info',
				'wp @client-site plugin list',
				'wp @client-site option get home',
			)
		);
	}

	private static function get_install_commands(): string {
		return implode(
			"\n",
			array(
				'# Run these from SSH or your hosting terminal, not from the WordPress admin browser.',
				'curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar',
				'curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar.asc',
				'curl -L https://raw.githubusercontent.com/wp-cli/builds/gh-pages/wp-cli.pgp | gpg --import',
				'gpg --verify wp-cli.phar.asc wp-cli.phar',
				'php wp-cli.phar --info',
				'',
				'# User-local install when you do not have sudo/root access.',
				'mkdir -p "$HOME/bin"',
				'chmod +x wp-cli.phar',
				'cp wp-cli.phar "$HOME/bin/wp"',
				'"$HOME/bin/wp" --info',
				'',
				'# Global install when your hosting/server user has permission.',
				'sudo mv wp-cli.phar /usr/local/bin/wp',
				'wp --info',
			)
		);
	}

	private static function function_available( string $function_name ): bool {
		if ( ! function_exists( $function_name ) ) {
			return false;
		}

		$disabled_functions = array_map(
			'trim',
			explode( ',', strtolower( (string) ini_get( 'disable_functions' ) ) )
		);

		return ! in_array( strtolower( $function_name ), $disabled_functions, true );
	}

	private static function plugin_version(): string {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$data = get_plugin_data( __FILE__, false, false );

		return isset( $data['Version'] ) ? (string) $data['Version'] : 'unknown';
	}

	private static function redact_sensitive_key( string $key ): string {
		return self::is_sensitive_key( $key ) ? '[redacted-sensitive-key]' : $key;
	}

	private static function redact_sensitive_value( string $key, $value ) {
		if ( self::is_sensitive_key( $key ) ) {
			return '[redacted]';
		}

		return $value;
	}

	private static function is_sensitive_key( string $key ): bool {
		$patterns = array(
			'password',
			'passwd',
			'secret',
			'token',
			'key',
			'auth',
			'salt',
			'nonce',
			'license',
			'private',
			'credential',
		);

		$key = strtolower( $key );

		foreach ( $patterns as $pattern ) {
			if ( false !== strpos( $key, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	private static function summarize_value( $value ) {
		if ( is_scalar( $value ) || null === $value ) {
			return $value;
		}

		if ( is_array( $value ) ) {
			$summary = array();

			foreach ( $value as $key => $item ) {
				if ( self::is_sensitive_key( (string) $key ) ) {
					$summary[ $key ] = '[redacted]';
					continue;
				}

				if ( is_scalar( $item ) || null === $item ) {
					$summary[ $key ] = $item;
					continue;
				}

				$summary[ $key ] = is_array( $item ) ? '[array:' . count( $item ) . ']' : '[' . gettype( $item ) . ']';
			}

			return $summary;
		}

		return '[' . gettype( $value ) . ']';
	}

	private static function normalize_path( string $path ): string {
		return wp_normalize_path( untrailingslashit( $path ) );
	}

	private static function shell_quote( string $value ): string {
		return "'" . str_replace( "'", "'\\''", $value ) . "'";
	}
}

register_activation_hook( __FILE__, array( 'WP_CLI_Setup_Helper', 'activate' ) );
register_uninstall_hook( __FILE__, array( 'WP_CLI_Setup_Helper', 'uninstall' ) );
WP_CLI_Setup_Helper::init();
