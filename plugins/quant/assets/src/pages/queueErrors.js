/**
 * Queue failures as the admin shows them.
 *
 * Each failure from /quant/v1/queue/errors and the diagnostics endpoint has
 * a short `error_message` for editors and, for a transport or server
 * failure, the raw `error_detail` (for example "cURL error 28: ...").
 * The cards show only the message. The detail goes only into the
 * diagnostics report, for support.
 */

/**
 * The rows the queue card shows.
 *
 * @param {Array<Object>} errors Failures from the REST API.
 * @param {number}        limit  The most rows to show.
 * @return {Array<{type: string, objectId: string, message: string}>} Rows without the raw detail.
 */
export function visibleErrors( errors, limit = 5 ) {
	return ( errors || [] ).slice( 0, limit ).map( ( err ) => ( {
		type: err.type,
		objectId: err.object_id,
		message: err.error_message,
	} ) );
}

/**
 * The "Recent Errors" section of the diagnostics report.
 *
 * @param {Array<Object>} errors Failures from the diagnostics endpoint.
 * @return {Array<string>} Report lines; empty when there are no failures.
 */
export function errorReportLines( errors ) {
	if ( ! errors || errors.length === 0 ) {
		return [];
	}

	const lines = [ '## Recent Errors' ];
	errors.forEach( ( err, i ) => {
		lines.push(
			`${ i + 1 }. [${ err.type }:${ err.object_id }] ${
				err.error_message
			}`
		);
		if ( err.error_detail ) {
			lines.push( `   Detail: ${ err.error_detail }` );
		}
		lines.push( `   At: ${ err.processed_at }` );
	} );
	lines.push( '' );

	return lines;
}
