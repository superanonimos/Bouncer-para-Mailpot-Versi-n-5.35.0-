/**
 * MailPoet Bouncer - Panel de Estadísticas, Gráficos y Procesamiento Manual (wp-admin)
 * Implementación vanilla en español, estilo nativo de WordPress.
 */
(function() {
  'use strict';

  let lineChartInstance = null;
  let doughnutChartInstance = null;

  document.addEventListener('DOMContentLoaded', function() {
    const dashboard = document.getElementById('pkmb-stats-dashboard');
    if (!dashboard) {
      return;
    }

    const config = window.pkmbStatsConfig || {};
    const strings = config.strings || {};

    // Elementos principales de la vista
    const fallbackNotice       = document.getElementById('pkmb-chartjs-fallback');
    const periodSelector       = document.getElementById('pkmb-period-selector');
    const btnRefresh           = document.getElementById('pkmb-btn-refresh-stats');
    const refreshIcon          = document.getElementById('pkmb-refresh-icon');
    const refreshText          = document.getElementById('pkmb-refresh-text');

    // Elementos del procesamiento manual
    const btnProcess           = document.getElementById('pkmb-btn-process-now');
    const processIcon          = document.getElementById('pkmb-process-btn-icon');
    const processText          = document.getElementById('pkmb-process-btn-text');
    const progressContainer    = document.getElementById('pkmb-process-progress-container');
    const stepText             = document.getElementById('pkmb-process-step-text');
    const longerMsg            = document.getElementById('pkmb-process-longer-msg');

    // Elementos de la caja de resultado
    const resultBox            = document.getElementById('pkmb-process-result-box');
    const resultTitle          = document.getElementById('pkmb-result-title');
    const resultIcon           = document.getElementById('pkmb-result-icon');
    const resultMessage        = document.getElementById('pkmb-result-message');
    const resultSummary        = document.getElementById('pkmb-result-summary');
    const resultDetails        = document.getElementById('pkmb-result-details');
    const resultDetailsList    = document.getElementById('pkmb-result-details-list');
    const btnCloseResult       = document.getElementById('pkmb-btn-close-result');

    // 1. Verificación de Chart.js desde CDN
    const isChartAvailable = typeof window.Chart !== 'undefined';
    if (!isChartAvailable) {
      if (fallbackNotice) {
        fallbackNotice.style.display = 'block';
      }
      console.warn('MailPoet Bouncer: Chart.js no se cargó correctamente desde el CDN. Las estadísticas tabulares continúan operativas.');
    } else {
      initCharts(config.lineData || {}, config.doughnutData || {});
    }

    // 2. Selector de periodo (7 o 30 días)
    if (periodSelector) {
      periodSelector.addEventListener('change', function() {
        fetchUpdatedStats(parseInt(this.value, 10) || 30);
      });
    }

    // 3. Botón "Actualizar estadísticas"
    if (btnRefresh) {
      btnRefresh.addEventListener('click', function() {
        const period = periodSelector ? (parseInt(periodSelector.value, 10) || 30) : 30;
        fetchUpdatedStats(period);
      });
    }

    // 4. Botón "Cerrar aviso de resultado"
    if (btnCloseResult && resultBox) {
      btnCloseResult.addEventListener('click', function() {
        resultBox.style.display = 'none';
      });
    }

    // 5. Botón "Procesar ahora" con barra tipo barbería y secuencia de estados
    if (btnProcess) {
      btnProcess.addEventListener('click', function(e) {
        e.preventDefault();
        runManualProcess();
      });
    }

    /**
     * Ejecuta el procesamiento manual por AJAX en lotes sucesivos hasta completar la bandeja.
     */
    async function runManualProcess() {
      if (!btnProcess || btnProcess.disabled) {
        return;
      }

      const period = periodSelector ? (parseInt(periodSelector.value, 10) || 30) : 30;

      // Ocultar avisos previos
      if (resultBox) {
        resultBox.style.display = 'none';
      }
      if (longerMsg) {
        longerMsg.style.display = 'none';
      }

      // Deshabilitar botón principal y botón de refresco para prevenir concurrencia
      btnProcess.disabled = true;
      btnProcess.classList.add('updating-message');
      if (processText) {
        processText.textContent = strings.btnProcessing || 'Procesando...';
      }
      if (processIcon) {
        processIcon.className = 'dashicons dashicons-update pkmb-spinning';
      }
      if (btnRefresh) {
        btnRefresh.disabled = true;
      }

      // Mostrar barra de progreso tipo barber pole
      if (progressContainer) {
        progressContainer.style.display = 'block';
      }

      // Secuencia visual de pasos en la barra de progreso
      const steps = [
        strings.stepConnecting  || 'Conectando con el buzón…',
        strings.stepFetching    || 'Procesando mensajes…',
        strings.stepClassifying || 'Clasificando rebotes…',
        strings.stepUpdating    || 'Actualizando suscriptores…'
      ];

      let stepIndex = 0;
      if (stepText) {
        stepText.textContent = steps[0];
      }

      const stepTimer = setInterval(function() {
        stepIndex = (stepIndex + 1) % steps.length;
        if (stepText) {
          stepText.textContent = steps[stepIndex];
        }
      }, 1900);

      // Aviso si la operación toma más de 12 segundos
      const longerTimer = setTimeout(function() {
        if (longerMsg) {
          longerMsg.style.display = 'flex';
        }
      }, 12000);

      // Acumulador de estadísticas para ejecución multi-lote
      const cumulativeSummary = {
        processed: 0,
        hard: 0,
        soft: 0,
        autoresponses: 0,
        ignored: 0,
        updated_mp: 0,
        deleted_imap: 0,
        errors: 0,
        duration_ms: 0
      };
      let cumulativeDetails = [];
      let batchCount = 0;
      let hasMore = true;
      let nextCursor = null;
      let lastPayload = null;
      let loopSuccess = true;
      const MAX_BATCHES = 20; // Salvaguarda contra bucles infinitos en buzones masivos

      try {
        while (hasMore && batchCount < MAX_BATCHES) {
          batchCount++;

          if (batchCount > 1 && stepText) {
            const batchFormat = strings.stepBatchProgress || 'Procesando lote %1$d (mensajes acumulados: %2$d)…';
            stepText.textContent = batchFormat
              .replace('%1$d', batchCount.toString())
              .replace('%2$d', cumulativeSummary.processed.toString());
          }

          const formData = new FormData();
          formData.append('action', 'pkmb_manual_process');
          formData.append('nonce', config.nonce || '');
          formData.append('period', period.toString());
          if (nextCursor) {
            formData.append('cursor', nextCursor);
          }

          let response;
          try {
            response = await fetch(config.ajaxUrl || 'admin-ajax.php', {
              method: 'POST',
              body: formData
            });
          } catch (fetchErr) {
            throw new Error('NETWORK_TIMEOUT:' + (fetchErr.message || ''));
          }

          // Verificación de status HTTP (403, 404, 500)
          if (!response.ok) {
            if (response.status === 403) {
              throw new Error('HTTP_403');
            }
            if (response.status === 404) {
              throw new Error('HTTP_404');
            }
            if (response.status >= 500) {
              throw new Error('HTTP_500');
            }
            throw new Error('HTTP_' + response.status);
          }

          let rawText;
          try {
            rawText = await response.text();
          } catch (readErr) {
            throw new Error('NETWORK_READ_ERROR');
          }

          // Manejo estricto de respuestas que no son JSON válido
          const trimmed = rawText.trim();
          if (trimmed === '0' || trimmed === '-1') {
            throw new Error(trimmed === '-1' ? 'WP_NONCE_EXPIRED' : 'WP_CALLBACK_MISSING');
          }

          let json;
          try {
            json = JSON.parse(trimmed);
          } catch (parseErr) {
            throw new Error('INVALID_JSON:' + trimmed.slice(0, 100));
          }

          const payload = json.data || {};
          lastPayload = payload;

          // Si el servidor reporta error explícito (nonce, capability, lock o IMAP)
          if (!json.success) {
            loopSuccess = false;
            break;
          }

          // Acumular estadísticas del lote actual
          const batchSummary = payload.summary || {};
          cumulativeSummary.processed     += Number(batchSummary.processed || 0);
          cumulativeSummary.hard          += Number(batchSummary.hard || 0);
          cumulativeSummary.soft          += Number(batchSummary.soft || 0);
          cumulativeSummary.autoresponses += Number(batchSummary.autoresponses || 0);
          cumulativeSummary.ignored       += Number(batchSummary.ignored || 0);
          cumulativeSummary.updated_mp    += Number(batchSummary.updated_mp || 0);
          cumulativeSummary.deleted_imap  += Number(batchSummary.deleted_imap || 0);
          cumulativeSummary.errors        += Number(batchSummary.errors || 0);
          cumulativeSummary.duration_ms   += Number(batchSummary.duration_ms || 0);

          if (Array.isArray(payload.details) && payload.details.length > 0) {
            cumulativeDetails = cumulativeDetails.concat(payload.details);
          }

          // Actualizar métricas visuales en cada lote
          if (payload.fresh_data) {
            applyFreshData(payload.fresh_data);
          }

          // Determinar si hay más lotes pendientes
          hasMore = Boolean(payload.has_more);
          nextCursor = payload.next_cursor || null;

          // Si no hay más mensajes, terminar el ciclo de reescaneo
          if (!hasMore) {
            break;
          }

          // Espera breve de seguridad entre lotes (350 ms) para liberar el event loop y respetar el servidor
          await new Promise(resolve => setTimeout(resolve, 350));
        }

        clearInterval(stepTimer);
        clearTimeout(longerTimer);

        if (progressContainer) {
          progressContainer.style.display = 'none';
        }
        resetProcessButton();

        // Si se ejecutó al menos un lote, construir payload consolidado
        if (batchCount > 1 && lastPayload) {
          lastPayload.summary = cumulativeSummary;
          lastPayload.details = cumulativeDetails;
          lastPayload.message = 'Proceso completado correctamente en ' + batchCount + ' lotes. Total revisados: ' + cumulativeSummary.processed + ' (' + cumulativeSummary.hard + ' hard, ' + cumulativeSummary.soft + ' soft).';
        }

        renderResultNotice(lastPayload || {}, loopSuccess);

      } catch (err) {
        clearInterval(stepTimer);
        clearTimeout(longerTimer);

        if (progressContainer) {
          progressContainer.style.display = 'none';
        }
        resetProcessButton();

        console.error('Error durante el reescaneo AJAX:', err);
        handleAjaxProcessError(err, cumulativeSummary, cumulativeDetails);
      }
    }

    /**
     * Mapea y presenta errores diferenciados con mensajes claros en español.
     */
    function handleAjaxProcessError(err, partialSummary, partialDetails) {
      const errCode = (err.message || '').split(':')[0];
      let userMsg = '';
      let category = 'error';
      const detailsList = Array.isArray(partialDetails) ? partialDetails.slice() : [];

      switch (errCode) {
        case 'WP_NONCE_EXPIRED':
          userMsg = strings.errNonce || 'Sesión expirada o token de seguridad inválido. Por favor, recargue la página.';
          detailsList.unshift('Código de seguridad (nonce) rechazado por check_ajax_referer().');
          break;

        case 'WP_CALLBACK_MISSING':
          userMsg = strings.errNotFound || 'Acción AJAX no encontrada o callback ausente en el servidor.';
          detailsList.unshift('admin-ajax.php devolvió 0: la acción "pkmb_manual_process" no está registrada.');
          break;

        case 'HTTP_403':
          userMsg = strings.errForbidden || 'Permisos insuficientes. Requiere privilegios de administrador para ejecutar esta acción.';
          detailsList.unshift('HTTP 403 Forbidden: el usuario actual no supera current_user_can("manage_options").');
          break;

        case 'HTTP_500':
          userMsg = strings.errPhp || 'Error interno de PHP en el servidor al ejecutar la operación.';
          detailsList.unshift('HTTP 500 Internal Server Error: revise error_log de PHP o el visor de actividad.');
          break;

        case 'NETWORK_TIMEOUT':
          userMsg = strings.ajaxTimeout || 'Error de conectividad o tiempo límite excedido al intentar procesar.';
          detailsList.unshift('Tiempo de espera agotado en la conexión AJAX con el servidor.');
          break;

        case 'INVALID_JSON':
          userMsg = strings.errJson || 'Respuesta no válida del servidor (formato JSON corrupto o inesperado).';
          const snippet = (err.message || '').replace('INVALID_JSON:', '');
          detailsList.unshift('Fragmento recibido: ' + (snippet || 'salida vacía o HTML inesperado'));
          break;

        default:
          userMsg = (err.message && err.message.length > 5 && !err.message.startsWith('HTTP_'))
            ? err.message
            : (strings.ajaxTimeout || 'Error de conectividad o procesamiento en el servidor.');
          detailsList.unshift(err.message || 'Error no especificado.');
          break;
      }

      renderResultNotice({
        status: category,
        message: userMsg,
        summary: partialSummary || {},
        details: detailsList
      }, false);
    }

    /**
     * Restaura el estado original del botón "Procesar ahora".
     */
    function resetProcessButton() {
      if (btnProcess) {
        btnProcess.disabled = false;
        btnProcess.classList.remove('updating-message');
      }
      if (processText) {
        processText.textContent = strings.btnProcessDefault || 'Procesar ahora';
      }
      if (processIcon) {
        processIcon.className = 'dashicons dashicons-controls-play';
      }
      if (btnRefresh) {
        btnRefresh.disabled = false;
      }
    }

    /**
     * Renderiza la caja de estado final (éxito, advertencia o error) con resumen estructurado.
     */
    function renderResultNotice(payload, isSuccess) {
      if (!resultBox) {
        return;
      }

      resultBox.classList.remove('notice-success', 'notice-warning', 'notice-error');

      const status = payload.status || (isSuccess ? 'success' : 'error');
      const message = payload.message || 'Operación finalizada.';
      const summary = payload.summary || {};
      const details = Array.isArray(payload.details) ? payload.details : [];

      if (status === 'success') {
        resultBox.classList.add('notice-success');
        if (resultIcon) resultIcon.className = 'dashicons dashicons-yes-alt';
        if (resultTitle) resultTitle.textContent = 'Proceso completado correctamente';
      } else if (status === 'warning') {
        resultBox.classList.add('notice-warning');
        if (resultIcon) resultIcon.className = 'dashicons dashicons-warning';
        if (resultTitle) resultTitle.textContent = 'Aviso de procesamiento';
      } else {
        resultBox.classList.add('notice-error');
        if (resultIcon) resultIcon.className = 'dashicons dashicons-dismiss';
        if (resultTitle) resultTitle.textContent = 'Error en el procesamiento';
      }

      if (resultMessage) {
        resultMessage.textContent = message;
      }

      // Resumen numérico en píldoras (pills)
      if (resultSummary) {
        if (summary && typeof summary.processed !== 'undefined' && (summary.processed > 0 || status === 'success')) {
          setSummaryPill('val-pill-processed', summary.processed || 0);
          setSummaryPill('val-pill-hard', summary.hard || 0);
          setSummaryPill('val-pill-soft', summary.soft || 0);
          setSummaryPill('val-pill-auto', summary.autoresponses || 0);
          setSummaryPill('val-pill-ignored', summary.ignored || 0);
          setSummaryPill('val-pill-mp', summary.updated_mp || 0);

          const durationText = typeof summary.duration_ms === 'number'
            ? (summary.duration_ms >= 1000
                ? (summary.duration_ms / 1000).toFixed(2) + ' s'
                : summary.duration_ms + ' ms')
            : '0 ms';
          setSummaryPill('val-pill-duration', durationText);

          resultSummary.style.display = 'flex';
        } else {
          resultSummary.style.display = 'none';
        }
      }

      // Detalles técnicos colapsables
      if (resultDetails && resultDetailsList) {
        if (details.length > 0) {
          resultDetailsList.innerHTML = '';
          details.forEach(function(item) {
            const li = document.createElement('li');
            li.textContent = item;
            resultDetailsList.appendChild(li);
          });
          resultDetails.style.display = 'block';
        } else {
          resultDetails.style.display = 'none';
        }
      }

      resultBox.style.display = 'block';
      resultBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function setSummaryPill(id, value) {
      const el = document.getElementById(id);
      if (el) {
        el.textContent = value.toString();
      }
    }

    /**
     * Aplica datos actualizados a KPIs, Gráficos y Tabla de actividad.
     */
    function applyFreshData(data) {
      if (!data) return;

      // 1. Ocultar banner de estado vacío si ahora hay datos
      const emptyBanner = document.getElementById('pkmb-empty-state-banner');
      const chartsWrapper = document.getElementById('pkmb-charts-wrapper');
      if (emptyBanner) {
        emptyBanner.style.display = 'none';
      }
      if (chartsWrapper) {
        chartsWrapper.style.opacity = '1';
      }

      // 2. Actualizar KPIs
      if (data.kpis) {
        const elProc = document.getElementById('kpi-val-processed');
        if (elProc) elProc.textContent = data.kpis.processed_today;

        const elHard = document.getElementById('kpi-val-hard');
        if (elHard) elHard.textContent = data.kpis.hard_bounces_today;

        const elSoft = document.getElementById('kpi-val-soft');
        if (elSoft) elSoft.textContent = data.kpis.soft_bounces_today;

        const elAuto = document.getElementById('kpi-val-auto');
        if (elAuto) elAuto.textContent = data.kpis.autoresponses_today;

        const elImap = document.getElementById('kpi-val-imap-errors');
        if (elImap) {
          elImap.textContent = data.kpis.imap_errors_7d;
          if (parseInt(data.kpis.imap_errors_7d, 10) > 0) {
            elImap.classList.add('pkmb-text-purple');
          } else {
            elImap.classList.remove('pkmb-text-purple');
          }
        }

        if (data.kpis.last_run) {
          const elLastDate = document.getElementById('kpi-val-last-date');
          if (elLastDate) elLastDate.textContent = data.kpis.last_run.date_formatted;

          const elLastTime = document.getElementById('kpi-val-last-time');
          if (elLastTime) {
            if (data.kpis.last_run.has_run) {
              elLastTime.textContent = data.kpis.last_run.time_formatted + ' (' + data.kpis.last_run.duration_formatted + ')';
              elLastTime.style.display = 'inline';
            } else {
              elLastTime.style.display = 'none';
            }
          }
        }
      }

      // 3. Actualizar Gráficos Chart.js
      if (isChartAvailable && data.lineData && data.doughnutData) {
        initCharts(data.lineData, data.doughnutData);

        const badgeLine = document.getElementById('pkmb-line-total-badge');
        if (badgeLine) badgeLine.textContent = (data.lineData.total_period || 0) + ' procesados';

        const badgeDoughnut = document.getElementById('pkmb-doughnut-total-badge');
        if (badgeDoughnut) badgeDoughnut.textContent = (data.doughnutData.total || 0) + ' eventos';
      }

      // 4. Actualizar Tabla de Actividad Reciente
      if (typeof data.tableHtml === 'string') {
        const wrapper = document.getElementById('pkmb-recent-table-wrapper');
        if (wrapper) {
          wrapper.innerHTML = data.tableHtml;
        }
      }
    }

    /**
     * Inicializa o redibuja los dos gráficos Chart.js
     */
    function initCharts(lineData, doughnutData) {
      if (!isChartAvailable) return;

      // Gráfico de Línea: Actividad diaria
      const lineCanvas = document.getElementById('pkmb-chart-line');
      if (lineCanvas) {
        if (lineChartInstance) {
          lineChartInstance.destroy();
        }

        const ctxLine = lineCanvas.getContext('2d');
        lineChartInstance = new window.Chart(ctxLine, {
          type: 'line',
          data: {
            labels: lineData.labels || [],
            datasets: [
              {
                label: 'Mensajes procesados',
                data: lineData.processed || [],
                borderColor: '#0284c7',
                backgroundColor: 'rgba(2, 132, 199, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.25,
                pointRadius: 3,
                pointHoverRadius: 5
              },
              {
                label: 'Hard Bounces',
                data: lineData.hard_bounces || [],
                borderColor: '#dc2626',
                backgroundColor: 'rgba(220, 38, 38, 0.08)',
                borderWidth: 2,
                fill: true,
                tension: 0.25,
                pointRadius: 3,
                pointHoverRadius: 5
              },
              {
                label: 'Soft Bounces',
                data: lineData.soft_bounces || [],
                borderColor: '#d97706',
                backgroundColor: 'rgba(217, 119, 6, 0.06)',
                borderWidth: 2,
                fill: true,
                tension: 0.25,
                pointRadius: 3,
                pointHoverRadius: 5
              }
            ]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
              mode: 'index',
              intersect: false
            },
            plugins: {
              legend: {
                position: 'top',
                labels: {
                  boxWidth: 12,
                  usePointStyle: true,
                  font: { size: 12 }
                }
              },
              tooltip: {
                padding: 10,
                boxPadding: 4
              }
            },
            scales: {
              x: {
                grid: { display: false },
                ticks: { maxRotation: 45, font: { size: 11 } }
              },
              y: {
                beginAtZero: true,
                ticks: { precision: 0, font: { size: 11 } },
                grid: { color: '#f1f5f9' }
              }
            }
          }
        });
      }

      // Gráfico de Dona: Distribución de resultados
      const doughnutCanvas = document.getElementById('pkmb-chart-doughnut');
      if (doughnutCanvas) {
        if (doughnutChartInstance) {
          doughnutChartInstance.destroy();
        }

        const ctxDoughnut = doughnutCanvas.getContext('2d');
        doughnutChartInstance = new window.Chart(ctxDoughnut, {
          type: 'doughnut',
          data: {
            labels: doughnutData.labels || ['Sin eventos'],
            datasets: [{
              data: doughnutData.data || [1],
              backgroundColor: doughnutData.colors || ['#e2e8f0'],
              borderWidth: 2,
              borderColor: '#ffffff'
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '68%',
            plugins: {
              legend: {
                position: 'right',
                labels: {
                  boxWidth: 12,
                  usePointStyle: true,
                  font: { size: 11 },
                  padding: 12
                }
              },
              tooltip: {
                callbacks: {
                  label: function(context) {
                    const label = context.label || '';
                    const val = context.raw || 0;
                    const total = doughnutData.total || 0;
                    const percent = total > 0 ? ((val / total) * 100).toFixed(1) : 0;
                    return label + ': ' + val + ' (' + percent + '%)';
                  }
                }
              }
            }
          }
        });
      }
    }

    /**
     * Refresca las estadísticas mediante AJAX sin recargar la página.
     */
    function fetchUpdatedStats(period) {
      if (!config.ajaxUrl || !config.nonce) {
        return;
      }

      if (btnRefresh) {
        btnRefresh.disabled = true;
      }
      if (refreshIcon) {
        refreshIcon.classList.add('pkmb-spinning');
      }
      if (refreshText) {
        refreshText.textContent = 'Actualizando...';
      }

      const formData = new FormData();
      formData.append('action', 'pkmb_refresh_stats');
      formData.append('nonce', config.nonce);
      formData.append('period', period.toString());

      fetch(config.ajaxUrl, {
        method: 'POST',
        body: formData
      })
      .then(function(res) {
        return res.json();
      })
      .then(function(response) {
        if (btnRefresh) {
          btnRefresh.disabled = false;
        }
        if (refreshIcon) {
          refreshIcon.classList.remove('pkmb-spinning');
        }
        if (refreshText) {
          refreshText.textContent = strings.btnRefreshDefault || 'Actualizar estadísticas';
        }

        if (!response.success || !response.data) {
          var errMsg = response.data && response.data.message ? response.data.message : 'Error desconocido al refrescar estadísticas.';
          renderResultNotice({
            status: 'error',
            message: errMsg,
            details: ['La solicitud al servidor no pudo completar la actualización de estadísticas.']
          }, false);
          return;
        }

        applyFreshData(response.data);
      })
      .catch(function(err) {
        if (btnRefresh) {
          btnRefresh.disabled = false;
        }
        if (refreshIcon) {
          refreshIcon.classList.remove('pkmb-spinning');
        }
        if (refreshText) {
          refreshText.textContent = strings.btnRefreshDefault || 'Actualizar estadísticas';
        }
        console.error('Error al actualizar estadísticas:', err);
        renderResultNotice({
          status: 'error',
          message: 'Error de conexión al intentar refrescar las estadísticas.',
          details: [err.message || 'Fallo de red al comunicar con admin-ajax.php']
        }, false);
      });
    }
  });
})();
