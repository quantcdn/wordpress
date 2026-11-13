import { indexMayStillChange, REFRESH_DELAYS } from '../indexRefresh';

describe( 'indexMayStillChange', () => {
	it( 'reads again while the engine has pending tasks', () => {
		expect(
			indexMayStillChange( 24, {
				stats: { entries: 30, numberOfPendingTasks: 2 },
			} )
		).toBe( true );
	} );

	it( 'reads again while the count is the count before the run', () => {
		expect(
			indexMayStillChange( 24, {
				stats: { entries: 24, numberOfPendingTasks: 0 },
			} )
		).toBe( true );
	} );

	it( 'stops when the count changed and nothing is pending', () => {
		expect(
			indexMayStillChange( 24, {
				stats: { entries: 41, numberOfPendingTasks: 0 },
			} )
		).toBe( false );
	} );

	it( 'reads again when the status has no stats', () => {
		expect( indexMayStillChange( 24, { configured: false } ) ).toBe( true );
	} );

	it( 'reads at 0, 3, 10 and 30 s (each delay follows the previous read)', () => {
		const at = REFRESH_DELAYS.reduce(
			( times, delay ) => [
				...times,
				( times.length ? times[ times.length - 1 ] : 0 ) + delay,
			],
			[]
		);
		expect( at ).toEqual( [ 0, 3000, 10000, 30000 ] );
	} );
} );
