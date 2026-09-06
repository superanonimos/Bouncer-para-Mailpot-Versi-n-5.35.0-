<?php
/**
 * Plugin Name: MailPoet Bouncer (Publikate)
 * Plugin URI:  https://publikate.cl
 * Description: Procesa hard bounces de MailPoet por IMAP y WP-CLI.
 * Version:     1.2.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author:      Publikate
 * Author URI:  https://publikate.cl
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mailpoet-bouncer
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PKMB_VERSION', '1.2.0' );
define( 'PKMB_FILE', __FILE__ );
define( 'PKMB_PATH', plugin_dir_path( __FILE__ ) );
define( 'PKMB_URL', plugin_dir_url( __FILE__ ) );
define( 'PKMB_BASENAME', plugin_basename( __FILE__ ) );

function pkmb_load_autoloader(): bool {
	$candidates = [
		PKMB_PATH . 'vendor/scoper-autoload.php',
		PKMB_PATH . 'vendor/autoload.php',
	];

	foreach ( $candidates as $autoload ) {
		if ( file_exists( $autoload ) ) {
			require_once $autoload;
			return true;
		}
	}

	spl_autoload_register(
		static function ( $class ) {
			$prefix   = 'Publikate\\MailPoetBouncer\\';
			$base_dir = PKMB_PATH . 'src/';

			if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
				return;
			}

			$relative = substr( $class, strlen( $prefix ) );
			$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	);

	return class_exists( 'Publikate\\MailPoetBouncer\\Plugin', true );
}

function pkmb_activate(): void {
	if ( class_exists( 'Publikate\\MailPoetBouncer\\Plugin', true ) ) {
		\Publikate\MailPoetBouncer\Plugin::activate();
	}
}

function pkmb_deactivate(): void {
	if ( class_exists( 'Publikate\\MailPoetBouncer\\Plugin', true ) ) {
		\Publikate\MailPoetBouncer\Plugin::deactivate();
	}
}

$pkmb_loaded = pkmb_load_autoloader();

add_action(
	'plugins_loaded',
	static function () use ( $pkmb_loaded ) {
		if ( ! $pkmb_loaded || ! class_exists( 'Publikate\\MailPoetBouncer\\Plugin', false ) ) {
			add_action(
				'admin_notices',
				static function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-error"><p><strong>' .
							esc_html__( 'MailPoet Bouncer', 'mailpoet-bouncer' ) .
							':</strong> ' .
							esc_html__( 'No se pudo cargar el bootstrap del plugin. Revisa el artefacto compilado.', 'mailpoet-bouncer' ) .
							'</p></div>';
					}
				}
			);
			return;
		}

		\Publikate\MailPoetBouncer\Plugin::getInstance()->boot();
	},
	20
);

register_activation_hook( __FILE__, 'pkmb_activate' );
register_deactivation_hook( __FILE__, 'pkmb_deactivate' );