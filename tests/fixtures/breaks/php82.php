<?php
/**
 * Fixture: deprecated in PHP 8.2.
 */

function legacy_82_examples( $name, $text ) {
	$greeting = "Hello ${name}"; // expect: warning PHPCompatibility.TextStrings.RemovedDollarBraceStringEmbeds.DeprecatedVariableSyntax
	$latin    = utf8_decode( $text ); // expect: warning PHPCompatibility.FunctionUse.RemovedFunctions.utf8_decodeDeprecated
	$utf8     = utf8_encode( $latin ); // expect: warning PHPCompatibility.FunctionUse.RemovedFunctions.utf8_encodeDeprecated
	return array( $greeting, $utf8 );
}
