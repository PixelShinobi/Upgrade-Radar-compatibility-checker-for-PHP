<?php
/**
 * Fixture: deprecated in PHP 8.4.
 */

function legacy_84_render( WP_Post $post = null ) { // expect: warning PHPCompatibility.FunctionDeclarations.RemovedImplicitlyNullableParam.Deprecated
	return $post;
}

function legacy_84_examples( $link, $line ) {
	if ( error_reporting() & E_STRICT ) { // expect: warning PHPCompatibility.Constants.RemovedConstants.e_strictDeprecated
		trigger_error( 'Legacy failure', E_USER_ERROR ); // expect: warning PHPCompatibility.ParameterValues.RemovedTriggerErrorLevel.Deprecated
	}
	mysqli_ping( $link ); // expect: warning PHPCompatibility.FunctionUse.RemovedFunctions.mysqli_pingDeprecated
	$random = lcg_value(); // expect: warning PHPCompatibility.FunctionUse.RemovedFunctions.lcg_valueDeprecated
	$fields = str_getcsv( $line ); // expect: warning PHPCompatibility.ParameterValues.RemovedProprietaryCSVEscaping.DeprecatedParamNotPassed
	return array( $random, $fields );
}
