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

	var queue = document.querySelector( '[data-swi-queue]' );
	var queueControls = document.querySelector( '[data-swi-queue-controls]' );
	if ( queue && queueControls ) {
		var cards = Array.from( queue.children );
		var stateFilter = document.getElementById( 'swi-queue-state' );
		var sourceFilter = document.getElementById( 'swi-queue-source' );
		var sort = document.getElementById( 'swi-queue-order' );
		var queueStatus = queueControls.querySelector( '[data-swi-queue-status]' );

		function filterQueue() {
			var visible = 0;
			cards.forEach( function ( card ) {
				card.hidden =
					( stateFilter.value && card.dataset.swiState !== stateFilter.value ) ||
					( sourceFilter.value && card.dataset.swiHost !== sourceFilter.value );
				card.disabled = false;
				var include = card.querySelector( '[data-swi-include]' );
				if ( include ) {
					include.disabled = card.hidden;
				}
				visible += Number( ! card.hidden );
			} );
			queueStatus.textContent = window.swiAdmin.queueCount
				.replace( '%1$s', visible )
				.replace( '%2$s', cards.length );
		}

		stateFilter.addEventListener( 'change', filterQueue );
		sourceFilter.addEventListener( 'change', filterQueue );
		sort.addEventListener( 'change', function () {
			var ordered = cards.slice();
			if ( 'state' === sort.value ) {
				ordered.sort( ( a, b ) => a.dataset.swiStateLabel.localeCompare( b.dataset.swiStateLabel ) );
			} else if ( 'source' === sort.value ) {
				ordered.sort( ( a, b ) => a.dataset.swiSource.localeCompare( b.dataset.swiSource ) );
			}
			ordered.forEach( ( card ) => queue.appendChild( card ) );
		} );
		// Update preview keeps every draft; import leaves hidden rows out, so they can't block validation.
		var form = queue.closest( 'form' );
		form.querySelector( '[name="swi_update_preview"]' ).addEventListener( 'click', function () {
			cards.forEach( ( card ) => ( card.disabled = false ) );
		} );
		form.querySelector( '[name="submit"]' ).addEventListener( 'click', function () {
			cards.forEach( ( card ) => ( card.disabled = card.hidden ) );
		} );
		filterQueue();
		sort.dispatchEvent( new Event( 'change' ) );
		queueControls.classList.remove( 'hidden' );
	}

	// --- Media Library avatar picker --------------------------------------

	if ( ! window.wp || ! window.wp.media || ! window.swiAdmin ) {
		return;
	}

	document.querySelectorAll( '[data-swi-media-picker]' ).forEach( function ( button ) {
		var input = document.getElementById( button.getAttribute( 'data-swi-media-picker' ) );
		var field = button.closest( '.swi-avatar-field' );
		var status = field.querySelector( '[data-swi-media-status]' );
		var preview = field.querySelector( '.swi-avatar-preview' );

		input.addEventListener( 'input', function () {
			status.textContent = '';
			if ( preview ) {
				preview.remove();
			}
		} );

		button.addEventListener( 'click', function () {
			var frame = window.wp.media( {
				title: window.swiAdmin.avatarTitle,
				multiple: false,
				library: { type: 'image' },
			} );

			var attachment;
			frame.on( 'select', function () {
				attachment = frame.state().get( 'selection' ).first();
			} );

			frame.on( 'close', function () {
				button.focus();
				// Core can fire "close" before "select" within the same event.
				window.setTimeout( function () {
					frame.detach();
					if ( ! attachment ) {
						return;
					}
					input.value = attachment.id;
					input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
					status.textContent = window.swiAdmin.avatarSelected.replace( '%s', attachment.id );
				}, 0 );
			} );

			frame.open();
		} );
		button.classList.remove( 'hidden' );
	} );
} )();
