#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// resident-menu-leave-out.spec.mjs: a portal may leave items of the own area
// out of the resident menu by name, so the school portals have no generic
// "Zaken en taken" group their boards do not draw (resident-menu-leave-out).
//
// Usage:
//   node --test tests/site-look/resident-menu-leave-out.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { buildNav, shellSections } from '../../src/shared/portalNav.js'
import { residentMenuGroups } from '../../src/site/lib/residentMenu.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const t = (key) => ({ 'Cases and tasks': 'Zaken en taken' })[key] || key
const CONTRIBUTIONS = {
	contributions: [],
	tasks: { enabled: true },
}

/**
 * The menu's groups with the given items left out.
 *
 * @param {Array<string>|null} leaveOut The names.
 * @return {Array<{title: string, names: Array<string>}>} Title and item names per group.
 */
function menu(leaveOut) {
	const nav = buildNav(
		CONTRIBUTIONS.contributions,
		t,
		shellSections({
			session: { subjectRef: 's1' },
			contributions: CONTRIBUTIONS,
			threads: [],
			news: [],
		}),
	)
	return residentMenuGroups(nav, t, 0, (route) => route, {}, null, leaveOut).map(
		(group) => ({
			title: group.title,
			names: group.items.map((item) => item.name),
		}),
	)
}

test('without leaveOut the site builds its "Zaken en taken" group', () => {
	assert.ok(menu(null).some((group) => group.title === 'Zaken en taken'))
})

test('leaving its items out removes the group and nothing else', () => {
	const before = menu(null).filter((group) => group.title !== 'Zaken en taken')
	const after = menu(['cases', 'tasks', 'access'])
	assert.ok(!after.some((group) => group.title === 'Zaken en taken'))
	assert.deepEqual(after, before)
})

test('the site hands the portal list to the menu in both places', () => {
	const app = readFileSync(join(ROOT, 'src/site/App.vue'), 'utf8')
	assert.equal(app.split('this.site?.residentMenu?.leaveOut,').length - 1, 2)
})
