<?php
declare(strict_types=1);

namespace Publikate\MailPoetBouncer\Bounce;

use Publikate\MailPoetBouncer\Admin\Settings;
use Publikate\MailPoetBouncer\Audit\Logger;
use Publikate\MailPoetBouncer\Audit\StatsRepository;
use Publikate\MailPoetBouncer\IMAP\ClientFactory;
use Publikate\MailPoetBouncer\MailPoet\SubscriberUpdater;
use Publikate\MailPoetBouncer\Support\Requirements;
use Publikate\MailPoetBouncer\Support\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Servicio centralizado y reutilizable para el procesamiento de rebotes por IMAP.
 * Invocado tanto por el comando WP-CLI como por la ejecución manual desde wp-admin.
 */
final class ProcessorService {

	public const LOCK_KEY  = 'pkmb_cli_process_lock';
	public const LOCK_TTL  = 10 * MINUTE_IN_SECONDS;
	public const LIMIT_MIN = 1;
	public const LIMIT_MAX = 500;

	/**
	 * Ejecuta el ciclo completo de procesamiento de rebotes.
	 *
	 * @param int|null      $limitOverride  Límite opcional de mensajes a procesar.
	 * @param bool|null     $dryRunOverride Si es true, fuerza modo simulación sin modificar datos.
	 * @param bool          $force          Si es true, ignora el lock de ejecución previo.
	 * @param callable|null $loggerCallback Callback opcional para emitir trazas (usado por CLI).
	 *
	 * @return array{
	 *   success: bool,
	 *   status: string,
	 *   message: string,
	 *   details: array<int, string>,
	 *   summary: array{
	 *     processed: int,
	 *     hard: int,
	 *     soft: int,
	 *     autoresponses: int,
	 *     ignored: int,
	 *     errors: int,
	 *     updated_mp: int,
	 *     deleted_imap: int,
	 *     duration_ms: int
	 *   },
	 *   last_run: array{
	 *     started_at: string,
	 *     finished_at: string
	 *   }
	 * }
	 */
	public static function process(
		?int $limitOverride = null,
		?bool $dryRunOverride = null,
		bool $force = false,
		?callable $loggerCallback = null
	): array {
		$log = static function ( string $msg ) use ( $loggerCallback ): void {
			if ( null !== $loggerCallback && is_callable( $loggerCallback ) ) {
				$loggerCallback( $msg );
			}
		};

		$startedAtUnix  = microtime( true );
		$startedAtIso   = gmdate( 'Y-m-d\TH:i:s\Z' );
		$details        = [];

		// 1. Verificación de Requisitos
		$reqChecks = Requirements::check();
		if ( ! empty( $reqChecks['errors'] ) ) {
			$errMsg = (string) reset( $reqChecks['errors'] );
			return self::buildResponse(
				success: false,
				status: 'error',
				message: __( 'El entorno no cumple los requisitos mínimos: ', 'mailpoet-bouncer' ) . $errMsg,
				details: array_map( 'strval', $reqChecks['errors'] ),
				stats: self::emptyStats(),
				startedAt: $startedAtIso,
				durationMs: 0
			);
		}

		// 2. Control de Concurrencia (Lock)
		$token = self::acquireLock( $force );
		if ( null === $token ) {
			return self::buildResponse(
				success: false,
				status: 'warning',
				message: __( 'Ya existe un proceso de MailPoet Bouncer en ejecución. Si el proceso previo se interrumpió abruptamente, intente nuevamente en unos minutos.', 'mailpoet-bouncer' ),
				details: [ __( 'Bloqueo activo detectado en cache/transient.', 'mailpoet-bouncer' ) ],
				stats: self::emptyStats(),
				startedAt: $startedAtIso,
				durationMs: 0
			);
		}

		try {
			// 3. Verificación de Ajustes IMAP
			$options = Settings::getAll();
			if ( empty( $options['host'] ) || empty( $options['username'] ) || empty( $options['password'] ) ) {
				throw new \RuntimeException( __( 'La configuración IMAP está incompleta en Ajustes > MailPoet Bouncer.', 'mailpoet-bouncer' ) );
			}

			$batchSize = $limitOverride ?? max( self::LIMIT_MIN, (int) ( $options['batch_size'] ?? 50 ) );
			$batchSize = min( self::LIMIT_MAX, $batchSize );

			$dryRun = null !== $dryRunOverride ? $dryRunOverride : ! empty( $options['dry_run'] );
			$delete = ! empty( $options['delete_after_process'] ) && ! $dryRun;

			$log( sprintf( 'Conectando al servidor IMAP: %s:%d (Límite: %d, Dry-run: %s)...', (string) $options['host'], (int) $options['port'], $batchSize, $dryRun ? 'SÍ' : 'NO' ) );

			// 4. Conexión IMAP
			$client = ClientFactory::create( $options );
			$client->connect();

			try {
				$log( 'Buscando mensajes no leídos (UNSEEN)...' );
				$messageIds = $client->getUnreadMessageIds( $batchSize );

				if ( empty( $messageIds ) ) {
					$duration = microtime( true ) - $startedAtUnix;
					$durationMs = (int) round( $duration * 1000 );

					$emptyStats = self::emptyStats();
					$emptyStats['duration_ms'] = $durationMs;

					StatsRepository::recordRun( 0, $duration, $emptyStats );

					$log( 'No se encontraron mensajes no leídos para procesar.' );

					return self::buildResponse(
						success: true,
						status: 'warning',
						message: __( 'No se encontraron mensajes no leídos (UNSEEN) en el buzón IMAP.', 'mailpoet-bouncer' ),
						details: [ __( 'El buzón está al día; no hay rebotes pendientes en este momento.', 'mailpoet-bouncer' ) ],
						stats: $emptyStats,
						startedAt: $startedAtIso,
						durationMs: $durationMs
					);
				}

				$total = count( $messageIds );
				$log( sprintf( 'Se encontraron %d mensaje(s) pendientes.', $total ) );

				$parser               = new Parser();
				$updater              = new SubscriberUpdater();
				$stats                = self::emptyStats();
				$markedForDeleteCount = 0;

				foreach ( $messageIds as $index => $msgId ) {
					$stats['processed']++;
					$num = $index + 1;

					try {
						$msgData = $client->fetchMessage( $msgId );

						if ( ! is_array( $msgData ) || empty( $msgData['headers'] ) ) {
							$stats['errors']++;
							$details[] = sprintf( '[#%d] No se pudo leer el mensaje #%s.', $num, (string) $msgId );
							continue;
						}

						$result = $parser->parse(
							(string) $msgData['headers'],
							(string) ( $msgData['body'] ?? '' )
						);

						$actionTaken = 'ignored';

						switch ( $result->type ) {
							case Result::TYPE_HARD:
								$stats['hard']++;
								break;
							case Result::TYPE_SOFT:
								$stats['soft']++;
								break;
							case Result::TYPE_AUTOREPLY:
								$stats['autoresponses']++;
								break;
							default:
								$stats['ignored']++;
								break;
						}

						if ( $result->shouldUpdateSubscriber() ) {
							$mpResult    = $updater->handleBounce( $result, $dryRun );
							$actionTaken = (string) ( $mpResult['action'] ?? 'unknown' );

							if ( ! empty( $mpResult['updated'] ) ) {
								$stats['updated_mp']++;
							}
						}

						Logger::log(
							email: $result->email ?: 'desconocido@rebote.local',
							bounceType: $result->type,
							actionTaken: $actionTaken,
							dsnCode: $result->dsnCode,
							diagnosticMessage: $result->reason,
							subject: $result->subject,
							messageId: $result->messageId
						);

						if ( $delete && $result->isHardBounce ) {
							$client->markForDeletion( $msgId );
							$markedForDeleteCount++;
						} else {
							// Marcar como leído (\Seen) para que no sea reprocesado en siguientes lotes ni mantenga la bandeja UNSEEN
							try {
								$client->markAsSeen( $msgId );
							} catch ( \Throwable ) {
								// No bloquear si el servidor IMAP tiene buzón de solo lectura
							}
						}

						$log( sprintf( '[%d/%d] %s -> %s (%s)', $num, $total, $result->email ?: 'Sin email', $result->type, $actionTaken ) );
					} catch ( \Throwable $msgEx ) {
						$stats['errors']++;
						$safeMsgEx = Security::sanitizeErrorMessage( $msgEx->getMessage(), (string) ( $options['password'] ?? '' ) );
						$details[] = sprintf( '[#%d] Error en mensaje #%s: %s', $num, (string) $msgId, $safeMsgEx );
					}
				}

				if ( $delete && $markedForDeleteCount > 0 ) {
					$client->expunge();
					$stats['deleted_imap'] = $markedForDeleteCount;
				}

				Logger::purgeOld( 60 );

				$duration = microtime( true ) - $startedAtUnix;
				$durationMs = (int) round( $duration * 1000 );
				$stats['duration_ms'] = $durationMs;

				StatsRepository::recordRun( $stats['processed'], $duration, $stats );

				$summaryMsg = sprintf(
					/* translators: 1: total processed, 2: hard bounces, 3: soft bounces, 4: autoresponders */
					__( 'Proceso completado correctamente. %1$d mensaje(s) revisado(s): %2$d hard bounce(s), %3$d soft bounce(s), %4$d autorespuesta(s).', 'mailpoet-bouncer' ),
					$stats['processed'],
					$stats['hard'],
					$stats['soft'],
					$stats['autoresponses']
				);

				$status = $stats['errors'] > 0 && $stats['errors'] === $stats['processed'] ? 'error' : 'success';
				$hasMore = $total >= $batchSize;
				$lastProcessedId = end( $messageIds );
				$nextCursor = false !== $lastProcessedId ? (string) $lastProcessedId : null;

				return self::buildResponse(
					success: 'error' !== $status,
					status: $status,
					message: $summaryMsg,
					details: $details,
					stats: $stats,
					startedAt: $startedAtIso,
					durationMs: $durationMs,
					hasMore: $hasMore,
					nextCursor: $nextCursor
				);
			} finally {
				$client->disconnect();
			}
		} catch ( \Throwable $e ) {
			$duration = microtime( true ) - $startedAtUnix;
			$durationMs = (int) round( $duration * 1000 );

			$activePassword = (string) ( Settings::get( 'password' ) ?? '' );
			$safeErrorMsg   = Security::sanitizeErrorMessage( $e->getMessage(), $activePassword );

			StatsRepository::recordRun( 0, $duration, [ 'error' => $safeErrorMsg ] );

			Logger::log(
				email: 'sistema@servidor.local',
				bounceType: 'imap_error',
				actionTaken: 'connection_failed',
				dsnCode: null,
				diagnosticMessage: 'Error IMAP: ' . $safeErrorMsg
			);

			$humanMessage = self::translateImapError( $safeErrorMsg );

			return self::buildResponse(
				success: false,
				status: 'error',
				message: $humanMessage,
				details: [ $safeErrorMsg ],
				stats: self::emptyStats( $durationMs ),
				startedAt: $startedAtIso,
				durationMs: $durationMs
			);
		} finally {
			self::releaseLock( $token );
		}
	}

