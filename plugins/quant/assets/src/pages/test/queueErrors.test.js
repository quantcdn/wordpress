import { visibleErrors, errorReportLines } from '../queueErrors';

const timeout = {
	type: 'post',
	object_id: '12',
	error_message:
		'API error publishing /about/: Quant did not answer in time. The plugin will try again.',
	error_detail:
		'http_request_failed: cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received (3 attempts)',
	processed_at: '2026-11-15 01:02:03',
};

const notFound = {
	type: 'route',
	object_id: '/gone',
	error_message: 'HTTP 404 response for /gone',
	error_detail: null,
	processed_at: '2026-11-15 01:00:00',
};

describe( 'visibleErrors', () => {
	it( 'shows the short message and never the raw detail', () => {
		const rows = visibleErrors( [ timeout ] );

		expect( rows ).toEqual( [
			{
				type: 'post',
				objectId: '12',
				message:
					'API error publishing /about/: Quant did not answer in time. The plugin will try again.',
			},
		] );
		expect( JSON.stringify( rows ) ).not.toContain( 'cURL' );
	} );

	it( 'limits the rows', () => {
		const many = Array.from( { length: 8 }, () => notFound );

		expect( visibleErrors( many ) ).toHaveLength( 5 );
		expect( visibleErrors( many, 2 ) ).toHaveLength( 2 );
	} );

	it( 'accepts a missing list', () => {
		expect( visibleErrors( undefined ) ).toEqual( [] );
	} );
} );

describe( 'errorReportLines', () => {
	it( 'keeps the raw detail in the diagnostics report', () => {
		expect( errorReportLines( [ timeout, notFound ] ) ).toEqual( [
			'## Recent Errors',
			'1. [post:12] API error publishing /about/: Quant did not answer in time. The plugin will try again.',
			'   Detail: http_request_failed: cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received (3 attempts)',
			'   At: 2026-11-15 01:02:03',
			'2. [route:/gone] HTTP 404 response for /gone',
			'   At: 2026-11-15 01:00:00',
			'',
		] );
	} );

	it( 'has no section when nothing failed', () => {
		expect( errorReportLines( [] ) ).toEqual( [] );
		expect( errorReportLines( undefined ) ).toEqual( [] );
	} );
} );
