<?php
/**
 * Fixture: annotations that name PHPCompatibility sniffs. The author checked
 * these lines, so the scanner honors the annotations.
 */

function edge_compatibility_annotations( $items, $text ) {
	if ( function_exists( 'create_function' ) ) {
		$callback = create_function( '$x', 'return $x;' ); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.create_functionDeprecatedRemoved
	}

	// phpcs:disable PHPCompatibility.FunctionUse -- Only runs on old PHP versions.
	if ( function_exists( 'each' ) ) {
		$pair = each( $items );
	}
	// phpcs:enable PHPCompatibility.FunctionUse

	$money = money_format( '%i', 3.5 ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.money_formatDeprecatedRemoved

	return array( $callback, $pair, $money );
}
