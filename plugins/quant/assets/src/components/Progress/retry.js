/**
 * Retry rules for the queue poll.
 *
 * One failed request (a 5xx from a busy server or proxy, a dropped connection)
 * must not stop the whole run. The poll retries such failures with a growing
 * delay, and stops only after MAX_RETRIES failures in a row. Errors that will
 * not go away on their own (401, 403, 404, a bad request) stop the run at once.
 */

export const MAX_RETRIES = 4;

const BASE_DELAY = 2000;
const MAX_DELAY = 15000;

// apiFetch error codes for a response that never arrived or was not JSON
// (a proxy error page, a PHP fatal error, a timeout).
const TRANSIENT_CODES = [
	'fetch_error',
	'invalid_json',
	'internal_server_error',
];

/**
 * Whether an apiFetch error is worth a retry.
 *
 * @param {Object} err The error that apiFetch threw.
 * @return {boolean} True for 5xx, 429 and network failures.
 */
export function isTransientError( err ) {
	if ( ! err || typeof err !== 'object' ) {
		return false;
	}
	const status = Number( err.data?.status || err.status || 0 );
	if ( status === 429 || status >= 500 ) {
		return true;
	}
	return TRANSIENT_CODES.includes( err.code );
}

/**
 * Delay before retry number `attempt` (1 for the first retry).
 *
 * @param {number} attempt The retry number, from 1.
 * @return {number} Delay in ms: 2 s, 4 s, 8 s, then 15 s at most.
 */
export function retryDelay( attempt ) {
	return Math.min( MAX_DELAY, BASE_DELAY * 2 ** Math.max( 0, attempt - 1 ) );
}

/**
 * Whether to retry after `failures` failures in a row.
 *
 * @param {Object} err      The error that apiFetch threw.
 * @param {number} failures Failures in a row, this one included.
 * @return {boolean} True when the poll should try again.
 */
export function shouldRetry( err, failures ) {
	return failures <= MAX_RETRIES && isTransientError( err );
}
