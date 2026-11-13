import { useState, useEffect } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	Spinner,
	SelectControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import './ContentBrowser.scss';

/**
 * Status badge component
 * @param {Object} props        Component props.
 * @param {string} props.status status.
 */
function StatusBadge( { status } ) {
	const statusLabels = {
		synced: __( 'Synced', 'quant' ),
		pending: __( 'Pending', 'quant' ),
		never: __( 'Never', 'quant' ),
		failed: __( 'Failed', 'quant' ),
	};

	return (
		<span
			className={ `quant-status-badge quant-status-badge--${ status }` }
		>
			{ statusLabels[ status ] || status }
		</span>
	);
}

/**
 * Pagination component
 * @param {Object}        props              Component props.
 * @param {number|Object} props.page         page.
 * @param {number}        props.pages        pages.
 * @param {Function}      props.onPageChange onPageChange.
 */
function Pagination( { page, pages, onPageChange } ) {
	if ( pages <= 1 ) {
		return null;
	}

	return (
		<div className="quant-pagination">
			<Button
				variant="secondary"
				size="small"
				disabled={ page <= 1 }
				onClick={ () => onPageChange( page - 1 ) }
			>
				{ __( 'Previous', 'quant' ) }
			</Button>
			<span className="quant-pagination__info">
				{ __( 'Page', 'quant' ) } { page } { __( 'of', 'quant' ) }{ ' ' }
				{ pages }
			</span>
			<Button
				variant="secondary"
				size="small"
				disabled={ page >= pages }
				onClick={ () => onPageChange( page + 1 ) }
			>
				{ __( 'Next', 'quant' ) }
			</Button>
		</div>
	);
}

/**
 * ContentBrowser component - displays content list with sync status
 * @param {Object}   props                   Component props.
 * @param {Object}   props.contentType       contentType.
 * @param {Array}    props.contentTypes      contentTypes.
 * @param {string}   props.specialType       specialType.
 * @param {Function} props.onSelectionChange onSelectionChange.
 * @param {number}   props.refreshToken      Bump to reload the list.
 */
