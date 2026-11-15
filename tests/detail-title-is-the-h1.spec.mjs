#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// detail-title-is-the-h1.spec.mjs: an open item's title is the page's h1, as
// the Mijn task board draws it (TaakAfronden: the eyebrow "Taak" over the task
// title at 40px; no "Mijn taken" heading above it). The page says so with a
// `titled` event and the signed-in area leaves its own heading and help out.
//
// Usage:
//   node --test tests/detail-title-is-the-h1.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { PAGE_EMITS } from '../src/site/pages/inbox/pageProps.js'
import { instance, inState, t } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const TasksPage = await loadSfc('src/site/pages/inbox/TasksPage.vue')
const AccountArea = await loadSfc('src/site/components/AccountArea.vue')

const TASK = { uuid: 'task-1', title: 'Stuur een kopie van uw ID-bewijs' }

/**
 * An api that opens TASK and lists nothing.
 *
 * @return {object} The api.
 */
function fakeApi() {
	return {
		fetchTask: async () => ({ ok: true, task: TASK }),
		fetchTasks: async () => ({ ok: true, tasks: [] }),
	}
}

test('a signed-in page may say it carries its own h1', () => {
	assert.ok(PAGE_EMITS.includes('titled'))
})

test('an open task is the page h1 under its eyebrow', async () => {
	const html = await renderComponent(
		inState(TasksPage, { loading: false, detail: { task: TASK } }),
		{ api: fakeApi(), t, locale: 'en' },
	)
	assert.match(
		html,
		/<p class="pq-task-detail__eyebrow">Task<\/p><h1 class="utrecht-heading-2 pq-task-detail__title">Stuur een kopie van uw ID-bewijs<\/h1>/,
	)
	assert.doesNotMatch(html, /<h2 class="utrecht-heading-2">Stuur/)
})

test('the task page says when an open task titles it, and when it no longer does', () => {
	const page = instance(TasksPage, { api: fakeApi(), t, locale: 'en' })
	assert.equal(page.showsTask, false)
	page.detail = { loading: true }
	assert.equal(page.showsTask, false, 'not while the task loads')
	page.detail = { refusal: 'This task does not exist or is not yours.' }
	assert.equal(page.showsTask, false, 'not on a refusal')
	page.detail = { task: TASK }
	assert.equal(page.showsTask, true)
	TasksPage.watch.showsTask.call(page, true)
	TasksPage.watch.showsTask.call(page, false)
	assert.deepEqual(page.emitted, [['titled', true], ['titled', false]])
})

test('the open task draws the board rhythm: eyebrow 12px over a 1.15 title', () => {
	const sfc = readFileSync(new URL('../src/site/pages/inbox/TasksPage.vue', import.meta.url), 'utf8')
	const eyebrow = sfc.match(/\n\.pq-task-detail__eyebrow \{([^}]*)\}/)
	const title = sfc.match(/\n\.pq-task-detail__title \{([^}]*)\}/)
	assert.ok(eyebrow && title)
	assert.match(eyebrow[1], /margin: 0 0 0\.75rem;/)
	assert.match(title[1], /line-height: 1\.15;/)
})

const entry = { key: 'tasks', label: 'Mijn taken', route: '/mijn/tasks', special: 'tasks' }
const areaProps = {
	sessionKnown: true,
	session: { subjectRef: 'sanne' },
	nav: [entry],
	entry,
	t,
	currentRoute: '/mijn/tasks',
}

/**
 * The area as it stands once its page has loaded: without the entry watcher,
 * so the state under test is not reset by the first load.
 *
 * @param {object} state Data over the area's own.
 * @return {object} The component.
 */
function settled(state) {
	const { entry: _loads, ...watch } = AccountArea.watch
	return { ...inState(AccountArea, state), watch }
}

test('the area keeps its heading over a list', async () => {
	const html = await renderComponent(settled({ pageTitled: false }), areaProps)
	assert.match(html, /data-testid="site-account-title">Mijn taken<\/h1>/)
})

test('the area leaves its heading out while the page titles itself', async () => {
	const html = await renderComponent(settled({ pageTitled: true }), areaProps)
	assert.doesNotMatch(html, /data-testid="site-account-title"/)
})

test('the area leaves the list help out while the page titles itself, and listens for it', () => {
	const sfc = readFileSync(new URL('../src/site/components/AccountArea.vue', import.meta.url), 'utf8')
	assert.match(sfc, /<PageHelp\s+v-if="sectionHelpText && !pageTitled"/)
	assert.match(sfc, /@titled="pageTitled = \$event === true"/)
})

test('a new page starts without its own title', async () => {
	const area = instance(AccountArea, { ...areaProps, entry: { ...entry, stepUp: true } })
	area.pageTitled = true
	await area.loadPage()
	assert.equal(area.pageTitled, false)
})
