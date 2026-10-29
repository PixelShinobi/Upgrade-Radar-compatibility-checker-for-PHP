<?php
/**
 * Fixture: worked on PHP 7.4, breaks on PHP 8.0 and later.
 *
 * Classic patterns from older WordPress plugins.
 */

class Legacy_Widget extends WP_Widget {
	function Legacy_Widget() { // expect: error PHPCompatibility.FunctionNameRestrictions.RemovedPHP4StyleConstructors.Removed
		parent::__construct( 'legacy', 'Legacy' );
	}
}

function legacy_80_each( $items ) {
	while ( list( $key, $value ) = each( $items ) ) { // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved
		echo $key . $value;
	}
}

function legacy_80_examples( $text, $a, $b ) {
	$callback = create_function( '$x', 'return $x;' ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.create_functionDeprecatedRemoved
	$first    = $text{0}; // expect: error PHPCompatibility.Syntax.RemovedCurlyBraceArrayAccess.Removed
	$price    = money_format( '%i', 3.5 ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.money_formatDeprecatedRemoved
	if ( get_magic_quotes_gpc() ) { // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.get_magic_quotes_gpcDeprecatedRemoved
		$text = stripslashes( $text );
	}
	$number = (real) $a; // expect: error PHPCompatibility.TypeCasts.RemovedTypeCasts.realDeprecatedRemoved
	$label  = 'Sum: ' . $a + $b; // expect: error PHPCompatibility.Operators.ChangedConcatOperatorPrecedence.Found
	$size   = $a ? 'small' : $b ? 'medium' : 'large'; // expect: error PHPCompatibility.Operators.RemovedTernaryAssociativity.Removed
	$joined = implode( $a, ', ' ); // expect: error PHPCompatibility.ParameterValues.RemovedImplodeFlexibleParamOrder.Removed
	parse_str( $text ); // expect: error PHPCompatibility.FunctionUse.OptionalToRequiredFunctionParameters.parse_str_resultHardRequired
	define( 'LEGACY_FLAG', true, true ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctionParameters.define_case_insensitiveDeprecatedRemoved
	return array( $callback, $first, $price, $number, $label, $size, $joined );
}
