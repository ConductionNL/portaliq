#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// timed-task.spec.mjs: a pupil takes a timed test on the site
// (portal-take-assessment, site-reaches-portal-parity REQ-SRP-036). The clock
// follows the server's deadline, answers are saved once per change and
// flushed before hand-in, and every item type gets its own control.
//
// Usage:
//   node --test tests/timed-task.spec.mjs
//
// No leaf app declares a timed task until learniq ships its side, so the flow
// is driven here against a fake api, through the shared helpers in
// src/shared/timedTask.js and the site's Vue components.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as task from '../src/shared/timedTask.js'
import { instance, inState } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const TimedTaskItem = await loadSfc(
	'src/site/components/timed-task/TimedTaskItem.vue',
)
const TimedTaskView = await loadSfc(
	'src/site/components/timed-task/TimedTaskView.vue',
)

const block = {
	available: 'listTests',
	start: 'startTest',
	answer: 'saveTestAnswer',
	submit: 'submitTest',
	result: 'readTestResult',
}
/**
 * The identity translator: English source strings with interpolation.
 *
 * @param {string} key The English string.
 * @param {object} [vars] Placeholder values.
 * @return {string} The string.
 */
function t(key, vars) {
	return Object.entries(vars || {}).reduce(
		(text, [k, v]) => text.replace(`{${k}}`, String(v)),
		key,
	)
}

/**
 * A fake portal api answering the five actions and recording every call.
 *
 * @param {object} options How it answers.
 * @param {Set<string>} [options.failOnce] Item ids whose first save fails.
 * @param {boolean} [options.closed] Whether saves answer 409 attempt_closed.
 * @return {object} The fake api with `calls`.
 */
function fakeApi({ failOnce = new Set(), closed = false } = {}) {
	const calls = []
	const failed = new Set()
	return {
		calls,
		async forwardAction(app, actionId, body) {
			calls.push({ app, actionId, body })
			await new Promise((resolve) => setTimeout(resolve, 1))
			if (actionId === 'startTest') {
				return {
					ok: true,
					status: 200,
					body: {
						attemptId: 'attempt-1',
						title: 'Toets hoofdstuk 3',
						serverNow: '2026-10-01T09:00:00+02:00',
						deadlineAt: '2026-10-01T09:20:00+02:00',
						items: [
							{
								itemId: 'q1',
								type: 'choice',
								prompt: 'Hoofdstad van Frankrijk?',
								choices: [
									{ id: 'A', label: 'Parijs' },
									{ id: 'B', label: 'Lyon' },
								],
							},
							{ itemId: 'q2', type: 'textEntry', prompt: '3 x 4 =' },
							{
								itemId: 'q3',
								type: 'extendedText',
								prompt: 'Leg uit.',
							},
							{ type: 'choice', prompt: 'no id, dropped' },
						],
						responses: { q2: '12' },
					},
				}
			}
			if (actionId === 'saveTestAnswer') {
				if (closed) {
					return {
						ok: false,
						status: 409,
						body: { error: 'attempt_closed' },
					}
				}
				if (failOnce.has(body.itemId) && !failed.has(body.itemId)) {
					failed.add(body.itemId)
					return { ok: false, status: 502, body: {} }
				}
				return { ok: true, status: 200, body: { saved: true } }
			}
			if (actionId === 'submitTest') {
				return { ok: true, status: 200, body: { state: 'submitted' } }
			}
			return { ok: true, status: 200, body: {} }
		},
	}
}