	/**
	 * Traduce excepciones y errores técnicos IMAP a mensajes legibles para administradores.
	 */
	public static function translateImapError( string $rawError ): string {
		$lower = strtolower( $rawError );

		if ( str_contains( $lower, 'authentication failed' ) || str_contains( $lower, 'login failed' ) || str_contains( $lower, 'invalid credentials' ) ) {
			return __( 'La autenticación IMAP fue rechazada. Verifique el usuario y la contraseña configurados en los Ajustes del plugin.', 'mailpoet-bouncer' );
		}

		if ( str_contains( $lower, 'timed out' ) || str_contains( $lower, 'timeout' ) ) {
			return __( 'El buzón IMAP no respondió dentro del tiempo esperado (timeout). Verifique la conectividad y latencia de red.', 'mailpoet-bouncer' );
		}

		if ( str_contains( $lower, 'certificate' ) || str_contains( $lower, 'ssl' ) || str_contains( $lower, 'tls' ) || str_contains( $lower, 'handshake' ) ) {
			return __( 'Error de negociación SSL/TLS con el servidor IMAP. Verifique el certificado del servidor o el puerto de conexión (993 SSL / 143 TLS).', 'mailpoet-bouncer' );
		}

		if ( str_contains( $lower, 'could not connect' ) || str_contains( $lower, 'connection refused' ) || str_contains( $lower, 'failed to connect' ) ) {
			return __( 'No se pudo conectar al servidor IMAP. Verifique que el host y el puerto sean correctos y que el firewall permita la conexión.', 'mailpoet-bouncer' );
		}

		return __( 'Error durante el procesamiento: ', 'mailpoet-bouncer' ) . Security::sanitizeErrorMessage( $rawError );
	}

