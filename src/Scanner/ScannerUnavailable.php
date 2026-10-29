<?php
/**
 * Thrown when the scanner can't run at all on this server.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Scanner;

use RuntimeException;

/**
 * For problems that affect every file, such as a missing PHP extension or
 * another plugin's copy of PHP_CodeSniffer already being loaded. Problems with
 * a single file end up in its FileReport instead.
 */
final class ScannerUnavailable extends RuntimeException {
}
