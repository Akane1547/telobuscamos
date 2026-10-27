#!/usr/bin/env bash
#
# Pruebas de seguridad del sistema de cuentas (souls.md §48-51).
#
#   bash tests/security/run.sh
#
# Ataca el sitio por HTTP real: necesita Local encendido y el sitio respondiendo.
# Resuelve solo el binario de PHP de Local y el socket de MySQL, igual que
# tests/integration/run.sh, y devuelve 0 solo si todas las pruebas pasan.
#
# Para otra instalación:
#   SF_SECURITY_URL=http://misitio.local SF_SECURITY_HOST=misitio.local \
#   SF_PHP=/usr/bin/php SF_SITE_PATH=/var/www/sitio SF_MYSQL_SOCKET=/tmp/mysql.sock \
#     bash tests/security/run.sh

set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [ -n "${SF_PHP:-}" ]; then
	PHP="$SF_PHP"
else
	PHP="$(ls -t "$HOME"/.config/Local/lightning-services/php-*/bin/linux/bin/php 2>/dev/null | head -1)"
fi

if [ -z "${PHP:-}" ] || [ ! -x "$PHP" ]; then
	echo "no encuentro un binario de PHP; pasalo con SF_PHP=/ruta/al/php" >&2
	exit 1
fi

LIB_CANDIDATO="$(dirname "$(dirname "$PHP")")/shared-libs"
LIB=""
[ -d "$LIB_CANDIDATO" ] && LIB="$LIB_CANDIDATO"

if [ -n "${SF_MYSQL_SOCKET:-}" ]; then
	SOCKET="$SF_MYSQL_SOCKET"
else
	SOCKET="$(ls -t "$HOME"/.config/Local/run/*/mysql/mysqld.sock 2>/dev/null | head -1)"
fi

if [ -z "${SOCKET:-}" ] || [ ! -S "$SOCKET" ]; then
	echo "no encuentro el socket de MySQL. ¿Está Local encendido? (o pasá SF_MYSQL_SOCKET=/ruta)" >&2
	exit 1
fi

HOST="${SF_SECURITY_HOST:-telobuscamos.local}"
URL="${SF_SECURITY_URL:-http://127.0.0.1}"

# ¿Responde el sitio? Sin esto las pruebas fallan por red y no por seguridad.
CODIGO="$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $HOST" "$URL/iniciar-sesion/" 2>/dev/null)"
if [ "$CODIGO" != "200" ]; then
	echo "el sitio no responde en $URL/iniciar-sesion/ (HTTP ${CODIGO:-000}); levantalo y volvé a correr" >&2
	exit 1
fi

LD_LIBRARY_PATH="${LIB}" SF_SECURITY_URL="$URL" SF_SECURITY_HOST="$HOST" \
	"$PHP" -d mysqli.default_socket="$SOCKET" -d pdo_mysql.default_socket="$SOCKET" \
	"$DIR/account-security.php"