	/**
	 * Retorna una estructura vacía normalizada para estadísticas.
	 *
	 * @return array{
	 *   processed: int,
	 *   hard: int,
	 *   soft: int,
	 *   autoresponses: int,
	 *   ignored: int,
	 *   errors: int,
	 *   updated_mp: int,
	 *   deleted_imap: int,
	 *   duration_ms: int
	 * }
	 */
	public static function emptyStats( int $durationMs = 0 ): array {
		return [
			'processed'     => 0,
			'hard'          => 0,
			'soft'          => 0,
			'autoresponses' => 0,
			'ignored'       => 0,
			'errors'        => 0,
			'updated_mp'    => 0,
			'deleted_imap'  => 0,
			'duration_ms'   => $durationMs,
		];
	}

	/**
	 * Construye una respuesta normalizada.
	 *
	 * @param array<string, int>   $stats
	 * @param array<int, string>   $details
	 *
	 * @return array{
	 *   success: bool,
	 *   status: string,
	 *   message: string,
	 *   details: array<int, string>,
	 *   summary: array<string, int>,
	 *   last_run: array{started_at: string, finished_at: string},
	 *   has_more: bool,
	 *   next_cursor: string|null
	 * }
	 */
	private static function buildResponse(
		bool $success,
		string $status,
		string $message,
		array $details,
		array $stats,
		string $startedAt,
		int $durationMs,
		bool $hasMore = false,
		?string $nextCursor = null
	): array {
		return [
			'success'     => $success,
			'status'      => $status,
			'message'     => $message,
			'details'     => $details,
			'summary'     => $stats,
			'has_more'    => $hasMore,
			'next_cursor' => $nextCursor,
			'last_run'    => [
				'started_at'  => $startedAt,
				'finished_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			],
		];
	}

	/**
	 * Adquiere el lock de ejecución.
	 */
	public static function acquireLock( bool $force = false ): ?string {
		$current = get_transient( self::LOCK_KEY );

		if ( ! $force && is_array( $current ) && ! empty( $current['token'] ) ) {
			return null;
		}

		$token = wp_generate_password( 32, false, false );

		set_transient(
			self::LOCK_KEY,
			[
				'token' => $token,
				'time'  => time(),
			],
			self::LOCK_TTL
		);

		return $token;
	}

	/**
	 * Libera el lock de ejecución si pertenece a esta llamada.
	 */
	public static function releaseLock( ?string $token ): void {
		if ( null === $token || '' === $token ) {
			return;
		}

		$current = get_transient( self::LOCK_KEY );

		if ( is_array( $current ) && ( $current['token'] ?? '' ) === $token ) {
			delete_transient( self::LOCK_KEY );
		}
	}
}
