<?php
/**
 * What WordPress's update data says about a plugin or theme.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Scanner;

/**
 * Reads the update_plugins or update_themes transient, which WordPress fills
 * when it checks for updates. Reading it makes no outside requests.
 */
final class UpdateStatus {

	/** A newer version is available. */
	public const AVAILABLE = 'available';

	/** The update source (usually WordPress.org) knows the item, and this is its newest version. */
	public const LATEST = 'latest';

	/** WordPress checked for updates, and nothing provides them for this item: custom code. */
	public const NOT_TRACKED = 'not_tracked';

	/** WordPress hasn't checked this version for updates yet. */
	public const UNKNOWN = 'unknown';

	/**
	 * One of the constants above.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * The newer version, when one is available.
	 *
	 * @var string
	 */
	private $new_version;

	/**
	 * Use from_transient() instead.
	 *
	 * @param string $status      One of the constants.
	 * @param string $new_version The newer version, or "".
	 */
	private function __construct( string $status, string $new_version ) {
		$this->status      = $status;
		$this->new_version = $new_version;
	}

	/**
	 * Reads an item's status from WordPress's update data.
	 *
	 * @param mixed  $transient The update_plugins or update_themes site transient,
	 *                          or false when it doesn't exist.
	 * @param string $key       Plugin file, such as "akismet/akismet.php", or
	 *                          theme directory name.
	 * @param string $version   The installed version.
	 * @return self
	 */
	public static function from_transient( $transient, string $key, string $version ): self {
		// WordPress lists every installed item it checked, with the version it checked.
		$checked = is_object( $transient ) && isset( $transient->checked ) && is_array( $transient->checked ) ? $transient->checked : array();
		if ( ! isset( $checked[ $key ] ) || (string) $checked[ $key ] !== $version ) {
			return new self( self::UNKNOWN, '' );
		}

		// Plugin updates are objects, theme updates are arrays.
		$update      = isset( $transient->response ) && is_array( $transient->response ) ? ( $transient->response[ $key ] ?? null ) : null;
		$new_version = (string) ( is_object( $update ) ? ( $update->new_version ?? '' ) : ( is_array( $update ) ? ( $update['new_version'] ?? '' ) : '' ) );
		if ( '' !== $new_version && version_compare( $new_version, $version, '>' ) ) {
			return new self( self::AVAILABLE, $new_version );
		}

		$known = null !== $update || ( isset( $transient->no_update ) && is_array( $transient->no_update ) && isset( $transient->no_update[ $key ] ) );

		return new self( $known ? self::LATEST : self::NOT_TRACKED, '' );
	}

	/**
	 * One of the constants.
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * The newer version, when the status is AVAILABLE.
	 *
	 * @return string
	 */
	public function new_version(): string {
		return $this->new_version;
	}
}
