#!/usr/bin/env bash
set -euo pipefail

MOODLE_ROOT=/var/www/html
MOODLE_DATA=/var/www/moodledata
MANAGED_SOURCE=/run/moodle-config
MANAGED_RUNTIME="${MOODLE_ROOT}/.managed-config.php"

required=(
  MOODLE_DB_HOST MOODLE_DB_PORT MOODLE_DB_NAME MOODLE_DB_USER
  MOODLE_DB_PASSWORD MOODLE_WWWROOT MOODLE_SITE_NAME MOODLE_SITE_SHORTNAME
  MOODLE_ADMIN_USER MOODLE_ADMIN_PASSWORD MOODLE_ADMIN_EMAIL
)

for name in "${required[@]}"; do
  if [[ -z "${!name:-}" ]]; then
    echo "Falta la variable obligatoria: ${name}" >&2
    exit 1
  fi
done

if [[ ${#MOODLE_ADMIN_PASSWORD} -lt 12 ||
      ! "${MOODLE_ADMIN_PASSWORD}" =~ [[:lower:]] ||
      ! "${MOODLE_ADMIN_PASSWORD}" =~ [[:upper:]] ||
      ! "${MOODLE_ADMIN_PASSWORD}" =~ [[:digit:]] ||
      ! "${MOODLE_ADMIN_PASSWORD}" =~ [._@-] ]]; then
  echo "La clave administrativa no cumple la política segura de instalación." >&2
  echo "Use al menos 12 caracteres, con minúscula, mayúscula, número y . _ @ o -." >&2
  exit 64
fi

required_extensions=(
  curl dom fileinfo gd intl json mbstring mysqli openssl sodium xml zip
)
for extension in "${required_extensions[@]}"; do
  if ! php -r "exit(extension_loaded('${extension}') ? 0 : 1);"; then
    echo "Falta la extensión PHP obligatoria: ${extension}" >&2
    exit 65
  fi
done

if [[ "$(php -r 'echo PHP_INT_SIZE;')" -ne 8 ]]; then
  echo "Moodle 5.2 requiere PHP de 64 bits." >&2
  exit 65
fi

if [[ "$(php -r 'echo (int)ini_get("max_input_vars");')" -lt 5000 ]]; then
  echo "Moodle 5.2 requiere max_input_vars >= 5000." >&2
  exit 65
fi

distribution_files=(
  lib/components.json
  public/version.php
  public/cache/classes/config.php
  public/lib/classes/component.php
  admin/cli/install.php
  admin/cli/install_database.php
  admin/cli/upgrade.php
)
for relative in "${distribution_files[@]}"; do
  if [[ ! -s "/opt/moodle/${relative}" ]]; then
    echo "IMAGEN_MOODLE_INCOMPLETA falta=/opt/moodle/${relative}" >&2
    echo "Reconstruya el destino con el paquete completo y sin reutilizar la imagen anterior." >&2
    exit 69
  fi
done

read_distribution_string() {
  local field="$1"
  local line pattern
  pattern="^[[:space:]]*\\\$${field}[[:space:]]*=[[:space:]]*'([^']+)'"

  while IFS= read -r line; do
    if [[ "${line}" =~ ${pattern} ]]; then
      printf '%s' "${BASH_REMATCH[1]}"
      return 0
    fi
  done < /opt/moodle/public/version.php

  echo "IMAGEN_MOODLE_INCONSISTENTE metadato=${field}" >&2
  return 1
}

# version.php no debe ejecutarse de forma aislada: usa constantes que Moodle
# define durante su bootstrap. Leer únicamente las asignaciones literales evita
# abortos PHP silenciosos antes de iniciar la instalación.
if ! distribution_release="$(read_distribution_string release)"; then
  exit 69
fi
if ! distribution_branch="$(read_distribution_string branch)"; then
  exit 69
fi
if [[ "${distribution_release}" != 5.2.1* ||
      "${distribution_branch}" != "502" ]]; then
  echo "IMAGEN_MOODLE_INESPERADA release=${distribution_release} branch=${distribution_branch}" >&2
  exit 69
fi

if ! grep -Eq '"cache"[[:space:]]*:[[:space:]]*"public/cache"' \
    /opt/moodle/lib/components.json; then
  echo "IMAGEN_MOODLE_INCONSISTENTE componente=core_cache" >&2
  exit 69
fi

if ! php -r '
  require "/opt/moodle/public/cache/classes/config.php";
  exit(class_exists("core_cache\\config", false) ? 0 : 1);
'; then
  echo "IMAGEN_MOODLE_INCONSISTENTE clase=core_cache\\\\config" >&2
  exit 69
fi

mkdir -p "${MOODLE_ROOT}" "${MOODLE_DATA}"

php /usr/local/lib/moodle-managed-config.php verify \
  "--directory=${MANAGED_SOURCE}"

# El código y los plugins se versionan en la imagen. config.php y moodledata
# permanecen en volúmenes, pero los ajustes administrados tienen su fuente en
# el host. La sincronización permite reconstruir sin reiniciar la migración.
root_config_sha256=""
if [[ -f "${MOODLE_ROOT}/config.php" ]]; then
  root_config_sha256="$(sha256sum "${MOODLE_ROOT}/config.php" | awk '{print $1}')"
fi

rsync -a --delete \
  --exclude='/config.php' \
  --exclude='.git/' \
  /opt/moodle/ "${MOODLE_ROOT}/"

managed_temporary="${MANAGED_RUNTIME}.partial.$$"
install -o root -g www-data -m 0640 \
  "${MANAGED_SOURCE}/active/current.php" "${managed_temporary}"
mv -f "${managed_temporary}" "${MANAGED_RUNTIME}"
managed_expected_sha256="$(
  sha256sum "${MANAGED_SOURCE}/active/current.php" | awk '{print $1}'
)"
managed_actual_sha256="$(sha256sum "${MANAGED_RUNTIME}" | awk '{print $1}')"
if [[ "${managed_actual_sha256}" != "${managed_expected_sha256}" ]]; then
  echo "CONFIG_ADMINISTRADA_COPIA_INVALIDA" >&2
  exit 70
