<?php
declare(strict_types=1);

namespace Publikate\MailPoetBouncer\CLI;

use Publikate\MailPoetBouncer\Bounce\ProcessorService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Comando WP-CLI para el procesamiento seguro de rebotes en segundo plano.
 * Delega la ejecución a ProcessorService garantizando paridad total con la UI.
 */
final class ProcessCommand {

	public const COMMAND_NAME = 'mailpoet-bouncer process';

	/**
	 * Registra el comando en WP-CLI si está disponible.
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}

		\WP_CLI::add_command(
			self::COMMAND_NAME,
			[ self::class, 'execute' ],
			[
				'shortdesc' => 'Procesa mensajes de rebote desde IMAP y actualiza MailPoet.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'limit',
						'description' => 'Cantidad máxima de correos a procesar.',
						'optional'    => true,
					],
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Ejecuta en modo simulación sin actualizar MailPoet ni borrar correos.',
						'optional'    => true,
					],
					[
						'type'        => 'flag',
						'name'        => 'force',
						'description' => 'Omite el lock previo si quedó colgado por una caída abrupta.',
						'optional'    => true,
					],
				],
			]
		);
	}

	/**
	 * Ejecuta el ciclo de procesamiento delegando en ProcessorService.
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assocArgs
	 */
	public static function execute( array $args, array $assocArgs ): void {
		$limit  = self::normalizeLimit( $assocArgs['limit'] ?? null );
		$dryRun = isset( $assocArgs['dry-run'] ) ? true : null;
		$force  = isset( $assocArgs['force'] );

		\WP_CLI::line( '=== MailPoet Bouncer ===' );

		$result = ProcessorService::process(
			limitOverride: $limit,
			dryRunOverride: $dryRun,
			force: $force,
			loggerCallback: static function ( string $line ): void {
				\WP_CLI::line( $line );
			}
		);

		if ( 'warning' === $result['status'] ) {
			\WP_CLI::warning( $result['message'] );
			return;
		}

		if ( ! $result['success'] ) {
			\WP_CLI::error( $result['message'] );
			return;
		}

		$summary = $result['summary'];
		\WP_CLI::line( '----------------------------------------' );
		\WP_CLI::success(
			sprintf(
				'Procesamiento finalizado. Total: %d | Hard: %d | Soft: %d | Autoresponder: %d | Ignorados: %d | MailPoet actualizados: %d | Eliminados IMAP: %d | Duración: %d ms',
				(int) $summary['processed'],
				(int) $summary['hard'],
				(int) $summary['soft'],
				(int) $summary['autoresponses'],
				(int) $summary['ignored'],
				(int) $summary['updated_mp'],
				(int) $summary['deleted_imap'],
				(int) $summary['duration_ms']
			)
		);
	}

	/**
	 * Normaliza el límite recibido por CLI.
	 */
	private static function normalizeLimit( mixed $limit ): ?int {
		if ( null === $limit || '' === $limit ) {
			return null;
		}

		$value = absint( (string) $limit );

		if ( $value < ProcessorService::LIMIT_MIN ) {
			return ProcessorService::LIMIT_MIN;
		}

		if ( $value > ProcessorService::LIMIT_MAX ) {
			return ProcessorService::LIMIT_MAX;
		}

		return $value;
	}
}