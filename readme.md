# MailPoet Bouncer (Publikate)

Plugin privado de WordPress para el procesamiento desatendido, seguro y aislado de rebotes de correo (**Hard Bounces**) para **MailPoet 5.31+** mediante conexión IMAP y ejecución por WP-CLI / cron del sistema.

---

## Características Principales

- **Arquitectura sin Bloqueos Web**: El procesamiento se ejecuta exclusivamente vía **WP-CLI**, evitando caídas o timeouts en peticiones HTTP del administrador.
- **Aislamiento de Dependencias (PHP-Scoper Ready)**: Las librerías externas se empaquetan en el namespace `Publikate\MailPoetBouncer\Scoped`, eliminando colisiones con otros plugins (*Dependency Hell*).
- **Composer fuera de Producción**: El servidor final (CWP / AlmaLinux 9) no requiere Composer ni Node.js. El plugin se entrega como un artefacto compilado listo para instalar.
- **Detección Conservadora de Rebotes**: Clasificación según estándares RFC 3463 y RFC 3464. Ignora respuestas automáticas (*Out of Office*), vacaciones y rebotes temporales (*Soft Bounces* 4.X.X), marcando únicamente fallos definitivos (5.X.X, 550 User Unknown).
- **Compatibilidad Dual IMAP**: Utiliza la extensión `ext-imap` de PHP si está presente, o conmuta automáticamente a sockets SSL/TLS nativos (`stream_socket_client`) en entornos modernos como PHP 8.4 donde `ext-imap` fue desvinculado.
- **Auditoría Transparente**: Tabla dedicada `wp_pkmb_audit_log` para registrar cada evento sin almacenar cuerpos completos de correos confidenciales.
- **Panel Operativo de Estadísticas y Gráficos**: Métricas en tiempo real con 6 tarjetas KPI, gráficos interactivos con Chart.js (línea y dona) cargado bajo demanda vía CDN, y tabla de actividad reciente filtrable sin dependencias de compilación en el servidor.
- **Control Operativo de Ejecución Manual**: Botón "Procesar ahora" protegido para administradores con AJAX seguro, indicador visual de progreso tipo barbería animada, retroalimentación secuencial de estados y resumen estructurado (éxito, advertencia o error) con actualización instantánea de métricas sin recargar la página.
- **Prevención de Concurrencia**: Bloqueo atómico por *transients* para evitar que ejecuciones simultáneas del cron del sistema o del panel interfieran entre sí.

---

## Requisitos del Servidor

- **WordPress**: 6.0 o superior.
- **PHP**: 8.1, 8.2, 8.3 o 8.4 (probado en AlmaLinux 9 con PHP 8.3.33).
- **MailPoet**: 5.31 o superior.
- **Buzón IMAP**: Acceso IMAP por puerto 993 (SSL) o 143 (TLS) a la cuenta receptora de rebotes.
- **WP-CLI**: Instalado y accesible en el servidor para la ejecución del cron.

---

## Configuración en WordPress

1. Suba y active el plugin desde **Plugins > Añadir nuevo > Subir plugin**.
2. Diríjase a **Ajustes > MailPoet Bouncer**.
3. Complete los datos de conexión IMAP:
   - **Servidor IMAP (Host)**: ej. `mail.dominio.cl`
   - **Puerto**: `993`
   - **Cifrado**: `SSL / TLS`
   - **Usuario**: `rebotes@dominio.cl`
   - **Contraseña**: (clave del buzón)
   - **Carpeta IMAP**: `INBOX`
   - **Tamaño de lote**: `50`
   - **Modo Simulación (Dry-Run)**: Activado por defecto para pruebas seguras.
4. Vaya a la pestaña **Prueba de Conexión** y pulse el botón para verificar que el servidor IMAP responda correctamente.

---

## Configuración del Cron del Sistema (CWP / AlmaLinux 9)

Edite el crontab del usuario del sitio o crontab del sistema:

```bash
# Ejecutar cada 5 minutos
*/5 * * * * /usr/local/bin/wp mailpoet-bouncer process --path=/home/usuario/public_html > /dev/null 2>&1
```

### Comandos WP-CLI Disponibles

```bash
# Ejecución estándar (respeta ajustes de la base de datos)
wp mailpoet-bouncer process

# Forzar modo simulación (audita sin modificar MailPoet ni borrar correos)
wp mailpoet-bouncer process --dry-run

# Procesar con un límite específico de correos
wp mailpoet-bouncer process --limit=100

# Forzar ejecución si un proceso anterior se interrumpió abruptamente
wp mailpoet-bouncer process --force
```

---

## Estructura del Código

```
mailpoet-bouncer/
├── mailpoet-bouncer.php       # Punto de entrada principal (autoloader y guards)
├── composer.json              # Configuración de desarrollo y build
├── scoper.inc.php             # Reglas de prefijado para PHP-Scoper
├── readme.md                  # Documentación
├── bin/
│   └── build.sh               # Script automatizado de compilación y empaquetado
├── assets/
│   └── js/
│       └── stats.js           # Lógica JavaScript vanilla para gráficos y KPIs
└── src/
    ├── Plugin.php             # Orquestador del ciclo de vida
    ├── Admin/
    │   ├── Settings.php       # Settings API y sanitización estricta
    │   ├── SettingsPage.php   # Interfaz en wp-admin (100% español)
    │   └── StatsRenderer.php  # Renderizador del panel de Estadísticas y KPIs
    ├── CLI/
    │   └── ProcessCommand.php # Comando WP-CLI con control de concurrencia
    ├── IMAP/
    │   ├── ClientInterface.php
    │   ├── ClientFactory.php  # Selector de driver según entorno
    │   ├── NativeImapClient.php
    │   └── SocketImapClient.php
    ├── Bounce/
    │   ├── ProcessorService.php # Servicio centralizado de ejecución y normalización
    │   ├── Parser.php         # Extracción DSN RFC 3464
    │   ├── Result.php         # DTO de resultado
    │   └── Classifier.php     # Clasificación conservadora Hard vs Soft
    ├── MailPoet/
    │   └── SubscriberUpdater.php # Actualización segura en MailPoet 5.31+
    ├── Audit/
    │   ├── Logger.php         # Tabla wp_pkmb_audit_log
    │   └── StatsRepository.php# Agregación SQL y cálculo de métricas para gráficos
    └── Support/
        ├── Requirements.php   # Verificación de PHP, WP y MailPoet
        └── Notices.php        # Mensajes administrativos seguros
```
