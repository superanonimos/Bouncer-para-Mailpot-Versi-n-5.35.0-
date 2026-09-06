<?php
declare(strict_types=1);

namespace Publikate\MailPoetBouncer\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Utilería de seguridad y hardening para MailPoet Bouncer.
 *
 * Proporciona:
 * - Enmascaramiento de credenciales y contraseñas IMAP en trazas, logs y respuestas.
 * - Sanitización de excepciones evitando divulgación de rutas internas del servidor.
 * - Validación y acotamiento seguro de lotes y parámetros numéricos.
 */
final class Security {

	/**
	 * Enmascara contraseñas, secretos y rutas internas del servidor en cualquier cadena de texto.
	 *
	 * @param string      $text   Texto original susceptible de contener datos sensibles.
	 * @param string|null $secret Contraseña o secreto conocido a enmascarar obligatoriamente.
	 * @return string Texto sanitizado y seguro para auditoría y visualización.
	 */
	public static function maskSensitiveData( string $text, ?string $secret = null ): string {
		if ( '' === trim( $text ) ) {
			return '';
		}

		// 1. Enmascarar la contraseña explícita configurada si está presente
		if ( null !== $secret && '' !== trim( $secret ) ) {
			$text = str_replace( $secret, '********', $text );
			// También enmascarar si estuviera codificada en URL o formato crudo
			$text = str_replace( rawurlencode( $secret ), '********', $text );
		}

		// 2. Enmascarar comandos LOGIN IMAP: LOGIN "usuario" "contraseña" o LOGIN usuario clave
		$text = (string) preg_replace(
			'/(LOGIN\s+(?:"[^"]*"|\S+)\s+)(?:"[^"]*"|\S+)/i',
			'$1"********"',
			$text
		);

		// 3. Enmascarar credenciales embebidas en URLs: esquema://usuario:contraseña@host
		$text = (string) preg_replace(
			'#([a-zA-Z][a-zA-Z0-9+\-.]*://[^:\s]+:)([^@\s]+)(@)#',
			'$1********$3',
			$text
		);

		// 4. Enmascarar rutas absolutas de servidor (evitar information disclosure)
		if ( defined( 'ABSPATH' ) && '' !== ABSPATH ) {
			$text = str_replace( ABSPATH, '[RAIZ_WP]/', $text );
		}

		$text = (string) preg_replace( '#/(?:home|var/www|app|usr|etc)/[a-zA-Z0-9_.\-]+/[a-zA-Z0-9_.\-/]+#i', '[RUTA_SISTEMA]', $text );

		return $text;
	}

	/**
	 * Sanitiza el mensaje extraído de una excepción Throwable para evitar divulgar secretos o trazas internas.
	 *
	 * @param \Throwable|string $error  Excepción o mensaje de error crudo.
	 * @param string|null       $secret Secreto opcional que debe ser ocultado.
	 * @return string Mensaje saneado apto para registro o respuesta JSON.
	 */
	public static function sanitizeErrorMessage( \Throwable|string $error, ?string $secret = null ): string {
		$rawMessage = $error instanceof \Throwable ? $error->getMessage() : $error;

		$sanitized = self::maskSensitiveData( $rawMessage, $secret );

		return sanitize_text_field( $sanitized );
	}

	/**
	 * Valida y acota un valor de lote (batch size) entre los límites definidos.
	 *
	 * @param mixed $value    Valor a validar.
	 * @param int   $min      Límite inferior.
	 * @param int   $max      Límite superior.
	 * @param int   $default  Valor predeterminado si es inválido.
	 * @return int Valor sanitizado y acotado.
	 */
	public static function clampBatchSize( mixed $value, int $min = 1, int $max = 500, int $default = 50 ): int {
		if ( null === $value || '' === $value ) {
			return $default;
		}

		$intVal = filter_var(
			$value,
			FILTER_VALIDATE_INT,
			[
				'options' => [
					'min_range' => $min,
					'max_range' => $max,
				],
			]
		);

		return false !== $intVal ? $intVal : $default;
	}

	/**
	 * Enmascara una dirección de correo electrónico para auditoría y visualización (ej: u***r@d***o.com).
	 *
	 * Evita exponer direcciones de correo completas en paneles o logs visuales.
	 */
	public static function maskEmail( string $email ): string {
		$email = trim( $email );
		if ( '' === $email || ! str_contains( $email, '@' ) ) {
			return $email;
		}

		$parts = explode( '@', $email, 2 );
		$user  = $parts[0];
		$host  = $parts[1];

		// Enmascarar parte local (usuario)
		$userLen = mb_strlen( $user );
		if ( $userLen <= 2 ) {
			$maskedUser = mb_substr( $user, 0, 1 ) . '*';
		} else {
			$maskedUser = mb_substr( $user, 0, 1 ) . str_repeat( '*', min( 4, $userLen - 2 ) ) . mb_substr( $user, -1 );
		}

		// Enmascarar dominio
		$hostParts = explode( '.', $host );
		if ( count( $hostParts ) >= 2 ) {
			$domainName = $hostParts[0];
			$tld        = implode( '.', array_slice( $hostParts, 1 ) );
			$domLen     = mb_strlen( $domainName );
			if ( $domLen <= 2 ) {
				$maskedDomain = mb_substr( $domainName, 0, 1 ) . '*';
			} else {
				$maskedDomain = mb_substr( $domainName, 0, 1 ) . str_repeat( '*', min( 3, $domLen - 2 ) ) . mb_substr( $domainName, -1 );
			}
			$maskedHost = $maskedDomain . '.' . $tld;
		} else {
			$maskedHost = $host;
		}

		return $maskedUser . '@' . $maskedHost;
	}
}
