<?php
declare(strict_types=1);

namespace Publikate\MailPoetBouncer\IMAP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fábrica para la creación de clientes IMAP desacoplados y resilientes.
 */
final class ClientFactory {

	/**
	 * Crea una instancia de ClientInterface según la disponibilidad del entorno.
	 *
	 * @param array{
	 *   host?: string,
	 *   port?: int|string,
	 *   encryption?: string,
	 *   username?: string,
	 *   password?: string,
	 *   folder?: string,
	 *   validate_cert?: bool|int|string
	 * } $config
	 */
	public static function create( array $config ): ClientInterface {
		$host         = self::normalizeHost( $config['host'] ?? '' );
		$port         = self::normalizePort( $config['port'] ?? null );
		$encryption   = self::normalizeEncryption( $config['encryption'] ?? 'ssl', $port );
		$username     = self::normalizeUsername( $config['username'] ?? '' );
		$password     = self::normalizePassword( $config['password'] ?? '' );
		$folder       = self::normalizeFolder( $config['folder'] ?? 'INBOX' );
		$validateCert = self::normalizeBool( $config['validate_cert'] ?? true );

		if ( '' === $host || '' === $username || '' === $password ) {
			throw new \InvalidArgumentException(
				__( 'La configuración IMAP está incompleta: faltan host, usuario o contraseña.', 'mailpoet-bouncer' )
			);
		}

		if ( extension_loaded( 'imap' ) ) {
			return new NativeImapClient(
				host: $host,
				port: $port,
				encryption: $encryption,
				username: $username,
				password: $password,
				folder: $folder,
				validateCert: $validateCert
			);
		}

		if ( ! function_exists( 'stream_socket_client' ) ) {
			throw new \RuntimeException(
				__( 'El servidor no dispone de ext-imap ni de sockets seguros para establecer conexiones IMAP.', 'mailpoet-bouncer' )
			);
		}

		return new SocketImapClient(
			host: $host,
			port: $port,
			encryption: $encryption,
			username: $username,
			password: $password,
			folder: $folder,
			validateCert: $validateCert
		);
	}

	private static function normalizeHost( mixed $host ): string {
		$host = is_scalar( $host ) ? trim( (string) $host ) : '';

		if ( '' === $host ) {
			return '';
		}

		$host = wp_strip_all_tags( $host );
		$host = preg_replace( '/[^a-z0-9\.\-\:]/i', '', $host );

		return is_string( $host ) ? strtolower( $host ) : '';
	}

	private static function normalizePort( mixed $port ): int {
		$port = is_numeric( $port ) ? (int) $port : 993;

		if ( $port < 1 || $port > 65535 ) {
			return 993;
		}

		return $port;
	}

	private static function normalizeEncryption( mixed $encryption, int $port ): string {
		$value = is_scalar( $encryption ) ? strtolower( trim( (string) $encryption ) ) : 'ssl';

		if ( in_array( $value, [ 'ssl', 'tls', 'starttls', 'none' ], true ) ) {
			if ( 'starttls' === $value ) {
				return 'tls';
			}

			return $value;
		}

		if ( 143 === $port ) {
			return 'tls';
		}

		if ( 993 === $port ) {
			return 'ssl';
		}

		return 'ssl';
	}

	private static function normalizeUsername( mixed $username ): string {
		$username = is_scalar( $username ) ? trim( (string) $username ) : '';

		if ( '' === $username ) {
			return '';
		}

		return sanitize_text_field( $username );
	}

	private static function normalizePassword( mixed $password ): string {
		if ( ! is_scalar( $password ) ) {
			return '';
		}

		return trim( (string) $password );
	}

	private static function normalizeFolder( mixed $folder ): string {
		$folder = is_scalar( $folder ) ? trim( (string) $folder ) : 'INBOX';

		if ( '' === $folder ) {
			return 'INBOX';
		}

		$folder = wp_strip_all_tags( $folder );
		$folder = preg_replace( '/[^a-zA-Z0-9_\-\.\/\s]/', '', $folder );
		$folder = is_string( $folder ) ? trim( $folder ) : 'INBOX';

		return '' !== $folder ? $folder : 'INBOX';
	}

	private static function normalizeBool( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (bool) $value;
		}

		$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';

		return in_array( $value, [ '1', 'true', 'yes', 'on' ], true );
	}
}