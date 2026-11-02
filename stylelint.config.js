module.exports = {
	extends: '@nextcloud/stylelint-config',
	rules: {
		'selector-pseudo-element-no-unknown': [
			true,
			{
				ignorePseudoElements: ['v-deep'],
			},
		],
	},
	overrides: [
		{
			// The site's own sheet paints from theme tokens only
			// (portal-theme-blocks-and-contributed-pages REQ-PTB-001).
			files: ['css/site-theme.css'],
			rules: {
				'color-no-hex': true,
				'color-named': 'never',
			},
		},
	],
}
