/**
 * Social Webmention Importer — admin enhancements.
 *
 * Progressive enhancement only: the screen is fully usable without this
 * file. Adds live post search for the target selector, the canonical-URL
 * echo, and the Media Library avatar picker.
 */
( function () {
	'use strict';

	// --- Post search -------------------------------------------------------

	var search = document.getElementById( 'swi-post-search' );
	var select = document.getElementById( 'swi-post-id' );
	var urlEcho = document.querySelector( '[data-swi-target-url]' );
	var debounce;

	function updateTargetUrl() {
		if ( ! urlEcho || ! select ) {
			return;
		}
		var option = select.options[ select.selectedIndex ];
		var url = option ? option.getAttribute( 'data-url' ) : '';
		urlEcho.textContent = url
			? swiAdmin.i18n && swiAdmin.i18n.canonical
				? swiAdmin.i18n.canonical.replace( '%s', url )
				: 'Canonical target URL: ' + url
			: '';
	}

	function fillOptions( items ) {
		var placeholder = select.options[ 0 ];
		while ( select.lastChild ) {
			select.removeChild( select.lastChild );
		}
		select.appendChild( placeholder );
		items.forEach( function ( item ) {
			var option = document.createElement( 'option' );
			option.value = item.id;
			option.textContent = item.title + ' (#' + item.id + ')';
			option.setAttribute( 'data-url', item.url );
			select.appendChild( option );
		} );
	}

	if ( search && select && window.swiAdmin ) {
		select.addEventListener( 'change', updateTargetUrl );

		search.addEventListener( 'input', function () {
			window.clearTimeout( debounce );
			debounce = window.setTimeout( function () {
				var url = new URL( swiAdmin.ajaxUrl, window.location.origin );
				url.searchParams.set( 'action', 'swi_post_search' );
				url.searchParams.set( '_ajax_nonce', swiAdmin.searchNonce );
				url.searchParams.set( 'term', search.value );

				window
					.fetch( url.toString(), { credentials: 'same-origin' } )
					.then( function ( response ) {
						return response.json();
					} )
					.then( function ( payload ) {
						if ( payload && payload.success ) {
							fillOptions( payload.data );
							updateTargetUrl();
						}
					} )
					.catch( function () {
						/* Search stays usable with the server-rendered list. */
					} );
			}, 300 );
		} );
	}

	// --- Media Library avatar picker --------------------------------------

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-swi-media-picker]' );
		if ( ! button || ! window.wp || ! window.wp.media ) {
			return;
		}
		event.preventDefault();

		var input = document.getElementById( button.getAttribute( 'data-swi-media-picker' ) );
		var frame = window.wp.media( {
			title: 'Choose an avatar',
			multiple: false,
			library: { type: 'image' },
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			if ( input ) {
				input.value = attachment.id;
			}
		} );

		frame.open();
	} );
} )();
