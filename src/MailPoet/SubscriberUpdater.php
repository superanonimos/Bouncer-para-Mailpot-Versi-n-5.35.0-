<?php
declare(strict_types=1);

namespace Publikate\MailPoetBouncer\MailPoet;

use Publikate\MailPoetBouncer\Bounce\Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestor de actualización de estado de suscriptores en MailPoet 5.31+.
 *
 * Implementa una estrategia de compatibilidad en 3 capas:
 * 1. Repositorio Doctrine de MailPoet 5.x vía contenedor DI.
 * 2. Modelos ORM heredados de MailPoet (\MailPoet\Models\Subscriber).
 * 3. Actualización directa, segura e idempotente en tabla $wpdb->prefix . 'mailpoet_subscribers'.
 */
final class SubscriberUpdater {

	public const STATUS_BOUNCED = 'bounced';

	/**
	 * Procesa la actualización del suscriptor si el resultado califica como hard bounce.
	 *
	 * @return array{
	 *   updated: bool,
	 *   action: string,
	 *   message: string,
	 *   status: ?string
	 * }
	 */
	public function handleBounce( Result $result, bool $dryRun = false ): array {
		if ( ! $result->shouldUpdateSubscriber() || null === $result->email ) {
			return [
				'updated' => false,
				'action'  => 'ignored',
				'message' => 'No califica para actualización (no es un Hard Bounce confirmado o falta email).',
				'status'  => null,
			];
		}

		$email = sanitize_email( $result->email );

		if ( ! is_email( $email ) ) {
			return [
				'updated' => false,
				'action'  => 'invalid_email',
				'message' => sprintf( 'Dirección de correo no válida: %s', $result->email ),
				'status'  => null,
			];
		}

		if ( $dryRun ) {
			$currentStatus = $this->detectCurrentStatus( $email );

			return [
				'updated' => false,
				'action'  => 'simulated',
				'message' => sprintf(
					'SIMULACIÓN: Se habría marcado como bounced a %s (estado actual: %s).',
					$email,
					$currentStatus ?? 'no encontrado'
				),
				'status'  => $currentStatus,
			];
		}

		// Capa 1: MailPoet 5.x vía Doctrine Repository
		try {
			$doctrineResult = $this->updateViaDoctrine( $email );
			if ( null !== $doctrineResult ) {
				return $doctrineResult;
			}
		} catch ( \Throwable $e ) {
			// Continuar a la siguiente capa defensiva
		}

		// Capa 2: Modelos ORM heredados de MailPoet
		try {
			$modelResult = $this->updateViaModel( $email );
			if ( null !== $modelResult ) {
				return $modelResult;
			}
		} catch ( \Throwable $e ) {
			// Continuar a la siguiente capa defensiva
		}

		// Capa 3: Actualización directa y segura en la base de datos
		return $this->updateViaSafeDb( $email );
	}

	/**
	 * Actualiza suscriptor usando la arquitectura Doctrine de MailPoet 5.x.
	 *
	 * @return ?array{updated: bool, action: string, message: string, status: ?string}
	 */
	private function updateViaDoctrine( string $email ): ?array {
		if ( ! class_exists( '\MailPoet\DI\ContainerWrapper' ) || ! class_exists( '\MailPoet\Subscribers\SubscribersRepository' ) ) {
			return null;
		}

		$container = \MailPoet\DI\ContainerWrapper::getInstance();
		if ( ! $container->has( \MailPoet\Subscribers\SubscribersRepository::class ) ) {
			return null;
		}

		/** @var \MailPoet\Subscribers\SubscribersRepository $repository */
		$repository = $container->get( \MailPoet\Subscribers\SubscribersRepository::class );
		$subscriber = $repository->findOneBy( [ 'email' => $email ] );

		if ( ! $subscriber ) {
			return [
				'updated' => false,
				'action'  => 'not_found',
				'message' => sprintf( 'El correo %s no está registrado en las listas de MailPoet.', $email ),
				'status'  => null,
			];
		}

		$currentStatus = method_exists( $subscriber, 'getStatus' ) ? (string) $subscriber->getStatus() : null;

		if ( self::STATUS_BOUNCED === $currentStatus ) {
			return [
				'updated' => false,
				'action'  => 'already_bounced',
				'message' => sprintf( 'El suscriptor %s ya se encontraba marcado como bounced.', $email ),
				'status'  => self::STATUS_BOUNCED,
			];
		}

		if ( method_exists( $subscriber, 'setStatus' ) ) {
			$subscriber->setStatus( self::STATUS_BOUNCED );
			$repository->persist( $subscriber );
			$repository->flush();

			return [
				'updated' => true,
				'action'  => 'updated_doctrine',
				'message' => sprintf( 'Suscriptor %s marcado como bounced mediante MailPoet Doctrine.', $email ),
				'status'  => self::STATUS_BOUNCED,
			];
		}

		return null;
	}

