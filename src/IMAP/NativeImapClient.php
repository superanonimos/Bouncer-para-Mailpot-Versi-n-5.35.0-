<?php
declare(strict_types=1);

namespace Publikate\MailPoetBouncer\IMAP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cliente IMAP basado en ext-imap.
 *
 * Regla de diseño:
 * - hacia afuera, este cliente trabaja con UIDs IMAP;
 * - no expungea automáticamente al cerrar;
 * - solo borra definitivamente cuando se invoca expunge().
 */
final class NativeImapClient implements ClientInterface {

	/**
	 * @var \IMAP\Connection|resource|null
	 */
	private $stream = null;

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
		if ( null !== $this->stream ) {
			return;
		}

		if ( ! extension_loaded( 'imap' ) ) {
			throw new \RuntimeException(
				__( 'La extensión PHP IMAP no está disponible en este servidor.', 'mailpoet-bouncer' )
			);
		}

		$mailbox = $this->buildMailboxString();

		imap_timeout( IMAP_OPENTIMEOUT, 15 );
		imap_timeout( IMAP_READTIMEOUT, 20 );
		imap_timeout( IMAP_WRITETIMEOUT, 20 );
		imap_timeout( IMAP_CLOSETIMEOUT, 10 );

		imap_errors();
		imap_alerts();

		$stream = @imap_open(
			$mailbox,
			$this->username,
			$this->password,
			0,
			1,
			[ 'DISABLE_AUTHENTICATOR' => 'GSSAPI' ]
		);

		if ( false === $stream ) {
			$errorMsg = $this->collectImapErrors();

			throw new \RuntimeException(
				sprintf(
					/* translators: %s: Detalle del error IMAP */
					__( 'Error al conectar con el servidor IMAP: %s', 'mailpoet-bouncer' ),
					$errorMsg ?: __( 'Fallo de autenticación o tiempo de espera agotado.', 'mailpoet-bouncer' )
				)
			);
		}

		$this->stream = $stream;
	}

	public function testConnection(): array {
		try {
			$this->connect();

			$check = @imap_check( $this->stream );
			$count = ( $check && isset( $check->Nmsgs ) ) ? (int) $check->Nmsgs : 0;

			$this->disconnect();

			return [
				'success' => true,
				'message' => sprintf(
					/* translators: %d: número de mensajes */
					__( 'Conexión IMAP establecida con éxito. Mensajes en buzón: %d.', 'mailpoet-bouncer' ),
					$count
				),
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

		$uids = @imap_search( $this->stream, 'UNSEEN', SE_UID );

		if ( false === $uids || empty( $uids ) ) {
			return [];
		}

		$uids = array_map( 'intval', $uids );
		$uids = array_filter(
			$uids,
			static fn( int $uid ): bool => $uid > 0
		);

		rsort( $uids, SORT_NUMERIC );

		return array_slice( array_values( $uids ), 0, $limit );
	}

	public function fetchMessage( int $messageId ): ?array {
		$this->connect();

		$uid = max( 1, $messageId );

		$headers = @imap_fetchheader( $this->stream, $uid, FT_UID );
		if ( false === $headers ) {
			throw new \RuntimeException(
				sprintf(
					__( 'No se pudieron leer las cabeceras del mensaje IMAP UID %d.', 'mailpoet-bouncer' ),
					$uid
				)
			);
		}

		$body = @imap_body( $this->stream, $uid, FT_UID | FT_PEEK );
		if ( false === $body ) {
			$body = '';
		}

		return [
			'headers' => (string) $headers,
			'body'    => (string) $body,
			'uid'     => $uid,
		];
	}

	public function markForDeletion( int $messageId ): bool {
		$this->connect();

		$uid = max( 1, $messageId );
		$result = @imap_delete( $this->stream, (string) $uid, FT_UID );

		if ( false === $result ) {
			$error = $this->collectImapErrors();

			throw new \RuntimeException(
				sprintf(
					__( 'No se pudo marcar para eliminación el mensaje IMAP UID %1$d. %2$s', 'mailpoet-bouncer' ),
					$uid,
					$error ?: __( 'El servidor devolvió un error.', 'mailpoet-bouncer' )
				)
			);
		}

		return true;
	}

	public function markAsSeen( int $messageId ): bool {
		$this->connect();

		$uid = max( 1, $messageId );
		$result = @imap_setflag_full( $this->stream, (string) $uid, '\\Seen', ST_UID );

		if ( false === $result ) {
			$error = $this->collectImapErrors();

			throw new \RuntimeException(
				sprintf(
					__( 'No se pudo marcar como leído el mensaje IMAP UID %1$d. %2$s', 'mailpoet-bouncer' ),
					$uid,
					$error ?: __( 'El servidor devolvió un error.', 'mailpoet-bouncer' )
				)
			);
		}

		return true;
	}

	public function expunge(): bool {
		if ( null === $this->stream ) {
			return false;
		}

		$result = @imap_expunge( $this->stream );

		if ( false === $result ) {
			$error = $this->collectImapErrors();

			throw new \RuntimeException(
				sprintf(
					__( 'No se pudo ejecutar EXPUNGE en el buzón IMAP. %s', 'mailpoet-bouncer' ),
					$error ?: __( 'El servidor devolvió un error.', 'mailpoet-bouncer' )
				)
			);
		}

		return true;
	}

	public function disconnect(): void {
		if ( null === $this->stream ) {
			@imap_errors();
			@imap_alerts();
			return;
		}

		try {
			@imap_close( $this->stream, 0 );
		} finally {
			$this->stream = null;
			@imap_errors();
			@imap_alerts();
		}
	}

	public function __destruct() {
		$this->disconnect();
	}

	private function buildMailboxString(): string {
		$flags = [ 'imap' ];

		if ( 'ssl' === $this->encryption ) {
			$flags[] = 'ssl';
		} elseif ( 'tls' === $this->encryption ) {
			$flags[] = 'tls';
		} elseif ( 'none' === $this->encryption ) {
			$flags[] = 'notls';
		}

		$flags[] = $this->validateCert ? 'validate-cert' : 'novalidate-cert';

		return sprintf(
			'{%s:%d/%s}%s',
			$this->host,
			$this->port,
			implode( '/', $flags ),
			$this->folder
		);
	}

	private function collectImapErrors(): string {
		$errors = @imap_errors();
		$alerts = @imap_alerts();

		$parts = [];

		if ( is_array( $errors ) && ! empty( $errors ) ) {
			$parts[] = implode( '; ', array_map( 'trim', $errors ) );
		}

		if ( is_array( $alerts ) && ! empty( $alerts ) ) {
			$parts[] = implode( '; ', array_map( 'trim', $alerts ) );
		}

		$lastError = @imap_last_error();
		if ( is_string( $lastError ) && '' !== trim( $lastError ) ) {
			$parts[] = trim( $lastError );
		}

		$parts = array_filter( array_unique( $parts ) );

		return implode( ' | ', $parts );
	}
}