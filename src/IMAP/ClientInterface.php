<?php
declare(strict_types=1);

namespace Publikate\MailPoetBouncer\IMAP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contrato unificado para clientes IMAP.
 *
 * Regla de diseño:
 * - el plugin debe trabajar con UIDs IMAP, no con sequence numbers;
 * - las implementaciones deben lanzar RuntimeException en errores operacionales;
 * - disconnect() nunca debe lanzar excepción fatal hacia arriba.
 */
interface ClientInterface {

	/**
	 * Establece la conexión con el servidor IMAP.
	 *
	 * @throws \RuntimeException Si la conexión, negociación TLS o autenticación falla.
	 */
	public function connect(): void;

	/**
	 * Verifica conectividad y credenciales sin alterar el estado del buzón.
	 *
	 * @return array{success:bool,message:string}
	 */
	public function testConnection(): array;

	/**
	 * Obtiene UIDs de mensajes no leídos (UNSEEN).
	 *
	 * @param int $limit Máximo de mensajes a devolver.
	 * @return array<int,int> Lista de UIDs IMAP.
	 *
	 * @throws \RuntimeException Si falla la consulta al buzón.
	 */
	public function getUnreadMessageIds( int $limit = 50 ): array;

	/**
	 * Recupera un mensaje por UID.
	 *
	 * @param int $messageId UID IMAP del mensaje.
	 * @return array{
	 *   headers:string,
	 *   body:string,
	 *   uid:int
	 * }|null
	 *
	 * @throws \RuntimeException Si la lectura del mensaje falla.
	 */
	public function fetchMessage( int $messageId ): ?array;

	/**
	 * Marca un mensaje para eliminación lógica por UID.
	 *
	 * @param int $messageId UID IMAP del mensaje.
	 * @return bool True si quedó marcado; false si no fue posible.
	 *
	 * @throws \RuntimeException Si el servidor responde con error.
	 */
	public function markForDeletion( int $messageId ): bool;

	/**
	 * Marca un mensaje como leído (\\Seen) por UID.
	 *
	 * @param int $messageId UID IMAP del mensaje.
	 * @return bool True si quedó marcado; false si no fue posible.
	 *
	 * @throws \RuntimeException Si el servidor responde con error.
	 */
	public function markAsSeen( int $messageId ): bool;

	/**
	 * Ejecuta EXPUNGE para borrar definitivamente mensajes ya marcados.
	 *
	 * @return bool True si EXPUNGE se ejecutó correctamente.
	 *
	 * @throws \RuntimeException Si el servidor responde con error.
	 */
	public function expunge(): bool;

	/**
	 * Cierra la sesión y libera recursos.
	 *
	 * Nunca debería propagar una excepción fatal al llamador.
	 */
	public function disconnect(): void;
}