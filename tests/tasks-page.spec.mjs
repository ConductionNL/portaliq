#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// tasks-page.spec.mjs: "My tasks" on the site (portal-task-delivery,
// site-reaches-portal-parity REQ-SRP-035). A resident sees their open tasks,
// arrives on one task straight from the inbox's "View task", and completes it
// with a comment and files within the task's upload rules. A refusal from the
// proxy reads as a plain sentence. The React portal's TasksPage.jsx had no
// node test; this one covers the Vue port.
//
// Usage:
//   node --test tests/tasks-page.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	keepTaskToOpen,
	takeTaskToOpen,
	TASK_STORAGE_KEY,
	TASKS_ROUTE,
} from '../src/site/pages/inbox/inbox.js'
import { pages } from '../src/site/pages/inbox/index.js'
import {
	acceptAttribute,
	refusalKey,
	typeAccepted,
	uploadRules,
	validateFiles,
} from '../src/site/pages/inbox/tasks.js'
import { instance, inState, t } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const TasksPage = await loadSfc('src/site/pages/inbox/TasksPage.vue')
const InboxPage = await loadSfc('src/site/pages/inbox/InboxPage.vue')

const TASK = {
	uuid: 'task-1',
	title: 'Lever het formulier in',
	description: 'Stuur het ingevulde formulier terug.',
	dueAt: '2026-10-15T00:00:00+02:00',
	overdue: false,
	metadata: {
		upload: {
			required: true,
			maxFiles: 2,
			maxSizeBytes: 2 * 1024 * 1024,
			acceptedTypes: ['application/pdf', 'image/*', 'docx'],
		},
	},
}

/**
 * A storage that keeps what it is given, like sessionStorage.
 *
 * @return {object} The storage.
 */
function memoryStorage() {
	const map = new Map()
	return {
		getItem: (key) => (map.has(key) ? map.get(key) : null),
		setItem: (key, value) => map.set(key, String(value)),
		removeItem: (key) => map.delete(key),
	}
}

/**
 * A fake portal api for the task proxy, recording every call.
 *
 * @param {object} [options] How it answers.
 * @param {string} [options.refuse] A refusal code for completeTask.
 * @param {string} [options.missing] A task uuid fetchTask refuses.
 * @return {object} The api with `calls`.
 */
function fakeApi({ refuse = '', missing = '' } = {}) {
	const calls = []
	return {
		calls,
		async fetchTasks() {
			calls.push(['fetchTasks'])
			return { results: [TASK], total: 1 }
		},
		async fetchTask(uuid) {
			calls.push(['fetchTask', uuid])
			return uuid === missing
				? { ok: false, status: 404, code: 'no-such-task', task: null }
				: { ok: true, status: 200, code: '', task: { ...TASK, uuid } }
		},
		async completeTask(uuid, payload) {
			calls.push(['completeTask', uuid, payload])
			return refuse
				? { ok: false, status: 409, code: refuse, body: {} }
				: { ok: true, status: 200, code: '', body: {} }
		},
	}
}

const PDF = { name: 'formulier.pdf', type: 'application/pdf', size: 1024 }

test('the upload rules are read as frozen on the task', () => {
	assert.deepEqual(uploadRules({}), {
		required: false,
		maxFiles: 1,
		maxSizeBytes: 0,
		acceptedTypes: [],
	})
	assert.deepEqual(uploadRules(TASK), {
		required: true,
		maxFiles: 2,
		maxSizeBytes: 2097152,
		acceptedTypes: ['application/pdf', 'image/*', 'docx'],
	})
	assert.equal(
		acceptAttribute(['application/pdf', 'image/*', 'docx', '.odt']),
		'application/pdf,image/*,.docx,.odt',
	)
	assert.equal(acceptAttribute([]), undefined)
})

test('a file type matches by media type, wildcard or extension, as the server matches', () => {
	const accepted = uploadRules(TASK).acceptedTypes
	assert.equal(typeAccepted(PDF, accepted), true)
	assert.equal(
		typeAccepted({ name: 'foto.jpg', type: 'image/jpeg' }, accepted),
		true,
	)
	assert.equal(typeAccepted({ name: 'brief.DOCX', type: '' }, accepted), true)
	assert.equal(
		typeAccepted({ name: 'film.mp4', type: 'video/mp4' }, accepted),
		false,
	)
	assert.equal(
		typeAccepted({ name: 'x.exe', type: '' }, []),
		true,
		'no rule accepts anything',
	)
})

test('the first broken rule is named before anything is sent', () => {
	assert.equal(validateFiles(TASK, [], t), 'A file is required for this task.')
	assert.equal(
		validateFiles(TASK, [PDF, PDF, PDF], t),
		'You can add at most 2 file(s).',
	)
	assert.equal(
		validateFiles(TASK, [{ ...PDF, size: 3 * 1024 * 1024 }], t),
		'This file is too large. The limit is 2 MB.',
	)
	assert.equal(
		validateFiles(TASK, [{ name: 'film.mp4', type: 'video/mp4', size: 1 }], t),
		'This file type is not accepted. Allowed: application/pdf, image/*, docx.',
	)
	assert.equal(validateFiles(TASK, [PDF], t), null)
	assert.equal(validateFiles({}, [], t), null, 'an optional upload may stay empty')
})

test('each proxy refusal reads as a plain sentence', () => {
	assert.equal(
		refusalKey('no-such-task'),
		'This task does not exist or is not yours.',
	)
	assert.equal(refusalKey('task-closed'), 'This task is already closed.')
	assert.equal(
		refusalKey('upload-constraint'),
		'The upload was refused. Check the file rules above.',
	)
	assert.equal(
		refusalKey('task-service-unreachable'),
		'The tasks are not available right now. Please try again later.',
	)
})

