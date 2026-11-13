import {
	isTransientError,
	retryDelay,
	shouldRetry,
	MAX_RETRIES,
} from '../retry';

describe( 'isTransientError', () => {
	it( 'retries server errors and rate limits', () => {
		expect( isTransientError( { data: { status: 500 } } ) ).toBe( true );
		expect( isTransientError( { data: { status: 502 } } ) ).toBe( true );
		expect( isTransientError( { data: { status: 503 } } ) ).toBe( true );
		expect( isTransientError( { data: { status: 429 } } ) ).toBe( true );
	} );

	it( 'retries a dropped connection and a non-JSON error page', () => {
		expect( isTransientError( { code: 'fetch_error' } ) ).toBe( true );
		expect( isTransientError( { code: 'invalid_json' } ) ).toBe( true );
	} );

	it( 'does not retry client errors', () => {
		expect(
			isTransientError( {
				code: 'rest_forbidden',
				data: { status: 403 },
			} )
		).toBe( false );
		expect(
			isTransientError( {
				code: 'rest_cookie_invalid_nonce',
				data: { status: 403 },
			} )
		).toBe( false );
		expect( isTransientError( { data: { status: 400 } } ) ).toBe( false );
		expect( isTransientError( null ) ).toBe( false );
	} );
} );

describe( 'retryDelay', () => {
	it( 'doubles the delay up to a cap', () => {
		expect( retryDelay( 1 ) ).toBe( 2000 );
		expect( retryDelay( 2 ) ).toBe( 4000 );
		expect( retryDelay( 3 ) ).toBe( 8000 );
		expect( retryDelay( 4 ) ).toBe( 15000 );
		expect( retryDelay( 10 ) ).toBe( 15000 );
	} );
} );

describe( 'shouldRetry', () => {
	const busy = { data: { status: 503 } };

	it( 'retries a transient error until the limit', () => {
		expect( shouldRetry( busy, 1 ) ).toBe( true );
		expect( shouldRetry( busy, MAX_RETRIES ) ).toBe( true );
		expect( shouldRetry( busy, MAX_RETRIES + 1 ) ).toBe( false );
	} );

	it( 'never retries a permanent error', () => {
		expect( shouldRetry( { data: { status: 401 } }, 1 ) ).toBe( false );
	} );
} );
