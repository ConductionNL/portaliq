// SPDX-License-Identifier: EUPL-1.2
//
// Build for the built-in SITE renderer — the Vue replacement for the React
// portal (ADR-084).
//
// Standalone on purpose, the same way webpack.portal.js was: this bundle must
// boot at a PUBLIC origin, so it cannot inherit @nextcloud/webpack-vue-config's
// assumptions about Nextcloud globals and asset paths.
//
// `output.clean` is FALSE here, and that is load-bearing while three bundles
// share js/. webpack.config.js documents what happens otherwise: an
// admin-only rebuild wipes the sibling bundle and the page then serves a bare
// <div> with a 404 on its script and NO console error. Once the React portal
// is retired and there are two configs instead of three, that hazard is worth
// removing rather than guarding.

const path = require('path')
const { VueLoaderPlugin } = require('vue-loader')
const webpack = require('webpack')

const isDev = process.env.NODE_ENV === 'development'

module.exports = {
	mode: isDev ? 'development' : 'production',
	devtool: isDev ? 'cheap-source-map' : 'source-map',
	entry: {
		'portaliq-site': path.join(__dirname, 'src', 'site', 'main.js'),
	},
	output: {
		path: path.join(__dirname, 'js'),
		filename: '[name].js',
		clean: false,
	},
	resolve: {
		extensions: ['.js', '.vue'],
		alias: {
			'@site': path.resolve(__dirname, 'src', 'site'),
		},
	},
	module: {
		rules: [
			{
				test: /\.vue$/,
				loader: 'vue-loader',
			},
			// THE COMPONENT LIBRARY'S DIST CSS IS ALREADY ON THE PAGE.
			//
			// `templates/site.php` links the vendored NL Design System
			// stylesheets, and `@conduction/nextcloud-vue` ships the same
			// `ac-*` / `utrecht-*` rules in its own dist CSS — so bundling
			// them sent the design system to every visitor twice.
			//
			// MEASURED before removing anything: 275 KiB of CSS was injected
			// from the bundle at runtime, and deleting every injected
			// `<style>` on a rendered portal changed exactly ONE computed
			// property across header, hero, cards, footer, navigation,
			// buttons and headings — the skip link's `position`, which is
			// why `@utrecht/skip-link-css` is still imported in `main.js`.
			//
			// The rule is narrow on purpose: `resourceQuery` excludes Vue SFC
			// styles, which arrive through this same `.css` rule with a
			// `?vue&type=style` query and are NOT redundant.
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
		// Vue 3 reads these at build time; without them the runtime logs a
		// warning on every boot about an undefined feature flag.
		new webpack.DefinePlugin({
			__VUE_OPTIONS_API__: JSON.stringify(true),
			__VUE_PROD_DEVTOOLS__: JSON.stringify(false),
			__VUE_PROD_HYDRATION_MISMATCH_DETAILS__: JSON.stringify(false),
		}),
	],
	performance: {
		// A public, first-visit, mobile-visited surface pays for every byte,
		// unlike an in-Nextcloud SPA behind a login. `hints: 'error'` makes
		// this a budget rather than a suggestion — a warning in a build log is
		// something nobody reads twice.
		hints: isDev ? false : 'error',
		maxAssetSize: 400 * 1024,
		maxEntrypointSize: 400 * 1024,
	},
}
