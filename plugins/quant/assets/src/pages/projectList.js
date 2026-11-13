/**
 * What the project picker shows.
 *
 * The warning "No organizations or projects" is a result, so it shows only
 * after a request for the list has ended. Before the first request ends (and
 * while any request runs) the picker shows a spinner.
 *
 * @param {Object}  list         The project list state.
 * @param {boolean} list.loading A request for the list runs.
 * @param {boolean} list.loaded  At least one request has ended.
 * @param {?string} list.error   The error of the last request.
 * @param {number}  list.count   Organizations in the list.
 * @return {string} 'loading', 'empty' (warning) or 'list'.
 */
export function projectListView( { loading, loaded, error, count } ) {
	if ( loading || ! loaded ) {
		return 'loading';
	}
	return error || count === 0 ? 'empty' : 'list';
}
