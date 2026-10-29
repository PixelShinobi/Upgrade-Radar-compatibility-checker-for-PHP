<?php
/**
 * A scanned file that only honors PHPCompatibility annotations.
 *
 * Only load this after PHP_CodeSniffer's autoloader is registered (Runtime does that).
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Scanner\Phpcs;

use PHP_CodeSniffer\Files\LocalFile;

/**
 * Honors "phpcs:ignore" and "phpcs:disable" comments only when they name a
 * PHPCompatibility sniff.
 *
 * Plugin authors who check their code with PHPCompatibility mark the false
 * alarms they've reviewed, and honoring those keeps results quieter. Bare
 * annotations and "phpcs:ignoreFile" are usually there for other rule sets,
 * such as the WordPress Coding Standards, so they mustn't hide a real break.
 * "phpcs:set" is ignored too, so one scanned file can't reconfigure the sniffs
 * for the next.
 *
 * The config this file gets must have annotations turned off.
 */
final class CompatibilityFile extends LocalFile {

	/**
	 * Tokenizes the file and keeps only the PHPCompatibility ignore rules.
	 *
	 * @return void
	 */
	public function parse() {
		// The tokenizer only records which lines are ignored when annotations are on.
		$this->config->annotations = true;
		try {
			parent::parse();
		} finally {
			// Off again before process() runs the sniffs, which skips phpcs:ignoreFile and phpcs:set.
			$this->config->annotations = false;
		}

		if ( null === $this->tokenizer ) {
			return;
		}

		$ignored_lines = array();
		foreach ( $this->tokenizer->ignoredLines as $line => $rules ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP_CodeSniffer's property.
			$kept = array();
			foreach ( $rules as $code => $value ) {
				if ( 0 === strpos( (string) $code, 'PHPCompatibility' ) ) {
					$kept[ $code ] = $value;
				}
			}

			if ( array() !== $kept ) {
				if ( isset( $rules['.except'] ) ) {
					$kept['.except'] = $rules['.except'];
				}
				$ignored_lines[ $line ] = $kept;
			}
		}

		$this->tokenizer->ignoredLines = $ignored_lines; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP_CodeSniffer's property.
	}
}
