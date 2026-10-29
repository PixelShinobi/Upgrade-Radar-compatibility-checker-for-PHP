<?php
/**
 * The plain-English verdict for a plugin or theme.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Scanner;

/**
 * Decides what the site owner should do about an item.
 *
 * Only errors decide: code that breaks on the target version. Warnings are
 * deprecations that still work, so they're shown but never block "ready".
 */
final class Verdict {

	/** No errors: the item is ready for the target version. */
	public const READY = 'ready';

	/** Errors, and an update may fix them: update, then scan again. */
	public const UPDATE_FIRST = 'update_first';

	/** Errors in the newest version: ask the developer, or replace the item. */
	public const NO_COMPATIBLE_VERSION = 'no_compatible_version';

	/** Errors in code nothing updates, such as custom code: it needs a developer. */
	public const NEEDS_DEVELOPER = 'needs_developer';

	/**
	 * All verdicts, most urgent first.
	 */
	public const ALL = array( self::NEEDS_DEVELOPER, self::NO_COMPATIBLE_VERSION, self::UPDATE_FIRST, self::READY );

	/**
	 * Decides the verdict for an item.
	 *
	 * When WordPress hasn't checked the installed version for updates yet,
	 * checking for updates is the right first step, so the verdict is
	 * "update first" too.
	 *
	 * @param int          $errors Errors found for the target version.
	 * @param UpdateStatus $update What WordPress's update data says about the item.
	 * @return string One of the verdict constants.
	 */
	public static function decide( int $errors, UpdateStatus $update ): string {
		if ( $errors <= 0 ) {
			return self::READY;
		}

		switch ( $update->status() ) {
			case UpdateStatus::LATEST:
				return self::NO_COMPATIBLE_VERSION;
			case UpdateStatus::NOT_TRACKED:
				return self::NEEDS_DEVELOPER;
			default:
				return self::UPDATE_FIRST;
		}
	}
}
