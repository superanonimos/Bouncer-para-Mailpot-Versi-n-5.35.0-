<?php
declare(strict_types=1);

namespace Publikate\MailPoetBouncer\IMAP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cliente IMAP mínimo por sockets para entornos sin ext-imap.
 *
 * Diseño:
 * - trabaja siempre con UIDs IMAP;
 * - soporta ssl implícito, tls via STARTTLS y none;
 * - implementa lectura de literales para FETCH BODY[];
 * - no hace EXPUNGE automático al desconectar.
 */
final class SocketImapClient implements ClientInterface {

	/**
	 * @var resource|null
	 */
	private $socket = null;

	private int $tagCounter = 1;

	public function __construct(
		private readonly string $host,
		private readonly int $port,
		private readonly string $encryption,
		private readonly string $username,
		private readonly string $password,
		private readonly string $folder = 'INBOX',
		private readonly bool $validateCert = true
	) {}

	public function connect(): void {
		if ( null !== $this->socket ) {
			return;
		}

		$this->socket = $this->openSocket();

		$banner = $this->readLine();
		if ( null === $banner || ! str_starts_with( strtoupper( $banner ), '* OK' ) ) {
			$this->disconnect();
			throw new \RuntimeException(
				__( 'El servidor IMAP no devolvió un banner válido.', 'mailpoet-bouncer' )
			);
		}

		if ( 'tls' === $this->encryption ) {
			$this->startTls();
		}

		$loginResponse = $this->sendCommand(
			sprintf(
				'LOGIN %s %s',
				$this->quote( $this->username ),
				$this->quote( $this->password )
			)
		);

		if ( ! $this->isOk( $loginResponse ) ) {
			$this->disconnect();
			throw new \RuntimeException(
				__( 'Fallo de autenticación IMAP por socket: credenciales inválidas o método de cifrado incorrecto.', 'mailpoet-bouncer' )
			);
		}

		$selectResponse = $this->sendCommand(
			sprintf( 'SELECT %s', $this->quote( $this->folder ) )
		);

		if ( ! $this->isOk( $selectResponse ) ) {
			$this->disconnect();
			throw new \RuntimeException(
				sprintf(
					__( 'No se pudo seleccionar la carpeta IMAP "%s".', 'mailpoet-bouncer' ),
					$this->folder
				)
			);
		}
	}

	public function testConnection(): array {
		try {
			$this->connect();
			$this->disconnect();

			return [
				'success' => true,
				'message' => __( 'Conexión IMAP y autenticación por socket exitosas.', 'mailpoet-bouncer' ),
			];
		} catch ( \Throwable $e ) {
			return [
				'success' => false,
				'message' => $e->getMessage(),
			];
		}
	}

	public function getUnreadMessageIds( int $limit = 50 ): array {
		$this->connect();

		$limit = max( 1, min( 500, $limit ) );

		$response = $this->sendCommand( 'UID SEARCH UNSEEN' );
		if ( ! $this->isOk( $response ) ) {
			throw new \RuntimeException(
				__( 'No se pudo ejecutar UID SEARCH UNSEEN en el buzón IMAP.', 'mailpoet-bouncer' )
			);
		}

		$uids = [];

		foreach ( $response['lines'] as $line ) {
			if ( preg_match( '/^\* SEARCH(.*)$/i', $line, $matches ) ) {
				$parts = preg_split( '/\s+/', trim( $matches[1] ) );
				if ( is_array( $parts ) ) {
					foreach ( $parts as $part ) {
						$uid = (int) $part;
						if ( $uid > 0 ) {
							$uids[] = $uid;
						}
					}
				}
			}
		}

		$uids = array_values( array_unique( $uids ) );
		rsort( $uids, SORT_NUMERIC );

		return array_slice( $uids, 0, $limit );
	}

	public function fetchMessage( int $messageId ): ?array {
		$this->connect();

		$uid = max( 1, $messageId );

		$response = $this->sendCommand(
			sprintf( 'UID FETCH %d (BODY.PEEK[])', $uid )
		);

		if ( ! $this->isOk( $response ) ) {
			throw new \RuntimeException(
				sprintf(
					__( 'No se pudo recuperar el mensaje IMAP UID %d.', 'mailpoet-bouncer' ),
					$uid
				)
			);
		}

		$rawMessage = $response['literal'] ?? '';

		if ( '' === $rawMessage ) {
			return null;
		}

		$parts = preg_split( "/\r\n\r\n|\n\n|\r\r/", $rawMessage, 2 );
		$headers = is_array( $parts ) ? (string) ( $parts[0] ?? '' ) : '';
		$body    = is_array( $parts ) ? (string) ( $parts[1] ?? '' ) : '';

		return [
			'headers' => $headers,
			'body'    => $body,
			'uid'     => $uid,
		];
	}

	public function markForDeletion( int $messageId ): bool {
		$this->connect();

		$uid = max( 1, $messageId );

		$response = $this->sendCommand(
			sprintf( 'UID STORE %d +FLAGS (\\Deleted)', $uid )
		);

		if ( ! $this->isOk( $response ) ) {
			throw new \RuntimeException(
				sprintf(
					__( 'No se pudo marcar para eliminación el mensaje IMAP UID %d.', 'mailpoet-bouncer' ),
					$uid
				)
			);
		}

		return true;
	}

	public function markAsSeen( int $messageId ): bool {
		$this->connect();

		$uid = max( 1, $messageId );

		$response = $this->sendCommand(
			sprintf( 'UID STORE %d +FLAGS (\\Seen)', $uid )
		);

		if ( ! $this->isOk( $response ) ) {
			throw new \RuntimeException(
				sprintf(
					__( 'No se pudo marcar como leído el mensaje IMAP UID %d.', 'mailpoet-bouncer' ),
					$uid
				)
			);
		}

		return true;
	}

