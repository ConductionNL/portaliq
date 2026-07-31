const {
	defineConfig,
} = require('@eslint/config-helpers')

const js = require('@eslint/js')

const {
	FlatCompat,
} = require('@eslint/eslintrc')

// Vue 3 rule set shipped inside @conduction/nextcloud-vue. It turns on the
// whole `vue/no-deprecated-*` family (which the `@nextcloud` v8 base leaves
// off, so Vue-2-only idioms such as `beforeDestroy` linted CLEAN against
// unmigrated code) and turns OFF the two INVERTED Vue-2 rules
// (`vue/no-v-model-argument`, `vue/no-v-for-template-key`) that forbid syntax
// Vue 3 requires. Registers no plugins, so it layers onto the base config.
// MUST be spread LAST — it is an array of three configs, not one object.
const {
	conductionVue3Fixes,
} = require('@conduction/nextcloud-vue/eslint')

const compat = new FlatCompat({
	baseDirectory: __dirname,
	recommendedConfig: js.configs.recommended,
	allConfig: js.configs.all,
})

module.exports = defineConfig([{
	extends: compat.extends('@nextcloud'),

	// The `@nextcloud` shared config resolves to `ecmaVersion: 6` (ES2015).
	// The main lint pass does not notice because vue-eslint-parser is driven
	// with its own options, but `eslint-plugin-import` re-parses every
	// *imported* module with the ecmaVersion from here — so it chokes on
	// optional chaining (`?.`), nullish coalescing (`??`) and object spread,
	// and reports each failure as a bogus "Parse errors in imported module".
	languageOptions: {
		ecmaVersion: 'latest',
		sourceType: 'module',
	},

	settings: {
		'import/resolver': {
			alias: {
				map: [
					['@', './src'],
					// Mirrors webpack.portal.js's `@portal` alias. Without it
					// every `@portal/...` import in the React portal reports
					// import/no-unresolved.
					['@portal', './src/portal'],
					['@floating-ui/dom-actual', './node_modules/@floating-ui/dom'],
					['@conduction/nextcloud-vue', '../nextcloud-vue/src'],
				],
				extensions: ['.js', '.jsx', '.ts', '.vue', '.json', '.css'],
			},
		},
	},

	rules: {
		// Allow unused i18n functions (t, n) — imported for future translation wiring
		'no-unused-vars': ['error', { varsIgnorePattern: '^(t|n)$', argsIgnorePattern: '^_' }],
		'jsdoc/require-jsdoc': 'off',
		// `@spec` is the hydra ADR-020 traceability tag (gate-16 reads it).
		// Without declaring it, jsdoc/check-tag-names flags every annotated
		// symbol in the app as an invalid tag.
		'jsdoc/check-tag-names': ['warn', { definedTags: ['spec'] }],
		'vue/first-attribute-linebreak': 'off',
		'@typescript-eslint/no-explicit-any': 'off',
		'n/no-missing-import': 'off',
		'import/namespace': 'off', // disable namespace checking to avoid parser requirement
		'import/default': 'off', // disable default import checking to avoid parser requirement
		'import/no-named-as-default': 'off', // disable named-as-default checking to avoid parser requirement
		'import/no-named-as-default-member': 'off', // disable named-as-default-member checking to avoid parser requirement
	},
}, {
	// The PUBLIC portal SPA is React (src/portal/**, built by webpack.portal.js).
	//
	// The `@nextcloud` base already sets `ecmaFeatures.jsx: true`, but that
	// flag only matters to espree — this config resolves to
	// `@babel/eslint-parser`, and with `requireConfigFile: false` Babel loads
	// NO config, so its JSX plugin is never enabled. Every .jsx file failed
	// with "This experimental syntax requires enabling one of the following
	// parser plugin(s)" and its real rule violations were never evaluated.
	// The repo has no babel.config.js (webpack.portal.js passes presets
	// inline), so the presets have to be declared here too.
	files: ['**/*.jsx'],
	languageOptions: {
		parserOptions: {
			requireConfigFile: false,
			babelOptions: {
				presets: [['@babel/preset-react', { runtime: 'automatic' }]],
			},
		},
	},
	plugins: {
		react: require('eslint-plugin-react'),
		'react-hooks': require('eslint-plugin-react-hooks'),
	},
	rules: {
		// Without `react/jsx-uses-vars`, ESLint's scope analysis does not see
		// `<PageView />` as a use of the imported `PageView`, so every React
		// component import reads as an unused variable (18 of them here).
		'react/jsx-uses-vars': 'error',
		'react/jsx-uses-react': 'error',
		// The source already carries
		// `// eslint-disable-next-line react-hooks/exhaustive-deps`
		// comments, but the plugin was never installed — so ESLint reported
		// "Definition for rule ... was not found" AS AN ERROR at each of
		// those lines. Registering the plugin is what makes those
		// suppressions mean what their authors intended.
		'react-hooks/rules-of-hooks': 'error',
		'react-hooks/exhaustive-deps': 'warn',
	},
}, {
	// Node-side CLI tools (build / validate scripts) legitimately use
	// console + process.exit and ship as plain JS (no shebang).
	files: ['tests/validate-manifest.js'],
	rules: {
		'no-console': 'off',
		'n/no-process-exit': 'off',
		'n/shebang': 'off',
	},
},
// Spread LAST so the Vue 3 severities win over the `@nextcloud` v8 base.
...conductionVue3Fixes,
])
