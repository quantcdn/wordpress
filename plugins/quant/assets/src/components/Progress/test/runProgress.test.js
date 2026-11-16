import { pollRun, summarizeRun } from '../runProgress';

/**
 * A stand-in for the plugin's queue and its REST endpoints.
 *
 * Rows hold a status and the time they were processed, like the
 * quant_sync_queue table. `/queue/status?since=` counts the run the way
 * DatabaseQueue::runStatus does: finished rows processed at or after `since`,
 * and every pending or processing row.
 *
 * @param {Object} options
 * @param {number} options.jobs  Jobs queued for the run.
 * @param {Array}  options.fail  Indexes of jobs that fail.
 * @param {number} options.old   Completed rows from earlier runs.
 * @param {number} options.since Start of the run.
 */
function fakeSite( { jobs, fail = [], old = 0, since = 1000 } ) {
	let clock = since;
	const rows = [];
	for ( let i = 0; i < old; i++ ) {
		rows.push( { status: 'completed', processedAt: since - 60 } );
	}
	for ( let i = 0; i < jobs; i++ ) {
		rows.push( {
			status: 'pending',
			processedAt: null,
			fails: fail.includes( i ),
			index: i,
		} );
	}

	const processBatch = ( limit ) => {
		const batch = rows
			.filter( ( row ) => row.status === 'pending' )
			.slice( 0, limit );
		let failed = 0;
		for ( const row of batch ) {
			row.processedAt = clock;
			row.status = row.fails ? 'failed' : 'completed';
			row.error = row.fails ? `Failed job ${ row.index }` : null;
			failed += row.fails ? 1 : 0;
		}
		return { processed: batch.length - failed, failed };
	};

	const status = ( runSince ) => {
		const count = ( st, finished ) =>
			rows.filter(
				( row ) =>
					row.status === st &&
					( ! finished || row.processedAt >= runSince )
			).length;
		return {
			pending: count( 'pending', false ),
			processing: count( 'processing', false ),
			completed: count( 'completed', true ),
			failed: count( 'failed', true ),
			errors: rows
				.filter(
					( row ) =>
						row.status === 'failed' && row.processedAt >= runSince
				)
				.map( ( row ) => row.error ),
		};
	};

	const site = {
		browserLimit: null,
		browserProcessed: 0,
		// A background run (WP-Cron, a system cron, WP-CLI) between two polls.
		background: ( limit ) => processBatch( limit ),
		fetch: async ( { path, data } ) => {
			clock += 1;
			if ( path === '/quant/v1/queue/process' ) {
				const result = processBatch(
					site.browserLimit === null ? data.limit : site.browserLimit
				);
				site.browserProcessed += result.processed;
				return { success: true, ...result };
			}
			const match = /since=(\d+)/.exec( path );
			return {
				now: clock,
				run: match ? status( Number( match[ 1 ] ) ) : undefined,
			};
		},
	};
	return site;
}

/**
 * Poll like the progress card until the run is finished.
 *
 * @param {Object}   site         The fake site.
 * @param {Function} betweenPolls Called before each poll (background work).
 * @return {Promise<Object>} The last summary.
 */
async function runToEnd( site, betweenPolls = () => {} ) {
	for ( let poll = 0; poll < 200; poll++ ) {
		betweenPolls();
		const { summary } = await pollRun( site.fetch, 1000, true );
		if ( summary.finished ) {
			return summary;
		}
	}
	throw new Error( 'The run did not finish' );
}

describe( 'pollRun', () => {
	it( 'counts a run that only this browser processed', async () => {
		const site = fakeSite( { jobs: 23, old: 40 } );

		const summary = await runToEnd( site );

		expect( summary ).toMatchObject( {
			completed: 23,
			failed: 0,
			remaining: 0,
			total: 23,
			percent: 100,
		} );
	} );

	it( 'counts a run that only a background run processed', async () => {
		// WP-Cron claims every job first, so the browser batches find nothing.
		const site = fakeSite( { jobs: 186, old: 3 } );
		site.browserLimit = 0;

		const summary = await runToEnd( site, () => site.background( 25 ) );

		expect( site.browserProcessed ).toBe( 0 );
		expect( summary ).toMatchObject( {
			completed: 186,
			failed: 0,
			total: 186,
		} );
	} );

	it( 'counts a run that the browser and a background run shared', async () => {
		const site = fakeSite( { jobs: 189 } );

		const summary = await runToEnd( site, () => site.background( 7 ) );

		// The browser did only part of the work, but the card counts all of it.
		expect( site.browserProcessed ).toBeGreaterThan( 0 );
		expect( site.browserProcessed ).toBeLessThan( 189 );
		expect( summary.completed ).toBe( 189 );
		expect( summary.total ).toBe( 189 );
	} );

	it( 'counts failures, whichever run processed them', async () => {
		// Job 0 fails in the background run, job 12 in a browser batch.
		const site = fakeSite( { jobs: 20, fail: [ 0, 12 ] } );
		let first = true;

		const summary = await runToEnd( site, () => {
			if ( first ) {
				site.background( 3 );
				first = false;
			}
		} );

		expect( summary.completed ).toBe( 18 );
		expect( summary.failed ).toBe( 2 );
		expect( summary.errors ).toEqual(
			expect.arrayContaining( [ 'Failed job 0', 'Failed job 12' ] )
		);
	} );

	it( 'is not finished while a background run holds jobs', async () => {
		const fetch = async ( { path } ) =>
			path === '/quant/v1/queue/process'
				? { processed: 0, failed: 0 }
				: {
						run: {
							pending: 0,
							processing: 4,
							completed: 10,
							failed: 0,
						},
				  };

		const { summary } = await pollRun( fetch, 1000, true );

		expect( summary.finished ).toBe( false );
		expect( summary.remaining ).toBe( 4 );
		expect( summary.percent ).toBe( 71 );
	} );

	it( 'asks for the run that started at `since` and only watches without autoProcess', async () => {
		const calls = [];
		const fetch = async ( request ) => {
			calls.push( request.path );
			return { run: { pending: 0, processing: 0 } };
		};

		await pollRun( fetch, 1763251200, false );

		expect( calls ).toEqual( [
			'/quant/v1/queue/status?since=1763251200',
		] );
	} );
} );

describe( 'summarizeRun', () => {
	it( 'is not finished before the first status read', () => {
		expect( summarizeRun( null ).finished ).toBe( false );
	} );

	it( 'is 100 % for a run with no jobs', () => {
		expect(
			summarizeRun( {
				pending: 0,
				processing: 0,
				completed: 0,
				failed: 0,
			} )
		).toMatchObject( { finished: true, percent: 100, total: 0 } );
	} );
} );
