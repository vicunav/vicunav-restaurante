<?php
/**
 * Pruebas del bootstrap contractual.
 *
 * @package Vicunav_Restaurante
 */

use PHPUnit\Framework\TestCase;
use Vicu\Restaurante\DependencyRequirements;

/**
 * Verifica versiones, autoload, dependencias y publicación del contrato.
 */
final class BootstrapTest extends TestCase {
	/**
	 * Carga el plugin una sola vez.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__ ) . '/vicunav-restaurante.php';
	}

	/**
	 * El entry point publica versiones y rutas consistentes.
	 *
	 * @return void
	 */
	public function test_defines_foundational_constants(): void {
		$this->assertSame( '0.11.0', VICU_RESTAURANTE_VERSION );
		$this->assertSame( '1.0.0', VICU_RESTAURANTE_CONTRACT_VERSION );
		$this->assertSame( '8', VICU_RESTAURANTE_DB_VERSION );
		$this->assertSame(
			realpath( dirname( __DIR__ ) . '/vicunav-restaurante.php' ),
			realpath( VICU_RESTAURANTE_PLUGIN_FILE )
		);
		$this->assertSame( dirname( __DIR__ ) . '/', VICU_RESTAURANTE_PATH );
	}

	/**
	 * El entry point registra la instalación desde el archivo principal.
	 *
	 * @return void
	 */
	public function test_registers_activation_hook_from_entry_point(): void {
		global $vicu_restaurante_test_activation_hooks;

		$this->assertCount( 1, $vicu_restaurante_test_activation_hooks );
		$this->assertSame( VICU_RESTAURANTE_PLUGIN_FILE, $vicu_restaurante_test_activation_hooks[0]['file'] );
		$this->assertSame( 'Vicu\\Restaurante\\activate', $vicu_restaurante_test_activation_hooks[0]['callback'] );
	}

	/**
	 * El entry point registra limpieza de cron sin borrar datos.
	 *
	 * @return void
	 */
	public function test_registers_deactivation_hook_from_entry_point(): void {
		global $vicu_restaurante_test_deactivation_hooks;

		$this->assertCount( 1, $vicu_restaurante_test_deactivation_hooks );
		$this->assertSame( VICU_RESTAURANTE_PLUGIN_FILE, $vicu_restaurante_test_deactivation_hooks[0]['file'] );
		$this->assertSame( 'Vicu\\Restaurante\\deactivate', $vicu_restaurante_test_deactivation_hooks[0]['callback'] );
	}

	/**
	 * El autoloader resuelve las clases propias desde src/.
	 *
	 * @return void
	 */
	public function test_autoloads_plugin_classes(): void {
		$this->assertTrue( class_exists( DependencyRequirements::class ) );
	}

	/**
	 * El bootstrap se registra después de core y pagos.
	 *
	 * @return void
	 */
	public function test_registers_bootstrap_at_priority_twenty(): void {
		global $vicu_restaurante_test_actions;

		$callbacks = $vicu_restaurante_test_actions['plugins_loaded'] ?? array();

		$this->assertCount( 1, $callbacks );
		$this->assertSame( 20, $callbacks[0]['priority'] );
		$this->assertSame( 'Vicu\\Restaurante\\bootstrap', $callbacks[0]['callback'] );
	}

	/**
	 * Los rangos y superficies incompatibles fallan con un código estable.
	 *
	 * @dataProvider invalid_dependency_states
	 *
	 * @param array<string, bool|string|null> $dependencies Estado que se validará.
	 * @param string                          $expected     Código esperado.
	 * @return void
	 */
	public function test_rejects_incompatible_dependencies( array $dependencies, string $expected ): void {
		$this->assertSame( $expected, DependencyRequirements::validate( $dependencies ) );
	}

	/**
	 * Casos incompatibles representativos de las dos dependencias.
	 *
	 * @return array<string, array{0: array<string, bool|string|null>, 1: string}>
	 */
	public static function invalid_dependency_states(): array {
		$compatible = array(
			'core_contract_version'   => '1.0.0',
			'core_classes_available'  => true,
			'pagos_contract_version'  => '0.3.0',
			'pagos_classes_available' => true,
		);

		return array(
			'core ausente'          => array(
				array_replace( $compatible, array( 'core_contract_version' => null ) ),
				DependencyRequirements::ERROR_CORE_UNAVAILABLE,
			),
			'core mayor siguiente'  => array(
				array_replace( $compatible, array( 'core_contract_version' => '2.0.0' ) ),
				DependencyRequirements::ERROR_CORE_INCOMPATIBLE,
			),
			'api core incompleta'   => array(
				array_replace( $compatible, array( 'core_classes_available' => false ) ),
				DependencyRequirements::ERROR_CORE_UNAVAILABLE,
			),
			'pagos anterior'        => array(
				array_replace( $compatible, array( 'pagos_contract_version' => '0.2.9' ) ),
				DependencyRequirements::ERROR_PAGOS_INCOMPATIBLE,
			),
			'pagos mayor siguiente' => array(
				array_replace( $compatible, array( 'pagos_contract_version' => '1.0.0' ) ),
				DependencyRequirements::ERROR_PAGOS_INCOMPATIBLE,
			),
			'api pagos incompleta'  => array(
				array_replace( $compatible, array( 'pagos_classes_available' => false ) ),
				DependencyRequirements::ERROR_PAGOS_UNAVAILABLE,
			),
		);
	}