test('counts down from the server deadline, not the client clock', () => {
	// The client clock runs five minutes ahead of the server.
	const receivedAt = Date.parse('2026-10-01T09:05:00+02:00')
	const serverNow = '2026-10-01T09:00:00+02:00'
	const deadline = '2026-10-01T09:37:30+02:00'

	assert.equal(
		task.formatClock(
			task.secondsLeft(deadline, serverNow, receivedAt, receivedAt),
		),
		'37:30',
	)
	assert.equal(
		task.formatClock(
			task.secondsLeft(deadline, serverNow, receivedAt, receivedAt + 90000),
		),
		'36:00',
	)
	assert.equal(
		task.secondsLeft(deadline, serverNow, receivedAt, receivedAt + 3600000),
		0,
		'never negative',
	)
	assert.equal(
		task.secondsLeft(null, serverNow, receivedAt, receivedAt),
		null,
		'untimed',
	)
	assert.equal(task.formatClock(3725), '1:02:05')
})

test('runs an attempt from start to submit', async () => {
	const api = fakeApi({ failOnce: new Set(['q3']) })
	const started = await task.startAttempt(
		api,
		'learniq',
		block,
		'assessment-1',
		'EXAMCODE',
	)
	assert.equal(started.ok, true)
	assert.deepEqual(api.calls[0], {
		app: 'learniq',
		actionId: 'startTest',
		body: { taskId: 'assessment-1', accessCode: 'EXAMCODE' },
	})
	assert.deepEqual(
		started.attempt.items.map((i) => i.itemId),
		['q1', 'q2', 'q3'],
	)
	assert.equal(
		task.initialResponse(started.attempt.items[1], started.attempt.responses.q2),
		'12',
		'a saved answer comes back',
	)

	const states = []
	const queue = task.createSaveQueue(
		(itemId, value) =>
			api.forwardAction('learniq', block.answer, {
				attemptId: started.attempt.attemptId,
				itemId,
				response: value,
			}),
		(s) => states.push(s),
	)
	queue.set('q1', 'A')
	queue.set('q3', 'eerste')
	queue.set('q3', 'Omdat het zo is.')
	queue.set('q1', 'B')
	await queue.flush()

	const saves = api.calls
		.filter((c) => c.actionId === 'saveTestAnswer')
		.map((c) => [c.body.itemId, c.body.response])
	// q1 'A' was already in flight; the waiting q3 and q1 values were replaced
	// by their latest; q3's first send failed and flush retried it once.
	assert.deepEqual(saves, [
		['q1', 'A'],
		['q3', 'Omdat het zo is.'],
		['q1', 'B'],
		['q3', 'Omdat het zo is.'],
	])
	assert.ok(api.calls.slice(1).every((c) => c.body.attemptId === 'attempt-1'))
	assert.deepEqual(queue.state(), { unsaved: [], failed: [], closed: false })

	queue.set('q2', '13')
	const handedIn = await task.submitAttempt(
		api,
		'learniq',
		block,
		'attempt-1',
		queue,
	)
	assert.deepEqual(handedIn, { ok: true, allSaved: true })
	const last = api.calls.slice(-2)
	assert.deepEqual(
		last.map((c) => c.actionId),
		['saveTestAnswer', 'submitTest'],
		'the unsaved answer is flushed before hand-in',
	)
	assert.deepEqual(last[1].body, { attemptId: 'attempt-1' })
})

test('a closed attempt stops saving and hands in as handed in', async () => {
	const api = fakeApi({ closed: true })
	const queue = task.createSaveQueue((itemId, value) =>
		api.forwardAction('learniq', block.answer, {
			attemptId: 'a',
			itemId,
			response: value,
		}),
	)
	queue.set('q1', 'A')
	queue.set('q2', 'x')
	await queue.flush()

	assert.equal(queue.state().closed, true)
	assert.equal(
		api.calls.filter((c) => c.actionId === 'saveTestAnswer').length,
		1,
		'nothing more is sent after the attempt closed',
	)
})

test('a start refusal carries the leaf app message', async () => {
	const api = {
		async forwardAction() {
			return {
				ok: false,
				status: 403,
				body: {
					error: 'access_code_wrong',
					message: 'De toegangscode klopt niet.',
				},
			}
		},
	}
	assert.deepEqual(await task.startAttempt(api, 'learniq', block, 'x'), {
		ok: false,
		error: 'access_code_wrong',
		message: 'De toegangscode klopt niet.',
	})
})

