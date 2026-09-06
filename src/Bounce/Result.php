<?php
declare(strict_types=1);

namespace Publikate\MailPoetBouncer\Bounce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DTO inmutable con el resultado del análisis de rebote.
 */
final class Result {

	public const TYPE_HARD          = 'hard';
	public const TYPE_SOFT          = 'soft';
	public const TYPE_AUTOREPLY     = 'autoresponder';
	public const TYPE_UNKNOWN       = 'unknown';
	public const TYPE_UNPROCESSABLE = 'unprocessable';

	/**
	 * @param self::TYPE_* $type
	 */
	private function __construct(
		public readonly ?string $email,
		public readonly string $type,
		public readonly bool $isHardBounce,
		public readonly ?string $dsnCode,
		public readonly ?string $action,
		public readonly string $reason,
		public readonly ?string $subject = null,
		public readonly ?string $messageId = null
	) {}

	/**
	 * Crea un resultado de tipo hard bounce.
	 */
	public static function hardBounce(
		string $email,
		?string $dsnCode,
		string $reason,
		?string $action = 'failed',
		?string $subject = null,
		?string $messageId = null
	): self {
		$email = self::normalizeEmail( $email );

		if ( null === $email ) {
			return self::unprocessable(
				reason: 'Se intentó crear un hard bounce sin un email válido.',
				subject: $subject,
				messageId: $messageId
			);
		}

		return new self(
			email: $email,
			type: self::TYPE_HARD,
			isHardBounce: true,
			dsnCode: self::normalizeDsnCode( $dsnCode ),
			action: self::normalizeAction( $action ?: 'failed' ),
			reason: self::normalizeReason( $reason ),
			subject: self::normalizeText( $subject, 255 ),
			messageId: self::normalizeMessageId( $messageId )
		);
	}

	/**
	 * Crea un resultado de tipo soft bounce.
	 */
	public static function softBounce(
		?string $email,
		?string $dsnCode,
		string $reason,
		?string $action = 'delayed',
		?string $subject = null,
		?string $messageId = null
	): self {
		return new self(
			email: self::normalizeEmail( $email ),
			type: self::TYPE_SOFT,
			isHardBounce: false,
			dsnCode: self::normalizeDsnCode( $dsnCode ),
			action: self::normalizeAction( $action ?: 'delayed' ),
			reason: self::normalizeReason( $reason ),
			subject: self::normalizeText( $subject, 255 ),
			messageId: self::normalizeMessageId( $messageId )
		);
	}

	/**
	 * Crea un resultado para respuestas automáticas.
	 */
	public static function autoresponder(
		?string $email,
		string $reason,
		?string $subject = null,
		?string $messageId = null
	): self {
		return new self(
			email: self::normalizeEmail( $email ),
			type: self::TYPE_AUTOREPLY,
			isHardBounce: false,
			dsnCode: null,
			action: null,
			reason: self::normalizeReason( $reason ),
			subject: self::normalizeText( $subject, 255 ),
			messageId: self::normalizeMessageId( $messageId )
		);
	}

	/**
	 * Crea un resultado desconocido o no concluyente.
	 */
	public static function unknown(
		?string $email,
		string $reason,
		?string $subject = null,
		?string $messageId = null
	): self {
		return new self(
			email: self::normalizeEmail( $email ),
			type: self::TYPE_UNKNOWN,
			isHardBounce: false,
			dsnCode: null,
			action: null,
			reason: self::normalizeReason( $reason ),
			subject: self::normalizeText( $subject, 255 ),
			messageId: self::normalizeMessageId( $messageId )
		);
	}

	/**
	 * Crea un resultado no procesable.
	 */
	public static function unprocessable(
		string $reason,
		?string $subject = null,
		?string $messageId = null,
		?string $email = null
	): self {
		return new self(
			email: self::normalizeEmail( $email ),
			type: self::TYPE_UNPROCESSABLE,
			isHardBounce: false,
			dsnCode: null,
			action: null,
			reason: self::normalizeReason( $reason ),
			subject: self::normalizeText( $subject, 255 ),
			messageId: self::normalizeMessageId( $messageId )
		);
	}

	/**
	 * Indica si el resultado debe intentar actualizar MailPoet.
	 */
	public function shouldUpdateSubscriber(): bool {
		return self::TYPE_HARD === $this->type
			&& $this->isHardBounce
			&& null !== $this->email;
	}

	/**
	 * Exporta a array para logging/debug.
	 *
	 * @return array{
	 *   email:?string,
	 *   type:string,
	 *   is_hard_bounce:bool,
	 *   dsn_code:?string,
	 *   action:?string,
	 *   reason:string,
	 *   subject:?string,
	 *   message_id:?string
	 * }
	 */
	public function toArray(): array {
		return [
			'email'          => $this->email,
			'type'           => $this->type,
			'is_hard_bounce' => $this->isHardBounce,
			'dsn_code'       => $this->dsnCode,
			'action'         => $this->action,
			'reason'         => $this->reason,
			'subject'        => $this->subject,
			'message_id'     => $this->messageId,
		];
	}

	private static function normalizeEmail( ?string $email ): ?string {
		if ( null === $email ) {
			return null;
		}

		$email = sanitize_email( strtolower( trim( $email ) ) );

		return is_email( $email ) ? $email : null;
	}

	private static function normalizeDsnCode( ?string $dsnCode ): ?string {
		if ( null === $dsnCode ) {
			return null;
		}

		$dsnCode = trim( sanitize_text_field( $dsnCode ) );

		return preg_match( '/^[245]\.\d{1,3}\.\d{1,3}$/', $dsnCode )
			? $dsnCode
			: null;
	}

	private static function normalizeAction( ?string $action ): ?string {
		if ( null === $action ) {
			return null;
		}

		$action = strtolower( trim( sanitize_text_field( $action ) ) );

		if ( '' === $action ) {
			return null;
		}

		return mb_substr( $action, 0, 32 );
	}

	private static function normalizeReason( string $reason ): string {
		$reason = trim( sanitize_text_field( $reason ) );

		if ( '' === $reason ) {
			return 'Sin detalle.';
		}

		return mb_substr( $reason, 0, 500 );
	}

	private static function normalizeText( ?string $value, int $maxLength ): ?string {
		if ( null === $value ) {
			return null;
		}

		$value = trim( sanitize_text_field( $value ) );

		if ( '' === $value ) {
			return null;
		}

		return mb_substr( $value, 0, $maxLength );
	}

	private static function normalizeMessageId( ?string $messageId ): ?string {
		if ( null === $messageId ) {
			return null;
		}

		$messageId = trim( $messageId, " \t\n\r\0\x0B<>" );
		$messageId = sanitize_text_field( $messageId );

		if ( '' === $messageId ) {
			return null;
		}

		return mb_substr( $messageId, 0, 191 );
	}
}