#!/usr/bin/env bash
# Envoltorio de WP-CLI para el sitio LocalWP de este demo.
#
# En LocalWP casi nunca sirve el binario `wp` global tal cual: necesita el PHP
# propio del sitio y, casi siempre, el socket de MySQL de LocalWP (ver
# docs/standards/...).
# Este script existe para no tener que recordar esa invocación cada vez.
#
# Variables (con los mismos nombres que bin/install-local.sh, para reusar el
# mismo .env o exportación de shell entre ambos scripts):
#   VICUNAV_PHP_BIN       Binario PHP del sitio LocalWP (ruta completa).
#   VICUNAV_MYSQL_SOCKET  Socket MySQL de LocalWP.
#   VICUNAV_WP_CLI_BIN    Ejecutable de WP-CLI. Por defecto: wp.
#   VICUNAV_WP_PATH       Ruta a wp-content/../ del sitio (app/public).
#   VICUNAV_SITE_URL      URL del sitio, con el esquema correcto (afecta
#                          is_ssl() dentro de WP-CLI; usarlo explícito evita el
#                          bug real de WP-CLI con is_ssl() en LocalWP).
#
# Uso: bin/wpcli.sh <comando wp-cli normal>

set -euo pipefail

: "${VICUNAV_PHP_BIN:?Falta VICUNAV_PHP_BIN (ruta al PHP del sitio LocalWP).}"
: "${VICUNAV_WP_PATH:?Falta VICUNAV_WP_PATH (ruta a app/public del sitio LocalWP).}"
: "${VICUNAV_SITE_URL:?Falta VICUNAV_SITE_URL (URL del sitio, con el esquema real).}"

wp_cli_bin="${VICUNAV_WP_CLI_BIN:-}"
if [[ -z "$wp_cli_bin" ]]; then
	wp_cli_bin="$(command -v wp || true)"
	[[ -n "$wp_cli_bin" ]] || { echo 'No se encontró WP-CLI en PATH; define VICUNAV_WP_CLI_BIN.' >&2; exit 1; }
fi
php_command=("$VICUNAV_PHP_BIN")

if [[ -n "${VICUNAV_MYSQL_SOCKET:-}" ]]; then
	php_command+=(
		-d "mysqli.default_socket=$VICUNAV_MYSQL_SOCKET"
		-d "pdo_mysql.default_socket=$VICUNAV_MYSQL_SOCKET"
	)
fi

exec "${php_command[@]}" "$wp_cli_bin" --path="$VICUNAV_WP_PATH" --url="$VICUNAV_SITE_URL" "$@"
