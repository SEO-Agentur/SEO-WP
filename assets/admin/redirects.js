/**
 * Toggles the source/target field hints between "fixed path" and "regex
 * pattern" wording when the Pattern checkbox is (un)checked, so the form
 * always explains what it currently expects. Vanilla JS, no dependencies;
 * loaded only on the SEO-WP > Redirects screen.
 */
( function () {
	'use strict';

	function applyRegexMode( isRegex ) {
		var sourceHint = document.querySelector( '[data-seo-wp-redirect-source-hint]' );
		var targetHint = document.querySelector( '[data-seo-wp-redirect-target-hint]' );
		var sourceInput = document.getElementById( 'seo_wp_redirect_source' );

		if ( sourceHint ) {
			sourceHint.style.display = isRegex ? 'none' : '';
		}

		if ( targetHint ) {
			targetHint.style.display = isRegex ? '' : 'none';
		}

		if ( sourceInput ) {
			sourceInput.placeholder = isRegex ? '^/blog/(.+)$' : '/old-page/';
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var checkbox = document.getElementById( 'seo_wp_redirect_is_regex' );
		if ( ! checkbox ) {
			return;
		}

		applyRegexMode( checkbox.checked );
		checkbox.addEventListener( 'change', function () {
			applyRegexMode( checkbox.checked );
		} );
	} );
} )();
