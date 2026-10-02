/**
 * Ticketoo Gutenberg blocks — buildless (ADR 0004).
 *
 * The editor only builds a shell: every block is server-rendered by its PHP
 * render_callback (do_shortcode('[ticketoo ...]')), so save() returns null
 * and no serialized markup can drift from the shortcode's output.
 *
 * @package Ticketoo
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	var el = element.createElement;
	var Fragment = element.Fragment;
	var __ = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var NumberControl = components.NumberControl;

	var BLOCKS = [
		{
			name: 'ticketoo/list',
			title: __( 'Ticketoo Ticket List', 'ticketoo' ),
			description: __( 'Shows the tickets of the logged-in visitor.', 'ticketoo' )
		},
		{
			name: 'ticketoo/form',
			title: __( 'Ticketoo New Ticket Form', 'ticketoo' ),
			description: __( 'Shows the new-ticket form.', 'ticketoo' )
		},
		{
			name: 'ticketoo/conversation',
			title: __( 'Ticketoo Conversation', 'ticketoo' ),
			description: __( 'Shows one ticket conversation, selected by ticket ID.', 'ticketoo' ),
			needsId: true
		}
	];

	BLOCKS.forEach( function ( settings ) {
		blocks.registerBlockType( settings.name, {
			title: settings.title,
			description: settings.description,
			icon: 'feedback',
			category: 'widgets',
			attributes: {
				id: {
					type: 'number',
					default: 0
				}
			},
			edit: function ( props ) {
				var blockProps = useBlockProps( { className: 'ticketoo-block' } );
				var inspector = null;

				if ( settings.needsId ) {
					inspector = el(
						InspectorControls,
						null,
						el(
							PanelBody,
							{ title: __( 'Ticket', 'ticketoo' ), initialOpen: true },
							el( NumberControl, {
								label: __( 'Ticket ID', 'ticketoo' ),
								value: props.attributes.id || 0,
								min: 0,
								onChange: function ( value ) {
									props.setAttributes( { id: parseInt( value, 10 ) || 0 } );
								}
							} )
						)
					);
				}

				return el(
					Fragment,
					null,
					el(
						'div',
						blockProps,
						el( 'span', { className: 'ticketoo-block__title' }, settings.title ),
						el( 'span', { className: 'ticketoo-block__description' }, settings.description )
					),
					inspector
				);
			},
			save: function () {
				return null;
			}
		} );
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.i18n
);
