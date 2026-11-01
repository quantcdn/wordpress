const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );
const path = require( 'path' );

// Bundle the Babel regenerator runtime instead of reading
// window.regeneratorRuntime from WordPress. The search scripts also run on the
// static copy, and the Typesense adapter needs the runtime at load time.
const plugins = defaultConfig.plugins.map( ( plugin ) =>
	plugin instanceof DependencyExtractionWebpackPlugin
		? new DependencyExtractionWebpackPlugin( {
				requestToExternal: ( request ) =>
					request === '@babel/runtime/regenerator'
						? false
						: undefined,
		  } )
		: plugin
);

module.exports = {
	...defaultConfig,
	plugins,
	entry: {
		index: path.resolve( __dirname, 'assets/src/index.js' ),
		search: path.resolve( __dirname, 'assets/src/frontend/search.js' ),
		autocomplete: path.resolve(
			__dirname,
			'assets/src/frontend/autocomplete.js'
		),
		blocks: path.resolve( __dirname, 'assets/src/blocks/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'assets/build' ),
	},
};
