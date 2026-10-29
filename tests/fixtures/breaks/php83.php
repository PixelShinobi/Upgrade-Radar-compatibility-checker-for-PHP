<?php
/**
 * Fixture: deprecated in PHP 8.3.
 */

class Legacy_Reflection_User {
	public function name() {
		return get_class(); // expect: warning PHPCompatibility.ParameterValues.RemovedGetClassNoArgs.ArgMissing
	}

	public function parent_name() {
		return get_parent_class(); // expect: warning PHPCompatibility.ParameterValues.RemovedGetClassNoArgs.ArgMissing
	}
}

function legacy_83_examples( $text ) {
	assert_options( ASSERT_ACTIVE, 1 ); // expect: warning PHPCompatibility.FunctionUse.RemovedFunctions.assert_optionsDeprecated; warning PHPCompatibility.Constants.RemovedConstants.assert_activeDeprecated
	mt_srand( 42, MT_RAND_PHP ); // expect: warning PHPCompatibility.Constants.RemovedConstants.mt_rand_phpDeprecated
	$short = mb_strimwidth( $text, 0, -3 ); // expect: warning PHPCompatibility.ParameterValues.RemovedMbStrimWidthNegativeWidth.Deprecated
	$ldap  = ldap_connect( 'ldap.example.com', 389 ); // expect: warning PHPCompatibility.ParameterValues.RemovedLdapConnectSignatures.DeprecatedTwoParamSignature
	return array( $short, $ldap );
}
