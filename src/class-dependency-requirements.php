<?php
/**
 * Requisitos contractuales de los paquetes de los que depende el vertical.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante;

/**
 * Inspecciona versiones y clases públicas sin leer persistencia ajena.
 *
 * @internal
 */
final class DependencyRequirements {
	public const ERROR_CORE_UNAVAILABLE   = 'vicu_restaurante_core_unavailable';
	public const ERROR_CORE_INCOMPATIBLE  = 'vicu_restaurante_core_incompatible';
	public const ERROR_PAGOS_UNAVAILABLE  = 'vicu_restaurante_pagos_unavailable';
	public const ERROR_PAGOS_INCOMPATIBLE = 'vicu_restaurante_pagos_incompatible';

	/**
	 * Obtiene el estado observable de las dependencias públicas.
	 *
	 * @return array<string, bool|string|null>
	 */
	public static function inspect(): array {
		return array(
			'core_contract_version'   => defined( 'VICU_CORE_CONTRACT_VERSION' ) ? (string) constant( 'VICU_CORE_CONTRACT_VERSION' ) : null,
			'core_classes_available'  => self::classes_available(
				array(
					'Vicu\\Core\\PostType',
					'Vicu\\Core\\Rest',
					'Vicu\\Core\\Security',
					'Vicu\\Core\\Settings',
				)
			),
			'pagos_contract_version'  => defined( 'VICU_PAGOS_CONTRACT_VERSION' ) ? (string) constant( 'VICU_PAGOS_CONTRACT_VERSION' ) : null,
			'pagos_classes_available' => self::classes_available(
				array(
					'Vicu\\Pagos\\ManualPaymentProvider',
					'Vicu\\Pagos\\PaymentRequests',
					'Vicu\\Pagos\\PaymentRequestState',
				)
			),
		);
	}

	/**
	 * Valida el estado observable contra los rangos aceptados.
	 *
	 * @param array<string, bool|string|null> $dependencies Estado de dependencias.
	 * @return string|null Código de error o null cuando el estado es compatible.
	 */
	public static function validate( array $dependencies ): ?string {
		$core_version = $dependencies['core_contract_version'] ?? null;

		if ( ! is_string( $core_version ) || '' === $core_version ) {
			return self::ERROR_CORE_UNAVAILABLE;
		}

		if ( version_compare( $core_version, '1.0.0', '<' ) || version_compare( $core_version, '2.0.0', '>=' ) ) {
			return self::ERROR_CORE_INCOMPATIBLE;
		}

		if ( true !== ( $dependencies['core_classes_available'] ?? false ) ) {
			return self::ERROR_CORE_UNAVAILABLE;
		}

		$pagos_version = $dependencies['pagos_contract_version'] ?? null;

		if ( ! is_string( $pagos_version ) || '' === $pagos_version ) {
			return self::ERROR_PAGOS_UNAVAILABLE;
		}

		if ( version_compare( $pagos_version, '0.3.0', '<' ) || version_compare( $pagos_version, '1.0.0', '>=' ) ) {
			return self::ERROR_PAGOS_INCOMPATIBLE;
		}

		if ( true !== ( $dependencies['pagos_classes_available'] ?? false ) ) {
			return self::ERROR_PAGOS_UNAVAILABLE;
		}

		return null;
	}

	/**
	 * Comprueba que todas las clases requeridas puedan resolverse.
	 *
	 * @param array<int, string> $classes Clases públicas requeridas.
	 * @return bool
	 */
	private static function classes_available( array $classes ): bool {
		foreach ( $classes as $class_name ) {
			if ( ! class_exists( $class_name ) ) {
				return false;
			}
		}

		return true;
	}
}
