<?php
/**
 * Bricks 2.4 native unified-transfer adapter.
 *
 * Isolates every interaction with the Bricks 2.4 unified global transfer
 * engine behind one small, testable seam. The adapter:
 *
 * - Detects the installed Bricks native transfer contract (schema, version,
 *   public methods, transfer type IDs, registered abilities).
 * - Prefers the registered WordPress Abilities API entry points when a safe
 *   callable invocation API (`wp_get_ability()` -> `execute()`) is available.
 * - Falls back to the public `Bricks\Unified_Global_Transfer` static methods
 *   only, and in that case enforces the plugin's own tested permission and
 *   policy matrix (the public methods do not reproduce every check performed
 *   by the abilities wrapper).
 * - Treats the native package as opaque bytes: it never parses, rewrites, or
 *   re-ZIPs the native package, never touches native-owned options, and never
 *   calls private native methods.
 * - Normalizes every failure to `WP_Error`.
 *
 * This file is additive for the 1.1.0 release and is wired into the plugin
 * transfer workflow.
 *
 * @package BricksIE
 * @since   1.1.0
 */

class Bricks_IE_Bricks_Transfer_Adapter {

	/**
	 * Native manifest schema identifier this plugin was audited against.
	 *
	 * @var string
	 */
	const EXPECTED_SCHEMA = 'bricks/unified-global-transfer';

	/**
	 * Native manifest schema version this plugin was audited against.
	 *
	 * @var int
	 */
	const EXPECTED_VERSION = 1;

	/**
	 * Registered ability names, preferred when the Abilities API is available.
	 *
	 * @var string
	 */
	const ABILITY_LIST    = 'bricks/list-transfer-items';
	const ABILITY_EXPORT  = 'bricks/export-transfer-package';
	const ABILITY_INSPECT = 'bricks/inspect-transfer-package';
	const ABILITY_IMPORT  = 'bricks/import-transfer-package';
	const ABILITY_REGENERATE_CSS = 'bricks/regenerate-css-files';

	/**
	 * Transfer type IDs supported by the audited Bricks 2.4 native engine.
	 *
	 * @var string[]
	 */
	const KNOWN_TYPE_IDS = array(
		'color-palettes',
		'theme-styles',
		'classes',
		'variables',
		'custom-fonts',
		'icon-manager',
		'breakpoints',
		'global-queries',
		'components',
		'templates',
		'settings',
		'custom-capabilities',
	);

	/**
	 * Settings-tab item IDs treated as sensitive by the plugin policy.
	 *
	 * `api-keys` and `custom-code` are natively sensitive. The `templates`
	 * settings tab carries the template access passwords
	 * (`myTemplatesPassword` / `remoteTemplatesPassword`), and `settings` /
	 * `all` are aggregate selectors that include the sensitive tabs. The
	 * native manifest groups settings by tab, so the whole tab is treated as
	 * sensitive (fail closed).
	 *
	 * @var string[]
	 */
	const SENSITIVE_SETTINGS_IDS = array(
		'settings',
		'all',
		'api-keys',
		'custom-code',
		'templates',
	);

	/** Settings selectors that are sensitive on import (Bricks does not filter them). */
	const IMPORT_SENSITIVE_SETTINGS_IDS = array(
		'general', 'templates', 'builder', 'performance', 'maintenance', 'api-keys',
		'custom-code', 'woocommerce', 'settings', 'all',
	);

	/**
	 * Transfer types whose payload can carry executable code.
	 *
	 * @var string[]
	 */
	const CODE_BEARING_TYPES = array(
		'global-queries',
		'components',
	);

	/**
	 * Fully-qualified native transfer class name.
	 *
	 * @var string
	 */
	private $native_class;

	/**
	 * Fully-qualified Bricks builder-permissions class name.
	 *
	 * @var string
	 */
	private $permissions_class;

	/**
	 * Fully-qualified Bricks capabilities class name.
	 *
	 * @var string
	 */
	private $capabilities_class;

	/**
	 * Constructor.
	 *
	 * The collaborator class names are injectable so the contract can be
	 * exercised against stubs in isolation; production uses the defaults.
	 *
	 * @param array $options {
	 *     Optional overrides.
	 *
	 *     @type string $native_class       Native transfer class.
	 *     @type string $permissions_class  Bricks builder-permissions class.
	 *     @type string $capabilities_class Bricks capabilities class.
	 * }
	 */
	public function __construct( array $options = array() ) {
		$this->native_class       = $this->normalize_class_name(
			isset( $options['native_class'] ) ? $options['native_class'] : 'Bricks\Unified_Global_Transfer'
		);
		$this->permissions_class  = $this->normalize_class_name(
			isset( $options['permissions_class'] ) ? $options['permissions_class'] : 'Bricks\Builder_Permissions'
		);
		$this->capabilities_class = $this->normalize_class_name(
			isset( $options['capabilities_class'] ) ? $options['capabilities_class'] : 'Bricks\Capabilities'
		);
	}

	// ==================================================================
	// Public contract
	// ==================================================================

	/**
	 * Detect and report the installed native transfer capabilities.
	 *
	 * Informational only: this method never writes and never returns a
	 * `WP_Error`. Callers inspect the report and decide whether to proceed.
	 *
	 * @return array {
	 *     @type bool   $available      Native contract is present and matches.
	 *     @type string $native_class   Native class name probed.
	 *     @type string $bricks_version Bricks version, empty when not active.
	 *     @type string $channel        stable|beta|alpha|rc, empty when unknown.
	 *     @type string|null $schema    Native manifest schema, null when missing.
	 *     @type bool   $schema_valid   Schema matches the audited value.
	 *     @type int|null $version      Native manifest version, null when missing.
	 *     @type bool   $version_valid  Version matches the audited value.
	 *     @type array  $type_ids       Native transfer type IDs.
	 *     @type bool   $types_valid    Every audited type ID is present.
 *     @type array  $methods        Required public method => actual availability.
 *     @type bool   $methods_valid  All required public methods are present.
 *     @type bool   $routes_valid   Every operation has an ability or static route.
	 *     @type array  $abilities      Ability name => registered.
	 *     @type bool   $use_abilities  All four transfer abilities are registered.
	 *     @type array  $errors         Machine-readable drift/availability codes.
	 * }
	 */
	public function detect_capabilities() {
		$report = array(
			'available'      => false,
			'native_class'   => $this->native_class,
			'bricks_version' => defined( 'BRICKS_VERSION' ) ? (string) constant( 'BRICKS_VERSION' ) : '',
			'channel'        => '',
			'schema'         => null,
			'schema_valid'   => false,
			'version'        => null,
			'version_valid'  => false,
			'type_ids'       => array(),
			'types_valid'    => false,
			'methods'        => array(),
			'methods_valid'  => false,
			'routes_valid'   => false,
			'abilities'      => array(),
			'use_abilities'  => false,
			'errors'         => array(),
		);

		if ( '' !== $report['bricks_version'] ) {
			$report['channel'] = $this->detect_channel( $report['bricks_version'] );
		}

		if ( ! class_exists( $this->native_class ) ) {
			$report['errors'][] = 'native_class_missing';
			return $report;
		}

		if ( defined( $this->native_class . '::MANIFEST_SCHEMA' ) ) {
			$report['schema']       = (string) constant( $this->native_class . '::MANIFEST_SCHEMA' );
			$report['schema_valid'] = ( self::EXPECTED_SCHEMA === $report['schema'] );
			if ( ! $report['schema_valid'] ) {
				$report['errors'][] = 'schema_mismatch';
			}
		} else {
			$report['errors'][] = 'schema_missing';
		}

		if ( defined( $this->native_class . '::MANIFEST_VERSION' ) ) {
			$report['version']       = (int) constant( $this->native_class . '::MANIFEST_VERSION' );
			$report['version_valid'] = ( self::EXPECTED_VERSION === $report['version'] );
			if ( ! $report['version_valid'] ) {
				$report['errors'][] = 'version_mismatch';
			}
		} else {
			$report['errors'][] = 'version_missing';
		}

		$all_abilities = true;
		foreach ( $this->ability_names() as $name ) {
			$available                = $this->ability_available( $name );
			$report['abilities'][ $name ] = $available;
			if ( ! $available ) {
				$all_abilities = false;
			}
		}
		$report['use_abilities'] = $all_abilities;

		if ( $this->native_method_available( 'get_transfer_type_ids' ) ) {
			$type_ids = call_user_func( array( $this->native_class, 'get_transfer_type_ids' ) );
			if ( is_array( $type_ids ) ) {
				$report['type_ids'] = array_values( array_map( 'strval', $type_ids ) );
				$missing            = array_diff( self::KNOWN_TYPE_IDS, $report['type_ids'] );
				$report['types_valid'] = empty( $missing );
				if ( ! $report['types_valid'] ) {
					$report['errors'][] = 'types_missing';
				}
			} else {
				$report['errors'][] = 'type_ids_invalid';
			}
		} elseif ( $report['abilities'][ self::ABILITY_LIST ] ) {
			$report['type_ids']    = self::KNOWN_TYPE_IDS;
			$report['types_valid'] = true;
		} else {
			$report['errors'][] = 'type_ids_missing';
		}

		$methods_valid = true;
		foreach ( $this->required_methods() as $method ) {
			$available = $this->native_method_available( $method );
			$report['methods'][ $method ] = $available;
			if ( ! $available ) {
				$methods_valid = false;
			}
		}
		$report['methods_valid'] = $methods_valid;
		if ( ! $methods_valid ) {
			$report['errors'][] = 'method_missing';
		}

		$routes = array(
			'list_export_items'    => $report['abilities'][ self::ABILITY_LIST ] || $this->native_method_available( 'list_export_items' ),
			'export_package'       => $report['abilities'][ self::ABILITY_EXPORT ] || $this->native_method_available( 'export_package' ),
			'inspect_package_bytes'=> $report['abilities'][ self::ABILITY_INSPECT ] || $this->native_method_available( 'inspect_package_bytes' ),
			'import_package_bytes' => $report['abilities'][ self::ABILITY_IMPORT ] || $this->native_method_available( 'import_package_bytes' ),
		);
		$report['routes_valid'] = ! in_array( false, $routes, true );
		if ( ! $report['routes_valid'] ) {
			$report['errors'][] = 'method_missing';
		}

		$report['available'] = $report['schema_valid'] && $report['version_valid'] && $report['types_valid'] && $report['routes_valid'];

		return $report;
	}

