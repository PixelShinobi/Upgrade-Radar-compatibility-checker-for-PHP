<?php
/**
 * PHP_CodeSniffer configuration that ignores process-wide state.
 *
 * Only load this after PHP_CodeSniffer's autoloader is registered (Runtime does that).
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Scanner\Phpcs;

use PHP_CodeSniffer\Config;
use ReflectionProperty;

/**
 * A Config built only from the arguments it's given.
 *
 * The parent constructor is written for the phpcs command: it falls back to
 * $_SERVER['argv'], looks for a phpcs.xml in the working directory, reads
 * STDIN when it isn't a terminal, and keeps the options it has seen in a
 * static property, so a second Config in the same process silently ignores
 * most of its own arguments. None of that is wanted inside WordPress.
 */
final class IsolatedConfig extends Config {

	/**
	 * Creates a configuration from command-line style arguments.
	 *
	 * @param string[] $args Arguments such as "--standard=/path/to/ruleset.xml".
	 */
	public function __construct( array $args ) {
		$overridden = new ReflectionProperty( Config::class, 'overriddenDefaults' );
		if ( PHP_VERSION_ID < 80100 ) {
			$overridden->setAccessible( true );
		}
		$overridden->setValue( null, array() );

		$this->dieOnUnknownArg = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP_CodeSniffer's property.
		$this->restoreDefaults();
		$this->setCommandLineValues( $args );
	}
}
