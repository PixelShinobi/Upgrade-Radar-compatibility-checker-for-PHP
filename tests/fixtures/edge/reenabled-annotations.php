<?php
/**
 * Fixture: an annotation for another rule set, switched back on at the end
 * of a line of code. PHP_CodeSniffer records that line's ignore rules as null.
 */

function edge_reenabled_annotations( $text ) {
	if ( $text ) {
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- Silencing warnings.
		$first = @$text{0}; // expect: error PHPCompatibility.Syntax.RemovedCurlyBraceArrayAccess.Removed
	} // phpcs:enable WordPress

	return each( $text ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved
}
