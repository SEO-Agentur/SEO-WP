/**
 * Starts and monitors the persistent Link Audit background job. The server
 * owns the job; this page only polls progress, so closing the tab does not
 * stop the audit.
 */
( function () {
	'use strict';

	var config = window.seoWpLinkAudit;
	var button = document.querySelector( '.seo-wp-link-audit-scan' );
	var status = document.querySelector( '.seo-wp-link-audit-status' );
	var pollTimer = null;
	var reloaded = false;

	if ( ! button || ! status || ! config ) { return; }

	function post( action ) {
		var params = new URLSearchParams();
		params.append( 'action', action );
		params.append( 'nonce', config.nonce );
		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: params.toString(),
		} ).then( function ( response ) { return response.json(); } );
	}

	function elapsed( startedAt ) {
		if ( ! startedAt ) { return '0:00'; }
		var seconds = Math.max( 0, Math.floor( Date.now() / 1000 ) - Number( startedAt ) );
		var hours = Math.floor( seconds / 3600 );
		var minutes = Math.floor( ( seconds % 3600 ) / 60 );
		seconds = seconds % 60;
		return ( hours ? hours + ':' + String( minutes ).padStart( 2, '0' ) : minutes ) + ':' + String( seconds ).padStart( 2, '0' );
	}

	function paint( job ) {
		var state = job.status || 'idle';
		var text = '';
		if ( state === 'scanning' ) {
			text = config.i18n.scanning + ': ' + ( job.current || '' ) + ' · ' + Number( job.processed_posts || 0 ) + '/' + Number( job.total_posts || 0 ) + ' pages';
		} else if ( state === 'checking' ) {
			text = config.i18n.checking + ': ' + ( job.current || '' ) + ' · ' + Number( job.checked_links || 0 ) + '/' + Number( job.total_links || 0 ) + ' unique URLs';
		} else if ( state === 'completed' ) {
			text = config.i18n.done;
		} else if ( state === 'error' ) {
			text = config.i18n.error + ( job.error ? ' ' + job.error : '' );
		}
		if ( text && job.started_at ) { text += ' · ' + config.i18n.elapsed + ' ' + elapsed( job.started_at ); }
		if ( state === 'scanning' || state === 'checking' ) { text += ' · ' + config.i18n.runningBackground; }
		status.textContent = text;
		status.dataset.jobStatus = state;
		button.disabled = state === 'scanning' || state === 'checking';
	}

	function poll() {
		post( config.statusAction ).then( function ( response ) {
			if ( ! response || ! response.success ) { throw new Error(); }
			paint( response.data );
			var state = response.data.status;
			if ( state === 'scanning' || state === 'checking' ) {
				pollTimer = window.setTimeout( poll, 2000 );
			} else if ( state === 'completed' && ! reloaded ) {
				reloaded = true;
				window.setTimeout( function () { window.location.reload(); }, 800 );
			}
		} ).catch( function () {
			status.textContent = config.i18n.error;
			button.disabled = false;
			pollTimer = window.setTimeout( poll, 5000 );
		} );
	}

	button.addEventListener( 'click', function ( event ) {
		event.preventDefault();
		button.disabled = true;
		status.textContent = config.i18n.preparing;
		post( config.startAction ).then( function ( response ) {
			if ( ! response || ! response.success ) { throw new Error(); }
			paint( response.data );
			if ( pollTimer ) { window.clearTimeout( pollTimer ); }
			pollTimer = window.setTimeout( poll, 750 );
		} ).catch( function () {
			status.textContent = config.i18n.error;
			button.disabled = false;
		} );
	} );

	if ( status.dataset.jobStatus === 'scanning' || status.dataset.jobStatus === 'checking' ) {
		button.disabled = true;
		poll();
	}
} )();