	public function expunge(): bool {
		if ( null === $this->socket ) {
			return false;
		}

		$response = $this->sendCommand( 'EXPUNGE' );

		if ( ! $this->isOk( $response ) ) {
			throw new \RuntimeException(
				__( 'No se pudo ejecutar EXPUNGE en el buzón IMAP.', 'mailpoet-bouncer' )
			);
		}

		return true;
	}

	public function disconnect(): void {
		if ( null === $this->socket ) {
			return;
		}

		try {
			$this->sendCommand( 'LOGOUT' );
		} catch ( \Throwable ) {
		}

		@fclose( $this->socket );
		$this->socket = null;
	}

	public function __destruct() {
		$this->disconnect();
	}

	private function openSocket() {
		$scheme = 'ssl' === $this->encryption ? 'ssl://' : 'tcp://';
		$target = $scheme . $this->host . ':' . $this->port;

		$context = stream_context_create(
			[
				'ssl' => [
					'verify_peer'       => $this->validateCert,
					'verify_peer_name'  => $this->validateCert,
					'allow_self_signed' => ! $this->validateCert,
					'SNI_enabled'       => true,
					'peer_name'         => $this->host,
				],
			]
		);

		$errno  = 0;
		$errstr = '';

		$socket = @stream_socket_client(
			$target,
			$errno,
			$errstr,
			15,
			STREAM_CLIENT_CONNECT,
			$context
		);

		if ( ! is_resource( $socket ) ) {
			throw new \RuntimeException(
				sprintf(
					__( 'No se pudo abrir el socket hacia %1$s: %2$s', 'mailpoet-bouncer' ),
					$target,
					$errstr ?: (string) $errno
				)
			);
		}

		stream_set_timeout( $socket, 20 );

		return $socket;
	}

	private function startTls(): void {
		$response = $this->sendCommand( 'STARTTLS' );

		if ( ! $this->isOk( $response ) ) {
			$this->disconnect();
			throw new \RuntimeException(
				__( 'El servidor IMAP no aceptó STARTTLS.', 'mailpoet-bouncer' )
			);
		}

		$cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;

		$result = @stream_socket_enable_crypto(
			$this->socket,
			true,
			$cryptoMethod
		);

		if ( true !== $result ) {
			$this->disconnect();
			throw new \RuntimeException(
				__( 'No se pudo establecer la capa TLS sobre el socket IMAP.', 'mailpoet-bouncer' )
			);
		}
	}

	/**
	 * @return array{
	 *   status:string,
	 *   lines:string[],
	 *   literal:?string
	 * }
	 */
	private function sendCommand( string $command ): array {
		if ( ! is_resource( $this->socket ) ) {
			throw new \RuntimeException(
				__( 'Conexión IMAP cerrada prematuramente.', 'mailpoet-bouncer' )
			);
		}

		$tag = sprintf( 'A%04d', $this->tagCounter++ );
		$payload = $tag . ' ' . $command . "\r\n";

		$written = @fwrite( $this->socket, $payload );
		if ( false === $written || $written < strlen( $payload ) ) {
			throw new \RuntimeException(
				__( 'Error al transmitir comando hacia el socket IMAP.', 'mailpoet-bouncer' )
			);
		}

		$lines = [];
		$status = 'BAD';
		$literal = null;

		while ( ! feof( $this->socket ) ) {
			$line = $this->readLine();

			if ( null === $line ) {
				break;
			}

			if ( preg_match( '/\{(\d+)\}$/', $line, $matches ) ) {
				$size = (int) $matches[1];
				$literal = $this->readBytes( $size );
				$lines[] = $line;
				continue;
			}

			if ( str_starts_with( $line, $tag . ' ' ) ) {
				$status = strtoupper( trim( substr( $line, strlen( $tag ) + 1 ) ) );
				break;
			}

			$lines[] = $line;
		}

		return [
			'status'  => $status,
			'lines'   => $lines,
			'literal' => $literal,
		];
	}

	private function readLine(): ?string {
		if ( ! is_resource( $this->socket ) ) {
			return null;
		}

		$line = fgets( $this->socket, 8192 );

		if ( false === $line ) {
			$meta = stream_get_meta_data( $this->socket );
			if ( ! empty( $meta['timed_out'] ) ) {
				throw new \RuntimeException(
					__( 'Timeout al leer desde el socket IMAP.', 'mailpoet-bouncer' )
				);
			}

			return null;
		}

		return rtrim( $line, "\r\n" );
	}

	private function readBytes( int $length ): string {
		$data = '';

		while ( strlen( $data ) < $length && is_resource( $this->socket ) && ! feof( $this->socket ) ) {
			$chunk = fread( $this->socket, $length - strlen( $data ) );

			if ( false === $chunk ) {
				throw new \RuntimeException(
					__( 'Error al leer un literal IMAP desde el socket.', 'mailpoet-bouncer' )
				);
			}

			if ( '' === $chunk ) {
				$meta = stream_get_meta_data( $this->socket );
				if ( ! empty( $meta['timed_out'] ) ) {
					throw new \RuntimeException(
						__( 'Timeout al leer el contenido literal del mensaje IMAP.', 'mailpoet-bouncer' )
					);
				}
			}

			$data .= $chunk;
		}

		return $data;
	}

	private function isOk( array $response ): bool {
		return str_starts_with( strtoupper( $response['status'] ?? '' ), 'OK' );
	}

	private function quote( string $value ): string {
		return '"' . addcslashes( $value, "\\\"\r\n" ) . '"';
	}
}