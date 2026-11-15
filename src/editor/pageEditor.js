/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The page editor: one controller for the admin designer and the portal edit
 * mode.
 *
 * It holds the page, the widgets being edited and the editor's status in one
 * `state` object, and offers every action as a method. Hosts render `state`
 * and call the methods; none of them keeps a copy of the grid or builds a
 * payload of its own, which is how the two hosts stay one implementation.
 *
 * `reactive` is handed in (Vue's in a component, the identity in a test), so
 * the controller needs no Vue to run and Vue still sees every change.
 *
 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
 */

import { restoredDraft } from '../lib/pageHistory.js'
import { createEditHistory, HISTORY_LIMIT } from './editHistory.js'
import {
	addWidget,
	addWidgetAt,
	applyLayout,
	cloneWidgets,
	removeWidget,
	replaceWidgetProps,
	setWidgetProp,
} from './gridModel.js'
import {
	bodyFor,
	discardPayload,
	draftPayload,
	publishPayload,
	readBody,
} from './pageBody.js'
import { isConflict } from './pageSaver.js'

const DEFAULT_SIZE = { gridWidth: 6, gridHeight: 4 }

/**
 * The state of an editor that has loaded nothing yet.
 *
 * @return {object} The state.
 */
function freshState() {
	return { ...freshContent(), ...freshStatus() }
}

/**
 * @return {object} What a loaded page fills in: the page, its body and the selection.
 */
function freshContent() {
	return {
		page: {},
		version: '',
		kind: 'grid',
		widgets: [],
		markdown: '',
		selectedId: '',
	}
}

/**
 * @return {object} Where the editing stands: loading, saving, the messages and the history.
 */
function freshStatus() {
	return {
		loading: true,
		saving: false,
		dirty: false,
		hasDraft: false,
		error: '',
		notice: '',
		conflict: null,
		canUndo: false,
		canRedo: false,
	}
}

/**
 * @param {object} state The editor's state.
 * @return {object} The part of the state undo restores.
 */
function snapshotOf(state) {
	return {
		widgets: cloneWidgets(state.widgets),
		selectedId: state.selectedId,
	}
}

/**
 * @param {{state: object, history: object}} editing The editor's state and its edit history.
 * @return {void}
 */
function syncHistoryOf(editing) {
	editing.state.canUndo = editing.history.canUndo()
	editing.state.canRedo = editing.history.canRedo()
}

/**
 * Apply a grid change, remembering the state before it. A page that is no
 * grid takes no grid change.
 *
 * @param {{state: object, history: object}} editing The editor's state and its edit history.
 * @param {{step: Function, coalesceKey: string|null}} edit The change, and its undo step's key.
 * @return {void}
 */
function applyChange(editing, edit) {
	const { state, history } = editing
	if (state.kind !== 'grid') {
		return
	}
	history.record(snapshotOf(state), edit.coalesceKey)
	edit.step()
	state.dirty = true
	state.notice = ''
	syncHistoryOf(editing)
}

/**
 * Take a loaded page into the state; a selection the page no longer holds
 * goes.
 *
 * @param {object} state The editor's state.
 * @param {{page: object, version: string}} loaded The page and its version.
 * @return {void}
 */
function takeLoaded(state, loaded) {
	const { page, version } = loaded
	const body = readBody(page)
	state.page = page
	state.version = version
	state.kind = body.kind
	state.widgets = body.widgets
	state.markdown = body.markdown
	state.hasDraft = Boolean(page.draftBody)
	state.dirty = false
	if (!state.widgets.some((w) => w.id === state.selectedId)) {
		state.selectedId = ''
	}
}

/**
 * A message for a failed request. A refusal is named as one: "saving failed"
 * over a 403 sends an editor looking for a bug in their content.
 *
 * @param {(app: string, text: string, vars?: object) => string} t The translate function.
 * @param {{error: unknown, fallback: string}} failure The error and the generic message.
 * @return {string} The message.
 */
function failureMessage(t, failure) {
	const status = failure.error?.response?.status
	if (status === 403 || status === 401) {
		return t(
			'portaliq',
			'You are not allowed to edit portal pages. An administrator sets the editor groups in the Portaliq admin settings.',
		)
	}
	if (status === 404) {
		return t('portaliq', 'This page no longer exists.')
	}
	return failure.fallback
}

/**
 * @param {(app: string, text: string, vars?: object) => string} t The translate function.
 * @param {{changedAt: string}} error The conflict.
 * @return {{conflict: object, error: string}} The state's changes: the conflict and its sentence.
 */
function conflictFailure(t, error) {
	return {
		conflict: { changedAt: error.changedAt },
		error: t(
			'portaliq',
			'Someone else saved this page after you opened it, so your changes were not saved. Reload the page to see their version, then make your changes again.',
		),
	}
}

/**
 * What a failed save puts in the state: a conflict with its sentence, or the
 * message for the refusal.
 *
 * @param {(app: string, text: string, vars?: object) => string} t The translate function.
 * @param {unknown} error The error.
 * @return {{conflict?: object, error: string}} The state's changes.
 */
function saveFailure(t, error) {
	if (isConflict(error)) {
		return conflictFailure(t, error)
	}
	return {
		error: failureMessage(t, {
			error,
			fallback: t('portaliq', 'Saving failed. Nothing was changed.'),
		}),
	}
}

/**
 * A new editor for one page.
 *
 * @param {object} deps The collaborators.
 * @param {object} deps.saver A page saver (createPageSaver()).
 * @param {string} deps.pageId The page id.
 * @param {Function} deps.t The translate function, `t(app, text, vars)`.
 * @param {Function} [deps.reactive] Wraps the state so a view sees changes.
 * @param {Function} [deps.defaultSizeFor] (key) => the first size of a widget.
 * @param {number} [deps.historyLimit] The undo cap.
 * @return {object} The editor.
 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
 */
export function createPageEditor({
	saver,
	pageId,
	t,
	reactive = (value) => value,
	defaultSizeFor = () => DEFAULT_SIZE,
	historyLimit = HISTORY_LIMIT,
}) {
	const history = createEditHistory({ limit: historyLimit })

	const state = reactive(freshState())

	/** @return {object} The part of the state undo restores. */
	const snapshot = () => snapshotOf(state)

	/** @return {void} */
	const syncHistory = () => syncHistoryOf({ state, history })

	/**
	 * Apply a grid change, remembering the state before it.
	 *
	 * @param {Function} step () => void, mutating state.
	 * @param {string|null} coalesceKey Same key as the previous change: same undo step.
	 * @return {void}
	 */
	const change = (step, coalesceKey = null) =>
		applyChange({ state, history }, { step, coalesceKey })

	/**
	 * @param {unknown} error The error.
	 * @param {string} fallback The generic message.
	 * @return {string} The message for a failed request.
	 */
	const messageFor = (error, fallback) => failureMessage(t, { error, fallback })

	const editor = {
		state,

		/**
		 * Load the page. A load is a new baseline: the history starts over.
		 *
		 * @return {Promise<void>} Resolves when loaded.
		 */
		async load() {
			state.loading = true
			state.error = ''
			state.conflict = null
			try {
				takeLoaded(state, await saver.load(pageId))
				history.reset()
				syncHistory()
			} catch (error) {
				state.error = messageFor(
					error,
					t('portaliq', 'This page could not be loaded.'),
				)
			} finally {
				state.loading = false
			}
		},

		/**
		 * The selected placement, or null.
		 *
		 * @return {object|null} The placement.
		 */
		selected() {
			return state.widgets.find((w) => w.id === state.selectedId) || null
		},

		/**
		 * Select a placement.
		 *
		 * @param {string} id The placement id.
		 * @return {void}
		 */
		select(id) {
			state.selectedId = id
		},

		/**
		 * Place a widget below everything and select it.
		 *
		 * @param {string} key The widget key.
		 * @return {void}
		 */
		addWidget(key) {
			change(() => {
				const result = addWidget(state.widgets, key, defaultSizeFor(key))
				state.widgets = result.widgets
				state.selectedId = result.id
			})
		},

		/**
		 * Place a widget at the cell it was dropped on, and select it.
		 *
		 * The same action as `addWidget` in every other respect, including the
		 * selection and the undo entry: a drop and a key press differ in where
		 * the widget lands, not in what placing one means
		 * (site-nlds-widget-palette REQ-SNW-002).
		 *
		 * @param {string} key The widget key.
		 * @param {{gridX: number, gridY: number}} cell Where it was dropped.
		 * @return {void}
		 */
		addWidgetAt(key, cell) {
			change(() => {
				const result = addWidgetAt(
					state.widgets,
					key,
					defaultSizeFor(key),
					cell,
				)
				state.widgets = result.widgets
				state.selectedId = result.id
			})
		},

		/**
		 * Remove a placement.
		 *
		 * @param {string} id The placement id.
		 * @return {void}
		 */
		removeWidget(id) {
			if (!state.widgets.some((w) => w.id === id)) {
				return
			}
			change(() => {
				state.widgets = removeWidget(state.widgets, id)
				if (state.selectedId === id) {
					state.selectedId = ''
				}
			})
		},

		/**
		 * Take the grid's new geometry. A layout that moves nothing (the grid
		 * reports one on mount) is not a change and not an undo step.
		 *
		 * @param {Array<object>} layout The grid's items.
		 * @return {void}
		 */
		applyLayout(layout) {
			const result = applyLayout(state.widgets, layout)
			if (!result.changed) {
				return
			}
			change(() => {
				state.widgets = result.widgets
			})
		},

		/**
		 * Write one prop on the selected placement. Consecutive writes to the
		 * same prop are one undo step.
		 *
		 * @param {string} name The prop.
		 * @param {*} value The value; undefined deletes it.
		 * @return {void}
		 */
		setProp(name, value) {
			const id = state.selectedId
			if (!id) {
				return
			}
			change(() => {
				state.widgets = setWidgetProp(state.widgets, id, name, value)
			}, `prop:${id}:${name}`)
		},

		/**
		 * Replace the selected placement's props, as a shared form hands them over.
		 *
		 * @param {object} props The props.
		 * @return {void}
		 */
		replaceProps(props) {
			const id = state.selectedId
			if (!id) {
				return
			}
			change(() => {
				state.widgets = replaceWidgetProps(state.widgets, id, props)
			}, `form:${id}`)
		},

		/**
		 * Change a page field outside the body (an image from the media library).
		 * Not an undo step: it is not part of the grid.
		 *
		 * @param {Function} update (page) => the new page.
		 * @return {void}
		 */
		updatePage(update) {
			state.page = update(state.page)
			state.dirty = true
			state.notice = ''
		},

		/** Undo the last change. @return {void} */
		undo() {
			const previous = history.undo(snapshot())
			if (previous) {
				state.widgets = previous.widgets
				state.selectedId = previous.selectedId
				state.dirty = true
				state.notice = ''
			}
			syncHistory()
		},

		/** Redo what was undone. @return {void} */
		redo() {
			const next = history.redo(snapshot())
			if (next) {
				state.widgets = next.widgets
				state.selectedId = next.selectedId
				state.dirty = true
				state.notice = ''
			}
			syncHistory()
		},

		/**
		 * Save the editor's body as the draft. The published page is unchanged.
		 *
		 * @return {Promise<void>} Resolves when saved or refused.
		 */
		saveDraft() {
			return editor.write(
				draftPayload(state.page, bodyFor(state)),
				t('portaliq', 'Draft saved. The published page is unchanged.'),
			)
		},

		/**
		 * Publish the editor's body, whatever its type, and clear the draft.
		 *
		 * @return {Promise<void>} Resolves when published or refused.
		 */
		publish() {
			return editor.write(
				publishPayload(state.page, bodyFor(state)),
				t('portaliq', 'Published.'),
			)
		},

		/**
		 * Throw the draft away; the published body stays as it was.
		 *
		 * @return {Promise<void>} Resolves when discarded or refused.
		 */
		discard() {
			return editor.write(
				discardPayload(state.page),
				t('portaliq', 'Draft discarded.'),
			)
		},

		/**
		 * Put an earlier published version in the draft.
		 *
		 * @param {object} version A version from the page history.
		 * @return {Promise<void>} Resolves when written or refused.
		 */
		restore(version) {
			return editor.write(
				restoredDraft(state.page, version),
				t(
					'portaliq',
					'The version is in the draft. The live page changes when you publish.',
				),
			)
		},

		/**
		 * Write the page and reload what was stored.
		 *
		 * Reloading rather than trusting the local copy shows the editor what
		 * OpenRegister kept. A conflict does NOT reload: the unsaved work stays
		 * on screen, and the editor chooses to reload.
		 *
		 * @param {object} payload The page to store.
		 * @param {string} notice The message on success.
		 * @return {Promise<void>} Resolves when written or refused.
		 */
		async write(payload, notice) {
			state.saving = true
			state.error = ''
			state.notice = ''
			state.conflict = null
			try {
				await saver.save(pageId, payload, state.version)
				await editor.load()
				state.notice = notice
			} catch (error) {
				Object.assign(state, saveFailure(t, error))
			} finally {
				state.saving = false
			}
		},
	}

	return editor
}