test('renders a control per item type', async () => {
	const render = (item, value) =>
		renderComponent(TimedTaskItem, {
			item: { choices: [], sources: [], targets: [], ...item },
			value,
			t,
		})

	const choice = await render(
		{
			itemId: 'q1',
			type: 'choice',
			prompt: 'Kies',
			choices: [
				{ id: 'A', label: 'Parijs' },
				{ id: 'B', label: 'Lyon' },
			],
		},
		'A',
	)
	assert.match(choice, /<fieldset[^>]*><legend[^>]*>Kies<\/legend>/)
	assert.equal((choice.match(/type="radio"/g) || []).length, 2)
	assert.match(choice, /value="A" checked/)

	assert.match(
		await render(
			{
				itemId: 'q2',
				type: 'inlineChoice',
				prompt: 'Vul in',
				choices: [{ id: 'x', label: 'x' }],
			},
			'',
		),
		/<label class="utrecht-form-label" for="tt-q2">Vul in<\/label><select id="tt-q2"/,
	)
	assert.match(
		await render({ itemId: 'q3', type: 'textEntry', prompt: '3 x 4' }, '12'),
		/<input id="tt-q3" type="text" class="utrecht-textbox" value="12">/,
	)

	const essay = await render(
		{ itemId: 'q4', type: 'extendedText', prompt: 'Leg uit' },
		'',
	)
	assert.match(essay, /<textarea id="tt-q4" class="utrecht-textarea" rows="10"/)
	assert.match(essay, /aria-describedby="tt-q4-help"/)
	assert.match(essay, /Your teacher marks this question\./)

	const order = await render(
		{
			itemId: 'q5',
			type: 'order',
			prompt: 'Zet op volgorde',
			choices: [
				{ id: 'a', label: 'Eerst' },
				{ id: 'b', label: 'Dan' },
			],
		},
		['b', 'a'],
	)
	assert.match(
		order,
		/<li><span>Dan<\/span><button type="button" class="utrecht-button utrecht-button--subtle" disabled>Move up<\/button><button type="button" class="utrecht-button utrecht-button--subtle">Move down<\/button><\/li>/,
	)

	const match = await render(
		{
			itemId: 'q6',
			type: 'match',
			prompt: 'Koppel',
			sources: [
				{ id: 's1', label: 'Hond' },
				{ id: 's2', label: 'Kat' },
			],
			targets: [{ id: 't1', label: 'Blaft' }],
		},
		{ s1: 't1' },
	)
	assert.equal((match.match(/<select/g) || []).length, 2, 'one select per source')
	assert.match(
		match,
		/<label class="utrecht-form-label" for="tt-q6-s1">Hond<\/label>/,
	)

	assert.match(
		await render({ itemId: 'q7', type: 'hotspot', prompt: 'Klik' }, ''),
		/<textarea id="tt-q7" class="utrecht-textarea" rows="4"/,
		'any other type is a text answer',
	)
	assert.deepEqual(task.move(['a', 'b', 'c'], 2, -1), ['a', 'c', 'b'])
	assert.deepEqual(task.move(['a', 'b'], 0, -1), ['a', 'b'])
})

test('the attempt payload is sanitised and answers are counted by shape', () => {
	assert.equal(task.normaliseAttempt({ items: [] }), null)
	const attempt = task.normaliseAttempt({
		attemptId: 'a',
		items: [
			{
				itemId: 'q',
				type: 'order',
				choices: [{ id: 'x', label: 'X' }, { label: 'no id' }, 'junk'],
			},
		],
	})
	assert.deepEqual(attempt.items[0].choices, [{ id: 'x', label: 'X' }])
	assert.deepEqual(task.initialResponse(attempt.items[0]), ['x'])
	assert.equal(task.isAnswered({ type: 'textEntry' }, '  '), false)
	assert.equal(task.isAnswered({ type: 'match' }, { s: 't' }), true)
	assert.equal(task.renderAs({ type: 'gapMatch' }), 'text')
})

