// SPDX-License-Identifier: EUPL-1.2
//
// Dedicated build for the PUBLIC Portaliq portal SPA (React + NL Design System).
//
// Deliberately standalone — it does NOT extend @nextcloud/webpack-vue-config
// (that config targets the internal Vue/nc-vue admin UI). The two frontends
// coexist: `npm run build` builds the Vue admin bundle, `npm run build:portal`
// builds this React portal bundle into js/portaliq-portal.js, attached by
// templates/portal.php.

const path = require('path')

const isDev = process.env.NODE_ENV === 'development'

module.exports = {
	mode: isDev ? 'development' : 'production',
	devtool: isDev ? 'cheap-source-map' : 'source-map',
	entry: {
		'portaliq-portal': path.join(__dirname, 'src', 'portal', 'main.jsx'),
	},
	output: {
		path: path.join(__dirname, 'js'),
		filename: '[name].js',
		clean: false,
	},
	resolve: {
		extensions: ['.js', '.jsx'],
		alias: {
			'@portal': path.resolve(__dirname, 'src', 'portal'),
		},
	},
	module: {
		rules: [
			{
				test: /\.jsx?$/,
				exclude: /node_modules/,
				use: {
					loader: 'babel-loader',
					options: {
						presets: [
							'@babel/preset-env',
							['@babel/preset-react', { runtime: 'automatic' }],
						],
					},
				},
			},
			{
				test: /\.css$/,
				use: ['style-loader', 'css-loader'],
			},
		],
	},
}
