/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The editor's undo/redo, as a pure value.
 *
 * NO VUE, NO DOM, NO CLOCK. Undo is the feature an editor is judged by and the
 * one that goes wrong invisibly: a stack that keeps a REFERENCE rather than a
 * copy will happily undo to a state that has since been mutated underneath it,
 * and the bug shows up as "undo did nothing" three edits later, nowhere near
 * its cause. Keeping this a pure function over plain data is what lets it be
 * tested exhaustively.
 *
 * @spec openspec/changes/portal-page-composition/tasks.md
 */

/**
 * How many steps are remembered.
 *
 * Bounded because a page's regions are held in full at every step. Fifty is far
 * past what anyone undoes in one sitting and nowhere near enough memory to
 * matter.
 */
const LIMIT = 50

/**
 * A deep copy of a plain-data value.
 *
 * THE WHOLE POINT OF THE STACK. Pushing the live object would store a
 * reference: the next edit mutates it, every earlier entry changes with it, and
 * undo returns the present.
 *
 * A JSON ROUND-TRIP, NOT `structuredClone`, and that is not a preference.
 * `structuredClone` throws `DataCloneError` on a Vue reactive Proxy, which is
 * exactly what the editor hands this on every edit — the unit tests passed
 * because they pass plain objects, and the failure only appeared in a browser,
 * as an edit that silently did not commit and an undo button that stayed
 * disabled. Regions are JSON by construction, so the round-trip loses nothing
 * and unwraps the proxy on the way through.
 *
 * @param {*} value The value.
 * @return {*} A copy sharing nothing with the original.
 */
function copy(value) {
	return JSON.parse(JSON.stringify(value))
}

/**
 * A new, empty history around a starting state.
 *
 * @param {object} state The initial regions.
 * @return {object} The history.
 */
export function createHistory(state) {
	return { past: [], present: copy(state), future: [] }
}

/**
 * Record a new state.
 *
 * CLEARS THE FUTURE, which every editor does and which has to be deliberate:
 * after undoing twice and then editing, the two undone steps are gone. Keeping
 * them would produce a branching history no interface here can show.
 *
 * An identical state is NOT recorded. A drag that ends where it started, or a
 * field blur that changed nothing, would otherwise cost an undo step — and an
 * author pressing undo would appear to have pressed nothing.
 *
 * @param {object} history The history.
 * @param {object} state   The new state.
 * @return {object} The next history.
 */
export function record(history, state) {
	if (JSON.stringify(history.present) === JSON.stringify(state)) {
		return history
	}

	const past = [...history.past, copy(history.present)].slice(-LIMIT)
	return { past, present: copy(state), future: [] }
}

/**
 * Step back.
 *
 * @param {object} history The history.
 * @return {object} The next history, unchanged when there is nothing to undo.
 */
export function undo(history) {
	if (history.past.length === 0) {
		return history
	}

	const past = [...history.past]
	const present = past.pop()
	return {
		past,
		present,
		future: [copy(history.present), ...history.future].slice(0, LIMIT),
	}
}

/**
 * Step forward.
 *
 * @param {object} history The history.
 * @return {object} The next history, unchanged when there is nothing to redo.
 */
export function redo(history) {
	if (history.future.length === 0) {
		return history
	}

	const [present, ...future] = history.future
	return {
		past: [...history.past, copy(history.present)].slice(-LIMIT),
		present,
		future,
	}
}

/**
 * @param {object} history The history.
 * @return {boolean} Whether undo would do anything.
 */
export function canUndo(history) {
	return history.past.length > 0
}

/**
 * @param {object} history The history.
 * @return {boolean} Whether redo would do anything.
 */
export function canRedo(history) {
	return history.future.length > 0
}
