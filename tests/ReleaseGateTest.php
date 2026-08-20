<?php
/**
 * Gates estáticos de la candidata 1.0.0.
 *
 * @package Vicunav_Restaurante
 */

use PHPUnit\Framework\TestCase;

/** Verifica que los artefactos públicos conserven las garantías de release. */
final class ReleaseGateTest extends TestCase {
	/** Los siete bloques públicos usan metadata dinámica compatible con WordPress 6.6. */
	public function test_public_blocks_use_api_three_and_server_rendering(): void {
		$blocks = array(
			'restaurante-menu',
			'restaurante-pizza-builder',
			'restaurante-cart',
			'restaurante-checkout',
			'restaurante-order-status',
			'restaurante-reservations',
			'restaurante-saved-pizzas',
		);

		foreach ( $blocks as $directory ) {
			$path = dirname( __DIR__ ) . '/src/blocks/' . $directory . '/block.json';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Archivo local versionado del gate.
			$metadata = json_decode( (string) file_get_contents( $path ), true );

			$this->assertIsArray( $metadata, $directory );
			$this->assertSame( 3, $metadata['apiVersion'], $directory );
			$this->assertSame( 'vicunav/' . $directory, $metadata['name'], $directory );
			$this->assertSame( 'file:./render.php', $metadata['render'], $directory );
			$this->assertFalse( $metadata['supports']['html'], $directory );
		}
	}

	/** El runtime propio no introduce tracking, logs de navegador ni recursos remotos. */
	public function test_frontend_sources_have_no_remote_or_persistent_tracking_surface(): void {
		$forbidden = array( 'localStorage', 'document.cookie', 'console.log', 'console.debug', 'http://', 'https://' );
		$iterator  = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(
				dirname( __DIR__ ) . '/src/blocks',
				FilesystemIterator::SKIP_DOTS
			)
		);

		foreach ( $iterator as $file ) {
			if ( ! $file instanceof SplFileInfo || ! in_array( $file->getExtension(), array( 'js', 'scss' ), true ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Archivo local versionado del gate.
			$contents = (string) file_get_contents( $file->getPathname() );
			foreach ( $forbidden as $value ) {
				$this->assertStringNotContainsString( $value, $contents, $file->getPathname() );
			}
		}
	}

	/** Los tokens recuperables permanecen en memoria o sesión y se quitan del estado. */
	public function test_guest_recovery_tokens_are_not_persisted_beyond_the_session(): void {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Archivos locales versionados del gate.
		$commerce     = (string) file_get_contents( dirname( __DIR__ ) . '/src/blocks/restaurante-commerce-assets/view.js' );
		$reservations = (string) file_get_contents( dirname( __DIR__ ) . '/src/blocks/restaurante-reservations/view.js' );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertStringContainsString( 'sessionStorage.setItem', $commerce );
		$this->assertStringContainsString( 'delete order.access_token', $commerce );
		$this->assertStringContainsString( 'sessionStorage.setItem', $reservations );
		$this->assertStringContainsString( 'delete reservation.access_token', $reservations );
		$this->assertStringNotContainsString( 'localStorage', $commerce . $reservations );
	}
}
