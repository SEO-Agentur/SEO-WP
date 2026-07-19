<?php
/**
 * PHPUnit bootstrap: loads only the plugin's own autoloader.
 *
 * WordPress function stubs (WP_Mock/Brain\Monkey) are added once tests
 * start exercising code that calls into WordPress, starting milestone M1.
 *
 * @package SlimSeoWpml
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/Autoloader.php';

SlimSeoWpml\Autoloader::register();
