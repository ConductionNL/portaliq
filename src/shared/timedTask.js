// SPDX-License-Identifier: EUPL-1.2
//
// The logic of a timed task in the portal (portal-take-assessment): the clock,
// the attempt payload, the answer shapes and the save queue. Pure and
// React-free, so node --test can pin it; TimedTaskView only wires it.
//
// The leaf app keeps every rule. This module only makes sure the portal shows
// the server's deadline (not the client clock's idea of it), sends each
// question's latest answer once and in order, and flushes before submit.

/** The item types the portal renders with their own control. */
export const SUPPORTED_TYPES = [
	'choice',
	'inlineChoice',
	'textEntry',
	'extendedText',
	'order',
	'match',
]

/**
 * The control an item gets: its own type when supported, else a text answer.
 *
 * @param {object} item A normalised item.
 * @return {string} The type to render.
 */
export function renderAs(item) {
	return SUPPORTED_TYPES.includes(item.type) ? item.type : 'text'
}

/**
 * Keep only well-formed `{id, label}` options.
 *
 * @param {Array} list The declared options.
 * @return {Array<{id: string, label: string}>} The options.
 */
function options(list) {
	if (!Array.isArray(list)) {
		return []
	}
	return list
		.filter((o) => o && typeof o.id === 'string' && o.id !== '')
		.map((o) => ({
			id: o.id,
			label: typeof o.label === 'string' ? o.label : o.id,
		}))
}

/**
 * Sanitise a `start` response into the attempt the screen runs. Items without
 * an id are dropped; unknown types keep their type and render as text.
 *
 * @param {object} payload The leaf app's start response.
 * @return {object|null} The attempt, or null when it has no id.
 */
export function normaliseAttempt(payload) {
	if (
		!payload
		|| typeof payload.attemptId !== 'string'
		|| payload.attemptId === ''
	) {
		return null
	}
	const items = (Array.isArray(payload.items) ? payload.items : [])
		.filter(
			(item) => item && typeof item.itemId === 'string' && item.itemId !== '',
		)
		.map((item) => ({
			itemId: item.itemId,
			type: typeof item.type === 'string' ? item.type : 'text',
			prompt: typeof item.prompt === 'string' ? item.prompt : '',
			points: typeof item.points === 'number' ? item.points : null,
			choices: options(item.choices),
			sources: options(item.sources),
			targets: options(item.targets),
		}))
	const responses =
		payload.responses && typeof payload.responses === 'object'
			? payload.responses
			: {}
	return {
		attemptId: payload.attemptId,
		title: typeof payload.title === 'string' ? payload.title : '',
		serverNow: typeof payload.serverNow === 'string' ? payload.serverNow : null,
		deadlineAt:
			typeof payload.deadlineAt === 'string' ? payload.deadlineAt : null,
		items,
		responses: { ...responses },
	}
}

/**
 * The answer an item starts with: the saved one, else an empty answer of the
 * right shape (the given order for an order item, no pairs for a match).
 *
 * @param {object} item A normalised item.
 * @param {string|Array<string>|Object<string, string>} saved The saved response, if any.
 * @return {string|Array<string>|Object<string, string>} The answer.
 */
export function initialResponse(item, saved) {
	if (saved !== undefined && saved !== null) {
		return saved
	}
	const as = renderAs(item)
	if (as === 'order') {
		return item.choices.map((c) => c.id)
	}
	if (as === 'match') {
		return {}
	}
	return ''
}

/**
 * Whether an item has an answer worth counting.
 *
 * @param {object} item A normalised item.
 * @param {string|Array<string>|Object<string, string>} value The current answer.
 * @return {boolean} Answered or not.
 */
export function isAnswered(item, value) {
	const as = renderAs(item)
	if (as === 'order') {
		return Array.isArray(value) && value.length > 0
	}
	if (as === 'match') {
		return (
			value !== null
			&& typeof value === 'object'
			&& Object.keys(value).length > 0
		)
	}
	return typeof value === 'string' && value.trim() !== ''
}

/**
 * Move one option in an order answer up (-1) or down (+1).
 *
 * @param {string[]} order The current order.
 * @param {number} index The option's position.
 * @param {number} step -1 or +1.
 * @return {string[]} The new order.
 */
export function move(order, index, step) {
	const target = index + step
	if (target < 0 || target >= order.length) {
		return order
	}
	const next = [...order]
	const [item] = next.splice(index, 1)
	next.splice(target, 0, item)
	return next
}

/**
 * Seconds left until the server's deadline, measured from the client moment
 * the payload arrived so a wrong client clock does not shift it. Null for an
 * untimed attempt; never negative.
 *
 * @param {string|null} deadlineAt The server deadline.
 * @param {string|null} serverNow The server time in the same payload.
 * @param {number} receivedAtMs Client clock (ms) when the payload arrived.
 * @param {number} nowMs Client clock (ms) now.
 * @return {number|null} Seconds left.
 */
