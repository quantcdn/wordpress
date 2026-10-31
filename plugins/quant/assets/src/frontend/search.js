/* global instantsearch, algoliasearch */
/**
 * Quant Search page.
 *
 * Reads its configuration from the data-quant-search attribute written by
 * Quant\Search\Frontend and mounts InstantSearch.js widgets. The libraries
 * are loaded from jsDelivr by the plugin, so the static copy of the page
 * works without WordPress.
 */
import './search.scss';

const escapeHtml = ( value ) =>
	String( value ?? '' ).replace(
		/[&<>"']/g,
		( c ) =>
			( {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#39;',
			} )[ c ]
	);

function queryFromUrl( param ) {
	const match = new RegExp( '[?&]' + param + '=([^&]*)' ).exec(
		window.location.search
	);
	return match ? decodeURIComponent( match[ 1 ].replace( /\+/g, ' ' ) ) : '';
}

function hitTemplate( hit, { html, components } ) {
	const tags = Object.keys( hit )
		.filter(
			( key ) =>
				/^(category|post_tag)_[a-z]{2}$/.test( key ) &&
				Array.isArray( hit[ key ] )
		)
		.flatMap( ( key ) => hit[ key ] );

	return html`
		<article class="quant-search__hit">
			<a href="${ hit.url }">
				${ hit.image
					? html`<img
							src="${ hit.image }"
							alt="${ escapeHtml( hit.title ) }"
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
			return instantsearch.widgets.menu( options );
		case 'select':
			return instantsearch.widgets.menuSelect( options );
		default:
			return instantsearch.widgets.refinementList( options );
	}
}

function mount( root ) {
	let config;
	try {
		config = JSON.parse( root.getAttribute( 'data-quant-search' ) );
	} catch ( e ) {
		return;
	}

	if (
		! config.index ||
		typeof instantsearch === 'undefined' ||
		typeof algoliasearch === 'undefined'
	) {
		return;
	}

	const search = instantsearch( {
		indexName: config.index.index,
		searchClient: algoliasearch(
			config.index.application_id,
			config.index.read_key
		),
		routing: false,
		initialUiState: {
			[ config.index.index ]: {
				query: queryFromUrl( config.queryParam || 'keys' ),
			},
		},
	} );

	const widgets = [
		instantsearch.widgets.configure( {
			attributesToSnippet: [ 'summary:40' ],
			snippetEllipsisText: '…',
			filters: config.filters || '',
			hitsPerPage: ( config.display && config.display.per_page ) || 10,
		} ),
	];

	const add = ( selector, factory ) => {
		const container = root.querySelector( selector );
		if ( container ) {
			widgets.push( factory( container ) );
		}
	};

	add( '[data-quant-searchbox]', ( container ) =>
		instantsearch.widgets.searchBox( { container, placeholder: 'Search…' } )
	);
	add( '[data-quant-stats]', ( container ) =>
		instantsearch.widgets.stats( {
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
		instantsearch.widgets.clearRefinements( { container } )
	);
	add( '[data-quant-hits]', ( container ) =>
		instantsearch.widgets.hits( {
			container,
			templates: {
				empty: () =>
					'<div class="quant-search__empty"><h4>No results found.</h4><p>Try different search terms or filters.</p></div>',
				item: hitTemplate,
			},
		} )
	);
	add( '[data-quant-pagination]', ( container ) =>
		instantsearch.widgets.pagination( { container } )
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
