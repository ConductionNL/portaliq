/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The page editor module: the ONE implementation the admin designer
 * (`src/views/PageLayoutDesigner.vue`) and the portal edit mode share.
 *
 * Import from this file only. Everything here is plain JavaScript that runs
 * under `node --test` (tests/page-editor.spec.mjs); the one Vue component,
 * `PageGridEditor.vue`, is imported by path because a `.vue` import here would
 * stop node from loading the module.
 *
 * Public API:
 *
 * - `createPageEditor({saver, pageId, t, reactive?, defaultSizeFor?, historyLimit?})`
 *   The controller. `editor.state` holds `page, version, kind ('grid'|'markdown'),
 *   widgets, markdown, selectedId, loading, saving, dirty, hasDraft, error, notice,
 *   conflict, canUndo, canRedo`. Actions: `load, select, selected, addWidget,
 *   removeWidget, applyLayout, setProp, replaceProps, updatePage, undo, redo,
 *   saveDraft, publish, discard, restore, write`.
 * - `createPageSaver({get, put, url})`: `load(id)`, `save(id, payload, version)`
 *   with the version check; `PageConflictError`, `isConflict()`.
 * - `createEditHistory({limit?})`, `HISTORY_LIMIT`, `historyIntent(event)`.
 * - Grid model: `GRID_COLUMNS, normaliseWidgets, storedWidget, nextWidgetId,
 *   addWidget, removeWidget, applyLayout, setWidgetProp, replaceWidgetProps,
 *   cloneWidgets`.
 * - Bodies and payloads: `readBody, bodyFor, draftPayload, publishPayload,
 *   discardPayload, versionOf, withoutEnvelope`.
 * - Shared forms: `sharedFormFor, inspectorModeFor, formWidgetFor,
 *   propsFromFormContent`.
 *
 * @spec openspec/changes/portal-in-place-editing/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
 */

export { createEditHistory, HISTORY_LIMIT, historyIntent } from './editHistory.js'
export {
	addWidget,
	applyLayout,
	cloneWidgets,
	GRID_COLUMNS,
	nextWidgetId,
	normaliseWidgets,
	removeWidget,
	replaceWidgetProps,
	setWidgetProp,
	storedWidget,
} from './gridModel.js'
export {
	bodyFor,
	discardPayload,
	draftPayload,
	publishPayload,
	readBody,
	versionOf,
	withoutEnvelope,
} from './pageBody.js'
export { createPageEditor } from './pageEditor.js'
export { createPageSaver, isConflict, PageConflictError } from './pageSaver.js'
export {
	formWidgetFor,
	inspectorModeFor,
	propsFromFormContent,
	sharedFormFor,
} from './widgetForms.js'
