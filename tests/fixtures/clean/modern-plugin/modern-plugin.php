<?php
/**
 * Plugin Name: Modern Fixture
 * Requires PHP: 8.1
 *
 * Fixture: modern code that uses PHP 8.0 and 8.1 features. Fine on every
 * target, so the scanner must stay quiet here.
 */

declare(strict_types=1);

namespace Acme\Modern;

require __DIR__ . '/src/Settings.php';
require __DIR__ . '/src/Compat.php';

add_action( 'init', static fn () => Settings::boot( status: Status::Active ) );
