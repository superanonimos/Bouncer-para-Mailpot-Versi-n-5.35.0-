<?php
declare(strict_types=1);

namespace Publikate\MailPoetBouncer\Bounce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analizador conservador de mensajes DSN/bounce (RFC 3464).
 *
 * Principios:
 * - prioriza señales RFC 3464 / delivery-status;
 * - evita usar campos ambiguos como "To" demasiado pronto;
 * - limita memoria y tamaño de análisis;
 * - delega la clasificación final al Classifier.
 */
final class Parser {

	private const MAX_ANALYSIS_BYTES = 262144; // 256 KB
	private const MAX_BODY_SNIPPET   = 32768;  // 32 KB
	private const MAX_HEADER_BYTES   = 65535;  // 64 KB

	public function __construct(
		private readonly Classifier $classifier = new Classifier()
	) {}

	public function parse( string $rawHeaders, string $rawBody ): Result {
		$rawHeaders = $this->sanitizeRawChunk( $rawHeaders, self::MAX_HEADER_BYTES );
		$rawBody    = $this->sanitizeRawChunk( $rawBody, self::MAX_ANALYSIS_BYTES );

		$headers   = $this->parseHeaders( $rawHeaders );
		$subject   = $this->normalizeHeaderValue( $this->decodeMimeHeader( $headers['subject'] ?? null ) );
		$messageId = $this->normalizeMessageId( $headers['message-id'] ?? null );

		$analysisText = $rawHeaders . "\r\n\r\n" . substr( $rawBody, 0, self::MAX_BODY_SNIPPET );
		$extracted    = $this->extractDsnFields( $analysisText );

		$email = $extracted['email'];

		if ( empty( $email ) ) {
			$email = $this->extractRecipientFromHeaders( $headers );
		}

		if ( empty( $email ) ) {
			$email = $this->extractRecipientFromEmbeddedOriginal( $rawBody );
		}

		if ( empty( $email ) ) {
			$email = $this->extractRecipientFromBody( $rawBody );
		}

		if ( ! empty( $email ) ) {
			$email = $this->normalizeEmail( $email );
		}

		$data = [
			'email'           => $email,
			'dsn_status'      => $this->normalizeStatus( $extracted['status'] ),
			'action'          => $this->normalizeAction( $extracted['action'] ),
			'diagnostic_code' => $this->normalizeDiagnostic( $extracted['diagnostic_code'] ),
			'headers'         => $headers,
			'subject'         => $subject,
			'message_id'      => $messageId,
			'body'            => substr( $rawBody, 0, self::MAX_BODY_SNIPPET ),
		];

		$result = $this->classifier->classify( $data );

		if ( null === $result->email && null === $data['dsn_status'] && null === $data['action'] ) {
			return Result::unprocessable(
				reason: 'No se encontraron campos DSN ni destinatario confiable en el mensaje.',
				subject: $subject,
				messageId: $messageId
			);
		}

		return $result;
	}

	/**
	 * Desglosa cabeceras de correo en un array asociativo normalizado a minúsculas.
	 *
	 * @return array<string, string>
	 */
	public function parseHeaders( string $rawHeaders ): array {
		$headers       = [];
		$lines         = preg_split( "/\r\n|\n|\r/", $rawHeaders );
		$currentHeader = '';

		if ( ! is_array( $lines ) ) {
			return [];
		}

		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}

			if ( preg_match( '/^\s+(.*)$/', $line, $matches ) && '' !== $currentHeader ) {
				$headers[ $currentHeader ] .= ' ' . trim( (string) $matches[1] );
				continue;
			}

