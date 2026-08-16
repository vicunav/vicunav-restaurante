<?php
/**
 * Migración fundacional del catálogo de menú.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Migrations;

use Vicu\Restaurante\Menu\CatalogRevision;

/**
 * Inicializa únicamente la revisión operativa, sin contenido de demostración.
 *
 * @internal
 */
final class InitializeMenuCatalog extends Migration {
	/**
	 * Indica si este intento creó el option que puede compensar.
	 *
	 * @var bool
	 */
	private bool $created = false;

	/**
	 * {@inheritDoc}
	 */
	public function version(): int {
		return 2;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_applied(): bool {
		$revision = get_option( CatalogRevision::OPTION_NAME, false );

		return false !== $revision && 1 <= (int) $revision;
	}

	/**
	 * {@inheritDoc}
	 */
	public function up(): bool {
		if ( ! $this->is_applied() ) {
			$this->created = add_option( CatalogRevision::OPTION_NAME, '1', '', false );

			if ( ! $this->created ) {
				return false;
			}
		}

		return $this->is_applied();
	}

	/**
	 * {@inheritDoc}
	 */
	public function down(): void {
		if ( $this->created ) {
			delete_option( CatalogRevision::OPTION_NAME );
		}
	}
}
