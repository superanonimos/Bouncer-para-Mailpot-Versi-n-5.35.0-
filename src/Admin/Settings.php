<?php
declare(strict_types=1);

namespace Publikate\MailPoetBouncer\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestor de persistencia, registro y sanitización estricta de la Settings API de WordPress.
 *
 * Implementa:
 * - Registro seguro con register_setting(), add_settings_section() y add_settings_field().
 * - Validación rigurosa de capacidades de usuario (manage_options) y nonces criptográficos.
 * - Sanitización defensiva contra inyecciones CRLF, null bytes y valores fuera de rango para host, usuario y puerto.
 * - Preservación transparente de contraseñas ya almacenadas en la base de datos.
 * - Emisión de errores y avisos contextuales mediante add_settings_error().
 */
final class Settings {

	public const OPTION_KEY = 'pkmb_settings';
	public const GROUP_KEY  = 'pkmb_settings_group';
	public const SECTION_ID = 'pkmb_settings_section_imap';

	/**
	 * Configuración por defecto del plugin.
	 *
	 * @return array{
	 *   host: string,
	 *   port: int,
	 *   encryption: string,
	 *   username: string,
	 *   password: string,
	 *   folder: string,
	 *   batch_size: int,
	 *   validate_cert: int,
	 *   dry_run: int,
	 *   delete_after_process: int
	 * }
	 */
	public static function getDefaults(): array {
		return [
			'host'                 => '',
			'port'                 => 993,
			'encryption'           => 'ssl',
			'username'             => '',
			'password'             => '',
			'folder'               => 'INBOX',
			'batch_size'           => 50,
			'validate_cert'        => 1,
			'dry_run'              => 1,
			'delete_after_process' => 0,
		];
	}

	/**
	 * Registra el grupo de opciones, secciones y campos con la Settings API de WordPress.
	 */
	public static function register(): void {
		register_setting(
			self::GROUP_KEY,
			self::OPTION_KEY,
			[
				'type'              => 'array',
				'description'       => __( 'Configuración de conexión y procesamiento de rebotes para MailPoet', 'mailpoet-bouncer' ),
				'sanitize_callback' => [ self::class, 'sanitize' ],
				'show_in_rest'      => false,
				'default'           => self::getDefaults(),
			]
		);

		add_settings_section(
			self::SECTION_ID,
			__( 'Credenciales y Parámetros del Buzón IMAP', 'mailpoet-bouncer' ),
			[ self::class, 'renderSectionDescription' ],
			SettingsPage::PAGE_SLUG
		);
	}

	/**
	 * Renderiza el texto explicativo de la sección de ajustes.
	 */
	public static function renderSectionDescription(): void {
		echo '<p class="description">';
		esc_html_e(
			'Configure las credenciales de acceso al buzón dedicado a capturar los rebotes (Return-Path / Bounce-To) de MailPoet.',
			'mailpoet-bouncer'
		);
		echo '</p>';
	}