test('the task list shows the attempts with their results', async () => {
	const html = await renderComponent(TimedTaskView, {
		collection: {
			id: 'studentTests',
			label: 'Toetsen',
			kind: 'timedTask',
			timedTask: block,
		},
		app: 'learniq',
		attempts: [
			{
				id: 'attempt-1',
				assessmentTitle: 'Toets hoofdstuk 2',
				lifecycle: 'graded',
			},
			{
				id: 'attempt-2',
				assessmentTitle: 'Toets 3',
				lifecycle: 'in-progress',
			},
		],
		api: fakeApi(),
		t,
		locale: 'en',
		initialTasks: [
			{
				taskId: 'assessment-1',
				title: 'Toets 4',
				timeLimitMinutes: 20,
				extraTimeMinutes: 5,
				needsAccessCode: true,
			},
		],
	})

	assert.match(html, /<h3 class="utrecht-heading-3">Toetsen<\/h3>/)
	assert.match(html, /Tests you can take/)
	assert.match(html, /Time limit: 20 minutes/)
	assert.match(html, /Including 5 minutes of extra time/)
	assert.match(
		html,
		/<label class="utrecht-form-label" for="tt-code-assessment-1">Access code<\/label>/,
	)
	assert.equal(
		(html.match(/View result/g) || []).length,
		1,
		'no result button while in progress',
	)
})

test('the question screen counts down and names the question', async () => {
	const receivedAt = Date.parse('2026-10-01T09:00:00+02:00')
	const attempt = {
		attemptId: 'attempt-1',
		title: 'Toets hoofdstuk 3',
		serverNow: '2026-10-01T09:00:00+02:00',
		deadlineAt: '2026-10-01T09:20:00+02:00',
		items: [
			{ itemId: 'q1', type: 'textEntry', prompt: '3 x 4 =' },
			{ itemId: 'q2', type: 'textEntry', prompt: '2 x 2 =' },
		],
		responses: {},
	}
	const html = await renderComponent(
		inState(TimedTaskView, {
			attempt,
			receivedAt,
			now: receivedAt + 61000,
			answers: { q1: '12' },
		}),
		{
			collection: { id: 'studentTests', timedTask: block },
			app: 'learniq',
			api: fakeApi(),
			t,
			locale: 'en',
		},
	)
	assert.match(html, /aria-hidden="true">Time left: 18:59<\/p>/)
	assert.match(
		html,
		/<p class="sr-only" aria-live="polite">Time left: 18:00<\/p>/,
		'a screen reader hears whole minutes',
	)
	assert.match(html, /Question 1 of 2/)
	assert.match(html, /value="12"/)
})

test('when the clock reaches zero the saved answers are handed in and the screen says so', async () => {
	const api = fakeApi()
	const view = instance(TimedTaskView, {
		collection: { id: 'studentTests', timedTask: block },
		app: 'learniq',
		api,
		t,
		locale: 'en',
		initialTasks: [],
	})
	view.loadTasks = async () => {}
	await view.start({ taskId: 'assessment-1' })
	assert.equal(view.attempt.attemptId, 'attempt-1')
	view.answer('q1', 'A')

	view.now = view.receivedAt + 21 * 60 * 1000
	assert.equal(view.left, 0)
	TimedTaskView.watch.left.call(view, view.left)
	await new Promise((resolve) => setTimeout(resolve, 30))

	const ids = api.calls.map((c) => c.actionId)
	assert.ok(
		ids.indexOf('saveTestAnswer') < ids.indexOf('submitTest'),
		'the answer is saved before hand-in',
	)
	assert.equal(ids.filter((id) => id === 'submitTest').length, 1, 'handed in once')
	assert.equal(view.attempt, null)
	assert.equal(view.notice, 'Time is up. Your test has been handed in.')
	assert.deepEqual(
		view.emitted.map(([name]) => name),
		['changed'],
	)
	view.stopClock()
})
