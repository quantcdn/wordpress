/**
 * Block editor registration for the Quant Search blocks.
 *
 * Both blocks are rendered server side (the shortcodes do the work), so the
 * editor only needs a page picker and, for autocomplete, two options.
 */
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	TextControl,
	ToggleControl,
	Placeholder,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

const pages =
	( window.quantSearchBlocks && window.quantSearchBlocks.pages ) || [];
const pageOptions = [
	{ label: __( 'Default search page', 'quant' ), value: '' },
].concat( pages.map( ( page ) => ( { label: page.label, value: page.id } ) ) );

/**
 * Sidebar panel with the search page picker.
 *
 * @param {Object}   props               Component props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 * @param {*}        props.children      Extra controls.
 */
function PagePanel( { attributes, setAttributes, children } ) {
	return (
		<InspectorControls>
			<PanelBody title={ __( 'Quant Search', 'quant' ) }>
				<SelectControl
					label={ __( 'Search page', 'quant' ) }
					value={ attributes.page }
					options={ pageOptions }
					onChange={ ( page ) => setAttributes( { page } ) }
				/>
				{ children }
			</PanelBody>
		</InspectorControls>
	);
}

/**
 * Server-side preview, or a hint when no search page exists yet.
 *
 * @param {Object} props            Component props.
 * @param {string} props.block      Block name.
 * @param {Object} props.attributes Block attributes.
 */
function Preview( { block, attributes } ) {
	if ( pages.length === 0 ) {
		return (
			<Placeholder icon="search" label={ __( 'Quant Search', 'quant' ) }>
				{ __(
					'Create a search page under QuantCDN > Search first.',
					'quant'
				) }
			</Placeholder>
		);
	}

	return <ServerSideRender block={ block } attributes={ attributes } />;
}

/**
 * Editor component for the search block.
 *
 * @param {Object}   props               Component props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 */
function SearchEdit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps();
	return (
		<div { ...blockProps }>
			<PagePanel
				attributes={ attributes }
				setAttributes={ setAttributes }
			/>
			<Preview block="quant/search" attributes={ attributes } />
		</div>
	);
}

/**
 * Editor component for the autocomplete block.
 *
 * @param {Object}   props               Component props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 */
function AutocompleteEdit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps();
	const showSummary = !! attributes.show_summary;
	return (
		<div { ...blockProps }>
			<PagePanel
				attributes={ attributes }
				setAttributes={ setAttributes }
			>
				<TextControl
					label={ __( 'Placeholder', 'quant' ) }
					value={ attributes.placeholder }
					onChange={ ( placeholder ) =>
						setAttributes( { placeholder } )
					}
				/>
				<ToggleControl
					label={ __( 'Show summary in suggestions', 'quant' ) }
					checked={ showSummary }
					onChange={ ( value ) =>
						setAttributes( { show_summary: value } )
					}
				/>
			</PagePanel>
			<div className="quant-search-autocomplete quant-search-autocomplete--editor">
				<input
					type="search"
					disabled
					aria-label={ __( 'Search preview', 'quant' ) }
					placeholder={
						attributes.placeholder || __( 'Search', 'quant' )
					}
				/>
			</div>
		</div>
	);
}

registerBlockType( 'quant/search', { edit: SearchEdit, save: () => null } );
registerBlockType( 'quant/search-autocomplete', {
	edit: AutocompleteEdit,
	save: () => null,
} );
