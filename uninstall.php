<?php
/**
 * Fired when the plugin is uninstalled via the WordPress admin.
 *
 * Removes plugin options and transients. Post/term meta written by this
 * plugin is intentionally left in place, matching the non-destructive
 * import stance described in the plugin's requirements.
 *
 * @package SlimSeoWpml
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Options and transients are registered by later milestones; nothing to
// clean up yet in the M0 scaffold.