export function ContentBrowser( {
	contentType,
	contentTypes,
	specialType,
	onSelectionChange,
	refreshToken = 0,
} ) {
	const [ items, setItems ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ loadError, setLoadError ] = useState( null );
	const [ page, setPage ] = useState( 1 );
	const [ totalPages, setTotalPages ] = useState( 1 );
	const [ totalItems, setTotalItems ] = useState( 0 );
	const [ selectedIds, setSelectedIds ] = useState( new Set() );
	const [ selectAll, setSelectAll ] = useState( false );

	const hasTaxonomies =
		Array.isArray( contentTypes ) && contentTypes.length > 0;
	const [ selectedTaxonomy, setSelectedTaxonomy ] = useState( () =>
		hasTaxonomies ? contentTypes[ 0 ].subtype || 'category' : ''
	);

	const contentKind = contentType && contentType.type;
	const contentSubtype = ( contentType && contentType.subtype ) || '';

	// One fetch per (page, taxonomy, refresh); responses from superseded
	// requests are ignored so a fast tab or taxonomy switch cannot show
	// the wrong rows.
	useEffect( () => {
		let ignore = false;

		const buildPath = () => {
			if ( specialType === 'theme_assets' ) {
				return `/quant/v1/theme-assets?page=${ page }&per_page=50`;
			}
			if ( specialType === 'redirects' ) {
				return `/quant/v1/redirects?page=${ page }&per_page=50`;
			}
			const type = hasTaxonomies ? 'taxonomy' : contentKind || 'post';
			const subtype = hasTaxonomies ? selectedTaxonomy : contentSubtype;
			return `/quant/v1/content?type=${ type }&subtype=${ encodeURIComponent(
				subtype
			) }&page=${ page }&per_page=20`;
		};

		setLoading( true );
		setLoadError( null );
		apiFetch( { path: buildPath() } )
			.then( ( response ) => {
				if ( ignore ) {
					return;
				}
				setItems( response.items || [] );
				setTotalPages( response.pages || 1 );
				setTotalItems( response.total || 0 );
			} )
			.catch( ( error ) => {
				if ( ignore ) {
					return;
				}
				setItems( [] );
				setTotalPages( 1 );
				setTotalItems( 0 );
				setLoadError(
					error.message || __( 'Failed to load content.', 'quant' )
				);
			} )
			.finally( () => {
				if ( ! ignore ) {
					setLoading( false );
				}
			} );

		return () => {
			ignore = true;
		};
	}, [
		page,
		selectedTaxonomy,
		refreshToken,
		specialType,
		hasTaxonomies,
		contentKind,
		contentSubtype,
	] );

	const handleTaxonomyChange = ( taxonomy ) => {
		setSelectedTaxonomy( taxonomy );
		setPage( 1 );
		setSelectedIds( new Set() );
		setSelectAll( false );
	};

	// Reset state when switching content types (via key prop on parent)
	useEffect( () => {
		setSelectedIds( new Set() );
		setSelectAll( false );
	}, [ contentType, contentTypes, specialType ] );

	// Notify parent of selection changes
	useEffect( () => {
		if ( onSelectionChange ) {
			const selectedItems = items
				.filter( ( item ) => selectedIds.has( item.id ) )
				.map( ( item ) => ( {
					type: item.type,
					id: item.id,
					subtype: item.subtype,
					url: item.url,
				} ) );
			onSelectionChange( selectedItems );
		}
	}, [ selectedIds, items, onSelectionChange ] );

	// Handle individual item selection
	const handleItemSelect = ( id, checked ) => {
		const newSelected = new Set( selectedIds );
		if ( checked ) {
			newSelected.add( id );
		} else {
			newSelected.delete( id );
		}
		setSelectedIds( newSelected );
		setSelectAll( newSelected.size === items.length );
	};

	// Handle select all
	const handleSelectAll = ( checked ) => {
		setSelectAll( checked );
		if ( checked ) {
			setSelectedIds( new Set( items.map( ( item ) => item.id ) ) );
		} else {
			setSelectedIds( new Set() );
		}
	};

	// Handle page change
	const handlePageChange = ( newPage ) => {
		setPage( newPage );
		setSelectedIds( new Set() );
		setSelectAll( false );
	};

	// Format date for display
	const formatDate = ( dateString ) => {
		if ( ! dateString ) {
			return '-';
		}
		const date = new Date( dateString );
		return date.toLocaleDateString( undefined, {
			year: 'numeric',
			month: 'short',
			day: 'numeric',
			hour: '2-digit',
			minute: '2-digit',
		} );
	};

	// Build CDN URL
	const getCdnUrl = ( item ) => {
		// This would need to be configured based on the actual CDN domain
		return item.url;
	};

	// Render taxonomy selector for taxonomy tabs
	const renderTaxonomySelector = () => {
		if ( ! contentTypes || contentTypes.length <= 1 ) {
			return null;
		}

		const options = contentTypes.map( ( t ) => ( {
			label: `${ t.label } (${ t.count })`,
			value: t.subtype,
		} ) );

		return (
			<div className="quant-content-browser__taxonomy-selector">
				<SelectControl
					label={ __( 'Taxonomy', 'quant' ) }
					value={ selectedTaxonomy }
					options={ options }
					onChange={ handleTaxonomyChange }
					__nextHasNoMarginBottom
				/>
			</div>
		);
	};

	if ( loading && items.length === 0 ) {
		return (
			<div className="quant-content-browser quant-content-browser--loading">
				<Spinner />
				<p>{ __( 'Loading content…', 'quant' ) }</p>
			</div>
		);
	}

	return (
		<div className="quant-content-browser">
			{ renderTaxonomySelector() }

			<div className="quant-content-browser__header">
				<span className="quant-content-browser__count">
					{ totalItems } { __( 'items', 'quant' ) }
					{ selectedIds.size > 0 &&
						` (${ selectedIds.size } ${ __(
							'selected',
							'quant'
						) })` }
				</span>
				<Pagination
					page={ page }
					pages={ totalPages }
					onPageChange={ handlePageChange }
				/>
			</div>

			{ loadError && (
				<div className="quant-content-browser__empty">
					<p className="quant-status--error">{ loadError }</p>
				</div>
			) }

			{ ! loadError && items.length === 0 && (
				<div className="quant-content-browser__empty">
					<p>{ __( 'No content found.', 'quant' ) }</p>
				</div>
			) }

			{ items.length > 0 && (
				<table className="quant-content-browser__table">
					<thead>
						<tr>
							<th className="quant-content-browser__col-checkbox">
								<CheckboxControl
									label={
										<span className="screen-reader-text">
											{ __( 'Select all', 'quant' ) }
										</span>
									}
									checked={ selectAll }
									onChange={ handleSelectAll }
									__nextHasNoMarginBottom
								/>
							</th>
							<th className="quant-content-browser__col-title">
								{ specialType === 'redirects'
									? __( 'Source', 'quant' )
									: __( 'Title', 'quant' ) }
							</th>
							<th className="quant-content-browser__col-url">
								{ specialType === 'redirects'
									? __( 'Destination', 'quant' )
									: __( 'URL', 'quant' ) }
							</th>
							{ specialType === 'redirects' && (
								<th className="quant-content-browser__col-code">
									{ __( 'Code', 'quant' ) }
								</th>
							) }
							{ specialType === 'theme_assets' && (
								<th className="quant-content-browser__col-type">
									{ __( 'Type', 'quant' ) }
								</th>
							) }
							<th className="quant-content-browser__col-status">
								{ __( 'Status', 'quant' ) }
							</th>
							{ ! specialType && (
								<th className="quant-content-browser__col-synced">
									{ __( 'Last Synced', 'quant' ) }
								</th>
							) }
							<th className="quant-content-browser__col-actions">
								{ __( 'Actions', 'quant' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ items.map( ( item ) => (
							<tr
								key={ item.id }
								className={
									selectedIds.has( item.id )
										? 'is-selected'
										: ''
								}
							>
								<td className="quant-content-browser__col-checkbox">
									<CheckboxControl
										label={
											<span className="screen-reader-text">
												{ sprintf(
													/* translators: %s: item title or URL */
													__( 'Select %s', 'quant' ),
													item.title || item.url
												) }
											</span>
										}
										checked={ selectedIds.has( item.id ) }
										onChange={ ( checked ) =>
											handleItemSelect( item.id, checked )
										}
										__nextHasNoMarginBottom
									/>
								</td>
								<td className="quant-content-browser__col-title">
									{ specialType === 'redirects' ? (
										<code>{ item.url }</code>
									) : (
										<>
											<strong>
												{ item.title ||
													__(
														'(No title)',
														'quant'
													) }
											</strong>
											{ item.subtype && (
												<span className="quant-content-browser__subtype">
													{ item.subtype }
												</span>
											) }
										</>
									) }
								</td>
								<td className="quant-content-browser__col-url">
									<code>
										{ specialType === 'redirects'
											? item.destination
											: item.url }
									</code>
								</td>
								{ specialType === 'redirects' && (
									<td className="quant-content-browser__col-code">
										<span
											className={ `quant-redirect-code quant-redirect-code--${ item.code }` }
										>
											{ item.code }
										</span>
									</td>
								) }
								{ specialType === 'theme_assets' && (
									<td className="quant-content-browser__col-type">
										<span
											className={ `quant-asset-type quant-asset-type--${ item.file_type }` }
										>
											{ item.file_type }
										</span>
									</td>
								) }
								<td className="quant-content-browser__col-status">
									<StatusBadge status={ item.sync_status } />
								</td>
								{ ! specialType && (
									<td className="quant-content-browser__col-synced">
										{ item.last_synced_label ||
											formatDate( item.last_synced ) }
									</td>
								) }
								<td className="quant-content-browser__col-actions">
									<Button
										variant="link"
										size="small"
										href={ getCdnUrl( item ) }
										target="_blank"
										rel="noopener noreferrer"
									>
										{ __( 'View', 'quant' ) }
									</Button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			<div className="quant-content-browser__footer">
				<Pagination
					page={ page }
					pages={ totalPages }
					onPageChange={ handlePageChange }
				/>
			</div>
		</div>
	);
}