	/**
	 * Sanitización estricta y defensiva de todos los campos de configuración.
	 *
	 * Valida capacidades del usuario actual y nonces antes de admitir modificaciones.
	 *
	 * @param mixed $input Datos crudos enviados desde el formulario.
	 * @return array<string, mixed> Datos completamente saneados y validados.
	 */
	public static function sanitize( mixed $input ): array {
		// 1. Verificación obligatoria de capacidades
		if ( ! current_user_can( 'manage_options' ) ) {
			add_settings_error(
				self::OPTION_KEY,
				'unauthorized_user',
				__( 'No dispone de permisos administrativos suficientes para modificar estos ajustes.', 'mailpoet-bouncer' ),
				'error'
			);

			return self::getAll();
		}

		// 2. Validación de nonce de seguridad en contexto HTTP POST
		if ( isset( $_POST['option_page'] ) && self::GROUP_KEY === wp_unslash( $_POST['option_page'] ) ) {
			$nonce = isset( $_POST['_wpnonce'] ) ? (string) wp_unslash( $_POST['_wpnonce'] ) : '';
			if ( ! wp_verify_nonce( $nonce, self::GROUP_KEY . '-options' ) ) {
				add_settings_error(
					self::OPTION_KEY,
					'invalid_nonce',
					__( 'Error de validación de seguridad (nonce inválido o expirado). Por favor, recargue la página e inténtelo nuevamente.', 'mailpoet-bouncer' ),
					'error'
				);

				return self::getAll();
			}
		}

		if ( ! is_array( $input ) ) {
			return self::getDefaults();
		}

		$current = self::getAll();
		$clean   = [];

		// 3. Sanitización estricta de Servidor IMAP (Host)
		$rawHost       = (string) ( $input['host'] ?? '' );
		$sanitizedHost = self::sanitizeHost( $rawHost );

		if ( '' === $sanitizedHost && '' !== trim( $rawHost ) ) {
			add_settings_error(
				self::OPTION_KEY,
				'invalid_host',
				__( 'El servidor IMAP especificado contiene caracteres no permitidos o formato inválido. Debe ser un nombre de dominio o dirección IP válida.', 'mailpoet-bouncer' ),
				'error'
			);
			$clean['host'] = $current['host'];
		} else {
			$clean['host'] = $sanitizedHost;
		}

		// 4. Sanitización estricta de Cifrado (Encryption)
		$encryption = strtolower( sanitize_key( (string) ( $input['encryption'] ?? 'ssl' ) ) );
		if ( in_array( $encryption, [ 'ssl', 'tls', 'none' ], true ) ) {
			$clean['encryption'] = $encryption;
		} else {
			add_settings_error(
				self::OPTION_KEY,
				'invalid_encryption',
				__( 'El tipo de cifrado seleccionado no es válido. Se ha restablecido a SSL/TLS por seguridad.', 'mailpoet-bouncer' ),
				'error'
			);
			$clean['encryption'] = 'ssl';
		}

		// 5. Sanitización estricta de Puerto (Port)
		$defaultPort   = ( 'ssl' === $clean['encryption'] ) ? 993 : 143;
		$rawPort       = $input['port'] ?? $defaultPort;
		$sanitizedPort = filter_var(
			$rawPort,
			FILTER_VALIDATE_INT,
			[
				'options' => [
					'min_range' => 1,
					'max_range' => 65535,
				],
			]
		);

		if ( false === $sanitizedPort ) {
			add_settings_error(
				self::OPTION_KEY,
				'invalid_port',
				sprintf(
					/* translators: %d: puerto asignado por defecto */
					__( 'El puerto debe ser un número entero válido entre 1 y 65535. Se ha asignado el puerto predeterminado %d.', 'mailpoet-bouncer' ),
					$defaultPort
				),
				'error'
			);
			$clean['port'] = $defaultPort;
		} else {
			$clean['port'] = (int) $sanitizedPort;
		}

		// 6. Sanitización estricta de Usuario / Correo (Username)
		$rawUser       = (string) ( $input['username'] ?? '' );
		$sanitizedUser = self::sanitizeUsername( $rawUser );

		if ( '' === $sanitizedUser && '' !== trim( $rawUser ) ) {
			add_settings_error(
				self::OPTION_KEY,
				'invalid_username',
				__( 'El nombre de usuario contiene caracteres de control o inyecciones no permitidas.', 'mailpoet-bouncer' ),
				'error'
			);
			$clean['username'] = $current['username'];
		} else {
			$clean['username'] = $sanitizedUser;
		}

		// 7. Sanitización de Contraseña (Password)
		$newPassword = (string) ( $input['password'] ?? '' );
		if ( '' === trim( $newPassword ) ) {
			// Preservar la contraseña existente sin exponerla ni borrarla
			$clean['password'] = (string) ( $current['password'] ?? '' );
		} else {
			$clean['password'] = self::sanitizePassword( $newPassword );
		}

		// 8. Sanitización de Carpeta IMAP (Folder)
		$rawFolder       = (string) ( $input['folder'] ?? 'INBOX' );
		$sanitizedFolder = self::sanitizeFolder( $rawFolder );
		$clean['folder'] = ( '' !== $sanitizedFolder ) ? $sanitizedFolder : 'INBOX';

		// 9. Tamaño de lote (Batch Size)
		$rawBatch       = $input['batch_size'] ?? 50;
		$sanitizedBatch = filter_var(
			$rawBatch,
			FILTER_VALIDATE_INT,
			[
				'options' => [
					'min_range' => 1,
					'max_range' => 500,
				],
			]
		);
		$clean['batch_size'] = ( false !== $sanitizedBatch ) ? (int) $sanitizedBatch : 50;

		// 10. Banderas booleanas (Validar certificado, Dry-Run, Borrado tras proceso)
		$clean['validate_cert']        = ! empty( $input['validate_cert'] ) ? 1 : 0;
		$clean['dry_run']              = ! empty( $input['dry_run'] ) ? 1 : 0;
		$clean['delete_after_process'] = ! empty( $input['delete_after_process'] ) ? 1 : 0;

		// Alerta de seguridad preventiva si se deshabilita el cifrado o la validación de certificados
		if ( 'none' === $clean['encryption'] ) {
			add_settings_error(
				self::OPTION_KEY,
				'insecure_encryption_notice',
				__( 'Advertencia de seguridad: Está utilizando conexión IMAP sin cifrado. Sus credenciales viajarán en texto plano por la red.', 'mailpoet-bouncer' ),
				'warning'
			);
		}

		if ( 0 === $clean['validate_cert'] && 'none' !== $clean['encryption'] ) {
			add_settings_error(
				self::OPTION_KEY,
				'disabled_cert_validation_notice',
				__( 'Aviso de seguridad: La validación de certificados SSL/TLS está desactivada. Esta opción solo debe usarse temporalmente en servidores de prueba con certificados autofirmados.', 'mailpoet-bouncer' ),
				'warning'
			);
		}

		return $clean;
	}