	/**
	 * List exportable native transfer items.
	 *
	 * @param array $types Optional transfer type IDs. Empty means every
	 *                     native-supported type the user may access.
	 * @return array|WP_Error Normalized `{ types, via }` on success.
	 */
	public function list_items( array $types = array() ) {
		$verify = $this->verify_native( 'list' );
		if ( is_wp_error( $verify ) ) {
			return $verify;
		}

		if ( ! $this->user_can( 'manage_options' ) ) {
			return $this->permission_error( 'manage_options' );
		}

		$explicit_types = ! empty( $types );
		if ( empty( $types ) ) {
			$types = $this->get_native_type_ids();
			if ( is_wp_error( $types ) ) {
				return $types;
			}
		}
		$types = $this->sanitize_type_list( $types );

		$validate = $this->validate_requested_types( $types );
		if ( is_wp_error( $validate ) ) {
			return $validate;
		}

		// Listing is read-only: filter to the types the user may access rather
		// than hard-failing, matching the native permissive listing semantics.
		$types = $this->filter_accessible_types( $types );
		if ( $explicit_types && empty( $types ) ) {
			return array(
				'types' => array(),
				'via'   => $this->get_ability( self::ABILITY_LIST ) ? 'ability' : 'native',
			);
		}

		$ability = $this->get_ability( self::ABILITY_LIST );
		if ( $ability ) {
			$result = $ability->execute( array( 'includeTypes' => $types ) );
			$via    = 'ability';
		} else {
			$result = call_user_func( array( $this->native_class, 'list_export_items' ), $types );
			$via    = 'native';
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) ) {
			return $this->invalid_result_error( 'list' );
		}

		$validate = $this->validate_list_result( $result );
		if ( is_wp_error( $validate ) ) {
			return $validate;
		}

