<?php
/**
 * Fixture: a PHP file with a syntax error.
 */

function edge_broken( $items {
	return each( $items ); // expect: error PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved
