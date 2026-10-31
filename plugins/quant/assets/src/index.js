import * as element from '@wordpress/element';
import { App } from './App';
import './index.scss';

const container = document.getElementById( 'quant-admin-root' );

if ( container ) {
	// WordPress 6.2+ ships React 18 (createRoot); 6.0 and 6.1 ship React 17 (render).
	if ( typeof element.createRoot === 'function' ) {
		element.createRoot( container ).render( <App /> );
	} else {
		element.render( <App />, container );
	}
}