fi

if [[ -n "${root_config_sha256}" ]]; then
  current_root_config_sha256=""
  if [[ -f "${MOODLE_ROOT}/config.php" ]]; then
    current_root_config_sha256="$(
      sha256sum "${MOODLE_ROOT}/config.php" | awk '{print $1}'
    )"
  fi
  if [[ "${current_root_config_sha256}" != "${root_config_sha256}" ]]; then
    echo "COPIA_MOODLE_INSEGURA archivo=${MOODLE_ROOT}/config.php" >&2
    echo "La sincronización no conservó el config.php raíz existente." >&2
    exit 70
  fi
fi

for relative in "${distribution_files[@]}"; do
  if [[ ! -s "${MOODLE_ROOT}/${relative}" ]] ||
      ! cmp -s "/opt/moodle/${relative}" "${MOODLE_ROOT}/${relative}"; then
    echo "COPIA_MOODLE_INCOMPLETA archivo=${MOODLE_ROOT}/${relative}" >&2
    exit 70
  fi
done

MOODLE_WEB_ROOT="${MOODLE_ROOT}"
if [[ -f "${MOODLE_ROOT}/public/index.php" ]]; then
  MOODLE_WEB_ROOT="${MOODLE_ROOT}/public"
fi
APACHE_SITE=/etc/apache2/sites-available/000-default.conf
APACHE_SITE_TMP="${APACHE_SITE}.moodle-production.$$"
document_root_found=0
while IFS= read -r apache_line; do
  if [[ "${apache_line}" =~ ^[[:space:]]*DocumentRoot[[:space:]] ]]; then
    printf 'DocumentRoot %s\n' "${MOODLE_WEB_ROOT}"
    document_root_found=$((document_root_found + 1))
  else
    printf '%s\n' "${apache_line}"
  fi
done < "${APACHE_SITE}" > "${APACHE_SITE_TMP}"
if [[ "${document_root_found}" -ne 1 ]]; then
  rm -f "${APACHE_SITE_TMP}"
  echo "Se esperó una única directiva DocumentRoot en ${APACHE_SITE}." >&2
  exit 1
fi
mv "${APACHE_SITE_TMP}" "${APACHE_SITE}"
echo "Apache DocumentRoot: ${MOODLE_WEB_ROOT}"

chown -R www-data:www-data "${MOODLE_ROOT}" "${MOODLE_DATA}"

