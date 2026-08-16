<?php
/**
 * Contrato interno de una migración reversible.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Migrations;

/**
 * Cada versión implementa comprobación, subida y compensación.
 *
 * @internal
 */
abstract class Migration {
	/**
	 * Número monotónico de la migración.
	 *
	 * @return int
	 */
	abstract public function version(): int;

	/**
	 * Comprueba si el cambio físico ya existe.
	 *
	 * @return bool
	 */
	abstract public function is_applied(): bool;

	/**
	 * Aplica el cambio de forma idempotente.
	 *
	 * @return bool
	 */
	abstract public function up(): bool;

	/**
	 * Compensa únicamente recursos creados por este intento.
	 *
	 * @return void
	 */
	abstract public function down(): void;
}
