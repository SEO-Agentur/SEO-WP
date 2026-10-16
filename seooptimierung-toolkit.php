<?php
/**
 * Plugin Name:       Seooptimierung Toolkit
 * Plugin URI:        https://seooptimierung.de/seo-toolkit
 * Description:       A slim, page-type-agnostic SEO plugin with full WPML and Polylang compatibility: meta tags, social meta, JSON-LD schema, XML sitemaps and crawl control.
 * Version:           1.6.2
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Cyrus Badde
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       seooptimierung-toolkit
 * Domain Path:       /languages
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION  = '1.6.2';
const FILE     = __FILE__;
const DIR      = __DIR__;
const BASENAME = 'seooptimierung-toolkit/seooptimierung-toolkit.php';

require_once __DIR__ . '/src/Autoloader.php';
Autoloader::register();

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( Plugin::instance(), 'init' ) );
