#!/usr/bin/env bash
set -euo pipefail

# ==============================================================================
# Script de Compilación y Aislamiento de Artefacto (PHP-Scoper + Composer)
# Plugin: MailPoet Bouncer (Publikate)
# ==============================================================================

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${PROJECT_DIR}/build"
DIST_DIR="${PROJECT_DIR}/dist"
PLUGIN_SLUG="mailpoet-bouncer"
PACKAGE_DIR="${DIST_DIR}/${PLUGIN_SLUG}"
ZIP_NAME="${PLUGIN_SLUG}.zip"

# Extraer versión actual definida en la cabecera del plugin
PLUGIN_VERSION=$(grep -m1 "Version:" "${PROJECT_DIR}/mailpoet-bouncer.php" | awk '{print $NF}')

echo "==> Iniciando proceso de compilación para ${PLUGIN_SLUG} (Versión: ${PLUGIN_VERSION})..."

# 1. Limpieza de artefactos previos del plugin
echo "==> Limpiando directorios de build y empaquetado del plugin..."
rm -rf "${BUILD_DIR}" "${PACKAGE_DIR}" "${DIST_DIR}/${ZIP_NAME}"
mkdir -p "${BUILD_DIR}" "${PACKAGE_DIR}" "${DIST_DIR}"

# 2. Instalación de dependencias de producción limpias
echo "==> Instalando dependencias de producción..."
cd "${PROJECT_DIR}"
if command -v composer >/dev/null 2>&1; then
    composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
else
    echo "AVISO: Composer no encontrado localmente. Omitiendo composer install si no hay dependencias de terceros."
fi

# 3. Aislamiento con PHP-Scoper si existe vendor externo
if [ -d "${PROJECT_DIR}/vendor" ] && [ -f "${PROJECT_DIR}/scoper.inc.php" ]; then
    if command -v php-scoper >/dev/null 2>&1; then
        echo "==> Ejecutando PHP-Scoper para aislar dependencias..."
        php-scoper add-prefix --config="${PROJECT_DIR}/scoper.inc.php" --output-dir="${BUILD_DIR}/scoped" --force
        
        # Volcar autoloader optimizado sobre el código scopiado
        cd "${BUILD_DIR}/scoped"
        if command -v composer >/dev/null 2>&1; then
            composer dump-autoload --working-dir="${BUILD_DIR}/scoped" --classmap-authoritative --no-dev
        fi
        
        # Copiar vendor scopiado a la carpeta del paquete final
        cp -r "${BUILD_DIR}/scoped/vendor" "${PACKAGE_DIR}/"
    else
        echo "==> php-scoper no está instalado en PATH global; usando vendor directo si aplica..."
        if [ -d "${PROJECT_DIR}/vendor" ]; then
            cp -r "${PROJECT_DIR}/vendor" "${PACKAGE_DIR}/"
        fi
    fi
fi

# 4. Copiado de archivos fuente del plugin al directorio de empaquetado
echo "==> Copiando archivos de código fuente a ${PACKAGE_DIR}..."
cp "${PROJECT_DIR}/mailpoet-bouncer.php" "${PACKAGE_DIR}/"
cp -r "${PROJECT_DIR}/src" "${PACKAGE_DIR}/"
if [ -d "${PROJECT_DIR}/assets" ]; then
    cp -r "${PROJECT_DIR}/assets" "${PACKAGE_DIR}/"
fi
if [ -f "${PROJECT_DIR}/readme.md" ]; then
    cp "${PROJECT_DIR}/readme.md" "${PACKAGE_DIR}/"
fi

# Normalizar permisos de archivos y carpetas para compatibilidad estricta con WordPress
echo "==> Normalizando permisos de archivos (644) y directorios (755)..."
find "${PACKAGE_DIR}" -type d -exec chmod 755 {} +
find "${PACKAGE_DIR}" -type f -exec chmod 644 {} +

# 5. Generación del archivo ZIP instalable
echo "==> Creando artefacto ZIP instalable: ${DIST_DIR}/${ZIP_NAME}..."
cd "${DIST_DIR}"
rm -f "${ZIP_NAME}"

if command -v zip >/dev/null 2>&1; then
    # -r: recursivo
    # -X: sin atributos extra de SO / UID / GID para evitar incompatibilidad con PclZip / WordPress
    # -9: compresión máxima estándar deflate
    zip -q -r -X -9 "${ZIP_NAME}" "${PLUGIN_SLUG}" -x "*.DS_Store" "*__MACOSX*" "*.git*"
elif command -v python3 >/dev/null 2>&1; then
    python3 -c "import shutil; shutil.make_archive('${PLUGIN_SLUG}', 'zip', '${DIST_DIR}', '${PLUGIN_SLUG}')"
fi

# Copiar a public/ para que el servidor web sirva el binario con MIME application/zip real
mkdir -p "${PROJECT_DIR}/public"
cp "${DIST_DIR}/${ZIP_NAME}" "${PROJECT_DIR}/public/${ZIP_NAME}"

echo "==> ¡Compilación completada exitosamente!"
echo "    Versión empaquetada: ${PLUGIN_VERSION}"
echo "    Artefacto listo: ${DIST_DIR}/${ZIP_NAME}"
echo "    Copia estática web lista: ${PROJECT_DIR}/public/${ZIP_NAME}"
