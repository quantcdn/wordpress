import { useState } from '@wordpress/element';
import {
	Button,
	TextControl,
	TextareaControl,
	ToggleControl,
	SelectControl,
	CheckboxControl,
	Flex,
	FlexItem,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const EMPTY_FACET = {
	facet_type: 'taxonomy',
	taxonomy: 'category',
	custom_key: '',
	facet_display: 'checkbox',
	facet_heading: '',
	facet_limit: 10,
};

const DEFAULT_PAGE = {
	id: '',
	label: '',
	status: true,
	route: '/search',
	title: 'Search',
	description: '',
	post_types: [],
	languages: [],
	manual_filters: '',
	display: {
		display_search: true,
		display_stats: true,
		show_clear_refinements: true,
		pagination_enabled: true,
		per_page: 10,
	},
	facets: [],
};

function FacetRow( { facet, taxonomies, onChange, onRemove } ) {
	return (
		<div className="quant-facet-row">
			<SelectControl
				label={ __( 'Type', 'quant' ) }
				value={ facet.facet_type }
				options={ [
					{ label: __( 'Taxonomy', 'quant' ), value: 'taxonomy' },
					{
						label: __( 'Content type', 'quant' ),
						value: 'content_type',
					},
					{ label: __( 'Language', 'quant' ), value: 'language' },
					{
						label: __( 'Custom attribute', 'quant' ),
						value: 'custom',
					},
				] }
				onChange={ ( v ) => onChange( { ...facet, facet_type: v } ) }
				__nextHasNoMarginBottom
			/>
			{ facet.facet_type === 'taxonomy' && (
				<SelectControl
					label={ __( 'Taxonomy', 'quant' ) }
					value={ facet.taxonomy }
					options={ taxonomies.map( ( t ) => ( {
						label: t.label,
						value: t.name,
					} ) ) }
					onChange={ ( v ) => onChange( { ...facet, taxonomy: v } ) }
					__nextHasNoMarginBottom
				/>
			) }
			{ facet.facet_type === 'custom' && (
				<TextControl
					label={ __( 'Attribute key', 'quant' ) }
					value={ facet.custom_key }
					onChange={ ( v ) =>
						onChange( { ...facet, custom_key: v } )
					}
					__nextHasNoMarginBottom
				/>
			) }
			<TextControl
				label={ __( 'Heading', 'quant' ) }
				value={ facet.facet_heading }
				onChange={ ( v ) => onChange( { ...facet, facet_heading: v } ) }
				__nextHasNoMarginBottom
			/>
			<SelectControl
				label={ __( 'Display', 'quant' ) }
				value={ facet.facet_display }
				options={ [
					{ label: __( 'Checkboxes', 'quant' ), value: 'checkbox' },
					{ label: __( 'Menu', 'quant' ), value: 'menu' },
					{ label: __( 'Select', 'quant' ), value: 'select' },
				] }
				onChange={ ( v ) => onChange( { ...facet, facet_display: v } ) }
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Limit', 'quant' ) }
				type="number"
				value={ facet.facet_limit }
				onChange={ ( v ) =>
					onChange( {
						...facet,
						facet_limit: parseInt( v, 10 ) || 10,
					} )
				}
				__nextHasNoMarginBottom
			/>
			<Button variant="link" isDestructive onClick={ onRemove }>
				{ __( 'Remove', 'quant' ) }
			</Button>
		</div>
	);
}

/**
 * Form for one Quant Search page definition.
 * @param {Object}        props            Component props.
 * @param {number|Object} props.page       page.
 * @param {Array}         props.postTypes  postTypes.
 * @param {Array}         props.taxonomies taxonomies.
 * @param {Function}      props.onSave     onSave.
 * @param {Function}      props.onCancel   onCancel.
 * @param {boolean}       props.saving     saving.
 */
export function SearchPageEditor( {
	page,
	postTypes,
	taxonomies,
	onSave,
	onCancel,
	saving,
} ) {
	const [ draft, setDraft ] = useState( {
		...DEFAULT_PAGE,
		...( page || {} ),
		display: { ...DEFAULT_PAGE.display, ...( page?.display || {} ) },
	} );

	const set = ( key, value ) =>
		setDraft( ( prev ) => ( { ...prev, [ key ]: value } ) );
	const setDisplay = ( key, value ) =>
		setDraft( ( prev ) => ( {
			...prev,
			display: { ...prev.display, [ key ]: value },
		} ) );
	const setFacet = ( index, facet ) =>
		setDraft( ( prev ) => ( {
			...prev,
			facets: prev.facets.map( ( f, i ) => ( i === index ? facet : f ) ),
		} ) );
	const removeFacet = ( index ) =>
		setDraft( ( prev ) => ( {
			...prev,
			facets: prev.facets.filter( ( _, i ) => i !== index ),
		} ) );
	const togglePostType = ( name, checked ) =>
		set(
			'post_types',
			checked
				? [ ...draft.post_types, name ]
				: draft.post_types.filter( ( t ) => t !== name )
		);

	return (
		<div className="quant-search-page-editor">
			<h3 style={ { marginTop: 0 } }>
				{ page?.id
					? __( 'Edit search page', 'quant' )
					: __( 'New search page', 'quant' ) }
			</h3>

			<TextControl
				label={ __( 'Label', 'quant' ) }
				value={ draft.label }
				onChange={ ( v ) => set( 'label', v ) }
				help={ __( 'Shown in the admin only.', 'quant' ) }
			/>
			<ToggleControl
				label={ __( 'Enabled', 'quant' ) }
				checked={ !! draft.status }
				onChange={ ( v ) => set( 'status', v ) }
				help={ __(
					'A disabled page is kept as a draft and removed from Quant.',
					'quant'
				) }
			/>
			<TextControl
				label={ __( 'Route', 'quant' ) }
				value={ draft.route }
				onChange={ ( v ) => set( 'route', v ) }
				placeholder="/search"
				help={ __(
					'The plugin creates a WordPress page at this path that renders the search.',
					'quant'
				) }
			/>
			<TextControl
				label={ __( 'Page title', 'quant' ) }
				value={ draft.title }
				onChange={ ( v ) => set( 'title', v ) }
			/>
			<TextareaControl
				label={ __( 'Description', 'quant' ) }
				value={ draft.description }
				onChange={ ( v ) => set( 'description', v ) }
				rows={ 2 }
			/>

			<p>
				<strong>{ __( 'Content types to search', 'quant' ) }</strong>{ ' ' }
				<span style={ { color: '#666' } }>
					{ __( '(none selected = all indexed types)', 'quant' ) }
				</span>
			</p>
			{ postTypes.map( ( type ) => (
				<CheckboxControl
					key={ type.name }
					label={ type.label }
					checked={ draft.post_types.includes( type.name ) }
					onChange={ ( checked ) =>
						togglePostType( type.name, checked )
					}
				/>
			) ) }

			<TextControl
				label={ __( 'Manual filters', 'quant' ) }
				value={ draft.manual_filters }
				onChange={ ( v ) => set( 'manual_filters', v ) }
				placeholder="category_en:'News'"
				help={ __(
					"Appended to the page filters as written. Use the project's search syntax: Algolia on QuantCDN (category_en:'News'), Typesense on QuantGov (category_en:=`News`).",
					'quant'
				) }
			/>

			<h4>{ __( 'Display', 'quant' ) }</h4>
			<ToggleControl
				label={ __( 'Show the search box', 'quant' ) }
				checked={ !! draft.display.display_search }
				onChange={ ( v ) => setDisplay( 'display_search', v ) }
			/>
			<ToggleControl
				label={ __( 'Show the result count', 'quant' ) }
				checked={ !! draft.display.display_stats }
				onChange={ ( v ) => setDisplay( 'display_stats', v ) }
			/>
			<ToggleControl
				label={ __( 'Show "Clear refinements"', 'quant' ) }
				checked={ !! draft.display.show_clear_refinements }
				onChange={ ( v ) => setDisplay( 'show_clear_refinements', v ) }
			/>
			<ToggleControl
				label={ __( 'Pagination', 'quant' ) }
				checked={ !! draft.display.pagination_enabled }
				onChange={ ( v ) => setDisplay( 'pagination_enabled', v ) }
			/>
			<TextControl
				label={ __( 'Results per page', 'quant' ) }
				type="number"
				value={ draft.display.per_page }
				onChange={ ( v ) =>
					setDisplay( 'per_page', parseInt( v, 10 ) || 10 )
				}
			/>

			<h4>{ __( 'Facets', 'quant' ) }</h4>
			{ draft.facets.map( ( facet, index ) => (
				<FacetRow
					key={ index }
					facet={ facet }
					taxonomies={ taxonomies }
					onChange={ ( f ) => setFacet( index, f ) }
					onRemove={ () => removeFacet( index ) }
				/>
			) ) }
			<Button
				variant="secondary"
				onClick={ () =>
					set( 'facets', [ ...draft.facets, { ...EMPTY_FACET } ] )
				}
			>
				{ __( 'Add facet', 'quant' ) }
			</Button>

			<Flex
				justify="flex-start"
				gap={ 2 }
				style={ { marginTop: '24px' } }
			>
				<FlexItem>
					<Button
						variant="primary"
						onClick={ () => onSave( draft ) }
						disabled={ saving || ! draft.label.trim() }
						isBusy={ saving }
					>
						{ __( 'Save search page', 'quant' ) }
					</Button>
				</FlexItem>
				<FlexItem>
					<Button
						variant="tertiary"
						onClick={ onCancel }
						disabled={ saving }
					>
						{ __( 'Cancel', 'quant' ) }
					</Button>
				</FlexItem>
			</Flex>
		</div>
	);
}
