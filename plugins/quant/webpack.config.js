const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
    ...defaultConfig,
    entry: {
        index: path.resolve(__dirname, 'assets/src/index.js'),
        search: path.resolve(__dirname, 'assets/src/frontend/search.js'),
        autocomplete: path.resolve(__dirname, 'assets/src/frontend/autocomplete.js'),
        blocks: path.resolve(__dirname, 'assets/src/blocks/index.js'),
    },
    output: {
        ...defaultConfig.output,
        path: path.resolve(__dirname, 'assets/build'),
    },
};
