<?php
// phpcs:ignoreFile -- Plugins often add this for the WordPress Coding Standards.

/**
 * Fixture: annotations that don't name a PHPCompatibility sniff. Authors add
 * these for other rule sets, so the scanner reports the breaks anyway.
 */

function edge_generic_annotations( $items, $text ) {
	$callback = create_function( '$x', 'return $x;' ); // phpcs:ignore -- expect: error PHPCompatibility.FunctionUse.RemovedFunctions.create_functionDeprecatedRemoved

	// phpcs:disable
	$pair = each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved
	// phpcs:enable

	$first = $text{0}; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- expect: error PHPCompatibility.Syntax.RemovedCurlyBraceArrayAccess.Removed

	return array( $callback, $pair, $first );
}
