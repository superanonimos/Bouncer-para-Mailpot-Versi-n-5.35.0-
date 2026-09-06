<?php
declare(strict_types=1);

namespace Publikate\MailPoetBouncer\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestor de persistencia y auditoría de eventos de rebote.
 */
final class Logger {

	public const TABLE_NAME  = 'pkmb_audit_log';
	public const RETENTION_DAYS_DEFAULT = 60;

	/**
	 * Crea o actualiza la tabla de auditoría mediante dbDelta().
	 */
	public static function createTable(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
id bigint unsigned NOT NULL AUTO_INCREMENT,
email varchar(191) NOT NULL,
bounce_type varchar(32) NOT NULL,
action_taken varchar(64) NOT NULL,
dsn_code varchar(32) NULL,
diagnostic_message varchar(255) NULL,
subject varchar(255) NULL,
message_id varchar(191) NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY email_idx (email),
KEY bounce_type_idx (bounce_type),
KEY created_at_idx (created_at)
) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Inserta un evento de rebote en el registro de auditoría.
	 */
	public static function log(
		string $email,
		string $bounceType,
		string $actionTaken,
		?string $dsnCode = null,
		?string $diagnosticMessage = null,
		?string $subject = null,
		?string $messageId = null
	): bool {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		if ( ! self::tableExists( $table_name ) ) {
			return false;
		}

		$email = sanitize_email( strtolower( trim( $email ) ) );
		if ( empty( $email ) ) {
			$email = 'desconocido@rebote.local';
		}

		$bounceType  = self::normalizeKey( $bounceType, 32 );
		$actionTaken = self::normalizeKey( $actionTaken, 64 );
		$dsnCode     = self::normalizeNullableText( $dsnCode, 32, true );
		$subject     = self::normalizeNullableText( $subject, 255 );
		$messageId   = self::normalizeNullableText( $messageId, 191 );
		$diagnosticMessage = self::normalizeNullableText( $diagnosticMessage, 255 );

		$data = [
			'email'              => $email,
			'bounce_type'        => $bounceType,
			'action_taken'       => $actionTaken,
			'dsn_code'           => $dsnCode,
			'diagnostic_message' => $diagnosticMessage,
			'subject'            => $subject,
			'message_id'         => $messageId,
			'created_at'         => current_time( 'mysql', true ),
		];

		$formats = [
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
		];

		$result = $wpdb->insert( $table_name, $data, $formats );

		return false !== $result;
	}

	/**
	 * Recupera los últimos eventos de auditoría con paginación segura.
	 *
	 * @return array<int, object>
	 */
	public static function getRecent( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		if ( ! self::tableExists( $table_name ) ) {
			return [];
		}

		$limit  = max( 1, min( 200, $limit ) );
		$offset = max( 0, $offset );

		$query = $wpdb->prepare(
			"SELECT id, email, bounce_type, action_taken, dsn_code, diagnostic_message, subject, message_id, created_at
			FROM {$table_name}
			ORDER BY id DESC
			LIMIT %d OFFSET %d",
			$limit,
			$offset
		);

		$results = $wpdb->get_results( $query );

		return is_array( $results ) ? $results : [];
	}

	/**
	 * Cuenta el número total de registros de auditoría.
	 */
	public static function countTotal(): int {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		if ( ! self::tableExists( $table_name ) ) {
			return 0;
		}

		$count = $wpdb->get_var( "SELECT COUNT(id) FROM {$table_name}" );

		return (int) $count;
	}

	/**
	 * Purga registros más antiguos que N días.
	 */
	public static function purgeOld( int $days = self::RETENTION_DAYS_DEFAULT ): int {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		if ( ! self::tableExists( $table_name ) ) {
			return 0;
		}

		$days = max( 1, $days );

		$cutoff = gmdate(
			'Y-m-d H:i:s',
			time() - ( $days * DAY_IN_SECONDS )
		);

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE created_at < %s",
				$cutoff
			)
		);

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}

	/**
	 * Verifica existencia de tabla.
	 */
	private static function tableExists( string $table_name ): bool {
		global $wpdb;

		return $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		) === $table_name;
	}

	/**
	 * Normaliza claves cortas para tipos y acciones.
	 */
	private static function normalizeKey( string $value, int $maxLength ): string {
		$value = sanitize_key( $value );
		$value = substr( $value, 0, $maxLength );

		return '' !== $value ? $value : 'unknown';
	}

	/**
	 * Normaliza texto nullable con truncamiento seguro.
	 */
	private static function normalizeNullableText( ?string $value, int $maxLength, bool $preserveDots = false ): ?string {
		if ( null === $value ) {
			return null;
		}

		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		if ( $preserveDots ) {
			$value = preg_replace( '/[^\pL\pN\.\-\_\s]/u', '', $value );
			$value = is_string( $value ) ? $value : '';
		} else {
			$value = sanitize_text_field( $value );
		}

		$value = trim( preg_replace( '/\s+/', ' ', $value ) ?? '' );

		if ( '' === $value ) {
			return null;
		}

		return mb_substr( $value, 0, $maxLength );
	}
}