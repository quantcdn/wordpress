/**
 * Progress of one processing run, read from the server.
 *
 * WP-Cron, a system cron or WP-CLI can process the queue while the progress
 * card does, so the card cannot count the jobs that its own requests
 * processed. The server counts the run instead: the jobs that finished at or
 * after the start of the run (`since`, server time) and the jobs that are
 * still waiting. See QueueRun in the plugin.
 */

/**
 * Counts for the card from the `run` object of /queue/status.
 *
 * @param {?Object} run The `run` object (pending, processing, completed, failed).
 * @return {Object} completed, failed, remaining, total, percent and finished.
 */
export function summarizeRun( run ) {
	const completed = Number( run?.completed || 0 );
	const failed = Number( run?.failed || 0 );
	const remaining =
		Number( run?.pending || 0 ) + Number( run?.processing || 0 );
	const total = completed + failed + remaining;
	const percent =
		total === 0
			? 100
			: Math.round( ( ( completed + failed ) / total ) * 100 );

	return {
		completed,
		failed,
		remaining,
		total,
		percent,
		finished: !! run && remaining === 0,
		errors: Array.isArray( run?.errors ) ? run.errors : [],
	};
}

/**
 * One poll of the run: process a batch in this request (when autoProcess is
 * set), then read the run outcome from the server.
 *
 * The batch result is not counted. The server counts the run, so jobs that a
 * background run processed count the same as jobs this browser processed.
 *
 * @param {Function} fetch       apiFetch, or a stand-in in tests.
 * @param {number}   since       Start of the run (Unix time from the server).
 * @param {boolean}  autoProcess Process a batch before the status read.
 * @param {number}   batchSize   Jobs per batch.
 * @return {Promise<Object>} The /queue/status response and its summary.
 */
export async function pollRun( fetch, since, autoProcess, batchSize = 5 ) {
	if ( autoProcess ) {
		await fetch( {
			path: '/quant/v1/queue/process',
			method: 'POST',
			data: { limit: batchSize },
		} );
	}

	const response = await fetch( {
		path: `/quant/v1/queue/status?since=${ encodeURIComponent( since ) }`,
	} );

	return { response, summary: summarizeRun( response.run ) };
}