echo "Esperando la base ${MOODLE_DB_NAME}..."
until MYSQL_PWD="${MOODLE_DB_PASSWORD}" mysqladmin ping \
  --host="${MOODLE_DB_HOST}" \
  --port="${MOODLE_DB_PORT}" \
  --user="${MOODLE_DB_USER}" \
  --silent; do
  sleep 2
done

database_scalar() {
  local sql="$1"
  MYSQL_PWD="${MOODLE_DB_PASSWORD}" mysql \
    --protocol=TCP \
    --host="${MOODLE_DB_HOST}" \
    --port="${MOODLE_DB_PORT}" \
    --user="${MOODLE_DB_USER}" \
    --batch \
    --skip-column-names \
    --raw \
    "${MOODLE_DB_NAME}" \
    --execute="${sql}"
}

if [[ ! -f "${MOODLE_ROOT}/config.php" ]]; then
  echo "Generando config.php para ${MOODLE_SITE_SHORTNAME}..."
  runuser -u www-data -- php admin/cli/install.php \
    --lang=es \
    --wwwroot="${MOODLE_WWWROOT}" \
    --dataroot="${MOODLE_DATA}" \
    --dbtype=mariadb \
    --dbhost="${MOODLE_DB_HOST}" \
    --dbport="${MOODLE_DB_PORT}" \
    --dbname="${MOODLE_DB_NAME}" \
    --dbuser="${MOODLE_DB_USER}" \
    --dbpass="${MOODLE_DB_PASSWORD}" \
    --fullname="${MOODLE_SITE_NAME}" \
    --shortname="${MOODLE_SITE_SHORTNAME}" \
    --adminuser="${MOODLE_ADMIN_USER}" \
    --adminpass="${MOODLE_ADMIN_PASSWORD}" \
    --adminemail="${MOODLE_ADMIN_EMAIL}" \
    --agree-license \
    --skip-database \
    --non-interactive
fi

if [[ ! -f "${MOODLE_ROOT}/config.php" ]]; then
  echo "El instalador no creó ${MOODLE_ROOT}/config.php." >&2
  exit 66
fi

table_count="$(database_scalar \
  'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE();')"
version_value=""
if database_scalar \
    "SELECT COUNT(*) FROM information_schema.tables
       WHERE table_schema = DATABASE() AND table_name = 'mdl_config';" |
    grep -qx '1'; then
  version_value="$(database_scalar \
    "SELECT value FROM mdl_config WHERE name = 'version' LIMIT 1;" || true)"
fi

if [[ -z "${version_value}" ]]; then
  if [[ "${table_count}" -ne 0 ]]; then
    echo "INSTALACION_INCOMPLETA tablas=${table_count} version_ausente=1" >&2
    echo "El destino contiene una creación parcial y no se intentará un upgrade." >&2
    echo "Recree únicamente los volúmenes del destino antes de preparar de nuevo." >&2
    exit 67
  fi

  echo "Instalando la base de datos de ${MOODLE_SITE_SHORTNAME}..."
  runuser -u www-data -- php admin/cli/install_database.php \
    --lang=es \
    --fullname="${MOODLE_SITE_NAME}" \
    --shortname="${MOODLE_SITE_SHORTNAME}" \
    --adminuser="${MOODLE_ADMIN_USER}" \
    --adminpass="${MOODLE_ADMIN_PASSWORD}" \
    --adminemail="${MOODLE_ADMIN_EMAIL}" \
    --supportemail="${MOODLE_ADMIN_EMAIL}" \
    --agree-license

  version_value="$(database_scalar \
    "SELECT value FROM mdl_config WHERE name = 'version' LIMIT 1;" || true)"
  if [[ -z "${version_value}" ]]; then
    echo "La instalación terminó sin registrar la versión de Moodle." >&2
    exit 68
  fi
  echo "INSTALACION_BASE_OK version=${version_value}"
else
  echo "Base Moodle instalada; comprobando actualizaciones..."
  runuser -u www-data -- php admin/cli/upgrade.php --non-interactive
fi

php /usr/local/lib/moodle-configure-runtime.php
chown root:www-data "${MOODLE_ROOT}/config.php" "${MANAGED_RUNTIME}"
chmod 0640 "${MOODLE_ROOT}/config.php" "${MANAGED_RUNTIME}"

exec "$@"