export function secondsLeft(deadlineAt, serverNow, receivedAtMs, nowMs) {
	if (!deadlineAt) {
		return null
	}
	const deadline = Date.parse(deadlineAt)
	const server = serverNow ? Date.parse(serverNow) : receivedAtMs
	if (Number.isNaN(deadline) || Number.isNaN(server)) {
		return null
	}
	const leftAtReceipt = (deadline - server) / 1000
	const elapsed = (nowMs - receivedAtMs) / 1000
	return Math.max(0, Math.floor(leftAtReceipt - elapsed))
}

/**
 * Seconds as `MM:SS`, or `H:MM:SS` from an hour up.
 *
 * @param {number} seconds Seconds left.
 * @return {string} The clock text.
 */
export function formatClock(seconds) {
	const s = Math.max(0, Math.floor(seconds))
	const pad = (n) => String(n).padStart(2, '0')
	const hours = Math.floor(s / 3600)
	const minutes = Math.floor((s % 3600) / 60)
	const rest = s % 60
	return hours > 0
		? `${hours}:${pad(minutes)}:${pad(rest)}`
		: `${pad(minutes)}:${pad(rest)}`
}

/**
 * A queue that saves each question's latest answer, one request at a time.
 * A value set while an earlier one is in flight replaces what is waiting; a
 * question counts as unsaved until its latest value is acknowledged. A 409
 * `attempt_closed` marks the queue closed: time is up or the attempt is in.
 *
 * @param {(itemId: string, value: string|Array<string>|Object<string, string>) => Promise<{ok: boolean, status?: number, error?: string}>} send Saves one answer.
 * @param {(state: object) => void} [onState] Receives `{unsaved, failed, closed}` on every change.
 * @return {{set: (itemId: string, value: string|Array<string>|Object<string, string>) => Promise<void>, flush: () => Promise<boolean>, state: () => object}} The queue.
 */
export function createSaveQueue(send, onState = () => {}) {
	const waiting = new Map()
	const latest = new Map()
	const unsaved = new Set()
	const failed = new Set()
	let closed = false
	let running = null

	const state = () => ({ unsaved: [...unsaved], failed: [...failed], closed })

	/**
	 * Send everything waiting, oldest question first.
	 */
	async function run() {
		while (waiting.size > 0 && !closed) {
			const [itemId, value] = waiting.entries().next().value
			waiting.delete(itemId)
			let result
			try {
				result = await send(itemId, value)
			} catch {
				result = null
			}
			if (result && result.status === 409) {
				closed = true
			}
			if (!waiting.has(itemId)) {
				if (result && result.ok) {
					unsaved.delete(itemId)
					failed.delete(itemId)
				} else {
					failed.add(itemId)
				}
			}
			onState(state())
		}
		running = null
	}

	/**
	 * Queue a question's latest answer.
	 *
	 * @param {string} itemId The question.
	 * @param {string|Array<string>|Object<string, string>} value The answer.
	 * @return {Promise<void>} Resolves when the queue is idle.
	 */
	function set(itemId, value) {
		latest.set(itemId, value)
		waiting.set(itemId, value)
		unsaved.add(itemId)
		failed.delete(itemId)
		onState(state())
		if (!running && !closed) {
			running = run()
		}
		return running || Promise.resolve()
	}

	/**
	 * Wait for the queue, then retry every failed question once.
	 *
	 * @return {Promise<boolean>} Whether every answer is saved.
	 */
	async function flush() {
		if (running) {
			await running
		}
		if (!closed && failed.size > 0) {
			for (const itemId of [...failed]) {
				waiting.set(itemId, latest.get(itemId))
			}
			failed.clear()
			running = run()
			await running
		}
		return unsaved.size === 0
	}

	return { set, flush, state }
}

/**
 * Start (or resume) a task and return the sanitised attempt.
 *
 * @param {object} api The portal api adapter (`forwardAction`).
 * @param {string} app The contributing app.
 * @param {object} block The collection's `timedTask` block.
 * @param {string} taskId The task to start.
 * @param {string} [accessCode] The access code, when the task asks for one.
 * @return {Promise<{ok: boolean, attempt?: object, error?: string, message?: string}>} The outcome.
 */
export async function startAttempt(api, app, block, taskId, accessCode = '') {
	const body = accessCode ? { taskId, accessCode } : { taskId }
	const result = await api.forwardAction(app, block.start, body)
	const attempt = result.ok ? normaliseAttempt(result.body) : null
	if (!attempt) {
		return {
			ok: false,
			error: result.body?.error || '',
			message: result.body?.message || '',
		}
	}
	return { ok: true, attempt }
}

/**
 * Flush unsaved answers, then hand the attempt in.
 *
 * @param {object} api The portal api adapter (`forwardAction`).
 * @param {string} app The contributing app.
 * @param {object} block The collection's `timedTask` block.
 * @param {string} attemptId The attempt.
 * @param {{flush: () => Promise<boolean>}} queue The attempt's save queue.
 * @return {Promise<{ok: boolean, allSaved: boolean}>} The outcome.
 */
export async function submitAttempt(api, app, block, attemptId, queue) {
	const allSaved = await queue.flush()
	const result = await api.forwardAction(app, block.submit, { attemptId })
	// Already handed in (by the deadline, or another tab) is handed in.
	return { ok: result.ok || result.status === 409, allSaved }
}
