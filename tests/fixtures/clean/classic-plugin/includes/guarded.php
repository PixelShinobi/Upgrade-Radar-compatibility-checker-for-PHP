<?php
/**
 * Fixture: a deprecated function behind a feature check, with an annotation
 * the author added after checking it. The scanner honors annotations that
 * name a PHPCompatibility sniff, so this stays quiet.
 */

function classic_fixture_to_latin1( string $text ): string {
	if ( function_exists( 'mb_convert_encoding' ) ) {
		return mb_convert_encoding( $text, 'ISO-8859-1', 'UTF-8' );
	}

	return utf8_decode( $text ); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.utf8_decodeDeprecated -- Only reached without mbstring.
}