	/**
	 * Sanitiza de forma estricta el nombre de servidor o dirección IP.
	 *
	 * Remueve prefijos de protocolo (ej. imap://, ssl://), puertos anexos, barras y caracteres peligrosos.
	 */
	public static function sanitizeHost( string $host ): string {
		// Eliminar bytes nulos y espacios
		$host = str_replace( [ "\0", "\r", "\n", "\t" ], '', trim( $host ) );

		// Remover esquema si el usuario pegó una URL completa (ej. imap:// o ssl://)
		$host = (string) preg_replace( '#^[a-zA-Z][a-zA-Z0-9+\-.]*://#', '', $host );

		// Remover barra final o rutas si existen
		if ( str_contains( $host, '/' ) ) {
			$parts = explode( '/', $host, 2 );
			$host  = $parts[0];
		}

		// Remover puerto si vino anexado con dos puntos (ej. mail.servidor.com:993)
		if ( str_contains( $host, ':' ) && ! str_contains( $host, ']' ) ) {
			$hostParts = explode( ':', $host, 2 );
			$host      = $hostParts[0];
		}

		$host = sanitize_text_field( strtolower( trim( $host ) ) );

		// Verificar que no exceda los 255 caracteres estándar de DNS
		if ( strlen( $host ) > 255 ) {
			return '';
		}

		// Validar que sea una IP válida (v4 o v6) o un hostname con formato RFC 1123
		if ( filter_var( $host, FILTER_VALIDATE_IP ) !== false ) {
			return $host;
		}

		if ( preg_match( '/^[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?)*$/', $host ) ) {
			return $host;
		}

		return '';
	}

	/**
	 * Sanitiza el usuario de la cuenta IMAP evitando inyecciones CRLF o caracteres de control.
	 */
	public static function sanitizeUsername( string $username ): string {
		// Eliminar bytes nulos y caracteres de control ASCII (0x00 a 0x1F y 0x7F)
		$username = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', trim( $username ) );

		// Sanitización estándar WordPress
		$username = sanitize_text_field( $username );

		return mb_substr( $username, 0, 190 );
	}

	/**
	 * Sanitiza la contraseña eliminando únicamente bytes nulos y saltos de línea (CRLF),
	 * preservando todos los símbolos especiales requeridos en contraseñas seguras.
	 */
	public static function sanitizePassword( string $password ): string {
		// Eliminar bytes nulos y saltos de línea que permitirían inyección de comandos IMAP
		return str_replace( [ "\0", "\r", "\n" ], '', $password );
	}

	/**
	 * Sanitiza el nombre de la carpeta IMAP seleccionada.
	 */
	public static function sanitizeFolder( string $folder ): string {
		$folder = str_replace( [ "\0", "\r", "\n", "\t" ], '', trim( $folder ) );
		$folder = sanitize_text_field( $folder );

		return mb_substr( $folder, 0, 100 );
	}

	/**
	 * Valida un nonce de seguridad contra la acción configurada para el plugin.
	 */
	public static function verifyNonce( string $nonce, string $action = self::GROUP_KEY . '-options' ): bool {
		return (bool) wp_verify_nonce( $nonce, $action );
	}

	/**
	 * Obtiene todas las opciones combinadas con los valores por defecto.
	 *
	 * @return array<string, mixed>
	 */
	public static function getAll(): array {
		$saved = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}

		return wp_parse_args( $saved, self::getDefaults() );
	}

	/**
	 * Obtiene un valor específico de la configuración.
	 */
	public static function get( string $key, mixed $default = null ): mixed {
		$all = self::getAll();

		return $all[ $key ] ?? $default;
	}
}
