<?php
/**
 * Fixture de PostType para la suite de integración.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Shared\Tests;

use Vicu\Restaurante\Shared\PostType;

/**
 * Implementación controlada del contrato para las pruebas.
 */
final class PostTypeFixture extends PostType {
	/**
	 * Construye el tipo de contenido controlado.
	 *
	 * @param string               $slug Slug de prueba.
	 * @param array<string, mixed> $args Argumentos de prueba.
	 */
	public function __construct(
		private string $slug,
		private array $args = array()
	) {}

	/**
	 * Devuelve el slug de prueba.
	 *
	 * @return string
	 */
	protected function get_slug(): string {
		return $this->slug;
	}

	/**
	 * Devuelve los argumentos de prueba.
	 *
	 * @return array<string, mixed>
	 */
	protected function get_args(): array {
		return $this->args;
	}
}
