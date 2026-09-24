#!/usr/bin/env bash

set -euo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
installer="$repo_dir/bin/install-local.sh"
test_root="$(mktemp -d "${TMPDIR:-/tmp}/vicunav-restaurante.XXXXXX")"
trap 'rm -rf "${test_root:?}"' EXIT

fail() {
	printf 'Fallo: %s\n' "$*" >&2
	exit 1
}

node "$repo_dir/tests/validate-content.mjs"
node "$repo_dir/tests/validate-qa.mjs"
bash "$repo_dir/theme/tests/run.sh"

wp_root="$test_root/site/app/public"
wp_stub="$test_root/wp-stub.php"
state_dir="$test_root/state"
activation_log="$test_root/activations.log"

mkdir -p "$wp_root/wp-content/plugins" "$wp_root/wp-content/themes" "$state_dir"
: > "$wp_root/wp-load.php"
: > "$wp_root/wp-config.php"
: > "$activation_log"

cat > "$wp_stub" <<'PHP'
<?php
$arguments = array_values(
	array_filter(
		array_slice( $argv, 1 ),
		static fn( $argument ) => ! str_starts_with( $argument, '--' )
	)
);
$type   = $arguments[0] ?? '';
$action = $arguments[1] ?? '';
$slug   = $arguments[2] ?? '';

if ( 'option' === $type && 'get' === $action ) {
	echo getenv( 'VICUNAV_TEST_SITE_URL' );
	exit( 0 );
}
if ( 'core' === $type && 'version' === $action ) {
	echo '6.6';
	exit( 0 );
}
if ( in_array( $type, array( 'plugin', 'theme' ), true ) && 'is-active' === $action ) {
	exit( file_exists( getenv( 'VICUNAV_TEST_STATE_DIR' ) . "/$type-$slug" ) ? 0 : 1 );
}
if ( in_array( $type, array( 'plugin', 'theme' ), true ) && 'activate' === $action ) {
	touch( getenv( 'VICUNAV_TEST_STATE_DIR' ) . "/$type-$slug" );
	file_put_contents( getenv( 'VICUNAV_TEST_LOG' ), "$type:$slug\n", FILE_APPEND );
	exit( 0 );
}
fwrite( STDERR, 'Comando WP-CLI inesperado.' );
exit( 2 );
PHP

export VICUNAV_PHP_BIN="$(command -v php)"
export VICUNAV_WP_CLI_BIN="$wp_stub"
export VICUNAV_TEST_SITE_URL='https://fixture.local'
export VICUNAV_TEST_STATE_DIR="$state_dir"
export VICUNAV_TEST_LOG="$activation_log"

run_installer() {
	bash "$installer" \
		"--wp-path=$wp_root" \
		'--site-url=https://fixture.local'
}

run_installer >/dev/null
run_installer >/dev/null

plugin_link="$wp_root/wp-content/plugins/vicunav-restaurante"
theme_link="$wp_root/wp-content/themes/vicunav-bonasera"

[[ -L "$plugin_link" ]] || fail 'faltó el symlink del plugin.'
[[ -L "$theme_link" ]] || fail 'faltó el symlink del theme.'
[[ "$(readlink "$plugin_link")" == "$repo_dir/plugin" ]] || fail 'el symlink del plugin apunta a otra fuente.'
[[ "$(readlink "$theme_link")" == "$repo_dir/theme" ]] || fail 'el symlink del theme apunta a otra fuente.'
[[ -f "$state_dir/plugin-vicunav-restaurante" ]] || fail 'el plugin no quedó activado.'
[[ -f "$state_dir/theme-vicunav-bonasera" ]] || fail 'el theme no quedó activado.'
[[ "$(wc -l < "$activation_log" | tr -d ' ')" == '2' ]] || fail 'la segunda ejecución reactivó paquetes.'

if bash "$installer" "--wp-path=$wp_root" '--site-url=https://produccion.example.com' >"$test_root/remote.log" 2>&1; then
	fail 'una URL que no es .local fue aceptada.'
fi
grep -q 'host .local' "$test_root/remote.log" || fail 'faltó el error de URL no local.'

rm "$plugin_link"
mkdir "$plugin_link"
if run_installer >"$test_root/collision.log" 2>&1; then
	fail 'una colisión de destino fue aceptada.'
fi
grep -q 'destino está ocupado' "$test_root/collision.log" || fail 'faltó el error de colisión.'

printf 'Pruebas de instalación completadas.\n'
