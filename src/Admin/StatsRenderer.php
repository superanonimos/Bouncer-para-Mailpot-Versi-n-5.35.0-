<?php
declare(strict_types=1);

namespace Publikate\MailPoetBouncer\Admin;

use Publikate\MailPoetBouncer\Audit\StatsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renderizador de la interfaz visual del panel de Estadísticas y Gráficos.
 */
final class StatsRenderer {

	/**
	 * Renderiza la pestaña completa de estadísticas dentro del wp-admin.
	 */
	public static function render( int $initialPeriod = 30 ): void {
		$period   = max( 7, min( 30, $initialPeriod ) );
		$kpis     = StatsRepository::getKpis();
		$activity = StatsRepository::getRecentActivity( 15 );
		$lineData = StatsRepository::getLineChartData( $period );
		$doughnut = StatsRepository::getDoughnutData( $period );

		$hasData = $lineData['total_period'] > 0 || $doughnut['total'] > 0 || ! empty( $activity );

		?>
		<div class="pkmb-stats-dashboard" id="pkmb-stats-dashboard">

			<!-- Barra de herramientas y filtros superiores -->
			<div class="pkmb-stats-header" id="pkmb-stats-header">
				<div class="pkmb-stats-header-info">
					<h2 class="pkmb-section-title" id="pkmb-stats-title">
						<?php esc_html_e( 'Rendimiento Operativo y Actividad de Rebotes', 'mailpoet-bouncer' ); ?>
					</h2>
					<p class="description">
						<?php esc_html_e( 'Métricas en tiempo real extraídas del registro de auditoría local sin exponer datos confidenciales.', 'mailpoet-bouncer' ); ?>
					</p>
				</div>

				<div class="pkmb-stats-actions" id="pkmb-stats-controls">
					<label for="pkmb-period-selector" class="screen-reader-text">
						<?php esc_html_e( 'Seleccionar periodo de análisis', 'mailpoet-bouncer' ); ?>
					</label>
					<select id="pkmb-period-selector" class="pkmb-select">
						<option value="7" <?php selected( $period, 7 ); ?>>
							<?php esc_html_e( 'Últimos 7 días', 'mailpoet-bouncer' ); ?>
						</option>
						<option value="30" <?php selected( $period, 30 ); ?>>
							<?php esc_html_e( 'Últimos 30 días', 'mailpoet-bouncer' ); ?>
						</option>
					</select>

					<button type="button" id="pkmb-btn-refresh-stats" class="button button-secondary">
						<span class="dashicons dashicons-update" id="pkmb-refresh-icon"></span>
						<span id="pkmb-refresh-text"><?php esc_html_e( 'Actualizar estadísticas', 'mailpoet-bouncer' ); ?></span>
					</button>

					<?php if ( current_user_can( 'manage_options' ) ) : ?>
						<button type="button" id="pkmb-btn-process-now" class="button button-primary">
							<span class="dashicons dashicons-controls-play" id="pkmb-process-btn-icon"></span>
							<span id="pkmb-process-btn-text"><?php esc_html_e( 'Procesar ahora', 'mailpoet-bouncer' ); ?></span>
						</button>
					<?php endif; ?>
				</div>
			</div>

			<!-- Indicador visual: Barra tipo barbería (Barber Pole) -->
			<div class="pkmb-barber-pole-wrapper" id="pkmb-process-progress-container" style="display: none;" role="status" aria-live="polite">
				<div class="pkmb-barber-pole-header">
					<span class="dashicons dashicons-update pkmb-spinning" id="pkmb-barber-spin-icon"></span>
					<span id="pkmb-process-step-text"><?php esc_html_e( 'Conectando con el buzón…', 'mailpoet-bouncer' ); ?></span>
				</div>
				<div class="pkmb-barber-pole" id="pkmb-barber-pole-bar"></div>
				<div class="pkmb-barber-pole-footer" id="pkmb-process-longer-msg" style="display: none;">
					<span class="dashicons dashicons-info"></span>
					<span><?php esc_html_e( 'La operación está tardando más de lo normal, esperando respuesta del servidor IMAP...', 'mailpoet-bouncer' ); ?></span>
				</div>
			</div>

			<!-- Caja de estado y resultado final -->
			<div class="notice pkmb-result-box" id="pkmb-process-result-box" style="display: none;" role="region" aria-live="polite">
				<div class="pkmb-result-header">
					<div class="pkmb-result-title-group">
						<span class="dashicons" id="pkmb-result-icon"></span>
						<strong id="pkmb-result-title"></strong>
					</div>
					<button type="button" class="pkmb-result-close" id="pkmb-btn-close-result" aria-label="<?php esc_attr_e( 'Cerrar aviso', 'mailpoet-bouncer' ); ?>">
						<span class="dashicons dashicons-no-alt"></span>
					</button>
				</div>
				<p class="pkmb-result-message" id="pkmb-result-message"></p>
				<div class="pkmb-result-summary" id="pkmb-result-summary" style="display: none;">
					<span class="pkmb-summary-pill" id="pill-processed">
						<strong><?php esc_html_e( 'Revisados:', 'mailpoet-bouncer' ); ?></strong> <span id="val-pill-processed">0</span>
					</span>
					<span class="pkmb-summary-pill pkmb-pill-danger" id="pill-hard">
						<strong><?php esc_html_e( 'Hard bounces:', 'mailpoet-bouncer' ); ?></strong> <span id="val-pill-hard">0</span>
					</span>
					<span class="pkmb-summary-pill pkmb-pill-warning" id="pill-soft">
						<strong><?php esc_html_e( 'Soft bounces:', 'mailpoet-bouncer' ); ?></strong> <span id="val-pill-soft">0</span>
					</span>
					<span class="pkmb-summary-pill pkmb-pill-info" id="pill-auto">
						<strong><?php esc_html_e( 'Autorespuestas:', 'mailpoet-bouncer' ); ?></strong> <span id="val-pill-auto">0</span>
					</span>
					<span class="pkmb-summary-pill pkmb-pill-neutral" id="pill-ignored">
						<strong><?php esc_html_e( 'Ignorados:', 'mailpoet-bouncer' ); ?></strong> <span id="val-pill-ignored">0</span>
					</span>
					<span class="pkmb-summary-pill pkmb-pill-purple" id="pill-mp">
						<strong><?php esc_html_e( 'MailPoet actualizados:', 'mailpoet-bouncer' ); ?></strong> <span id="val-pill-mp">0</span>
					</span>
					<span class="pkmb-summary-pill pkmb-pill-gray" id="pill-duration">
						<strong><?php esc_html_e( 'Duración:', 'mailpoet-bouncer' ); ?></strong> <span id="val-pill-duration">0 ms</span>
					</span>
				</div>
				<div class="pkmb-result-details" id="pkmb-result-details" style="display: none;">
					<details>
						<summary><?php esc_html_e( 'Ver detalles técnicos', 'mailpoet-bouncer' ); ?></summary>
						<ul id="pkmb-result-details-list"></ul>
					</details>
				</div>
			</div>

			<!-- 6 Tarjetas KPI Principales -->
			<div class="pkmb-kpi-grid" id="pkmb-kpis-container">
				<!-- KPI 1: Procesados hoy -->
				<div class="pkmb-kpi-card" id="pkmb-kpi-processed-today">
					<div class="pkmb-kpi-icon pkmb-kpi-icon-blue">
						<span class="dashicons dashicons-email-alt"></span>
					</div>
					<div class="pkmb-kpi-content">
						<span class="pkmb-kpi-label"><?php esc_html_e( 'Mensajes procesados hoy', 'mailpoet-bouncer' ); ?></span>
						<span class="pkmb-kpi-value" id="kpi-val-processed"><?php echo esc_html( number_format_i18n( $kpis['processed_today'] ) ); ?></span>
					</div>
				</div>

				<!-- KPI 2: Hard bounces hoy -->
				<div class="pkmb-kpi-card" id="pkmb-kpi-hard-today">
					<div class="pkmb-kpi-icon pkmb-kpi-icon-red">
						<span class="dashicons dashicons-dismiss"></span>
					</div>
					<div class="pkmb-kpi-content">
						<span class="pkmb-kpi-label"><?php esc_html_e( 'Hard bounces hoy', 'mailpoet-bouncer' ); ?></span>
						<span class="pkmb-kpi-value pkmb-text-danger" id="kpi-val-hard"><?php echo esc_html( number_format_i18n( $kpis['hard_bounces_today'] ) ); ?></span>
					</div>
				</div>

				<!-- KPI 3: Soft bounces hoy -->
				<div class="pkmb-kpi-card" id="pkmb-kpi-soft-today">
					<div class="pkmb-kpi-icon pkmb-kpi-icon-amber">
						<span class="dashicons dashicons-warning"></span>
					</div>
					<div class="pkmb-kpi-content">
						<span class="pkmb-kpi-label"><?php esc_html_e( 'Soft bounces hoy', 'mailpoet-bouncer' ); ?></span>
						<span class="pkmb-kpi-value pkmb-text-warning" id="kpi-val-soft"><?php echo esc_html( number_format_i18n( $kpis['soft_bounces_today'] ) ); ?></span>
					</div>
				</div>

				<!-- KPI 4: Autorespuestas hoy -->
				<div class="pkmb-kpi-card" id="pkmb-kpi-auto-today">
					<div class="pkmb-kpi-icon pkmb-kpi-icon-teal">
						<span class="dashicons dashicons-undo"></span>
					</div>
					<div class="pkmb-kpi-content">
						<span class="pkmb-kpi-label"><?php esc_html_e( 'Autorespuestas ignoradas hoy', 'mailpoet-bouncer' ); ?></span>
						<span class="pkmb-kpi-value pkmb-text-info" id="kpi-val-auto"><?php echo esc_html( number_format_i18n( $kpis['autoresponses_today'] ) ); ?></span>
					</div>
				</div>

				<!-- KPI 5: Errores IMAP 7 días -->
				<div class="pkmb-kpi-card" id="pkmb-kpi-imap-errors">
					<div class="pkmb-kpi-icon pkmb-kpi-icon-purple">
						<span class="dashicons dashicons-networking"></span>
					</div>
					<div class="pkmb-kpi-content">
						<span class="pkmb-kpi-label"><?php esc_html_e( 'Errores IMAP (últimos 7 días)', 'mailpoet-bouncer' ); ?></span>
						<span class="pkmb-kpi-value <?php echo $kpis['imap_errors_7d'] > 0 ? 'pkmb-text-purple' : ''; ?>" id="kpi-val-imap-errors">
							<?php echo esc_html( number_format_i18n( $kpis['imap_errors_7d'] ) ); ?>
						</span>
					</div>
				</div>

				<!-- KPI 6: Última ejecución -->
				<div class="pkmb-kpi-card" id="pkmb-kpi-last-run">
					<div class="pkmb-kpi-icon pkmb-kpi-icon-gray">
						<span class="dashicons dashicons-clock"></span>
					</div>
					<div class="pkmb-kpi-content">
						<span class="pkmb-kpi-label"><?php esc_html_e( 'Última ejecución WP-CLI / Cron', 'mailpoet-bouncer' ); ?></span>
						<div class="pkmb-kpi-subtext" id="kpi-val-last-run-box">
							<strong id="kpi-val-last-date"><?php echo esc_html( $kpis['last_run']['date_formatted'] ); ?></strong>
							<?php if ( $kpis['last_run']['has_run'] ) : ?>
								<span class="pkmb-muted" id="kpi-val-last-time">
									<?php echo esc_html( $kpis['last_run']['time_formatted'] ); ?> (<?php echo esc_html( $kpis['last_run']['duration_formatted'] ); ?>)
								</span>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>

			<!-- Aviso de fallback si Chart.js no carga -->
			<div class="notice notice-warning pkmb-cdn-fallback-notice" id="pkmb-chartjs-fallback" style="display: none;">
				<p>
					<strong><?php esc_html_e( 'Aviso:', 'mailpoet-bouncer' ); ?></strong>
					<?php esc_html_e( 'No se pudo cargar la librería gráfica Chart.js desde la CDN. Las métricas numéricas y el registro detallado continúan funcionando normalmente.', 'mailpoet-bouncer' ); ?>
				</p>
			</div>

			<!-- Estado vacío si aún no hay actividad -->
			<?php if ( ! $hasData ) : ?>
				<div class="pkmb-empty-state" id="pkmb-empty-state-banner">
					<div class="pkmb-empty-state-icon">
						<span class="dashicons dashicons-chart-area"></span>
					</div>
					<h3><?php esc_html_e( 'Aún no hay registros de rebotes procesados', 'mailpoet-bouncer' ); ?></h3>
					<p>
						<?php esc_html_e( 'Los gráficos y estadísticas se actualizarán automáticamente una vez que el comando WP-CLI o la tarea programada analice el buzón IMAP.', 'mailpoet-bouncer' ); ?>
					</p>
					<code>wp mailpoet-bouncer process</code>
				</div>
			<?php endif; ?>

			<!-- Cuadrícula de Gráficos Chart.js -->
			<div class="pkmb-charts-grid" id="pkmb-charts-wrapper" <?php echo ! $hasData ? 'style="opacity: 0.5;"' : ''; ?>>
				<!-- Gráfico 1: Línea (Actividad diaria) -->
				<div class="pkmb-chart-box" id="pkmb-box-chart-line">
					<div class="pkmb-chart-header">
						<h3 class="pkmb-chart-title">
							<span class="dashicons dashicons-chart-line"></span>
							<?php esc_html_e( 'Actividad en el periodo seleccionado', 'mailpoet-bouncer' ); ?>
						</h3>
						<span class="pkmb-badge pkmb-badge-neutral" id="pkmb-line-total-badge">
							<?php
							/* translators: %s: total number of processed emails in period */
							printf( esc_html__( '%s procesados', 'mailpoet-bouncer' ), esc_html( (string) $lineData['total_period'] ) );
							?>
						</span>
					</div>
					<div class="pkmb-chart-canvas-container" style="position: relative; height: 320px;">
						<canvas id="pkmb-chart-line"></canvas>
					</div>
				</div>

				<!-- Gráfico 2: Dona (Distribución de resultados) -->
				<div class="pkmb-chart-box" id="pkmb-box-chart-doughnut">
					<div class="pkmb-chart-header">
						<h3 class="pkmb-chart-title">
							<span class="dashicons dashicons-chart-pie"></span>
							<?php esc_html_e( 'Distribución de resultados', 'mailpoet-bouncer' ); ?>
						</h3>
						<span class="pkmb-badge pkmb-badge-neutral" id="pkmb-doughnut-total-badge">
							<?php
							/* translators: %s: total events in period */
							printf( esc_html__( '%s eventos', 'mailpoet-bouncer' ), esc_html( (string) $doughnut['total'] ) );
							?>
						</span>
					</div>
					<div class="pkmb-chart-canvas-container" style="position: relative; height: 320px;">
						<canvas id="pkmb-chart-doughnut"></canvas>
					</div>
				</div>
			</div>

			<!-- Tabla de Actividad Reciente -->
			<div class="pkmb-recent-section" id="pkmb-recent-activity-section">
				<div class="pkmb-recent-header">
					<h3 class="pkmb-recent-title">
						<span class="dashicons dashicons-list-view"></span>
						<?php esc_html_e( 'Actividad reciente', 'mailpoet-bouncer' ); ?>
					</h3>
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=mailpoet-bouncer&tab=audit' ) ); ?>" class="button button-small" id="pkmb-link-view-all-audit">
						<?php esc_html_e( 'Ver registro completo', 'mailpoet-bouncer' ); ?>
					</a>
				</div>

				<div class="pkmb-table-responsive" id="pkmb-recent-table-wrapper">
					<?php self::renderActivityTable( $activity ); ?>
				</div>
			</div>
		</div>

		<style>
			/* Estilos nativos sobrios y accesibles para wp-admin */
			.pkmb-stats-dashboard {
				margin-top: 18px;
			}
			.pkmb-stats-header {
				display: flex;
				flex-wrap: wrap;
				justify-content: space-between;
				align-items: center;
				gap: 12px;
				margin-bottom: 20px;
				padding-bottom: 12px;
				border-bottom: 1px solid #dcdcde;
			}
			.pkmb-stats-header h2 {
				margin: 0 0 4px 0;
				font-size: 18px;
				color: #1d2327;
			}
			.pkmb-stats-actions {
				display: flex;
				align-items: center;
				gap: 10px;
			}
			.pkmb-kpi-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
				gap: 16px;
				margin-bottom: 24px;
			}
			.pkmb-kpi-card {
				background: #ffffff;
				border: 1px solid #c3c4c7;
				border-radius: 6px;
				padding: 16px;
				display: flex;
				align-items: center;
				gap: 14px;
				box-shadow: 0 1px 2px rgba(0,0,0,0.04);
			}
			.pkmb-kpi-icon {
				width: 44px;
				height: 44px;
				border-radius: 6px;
				display: flex;
				align-items: center;
				justify-content: center;
				flex-shrink: 0;
			}
			.pkmb-kpi-icon .dashicons {
				font-size: 24px;
				width: 24px;
				height: 24px;
			}
			.pkmb-kpi-icon-blue { background: #e0f2fe; color: #0284c7; }
			.pkmb-kpi-icon-red { background: #fee2e2; color: #dc2626; }
			.pkmb-kpi-icon-amber { background: #fef3c7; color: #d97706; }
			.pkmb-kpi-icon-teal { background: #ccfbf1; color: #0f766e; }
			.pkmb-kpi-icon-purple { background: #f3e8ff; color: #7e22ce; }
			.pkmb-kpi-icon-gray { background: #f1f5f9; color: #475569; }

			.pkmb-kpi-content {
				display: flex;
				flex-direction: column;
				min-width: 0;
			}
			.pkmb-kpi-label {
				font-size: 12px;
				font-weight: 500;
				color: #64748b;
				margin-bottom: 2px;
				line-height: 1.3;
			}
			.pkmb-kpi-value {
				font-size: 24px;
				font-weight: 700;
				color: #1e293b;
				line-height: 1.2;
			}
			.pkmb-kpi-subtext {
				font-size: 13px;
				color: #334155;
				display: flex;
				flex-direction: column;
			}
			.pkmb-muted {
				font-size: 11px;
				color: #64748b;
				margin-top: 2px;
			}
			.pkmb-text-danger { color: #dc2626; }
			.pkmb-text-warning { color: #d97706; }
			.pkmb-text-info { color: #0284c7; }
			.pkmb-text-purple { color: #7e22ce; }

			.pkmb-charts-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
				gap: 20px;
				margin-bottom: 28px;
			}
			.pkmb-chart-box {
				background: #ffffff;
				border: 1px solid #c3c4c7;
				border-radius: 6px;
				padding: 18px;
				box-shadow: 0 1px 2px rgba(0,0,0,0.04);
			}
			.pkmb-chart-header {
				display: flex;
				justify-content: space-between;
				align-items: center;
				margin-bottom: 14px;
			}
			.pkmb-chart-title {
				margin: 0;
				font-size: 14px;
				font-weight: 600;
				color: #1e293b;
				display: flex;
				align-items: center;
				gap: 6px;
			}
			.pkmb-chart-title .dashicons {
				color: #2271b1;
			}

			.pkmb-recent-section {
				background: #ffffff;
				border: 1px solid #c3c4c7;
				border-radius: 6px;
				padding: 18px;
				box-shadow: 0 1px 2px rgba(0,0,0,0.04);
			}
			.pkmb-recent-header {
				display: flex;
				justify-content: space-between;
				align-items: center;
				margin-bottom: 14px;
			}
			.pkmb-recent-title {
				margin: 0;
				font-size: 15px;
				font-weight: 600;
				color: #1e293b;
				display: flex;
				align-items: center;
				gap: 6px;
			}
			.pkmb-recent-title .dashicons {
				color: #2271b1;
			}

			.pkmb-badge {
				display: inline-block;
				padding: 2px 8px;
				font-size: 11px;
				font-weight: 600;
				border-radius: 4px;
				white-space: nowrap;
			}
			.pkmb-badge-danger { background: #fee2e2; color: #991b1b; }
			.pkmb-badge-warning { background: #fef3c7; color: #92400e; }
			.pkmb-badge-info { background: #e0f2fe; color: #075985; }
			.pkmb-badge-neutral { background: #f1f5f9; color: #475569; }
			.pkmb-badge-purple { background: #f3e8ff; color: #6b21a8; }
			.pkmb-badge-secondary { background: #f3f4f6; color: #374151; }

			.pkmb-status-success { color: #16a34a; font-weight: 600; }
			.pkmb-status-info { color: #0284c7; font-weight: 600; }
			.pkmb-status-neutral { color: #64748b; }
			.pkmb-status-error { color: #dc2626; font-weight: 600; }

			.pkmb-empty-state {
				background: #f8fafc;
				border: 1px dashed #cbd5e1;
				border-radius: 8px;
				padding: 36px 20px;
				text-align: center;
				margin-bottom: 24px;
			}
			.pkmb-empty-state-icon .dashicons {
				font-size: 40px;
				width: 40px;
				height: 40px;
				color: #94a3b8;
			}
			.pkmb-empty-state h3 {
				margin: 12px 0 6px 0;
				color: #334155;
			}
			.pkmb-empty-state p {
				color: #64748b;
				max-width: 520px;
				margin: 0 auto 12px auto;
			}
			.pkmb-empty-state code {
				background: #e2e8f0;
				padding: 4px 10px;
				border-radius: 4px;
				color: #0f172a;
			}

			@keyframes pkmb-spin {
				from { transform: rotate(0deg); }
				to { transform: rotate(360deg); }
			}
			.pkmb-spinning {
				animation: pkmb-spin 0.8s linear infinite;
			}

			/* Barra tipo Barbería (Barber Pole) */
			.pkmb-barber-pole-wrapper {
				background: #ffffff;
				border: 1px solid #c3c4c7;
				border-left: 4px solid #2271b1;
				border-radius: 4px;
				padding: 14px 18px;
				margin-bottom: 22px;
				box-shadow: 0 1px 3px rgba(0,0,0,0.05);
			}
			.pkmb-barber-pole-header {
				display: flex;
				align-items: center;
				gap: 8px;
				font-size: 13px;
				font-weight: 600;
				color: #1d2327;
				margin-bottom: 10px;
			}
			.pkmb-barber-pole-header .dashicons {
				color: #2271b1;
			}
			.pkmb-barber-pole {
				width: 100%;
				height: 16px;
				border-radius: 8px;
				background-color: #f0f0f1;
				overflow: hidden;
				position: relative;
				box-shadow: inset 0 1px 2px rgba(0,0,0,0.1);
			}
			.pkmb-barber-pole::after {
				content: '';
				position: absolute;
				top: 0;
				left: 0;
				right: 0;
				bottom: 0;
				background-size: 28px 28px;
				background-image: linear-gradient(
					135deg,
					#2271b1 25%,
					#135e96 25%,
					#135e96 50%,
					#2271b1 50%,
					#2271b1 75%,
					#135e96 75%,
					#135e96 100%
				);
				animation: pkmb-barberpole-anim 0.9s linear infinite;
			}
			@keyframes pkmb-barberpole-anim {
				0% { background-position: 0 0; }
				100% { background-position: 28px 0; }
			}
			.pkmb-barber-pole-footer {
				display: flex;
				align-items: center;
				gap: 6px;
				margin-top: 8px;
				font-size: 12px;
				color: #64748b;
			}
			.pkmb-barber-pole-footer .dashicons {
				font-size: 16px;
				width: 16px;
				height: 16px;
				color: #d97706;
			}

			/* Caja de Resultado Final */
			.pkmb-result-box {
				margin: 0 0 22px 0;
				padding: 14px 18px;
				border-radius: 4px;
				background: #ffffff;
				box-shadow: 0 1px 3px rgba(0,0,0,0.05);
			}
			.pkmb-result-box.notice-success { border-left: 4px solid #00a32a; }
			.pkmb-result-box.notice-warning { border-left: 4px solid #dba617; }
			.pkmb-result-box.notice-error   { border-left: 4px solid #d63638; }

			.pkmb-result-header {
				display: flex;
				justify-content: space-between;
				align-items: center;
				margin-bottom: 6px;
			}
			.pkmb-result-title-group {
				display: flex;
				align-items: center;
				gap: 8px;
				font-size: 14px;
				color: #1d2327;
			}
			.pkmb-result-box.notice-success .pkmb-result-title-group .dashicons { color: #00a32a; }
			.pkmb-result-box.notice-warning .pkmb-result-title-group .dashicons { color: #dba617; }
			.pkmb-result-box.notice-error   .pkmb-result-title-group .dashicons { color: #d63638; }

			.pkmb-result-close {
				background: none;
				border: none;
				cursor: pointer;
				color: #787c82;
				padding: 4px;
				line-height: 1;
				border-radius: 3px;
			}
			.pkmb-result-close:hover {
				color: #d63638;
				background: #f0f0f1;
			}
			.pkmb-result-message {
				margin: 4px 0 12px 0;
				font-size: 13px;
				color: #334155;
			}
			.pkmb-result-summary {
				display: flex;
				flex-wrap: wrap;
				gap: 8px;
				margin-top: 10px;
			}
			.pkmb-summary-pill {
				display: inline-flex;
				align-items: center;
				gap: 4px;
				padding: 4px 10px;
				border-radius: 4px;
				font-size: 12px;
				background: #f1f5f9;
				color: #334155;
				border: 1px solid #e2e8f0;
			}
			.pkmb-pill-danger  { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
			.pkmb-pill-warning { background: #fef3c7; color: #92400e; border-color: #fde68a; }
			.pkmb-pill-info    { background: #e0f2fe; color: #075985; border-color: #bae6fd; }
			.pkmb-pill-neutral { background: #f8fafc; color: #475569; border-color: #cbd5e1; }
			.pkmb-pill-purple  { background: #f3e8ff; color: #6b21a8; border-color: #e9d5ff; }
			.pkmb-pill-gray    { background: #f1f5f9; color: #1e293b; border-color: #cbd5e1; }

			.pkmb-result-details {
				margin-top: 12px;
				padding-top: 10px;
				border-top: 1px dashed #e2e8f0;
			}
			.pkmb-result-details summary {
				cursor: pointer;
				font-size: 12px;
				color: #2271b1;
				font-weight: 500;
			}
			.pkmb-result-details ul {
				margin: 8px 0 0 18px;
				font-family: monospace;
				font-size: 11px;
				color: #64748b;
				list-style-type: disc;
			}
		</style>
		<?php
	}

	/**
	 * Renderiza la tabla HTML de actividad reciente para reutilizar en vistas y respuestas AJAX.
	 *
	 * @param array<int, array<string, mixed>> $activity
	 */
	public static function renderActivityTable( array $activity ): void {
		?>
		<table class="wp-list-table widefat striped table-view-list" id="pkmb-recent-table">
			<thead>
				<tr>
					<th scope="col" style="width: 155px;"><?php esc_html_e( 'Fecha / Hora', 'mailpoet-bouncer' ); ?></th>
					<th scope="col" style="width: 140px;"><?php esc_html_e( 'Resultado / Evento', 'mailpoet-bouncer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Dirección de correo', 'mailpoet-bouncer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Diagnóstico resumido', 'mailpoet-bouncer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Acción aplicada', 'mailpoet-bouncer' ); ?></th>
					<th scope="col" style="width: 110px;"><?php esc_html_e( 'Estado', 'mailpoet-bouncer' ); ?></th>
				</tr>
			</thead>
			<tbody id="pkmb-recent-tbody">
				<?php if ( empty( $activity ) ) : ?>
					<tr>
						<td colspan="6" style="text-align: center; padding: 24px; color: #64748b;">
							<em><?php esc_html_e( 'Sin registros recientes en el log de auditoría.', 'mailpoet-bouncer' ); ?></em>
						</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $activity as $row ) : ?>
						<tr>
							<td>
								<span style="font-family: monospace; font-size: 12px; color: #475569;">
									<?php echo esc_html( (string) $row['datetime'] ); ?>
								</span>
							</td>
							<td>
								<span class="pkmb-badge <?php echo esc_attr( (string) $row['type_badge_class'] ); ?>">
									<?php echo esc_html( (string) $row['type_label'] ); ?>
								</span>
							</td>
							<td>
								<strong><?php echo esc_html( (string) $row['email'] ); ?></strong>
							</td>
							<td>
								<span title="<?php echo esc_attr( (string) $row['diagnostic_short'] ); ?>">
									<?php echo esc_html( (string) $row['diagnostic_short'] ); ?>
								</span>
							</td>
							<td>
								<?php echo esc_html( (string) $row['action_label'] ); ?>
							</td>
							<td>
								<span class="<?php echo esc_attr( (string) $row['status_badge_class'] ); ?>">
									<?php echo esc_html( (string) $row['status_label'] ); ?>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}
}
