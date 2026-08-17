// SPDX-License-Identifier: EUPL-1.2
//
// Build for the STANDALONE traffic script.
//
// Separate from webpack.site.js because its consumer is separate: a portal
// built by docusaurus-plugin-portaliq is static HTML on another host, and it
// loads this one file rather than the renderer. Same source, different
// delivery — which is the whole point of the split.
//
// The budget here is deliberately severe. This script runs on a government
// portal's every page, before anything a visitor came for, purely to measure.
// A measurement script that costs more than a few kilobytes has stopped being
// worth its own presence, and `hints: 'error'` is what keeps that a fact
// rather than an intention.

const path = require('path')

const isDev = process.env.NODE_ENV === 'development'

module.exports = {
	mode: isDev ? 'development' : 'production',
	devtool: isDev ? 'cheap-source-map' : 'source-map',
	entry: {
		'portaliq-traffic': path.join(__dirname, 'src', 'traffic', 'main.js'),
	},
	output: {
		path: path.join(__dirname, 'js'),
		filename: '[name].js',
		// FALSE, like every sibling config: three bundles share js/, and a
		// clean build here would delete the renderer and leave a page with a
		// 404 on its script and no console error.
		clean: false,
	},
	performance: {
		hints: isDev ? false : 'error',
		maxAssetSize: 8 * 1024,
		maxEntrypointSize: 8 * 1024,
	},
}
