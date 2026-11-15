/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Undo and redo for the page editor: a capped stack of snapshots.
 *
 * A snapshot is whatever the editor hands in (the widgets and the selection);
 * this module never looks inside one. Typing in one field is coalesced into
 * one step by passing the same `coalesceKey` on consecutive records, so Ctrl+Z
 * undoes an edit, not a letter.
 *
 * @spec openspec/specs/portal-page-designer/spec.md#requirement-editor-changes-must-be-undoable-and-redoable-req-pie-004
 */

/** The most steps the editor can undo. */
export const HISTORY_LIMIT = 50

/**
 * A new, empty history.
 *
 * @param {{limit?: number}} options The cap.
 * @return {object} The history.
 * @spec openspec/specs/portal-page-designer/spec.md#requirement-editor-changes-must-be-undoable-and-redoable-req-pie-004
 */
export function createEditHistory({ limit = HISTORY_LIMIT } = {}) {
	let past = []
	let future = []
	let lastKey = null
	// The same key as the previous record, while there is one: the same step.
	const continuesStep = (coalesceKey) =>
		coalesceKey !== null && coalesceKey === lastKey && past.length

	return {
		/**
		 * Remember the state BEFORE a change.
		 *
		 * @param {object} snapshot The state before the change.
		 * @param {string|null} coalesceKey Same key as the previous record: same step.
		 * @return {void}
		 */
		record(snapshot, coalesceKey = null) {
			future = []
			if (continuesStep(coalesceKey)) {
				return
			}
			lastKey = coalesceKey
			past.push(snapshot)
			if (past.length > limit) {
				past = past.slice(past.length - limit)
			}
		},

		/**
		 * Step back.
		 *
		 * @param {object} current The state now, kept for redo.
		 * @return {object|null} The state to show, or null.
		 */
		undo(current) {
			if (!past.length) {
				return null
			}
			lastKey = null
			future.push(current)
			return past.pop()
		},

		/**
		 * Step forward again.
		 *
		 * @param {object} current The state now, kept for undo.
		 * @return {object|null} The state to show, or null.
		 */
		redo(current) {
			if (!future.length) {
				return null
			}
			lastKey = null
			past.push(current)
			return future.pop()
		},

		/** @return {boolean} Whether there is a step to undo. */
		canUndo() {
			return past.length > 0
		},

		/** @return {boolean} Whether there is a step to redo. */
		canRedo() {
			return future.length > 0
		},

		/** Forget everything: a load is a new baseline. @return {void} */
		reset() {
			past = []
			future = []
			lastKey = null
		},
	}
}

/** Input types that hold no typing, so the editor may take over their undo. */
const NON_TEXT_INPUT_TYPES = ['checkbox', 'radio', 'button', 'submit']

/** Elements whose own undo of the typing in them the editor leaves alone. */
const OWN_UNDO_TAGS = ['TEXTAREA', 'SELECT']

/** What z and y ask for, alone and with shift. */
const KEY_INTENTS = new Map([
	['z', { plain: 'undo', shifted: 'redo' }],
	['y', { plain: 'redo', shifted: null }],
])

/**
 * @param {KeyboardEvent|object|null} event The key event.
 * @return {boolean} Whether it is pressed with ctrl or cmd and without alt.
 */
function isCommandPress(event) {
	return Boolean(event) && (event.ctrlKey || event.metaKey) && !event.altKey
}

/**
 * @param {string} tag The focused element's tag, upper case.
 * @param {object} target The focused element.
 * @return {boolean} Whether it is an input that holds typing.
 */
function isTextInput(tag, target) {
	return (
		tag === 'INPUT'
		&& !NON_TEXT_INPUT_TYPES.includes(String(target.type || 'text'))
	)
}

/**
 * @param {object} target The focused element.
 * @return {boolean} Whether it is a text field with an undo of its own.
 */
function keepsOwnUndo(target) {
	const tag = String(target.tagName || '').toUpperCase()
	return (
		OWN_UNDO_TAGS.includes(tag)
		|| isTextInput(tag, target)
		|| Boolean(target.isContentEditable)
	)
}

/**
 * @param {KeyboardEvent|object} event A command key event.
 * @return {'undo'|'redo'|null} What its key asks for.
 */
function keyIntent(event) {
	const intents = KEY_INTENTS.get(String(event.key || '').toLowerCase())
	if (!intents) {
		return null
	}
	return event.shiftKey ? intents.shifted : intents.plain
}

/**
 * Whether a key press asks to undo or redo.
 *
 * Ignored while focus is in a text field: the browser's own undo of the typing
 * there is what the editor expects, and taking it over would undo a whole
 * widget change when they meant one word.
 *
 * @param {KeyboardEvent|object} event The key event.
 * @return {'undo'|'redo'|null} The intent.
 * @spec openspec/specs/portal-page-designer/spec.md#requirement-editor-changes-must-be-undoable-and-redoable-req-pie-004
 */
export function historyIntent(event) {
	if (!isCommandPress(event) || keepsOwnUndo(event.target || {})) {
		return null
	}
	return keyIntent(event)
}
