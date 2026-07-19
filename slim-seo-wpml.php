<?php
/**
 * Plugin Name:       Slim SEO WPML
 * Plugin URI:        https://example.com/slim-seo-wpml
 * Description:       Leichtgewichtiges, seitentyp-agnostisches SEO-Plugin mit voller WPML-Kompatibilitaet: Meta-Tags, Social-Meta, Schema (JSON-LD), XML-Sitemaps und Crawl-Steuerung.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Your Name
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       slim-seo-wpml
 * Domain Path:       /languages
 *
 * @package SlimSeoWpml
 */

declare(strict_types=1);

namespace SlimSeoWpml;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION  = '0.1.0';
const FILE     = __FILE__;
const DIR      = __DIR__;
const BASENAME = 'slim-seo-wpml/slim-seo-wpml.php';

require_once __DIR__ . '/src/Autoloader.php';
Autoloader::register();

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( Plugin::instance(), 'init' ) );
