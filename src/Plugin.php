<?php
declare(strict_types=1);

namespace Publikate\MailPoetBouncer;

use Publikate\MailPoetBouncer\Admin\Settings;
use Publikate\MailPoetBouncer\Admin\SettingsPage;
use Publikate\MailPoetBouncer\Audit\Logger;
use Publikate\MailPoetBouncer\Bounce\ProcessorService;
use Publikate\MailPoetBouncer\CLI\ProcessCommand;
use Publikate\MailPoetBouncer\Support\Notices;
use Publikate\MailPoetBouncer\Support\Requirements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orquestador principal del ciclo de vida del plugin.
 */
final class Plugin {

	private static ?self $instance = null;

	public static function getInstance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Inicializa los módulos del plugin de forma segura.
	 */
	public function boot(): void {
		// 1. Verificación de requisitos del sistema
		$requirements = Requirements::check();
		if ( ! empty( $requirements['errors'] ) ) {
			foreach ( $requirements['errors'] as $error ) {
				Notices::error( $error );
			}
			// Permitir cargar solo el menú administrativo si falta algún componente para que el usuario pueda ver el estado
		}

		// 2. Registro de interfaces de administración
		if ( is_admin() ) {
			SettingsPage::init();
			add_filter( 'plugin_action_links_' . PKMB_BASENAME, [ $this, 'addSettingsLink' ] );
		}

		// 3. Registro de comandos WP-CLI
		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			ProcessCommand::register();
		}
	}

	/**
	 * Hook de activación del plugin.
	 */
	public static function activate(): void {
		// Crear tabla de auditoría en la base de datos
		Logger::createTable();

		// Inicializar opciones por defecto si no existen
		if ( false === get_option( Settings::OPTION_KEY ) ) {
			add_option( Settings::OPTION_KEY, Settings::getDefaults(), '', false );
		}
	}

	/**
	 * Hook de desactivación del plugin.
	 */
	public static function deactivate(): void {
		// Liberar cualquier bloqueo pendiente
		delete_transient( ProcessorService::LOCK_KEY );
	}

	/**
	 * Añade el enlace rápido a "Ajustes" en la lista de plugins.
	 *
	 * @param array<int|string, string> $links
	 * @return array<int|string, string>
	 */
	public function addSettingsLink( array $links ): array {
		$settingsUrl  = admin_url( 'options-general.php?page=' . SettingsPage::PAGE_SLUG );
		$settingsLink = sprintf( '<a href="%s">%s</a>', esc_url( $settingsUrl ), esc_html__( 'Ajustes', 'mailpoet-bouncer' ) );
		array_unshift( $links, $settingsLink );
		return $links;
	}
}
