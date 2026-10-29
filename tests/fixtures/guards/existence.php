<?php
/**
 * Fixture: existence checks. A check of the very function, class or constant
 * a finding says PHP removed means the code can't run on the target version,
 * so the finding is a possible false alarm. A check of anything else proves
 * nothing.
 */

function guards_existence_if( $items ) {
	if ( function_exists( 'each' ) ) {
		$pair = each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved (false alarm)
	}

	if ( \function_exists( '\each' ) ) {
		$pair = \each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved (false alarm)
	}

	if ( is_callable( 'EACH' ) ) {
		$pair = each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved (false alarm)
	}

	if ( ! function_exists( 'each' ) ) {
		$pair = null;
	} else {
		$pair = each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved (false alarm)
	}

	if ( $items && false === ! function_exists( 'each' ) ) {
		$pair = each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved (false alarm)
	}

	// A check of another function proves nothing about each().
	if ( function_exists( 'create_function' ) ) {
		$pair = each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved
	}

	return $pair;
}

function guards_existence_expressions( $items ) {
	if ( function_exists( 'get_magic_quotes_gpc' ) && get_magic_quotes_gpc() ) { // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.get_magic_quotes_gpcDeprecatedRemoved (false alarm)
		$items = array_map( 'stripslashes', $items );
	}

	$price  = function_exists( 'money_format' ) ? money_format( '%i', 3.5 ) : ''; // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.money_formatDeprecatedRemoved (false alarm)
	$quotes = ! function_exists( 'get_magic_quotes_runtime' ) || get_magic_quotes_runtime(); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.get_magic_quotes_runtimeDeprecatedRemoved (false alarm)
	$pair   = function_exists( 'each' ) and each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved (false alarm)
	$joined = implode( ',', array( function_exists( 'each' ) ? each( $items ) : null ) ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved (false alarm)

	return array( $items, $price, $quotes, $pair, $joined );
}

function guards_existence_classes_and_constants( $url ) {
	if ( class_exists( 'XmlRpcServer' ) ) {
		$server = new XmlRpcServer(); // expect: error PHPCompatibility.Classes.RemovedClasses.xmlrpcserverRemoved (false alarm)
	}

	if ( defined( 'FILTER_FLAG_SCHEME_REQUIRED' ) ) {
		$valid = filter_var( $url, FILTER_VALIDATE_URL, FILTER_FLAG_SCHEME_REQUIRED ); // expect: error PHPCompatibility.Constants.RemovedConstants.filter_flag_scheme_requiredDeprecatedRemoved (false alarm)
	}

	return array( $server, $valid );
}

function guards_existence_early_exits( $lists ) {
	if ( ! function_exists( 'each' ) ) {
		return null;
	}

	foreach ( $lists as $items ) {
		if ( ! function_exists( 'get_magic_quotes_gpc' ) ) continue;
		$quotes = get_magic_quotes_gpc(); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.get_magic_quotes_gpcDeprecatedRemoved (false alarm)
	}

	return each( $lists ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved (false alarm)
}

// Alternative syntax, and an if without braces.
if ( function_exists( 'each' ) ) :
	each( $guards_list ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved (false alarm)
endif;

if ( function_exists( 'each' ) ) each( $guards_list ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved (false alarm)

while ( function_exists( 'each' ) ) {
	each( $guards_list ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved (false alarm)
}
