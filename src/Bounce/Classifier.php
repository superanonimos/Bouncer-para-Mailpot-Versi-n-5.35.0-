<?php
declare(strict_types=1);

namespace Publikate\MailPoetBouncer\Bounce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clasificador conservador de estados de rebote según RFC 3463 / RFC 3464.
 *
 * Regla principal:
 * - solo marcar hard bounce cuando exista evidencia suficientemente clara;
 * - si hay ambigüedad, devolver unknown;
 * - autoresponders y soft bounces nunca deben terminar como hard.
 */
final class Classifier {

	/**
	 * Códigos DSN permanentemente inválidos con alta confianza.
	 *
	 * Solo estos códigos 5.X.X se consideran hard bounce de forma directa.
	 */
	private const PERMANENT_DSN_CODES = [
		'5.1.0' => 'Dirección de destino permanentemente inválida.',
		'5.1.1' => 'Buzón inexistente o dirección desconocida.',
		'5.1.2' => 'Dominio de destino no encontrado.',
		'5.1.3' => 'Sintaxis de dirección inválida.',
		'5.1.6' => 'Buzón trasladado permanentemente.',
		'5.2.1' => 'Buzón deshabilitado o que no acepta mensajes.',
		'5.4.4' => 'No se puede enrutar al dominio o host destino.',
	];

	/**
	 * Códigos DSN temporales o no concluyentes.
	 */
	private const TEMPORARY_DSN_CODES = [
		'4.2.2' => 'Buzón lleno temporalmente.',
		'4.3.1' => 'Sistema de correo saturado.',
		'4.4.1' => 'Sin respuesta del host de destino.',
		'4.4.2' => 'Conexión interrumpida.',
		'4.4.7' => 'Tiempo de entrega agotado.',
		'4.7.1' => 'Greylisting o limitación temporal.',
		'5.2.2' => 'Buzón sobre cuota; tratado como temporal por precaución.',
	];

	/**
	 * Clasifica metadatos extraídos de un mensaje de rebote.
	 *
	 * @param array{
	 *   email:?string,
	 *   dsn_status:?string,
	 *   action:?string,
	 *   diagnostic_code:?string,
	 *   headers:array<string,string>,
	 *   subject:?string,
	 *   message_id:?string,
	 *   body:string
	 * } $data
	 */
	public function classify( array $data ): Result {
		$email          = $data['email'] ?? null;
		$dsnStatus      = $this->normalizeStatus( $data['dsn_status'] ?? null );
		$action         = $this->normalizeAction( $data['action'] ?? null );
		$diagnosticCode = (string) ( $data['diagnostic_code'] ?? '' );
		$headers        = array_change_key_case( $data['headers'] ?? [], CASE_LOWER );
		$subject        = $data['subject'] ?? null;
		$messageId      = $data['message_id'] ?? null;
		$body           = (string) ( $data['body'] ?? '' );

		if ( $this->isAutoresponder( $headers, $subject, $body ) ) {
			return Result::autoresponder(
				email: $email,
				reason: 'Detectada respuesta automática o fuera de oficina. Ignorado por seguridad.',
				subject: $subject,
				messageId: $messageId
			);
		}

		if ( empty( $email ) || ! is_email( $email ) ) {
			return Result::unknown(
				email: null,
				reason: 'No se identificó un destinatario válido en el reporte de rebote.',
				subject: $subject,
				messageId: $messageId
			);
		}

		if ( null !== $dsnStatus ) {
			if ( isset( self::TEMPORARY_DSN_CODES[ $dsnStatus ] ) || str_starts_with( $dsnStatus, '4.' ) ) {
				return Result::softBounce(
					email: $email,
					dsnCode: $dsnStatus,
					reason: self::TEMPORARY_DSN_CODES[ $dsnStatus ] ?? ( 'Error temporal DSN ' . $dsnStatus . '.' ),
					action: $action ?: 'delayed',
					subject: $subject,
					messageId: $messageId
				);
			}

			if ( isset( self::PERMANENT_DSN_CODES[ $dsnStatus ] ) && ( '' === $action || 'failed' === $action ) ) {
				return Result::hardBounce(
					email: $email,
					dsnCode: $dsnStatus,
					reason: self::PERMANENT_DSN_CODES[ $dsnStatus ],
					action: $action ?: 'failed',
					subject: $subject,
					messageId: $messageId
				);
			}

			if ( str_starts_with( $dsnStatus, '5.' ) ) {
				return Result::unknown(
					email: $email,
					reason: 'DSN permanente no mapeado con certeza suficiente. Se conserva como desconocido por seguridad.',
					subject: $subject,
					messageId: $messageId
				);
			}
		}

		$combinedText = trim( $diagnosticCode . ' ' . substr( $body, 0, 4000 ) );

		if ( '' !== $combinedText ) {
			$hardPatterns = [
				'/\b550[\s\-]+5\.1\.1\b/i',
				'/\buser unknown\b/i',
				'/\bno such user\b/i',
				'/\bmailbox not found\b/i',
				'/\baccount does not exist\b/i',
				'/\brecipient address rejected: user unknown\b/i',
				'/\bno mailbox here by that name\b/i',
				'/\bunknown recipient\b/i',
				'/\bstatus:\s*5\.1\.1\b/i',
			];

			foreach ( $hardPatterns as $pattern ) {
				if ( preg_match( $pattern, $combinedText, $matches ) ) {
					return Result::hardBounce(
						email: $email,
						dsnCode: $dsnStatus ?: '5.1.1',
						reason: 'Coincidencia con patrón inequívoco de fallo permanente: ' . trim( (string) $matches[0] ),
						action: $action ?: 'failed',
						subject: $subject,
						messageId: $messageId
					);
				}
			}

			$softPatterns = [
				'/\b450\b/i',
				'/\b451\b/i',
				'/\b452\b/i',
				'/\bmailbox full\b/i',
				'/\bquota exceeded\b/i',
				'/\bexceeded storage\b/i',
				'/\btry again later\b/i',
				'/\btemporary failure\b/i',
				'/\bgreylisted\b/i',
				'/\brate limit\b/i',
			];

			foreach ( $softPatterns as $pattern ) {
				if ( preg_match( $pattern, $combinedText ) ) {
					return Result::softBounce(
						email: $email,
						dsnCode: $dsnStatus ?: '4.2.2',
						reason: 'Coincidencia con patrón temporal; no se marca como rebote definitivo.',
						action: $action ?: 'delayed',
						subject: $subject,
						messageId: $messageId
					);
				}
			}
		}

		if ( in_array( $action, [ 'delayed', 'relayed', 'expanded' ], true ) ) {
			return Result::softBounce(
				email: $email,
				dsnCode: $dsnStatus,
				reason: 'Acción DSN no definitiva; tratado como temporal por precaución.',
				action: $action,
				subject: $subject,
				messageId: $messageId
			);
		}

		return Result::unknown(
			email: $email,
			reason: 'Reporte ambiguo o no concluyente; no se clasifica como hard bounce por seguridad.',
			subject: $subject,
			messageId: $messageId
		);
	}

