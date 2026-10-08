#!/usr/bin/env bash
#
# Suite de integración: las pruebas que necesitan WordPress y MySQL de verdad.
#
#   composer test:integration        (o directamente: tests/integration/run.sh)
#
# Cada suite corre en su propio proceso y termina con código de error si falla
# alguna aserción, así que el resumen de acá es fiable.
#
# Lo que este lanzador resuelve, que en esta máquina no es obvio:
#   - el binario de PHP: el de Local, porque no hay php en el PATH;
#   - el socket de MySQL: Local lo cambia de sesión, así que se busca el vivo;
#   - las rutas del sitio: cada suite las deriva de __DIR__ (o de SF_SITE_PATH).
#
# Para correrlo en otra instalación:
#   SF_PHP=/usr/bin/php SF_SITE_PATH=/var/www/sitio SF_MYSQL_SOCKET=/tmp/mysql.sock \
#     tests/integration/run.sh
#
# Aparte y a mano: mercado-pago-live.php (no empieza con test-, así que este
# script no lo toca). Ese llama a la API real de Mercado Pago; no cobra nada
# porque una preferencia no es un pago, pero no tiene por qué correr siempre.
#
# Códigos de salida de cada suite: 0 = todo bien, 1 = falló una aserción,
# 2 = la suite decidió no correr (test-uninstall lo hace si hay pedidos
# guardados, para no dropear tablas con datos). El 2 no es un fallo.

set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# --- PHP ---------------------------------------------------------------------

if [ -n "${SF_PHP:-}" ]; then
	PHP="$SF_PHP"
else
	PHP="$(ls -t "$HOME"/.config/Local/lightning-services/php-*/bin/linux/bin/php 2>/dev/null | head -1)"
fi

if [ -z "${PHP:-}" ] || [ ! -x "$PHP" ]; then
	echo "no encuentro un binario de PHP; pasalo con SF_PHP=/ruta/al/php" >&2
	exit 1
fi

# El PHP de Local necesita sus librerías, y solo él: exportar LD_LIBRARY_PATH
# rompe git y curl, así que va como prefijo de cada comando.
LIB_CANDIDATO="$(dirname "$(dirname "$PHP")")/shared-libs"
LIB=""
[ -d "$LIB_CANDIDATO" ] && LIB="$LIB_CANDIDATO"

# --- MySQL -------------------------------------------------------------------

if [ -n "${SF_MYSQL_SOCKET:-}" ]; then
	SOCKET="$SF_MYSQL_SOCKET"
else
	SOCKET="$(ls -t "$HOME"/.config/Local/run/*/mysql/mysqld.sock 2>/dev/null | head -1)"
fi

if [ -z "${SOCKET:-}" ] || [ ! -S "$SOCKET" ]; then
	echo "no encuentro el socket de MySQL. ¿Está Local encendido? (o pasá SF_MYSQL_SOCKET=/ruta)" >&2
	exit 1
fi

echo "php:    $PHP"
[ -n "$LIB" ] && echo "libs:   $LIB"
echo "socket: $SOCKET"
echo

# --- suites ------------------------------------------------------------------

total=0
ok=0
saltadas=0
fallidas=0

for suite in "$DIR"/test-*.php; do
	[ -f "$suite" ] || continue

	total=$(( total + 1 ))
	nombre="$(basename "$suite")"

	echo "================ $nombre ================"

	if LD_LIBRARY_PATH="$LIB" "$PHP" \
		-d mysqli.default_socket="$SOCKET" \
		-d pdo_mysql.default_socket="$SOCKET" \
		"$suite"; then
		echo "---- $nombre: OK"
		ok=$(( ok + 1 ))
	else
		codigo=$?

		if [ "$codigo" -eq 2 ]; then
			echo "---- $nombre: SALTADA"
			saltadas=$(( saltadas + 1 ))
		else
			echo "---- $nombre: FALLÓ (código $codigo)"
			fallidas=$(( fallidas + 1 ))
		fi
	fi

	echo
done

echo "=============================================="

if [ "$total" -eq 0 ]; then
	echo "no hay ninguna suite que correr en $DIR" >&2
	exit 1
fi

echo "integración: $total suite(s) — $ok OK, $saltadas saltada(s), $fallidas con fallos"

[ "$fallidas" -eq 0 ] && exit 0
exit 1
