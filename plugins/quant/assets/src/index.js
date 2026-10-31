import { createRoot } from '@wordpress/element';
import { App } from './App';
import './index.scss';

const container = document.getElementById( 'quant-admin-root' );

if ( container ) {
	const root = createRoot( container );
	root.render( <App /> );
}
