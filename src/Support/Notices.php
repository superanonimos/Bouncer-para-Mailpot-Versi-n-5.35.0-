<?php
declare(strict_types=1);

namespace Publikate\MailPoetBouncer\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestor de avisos administrativos en el panel de WordPress.
 */
final class Notices {

	/**
	 * Muestra un aviso de error para administradores.
	 */
	public static function error( string $message ): void {
		self::addNotice( $message, 'error' );
	}

	/**
	 * Muestra un aviso de advertencia para administradores.
	 */
	public static function warning( string $message ): void {
		self::addNotice( $message, 'warning' );
	}

	/**
	 * Muestra un aviso de éxito para administradores.
	 */
	public static function success( string $message ): void {
		self::addNotice( $message, 'success' );
	}

	/**
	 * Muestra un aviso informativo para administradores.
	 */
	public static function info( string $message ): void {
		self::addNotice( $message, 'info' );
	}

	/**
	 * Encola el renderizado del aviso en el hook admin_notices.
	 */
	private static function addNotice( string $message, string $type ): void {
		add_action(
			'admin_notices',
			static function () use ( $message, $type ): void {
				if ( ! current_user_can( 'manage_options' ) ) {
					return;
				}

				$class = match ( $type ) {
					'error'   => 'notice notice-error',
					'warning' => 'notice notice-warning',
					'success' => 'notice notice-success',
					default   => 'notice notice-info',
				};

				printf(
					'<div class="%1$s is-dismissible"><p><strong>%2$s:</strong> %3$s</p></div>',
					esc_attr( $class ),
					esc_html__( 'MailPoet Bouncer', 'mailpoet-bouncer' ),
					wp_kses_post( $message )
				);
			}
		);
	}
}
