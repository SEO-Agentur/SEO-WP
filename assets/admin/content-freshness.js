/**
 * Auto-submits the Content Freshness threshold filter on change, so the
 * "Filter" button is only needed as a no-JS fallback. Vanilla JS, no
 * dependencies; loaded only on the SEO-WP > Content Freshness screen.
 */
( function () {
	'use strict';

	document.addEventListener( 'change', function ( event ) {
		if ( event.target && 'seo-wp-freshness-months' === event.target.id && event.target.form ) {
			event.target.form.submit();
		}
	} );
} )();
