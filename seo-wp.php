<?php
/**
 * Plugin Name:       SEO-WP
 * Plugin URI:        https://seo-wp.net
 * Description:       A slim, page-type-agnostic SEO plugin with full WPML compatibility: meta tags, social meta, JSON-LD schema, XML sitemaps and crawl control.
 * Version:           1.4.6
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Cyrus Badde
 * Author URI:        https://seo-wp.net
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       seo-wp
 * Domain Path:       /languages
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION  = '1.4.6';
const FILE     = __FILE__;
const DIR      = __DIR__;
const BASENAME = 'seo-wp/seo-wp.php';

require_once __DIR__ . '/src/Autoloader.php';
Autoloader::register();

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( Plugin::instance(), 'init' ) );
