// SPDX-License-Identifier: EUPL-1.2
const path = require('path')
const fs = require('fs')
const webpack = require('webpack')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const { VueLoaderPlugin } = require('vue-loader')
const NodePolyfillPlugin = require('node-polyfill-webpack-plugin')

const buildMode = process.env.NODE_ENV
const isDev = buildMode === 'development'
webpackConfig.devtool = isDev ? 'cheap-source-map' : 'source-map'

webpackConfig.stats = {
	colors: true,
	modules: false,
}

const appId = 'portaliq'

// Each Nextcloud Dashboard widget needs its own webpack entry-point so the
// widget's JS can be attached via `Util::addScript()` from PHP. Add a new
// line here for every widget you create alongside `lib/Dashboard/<Foo>Widget.php`.
webpackConfig.entry = {
	main: {
		import: path.join(__dirname, 'src', 'main.js'),
		filename: appId + '-main.js',
	},
	adminSettings: {
		import: path.join(__dirname, 'src', 'settings.js'),
		filename: appId + '-settings.js',
	},
}

// Use local source when explicitly opted in, otherwise the npm package.
//
// USE_LOCAL_LIB is opt-IN (ADR-090): building against a developer's working
// checkout is the wrong default for a build that can ship. This app previously
// had NO version check at all, so an unset variable silently built from whatever
// sibling happened to be on disk.
//
// The sibling must satisfy this app's own declared range. It is 2.0.5 today
// against a declared 2.2.0-vue3.16 — a Vue 3 library, but not the version this
// app asked for. That skew breaks the build in a non-obvious way: building from
// the sibling's SOURCE also resolves packages out of the SIBLING's node_modules,
// where a stale vue-demi shim (postinstall picks v2/v2.7/v3 and does not re-run
// on `npm install`) yields
//   export 'default' (imported as 'Vue') was not found in 'vue'
//
// Fail CLOSED: if the check cannot run, the sibling is refused.
const localLib = path.resolve(__dirname, '../nextcloud-vue/src')
const localLibPkg = path.resolve(__dirname, '../nextcloud-vue/package.json')
let useLocalLib = process.env.USE_LOCAL_LIB === 'true' && fs.existsSync(localLib)
if (useLocalLib) {
	let localVersion = 'unreadable'
	let satisfied = false
	try {
		// eslint-disable-next-line n/no-extraneous-require
		const semver = require('semver')
		const required =
			require('./package.json').dependencies['@conduction/nextcloud-vue']
		localVersion = String(
			JSON.parse(fs.readFileSync(localLibPkg, 'utf8')).version || '',
		)
		satisfied = semver.satisfies(localVersion, required, {
			includePrerelease: true,
		})
	} catch (e) {
		satisfied = false
	}

	if (!satisfied) {
		// eslint-disable-next-line no-console
		console.warn(
			`[portaliq] IGNORING sibling @conduction/nextcloud-vue@${localVersion} — `
				+ "it does not satisfy this app's declared range. Building against the npm dist.",
		)
		useLocalLib = false
	}
}

// Extend the base resolve config (preserves defaults from @nextcloud/webpack-vue-config)
webpackConfig.resolve = webpackConfig.resolve || {}
webpackConfig.resolve.modules = [
	path.resolve(__dirname, 'node_modules'),
	'node_modules',
]
// nc-vue's chunked ESM bundles @nextcloud/dialogs chunks that import Node's
// `path`; webpack 5 ships no core-module polyfills, so a clean `npm ci` +
// build fails with "Can't resolve 'path'" without this fallback.
webpackConfig.resolve.fallback = {
	...(webpackConfig.resolve.fallback || {}),
	path: require.resolve('path-browserify'),
}
webpackConfig.resolve.alias = {
	...(webpackConfig.resolve.alias || {}),
	'@': path.resolve(__dirname, 'src'),
	...(useLocalLib ? { '@conduction/nextcloud-vue': localLib } : {}),
	// Deduplicate shared packages to ONE ABSOLUTE FILE each, so the app and
	// every library that imports them share a single module instance. A
	// per-importer resolve gives two copies, and for these packages two
	// copies means two disjoint runtime states.
	//
	// PURE VUE 3: the source is compat-construct-free, so this is the REAL
	// Vue 3 runtime, not @vue/compat. Two Vue copies = two
	// `currentRenderingInstance` states, which crashes CnAppRoot with a null
	// instance.
	vue$: path.resolve(
		__dirname,
		'node_modules/vue/dist/vue.runtime.esm-bundler.js',
	),
	pinia$: path.resolve(__dirname, 'node_modules/pinia'),
	// @nextcloud/vue@9 declares `vue-router` as a hard DEPENDENCY (^5.1.0),
	// so npm installs a second copy under
	// node_modules/@nextcloud/vue/node_modules/vue-router. Without this
	// alias, NcAppNavigationItem's <router-link> resolves the v5 injection
	// key while `app.use(router)` provided the v4 one — the link renders
	// against an undefined router and throws.
	'vue-router$': path.resolve(
		__dirname,
		'node_modules/vue-router/dist/vue-router.mjs',
	),
	// v9 is ESM-only: its exports map has '.' -> ./dist/index.mjs with no
	// `main`/`module`, so a bare directory alias cannot resolve it.
	'@nextcloud/vue$': path.resolve(
		__dirname,
		'node_modules/@nextcloud/vue/dist/index.mjs',
	),
	'@nextcloud/dialogs': path.resolve(__dirname, 'node_modules/@nextcloud/dialogs'),
	// Force the lib's transitive @nextcloud/axios import to resolve to
	// the app's installed copy. Without the `$` exact-match suffix,
	// webpack would walk up to the lib's own node_modules and load a
	// second axios instance, breaking shared interceptors / CSRF tokens.
	// Decidesk reference: commit ed34703c.
	'@nextcloud/axios$': path.resolve(__dirname, 'node_modules/@nextcloud/axios'),
}

