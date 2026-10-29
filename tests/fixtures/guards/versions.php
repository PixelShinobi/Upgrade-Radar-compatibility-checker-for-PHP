<?php
/**
 * Fixture: PHP version checks, evaluated against the target version. The
 * checks here are false (or true) on every PHP 8 release, so the results are
 * the same for every target.
 */

function guards_versions( $items, $handle ) {
	if ( PHP_VERSION_ID < 80000 ) {
		$pair = each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved (false alarm)
	}

	if ( \PHP_VERSION_ID <= 70499 ) {
		$pair = each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved (false alarm)
	}

	if ( PHP_VERSION_ID >= 80000 ) {
		$pair = null;
	} else {
		$pair = each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved (false alarm)
	}

	if ( PHP_VERSION_ID < 70000 ) {
		$pair = null;
	} elseif ( PHP_VERSION_ID < 80000 ) {
		$pair = each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved (false alarm)
	}

	if ( 80000 > PHP_VERSION_ID ) {
		$pair = each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved (false alarm)
	}

	if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
		$pair = each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved (false alarm)
	}

	if ( version_compare( phpversion(), '8.0.0', 'lt' ) ) {
		$pair = each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved (false alarm)
	}

	if ( version_compare( PHP_VERSION, '8.0' ) < 0 ) {
		$pair = each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved (false alarm)
	}

	if ( -1 === version_compare( PHP_VERSION, '8.0' ) ) {
		$pair = each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved (false alarm)
	}

	if ( PHP_MAJOR_VERSION < 8 ) {
		$pair = each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved (false alarm)
	}

	if ( 7 === PHP_MAJOR_VERSION && PHP_MINOR_VERSION >= 4 ) {
		$pair = each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved (false alarm)
	}

	// Something unknown and something false is still false.
	if ( $items && PHP_VERSION_ID < 80000 ) {
		$pair = each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved (false alarm)
	}

	// Something unknown or something false could be true.
	if ( $items || PHP_VERSION_ID < 80000 ) {
		$pair = each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved
	}

	// version_compare() rejects upper-case operators, and comparing version strings with < isn't evaluated.
	if ( version_compare( PHP_VERSION, '8.0', 'LT' ) || PHP_VERSION < '8.0' ) {
		$pair = each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved
	}

	// Deprecations behind a version check are possible false alarms too.
	if ( PHP_VERSION_ID < 80000 ) {
		libxml_disable_entity_loader( true ); // expect: warning PHPCompatibility.FunctionUse.RemovedFunctions.libxml_disable_entity_loaderDeprecated (false alarm)
	}

	return $pair;
}

// Like getID3: a version check around an existence check.
function guards_versions_nested() {
	if ( version_compare( PHP_VERSION, '5.4.0', '<' ) ) {
		if ( function_exists( 'get_magic_quotes_runtime' ) ) {
			if ( get_magic_quotes_runtime() ) { // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.get_magic_quotes_runtimeDeprecatedRemoved (false alarm)
				return false;
			}
		}
	}

	return true;
}

function guards_versions_early_exit( $items ) {
	if ( version_compare( PHP_VERSION, '8.0', '>=' ) ) {
		return array();
	}

	return each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved (false alarm)
}

// Like SimplePie: since PHP 8.0, exit() in a constructor skips the destructor.
class Guards_Old_Constructor {
	public function __construct() {
		if ( version_compare( PHP_VERSION, '7.2', '<' ) ) {
			exit( 'Please upgrade PHP.' ); // expect: error PHPCompatibility.FunctionDeclarations.RemovedCallingDestructAfterConstructorExit.Found (false alarm)
		}
	}

	public function __destruct() {
		echo 'Done.';
	}
}
