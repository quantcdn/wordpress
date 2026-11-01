/**
 * Search client factory shared by the search page and the autocomplete box.
 *
 * A project runs on one of two hosted search backends, resolved server-side
 * by Quant\Search\SearchBackend:
 *   - algolia:   the Algolia client with application_id and read_key.
 *   - typesense: the Typesense InstantSearch adapter against endpoint with
 *                read_key, collection index (QuantGov).
 * Both expose the same InstantSearch search client, so widgets do not care.
 * Filter strings already use the backend's syntax. Typesense needs the
 * project's custom ranking as sort_by on every query.
 */
import algoliasearch from 'algoliasearch/lite';
import TypesenseInstantSearchAdapter from 'typesense-instantsearch-adapter';

/**
 * Typesense node for an endpoint URL.
 *
 * @param {string} endpoint For example https://search.quantgov.cloud.
 * @return {Array<Object>} The nodes option.
 */
export function typesenseNodes( endpoint ) {
	const url = new window.URL( endpoint );
	const protocol = url.protocol.replace( ':', '' ) || 'https';
	const port = url.port
		? parseInt( url.port, 10 )
		: ( protocol === 'https' && 443 ) || 80;
	return [ { host: url.hostname, port, protocol } ];
}

/**
 * InstantSearch-compatible search client for the configured backend.
 *
 * @param {Object} index The index config from the data attribute.
 * @return {Object} A search client.
 */
export function createSearchClient( index ) {
	if ( index.backend !== 'typesense' ) {
		return algoliasearch( index.application_id, index.read_key );
	}

	const additionalSearchParameters = {
		query_by: index.query_by || 'title,content,summary',
	};
	if ( index.sort_by ) {
		additionalSearchParameters.sort_by = index.sort_by;
	}

	return new TypesenseInstantSearchAdapter( {
		server: {
			apiKey: index.read_key,
			nodes: typesenseNodes( index.endpoint ),
		},
		additionalSearchParameters,
	} ).searchClient;
}
