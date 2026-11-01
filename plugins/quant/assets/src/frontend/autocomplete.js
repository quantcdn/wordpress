/**
 * Quant Search autocomplete box.
 *
 * Configuration comes from the data-quant-autocomplete attribute written by
 * Quant\Search\Frontend. Submitting the box goes to the search page with the
 * query in the `keys` parameter.
 */
import { autocomplete, getAlgoliaResults } from '@algolia/autocomplete-js';
import '@algolia/autocomplete-theme-classic/dist/theme.min.css';
import { createSearchClient } from './search-client';
import './search.scss';

/**
 * Only same-site paths and http(s) URLs are followed.
 *
 * @param {*} value A URL from a search record.
 * @return {string} The URL, or '' when it is not safe.
 */
function safeUrl( value ) {
	if (
		typeof value === 'string' &&
		( /^(https?:)?\/\//i.test( value ) || /^\/(?!\/)/.test( value ) )
	) {
		return value;
	}
	return '';
}

function mount( root ) {
	let config;
	try {
		config = JSON.parse( root.getAttribute( 'data-quant-autocomplete' ) );
	} catch ( e ) {
		return;
	}

	if ( ! config.index ) {
		return;
	}

	const searchClient = createSearchClient( config.index );
	const searchUrl = ( query ) =>
		`${ config.searchPath }?${
			config.queryParam || 'keys'
		}=${ encodeURIComponent( query ) }`;

	// Replace the no-JavaScript fallback form.
	root.innerHTML = '';

	autocomplete( {
		container: root,
		placeholder: config.placeholder || 'Search',
		detachedMediaQuery: 'none',
		navigator: {
			navigate( { itemUrl } ) {
				if ( itemUrl ) {
					window.location.assign( itemUrl );
				}
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
					getItemUrl: ( { item } ) => safeUrl( item.url ),
					getItems: ( { query } ) =>
						getAlgoliaResults( {
							searchClient,
							queries: [
								{
									indexName: config.index.index,
									query,
									// The query goes in both places. Algolia reads the
									// sibling; the Typesense adapter reads only
									// params.query and otherwise searches '*'.
									params: {
										query,
										filters: config.filters || '',
										hitsPerPage: 8,
									},
								},
							],
						} ),
					onSelect( { item } ) {
						const target = safeUrl( item.url );
						if ( target ) {
							window.location.assign( target );
						}
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
