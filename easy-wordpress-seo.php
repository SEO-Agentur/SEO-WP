<?php
/**
 * Plugin Name:       Easy WordPress SEO
 * Plugin URI:        https://example.com/easy-wordpress-seo
 * Description:       Leichtgewichtiges, seitentyp-agnostisches SEO-Plugin mit voller WPML-Kompatibilitaet: Meta-Tags, Social-Meta, Schema (JSON-LD), XML-Sitemaps und Crawl-Steuerung.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Your Name
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       easy-wordpress-seo
 * Domain Path:       /languages
 *
 * @package EasyWordpressSeo
 */

declare(strict_types=1);

namespace EasyWordpressSeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION  = '0.1.0';
const FILE     = __FILE__;
const DIR      = __DIR__;
const BASENAME = 'easy-wordpress-seo/easy-wordpress-seo.php';

require_once __DIR__ . '/src/Autoloader.php';
Autoloader::register();

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( Plugin::instance(), 'init' ) );
