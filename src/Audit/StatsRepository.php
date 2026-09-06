<?php
declare(strict_types=1);

namespace Publikate\MailPoetBouncer\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repositorio de consultas optimizadas para el panel de estadísticas y KPIs.
 * Opera exclusivamente sobre la tabla wp_pkmb_audit_log mediante $wpdb.
 */
final class StatsRepository {

	public const LAST_RUN_OPTION = 'pkmb_last_run';

	/**
	 * Obtiene los valores consolidados de los 6 KPIs principales.
	 *
	 * @return array{
	 *   processed_today: int,
	 *   hard_bounces_today: int,
	 *   soft_bounces_today: int,
	 *   autoresponses_today: int,
	 *   imap_errors_7d: int,
	 *   last_run: array{
	 *     date_formatted: string,
	 *     time_formatted: string,
	 *     duration_formatted: string,
	 *     processed: int,
	 *     has_run: bool
	 *   }
	 * }
	 */
	public static function getKpis(): array {
		global $wpdb;

		$table_name = $wpdb->prefix . Logger::TABLE_NAME;

		$defaults = [
			'processed_today'     => 0,
			'hard_bounces_today'  => 0,
			'soft_bounces_today'  => 0,
			'autoresponses_today' => 0,
			'imap_errors_7d'      => 0,
			'last_run'            => self::getLastRunInfo(),
		];

		if ( ! self::tableExists( $table_name ) ) {
			return $defaults;
		}

		$todayStart = current_time( 'Y-m-d 00:00:00' );
		$todayEnd   = current_time( 'Y-m-d 23:59:59' );
		$sevenDaysAgo = gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) );

		// Consulta consolidada para las métricas de hoy
		$todayStats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT 
					COUNT(id) AS processed_today,
					SUM(CASE WHEN bounce_type = 'hard' THEN 1 ELSE 0 END) AS hard_today,
					SUM(CASE WHEN bounce_type = 'soft' THEN 1 ELSE 0 END) AS soft_today,
					SUM(CASE WHEN bounce_type = 'autoresponder' THEN 1 ELSE 0 END) AS auto_today
				FROM {$table_name}
				WHERE created_at >= %s AND created_at <= %s",
				$todayStart,
				$todayEnd
			),
			ARRAY_A
		);

		if ( is_array( $todayStats ) ) {
			$defaults['processed_today']     = (int) ( $todayStats['processed_today'] ?? 0 );
			$defaults['hard_bounces_today']  = (int) ( $todayStats['hard_today'] ?? 0 );
			$defaults['soft_bounces_today']  = (int) ( $todayStats['soft_today'] ?? 0 );
			$defaults['autoresponses_today'] = (int) ( $todayStats['auto_today'] ?? 0 );
		}

		// Consulta para errores IMAP de los últimos 7 días
		$errorsCount = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(id) 
				FROM {$table_name} 
				WHERE bounce_type IN ('error', 'imap_error') 
				  AND created_at >= %s",
				$sevenDaysAgo
			)
		);

		$defaults['imap_errors_7d'] = (int) $errorsCount;

		return $defaults;
	}

	/**
	 * Obtiene información de la última ejecución registrada.
	 *
	 * @return array{
	 *   date_formatted: string,
	 *   time_formatted: string,
	 *   duration_formatted: string,
	 *   processed: int,
	 *   has_run: bool
	 * }
	 */
	public static function getLastRunInfo(): array {
		$lastRun = get_option( self::LAST_RUN_OPTION );

		if ( is_array( $lastRun ) && ! empty( $lastRun['timestamp'] ) ) {
			$ts       = (int) $lastRun['timestamp'];
			$duration = (float) ( $lastRun['duration'] ?? 0.0 );
			$proc     = (int) ( $lastRun['processed'] ?? 0 );

			return [
				'date_formatted'     => wp_date( get_option( 'date_format', 'd/m/Y' ), $ts ),
				'time_formatted'     => wp_date( get_option( 'time_format', 'H:i:s' ), $ts ),
				'duration_formatted' => sprintf( '%.2f s', $duration ),
				'processed'          => $proc,
				'has_run'            => true,
			];
		}

		// Fallback: Si no existe la opción pero hay registros en la tabla
		global $wpdb;
		$table_name = $wpdb->prefix . Logger::TABLE_NAME;

		if ( self::tableExists( $table_name ) ) {
			$lastRow = $wpdb->get_row( "SELECT created_at FROM {$table_name} ORDER BY id DESC LIMIT 1" );
			if ( $lastRow && ! empty( $lastRow->created_at ) ) {
				$ts = strtotime( (string) $lastRow->created_at );
				if ( false !== $ts ) {
					return [
						'date_formatted'     => wp_date( get_option( 'date_format', 'd/m/Y' ), $ts ),
						'time_formatted'     => wp_date( get_option( 'time_format', 'H:i:s' ), $ts ),
						'duration_formatted' => __( 'N/D', 'mailpoet-bouncer' ),
						'processed'          => 1,
						'has_run'            => true,
					];
				}
			}
		}

		return [
			'date_formatted'     => __( 'Sin ejecuciones', 'mailpoet-bouncer' ),
			'time_formatted'     => '--:--',
			'duration_formatted' => '--',
			'processed'          => 0,
			'has_run'            => false,
		];
	}

	/**
	 * Guarda el registro de una corrida de procesamiento finalizada.
	 *
	 * @param int                  $processed Cantidad de mensajes analizados.
	 * @param float                $durationSeconds Tiempo de ejecución en segundos.
	 * @param array<string, mixed> $details Detalles complementarios.
	 */
	public static function recordRun( int $processed, float $durationSeconds, array $details = [] ): void {
		update_option(
			self::LAST_RUN_OPTION,
			[
				'timestamp' => time(),
				'datetime'  => current_time( 'mysql' ),
				'duration'  => round( $durationSeconds, 3 ),
				'processed' => $processed,
				'details'   => $details,
			],
			false
		);
	}

	/**
	 * Agrega y estructura datos para el gráfico de líneas (actividad diaria).
	 *
	 * @param int $days Cantidad de días (entre 7 y 30).
	 * @return array{
	 *   labels: array<int, string>,
	 *   dates: array<int, string>,
	 *   processed: array<int, int>,
	 *   hard_bounces: array<int, int>,
	 *   soft_bounces: array<int, int>,
	 *   total_period: int
	 * }
	 */
	public static function getLineChartData( int $days = 30 ): array {
		global $wpdb;

		$days = max( 7, min( 30, $days ) );
		$table_name = $wpdb->prefix . Logger::TABLE_NAME;

		// Construir mapa inicial con todas las fechas del rango
		$daysMap = [];
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$ts      = time() - ( $i * DAY_IN_SECONDS );
			$dateKey = gmdate( 'Y-m-d', $ts );
			$daysMap[ $dateKey ] = [
				'label'     => wp_date( 'j M', $ts ),
				'date'      => $dateKey,
				'processed' => 0,
				'hard'      => 0,
				'soft'      => 0,
			];
		}

		$totalPeriod = 0;

		if ( self::tableExists( $table_name ) ) {
			$startDate = gmdate( 'Y-m-d 00:00:00', time() - ( ( $days - 1 ) * DAY_IN_SECONDS ) );

			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT 
						DATE(created_at) AS day_date,
						COUNT(id) AS total_count,
						SUM(CASE WHEN bounce_type = 'hard' THEN 1 ELSE 0 END) AS hard_count,
						SUM(CASE WHEN bounce_type = 'soft' THEN 1 ELSE 0 END) AS soft_count
					FROM {$table_name}
					WHERE created_at >= %s
					GROUP BY DATE(created_at)
					ORDER BY day_date ASC",
					$startDate
				),
				ARRAY_A
			);

			if ( is_array( $results ) ) {
				foreach ( $results as $row ) {
					$dateStr = (string) ( $row['day_date'] ?? '' );
					if ( isset( $daysMap[ $dateStr ] ) ) {
						$p = (int) ( $row['total_count'] ?? 0 );
						$h = (int) ( $row['hard_count'] ?? 0 );
						$s = (int) ( $row['soft_count'] ?? 0 );

						$daysMap[ $dateStr ]['processed'] = $p;
						$daysMap[ $dateStr ]['hard']      = $h;
						$daysMap[ $dateStr ]['soft']      = $s;

						$totalPeriod += $p;
					}
				}
			}
		}

		return [
			'labels'       => array_values( array_column( $daysMap, 'label' ) ),
			'dates'        => array_values( array_keys( $daysMap ) ),
			'processed'    => array_values( array_map( 'intval', array_column( $daysMap, 'processed' ) ) ),
			'hard_bounces' => array_values( array_map( 'intval', array_column( $daysMap, 'hard' ) ) ),
			'soft_bounces' => array_values( array_map( 'intval', array_column( $daysMap, 'soft' ) ) ),
			'total_period' => $totalPeriod,
		];
	}

	/**
	 * Agrega y estructura datos para el gráfico de dona (distribución de resultados).
	 *
	 * @param int $days Cantidad de días (entre 7 y 30).
	 * @return array{
	 *   labels: array<int, string>,
	 *   data: array<int, int>,
	 *   colors: array<int, string>,
	 *   total: int
	 * }
	 */
	public static function getDoughnutData( int $days = 30 ): array {
		global $wpdb;

		$days = max( 7, min( 30, $days ) );
		$table_name = $wpdb->prefix . Logger::TABLE_NAME;

		$canonical = [
			'hard'          => 0,
			'soft'          => 0,
			'autoresponder' => 0,
			'ignored'       => 0,
			'error'         => 0,
			'unknown'       => 0,
		];

		if ( self::tableExists( $table_name ) ) {
			$startDate = gmdate( 'Y-m-d 00:00:00', time() - ( ( $days - 1 ) * DAY_IN_SECONDS ) );

			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT bounce_type, COUNT(id) AS count_type
					FROM {$table_name}
					WHERE created_at >= %s
					GROUP BY bounce_type",
					$startDate
				),
				ARRAY_A
			);

			if ( is_array( $results ) ) {
				foreach ( $results as $row ) {
					$type  = (string) ( $row['bounce_type'] ?? '' );
					$count = (int) ( $row['count_type'] ?? 0 );

					switch ( $type ) {
						case 'hard':
							$canonical['hard'] += $count;
							break;
						case 'soft':
							$canonical['soft'] += $count;
							break;
						case 'autoresponder':
							$canonical['autoresponder'] += $count;
							break;
						case 'non_bounce':
						case 'ignored':
							$canonical['ignored'] += $count;
							break;
						case 'error':
						case 'imap_error':
							$canonical['error'] += $count;
							break;
						default:
							$canonical['unknown'] += $count;
							break;
					}
				}
			}
		}

		$labels = [
			__( 'Hard Bounces', 'mailpoet-bouncer' ),
			__( 'Soft Bounces', 'mailpoet-bouncer' ),
			__( 'Autorespuestas', 'mailpoet-bouncer' ),
			__( 'Ignorados', 'mailpoet-bouncer' ),
			__( 'Errores', 'mailpoet-bouncer' ),
			__( 'Desconocidos', 'mailpoet-bouncer' ),
		];

		$colors = [
			'#dc2626', // Rojo oscuro (Hard)
			'#f59e0b', // Ámbar (Soft)
			'#3b82f6', // Azul (Autoresponder)
			'#9ca3af', // Gris medio (Ignorados)
			'#7c3aed', // Violeta (Errores)
			'#64748b', // Pizarra (Desconocidos)
		];

		$dataValues = array_values( $canonical );
		$total      = (int) array_sum( $dataValues );

		return [
			'labels' => $labels,
			'data'   => $dataValues,
			'colors' => $colors,
			'total'  => $total,
		];
	}

	/**
	 * Recupera los últimos N registros formateados para la tabla de actividad reciente.
	 *
	 * @param int $limit Límite de filas (máximo 50).
	 * @return array<int, array{
	 *   id: int,
	 *   datetime: string,
	 *   type_label: string,
	 *   type_badge_class: string,
	 *   email: string,
	 *   diagnostic_short: string,
	 *   action_label: string,
	 *   status_label: string,
	 *   status_badge_class: string
	 * }>
	 */
	public static function getRecentActivity( int $limit = 15 ): array {
		global $wpdb;

		$table_name = $wpdb->prefix . Logger::TABLE_NAME;
		$limit      = max( 1, min( 50, $limit ) );

		if ( ! self::tableExists( $table_name ) ) {
			return [];
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, email, bounce_type, action_taken, dsn_code, diagnostic_message, subject, created_at
				FROM {$table_name}
				ORDER BY id DESC
				LIMIT %d",
				$limit
			)
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$formatted = [];
		foreach ( $rows as $row ) {
			$ts = strtotime( (string) $row->created_at );
			$dateStr = false !== $ts ? wp_date( 'd/m/Y H:i:s', $ts ) : (string) $row->created_at;

			$typeInfo   = self::mapBounceType( (string) $row->bounce_type );
			$actionInfo = self::mapActionTaken( (string) $row->action_taken, (string) $row->bounce_type );

			// Diagnóstico resumido
			$diag = (string) ( $row->diagnostic_message ?? '' );
			if ( empty( $diag ) && ! empty( $row->dsn_code ) ) {
				$diag = 'Código DSN: ' . $row->dsn_code;
			} elseif ( empty( $diag ) && ! empty( $row->subject ) ) {
				$diag = 'Asunto: ' . $row->subject;
			}
			if ( empty( $diag ) ) {
				$diag = __( 'Sin diagnóstico adicional.', 'mailpoet-bouncer' );
			}

			$formatted[] = [
				'id'                 => (int) $row->id,
				'datetime'           => $dateStr,
				'type_label'         => $typeInfo['label'],
				'type_badge_class'   => $typeInfo['class'],
				'email'              => Security::maskEmail( (string) $row->email ),
				'diagnostic_short'   => mb_substr( $diag, 0, 90 ),
				'action_label'       => $actionInfo['label'],
				'status_label'       => $actionInfo['status'],
				'status_badge_class' => $actionInfo['status_class'],
			];
		}

		return $formatted;
	}

	/**
	 * Mapea el tipo de rebote a etiqueta y clase visual de badge.
	 *
	 * @return array{label: string, class: string}
	 */
	private static function mapBounceType( string $type ): array {
		return match ( $type ) {
			'hard'          => [ 'label' => __( 'Hard Bounce', 'mailpoet-bouncer' ), 'class' => 'pkmb-badge-danger' ],
			'soft'          => [ 'label' => __( 'Soft Bounce', 'mailpoet-bouncer' ), 'class' => 'pkmb-badge-warning' ],
			'autoresponder' => [ 'label' => __( 'Autorespuesta', 'mailpoet-bouncer' ), 'class' => 'pkmb-badge-info' ],
			'non_bounce',
			'ignored'       => [ 'label' => __( 'Ignorado', 'mailpoet-bouncer' ), 'class' => 'pkmb-badge-neutral' ],
			'error',
			'imap_error'    => [ 'label' => __( 'Error IMAP', 'mailpoet-bouncer' ), 'class' => 'pkmb-badge-purple' ],
			default         => [ 'label' => __( 'Desconocido', 'mailpoet-bouncer' ), 'class' => 'pkmb-badge-secondary' ],
		};
	}

	/**
	 * Mapea la acción tomada a descripciones y estado en español.
	 *
	 * @return array{label: string, status: string, status_class: string}
	 */
	private static function mapActionTaken( string $action, string $bounceType ): array {
		return match ( $action ) {
			'unsubscribed' => [
				'label'        => __( 'Suscriptor desuscrito en MailPoet', 'mailpoet-bouncer' ),
				'status'       => __( 'Completado', 'mailpoet-bouncer' ),
				'status_class' => 'pkmb-status-success',
			],
			'dry_run_simulated',
			'simulated' => [
				'label'        => __( 'Simulación activa (Dry-Run)', 'mailpoet-bouncer' ),
				'status'       => __( 'Simulado', 'mailpoet-bouncer' ),
				'status_class' => 'pkmb-status-info',
			],
			'subscriber_not_found' => [
				'label'        => __( 'Correo no hallado en listas MailPoet', 'mailpoet-bouncer' ),
				'status'       => __( 'Omitido', 'mailpoet-bouncer' ),
				'status_class' => 'pkmb-status-neutral',
			],
			'already_unsubscribed' => [
				'label'        => __( 'Ya figuraba desuscrito previamente', 'mailpoet-bouncer' ),
				'status'       => __( 'Sin cambios', 'mailpoet-bouncer' ),
				'status_class' => 'pkmb-status-neutral',
			],
			'ignored' => [
				'label'        => 'autoresponder' === $bounceType
					? __( 'Autorespuesta preservada (sin sanción)', 'mailpoet-bouncer' )
					: __( 'Rebote transitorio o no procesable', 'mailpoet-bouncer' ),
				'status'       => __( 'Ignorado', 'mailpoet-bouncer' ),
				'status_class' => 'pkmb-status-neutral',
			],
			'connection_failed' => [
				'label'        => __( 'Fallo en la comunicación con el servidor', 'mailpoet-bouncer' ),
				'status'       => __( 'Fallo IMAP', 'mailpoet-bouncer' ),
				'status_class' => 'pkmb-status-error',
			],
			default => [
				'label'        => sanitize_text_field( $action ),
				'status'       => __( 'Procesado', 'mailpoet-bouncer' ),
				'status_class' => 'pkmb-status-neutral',
			],
		};
	}

	/**
	 * Verifica de forma segura si la tabla existe en la base de datos.
	 */
	private static function tableExists( string $tableName ): bool {
		global $wpdb;

		return $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $tableName )
		) === $tableName;
	}
}
