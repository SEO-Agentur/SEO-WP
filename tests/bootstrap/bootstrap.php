<?php
/**
 * PHPUnit bootstrap: loads the plugin's own autoloader plus a handful of
 * hand-rolled WordPress function stubs for pure-logic unit tests.
 *
 * @package SeoWp
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/Autoloader.php';
require_once __DIR__ . '/wp-stubs.php';

SeoWp\Autoloader::register();