	/**
	 * Detecta autorespuestas y mensajes automáticos.
	 *
	 * @param array<string,string> $headers
	 */
	private function isAutoresponder( array $headers, ?string $subject, string $body ): bool {
		$headerChecks = [
			'auto-submitted',
			'x-autoreply',
			'x-autorespond',
			'x-auto-response-suppress',
			'x-ms-exchange-inbox-rules-loop',
		];

		foreach ( $headerChecks as $headerName ) {
			if ( empty( $headers[ $headerName ] ) ) {
				continue;
			}

			$value = strtolower( trim( $headers[ $headerName ] ) );

			if ( 'auto-submitted' === $headerName && 'no' !== $value ) {
				return true;
			}

			if ( in_array( $headerName, [ 'x-autoreply', 'x-autorespond' ], true ) && in_array( $value, [ 'yes', 'true', '1' ], true ) ) {
				return true;
			}

			if ( in_array( $headerName, [ 'x-auto-response-suppress', 'x-ms-exchange-inbox-rules-loop' ], true ) && '' !== $value ) {
				return true;
			}
		}

		if ( isset( $headers['precedence'] ) ) {
			$precedence = strtolower( trim( $headers['precedence'] ) );
			if ( in_array( $precedence, [ 'auto_reply', 'autoreply', 'bulk', 'vacation', 'junk' ], true ) ) {
				return true;
			}
		}

		$text = strtolower(
			trim(
				(string) $subject . "\n" . substr( $body, 0, 1500 )
			)
		);

		$keywords = [
			'out of office',
			'fuera de la oficina',
			'respuesta automática',
			'automatic reply',
			'autoreply',
			'auto-reply',
			'vacation notice',
			'estoy de vacaciones',
			'absence notice',
			'i am away from the office',
			'currently out of the office',
		];

		foreach ( $keywords as $keyword ) {
			if ( str_contains( $text, $keyword ) ) {
				return true;
			}
		}

		return false;
	}

	private function normalizeStatus( ?string $status ): ?string {
		if ( null === $status ) {
			return null;
		}

		$status = trim( $status );

		return preg_match( '/^[245]\.\d{1,3}\.\d{1,3}$/', $status ) ? $status : null;
	}

	private function normalizeAction( ?string $action ): string {
		if ( null === $action ) {
			return '';
		}

		return strtolower( sanitize_text_field( trim( $action ) ) );
	}
}