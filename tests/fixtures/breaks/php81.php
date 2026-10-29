<?php
/**
 * Fixture: deprecated or removed in PHP 8.1.
 */

class Legacy_Session_Data implements Serializable { // expect: warning PHPCompatibility.Interfaces.RemovedSerializable.Deprecated
	private $data = array();

	public function serialize() {
		return serialize( $this->data );
	}

	public function unserialize( $data ) {
		$this->data = unserialize( $data );
	}
}

function legacy_81_examples( $text, $algorithm ) {
	$date    = strftime( '%Y-%m-%d', time() ); // expect: warning PHPCompatibility.FunctionUse.RemovedFunctions.strftimeDeprecated
	$clean   = filter_var( $text, FILTER_SANITIZE_STRING ); // expect: warning PHPCompatibility.Constants.RemovedConstants.filter_sanitize_stringDeprecated
	$sunrise = date_sunrise( time() ); // expect: warning PHPCompatibility.FunctionUse.RemovedFunctions.date_sunriseDeprecated
	$hash    = mhash( $algorithm, $text ); // expect: warning PHPCompatibility.FunctionUse.RemovedFunctions.mhashDeprecated
	ini_set( 'auto_detect_line_endings', '1' ); // expect: warning PHPCompatibility.IniDirectives.RemovedIniDirectives.auto_detect_line_endingsDeprecated
	$GLOBALS = array(); // expect: error PHPCompatibility.Variables.RemovedIndirectModificationOfGlobals.Overwrite
	return array( $date, $clean, $sunrise, $hash );
}
