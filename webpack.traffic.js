// SPDX-License-Identifier: EUPL-1.2
//
// Build for the TRAFFIC CLIENT (portal-traffic-analytics): the script every
// renderer loads through /api/traffic-client.js, whether the built-in site
// or a Docusaurus build on its own domain.
//
// Standalone, like webpack.site.js, and smaller than any of them on purpose:
// no Vue, no loaders, no polyfills. The source is ES2017 and ships as-is,
// minified into one IIFE. A tracking script is the one asset a public
// portal loads on EVERY page for EVERY visitor, so its size is a budget
// (16 KB, `hints: 'error'`) rather than a suggestion.
//
// `output.clean` is FALSE here, and that is load-bearing while four bundles
// share js/. webpack.config.js documents what happens otherwise: a rebuild of
// one entry wipes its siblings, and the page then serves a bare <div> with a
// 404 on its script and NO console error.

const path = require('path')

const isDev = process.env.NODE_ENV === 'development'

module.exports = {
	mode: isDev ? 'development' : 'production',
	devtool: isDev ? 'cheap-source-map' : false,
	target: ['web', 'es2017'],
	entry: {
		'portaliq-traffic': path.join(__dirname, 'src', 'traffic', 'client.js'),
	},
	output: {
		path: path.join(__dirname, 'js'),
		filename: '[name].js',
		clean: false,
		iife: true,
	},
	performance: {
		hints: isDev ? false : 'error',
		maxAssetSize: 16 * 1024,
		maxEntrypointSize: 16 * 1024,
	},
}