	/**
	 * Actualiza suscriptor usando el modelo histórico de MailPoet.
	 *
	 * @return ?array{updated: bool, action: string, message: string, status: ?string}
	 */
	private function updateViaModel( string $email ): ?array {
		if ( ! class_exists( '\MailPoet\Models\Subscriber' ) ) {
			return null;
		}

		/** @var mixed $subscriber */
		$subscriber = \MailPoet\Models\Subscriber::where( 'email', $email )->findOne();

		if ( ! $subscriber ) {
			return [
				'updated' => false,
				'action'  => 'not_found',
				'message' => sprintf( 'El correo %s no existe en MailPoet.', $email ),
				'status'  => null,
			];
		}

		$currentStatus = isset( $subscriber->status ) ? (string) $subscriber->status : null;

		if ( self::STATUS_BOUNCED === $currentStatus ) {
			return [
				'updated' => false,
				'action'  => 'already_bounced',
				'message' => sprintf( 'El suscriptor %s ya tenía el estado bounced.', $email ),
				'status'  => self::STATUS_BOUNCED,
			];
		}

		$subscriber->status = self::STATUS_BOUNCED;
		$subscriber->save();

		return [
			'updated' => true,
			'action'  => 'updated_model',
			'message' => sprintf( 'Suscriptor %s actualizado a bounced mediante MailPoet Models.', $email ),
			'status'  => self::STATUS_BOUNCED,
		];
	}

	/**
	 * Actualización directa, segura e idempotente en base de datos.
	 *
	 * @return array{updated: bool, action: string, message: string, status: ?string}
	 */
	private function updateViaSafeDb( string $email ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'mailpoet_subscribers';

		// Verificar existencia de la tabla
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$tableExists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		if ( $tableExists !== $table ) {
			return [
				'updated' => false,
				'action'  => 'table_missing',
				'message' => sprintf( 'La tabla %s no existe en la base de datos.', $table ),
				'status'  => null,
			];
		}

		// Consultar estado actual con consulta preparada
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status FROM {$table} WHERE email = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$email
			)
		);

		if ( ! $row ) {
			return [
				'updated' => false,
				'action'  => 'not_found',
				'message' => sprintf( 'El correo %s no se encontró en la tabla de MailPoet.', $email ),
				'status'  => null,
			];
		}

		if ( self::STATUS_BOUNCED === $row->status ) {
			return [
				'updated' => false,
				'action'  => 'already_bounced',
				'message' => sprintf( 'El suscriptor %s ya tenía estado bounced en BD.', $email ),
				'status'  => self::STATUS_BOUNCED,
			];
		}

		// Ejecutar actualización idempotente
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$affected = $wpdb->update(
			$table,
			[
				'status'     => self::STATUS_BOUNCED,
				'updated_at' => current_time( 'mysql' ),
			],
			[ 'id' => (int) $row->id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		if ( false === $affected || 0 === $affected ) {
			return [
				'updated' => false,
				'action'  => 'db_error',
				'message' => sprintf( 'No se pudo actualizar el estado de %s en la base de datos.', $email ),
				'status'  => $row->status,
			];
		}

		return [
			'updated' => true,
			'action'  => 'updated_db',
			'message' => sprintf( 'Suscriptor %s actualizado a bounced directamente en BD.', $email ),
			'status'  => self::STATUS_BOUNCED,
		];
	}

	/**
	 * Detecta el estado actual de un suscriptor sin modificarlo.
	 */
	private function detectCurrentStatus( string $email ): ?string {
		global $wpdb;

		$table = $wpdb->prefix . 'mailpoet_subscribers';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM {$table} WHERE email = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$email
			)
		);

		return is_string( $status ) ? $status : null;
	}
}
