/* global algoliasearch */
/**
 * Quant Search autocomplete box.
 *
 * Configuration comes from the data-quant-autocomplete attribute written by
 * Quant\Search\Frontend. Submitting the box goes to the search page with the
 * query in the `keys` parameter.
 */
import './search.scss';

function mount( root ) {
	let config;
	try {
		config = JSON.parse( root.getAttribute( 'data-quant-autocomplete' ) );
	} catch ( e ) {
		return;
	}

	const lib = window[ '@algolia/autocomplete-js' ];
	if ( ! config.index || ! lib || typeof algoliasearch === 'undefined' ) {
		return;
	}

	const { autocomplete, getAlgoliaResults } = lib;
	const searchClient = algoliasearch(
		config.index.application_id,
		config.index.read_key
	);
	const searchUrl = ( query ) =>
		`${ config.searchPath }?${
			config.queryParam || 'keys'
		}=${ encodeURIComponent( query ) }`;

	autocomplete( {
		container: root,
		placeholder: config.placeholder || 'Search',
		detachedMediaQuery: 'none',
		navigator: {
			navigate( { itemUrl } ) {
				window.location.assign( itemUrl );
			},
			navigateNewTab( { itemUrl } ) {
				const w = window.open( itemUrl, '_blank', 'noopener' );
				if ( w ) {
					w.focus();
				}
			},
			navigateNewWindow( { itemUrl } ) {
				window.open( itemUrl, '_blank', 'noopener' );
			},
		},
		onSubmit( { state } ) {
			window.location.href = searchUrl( state.query );
		},
		getSources() {
			return [
				{
					sourceId: config.index.index,
					getItemUrl: ( { item } ) => item.url,
					getItems: ( { query } ) =>
						getAlgoliaResults( {
							searchClient,
							queries: [
								{
									indexName: config.index.index,
									query,
									params: {
										filters: config.filters || '',
										hitsPerPage: 8,
									},
								},
							],
						} ),
					onSelect( { item } ) {
						window.location.assign( item.url );
					},
					templates: {
						item( { item, html } ) {
							return html`<div>
								<h6 class="quant-autocomplete__title">
									${ item.title }
								</h6>
								${ config.showSummary && item.summary
									? html`<p
											class="quant-autocomplete__summary"
									  >
											${ item.summary }
									  </p>`
									: '' }
							</div>`;
						},
						noResults() {
							return 'No results.';
						},
					},
				},
			];
		},
	} );
}

function init() {
	document.querySelectorAll( '[data-quant-autocomplete]' ).forEach( mount );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
