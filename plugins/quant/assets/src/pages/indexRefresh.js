/**
 * When to read the search status again after a Rebuild.
 *
 * The run ends when the WordPress queue is empty, but the search engine
 * counts new records only after it has indexed them (Algolia reports
 * numberOfPendingTasks until then). So one status read at the end of the run
 * can still show the old count. The page reads the status again, and stops
 * early when the count has changed and no indexing task is pending.
 *
 * Each delay is the wait after the previous read, so the reads are at about
 * 0, 3, 10 and 30 s.
 */
export const REFRESH_DELAYS = [ 0, 3000, 7000, 20000 ];

/**
 * Whether the index status can still change.
 *
 * @param {?number} before  Records before the run (null if unknown).
 * @param {?Object} current The status just read.
 * @return {boolean} True to read the status again.
 */
export function indexMayStillChange( before, current ) {
	const stats = current?.stats;
	if ( ! stats ) {
		return true;
	}
	if ( Number( stats.numberOfPendingTasks || 0 ) > 0 ) {
		return true;
	}
	return before === null || stats.entries === before;
}
