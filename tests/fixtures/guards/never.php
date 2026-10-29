<?php
/**
 * Fixture: guarded code that still counts. None of these findings are
 * possible false alarms.
 */

// Declaration guards: a function or class declared when it's missing runs
// whenever it's called.
if ( ! function_exists( 'guards_polyfill' ) ) {
	function guards_polyfill( $items ) {
		return each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved
	}
}

if ( ! class_exists( 'Guards_Polyfill' ) ) {
	class Guards_Polyfill {
		public function next( $items ) {
			return each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved
		}
	}
}

// A polyfill for the removed function itself is declared on PHP 8.
if ( ! function_exists( 'each' ) ) {
	function guards_each_fallback( $items ) {
		return each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved
	}
}

// Syntax that no longer compiles breaks the whole file, guarded or not.
if ( PHP_VERSION_ID < 80000 ) {
	$guards_first  = $guards_text{0}; // expect: error PHPCompatibility.Syntax.RemovedCurlyBraceArrayAccess.Removed
	$guards_number = (real) $guards_a; // expect: error PHPCompatibility.TypeCasts.RemovedTypeCasts.realDeprecatedRemoved
}

if ( function_exists( 'each' ) ) {
	$guards_size = $guards_a ? 'small' : $guards_b ? 'medium' : 'large'; // expect: error PHPCompatibility.Operators.RemovedTernaryAssociativity.Removed
}

// Deprecated functions still exist, so checking for them doesn't stop the call.
if ( function_exists( 'libxml_disable_entity_loader' ) ) {
	libxml_disable_entity_loader( true ); // expect: warning PHPCompatibility.FunctionUse.RemovedFunctions.libxml_disable_entity_loaderDeprecated
}

// The if doesn't always leave the function, so the code after it can run.
function guards_no_exit( $items ) {
	if ( ! function_exists( 'each' ) ) {
		$items = array_values( $items );
	}

	return each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved
}

// The early exit is inside another if, so it doesn't always run.
function guards_nested_exit( $items, $strict ) {
	if ( $strict ) if ( ! function_exists( 'each' ) ) return null;

	return each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved
}

// Checks in another function, or in a variable, aren't followed.
function guards_has_each() {
	return function_exists( 'each' );
}

function guards_checked_elsewhere( $items ) {
	$has_each = function_exists( 'each' );
	if ( guards_has_each() || $has_each ) {
		return each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved
	}

	return null;
}

// A top-level return doesn't stop functions declared after it: PHP declares them first.
if ( PHP_VERSION_ID >= 80000 ) {
	return;
}

function guards_after_return( $items ) {
	return each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved
}
