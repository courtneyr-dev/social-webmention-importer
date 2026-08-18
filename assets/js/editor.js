/**
 * Social Webmention Importer — block editor panel.
 *
 * Adds a "Social responses" document-settings panel where an authorized
 * editor pastes response URLs for the post being edited. Submitting hands
 * the batch to the existing admin-post preview flow, so review, editing,
 * and import all happen on the same screen as Tools-initiated batches.
 *
 * Written without a build step: plain wp.element calls, no JSX.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! window.swiEditor ) {
		return;
	}

	var Panel =
		( wp.editor && wp.editor.PluginDocumentSettingPanel ) ||
		( wp.editPost && wp.editPost.PluginDocumentSettingPanel );

	if ( ! Panel ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useSelect = wp.data.useSelect;
	var __ = wp.i18n.__;

	function SwiPanel() {
		var state = useState( '' );
		var urls = state[ 0 ];
		var setUrls = state[ 1 ];

		var post = useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			return {
				id: editor.getCurrentPostId(),
				status: editor.getEditedPostAttribute( 'status' ),
				dirty: editor.isEditedPostDirty(),
			};
		}, [] );

		var submit = function () {
			var form = document.createElement( 'form' );
			form.method = 'POST';
			form.action = window.swiEditor.adminPost;

			var fields = {
				action: 'swi_preview',
				_wpnonce: window.swiEditor.nonce,
				swi_post_id: String( post.id ),
				swi_urls: urls,
			};
			Object.keys( fields ).forEach( function ( name ) {
				var input = document.createElement( 'input' );
				input.type = 'hidden';
				input.name = name;
				input.value = fields[ name ];
				form.appendChild( input );
			} );

			document.body.appendChild( form );
			form.submit();
		};

		return el(
			Panel,
			{
				name: 'swi-social-responses',
				title: __( 'Social responses', 'social-webmention-importer' ),
			},
			el( wp.components.TextareaControl, {
				label: __( 'Response URLs', 'social-webmention-importer' ),
				help: __(
					'One X/Twitter or LinkedIn URL per line. Preview opens the importer’s review screen for this post.',
					'social-webmention-importer'
				),
				rows: 5,
				value: urls,
				onChange: setUrls,
				__nextHasNoMarginBottom: true,
			} ),
			post.dirty
				? el(
						'p',
						{ className: 'swi-editor-warning' },
						__( 'Save the post first — previewing leaves this screen.', 'social-webmention-importer' )
				  )
				: null,
			el(
				wp.components.Button,
				{
					variant: 'primary',
					disabled: ! urls.trim() || ! post.id || 'auto-draft' === post.status,
					onClick: submit,
				},
				__( 'Preview responses', 'social-webmention-importer' )
			)
		);
	}

	wp.plugins.registerPlugin( 'social-webmention-importer', {
		render: SwiPanel,
	} );
} )( window.wp );