		return $this->normalize_list_result( $result, $via );
	}

	/**
	 * Export a native transfer package for an explicit selection.
	 *
	 * @param array $selection {
	 *     @type string[] $types Transfer type IDs.
	 *     @type array    $items Item IDs keyed by transfer type.
	 * }
	 * @param array $policy Optional policy flags, see import_package().
	 * @return array|WP_Error Normalized package result on success.
	 */
	public function export_package( array $selection, array $policy = array() ) {
		$verify = $this->verify_native( 'export' );
		if ( is_wp_error( $verify ) ) {
			return $verify;
		}

		$types = $this->selection_types( $selection );
		$items = $this->selection_items( $selection );

		if ( empty( $types ) ) {
			return $this->no_types_error();
		}

		$validate = $this->validate_requested_types( $types );
		if ( is_wp_error( $validate ) ) {
			return $validate;
		}

		$explicit = $this->require_explicit_items( $types, $items );
		if ( is_wp_error( $explicit ) ) {
			return $explicit;
		}

		$ability = $this->get_ability( self::ABILITY_EXPORT );
		$permissions = $this->enforce_permissions( $types, 'export', ! $ability );
		if ( is_wp_error( $permissions ) ) {
			return $permissions;
		}

		$valid_items = $this->validate_item_values( $types, $items );
		if ( is_wp_error( $valid_items ) ) {
			return $valid_items;
		}
		$listed = $this->list_items( $types );
		if ( is_wp_error( $listed ) ) {
			return $listed;
		}
		$known_items = $this->item_ids_from_list( $listed['types'] );
		$known = $this->validate_items_against_known( $types, $items, $known_items );
		if ( is_wp_error( $known ) ) {
			return $known;
		}

		$sensitive = $this->enforce_sensitive_settings( $types, $items, $policy, false );
		if ( is_wp_error( $sensitive ) ) {
			return $sensitive;
		}

		if ( $ability ) {
			$result = $ability->execute(
				array(
					'types'                  => $types,
					'items'                  => $items,
					'allowSensitiveSettings' => ! empty( $policy['allow_sensitive_settings'] ),
				)
			);
			$via    = 'ability';
		} else {
			$result = call_user_func( array( $this->native_class, 'export_package' ), $types, $items );
			$via    = 'native';
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) ) {
			return $this->invalid_result_error( 'export' );
		}

		$validate = $this->validate_export_result( $result );
		if ( is_wp_error( $validate ) ) {
			return $validate;
		}

		return $this->normalize_export_result( $result, $via );
	}

	/**
	 * Inspect native package bytes without writing.
	 *
	 * @param string $bytes Decoded native ZIP bytes.
	 * @return array|WP_Error Normalized manifest result on success.
	 */
	public function inspect_package( $bytes ) {
		if ( ! is_string( $bytes ) || '' === $bytes ) {
			return new WP_Error(
				'bricks_ie_package_empty',
				__( 'No native transfer package bytes were provided.', 'bricks-ie' )
			);
		}
		$size = $this->enforce_package_size( $bytes );
		if ( is_wp_error( $size ) ) {
			return $size;
		}
		if ( strlen( $bytes ) < 4 || "PK\x03\x04" !== substr( $bytes, 0, 4 ) ) {
			return $this->package_invalid_error( 'zip_signature_invalid' );
		}

		$verify = $this->verify_native( 'inspect' );
		if ( is_wp_error( $verify ) ) {
			return $verify;
		}

		if ( ! $this->user_can( 'manage_options' ) ) {
			return $this->permission_error( 'manage_options' );
		}

		$ability = $this->get_ability( self::ABILITY_INSPECT );
		if ( $ability ) {
			$result = $ability->execute( array( 'zipBase64' => base64_encode( $bytes ) ) );
			$via    = 'ability';
		} else {
			$result = call_user_func( array( $this->native_class, 'inspect_package_bytes' ), $bytes );
			$via    = 'native';
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) ) {
			return $this->invalid_result_error( 'inspect' );
		}

		$validate = $this->validate_inspect_result( $result, $bytes );
		if ( is_wp_error( $validate ) ) {
			return $validate;
		}

		return $this->normalize_inspect_result( $result, $via );
	}

	/**
	 * Import an explicit selection from native package bytes.
	 *
	 * @param string $bytes     Decoded native ZIP bytes.
	 * @param array  $selection `{ types: string[], items: array }`.
	 * @param array  $policy {
	 *     Optional policy flags. All default to the safe value.
	 *
	 *     @type bool   $allow_sensitive_settings Required for sensitive settings.
	 *     @type bool   $allow_overwrite          Required for any `replace`.
	 *     @type bool   $import_images            Download template images. Default false.
	 *     @type string $conflict_mode            `skip` (default) or `replace`.
	 *     @type array  $conflict_decisions       Per-type item decisions.
	 * }
	 * @return array|WP_Error Normalized import result on success.
	 */
	public function import_package( $bytes, array $selection, array $policy = array() ) {
		if ( ! is_string( $bytes ) || '' === $bytes ) {
			return new WP_Error(
				'bricks_ie_package_empty',
				__( 'No native transfer package bytes were provided.', 'bricks-ie' )
			);
		}
		$size = $this->enforce_package_size( $bytes );
		if ( is_wp_error( $size ) ) {
			return $size;
		}

		$verify = $this->verify_native( 'import' );
		if ( is_wp_error( $verify ) ) {
			return $verify;
		}

		$types = $this->selection_types( $selection );
		$items = $this->selection_items( $selection );

		if ( empty( $types ) ) {
			return $this->no_types_error();
		}

		$validate = $this->validate_requested_types( $types );
		if ( is_wp_error( $validate ) ) {
			return $validate;
		}

		$explicit = $this->require_explicit_items( $types, $items );
		if ( is_wp_error( $explicit ) ) {
			return $explicit;
		}

		$valid_items = $this->validate_item_values( $types, $items );
		if ( is_wp_error( $valid_items ) ) {
			return $valid_items;
		}
		$ability = $this->get_ability( self::ABILITY_IMPORT );
		$permissions = $this->enforce_permissions( $types, 'import', ! $ability );
		if ( is_wp_error( $permissions ) ) {
			return $permissions;
		}

		$sensitive = $this->enforce_sensitive_settings( $types, $items, $policy, true );
		if ( is_wp_error( $sensitive ) ) {
			return $sensitive;
		}

		// Resolve authorization before inspection, which can expose package metadata.
		$import_images = false;
		if ( ! empty( $policy['import_images'] ) ) {
			if ( in_array( 'templates', $types, true ) && ! $this->user_can( 'upload_files' ) ) {
				return $this->permission_error( 'upload_files', 'templates' );
			}
			$import_images = true;
		}

		// Inspect once through the native route without re-entering inspect_package().
		$inspected = $this->inspect_for_import( $bytes );
		if ( is_wp_error( $inspected ) ) {
			return $inspected;
		}
		$known_items = $this->item_ids_from_manifest( $inspected['manifest'] );
		$known = $this->validate_items_against_known( $types, $items, $known_items, true );
		if ( is_wp_error( $known ) ) {
			return $known;
		}

		$conflict_mode = isset( $policy['conflict_mode'] ) ? (string) $policy['conflict_mode'] : 'skip';
		if ( ! in_array( $conflict_mode, array( 'skip', 'replace' ), true ) ) {
			$conflict_mode = 'skip';
		}
		$conflict_decisions = isset( $policy['conflict_decisions'] ) && is_array( $policy['conflict_decisions'] )
			? $policy['conflict_decisions']
			: array();

		$overwrite = $this->enforce_overwrite( $conflict_mode, $conflict_decisions, $policy );
		if ( is_wp_error( $overwrite ) ) {
			return $overwrite;
		}

		$zip_hash = hash( 'sha256', $bytes );

		if ( $ability ) {
			$input = array(
				'zipBase64'              => base64_encode( $bytes ),
				'expectedZipHash'        => $zip_hash,
				'types'                  => $types,
				'items'                  => $items,
				'conflictMode'           => $conflict_mode,
				'allowOverwrite'         => ! empty( $policy['allow_overwrite'] ),
				'allowSensitiveSettings' => ! empty( $policy['allow_sensitive_settings'] ),
				'importImages'           => $import_images,
			);
			if ( ! empty( $conflict_decisions ) ) {
				$input['conflictDecisions'] = $conflict_decisions;
			}
			$result = $ability->execute( $input );
			$via    = 'ability';
		} else {
			$result = call_user_func(
				array( $this->native_class, 'import_package_bytes' ),
				$bytes,
				$types,
				$items,
				$conflict_mode,
				$conflict_decisions,
				$import_images
			);
			$via    = 'native';
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) ) {
			return $this->invalid_result_error( 'import' );
		}

		return $this->normalize_import_result( $result, $via, $zip_hash );
	}

	/**
	 * Regenerate Bricks CSS files through the native public contract.
	 *
	 * @return array|WP_Error Normalized regeneration result.
	 */
	public function regenerate_css_files() {
		if ( ! $this->user_can( 'manage_options' ) ) {
			return $this->permission_error( 'manage_options' );
		}

		$ability = $this->get_ability( self::ABILITY_REGENERATE_CSS );
		if ( $ability ) {
			$result = $ability->execute( array() );
			return $this->process_css_regeneration_result( $result, 'ability' );
		}

		$maintenance = 'Bricks\\Abilities\\Maintenance';
		if ( ! class_exists( $maintenance, false ) && defined( 'BRICKS_PATH' ) ) {
			$maintenance_file = rtrim( BRICKS_PATH, '/\\' ) . '/includes/abilities/maintenance.php';
			if ( is_readable( $maintenance_file ) ) {
				require_once $maintenance_file;
			}
		}
		if ( class_exists( $maintenance ) && is_callable( array( $maintenance, 'regenerate_css_files' ) ) ) {
			$result = call_user_func( array( $maintenance, 'regenerate_css_files' ), array() );
			return $this->process_css_regeneration_result( $result, 'maintenance' );
		}

		$fallback = 'Bricks\\Assets_Files';
		if ( ! class_exists( $fallback ) || ! is_callable( array( $fallback, 'regenerate_css_files' ) ) ) {
			return new WP_Error(
				'bricks_ie_css_regeneration_unavailable',
				__( 'The Bricks CSS regeneration contract is unavailable; refusing to call an unrecognized implementation.', 'bricks-ie' )
			);
		}

		$result = call_user_func( array( $fallback, 'regenerate_css_files' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) ) {
			return $this->invalid_css_regeneration_error( 'fallback_result_not_array' );
		}

		$files = array_values( $result );
		foreach ( $files as $file ) {
			if ( ! is_string( $file ) || '' === $file ) {
				return $this->invalid_css_regeneration_error( 'generated_file_invalid' );
			}
		}

		return array(
			'success'              => true,
			'generated_file_count' => count( $files ),
			'generated_files'      => $files,
			'css_loading'          => $this->get_css_loading(),
			'via'                  => 'fallback',
		);
	}

	// ==================================================================
	// Native contract verification
	// ==================================================================

	/**
	 * Public native methods required by the audited contract.
	 *
	 * @return string[]
	 */
	private function required_methods() {
		return array(
			'get_transfer_type_ids',
			'list_export_items',
			'export_package',
			'inspect_package_bytes',
			'import_package_bytes',
		);
	}

	/**
	 * Ability names used by the adapter.
	 *
	 * @return string[]
	 */
	private function ability_names() {
		return array(
			self::ABILITY_LIST,
			self::ABILITY_EXPORT,
			self::ABILITY_INSPECT,
			self::ABILITY_IMPORT,
		);
	}

	/**
	 * Verify the native contract before any operation that could write.
	 *
	 * Fails closed when the class, schema, version, or any required public
	 * method is missing or has drifted. Each operation may use its matching
	 * ability instead of its matching native method.
	 *
	 * @param string $operation Optional operation to verify. Empty verifies all.
	 * @return true|WP_Error
	 */
	private function verify_native( $operation = '' ) {
		if ( ! class_exists( $this->native_class ) ) {
			return new WP_Error(
				'bricks_ie_native_unavailable',
				sprintf(
					/* translators: %s: native class name. */
					__( 'The Bricks native transfer class %s is not available. Bricks 2.4 or newer must be active.', 'bricks-ie' ),
					$this->native_class
				),
				array( 'native_class' => $this->native_class )
			);
		}

		if ( ! defined( $this->native_class . '::MANIFEST_SCHEMA' )
			|| self::EXPECTED_SCHEMA !== constant( $this->native_class . '::MANIFEST_SCHEMA' ) ) {
			return new WP_Error(
				'bricks_ie_native_schema_mismatch',
				sprintf(
					/* translators: 1: expected schema, 2: native class. */
					__( 'The Bricks native transfer schema is not the expected "%1$s". Refusing to use an unrecognized native package contract (%2$s).', 'bricks-ie' ),
					self::EXPECTED_SCHEMA,
					$this->native_class
				),
				array( 'expected_schema' => self::EXPECTED_SCHEMA )
			);
		}

		if ( ! defined( $this->native_class . '::MANIFEST_VERSION' )
			|| self::EXPECTED_VERSION !== (int) constant( $this->native_class . '::MANIFEST_VERSION' ) ) {
			return new WP_Error(
				'bricks_ie_native_version_mismatch',
				sprintf(
					/* translators: 1: expected version, 2: native class. */
					__( 'The Bricks native transfer schema version is not the expected %1$d. Refusing to use an unrecognized native package contract (%2$s).', 'bricks-ie' ),
					self::EXPECTED_VERSION,
					$this->native_class
				),
				array( 'expected_version' => self::EXPECTED_VERSION )
			);
		}

		$routes = array(
			'list_export_items'     => self::ABILITY_LIST,
			'export_package'        => self::ABILITY_EXPORT,
			'inspect_package_bytes' => self::ABILITY_INSPECT,
			'import_package_bytes'  => self::ABILITY_IMPORT,
		);
		$operation_methods = array(
			'list'    => array( 'list_export_items' ),
			'export'  => array( 'export_package' ),
			'inspect' => array( 'inspect_package_bytes' ),
			'import'  => array( 'import_package_bytes' ),
		);
		if ( isset( $operation_methods[ $operation ] ) ) {
			$routes = array_intersect_key( $routes, array_flip( $operation_methods[ $operation ] ) );
		}
		foreach ( $routes as $method => $ability_name ) {
			if ( ! $this->native_method_available( $method ) && ! $this->ability_available( $ability_name ) ) {
				return new WP_Error(
					'bricks_ie_native_method_missing',
					sprintf(
						/* translators: 1: method name, 2: native class. */
						__( 'The Bricks native transfer method %1$s() is missing or not publicly callable on %2$s.', 'bricks-ie' ),
						$method,
						$this->native_class
					),
					array( 'method' => $method )
				);
			}
		}

		return true;
	}

	/**
	 * Whether a native method exists and is publicly callable.
	 *
	 * `is_callable()` is false for private/protected methods when probed from
	 * outside the class, which also guarantees the adapter can never route to
	 * a private native method.
	 *
	 * @param string $method Method name.
	 * @return bool
	 */
	private function native_method_available( $method ) {
		return method_exists( $this->native_class, $method )
			&& is_callable( array( $this->native_class, $method ) );
	}

	/**
	 * Read the native transfer type IDs.
	 *
	 * @return string[]|WP_Error
	 */
	private function get_native_type_ids() {
		if ( ! $this->native_method_available( 'get_transfer_type_ids' ) ) {
			if ( $this->ability_available( self::ABILITY_LIST ) ) {
				return self::KNOWN_TYPE_IDS;
			}
			return new WP_Error(
				'bricks_ie_native_method_missing',
				__( 'The Bricks native transfer type ID method is unavailable.', 'bricks-ie' )
			);
		}
		$type_ids = call_user_func( array( $this->native_class, 'get_transfer_type_ids' ) );
		if ( ! is_array( $type_ids ) ) {
			return new WP_Error(
				'bricks_ie_native_type_ids_invalid',
				__( 'The Bricks native transfer engine did not return a usable list of transfer type IDs.', 'bricks-ie' )
			);
		}

		return array_values( array_map( 'strval', $type_ids ) );
	}

	/**
	 * Validate that requested types are known to the plugin and the native engine.
	 *
	 * @param string[] $types Requested transfer type IDs.
	 * @return true|WP_Error
	 */
	private function validate_requested_types( array $types ) {
		$native_ids = $this->get_native_type_ids();
		if ( is_wp_error( $native_ids ) ) {
			return $native_ids;
		}

		foreach ( $types as $type ) {
			if ( ! in_array( $type, self::KNOWN_TYPE_IDS, true ) ) {
				return new WP_Error(
					'bricks_ie_unsupported_transfer_type',
					sprintf(
						/* translators: %s: transfer type ID. */
						__( 'The transfer type "%s" is not supported by this plugin.', 'bricks-ie' ),
						$type
					),
					array( 'type' => $type )
				);
			}
			if ( ! in_array( $type, $native_ids, true ) ) {
				return new WP_Error(
					'bricks_ie_native_type_unavailable',
					sprintf(
						/* translators: %s: transfer type ID. */
						__( 'The transfer type "%s" is not available in the installed Bricks native transfer engine.', 'bricks-ie' ),
						$type
					),
					array( 'type' => $type )
				);
			}
		}

		return true;
	}

	// ==================================================================
	// Permission matrix
	// ==================================================================

	/**
	 * Plugin-side transfer-type permission requirements.
	 *
	 * `manage_options` is always required as the plugin baseline and is not
	 * repeated here. Keys:
	 *
	 * - `builder`:               Bricks builder permissions, all required.
	 * - `builder_any_on_import`: at least one required on import.
	 * - `wp`:                    WordPress capabilities, always required.
	 * - `wp_on_import`:          WordPress capabilities required on import.
	 * - `svg_on_import`:         requires SVG upload on import.
	 * - `code`:                  requires code execution when data moves.
	 *
	 * @return array
	 */
	private function get_type_requirements() {
		return array(
			'color-palettes'      => array(
				'builder' => array( 'edit_color_palettes' ),
			),
			'theme-styles'        => array(
				'builder' => array( 'access_theme_styles' ),
			),
			'classes'             => array(
				'builder'               => array( 'access_class_manager' ),
				'builder_any_on_import' => array( 'create_global_classes', 'edit_global_classes' ),
			),
			'variables'           => array(
				'builder' => array( 'access_variable_manager' ),
			),
			'custom-fonts'        => array(
				'builder'       => array( 'access_font_manager' ),
				'wp_on_import'  => array( 'upload_files' ),
			),
			'icon-manager'        => array(
				'builder'       => array( 'access_icon_manager' ),
				'wp_on_import'  => array( 'upload_files' ),
				'svg_on_import' => true,
			),
			'breakpoints'         => array(
				'builder' => array( 'access_breakpoints_manager' ),
			),
			'global-queries'      => array(
				'builder' => array( 'access_query_manager' ),
				'code'    => true,
			),
			'components'          => array(
				'builder' => array( 'import_export_components' ),
				'code'    => true,
			),
			'templates'           => array(
				'builder' => array( 'import_export_templates' ),
			),
			'settings'            => array(
				'wp' => array( 'manage_options' ),
			),
			'custom-capabilities' => array(
				'wp' => array( 'manage_options' ),
			),
		);
	}

	/**
	 * Hard-enforce the permission matrix for every selected type.
	 *
	 * @param string[] $types   Transfer type IDs.
	 * @param string   $context list|export|import.
	 * @param bool    $check_code Whether to apply the plugin-side code policy.
	 * @return true|WP_Error
	 */
	private function enforce_permissions( array $types, $context, $check_code = true ) {
		if ( ! $this->user_can( 'manage_options' ) ) {
			return $this->permission_error( 'manage_options' );
		}

		foreach ( $types as $type ) {
			$check = $this->check_type_permission( $type, $context, $check_code );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}

		return true;
	}

	/**
	 * Soft-filter types to those the user may access (used for read-only list).
	 *
	 * Types whose permission cannot be evaluated are excluded (fail closed).
	 *
	 * @param string[] $types Transfer type IDs.
	 * @return string[]
	 */
	private function filter_accessible_types( array $types ) {
		$accessible = array();
		foreach ( $types as $type ) {
			$check = $this->check_type_permission( $type, 'list' );
			if ( true === $check ) {
				$accessible[] = $type;
			}
		}

		return $accessible;
	}

	/**
	 * Evaluate the permission requirements for a single transfer type.
	 *
	 * @param string $type    Transfer type ID.
	 * @param string $context list|export|import.
	 * @param bool   $check_code Whether to apply the plugin-side code policy.
	 * @return true|WP_Error
	 */
	private function check_type_permission( $type, $context, $check_code = true ) {
		$requirements = $this->get_type_requirements();
		if ( ! array_key_exists( $type, $requirements ) ) {
			return new WP_Error(
				'bricks_ie_unknown_transfer_type',
				sprintf(
					/* translators: %s: transfer type ID. */
					__( 'No permission requirements are defined for the transfer type "%s"; refusing to proceed.', 'bricks-ie' ),
					$type
				),
				array( 'type' => $type )
			);
		}

		$requirement    = $requirements[ $type ];
		$is_import      = ( 'import' === $context );
		$transfers_data = in_array( $context, array( 'export', 'import' ), true );

		// WordPress capabilities.
		$wp_caps = isset( $requirement['wp'] ) ? (array) $requirement['wp'] : array();
		if ( $is_import && isset( $requirement['wp_on_import'] ) ) {
			$wp_caps = array_merge( $wp_caps, (array) $requirement['wp_on_import'] );
		}
		foreach ( $wp_caps as $cap ) {
			if ( ! $this->user_can( $cap ) ) {
				return $this->permission_error( $cap, $type );
			}
		}

		// Bricks builder permissions, all required.
		if ( ! empty( $requirement['builder'] ) ) {
			foreach ( (array) $requirement['builder'] as $permission ) {
				$has = $this->builder_permission( $permission );
				if ( null === $has ) {
					return $this->permission_unevaluable_error( $permission, $type );
				}
				if ( ! $has ) {
					return $this->permission_error( $permission, $type );
				}
			}
		}

		// Bricks builder permissions, at least one required on import.
		if ( $is_import && ! empty( $requirement['builder_any_on_import'] ) ) {
			$any_of        = (array) $requirement['builder_any_on_import'];
			$any_granted   = false;
			$all_evaluable = true;
			foreach ( $any_of as $permission ) {
				$has = $this->builder_permission( $permission );
				if ( null === $has ) {
					$all_evaluable = false;
					continue;
				}
				if ( $has ) {
					$any_granted = true;
				}
			}
			if ( ! $any_granted ) {
				$label = implode( ' or ', $any_of );
				if ( ! $all_evaluable ) {
					return $this->permission_unevaluable_error( $label, $type );
				}
				return $this->permission_error( $label, $type );
			}
		}

		// SVG upload on import.
		if ( $is_import && ! empty( $requirement['svg_on_import'] ) ) {
			$can = $this->can_upload_svg();
			if ( null === $can ) {
				return $this->permission_unevaluable_error( 'upload_svg', $type );
			}
			if ( ! $can ) {
				return $this->permission_error( 'upload_svg', $type );
			}
		}

		// Code execution, only when data actually moves.
		if ( $check_code && $transfers_data && ! empty( $requirement['code'] ) ) {
			$can = $this->can_execute_code();
			if ( null === $can ) {
				return $this->permission_unevaluable_error( 'execute_code', $type );
			}
			if ( ! $can ) {
				return $this->permission_error( 'execute_code', $type );
			}
		}

		return true;
	}

	// ==================================================================
	// Policy enforcement
	// ==================================================================

	/**
	 * Require explicit, non-empty item IDs for every selected type.
	 *
	 * An empty selection never means "all items".
	 *
	 * @param string[] $types Transfer type IDs.
	 * @param array    $items Item IDs keyed by type.
	 * @return true|WP_Error
	 */
	private function require_explicit_items( array $types, array $items ) {
		foreach ( $types as $type ) {
			if ( ! array_key_exists( $type, $items )
				|| ! is_array( $items[ $type ] )
				|| empty( $items[ $type ] ) ) {
				return new WP_Error(
					'bricks_ie_explicit_items_required',
					sprintf(
						/* translators: %s: transfer type ID. */
						__( 'An explicit, non-empty item selection is required for the transfer type "%s".', 'bricks-ie' ),
						$type
					),
					array( 'type' => $type )
				);
			}
		}

		return true;
	}

	/**
	 * Enforce the sensitive-settings policy.
	 *
	 * Sensitive settings require both the plugin's explicit authorization and
	 * `manage_options`.
	 *
	 * @param string[] $types  Transfer type IDs.
	 * @param array    $items  Item IDs keyed by type.
	 * @param array    $policy Policy flags.
	 * @return true|WP_Error
	 */
	private function enforce_sensitive_settings( array $types, array $items, array $policy, $is_import = false ) {
		if ( ! in_array( 'settings', $types, true ) ) {
			return true;
		}

		$selected  = isset( $items['settings'] ) && is_array( $items['settings'] )
			? array_map( 'strval', $items['settings'] )
			: array();
		$sensitive_ids = $is_import ? self::IMPORT_SENSITIVE_SETTINGS_IDS : self::SENSITIVE_SETTINGS_IDS;
		$sensitive = array_intersect( $selected, $sensitive_ids );

		if ( empty( $sensitive ) ) {
			return true;
		}

		if ( empty( $policy['allow_sensitive_settings'] ) ) {
			return new WP_Error(
				'bricks_ie_sensitive_settings_requires_authorization',
				__( 'Sensitive settings (API keys, custom code, template passwords) are excluded unless explicitly authorized.', 'bricks-ie' ),
				array( 'sensitive_settings' => array_values( $sensitive ) )
			);
		}

		if ( ! $this->user_can( 'manage_options' ) ) {
			return $this->permission_error( 'manage_options', 'settings' );
		}

		return true;
	}

	/**
	 * Require explicit overwrite authorization before any `replace`.
	 *
	 * @param string $conflict_mode      Default conflict mode.
	 * @param array  $conflict_decisions Per-type item decisions.
	 * @param array  $policy             Policy flags.
	 * @return true|WP_Error
	 */
	private function enforce_overwrite( $conflict_mode, array $conflict_decisions, array $policy ) {
		$requests_replace = ( 'replace' === $conflict_mode );

		if ( ! $requests_replace ) {
			foreach ( $conflict_decisions as $type_decisions ) {
				if ( ! is_array( $type_decisions ) ) {
					continue;
				}
				foreach ( $type_decisions as $decision ) {
					if ( 'replace' === (string) $decision ) {
						$requests_replace = true;
						break 2;
					}
				}
			}
		}

		if ( $requests_replace && empty( $policy['allow_overwrite'] ) ) {
			return new WP_Error(
				'bricks_ie_overwrite_requires_authorization',
				__( 'Replacing existing data requires explicit overwrite authorization. The default conflict mode is "skip".', 'bricks-ie' )
			);
		}

		return true;
	}

	// ==================================================================
	// Permission primitives
	// ==================================================================

	/**
	 * Check a WordPress capability for the current user.
	 *
	 * @param string $capability Capability name.
	 * @return bool
	 */
	private function user_can( $capability ) {
		return function_exists( 'current_user_can' ) && current_user_can( $capability );
	}

	/**
	 * Check a Bricks builder permission.
	 *
	 * @param string $permission Builder permission key.
	 * @return bool|null Null when the permission cannot be evaluated.
	 */
	private function builder_permission( $permission ) {
		if ( ! class_exists( $this->permissions_class )
			|| ! is_callable( array( $this->permissions_class, 'user_has_permission' ) ) ) {
			return null;
		}

		return (bool) call_user_func( array( $this->permissions_class, 'user_has_permission' ), $permission );
	}

	/**
	 * Whether the current user may execute code.
	 *
	 * @return bool|null Null when the capability cannot be evaluated.
	 */
	private function can_execute_code() {
		if ( ! class_exists( $this->capabilities_class )
			|| ! is_callable( array( $this->capabilities_class, 'current_user_can_execute_code' ) ) ) {
			return null;
		}

		return (bool) call_user_func( array( $this->capabilities_class, 'current_user_can_execute_code' ) );
	}

	/**
	 * Whether the current user may upload SVG files.
	 *
	 * @return bool|null Null when the capability cannot be evaluated.
	 */
	private function can_upload_svg() {
		if ( ! class_exists( $this->capabilities_class )
			|| ! is_callable( array( $this->capabilities_class, 'current_user_can_upload_svg' ) ) ) {
			return null;
		}

		return (bool) call_user_func( array( $this->capabilities_class, 'current_user_can_upload_svg' ) );
	}

	// ==================================================================
	// Abilities API
	// ==================================================================

	/**
	 * Resolve a registered ability with a safe callable invocation API.
	 *
	 * `is_callable()` is used instead of `method_exists()` because it only
	 * reports true when `execute()` is actually invocable from this scope. A
	 * private or protected `execute()` is visible to `method_exists()` but
	 * would fatal at call time; `is_callable()` fails closed and the adapter
	 * falls back to the native path instead.
	 *
	 * @param string $name Ability name.
	 * @return object|null Ability object exposing a public execute(), or null.
	 */
	private function get_ability( $name ) {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return null;
		}

		$ability = wp_get_ability( $name );
		if ( ! is_object( $ability ) || ! is_callable( array( $ability, 'execute' ) ) ) {
			return null;
		}

		return $ability;
	}

	/**
	 * Whether a registered ability is available.
	 *
	 * @param string $name Ability name.
	 * @return bool
	 */
	private function ability_available( $name ) {
		return null !== $this->get_ability( $name );
	}

	/** Enforce the native transport limit before handing bytes to any route. */
	private function enforce_package_size( $bytes ) {
		$limit = null;
		if ( class_exists( $this->native_class )
			&& is_callable( array( $this->native_class, 'get_mcp_transfer_max_zip_bytes' ) ) ) {
			$candidate = call_user_func( array( $this->native_class, 'get_mcp_transfer_max_zip_bytes' ) );
			if ( is_int( $candidate ) && $candidate > 0 ) {
				$limit = $candidate;
			}
		}
		if ( null === $limit && defined( $this->native_class . '::MCP_MAX_ZIP_BYTES' ) && is_numeric( constant( $this->native_class . '::MCP_MAX_ZIP_BYTES' ) ) ) {
			$candidate = (int) constant( $this->native_class . '::MCP_MAX_ZIP_BYTES' );
			if ( $candidate > 0 ) {
				$limit = $candidate;
			}
		}
		if ( function_exists( 'wp_max_upload_size' ) ) {
			$upload = (int) wp_max_upload_size();
			if ( $upload > 0 ) {
				$limit = null === $limit ? $upload : min( $limit, $upload );
			}
		}
		if ( null === $limit ) {
			return new WP_Error(
				'bricks_ie_native_package_size_limit_unavailable',
				__( 'The native transfer package size limit is unavailable; refusing unlimited transport.', 'bricks-ie' )
			);
		}
		if ( strlen( $bytes ) > $limit ) {
			return new WP_Error(
				'bricks_ie_native_package_too_large',
				__( 'The Bricks native transfer package exceeds the permitted size limit.', 'bricks-ie' ),
				array( 'size' => strlen( $bytes ), 'max' => $limit )
			);
		}
		return true;
	}

	/** Validate scalar IDs and reject duplicates after string normalization. */
	private function validate_item_values( array $types, array $items ) {
		foreach ( $types as $type ) {
			$seen = array();
			foreach ( $items[ $type ] as $item ) {
				if ( ( ! is_string( $item ) && ! is_int( $item ) ) || '' === (string) $item ) {
					return new WP_Error( 'bricks_ie_invalid_transfer_item_id', __( 'Transfer item IDs must be non-empty strings or integers.', 'bricks-ie' ) );
				}
				$id = (string) $item;
				if ( isset( $seen[ $id ] ) ) {
					return new WP_Error( 'bricks_ie_duplicate_transfer_item_id', __( 'Duplicate transfer item IDs are not allowed.', 'bricks-ie' ) );
				}
				$seen[ $id ] = true;
			}
		}
		return true;
	}

	private function validate_items_against_known( array $types, array $items, array $known, $import = false ) {
		foreach ( $types as $type ) {
			foreach ( $items[ $type ] as $item ) {
				$id = (string) $item;
				if ( $import && 'breakpoints' === $type && 'all' === $id ) {
					continue;
				}
				if ( ! isset( $known[ $type ][ $id ] ) ) {
					return new WP_Error( 'bricks_ie_unknown_transfer_item_id', __( 'An unknown transfer item ID was requested.', 'bricks-ie' ), array( 'type' => $type, 'item' => $id ) );
				}
			}
		}
		return true;
	}

	private function item_ids_from_list( array $types ) {
		$known = array();
		foreach ( $types as $entry_key => $entry ) {
			$type = is_array( $entry ) && isset( $entry['id'] ) ? (string) $entry['id'] : (string) $entry_key;
			$list = is_array( $entry ) && isset( $entry['items'] ) && is_array( $entry['items'] ) ? $entry['items'] : ( is_array( $entry ) && ! isset( $entry['id'] ) ? $entry : array() );
			foreach ( $list as $key => $item ) {
				$id = is_array( $item ) && isset( $item['id'] ) ? $item['id'] : ( is_scalar( $item ) ? $item : ( is_string( $key ) ? $key : '' ) );
				if ( is_string( $id ) || is_int( $id ) ) {
					$known[ $type ][ (string) $id ] = true;
				}
			}
		}
		return $known;
	}

	private function item_ids_from_manifest( array $manifest ) {
		return isset( $manifest['types'] ) && is_array( $manifest['types'] ) ? $this->item_ids_from_list( $manifest['types'] ) : array();
	}

	/** Inspect for import without recursively entering inspect_package(). */
	private function inspect_for_import( $bytes ) {
		$ability = $this->get_ability( self::ABILITY_INSPECT );
		if ( ! $ability && ! $this->native_method_available( 'inspect_package_bytes' ) ) {
			return new WP_Error( 'bricks_ie_native_method_missing', __( 'The Bricks native package inspection route is unavailable.', 'bricks-ie' ) );
		}
		$result = $ability ? $ability->execute( array( 'zipBase64' => base64_encode( $bytes ) ) ) : call_user_func( array( $this->native_class, 'inspect_package_bytes' ), $bytes );
		$via = $ability ? 'ability' : 'native';
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) ) {
			return $this->invalid_result_error( 'inspect' );
		}
		$valid = $this->validate_inspect_result( $result, $bytes );
		return is_wp_error( $valid ) ? $valid : $this->normalize_inspect_result( $result, $via );
	}

	// ==================================================================
	// Result validation (fail closed before normalization)
	// ==================================================================

	/**
	 * Validate a raw list result shape.
	 *
	 * Requires a `types` array whose entries follow one of the two shapes the
	 * plugin supports: the audited Bricks 2.4 descriptor list (numeric list of
	 * arrays, each with a non-empty string `id`) or a keyed map (non-empty
	 * string type ID => item array). Anything else fails closed.
	 *
	 * @param array $result Raw native/ability result.
	 * @return true|WP_Error
	 */
	private function validate_list_result( array $result ) {
		if ( ! array_key_exists( 'types', $result ) || ! is_array( $result['types'] ) ) {
			return $this->invalid_result_error( 'list', 'types_missing' );
		}

		foreach ( $result['types'] as $key => $entry ) {
			// Audited descriptor shape: { id, label, items, ... }.
			if ( is_array( $entry ) && isset( $entry['id'] )
				&& is_string( $entry['id'] ) && '' !== $entry['id'] ) {
				continue;
			}
			// Keyed map shape: type ID => items array.
			if ( is_string( $key ) && '' !== $key && is_array( $entry ) ) {
				continue;
			}

			return $this->invalid_result_error( 'list', 'types_entry_invalid' );
		}

		return true;
	}

	/**
	 * Strictly validate a raw export result before it is normalized.
	 *
	 * The package is accepted only when every audited integrity property
	 * holds: non-empty valid base64 ZIP bytes with the ZIP local-file-header
	 * signature, a SHA-256 `zipHash` matching the decoded bytes, a `zipBytes`
	 * count matching the decoded length, a safe bare `.zip` filename, and a
	 * manifest array declaring the audited schema and version. Any violation
	 * fails closed.
	 *
	 * @param array $result Raw native/ability result.
	 * @return true|WP_Error
	 */
	private function validate_export_result( array $result ) {
		$encoded = isset( $result['zipBase64'] ) ? $result['zipBase64'] : null;
		if ( ! is_string( $encoded ) || '' === $encoded ) {
			return $this->package_invalid_error( 'zip_base64_missing' );
		}

		$bytes = base64_decode( $encoded, true );
		if ( false === $bytes || '' === $bytes ) {
			return $this->package_invalid_error( 'zip_base64_invalid' );
		}

		if ( strlen( $bytes ) < 4 || "PK\x03\x04" !== substr( $bytes, 0, 4 ) ) {
			return $this->package_invalid_error( 'zip_signature_invalid' );
		}

		$declared_hash = isset( $result['zipHash'] ) && is_string( $result['zipHash'] )
			? strtolower( trim( $result['zipHash'] ) )
			: '';
		if ( '' === $declared_hash ) {
			return $this->package_hash_error( 'zip_hash_missing' );
		}
		if ( ! hash_equals( $declared_hash, hash( 'sha256', $bytes ) ) ) {
			return $this->package_hash_error( 'zip_hash_mismatch' );
		}

		if ( ! array_key_exists( 'zipBytes', $result )
			|| ! is_int( $result['zipBytes'] )
			|| $result['zipBytes'] !== strlen( $bytes ) ) {
			return new WP_Error(
				'bricks_ie_native_package_bytes_mismatch',
				__( 'The Bricks native package byte count does not match the decoded package size.', 'bricks-ie' ),
				array(
					'reason'    => 'zip_bytes_mismatch',
					'zip_bytes' => isset( $result['zipBytes'] ) ? $result['zipBytes'] : null,
					'actual'    => strlen( $bytes ),
				)
			);
		}

		$filename = isset( $result['filename'] ) ? $result['filename'] : '';
		if ( ! $this->is_safe_package_filename( $filename ) ) {
			return new WP_Error(
				'bricks_ie_native_package_filename_invalid',
				__( 'The Bricks native package filename is missing or unsafe.', 'bricks-ie' ),
				array(
					'reason'   => 'filename_invalid',
					'filename' => is_string( $filename ) ? $filename : '',
				)
			);
		}

		$manifest = isset( $result['manifest'] ) ? $result['manifest'] : null;
		if ( ! is_array( $manifest )
			|| ! isset( $manifest['schema'] )
			|| self::EXPECTED_SCHEMA !== $manifest['schema']
			|| ! isset( $manifest['version'] )
			|| ! is_int( $manifest['version'] )
			|| self::EXPECTED_VERSION !== $manifest['version'] ) {
			return new WP_Error(
				'bricks_ie_native_package_manifest_invalid',
				sprintf(
					/* translators: 1: expected schema, 2: expected version. */
					__( 'The Bricks native package manifest does not declare the audited schema "%1$s" and version %2$d.', 'bricks-ie' ),
					self::EXPECTED_SCHEMA,
					self::EXPECTED_VERSION
				),
				array(
					'reason'           => 'manifest_invalid',
					'expected_schema'  => self::EXPECTED_SCHEMA,
					'expected_version' => self::EXPECTED_VERSION,
				)
			);
		}

		return true;
	}

	/**
	 * Validate a raw inspect result shape.
	 *
	 * Requires a manifest declaring the audited schema and version, and
	 * integrity fields matching the supplied ZIP bytes. Anything else fails
	 * closed.
	 *
	 * @param array  $result Raw native/ability result.
	 * @param string $bytes  Supplied native ZIP bytes.
	 * @return true|WP_Error
	 */
	private function validate_inspect_result( array $result, $bytes ) {
		if ( ! array_key_exists( 'manifest', $result ) || ! is_array( $result['manifest'] ) ) {
			return $this->invalid_result_error( 'inspect', 'manifest_missing' );
		}

		$manifest = $result['manifest'];
		if ( ! isset( $manifest['schema'] ) || self::EXPECTED_SCHEMA !== $manifest['schema']
			|| ! array_key_exists( 'version', $manifest ) || ! is_int( $manifest['version'] )
			|| self::EXPECTED_VERSION !== $manifest['version'] ) {
			return $this->invalid_result_error( 'inspect', 'manifest_invalid' );
		}

		if ( ! array_key_exists( 'zipHash', $result ) || ! is_string( $result['zipHash'] ) ) {
			return $this->invalid_result_error( 'inspect', 'zip_hash_missing' );
		}
		if ( ! hash_equals( strtolower( trim( $result['zipHash'] ) ), hash( 'sha256', $bytes ) ) ) {
			return $this->package_hash_error( 'zip_hash_mismatch' );
		}

		if ( ! array_key_exists( 'zipBytes', $result )
			|| ! is_int( $result['zipBytes'] )
			|| $result['zipBytes'] < 0 || $result['zipBytes'] !== strlen( $bytes ) ) {
			return $this->invalid_result_error( 'inspect', 'zip_bytes_invalid' );
		}

		if ( isset( $result['maxZipBytes'] )
			&& ( ! is_int( $result['maxZipBytes'] ) || $result['maxZipBytes'] < 0 ) ) {
			return $this->invalid_result_error( 'inspect', 'max_zip_bytes_invalid' );
		}

		return true;
	}

	/**
	 * Whether a package filename is a safe, bare `.zip` name.
	 *
	 * Rejects empty names, path separators, NUL bytes, and names that do not
	 * end in `.zip`, so a hostile or drifted engine can never smuggle a path
	 * into downstream file handling.
	 *
	 * @param mixed $filename Candidate filename.
	 * @return bool
	 */
	private function is_safe_package_filename( $filename ) {
		if ( ! is_string( $filename ) || '' === $filename ) {
			return false;
		}
		if ( strlen( $filename ) <= 4 || '.zip' !== strtolower( substr( $filename, -4 ) ) ) {
			return false;
		}
		if ( false !== strpos( $filename, '/' )
			|| false !== strpos( $filename, '\\' )
			|| false !== strpos( $filename, "\0" ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Standard error for an unreadable or structurally invalid package.
	 *
	 * @param string $reason Machine-readable reason.
	 * @return WP_Error
	 */
	private function package_invalid_error( $reason ) {
		return new WP_Error(
			'bricks_ie_native_package_invalid',
			__( 'The Bricks native transfer engine returned an invalid or unreadable package.', 'bricks-ie' ),
			array( 'reason' => $reason )
		);
	}

	/**
	 * Standard error for a missing or mismatched package SHA-256 hash.
	 *
	 * @param string $reason Machine-readable reason.
	 * @return WP_Error
	 */
	private function package_hash_error( $reason ) {
		return new WP_Error(
			'bricks_ie_native_package_hash_mismatch',
			__( 'The Bricks native package does not match the SHA-256 hash reported by the native transfer.', 'bricks-ie' ),
			array( 'reason' => $reason )
		);
	}

	// ==================================================================
	// Normalization helpers
	// ==================================================================

	/**
	 * Validate the result of the CSS regeneration ability.
	 *
	 * @param array $result Raw result.
	 * @return true|WP_Error
	 */
	private function validate_css_regeneration_result( array $result ) {
		if ( ! array_key_exists( 'success', $result ) || ! is_bool( $result['success'] ) ) {
			return $this->invalid_css_regeneration_error( 'success_invalid' );
		}
		if ( ! array_key_exists( 'generatedFileCount', $result )
			|| ! is_int( $result['generatedFileCount'] )
			|| $result['generatedFileCount'] < 0 ) {
			return $this->invalid_css_regeneration_error( 'generated_file_count_invalid' );
		}
		if ( ! array_key_exists( 'generatedFiles', $result ) || ! is_array( $result['generatedFiles'] ) ) {
			return $this->invalid_css_regeneration_error( 'generated_files_invalid' );
		}
		foreach ( $result['generatedFiles'] as $file ) {
			if ( ! is_string( $file ) || '' === $file ) {
				return $this->invalid_css_regeneration_error( 'generated_file_invalid' );
			}
		}
		if ( $result['generatedFileCount'] !== count( $result['generatedFiles'] ) ) {
			return $this->invalid_css_regeneration_error( 'generated_file_count_mismatch' );
		}
		if ( ! array_key_exists( 'cssLoading', $result ) || ! is_string( $result['cssLoading'] ) || '' === $result['cssLoading'] ) {
			return $this->invalid_css_regeneration_error( 'css_loading_invalid' );
		}

		return true;
	}

	/**
	 * Normalize a CSS regeneration result.
	 *
	 * @param array  $result Raw result.
	 * @param string $via    ability|fallback.
	 * @return array
	 */
	private function normalize_css_regeneration_result( array $result, $via ) {
		return array(
			'success'              => $result['success'],
			'generated_file_count' => $result['generatedFileCount'],
			'generated_files'      => array_values( $result['generatedFiles'] ),
			'css_loading'          => $result['cssLoading'],
			'via'                  => $via,
		);
	}

	/**
	 * Read the CSS loading mode without assuming a Bricks internal API.
	 *
	 * @return string|null
	 */
	private function get_css_loading() {
		if ( ! function_exists( 'get_option' ) ) {
			return null;
		}
		$settings = get_option( 'bricks_global_settings', array() );
		if ( ! is_array( $settings ) || ! isset( $settings['cssLoading'] ) || ! is_string( $settings['cssLoading'] ) || '' === $settings['cssLoading'] ) {
			$settings = get_option( 'bricks_settings', array() );
		}
		if ( ! is_array( $settings ) || ! isset( $settings['cssLoading'] ) || ! is_string( $settings['cssLoading'] ) || '' === $settings['cssLoading'] ) {
			return null;
		}

		return $settings['cssLoading'];
	}

	/**
	 * Validate and normalize the result of a public CSS regeneration contract.
	 *
	 * @param mixed  $result Raw result.
	 * @param string $via    Contract used to regenerate files.
	 * @return array|WP_Error
	 */
	private function process_css_regeneration_result( $result, $via ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) ) {
			return $this->invalid_css_regeneration_error( 'result_not_array' );
		}

		$validated = $this->validate_css_regeneration_result( $result );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		return $this->normalize_css_regeneration_result( $result, $via );
	}

	/**
	 * Standard malformed CSS regeneration result error.
	 *
	 * @param string $reason Machine-readable reason.
	 * @return WP_Error
	 */
	private function invalid_css_regeneration_error( $reason ) {
		return new WP_Error(
			'bricks_ie_css_regeneration_result_invalid',
			__( 'The Bricks CSS regeneration engine returned an unexpected result.', 'bricks-ie' ),
			array( 'reason' => $reason )
		);
	}

	/**
	 * Normalize a list result.
	 *
	 * @param array  $result Raw native/ability result.
	 * @param string $via    ability|native.
	 * @return array
	 */
	private function normalize_list_result( array $result, $via ) {
		return array(
			'types' => isset( $result['types'] ) && is_array( $result['types'] ) ? $result['types'] : array(),
			'via'   => $via,
		);
	}

	/**
	 * Normalize an export result.
	 *
	 * @param array  $result Raw native/ability result.
	 * @param string $via    ability|native.
	 * @return array
	 */
	private function normalize_export_result( array $result, $via ) {
		return array(
			'filename'   => isset( $result['filename'] ) ? (string) $result['filename'] : '',
			'zip_base64' => isset( $result['zipBase64'] ) ? (string) $result['zipBase64'] : '',
			'zip_hash'   => isset( $result['zipHash'] ) ? (string) $result['zipHash'] : '',
			'zip_bytes'  => isset( $result['zipBytes'] ) ? (int) $result['zipBytes'] : 0,
			'manifest'   => isset( $result['manifest'] ) && is_array( $result['manifest'] ) ? $result['manifest'] : array(),
			'via'        => $via,
		);
	}

	/**
	 * Normalize an inspect result.
	 *
	 * @param array  $result Raw native/ability result.
	 * @param string $via    ability|native.
	 * @return array
	 */
	private function normalize_inspect_result( array $result, $via ) {
		return array(
			'manifest'      => isset( $result['manifest'] ) && is_array( $result['manifest'] ) ? $result['manifest'] : array(),
			'zip_hash'      => isset( $result['zipHash'] ) ? (string) $result['zipHash'] : '',
			'zip_bytes'     => isset( $result['zipBytes'] ) ? (int) $result['zipBytes'] : 0,
			'max_zip_bytes' => isset( $result['maxZipBytes'] ) ? (int) $result['maxZipBytes'] : 0,
			'via'           => $via,
		);
	}

	/**
	 * Normalize an import result.
	 *
	 * @param array  $result   Raw native/ability result.
	 * @param string $via      ability|native.
	 * @param string $zip_hash SHA-256 of the imported bytes.
	 * @return array|WP_Error
	 */
	private function normalize_import_result( array $result, $via, $zip_hash = '' ) {
		if ( array_key_exists( 'success', $result ) && ! is_bool( $result['success'] ) ) {
			return $this->invalid_result_error( 'import', 'success_invalid' );
		}

		$normalized = array(
			'success'  => false,
			'results'  => isset( $result['results'] ) && is_array( $result['results'] ) ? $result['results'] : array(),
			'zip_hash' => isset( $result['zipHash'] ) ? (string) $result['zipHash'] : $zip_hash,
			'via'      => $via,
		);

		if ( isset( $result['refresh'] ) && is_array( $result['refresh'] ) ) {
			$normalized['refresh'] = $result['refresh'];
		}

		$normalized['success'] = array_key_exists( 'success', $result )
			? $result['success']
			: ( isset( $result['results'] ) && is_array( $result['results'] ) );

		return $normalized;
	}

	// ==================================================================
	// Small shared helpers
	// ==================================================================

	/**
	 * Normalize a fully-qualified class name.
	 *
	 * @param string $class Class name.
	 * @return string
	 */
	private function normalize_class_name( $class ) {
		return ltrim( (string) $class, '\\' );
	}

	/**
	 * Detect the release channel from a Bricks version string.
	 *
	 * @param string $version Bricks version.
	 * @return string stable|beta|alpha|rc.
	 */
	private function detect_channel( $version ) {
		$version = strtolower( (string) $version );
		if ( strpos( $version, 'beta' ) !== false ) {
			return 'beta';
		}
		if ( strpos( $version, 'alpha' ) !== false ) {
			return 'alpha';
		}
		if ( strpos( $version, 'rc' ) !== false ) {
			return 'rc';
		}

		return 'stable';
	}

	/**
	 * Sanitize a list of transfer type IDs.
	 *
	 * @param array $types Raw type IDs.
	 * @return string[]
	 */
	private function sanitize_type_list( array $types ) {
		return array_values( array_unique( array_map( 'strval', $types ) ) );
	}

	/**
	 * Extract the type list from a selection array.
	 *
	 * @param array $selection Selection.
	 * @return string[]
	 */
	private function selection_types( array $selection ) {
		$types = isset( $selection['types'] ) && is_array( $selection['types'] ) ? $selection['types'] : array();

		return $this->sanitize_type_list( $types );
	}

	/**
	 * Extract the item map from a selection array.
	 *
	 * @param array $selection Selection.
	 * @return array
	 */
	private function selection_items( array $selection ) {
		return isset( $selection['items'] ) && is_array( $selection['items'] ) ? $selection['items'] : array();
	}

	/**
	 * Standard error for an empty type selection.
	 *
	 * @return WP_Error
	 */
	private function no_types_error() {
		return new WP_Error(
			'bricks_ie_no_transfer_types',
			__( 'At least one transfer type must be selected.', 'bricks-ie' )
		);
	}

	/**
	 * Standard error for an unexpected native result shape.
	 *
	 * @param string $operation Operation name.
	 * @param string $reason    Optional machine-readable reason.
	 * @return WP_Error
	 */
	private function invalid_result_error( $operation, $reason = '' ) {
		$data = array( 'operation' => $operation );
		if ( '' !== $reason ) {
			$data['reason'] = $reason;
		}

		return new WP_Error(
			'bricks_ie_native_result_invalid',
			sprintf(
				/* translators: %s: operation name. */
				__( 'The Bricks native transfer engine returned an unexpected result for the %s operation.', 'bricks-ie' ),
				$operation
			),
			$data
		);
	}

	/**
	 * Standard missing-permission error.
	 *
	 * @param string $capability Capability or permission key.
	 * @param string $type       Optional transfer type ID.
	 * @return WP_Error
	 */
	private function permission_error( $capability, $type = '' ) {
		return new WP_Error(
			'bricks_ie_missing_permission',
			sprintf(
				/* translators: 1: capability, 2: transfer type. */
				__( 'You do not have the required permission "%1$s"%2$s for this Bricks transfer operation.', 'bricks-ie' ),
				$capability,
				'' !== $type ? ' (' . $type . ')' : ''
			),
			array(
				'capability' => $capability,
				'type'       => $type,
			)
		);
	}

	/**
	 * Standard error when a required permission cannot be evaluated.
	 *
	 * @param string $capability Capability or permission key.
	 * @param string $type       Optional transfer type ID.
	 * @return WP_Error
	 */
	private function permission_unevaluable_error( $capability, $type = '' ) {
		return new WP_Error(
			'bricks_ie_permission_unevaluable',
			sprintf(
				/* translators: 1: capability, 2: transfer type. */
				__( 'The required permission "%1$s"%2$s could not be evaluated; refusing to proceed.', 'bricks-ie' ),
				$capability,
				'' !== $type ? ' (' . $type . ')' : ''
			),
			array(
				'capability' => $capability,
				'type'       => $type,
			)
		);
	}
}
