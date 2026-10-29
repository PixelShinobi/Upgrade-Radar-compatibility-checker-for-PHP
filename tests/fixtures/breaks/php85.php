<?php
/**
 * Fixture: deprecated in PHP 8.5.
 */

function legacy_85_examples( $url, $value ) {
	$handle = curl_init( $url );
	curl_exec( $handle );
	curl_close( $handle ); // expect: warning PHPCompatibility.FunctionUse.RemovedFunctions.curl_closeDeprecated
	$count  = (integer) $value; // expect: warning PHPCompatibility.TypeCasts.RemovedTypeCasts.integerDeprecated
	$active = (boolean) $value; // expect: warning PHPCompatibility.TypeCasts.RemovedTypeCasts.booleanDeprecated
	$files  = `ls`; // expect: warning PHPCompatibility.LanguageConstructs.RemovedLanguageConstructs.t_backtickDeprecated; warning PHPCompatibility.LanguageConstructs.RemovedLanguageConstructs.t_backtickDeprecated
	$stamp  = date( DATE_RFC7231 ); // expect: warning PHPCompatibility.Constants.RemovedConstants.date_rfc7231Deprecated
	return array( $count, $active, $files, $stamp );
}