			if ( preg_match( '/^([a-zA-Z0-9\-_]+):\s*(.*)$/', $line, $matches ) ) {
				$currentHeader = strtolower( trim( (string) $matches[1] ) );
				$value         = trim( (string) $matches[2] );

				if ( isset( $headers[ $currentHeader ] ) && '' !== $headers[ $currentHeader ] ) {
					$headers[ $currentHeader ] .= ', ' . $value;
				} else {
					$headers[ $currentHeader ] = $value;
				}
			}
		}

		return $headers;
	}

	/**
	 * @return array{
	 *   email: ?string,
	 *   status: ?string,
	 *   action: ?string,
	 *   diagnostic_code: ?string
	 * }
	 */
	private function extractDsnFields( string $text ): array {
		$result = [
			'email'           => null,
			'status'          => null,
			'action'          => null,
			'diagnostic_code' => null,
		];

		$emailPatterns = [
			'/(?:Final-Recipient|Original-Recipient):\s*(?:rfc822;)?\s*([^\s;<>]+@[^\s;<>]+)/i',
			'/X-Failed-Recipients:\s*([^\s,;<>]+@[^\s,;<>]+)/i',
			'/X-Original-To:\s*<?([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})>?/i',
			'/Delivered-To:\s*<?([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})>?/i',
		];

		foreach ( $emailPatterns as $pattern ) {
			if ( preg_match( $pattern, $text, $matches ) ) {
				$result['email'] = $this->cleanEmail( (string) $matches[1] );
				break;
			}
		}

		if ( preg_match( '/\bAction:\s*([a-zA-Z\-]+)/i', $text, $matches ) ) {
			$result['action'] = strtolower( trim( (string) $matches[1] ) );
		}

		if ( preg_match( '/\bStatus:\s*([245]\.\d{1,3}\.\d{1,3})\b/i', $text, $matches ) ) {
			$result['status'] = trim( (string) $matches[1] );
		}

		if ( preg_match( '/Diagnostic-Code:\s*([^;\r\n]+;\s*)?([^\r\n]+(?:\r?\n[ \t]+[^\r\n]+)*)/i', $text, $matches ) ) {
			$result['diagnostic_code'] = trim(
				(string) preg_replace(
					'/\s+/',
					' ',
					(string) ( $matches[2] ?? '' )
				)
			);
		}

		return $result;
	}

	/**
	 * @param array<string, string> $headers
	 */
	private function extractRecipientFromHeaders( array $headers ): ?string {
		$candidateHeaders = [
			'x-failed-recipients',
			'x-original-to',
			'delivered-to',
			'final-recipient',
			'original-recipient',
		];

		foreach ( $candidateHeaders as $headerName ) {
			if ( empty( $headers[ $headerName ] ) ) {
				continue;
			}

			$email = $this->extractFirstEmail( $headers[ $headerName ] );
			if ( null !== $email ) {
				return $email;
			}
		}

		return null;
	}

	private function extractRecipientFromEmbeddedOriginal( string $body ): ?string {
		$snippet = substr( $body, 0, self::MAX_BODY_SNIPPET );

		$patterns = [
			'/\nTo:\s*.*?([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/i',
			'/\nDelivered-To:\s*<?([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})>?/i',
			'/\nX-Original-To:\s*<?([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})>?/i',
		];

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $snippet, $matches ) ) {
				return $this->cleanEmail( (string) $matches[1] );
			}
		}

		return null;
	}

	private function extractRecipientFromBody( string $body ): ?string {
		$snippet = substr( $body, 0, self::MAX_BODY_SNIPPET );

		$patterns = [
			'/recipient(?:\s+address)?:\s*<?([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})>?/i',
			'/unable to deliver message to\s*<?([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})>?/i',
			'/delivery to the following recipient(?:s)? failed(?: permanently)?:\s*<?([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})>?/i',
		];

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $snippet, $matches ) ) {
				return $this->cleanEmail( (string) $matches[1] );
			}
		}

		return null;
	}

	private function extractFirstEmail( string $value ): ?string {
		if ( preg_match( '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $value, $matches ) ) {
			return $this->cleanEmail( (string) $matches[0] );
		}

		return null;
	}

	private function decodeMimeHeader( ?string $value ): ?string {
		if ( null === $value || '' === trim( $value ) ) {
			return null;
		}

		$value = trim( $value );

		if ( function_exists( 'iconv_mime_decode' ) ) {
			$decoded = @iconv_mime_decode( $value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8' );
			if ( is_string( $decoded ) && '' !== trim( $decoded ) ) {
				return $decoded;
			}
		}

		if ( function_exists( 'mb_decode_mimeheader' ) ) {
			$decoded = @mb_decode_mimeheader( $value );
			if ( is_string( $decoded ) && '' !== trim( $decoded ) ) {
				return $decoded;
			}
		}

		return $value;
	}

	private function cleanEmail( string $email ): string {
		return trim( $email, " \t\n\r\0\x0B<>\"'[](){}" );
	}

	private function normalizeEmail( string $email ): ?string {
		$clean = sanitize_email( strtolower( trim( $email ) ) );

		return is_email( $clean ) ? $clean : null;
	}

	private function normalizeAction( ?string $action ): ?string {
		if ( null === $action || '' === trim( $action ) ) {
			return null;
		}

		$action = strtolower( sanitize_text_field( $action ) );

		return mb_substr( $action, 0, 32 );
	}

	private function normalizeStatus( ?string $status ): ?string {
		if ( null === $status || '' === trim( $status ) ) {
			return null;
		}

		$status = trim( $status );

		if ( preg_match( '/^[245]\.\d{1,3}\.\d{1,3}$/', $status ) ) {
			return $status;
		}

		return null;
	}

	private function normalizeDiagnostic( ?string $value ): ?string {
		if ( null === $value || '' === trim( $value ) ) {
			return null;
		}

		$value = sanitize_text_field( $value );
		$value = (string) preg_replace( '/\s+/', ' ', $value );

		return mb_substr( trim( $value ), 0, 500 );
	}

	private function normalizeHeaderValue( ?string $value ): ?string {
		if ( null === $value || '' === trim( $value ) ) {
			return null;
		}

		return mb_substr( trim( wp_strip_all_tags( $value ) ), 0, 255 );
	}

	private function normalizeMessageId( ?string $value ): ?string {
		if ( null === $value || '' === trim( $value ) ) {
			return null;
		}

		$value = trim( $value, " \t\n\r\0\x0B<>" );
		$value = sanitize_text_field( $value );

		return mb_substr( $value, 0, 190 );
	}

	private function sanitizeRawChunk( string $value, int $limit ): string {
		$value = str_replace( "\0", '', $value );

		if ( strlen( $value ) > $limit ) {
			$value = substr( $value, 0, $limit );
		}

		return $value;
	}
}
