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
 * A string field, or the fallback when the field is not a string.
 *
 * @param {unknown} value The field.
 * @param {string|null} fallback The value for a missing or mistyped field.
 * @return {string|null} The field or the fallback.
 */
function stringOr(value, fallback) {
	return typeof value === 'string' ? value : fallback
}

/**
 * Whether a start response names its attempt.
 *
 * @param {object} payload The leaf app's start response.
 * @return {boolean} Whether it carries a non-empty attempt id.
 */
function hasAttemptId(payload) {
	if (!payload) {
		return false
	}
	return typeof payload.attemptId === 'string' && payload.attemptId !== ''
}

/**
 * Whether an item names itself.
 *
 * @param {object} item A declared item.
 * @return {boolean} Whether it carries a non-empty item id.
 */
function hasItemId(item) {
	return item && typeof item.itemId === 'string' && item.itemId !== ''
}

/**
 * One declared item in the shape the screen renders.
 *
 * @param {object} item A declared item with an id.
 * @return {object} The normalised item.
 */
function normaliseItem(item) {
	return {
		itemId: item.itemId,
		type: stringOr(item.type, 'text'),
		prompt: stringOr(item.prompt, ''),
		points: typeof item.points === 'number' ? item.points : null,
		choices: options(item.choices),
		sources: options(item.sources),
		targets: options(item.targets),
	}
}

/**
 * The attempt's items that have an id, normalised.
 *
 * @param {object} payload The leaf app's start response.
 * @return {Array<object>} The items.
 */
function attemptItems(payload) {
	return (Array.isArray(payload.items) ? payload.items : [])
		.filter(hasItemId)
		.map(normaliseItem)
}

/**
 * The answers the attempt already saved, or none.
 *
 * @param {object} payload The leaf app's start response.
 * @return {object} The saved responses by item id.
 */
function savedResponses(payload) {
	return payload.responses && typeof payload.responses === 'object'
		? payload.responses
		: {}
}

/**
 * Sanitise a `start` response into the attempt the screen runs. Items without
 * an id are dropped; unknown types keep their type and render as text.
 *
 * @param {object} payload The leaf app's start response.
 * @return {object|null} The attempt, or null when it has no id.
 */
export function normaliseAttempt(payload) {
	if (!hasAttemptId(payload)) {
		return null
	}
	const items = attemptItems(payload)
	const responses = savedResponses(payload)
	return {
		attemptId: payload.attemptId,
		title: stringOr(payload.title, ''),
		serverNow: stringOr(payload.serverNow, null),
		deadlineAt: stringOr(payload.deadlineAt, null),
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
 * Whether an order answer puts at least one option in place.
 *
 * @param {Array<string>} value The current answer.
 * @return {boolean} Answered or not.
 */
function isOrdered(value) {
	return Array.isArray(value) && value.length > 0
}

/**
 * Whether a match answer pairs at least one source.
 *
 * @param {Object<string, string>} value The current answer.
 * @return {boolean} Answered or not.
 */
function isMatched(value) {
	return (
		value !== null
		&& typeof value === 'object'
		&& Object.keys(value).length > 0
	)
}

/**
 * Whether a text answer holds more than whitespace.
 *
 * @param {string} value The current answer.
 * @return {boolean} Answered or not.
 */
function isWritten(value) {
	return typeof value === 'string' && value.trim() !== ''
}

/** How each control with its own answer shape tells an answer apart. */
const ANSWERED_BY_SHAPE = {
	order: isOrdered,
	match: isMatched,
}

/**
 * Whether an item has an answer worth counting.
 *
 * @param {object} item A normalised item.
 * @param {string|Array<string>|Object<string, string>} value The current answer.
 * @return {boolean} Answered or not.
 */
export function isAnswered(item, value) {
	const answered = ANSWERED_BY_SHAPE[renderAs(item)] || isWritten
	return answered(value)
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
	 * Send one answer; a save that throws counts as no answer.
	 *
	 * @param {string} itemId The question.
	 * @param {string|Array<string>|Object<string, string>} value The answer.
	 * @return {Promise<object|null>} The save's outcome, or null.
	 */
	async function sendOne(itemId, value) {
		try {
			return await send(itemId, value)
		} catch {
			return null
		}
	}

	/**
	 * Close the queue when the attempt is closed (409).
	 *
	 * @param {object|null} result The save's outcome.
	 */
	function closeWhenRefused(result) {
		if (result && result.status === 409) {
			closed = true
		}
	}

	/**
	 * Mark a question saved or failed, unless a newer answer is waiting.
	 *
	 * @param {string} itemId The question.
	 * @param {object|null} result The save's outcome.
	 */
	function settle(itemId, result) {
		if (waiting.has(itemId)) {
			return
		}
		if (result && result.ok) {
			unsaved.delete(itemId)
			failed.delete(itemId)
			return
		}
		failed.add(itemId)
	}

	/**
	 * Send everything waiting, oldest question first.
	 */
	async function run() {
		while (waiting.size > 0 && !closed) {
			const [itemId, value] = waiting.entries().next().value
			waiting.delete(itemId)
			const result = await sendOne(itemId, value)
			closeWhenRefused(result)
			settle(itemId, result)
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
 * The start call's body: the task, and the access code when there is one.
 *
 * @param {{taskId: string, accessCode?: string}} start The task to start.
 * @return {{taskId: string, accessCode?: string}} The body.
 */
function startBody(start) {
	const { taskId, accessCode = '' } = start
	return accessCode ? { taskId, accessCode } : { taskId }
}

/**
 * A refused start, carrying the leaf app's error and message.
 *
 * @param {{body?: object}} result The start call's outcome.
 * @return {{ok: false, error: string, message: string}} The outcome.
 */
function startRefusal(result) {
	return {
		ok: false,
		error: result.body?.error || '',
		message: result.body?.message || '',
	}
}

/**
 * Start (or resume) a task and return the sanitised attempt.
 *
 * @param {{api: object, app: string, block: object}} actions The portal api adapter (`forwardAction`), the contributing app and the collection's `timedTask` block.
 * @param {{taskId: string, accessCode?: string}} start The task to start, and the access code when the task asks for one.
 * @return {Promise<{ok: boolean, attempt?: object, error?: string, message?: string}>} The outcome.
 */
export async function startAttempt(actions, start) {
	const body = startBody(start)
	const result = await actions.api.forwardAction(actions.app, actions.block.start, body)
	const attempt = result.ok ? normaliseAttempt(result.body) : null
	if (!attempt) {
		return startRefusal(result)
	}
	return { ok: true, attempt }
}

/**
 * Flush unsaved answers, then hand the attempt in.
 *
 * @param {{api: object, app: string, block: object}} actions The portal api adapter (`forwardAction`), the contributing app and the collection's `timedTask` block.
 * @param {{attemptId: string, queue: {flush: () => Promise<boolean>}}} handIn The attempt and its save queue.
 * @return {Promise<{ok: boolean, allSaved: boolean}>} The outcome.
 */
export async function submitAttempt(actions, handIn) {
	const allSaved = await handIn.queue.flush()
	const result = await actions.api.forwardAction(actions.app, actions.block.submit, {
		attemptId: handIn.attemptId,
	})
	// Already handed in (by the deadline, or another tab) is handed in.
	return { ok: result.ok || result.status === 409, allSaved }
}
