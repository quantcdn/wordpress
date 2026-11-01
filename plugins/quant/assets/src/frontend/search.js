/**
 * Quant Search page.
 *
 * Reads its configuration from the data-quant-search attribute written by
 * Quant\Search\Frontend and mounts InstantSearch.js widgets. The libraries
 * are bundled with the plugin, so the static copy of the page works without
 * WordPress. The search client is Algolia or Typesense (search-client.js).
 */
import instantsearch from 'instantsearch.js';
import * as isWidgets from 'instantsearch.js/es/widgets';
import { createSearchClient } from './search-client';
import 'instantsearch.css/themes/algolia-min.css';
import './search.scss';

/**
 * The value of a query parameter, or '' when it is missing or malformed.
 *
 * @param {string} param Parameter name.
 * @return {string} The decoded value.
 */
function queryFromUrl( param ) {
	try {
		return (
			new window.URLSearchParams( window.location.search ).get( param ) ||
			''
		);
	} catch ( e ) {
		return '';
	}
}

/**
 * Only same-site paths and http(s) URLs are rendered as links or images.
 *
 * @param {*} value A URL from a search record.
 * @return {string|undefined} The URL, or undefined to omit the attribute.
 */
function safeUrl( value ) {
	if ( typeof value !== 'string' ) {
		return undefined;
	}
	if ( /^(https?:)?\/\//i.test( value ) || /^\/(?!\/)/.test( value ) ) {
		return value;
	}
	return undefined;
}

function hitTemplate( hit, { html, components } ) {
	const tags = Object.keys( hit )
		.filter(
			( key ) =>
				/^(category|post_tag)_[a-z]{2}$/.test( key ) &&
				Array.isArray( hit[ key ] )
		)
		.flatMap( ( key ) => hit[ key ] );
	const url = safeUrl( hit.url );
	const image = safeUrl( hit.image );

	return html`
		<article class="quant-search__hit">
			<a href=${ url }>
				${ image
					? html`<img
							src=${ image }
							alt=${ String( hit.title ?? '' ) }
							loading="lazy"
					  />`
					: '' }
				<h3 class="quant-search__hit-title">
					${ components.Highlight( { hit, attribute: 'title' } ) }
				</h3>
			</a>
			<p class="quant-search__hit-summary">
				${ components.Snippet( { hit, attribute: 'summary' } ) }
			</p>
			<div>
				${ tags.map(
					( tag ) =>
						html`<span class="quant-search__hit-tag"
							>${ tag }</span
						>`
				) }
			</div>
		</article>
	`;
}

function facetWidget( root, facet ) {
	const container = root.querySelector( '#' + facet.container );
	if ( ! container ) {
		return null;
	}

	const options = {
		container,
		attribute: facet.attribute,
		limit: facet.limit || 10,
	};

	switch ( facet.display ) {
		case 'menu':
			return isWidgets.menu( options );
		case 'select':
			return isWidgets.menuSelect( options );
		default:
			return isWidgets.refinementList( options );
	}
}

function mount( root ) {
	let config;
	try {
		config = JSON.parse( root.getAttribute( 'data-quant-search' ) );
	} catch ( e ) {
		return;
	}

	if ( ! config.index ) {
		return;
	}

	const search = instantsearch( {
		indexName: config.index.index,
		searchClient: createSearchClient( config.index ),
		routing: false,
		initialUiState: {
			[ config.index.index ]: {
				query: queryFromUrl( config.queryParam || 'keys' ),
			},
		},
	} );

	const widgets = [
		isWidgets.configure( {
			attributesToSnippet: [ 'summary:40' ],
			snippetEllipsisText: '…',
			filters: config.filters || '',
			hitsPerPage: ( config.display && config.display.per_page ) || 10,
		} ),
	];

	const add = ( selector, factory ) => {
		const container = root.querySelector( selector );
		if ( container ) {
			// Replace the no-JavaScript fallback form.
			container.innerHTML = '';
			widgets.push( factory( container ) );
		}
	};

	add( '[data-quant-searchbox]', ( container ) =>
		isWidgets.searchBox( { container, placeholder: 'Search…' } )
	);
	add( '[data-quant-stats]', ( container ) =>
		isWidgets.stats( {
			container,
			templates: {
				text: ( { nbHits, hasOneResult } ) =>
					hasOneResult
						? '1 result'
						: `${ nbHits.toLocaleString() } results`,
			},
		} )
	);
	add( '[data-quant-clear]', ( container ) =>
		isWidgets.clearRefinements( { container } )
	);
	add( '[data-quant-hits]', ( container ) =>
		isWidgets.hits( {
			container,
			templates: {
				empty: () =>
					'<div class="quant-search__empty"><h4>No results found.</h4><p>Try different search terms or filters.</p></div>',
				item: hitTemplate,
			},
		} )
	);
	add( '[data-quant-pagination]', ( container ) =>
		isWidgets.pagination( { container } )
	);

	( config.facets || [] ).forEach( ( facet ) => {
		const widget = facetWidget( root, facet );
		if ( widget ) {
			widgets.push( widget );
		}
	} );

	search.addWidgets( widgets );
	search.start();
}

function init() {
	document.querySelectorAll( '[data-quant-search]' ).forEach( mount );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
