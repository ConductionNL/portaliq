// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Every webpack entry that renders library components registers the library
// catalogue itself. The admin page (src/settings.js) is its own entry:
// without the call the admin shell chrome reads in English.
//
// Usage:
//   node --test tests/admin-entry-library-catalogue.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

for (const entry of ['main.js', 'settings.js']) {
	test(`src/${entry} registers the library catalogue`, () => {
		const source = readFileSync(
			new URL(`../src/${entry}`, import.meta.url),
			'utf8',
		)
		assert.match(
			source,
			/import\s*\{[^}]*\bregisterTranslations\b[^}]*\}\s*from\s*'@conduction\/nextcloud-vue'/,
		)
		assert.match(source, /^\s*registerTranslations\(\)/m)
	})
}