	/**
	 * Un error registra aviso y una combinación compatible publica una sola vez.
	 *
	 * @return void
	 */
	public function test_bootstrap_guards_and_publishes_contract_once(): void {
		global $vicu_restaurante_test_actions;
		global $vicu_restaurante_test_fired_actions;

		$missing_core = array(
			'core_contract_version'   => null,
			'core_classes_available'  => false,
			'pagos_contract_version'  => '0.3.0',
			'pagos_classes_available' => true,
		);

		\Vicu\Restaurante\bootstrap_with_dependencies( $missing_core );

		$this->assertArrayHasKey( 'admin_notices', $vicu_restaurante_test_actions );
		$this->assertArrayNotHasKey( 'vicu_restaurante_loaded', $vicu_restaurante_test_fired_actions );

		$compatible = DependencyRequirements::inspect();
		\Vicu\Restaurante\bootstrap_with_dependencies( $compatible );
		\Vicu\Restaurante\bootstrap_with_dependencies( $compatible );

		$this->assertSame( 1, did_action( 'vicu_restaurante_loaded' ) );
		$this->assertSame(
			array( '0.11.0', '1.0.0' ),
			$vicu_restaurante_test_fired_actions['vicu_restaurante_loaded'][0]
		);
	}

	/**
	 * El aviso solo se muestra a quien puede administrar plugins.
	 *
	 * @return void
	 */
	public function test_dependency_notice_requires_capability(): void {
		global $vicu_restaurante_test_can_activate_plugins;

		$vicu_restaurante_test_can_activate_plugins = false;
		ob_start();
		\Vicu\Restaurante\render_dependency_notice( DependencyRequirements::ERROR_CORE_UNAVAILABLE );
		$unauthorized_output = ob_get_clean();

		$this->assertSame( '', $unauthorized_output );

		$vicu_restaurante_test_can_activate_plugins = true;
		ob_start();
		\Vicu\Restaurante\render_dependency_notice( DependencyRequirements::ERROR_CORE_UNAVAILABLE );
		$authorized_output = ob_get_clean();

		$this->assertStringContainsString( 'notice notice-error', $authorized_output );
		$this->assertStringContainsString( 'Vicunav Plugin Core', $authorized_output );
	}

	/**
	 * El header conserva compatibilidad y dependencias acordadas.
	 *
	 * @return void
	 */
	public function test_plugin_header_declares_requirements(): void {
		$file     = new SplFileObject( dirname( __DIR__ ) . '/vicunav-restaurante.php' );
		$contents = '';

		while ( ! $file->eof() ) {
			$contents .= $file->fgets();
		}

		$this->assertStringContainsString( 'Version:           0.11.0', $contents );
		$this->assertStringContainsString( 'Requires at least: 6.6', $contents );
		$this->assertStringContainsString( 'Requires PHP:      8.1', $contents );
		$this->assertStringContainsString(
			'Requires Plugins:  vicunav-plugin-core, vicunav-pagos',
			$contents
		);
		$this->assertStringContainsString( 'Text Domain:       vicunav-restaurante', $contents );
	}

	/**
	 * El contrato distingue lo disponible de las superficies futuras.
	 *
	 * @return void
	 */
	public function test_public_contract_documents_implementation_state(): void {
		$file     = new SplFileObject( dirname( __DIR__ ) . '/docs/contrato-publico.md' );
		$contract = '';

		while ( ! $file->eof() ) {
			$contract .= $file->fgets();
		}

		$this->assertStringContainsString( 'contrato 1.0.0 aprobado', $contract );
		$this->assertStringContainsString( '| Versiones, autoload, dependencias y hook de carga | REST-02B | Implementado |', $contract );
		$this->assertStringContainsString( '| Capabilities, migraciones e instalación | REST-02C | Implementado |', $contract );
		$this->assertStringContainsString( '| Menú estructurado | REST-02D | Implementado |', $contract );
		$this->assertStringContainsString( '| Integración con pagos | REST-02J | Implementado |', $contract );
		$this->assertStringContainsString( '| Reservas | REST-02K | Implementado |', $contract );
		$this->assertStringContainsString( "'vicu_restaurante_loaded'", $contract );
	}
}
