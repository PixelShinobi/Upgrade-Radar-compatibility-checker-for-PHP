<?php
/**
 * What's known to be missing on the target PHP version.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Scanner\Guards;

/**
 * Functions, classes and constants a finding says the target PHP version
 * removed. A check for one of these, such as function_exists( 'each' ), is
 * false there. Nothing else is assumed to exist or be missing.
 */
final class Facts {

	/**
	 * Missing functions, by lowercase name.
	 *
	 * @var array<string, true>
	 */
	private $functions = array();

	/**
	 * Missing classes and interfaces, by lowercase name.
	 *
	 * @var array<string, true>
	 */
	private $classes = array();

	/**
	 * Missing constants, by name.
	 *
	 * @var array<string, true>
	 */
	private $constants = array();

	/**
	 * Nothing known to be missing.
	 *
	 * @return self
	 */
	public static function none(): self {
		return new self();
	}

	/**
	 * A function that's missing.
	 *
	 * @param string $name Function name.
	 * @return self
	 */
	public static function missing_function( string $name ): self {
		$facts                                    = new self();
		$facts->functions[ self::lower( $name ) ] = true;
		return $facts;
	}

	/**
	 * A class or interface that's missing.
	 *
	 * @param string $name Class name.
	 * @return self
	 */
	public static function missing_class( string $name ): self {
		$facts                                  = new self();
		$facts->classes[ self::lower( $name ) ] = true;
		return $facts;
	}

	/**
	 * A constant that's missing.
	 *
	 * @param string $name Constant name.
	 * @return self
	 */
	public static function missing_constant( string $name ): self {
		$facts                                    = new self();
		$facts->constants[ ltrim( $name, '\\' ) ] = true;
		return $facts;
	}

	/**
	 * Whether a function is known to be missing.
	 *
	 * @param string $name Function name, as passed to function_exists().
	 * @return bool
	 */
	public function is_missing_function( string $name ): bool {
		return isset( $this->functions[ self::lower( $name ) ] );
	}

	/**
	 * Whether a class or interface is known to be missing.
	 *
	 * @param string $name Class name, as passed to class_exists().
	 * @return bool
	 */
	public function is_missing_class( string $name ): bool {
		return isset( $this->classes[ self::lower( $name ) ] );
	}

	/**
	 * Whether a constant is known to be missing.
	 *
	 * @param string $name Constant name, as passed to defined().
	 * @return bool
	 */
	public function is_missing_constant( string $name ): bool {
		return isset( $this->constants[ ltrim( $name, '\\' ) ] );
	}

	/**
	 * A function or class name the way PHP compares them.
	 *
	 * @param string $name Name, possibly with a leading "\".
	 * @return string
	 */
	private static function lower( string $name ): string {
		return strtolower( ltrim( $name, '\\' ) );
	}
}