// This app emits THREE independent bundles into the SAME `js/` directory:
// the Vue admin SPA (this config), the React public portal
// (webpack.portal.js) and the Vue site renderer (webpack.site.js).
// @nextcloud/webpack-vue-config sets `output.clean: true`, so the admin build
// WIPES js/ — including bundles only the other configs ever write.
//
// `npm run build` survives that only by accident of ordering (admin first).
// Anything that rebuilds the admin bundle alone — `npm run build:admin`,
// `npm run watch` — silently deletes the others, and the affected page then
// serves a bare mount `<div>` with a 404 on its script and NO console error.
// The other two configs set `clean: false` to protect this side; this is the
// missing other half.
//
// EVERY foreign entry must be listed. It was not: the site renderer was added
// after this guard and never added to it, so `build:admin` deleted
// `portaliq-site.js` and `/site` rendered an empty div — silently, because a
// script that 404s produces no console error and an unmounted Vue app logs
// nothing. Keep this in step with the `entry` blocks of webpack.portal.js and
// webpack.site.js.
webpackConfig.output.clean = {
	keep: /^portaliq-(portal|site)\.js/,
}

// Add SCSS rule to the existing module rules
webpackConfig.module.rules.push({
	test: /\.scss$/,
	use: ['style-loader', 'css-loader', 'sass-loader'],
})

// Replace plugins to avoid duplicate VueLoaderPlugin (base config also registers one).
// CRITICAL: re-add the appName / appVersion DefinePlugin entries — without them
// every @nextcloud/vue widget mount logs `[ERROR] @nextcloud/vue: The library
// was used without setting / replacing the appName`. The base config sets these
// defines, but we lose them when we replace `webpackConfig.plugins` wholesale.
// See ADR-004 (Build / bundling) in hydra/openspec/architecture/.
webpackConfig.plugins = [
	new VueLoaderPlugin(),
	new NodePolyfillPlugin({ additionalAliases: ['process'] }),
	new webpack.DefinePlugin({ appName: JSON.stringify(appId) }),
	new webpack.DefinePlugin({
		appVersion: JSON.stringify(process.env.npm_package_version),
	}),
]

// Share Vue + @nextcloud/vue + pinia + icons + @conduction/nextcloud-vue across
// every entry-point so each widget bundle no longer inlines its own ~3 MB
// framework copy. Stable filenames (no contenthash in the JS name) mean each
// widget's `Util::addScript` PHP call can reference the chunk directly without
// a manifest. The shared chunks load once on the page and stay cached across
// navigations between this app's pages.
//
// Each widget's PHP `load()` MUST attach the shared chunks before the per-widget
// bundle. Order in PHP:
//   1. <appId>-shared-vendor   (Vue, pinia, icons)
//   2. <appId>-shared-nc-vue   (@nextcloud/vue, @conduction/nextcloud-vue)
//   3. <appId>-<widget>Widget  (your widget code)
// `Util::addScript` dedupes by (app, file) so eagerly loading every widget
// still emits each shared chunk exactly once.
webpackConfig.optimization = {
	...(webpackConfig.optimization || {}),
	splitChunks: {
		...(webpackConfig.optimization?.splitChunks || {}),
		chunks: 'all',
		cacheGroups: {
			default: false,
			defaultVendors: false,
			ncVue: {
				name: appId + '-shared-nc-vue',
				// Matches both node_modules entries AND the monorepo-dev alias
				// `../nextcloud-vue/src/...` which webpack resolves outside
				// node_modules when @conduction/nextcloud-vue is aliased to it.
				test: /[\\/]node_modules[\\/](@nextcloud[\\/]vue|@conduction[\\/]nextcloud-vue)[\\/]|[\\/]nextcloud-vue[\\/]src[\\/]/,
				priority: 30,
				reuseExistingChunk: true,
				enforce: true,
				filename: appId + '-shared-nc-vue.js',
			},
			vendor: {
				name: appId + '-shared-vendor',
				test: /[\\/]node_modules[\\/](vue|pinia|vue-material-design-icons|@vueuse|core-js)[\\/]/,
				priority: 20,
				reuseExistingChunk: true,
				enforce: true,
				filename: appId + '-shared-vendor.js',
			},
		},
	},
}

module.exports = webpackConfig
