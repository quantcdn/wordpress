import { indexMayStillChange, REFRESH_DELAYS } from '../indexRefresh';

describe( 'indexMayStillChange', () => {
	it( 'reads again while the engine has pending tasks', () => {
		expect(
			indexMayStillChange( 24, 24, {
				stats: { entries: 30, numberOfPendingTasks: 2 },
			} )
		).toBe( true );
	} );

	it( 'reads again while the count is the count before the run', () => {
		expect(
			indexMayStillChange( 24, 24, {
				stats: { entries: 24, numberOfPendingTasks: 0 },
			} )
		).toBe( true );
	} );

	it( 'reads again while the count still changes between reads', () => {
		// Typesense reports no pending tasks; 24 -> 30 -> 41 is still moving.
		expect(
			indexMayStillChange( 24, 30, {
				stats: { entries: 41, numberOfPendingTasks: 0 },
			} )
		).toBe( true );
	} );

	it( 'stops when the count is the same at two reads after it changed', () => {
		expect(
			indexMayStillChange( 24, 41, {
				stats: { entries: 41, numberOfPendingTasks: 0 },
			} )
		).toBe( false );
	} );

	it( 'reads once more after the first change, to see that the count holds', () => {
		expect(
			indexMayStillChange( 24, 24, {
				stats: { entries: 41, numberOfPendingTasks: 0 },
			} )
		).toBe( true );
	} );

	it( 'reads again when the status has no stats', () => {
		expect( indexMayStillChange( 24, 24, { configured: false } ) ).toBe(
			true
		);
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
