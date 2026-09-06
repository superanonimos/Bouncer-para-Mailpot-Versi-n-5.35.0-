<?php
declare(strict_types=1);

namespace Publikate\MailPoetBouncer\Admin;

use Publikate\MailPoetBouncer\Audit\Logger;
use Publikate\MailPoetBouncer\Audit\StatsRepository;
use Publikate\MailPoetBouncer\Bounce\ProcessorService;
use Publikate\MailPoetBouncer\IMAP\ClientFactory;
use Publikate\MailPoetBouncer\Support\Requirements;
use Publikate\MailPoetBouncer\Support\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renderizador y controlador de la página de ajustes en wp-admin.
 */
final class SettingsPage {

	public const PAGE_SLUG = 'mailpoet-bouncer';

	private static string $pageHook = '';

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'addMenu' ] );
		add_action( 'admin_init', [ Settings::class, 'register' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueueAssets' ] );
		add_action( 'wp_ajax_pkmb_test_connection', [ self::class, 'ajaxTestConnection' ] );
		add_action( 'wp_ajax_pkmb_refresh_stats', [ self::class, 'ajaxRefreshStats' ] );
		add_action( 'wp_ajax_pkmb_manual_process', [ self::class, 'ajaxManualProcess' ] );
	}

	public static function addMenu(): void {
		self::$pageHook = (string) add_options_page(
			__( 'MailPoet Bouncer', 'mailpoet-bouncer' ),
			__( 'MailPoet Bouncer', 'mailpoet-bouncer' ),
			'manage_options',
			self::PAGE_SLUG,
			[ self::class, 'render' ]
		);
	}

	/**
	 * Encola scripts y estilos específicos de la pantalla de MailPoet Bouncer.
	 */
	public static function enqueueAssets( string $hookSuffix ): void {
		if ( $hookSuffix !== self::$pageHook && $hookSuffix !== 'settings_page_' . self::PAGE_SLUG ) {
			return;
		}

		$activeTab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'stats';
		if ( 'stats' !== $activeTab ) {
			return;
		}

		// 1. Chart.js cargado localmente (cero dependencias de CDNs externas)
		$chartJsUrl = plugin_dir_url( dirname( __DIR__ ) ) . 'assets/js/chart.umd.min.js';
		wp_register_script(
			'pkmb-chartjs',
			$chartJsUrl,
			[],
			PKMB_VERSION,
			true
		);

		// 2. Script local del panel de estadísticas
		$scriptUrl = plugin_dir_url( dirname( __DIR__ ) ) . 'assets/js/stats.js';
		wp_register_script(
			'pkmb-admin-stats',
			$scriptUrl,
			[ 'pkmb-chartjs' ],
			PKMB_VERSION,
			true
		);

		$initialPeriod = 30;
		$lineData      = StatsRepository::getLineChartData( $initialPeriod );
		$doughnutData  = StatsRepository::getDoughnutData( $initialPeriod );

		wp_localize_script(
			'pkmb-admin-stats',
			'pkmbStatsConfig',
			[
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'pkmb_stats_nonce' ),
				'lineData'     => $lineData,
				'doughnutData' => $doughnutData,
				'strings'      => [
					'btnProcessDefault' => __( 'Procesar ahora', 'mailpoet-bouncer' ),
					'btnProcessing'     => __( 'Procesando...', 'mailpoet-bouncer' ),
					'btnRefreshDefault' => __( 'Actualizar estadísticas', 'mailpoet-bouncer' ),
					'stepConnecting'    => __( 'Conectando con el buzón…', 'mailpoet-bouncer' ),
					'stepFetching'      => __( 'Procesando mensajes…', 'mailpoet-bouncer' ),
					'stepClassifying'   => __( 'Clasificando rebotes…', 'mailpoet-bouncer' ),
					'stepUpdating'      => __( 'Actualizando suscriptores…', 'mailpoet-bouncer' ),
					'stepTakingLonger'  => __( 'La operación está tardando más de lo normal...', 'mailpoet-bouncer' ),
					'stepBatchProgress' => __( 'Procesando lote %1$d (mensajes acumulados: %2$d)…', 'mailpoet-bouncer' ),
					'ajaxTimeout'       => __( 'Error de conectividad o tiempo límite excedido al intentar procesar.', 'mailpoet-bouncer' ),
					'errNonce'          => __( 'Sesión expirada o token de seguridad inválido. Por favor, recargue la página.', 'mailpoet-bouncer' ),
					'errForbidden'      => __( 'Permisos insuficientes. Requiere privilegios de administrador para ejecutar esta acción.', 'mailpoet-bouncer' ),
					'errNotFound'       => __( 'Acción AJAX no encontrada o callback ausente en el servidor.', 'mailpoet-bouncer' ),
					'errPhp'            => __( 'Error interno de PHP en el servidor al ejecutar la operación.', 'mailpoet-bouncer' ),
					'errImap'           => __( 'Error de conexión o protocolo IMAP con el servidor de correo.', 'mailpoet-bouncer' ),
					'errJson'           => __( 'Respuesta no válida del servidor (formato JSON corrupto o inesperado).', 'mailpoet-bouncer' ),
				],
			]
		);

		wp_enqueue_script( 'pkmb-chartjs' );
		wp_enqueue_script( 'pkmb-admin-stats' );
	}

	/**
	 * Renderiza la interfaz de usuario de ajustes.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tiene permisos suficientes para acceder a esta página.', 'mailpoet-bouncer' ) );
		}

		$options   = Settings::getAll();
		$reqChecks = Requirements::check();
		$activeTab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'stats';

		?>
		<div class="wrap pkmb-wrap" id="pkmb-admin-app">
			<h1 id="pkmb-title"><?php esc_html_e( 'MailPoet Bouncer — Gestión Segura de Rebotes', 'mailpoet-bouncer' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Arquitectura aislada para procesamiento desatendido de rebotes (Hard Bounces) para MailPoet 5.31+.', 'mailpoet-bouncer' ); ?>
			</p>

			<?php settings_errors(); ?>

			<?php if ( ! empty( $reqChecks['errors'] ) ) : ?>
				<div class="notice notice-error" id="pkmb-requirements-errors">
					<p><strong><?php esc_html_e( 'Requisitos no cumplidos:', 'mailpoet-bouncer' ); ?></strong></p>
					<ul>
						<?php foreach ( $reqChecks['errors'] as $err ) : ?>
							<li><?php echo esc_html( $err ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $reqChecks['warnings'] ) ) : ?>
				<div class="notice notice-warning" id="pkmb-requirements-warnings">
					<p><strong><?php esc_html_e( 'Advertencias del sistema:', 'mailpoet-bouncer' ); ?></strong></p>
					<ul>
						<?php foreach ( $reqChecks['warnings'] as $warn ) : ?>
							<li><?php echo esc_html( $warn ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper" id="pkmb-nav-tabs">
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=stats' ) ); ?>" class="nav-tab <?php echo 'stats' === $activeTab ? 'nav-tab-active' : ''; ?>" id="pkmb-tab-link-stats">
					<?php esc_html_e( 'Estadísticas', 'mailpoet-bouncer' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=settings' ) ); ?>" class="nav-tab <?php echo 'settings' === $activeTab ? 'nav-tab-active' : ''; ?>" id="pkmb-tab-link-settings">
					<?php esc_html_e( 'Configuración IMAP', 'mailpoet-bouncer' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=test' ) ); ?>" class="nav-tab <?php echo 'test' === $activeTab ? 'nav-tab-active' : ''; ?>" id="pkmb-tab-link-test">
					<?php esc_html_e( 'Prueba de Conexión', 'mailpoet-bouncer' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=audit' ) ); ?>" class="nav-tab <?php echo 'audit' === $activeTab ? 'nav-tab-active' : ''; ?>" id="pkmb-tab-link-audit">
					<?php esc_html_e( 'Registro de Auditoría', 'mailpoet-bouncer' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=cron' ) ); ?>" class="nav-tab <?php echo 'cron' === $activeTab ? 'nav-tab-active' : ''; ?>" id="pkmb-tab-link-cron">
					<?php esc_html_e( 'Cron del Sistema / CLI', 'mailpoet-bouncer' ); ?>
				</a>
			</nav>

			<div class="pkmb-content-body" id="pkmb-tab-content">
				<?php if ( 'stats' === $activeTab ) : ?>
					<?php StatsRenderer::render(); ?>
				<?php elseif ( 'settings' === $activeTab ) : ?>
					<form method="post" action="options.php" id="pkmb-settings-form">
						<?php settings_fields( Settings::GROUP_KEY ); ?>

						<table class="form-table" role="presentation" id="pkmb-table-settings">
							<tr>
								<th scope="row">
									<label for="pkmb_host"><?php esc_html_e( 'Servidor IMAP (Host)', 'mailpoet-bouncer' ); ?></label>
								</th>
								<td>
									<input name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[host]" type="text" id="pkmb_host" value="<?php echo esc_attr( $options['host'] ); ?>" class="regular-text" placeholder="mail.ejemplo.com" required />
									<p class="description"><?php esc_html_e( 'Nombre de dominio o IP del servidor de correo donde se reciben los rebotes.', 'mailpoet-bouncer' ); ?></p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="pkmb_port"><?php esc_html_e( 'Puerto', 'mailpoet-bouncer' ); ?></label>
								</th>
								<td>
									<input name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[port]" type="number" id="pkmb_port" value="<?php echo esc_attr( (string) $options['port'] ); ?>" class="small-text" min="1" max="65535" required />
									<p class="description"><?php esc_html_e( 'Puerto estándar: 993 para SSL/TLS, 143 para STARTTLS/None.', 'mailpoet-bouncer' ); ?></p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="pkmb_encryption"><?php esc_html_e( 'Cifrado', 'mailpoet-bouncer' ); ?></label>
								</th>
								<td>
									<select name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[encryption]" id="pkmb_encryption">
										<option value="ssl" <?php selected( $options['encryption'], 'ssl' ); ?>>SSL / TLS (Recomendado, puerto 993)</option>
										<option value="tls" <?php selected( $options['encryption'], 'tls' ); ?>>STARTTLS</option>
										<option value="none" <?php selected( $options['encryption'], 'none' ); ?>>Sin cifrado (Inseguro)</option>
									</select>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e( 'Validar certificado SSL/TLS', 'mailpoet-bouncer' ); ?></th>
								<td>
									<label for="pkmb_validate_cert">
										<input name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[validate_cert]" type="checkbox" id="pkmb_validate_cert" value="1" <?php checked( ! empty( $options['validate_cert'] ), true ); ?> />
										<?php esc_html_e( 'Validar certificado del servidor (Recomendado). Desmarcar solo si usa certificados autofirmados en el VPS.', 'mailpoet-bouncer' ); ?>
									</label>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="pkmb_username"><?php esc_html_e( 'Usuario / Correo', 'mailpoet-bouncer' ); ?></label>
								</th>
								<td>
									<input name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[username]" type="text" id="pkmb_username" value="<?php echo esc_attr( $options['username'] ); ?>" class="regular-text" placeholder="rebotes@ejemplo.com" required />
									<p class="description"><?php esc_html_e( 'Cuenta de correo asignada para capturar los mensajes de retorno/rebote.', 'mailpoet-bouncer' ); ?></p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="pkmb_password"><?php esc_html_e( 'Contraseña', 'mailpoet-bouncer' ); ?></label>
								</th>
								<td>
									<input name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[password]" type="password" id="pkmb_password" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo ! empty( $options['password'] ) ? '••••••••••••' : ''; ?>" />
									<p class="description"><?php esc_html_e( 'Dejar en blanco para mantener la contraseña actual guardada.', 'mailpoet-bouncer' ); ?></p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="pkmb_folder"><?php esc_html_e( 'Carpeta IMAP', 'mailpoet-bouncer' ); ?></label>
								</th>
								<td>
									<input name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[folder]" type="text" id="pkmb_folder" value="<?php echo esc_attr( $options['folder'] ); ?>" class="regular-text" />
									<p class="description"><?php esc_html_e( 'Normalmente "INBOX".', 'mailpoet-bouncer' ); ?></p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="pkmb_batch_size"><?php esc_html_e( 'Tamaño de lote (Batch)', 'mailpoet-bouncer' ); ?></label>
								</th>
								<td>
									<input name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[batch_size]" type="number" id="pkmb_batch_size" value="<?php echo esc_attr( (string) $options['batch_size'] ); ?>" class="small-text" min="1" max="500" />
									<p class="description"><?php esc_html_e( 'Cantidad máxima de correos a procesar en cada ejecución (Recomendado: 50).', 'mailpoet-bouncer' ); ?></p>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e( 'Modo Simulación (Dry-Run)', 'mailpoet-bouncer' ); ?></th>
								<td>
									<label for="pkmb_dry_run">
										<input name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[dry_run]" type="checkbox" id="pkmb_dry_run" value="1" <?php checked( $options['dry_run'], 1 ); ?> />
										<?php esc_html_e( 'Activar modo de prueba (analiza rebotes y registra en auditoría sin modificar suscriptores en MailPoet).', 'mailpoet-bouncer' ); ?>
									</label>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e( 'Eliminar tras procesar', 'mailpoet-bouncer' ); ?></th>
								<td>
									<label for="pkmb_delete_after_process">
										<input name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[delete_after_process]" type="checkbox" id="pkmb_delete_after_process" value="1" <?php checked( $options['delete_after_process'], 1 ); ?> />
										<?php esc_html_e( 'Eliminar definitivamente del buzón IMAP los correos procesados satisfactoriamente.', 'mailpoet-bouncer' ); ?>
									</label>
								</td>
							</tr>
						</table>

						<?php submit_button( __( 'Guardar Configuración', 'mailpoet-bouncer' ) ); ?>
					</form>

				<?php elseif ( 'test' === $activeTab ) : ?>
					<div class="card" style="max-width: 800px; padding: 20px; margin-top: 15px;" id="pkmb-card-test">
						<h2><?php esc_html_e( 'Comprobar Conectividad IMAP', 'mailpoet-bouncer' ); ?></h2>
						<p><?php esc_html_e( 'Pulse el botón a continuación para verificar los parámetros de conexión y autenticación guardados actualmente.', 'mailpoet-bouncer' ); ?></p>

						<button type="button" class="button button-primary" id="pkmb-btn-test-connection">
							<?php esc_html_e( 'Ejecutar Prueba de Conexión', 'mailpoet-bouncer' ); ?>
						</button>

						<div id="pkmb-test-result" style="margin-top: 15px; display: none;"></div>
					</div>

				<?php elseif ( 'audit' === $activeTab ) : ?>
					<?php
					$pageNumber = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
					$perPage    = 25;
					$offset     = ( $pageNumber - 1 ) * $perPage;
					$logs       = Logger::getRecent( $perPage, $offset );
					$totalLogs  = Logger::countTotal();
					$totalPages = ceil( $totalLogs / $perPage );
					?>
					<div style="margin-top: 15px;" id="pkmb-audit-section">
						<p><?php printf( esc_html__( 'Total de eventos registrados: %d', 'mailpoet-bouncer' ), $totalLogs ); ?></p>

						<table class="widefat fixed striped" id="pkmb-audit-table">
							<thead>
								<tr>
									<th style="width: 140px;"><?php esc_html_e( 'Fecha y Hora', 'mailpoet-bouncer' ); ?></th>
									<th><?php esc_html_e( 'Destinatario', 'mailpoet-bouncer' ); ?></th>
									<th style="width: 100px;"><?php esc_html_e( 'Tipo', 'mailpoet-bouncer' ); ?></th>
									<th style="width: 80px;"><?php esc_html_e( 'Código DSN', 'mailpoet-bouncer' ); ?></th>
									<th style="width: 160px;"><?php esc_html_e( 'Acción Realizada', 'mailpoet-bouncer' ); ?></th>
									<th><?php esc_html_e( 'Diagnóstico / Motivo', 'mailpoet-bouncer' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php if ( empty( $logs ) ) : ?>
									<tr>
										<td colspan="6" style="text-align: center; padding: 20px;">
											<?php esc_html_e( 'No hay registros de rebotes aún en la base de datos.', 'mailpoet-bouncer' ); ?>
										</td>
									</tr>
								<?php else : ?>
									<?php foreach ( $logs as $row ) : ?>
										<tr>
											<td><?php echo esc_html( $row->created_at ); ?></td>
											<td><strong><?php echo esc_html( Security::maskEmail( (string) $row->email ) ); ?></strong></td>
											<td>
												<?php
												$typeClass = match ( $row->bounce_type ) {
													'hard'          => 'color: #d63638; font-weight: bold;',
													'soft'          => 'color: #dba617;',
													'autoresponder' => 'color: #2271b1;',
													default         => 'color: #646970;',
												};
												?>
												<span style="<?php echo esc_attr( $typeClass ); ?>"><?php echo esc_html( ucfirst( $row->bounce_type ) ); ?></span>
											</td>
											<td><code><?php echo esc_html( $row->dsn_code ?: '—' ); ?></code></td>
											<td><code><?php echo esc_html( $row->action_taken ); ?></code></td>
											<td><?php echo esc_html( $row->diagnostic_message ?: '—' ); ?></td>
										</tr>
									<?php endforeach; ?>
								<?php endif; ?>
							</tbody>
						</table>

						<?php if ( $totalPages > 1 ) : ?>
							<div class="tablenav" id="pkmb-pagination">
								<div class="tablenav-pages">
									<span class="pagination-links">
										<?php
										echo paginate_links( [
											'base'      => add_query_arg( 'paged', '%#%' ),
											'format'    => '',
											'prev_text' => '&laquo;',
											'next_text' => '&raquo;',
											'total'     => $totalPages,
											'current'   => $pageNumber,
										] );
										?>
									</span>
								</div>
							</div>
						<?php endif; ?>
					</div>

				<?php elseif ( 'cron' === $activeTab ) : ?>
					<div class="card" style="max-width: 850px; padding: 20px; margin-top: 15px;" id="pkmb-card-cron">
						<h2><?php esc_html_e( 'Automatización en CWP / AlmaLinux 9 vía Cron del Sistema', 'mailpoet-bouncer' ); ?></h2>
						<p>
							<?php esc_html_e( 'Para evitar bloqueos de peticiones web y garantizar una ejecución segura y aislada, el procesamiento de rebotes debe ejecutarse mediante WP-CLI cada 5 minutos.', 'mailpoet-bouncer' ); ?>
						</p>

						<h3><?php esc_html_e( 'Línea de Crontab recomendada (cada 5 minutos):', 'mailpoet-bouncer' ); ?></h3>
						<pre style="background: #f0f0f1; padding: 12px; border-radius: 4px; overflow-x: auto;"><code>*/5 * * * * /usr/local/bin/wp mailpoet-bouncer process --path=<?php echo esc_attr( ABSPATH ); ?> > /dev/null 2>&1</code></pre>

						<h3><?php esc_html_e( 'Comandos WP-CLI disponibles:', 'mailpoet-bouncer' ); ?></h3>
						<ul>
							<li><code>wp mailpoet-bouncer process</code> — <?php esc_html_e( 'Procesa el lote de rebotes según los ajustes guardados.', 'mailpoet-bouncer' ); ?></li>
							<li><code>wp mailpoet-bouncer process --dry-run</code> — <?php esc_html_e( 'Fuerza el modo simulación (sin alterar MailPoet).', 'mailpoet-bouncer' ); ?></li>
							<li><code>wp mailpoet-bouncer process --limit=100</code> — <?php esc_html_e( 'Ajusta el límite de correos procesados en esta corrida.', 'mailpoet-bouncer' ); ?></li>
						</ul>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<script>
		document.addEventListener('DOMContentLoaded', function() {
			var btnTest = document.getElementById('pkmb-btn-test-connection');
			var resultBox = document.getElementById('pkmb-test-result');

			if (!btnTest || !resultBox) return;

			btnTest.addEventListener('click', function() {
				btnTest.disabled = true;
				btnTest.textContent = '<?php echo esc_js( __( 'Conectando...', 'mailpoet-bouncer' ) ); ?>';
				resultBox.style.display = 'block';
				resultBox.className = 'notice notice-info';
				resultBox.textContent = '';
				var pInfo = document.createElement('p');
				pInfo.textContent = '<?php echo esc_js( __( 'Iniciando verificación de conexión IMAP...', 'mailpoet-bouncer' ) ); ?>';
				resultBox.appendChild(pInfo);

				var formData = new FormData();
				formData.append('action', 'pkmb_test_connection');
				formData.append('nonce', '<?php echo esc_js( wp_create_nonce( 'pkmb_test_connection_nonce' ) ); ?>');

				fetch(ajaxurl, {
					method: 'POST',
					body: formData
				})
				.then(function(res) { return res.json(); })
				.then(function(data) {
					btnTest.disabled = false;
					btnTest.textContent = '<?php echo esc_js( __( 'Ejecutar Prueba de Conexión', 'mailpoet-bouncer' ) ); ?>';
					resultBox.textContent = '';
					var p = document.createElement('p');
					var strong = document.createElement('strong');

					if (data.success) {
						resultBox.className = 'notice notice-success';
						strong.textContent = '<?php echo esc_js( __( 'Éxito: ', 'mailpoet-bouncer' ) ); ?>';
						p.appendChild(strong);
						var textSuccess = (data.data && data.data.message) ? data.data.message : '<?php echo esc_js( __( 'Conexión IMAP establecida con éxito.', 'mailpoet-bouncer' ) ); ?>';
						p.appendChild(document.createTextNode(textSuccess));
					} else {
						resultBox.className = 'notice notice-error';
						strong.textContent = '<?php echo esc_js( __( 'Fallo: ', 'mailpoet-bouncer' ) ); ?>';
						p.appendChild(strong);
						var textError = (data.data && data.data.message) ? data.data.message : '<?php echo esc_js( __( 'Error desconocido.', 'mailpoet-bouncer' ) ); ?>';
						p.appendChild(document.createTextNode(textError));
					}
					resultBox.appendChild(p);
				})
				.catch(function(err) {
					btnTest.disabled = false;
					btnTest.textContent = '<?php echo esc_js( __( 'Ejecutar Prueba de Conexión', 'mailpoet-bouncer' ) ); ?>';
					resultBox.className = 'notice notice-error';
					resultBox.textContent = '';
					var p = document.createElement('p');
					var strong = document.createElement('strong');
					strong.textContent = '<?php echo esc_js( __( 'Error de red: ', 'mailpoet-bouncer' ) ); ?>';
					p.appendChild(strong);
					p.appendChild(document.createTextNode(err.message || 'Error en la petición.'));
					resultBox.appendChild(p);
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Endpoint AJAX para probar conexión IMAP desde la administración de forma segura.
	 */
	public static function ajaxTestConnection(): void {
		check_ajax_referer( 'pkmb_test_connection_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Acción no autorizada. Requiere permisos de administrador.', 'mailpoet-bouncer' ) ] );
		}

		$options = Settings::getAll();

		if ( empty( $options['host'] ) || empty( $options['username'] ) || empty( $options['password'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Debe configurar y guardar el servidor, usuario y contraseña antes de probar la conexión.', 'mailpoet-bouncer' ) ] );
		}

		$configuredPassword = (string) ( $options['password'] ?? '' );

		try {
			$client = ClientFactory::create( $options );
			$result = $client->testConnection();

			if ( $result['success'] ) {
				$safeSuccess = Security::maskSensitiveData( (string) $result['message'], $configuredPassword );
				wp_send_json_success( [ 'message' => sanitize_text_field( $safeSuccess ) ] );
			} else {
				$safeFailure = Security::sanitizeErrorMessage( (string) $result['message'], $configuredPassword );
				wp_send_json_error( [ 'message' => $safeFailure ] );
			}
		} catch ( \Throwable $e ) {
			$safeError = Security::sanitizeErrorMessage( $e->getMessage(), $configuredPassword );
			wp_send_json_error( [ 'message' => $safeError ] );
		}
	}

	/**
	 * Endpoint AJAX para refrescar métricas, gráficos y actividad reciente en vivo.
	 */
	public static function ajaxRefreshStats(): void {
		check_ajax_referer( 'pkmb_stats_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Acción no autorizada.', 'mailpoet-bouncer' ) ] );
		}

		$rawPeriod = isset( $_POST['period'] ) ? wp_unslash( $_POST['period'] ) : 30;
		$period    = Security::clampBatchSize( $rawPeriod, 7, 30, 30 );

		$rawKpis = StatsRepository::getKpis();
		$kpis    = [
			'processed_today'     => number_format_i18n( $rawKpis['processed_today'] ),
			'hard_bounces_today'  => number_format_i18n( $rawKpis['hard_bounces_today'] ),
			'soft_bounces_today'  => number_format_i18n( $rawKpis['soft_bounces_today'] ),
			'autoresponses_today' => number_format_i18n( $rawKpis['autoresponses_today'] ),
			'imap_errors_7d'      => number_format_i18n( $rawKpis['imap_errors_7d'] ),
			'last_run'            => $rawKpis['last_run'],
		];

		$lineData     = StatsRepository::getLineChartData( $period );
		$doughnutData = StatsRepository::getDoughnutData( $period );
		$activity     = StatsRepository::getRecentActivity( 15 );

		ob_start();
		StatsRenderer::renderActivityTable( $activity );
		$tableHtml = (string) ob_get_clean();

		wp_send_json_success( [
			'kpis'         => $kpis,
			'lineData'     => $lineData,
			'doughnutData' => $doughnutData,
			'tableHtml'    => $tableHtml,
		] );
	}

	/**
	 * Endpoint AJAX para la ejecución manual del procesamiento de rebotes.
	 *
	 * Endurecido con:
	 * - Verificación de capability manage_options.
	 * - Verificación de nonce de sesión.
	 * - Protección contra ráfagas de solicitudes y concurrencia.
	 * - Validación defensiva de parámetros de batching (limit, dry_run).
	 * - Ocultación y enmascaramiento total de secretos en respuestas.
	 */
	public static function ajaxManualProcess(): void {
		check_ajax_referer( 'pkmb_stats_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [
				'status'  => 'error',
				'message' => __( 'Acción no autorizada. Requiere permisos de administrador.', 'mailpoet-bouncer' ),
				'details' => [],
				'summary' => ProcessorService::emptyStats(),
				'last_run' => [
					'started_at'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
					'finished_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				],
			] );
		}

		// Cooldown transitorio por usuario administrador (3 segundos para evitar doble click accidental)
		// No se aplica a lotes continuos del mismo proceso secuencial identificados por cursor
		$isSubsequentBatch = isset( $_POST['cursor'] ) && '' !== trim( (string) $_POST['cursor'] );
		$userId            = get_current_user_id();
		$cooldownKey       = 'pkmb_ajax_cooldown_' . $userId;

		if ( ! $isSubsequentBatch && get_transient( $cooldownKey ) ) {
			wp_send_json_error( [
				'status'   => 'warning',
				'message'  => __( 'Por favor, espere unos instantes antes de iniciar otra ejecución manual.', 'mailpoet-bouncer' ),
				'details'  => [ __( 'Petición limitada temporalmente por protección contra sobrecarga.', 'mailpoet-bouncer' ) ],
				'summary'  => ProcessorService::emptyStats(),
				'last_run' => [
					'started_at'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
					'finished_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				],
			] );
		}
		if ( ! $isSubsequentBatch ) {
			set_transient( $cooldownKey, 1, 3 );
		}

		// Validación y sanitización estricta de parámetros opcionales de lote
		$limit = null;
		if ( isset( $_POST['limit'] ) && '' !== trim( (string) $_POST['limit'] ) ) {
			$rawLimit = wp_unslash( $_POST['limit'] );
			$limit    = Security::clampBatchSize(
				$rawLimit,
				ProcessorService::LIMIT_MIN,
				ProcessorService::LIMIT_MAX,
				50
			);
		}

		$dryRun = null;
		if ( isset( $_POST['dry_run'] ) && '' !== trim( (string) $_POST['dry_run'] ) ) {
			$rawDryRun = wp_unslash( $_POST['dry_run'] );
			$dryRun    = (bool) absint( $rawDryRun );
		}

		// Ejecución centralizada y segura mediante ProcessorService
		$result = ProcessorService::process(
			limitOverride: $limit,
			dryRunOverride: $dryRun
		);

		// Recuperación de métricas actualizadas para refresco en tiempo real
		$rawPeriod = isset( $_POST['period'] ) ? wp_unslash( $_POST['period'] ) : 30;
		$period    = Security::clampBatchSize( $rawPeriod, 7, 30, 30 );

		$rawKpis = StatsRepository::getKpis();
		$kpis    = [
			'processed_today'     => number_format_i18n( $rawKpis['processed_today'] ),
			'hard_bounces_today'  => number_format_i18n( $rawKpis['hard_bounces_today'] ),
			'soft_bounces_today'  => number_format_i18n( $rawKpis['soft_bounces_today'] ),
			'autoresponses_today' => number_format_i18n( $rawKpis['autoresponses_today'] ),
			'imap_errors_7d'      => number_format_i18n( $rawKpis['imap_errors_7d'] ),
			'last_run'            => $rawKpis['last_run'],
		];

		$lineData     = StatsRepository::getLineChartData( $period );
		$doughnutData = StatsRepository::getDoughnutData( $period );
		$activity     = StatsRepository::getRecentActivity( 15 );

		ob_start();
		StatsRenderer::renderActivityTable( $activity );
		$tableHtml = (string) ob_get_clean();

		$payload = array_merge( $result, [
			'fresh_data' => [
				'kpis'         => $kpis,
				'lineData'     => $lineData,
				'doughnutData' => $doughnutData,
				'tableHtml'    => $tableHtml,
			],
		] );

		if ( $result['success'] ) {
			wp_send_json_success( $payload );
		} else {
			if ( 'warning' === $result['status'] ) {
				wp_send_json_success( $payload );
			} else {
				wp_send_json_error( $payload );
			}
		}
	}
}


