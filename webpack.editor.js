// SPDX-License-Identifier: EUPL-1.2
//
// Build for the PAGE EDITOR.
//
// Standalone like the site bundle, and for the same reason: it renders in a
// document this app owns end to end (`RENDER_AS_BLANK`), so it cannot inherit
// @nextcloud/webpack-vue-config's assumptions about Nextcloud globals.
//
// THE BUDGET IS DELIBERATELY LOOSER THAN THE SITE'S. The site's 400 KiB is a
// public, first-visit, mobile-visited surface; this one is behind a login and
// opened on purpose by somebody about to spend an hour in it. It still HAS a
// budget, because an editor that takes ten seconds to open is an editor people
// avoid — and because a budget nobody set is a budget nobody notices growing.

const path = require('path')
const { VueLoaderPlugin } = require('vue-loader')
const webpack = require('webpack')

const isDev = process.env.NODE_ENV === 'development'

module.exports = {
	mode: isDev ? 'development' : 'production',
	devtool: isDev ? 'cheap-source-map' : 'source-map',
	entry: {
		'portaliq-editor': path.join(__dirname, 'src', 'editor', 'main.js'),
	},
	output: {
		path: path.join(__dirname, 'js'),
		filename: '[name].js',
		// FALSE, like every sibling config: four bundles share js/, and a clean
		// build here would delete the public renderer and leave a page with a
		// 404 on its script and no console error.
		clean: false,
	},
	resolve: {
		extensions: ['.js', '.vue'],
	},
	module: {
		rules: [
			{
				test: /\.vue$/,
				loader: 'vue-loader',
			},
			// THE SAME EXCLUSION THE SITE BUILD MAKES. The editor's document
			// links the design system's stylesheets, so bundling the component
			// library's dist CSS would ship it twice — and, worse here, would
			// inject it at runtime into a page that already has it, where the
			// injected copy wins on order and the canvas stops matching the
			// public route.
			{
				test: /\.css$/,
				include: /node_modules[\\/]@conduction[\\/]nextcloud-vue/,
				resourceQuery: { not: [/vue/] },
				use: [path.resolve(__dirname, 'build', 'empty-css-loader.js')],
			},
			{
				test: /\.css$/,
				use: ['style-loader', 'css-loader'],
			},
			{
				test: /\.(png|jpe?g|gif|svg|woff2?)$/,
				type: 'asset/inline',
			},
		],
	},
	plugins: [
		new VueLoaderPlugin(),
		new webpack.DefinePlugin({
			__VUE_OPTIONS_API__: JSON.stringify(true),
			__VUE_PROD_DEVTOOLS__: JSON.stringify(false),
			__VUE_PROD_HYDRATION_MISMATCH_DETAILS__: JSON.stringify(false),
		}),
	],
	performance: {
		hints: isDev ? false : 'error',
		maxAssetSize: 700 * 1024,
		maxEntrypointSize: 700 * 1024,
	},
}
