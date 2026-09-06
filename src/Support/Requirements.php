<?php
declare(strict_types=1);

namespace Publikate\MailPoetBouncer\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verificación de requisitos del sistema.
 */
final class Requirements {

	public const MIN_PHP_VERSION = '8.1.0';
	public const MIN_WP_VERSION  = '6.0.0';

	/**
	 * Evalúa si el entorno cumple con los requisitos mínimos de ejecución.
	 *
	 * @return array{passed: bool, errors: string[], warnings: string[]}
	 */
	public static function check(): array {
		$errors   = [];
		$warnings = [];

		// 1. Versión de PHP
		if ( version_compare( PHP_VERSION, self::MIN_PHP_VERSION, '<' ) ) {
			$errors[] = sprintf(
				/* translators: 1: Required PHP version, 2: Current PHP version */
				__( 'Se requiere PHP %1$s o superior. Su servidor ejecuta actualmente la versión %2$s.', 'mailpoet-bouncer' ),
				self::MIN_PHP_VERSION,
				PHP_VERSION
			);
		}

		// 2. Versión de WordPress
		global $wp_version;
		if ( version_compare( $wp_version, self::MIN_WP_VERSION, '<' ) ) {
			$errors[] = sprintf(
				/* translators: 1: Required WP version, 2: Current WP version */
				__( 'Se requiere WordPress %1$s o superior. Su instalación tiene la versión %2$s.', 'mailpoet-bouncer' ),
				self::MIN_WP_VERSION,
				$wp_version
			);
		}

		// 3. Verificación de MailPoet
		if ( ! self::isMailPoetActive() ) {
			$warnings[] = __( 'El plugin MailPoet no está activo o sus clases no están cargadas. El procesamiento de rebotes no podrá actualizar suscriptores hasta que MailPoet esté operativo.', 'mailpoet-bouncer' );
		}

		// 4. Verificación de capacidades de conexión IMAP / Sockets
		if ( ! extension_loaded( 'imap' ) && ! function_exists( 'stream_socket_client' ) ) {
			$errors[] = __( 'Ni la extensión PHP IMAP ni las funciones de socket seguro (stream_socket_client) están habilitadas en este servidor.', 'mailpoet-bouncer' );
		} elseif ( ! extension_loaded( 'imap' ) ) {
			$warnings[] = __( 'La extensión PHP IMAP no está instalada; se utilizará el conector por sockets seguros en modo fallback.', 'mailpoet-bouncer' );
		}

		return [
			'passed'   => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
		];
	}

	/**
	 * Determina si MailPoet está instalado y accesible.
	 */
	public static function isMailPoetActive(): bool {
		if ( class_exists( '\MailPoet\API\API', false ) || class_exists( '\MailPoet\API\API' ) ) {
			return true;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( 'mailpoet/mailpoet.php' );
	}
}
