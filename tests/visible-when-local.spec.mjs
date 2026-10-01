/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * intake-conditional-questions-and-drafts T01 (REQ-ICQ-002): the shared
 * visibleWhen fixture holds against nextcloud-vue's own evaluateVisibleWhenLocal,
 * imported from the installed package rather than copied. The PHP evaluator
 * (lib/Service/Intake/VisibleWhenLocal.php) runs the same fixture in
 * tests/Unit/Service/Intake/VisibleWhenLocalTest.php, so the server and the
 * screen answer every case alike.
 *
 * Runs under `node --test` (portaliq has no Vitest). The predicate's imports
 * reach @nextcloud/auth and @nextcloud/capabilities, which read browser state
 * when loaded, so a window with empty storage and no signed-in user is set up
 * before the dynamic import: the public form's reader is nobody.
 *
 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-the-server-skips-a-hidden-field-req-icq-002
 */

import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

const memoryStorage = () => {
	const items = new Map()
	return {
		getItem: (key) => (items.has(key) ? items.get(key) : null),
		setItem: (key, value) => items.set(key, String(value)),
		removeItem: (key) => items.delete(key),
		key: (index) => [...items.keys()][index] ?? null,
		clear: () => items.clear(),
		get length() {
			return items.size
		},
	}
}

globalThis.window = { localStorage: memoryStorage(), sessionStorage: memoryStorage(), OC: {} }

const { evaluateVisibleWhenLocal } = await import(
	join(ROOT, 'node_modules/@conduction/nextcloud-vue/src/utils/visibleWhen.js')
)

const fixture = JSON.parse(readFileSync(join(ROOT, 'tests/fixtures/visible-when-local.json'), 'utf8'))

test('the fixture carries cases', () => {
	assert.ok(fixture.cases.length > 40)
})

for (const { name, condition, data, visible } of fixture.cases) {
	test(name, () => {
		assert.equal(evaluateVisibleWhenLocal(condition, data), visible)
	})
}
