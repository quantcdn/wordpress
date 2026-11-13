import { projectListView } from '../projectList';

describe( 'projectListView', () => {
	it( 'shows a spinner before the first request ends', () => {
		expect(
			projectListView( {
				loading: false,
				loaded: false,
				error: null,
				count: 0,
			} )
		).toBe( 'loading' );
	} );

	it( 'shows a spinner while a request runs', () => {
		expect(
			projectListView( {
				loading: true,
				loaded: true,
				error: null,
				count: 0,
			} )
		).toBe( 'loading' );
	} );

	it( 'warns only after a request returned no organizations', () => {
		expect(
			projectListView( {
				loading: false,
				loaded: true,
				error: null,
				count: 0,
			} )
		).toBe( 'empty' );
		expect(
			projectListView( {
				loading: false,
				loaded: true,
				error: 'Failed',
				count: 2,
			} )
		).toBe( 'empty' );
	} );

	it( 'shows the list when organizations loaded', () => {
		expect(
			projectListView( {
				loading: false,
				loaded: true,
				error: null,
				count: 1,
			} )
		).toBe( 'list' );
	} );
} );