test('the task to open is handed over once', () => {
	const storage = memoryStorage()
	keepTaskToOpen(storage, 'task-9')
	assert.equal(storage.getItem(TASK_STORAGE_KEY), 'task-9')
	assert.equal(takeTaskToOpen(storage), 'task-9')
	assert.equal(takeTaskToOpen(storage), null, 'forgotten once read')
	assert.equal(takeTaskToOpen(null), null)
})

test('"View task" in the inbox opens My tasks with that task open', async () => {
	const storage = memoryStorage()
	globalThis.window = { sessionStorage: storage }
	try {
		const inbox = instance(InboxPage, { api: {}, t, locale: 'en' })
		inbox.openTask('task-7')
		assert.deepEqual(inbox.emitted, [['navigate', TASKS_ROUTE]])
		assert.equal(TASKS_ROUTE, '/mijn/tasks')

		const asked = []
		const withNavigate = instance(InboxPage, {
			api: {},
			t,
			navigate: (key, params) => asked.push([key, params]),
		})
		withNavigate.openTask('task-7')
		assert.deepEqual(asked, [['tasks', { task: 'task-7' }]])

		const api = fakeApi()
		const page = instance(TasksPage, { api, t, locale: 'en' })
		TasksPage.created.call(page)
		await new Promise((resolve) => setTimeout(resolve, 5))
		assert.deepEqual(
			api.calls.map((c) => c.slice(0, 2)),
			[['fetchTasks'], ['fetchTask', 'task-7']],
		)
		assert.equal(page.detail.task.uuid, 'task-7')
		assert.equal(
			storage.getItem(TASK_STORAGE_KEY),
			null,
			'a reload opens the list again',
		)
	} finally {
		delete globalThis.window
	}
})

test('completing a task sends the comment and the files, then says thank you', async () => {
	const api = fakeApi()
	const page = instance(TasksPage, { api, t, locale: 'en' })
	await page.openTask('task-1')

	await page.submit()
	assert.equal(page.formError, 'A file is required for this task.')
	assert.equal(
		api.calls.some((c) => c[0] === 'completeTask'),
		false,
		'nothing is sent while a rule is broken',
	)

	page.pick([PDF])
	page.comment = 'Zie bijlage.'
	await page.submit()
	const sent = api.calls.find((c) => c[0] === 'completeTask')
	assert.deepEqual(sent, [
		'completeTask',
		'task-1',
		{ comment: 'Zie bijlage.', files: [PDF] },
	])
	assert.equal(page.completed, true)
	assert.equal(page.formError, '')
	assert.deepEqual(page.emitted, [['refresh', undefined]])
})

test('a refusal on completion and on opening reads as a sentence', async () => {
	const closed = instance(TasksPage, {
		api: fakeApi({ refuse: 'task-closed' }),
		t,
		locale: 'en',
	})
	await closed.openTask('task-1')
	closed.pick([PDF])
	await closed.submit()
	assert.equal(closed.completed, false)
	assert.equal(closed.formError, 'This task is already closed.')

	const missing = instance(TasksPage, {
		api: fakeApi({ missing: 'gone' }),
		t,
		locale: 'en',
	})
	await missing.openTask('gone')
	assert.deepEqual(missing.detail, {
		refusal: 'This task does not exist or is not yours.',
	})
})

test('the completion form labels every control and ties the rules to the file field', async () => {
	const html = await renderComponent(
		inState(TasksPage, {
			loading: false,
			detail: { task: TASK },
			formError: 'A file is required for this task.',
		}),
		{ api: fakeApi(), t, locale: 'en' },
	)
	assert.match(html, /<h2 class="utrecht-heading-2">Lever het formulier in<\/h2>/)
	assert.match(html, /Finish before 15\/10\/2026/)
	assert.match(
		html,
		/<label class="utrecht-form-label" for="portaliq-task-files">Add a file \(required\)<\/label>/,
	)
	assert.match(
		html,
		/<ul id="portaliq-task-file-rules" class="pq-task-upload-rules">/,
	)
	assert.match(html, /You can add at most 2 file\(s\)\./)
	assert.match(html, /Maximum file size: 2 MB\./)
	assert.match(
		html,
		/<input id="portaliq-task-files" type="file" class="pq-task-file-input" multiple accept="application\/pdf,image\/\*,\.docx" aria-required="true" aria-describedby="portaliq-task-file-rules">/,
	)
	assert.match(
		html,
		/<label class="utrecht-form-label" for="portaliq-task-comment">Comment \(optional\)<\/label>/,
	)
	assert.match(
		html,
		/<p class="utrecht-paragraph pq-error" role="alert">A file is required for this task\.<\/p>/,
	)
	assert.match(
		html,
		/<button type="submit" class="utrecht-button utrecht-button--primary-action">Submit task<\/button>/,
	)
})

test('the list shows each open task with its due date, and an empty list says so', async () => {
	const list = await renderComponent(
		inState(TasksPage, { loading: false, tasks: [{ ...TASK, overdue: true }] }),
		{ api: fakeApi(), t, locale: 'en' },
	)
	assert.match(list, /<span class="pq-task-title">Lever het formulier in<\/span>/)
	assert.match(list, /Finish before 15\/10\/2026/)
	assert.match(list, /· Overdue/, 'overdue in text, not colour alone')

	const empty = await renderComponent(
		inState(TasksPage, { loading: false, tasks: [] }),
		{ api: fakeApi(), t, locale: 'nl' },
	)
	assert.match(empty, /<em>U heeft geen open taken\.<\/em>/)
	assert.equal(typeof pages.tasks, 'function')
})
