<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  PageGridEditor: the canvas and the inspector of the page editor.

  The ONE editing surface the admin designer and the portal edit mode mount.
  It renders an editor controller (createPageEditor() in ./pageEditor.js) and
  calls its actions; it holds no grid state of its own, so a host cannot drift
  from the other host by keeping a copy.

  THE GRID IS THE FLEET'S GRID. `CnDashboardGrid` carries the drag and resize
  engine, the collision handling and the keyboard repositioning every dashboard
  uses, and its item shape is the manifest-v2 widget entry a page stores.

  A MARKDOWN PAGE IS NOT A GRID. It is shown as markdown, with no grid actions,
  so nothing here can turn it into a grid page by accident (REQ-PIE-001).

  @spec openspec/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
-->
<template>
	<div class="page-grid-editor">
		<div
			v-if="state.kind === 'markdown'"
			class="page-grid-editor__markdown"
			data-testid="editor-markdown">
			<NcNoteCard type="info">
				{{
					t(
						'portaliq',
						'This is a markdown page. The layout editor places widgets, so it shows the markdown as it is and keeps it when you save or publish. Change the text in the page details.',
					)
				}}
			</NcNoteCard>
			<pre
				class="page-grid-editor__source"
				data-testid="editor-markdown-source"
				>{{ state.markdown }}</pre>
		</div>

		<div v-else class="page-grid-editor__panes">
			<section class="page-grid-editor__canvas" data-testid="designer-canvas">
				<CnDashboardGrid
					v-if="state.widgets.length"
					:layout="state.widgets"
					:editable="true"
					:columns="12"
					:itemLabel="itemLabel"
					@layoutChange="editor.applyLayout"
					@itemActivate="onActivate">
					<template #widget="{ item }">
						<!--
							A POINTER AFFORDANCE OVER AN ALREADY KEYBOARD-OPERABLE
							CONTROL. The grid item around this cell is the tab stop:
							`CnDashboardGrid` makes it focusable, moves it with the
							arrow keys and emits `item-activate` on Enter or Space,
							which selects it here. So this element is tabindex -1:
							a second tab stop per widget would double the stops.
						-->
						<div
							class="page-grid-editor__cell"
							:class="{
								'page-grid-editor__cell--selected':
									item.id === state.selectedId,
								'page-grid-editor__cell--warned': !isPublic(
									item.widgetKey,
								),
							}"
							role="button"
							tabindex="-1"
							:aria-pressed="item.id === state.selectedId"
							:aria-label="
								t('portaliq', 'Select the {key} widget', {
									key: item.widgetKey,
								})
							"
							:data-testid="`designer-widget-${item.id}`"
							:data-widget-key="item.widgetKey"
							@click="editor.select(item.id)"
							@keydown.enter.prevent="editor.select(item.id)"
							@keydown.space.prevent="editor.select(item.id)">
							<header class="page-grid-editor__cell-bar">
								<span class="page-grid-editor__cell-key">{{
									item.widgetKey
								}}</span>
								<NcButton
									variant="tertiary"
									:aria-label="t('portaliq', 'Remove this widget')"
									:data-testid="`designer-remove-${item.id}`"
									@click.stop="editor.removeWidget(item.id)">
									✕
								</NcButton>
							</header>

							<!--
								Previewed with the SAME resolution the public renderer
								uses, so a placeholder here means a placeholder there.
							-->
							<div class="page-grid-editor__cell-body">
								<component
									:is="previewFor(item.widgetKey)"
									v-if="previewFor(item.widgetKey)"
									v-bind="previewProps(item)" />
								<p
									v-else
									class="page-grid-editor__placeholder"
									:data-testid="`designer-placeholder-${item.id}`">
									{{
										t(
											'portaliq',
											'This widget is not shown on a public page.',
										)
									}}
								</p>
							</div>
						</div>
					</template>
				</CnDashboardGrid>

				<p
					v-else
					class="page-grid-editor__hint"
					data-testid="designer-empty">
					{{
						t(
							'portaliq',
							'This page has no widgets yet. Add one to start.',
						)
					}}
				</p>
			</section>

			<aside
				class="page-grid-editor__inspector"
				data-testid="designer-inspector">
				<h3>{{ t('portaliq', 'Widget') }}</h3>
				<p v-if="!selected" class="page-grid-editor__hint">
					{{
						t(
							'portaliq',
							'Select a widget on the page to edit its content.',
						)
					}}
				</p>

				<template v-else>
					<p class="page-grid-editor__hint">
						<code>{{ selected.widgetKey }}</code>
					</p>

					<!-- The shared form, as every dashboard configures this widget. -->
					<component
						:is="sharedForm"
						v-if="mode === 'form'"
						:key="selected.id"
						:editingWidget="formWidget"
						data-testid="designer-shared-form"
						@update:content="onFormContent" />

					<template v-else-if="mode === 'fields'">
						<div
							v-for="field in fields"
							:key="field.name"
							class="page-grid-editor__field">
							<label :for="`field-${field.name}`">{{
								field.label
							}}</label>
							<textarea
								v-if="field.kind === 'text' || field.kind === 'json'"
								:id="`field-${field.name}`"
								class="page-grid-editor__input"
								rows="6"
								:data-testid="`designer-field-${field.name}`"
								:value="fieldValue(field)"
								@input="onFieldInput(field, $event.target.value)" />
							<input
								v-else-if="field.kind === 'boolean'"
								:id="`field-${field.name}`"
								type="checkbox"
								:data-testid="`designer-field-${field.name}`"
								:checked="Boolean(selected.props[field.name])"
								@change="
									editor.setProp(field.name, $event.target.checked)
								" />
							<input
								v-else
								:id="`field-${field.name}`"
								class="page-grid-editor__input"
								:type="field.kind === 'number' ? 'number' : 'text'"
								:data-testid="`designer-field-${field.name}`"
								:value="fieldValue(field)"
								@input="onFieldInput(field, $event.target.value)" />
						</div>
					</template>

					<div v-else class="page-grid-editor__field">
						<label for="field-props-json">{{
							t('portaliq', 'Settings (JSON)')
						}}</label>
						<textarea
							id="field-props-json"
							class="page-grid-editor__input"
							rows="10"
							data-testid="designer-field-json"
							:value="propsJson"
							@change="onJsonInput($event.target.value)" />
					</div>

					<p v-if="jsonError" class="page-grid-editor__error" role="alert">
						{{ jsonError }}
					</p>
				</template>
			</aside>
		</div>
	</div>
</template>

<script>
import { CnDashboardGrid, dashboardWidgetRegistry } from '@conduction/nextcloud-vue'
import { translate } from '@nextcloud/l10n'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import {
	fieldsFor,
	isPublicWidget,
	previewComponentFor,
} from '../lib/pageWidgetCatalogue.js'
import { historyIntent } from './editHistory.js'
import {
	formWidgetFor,
	inspectorModeFor,
	propsFromFormContent,
	sharedFormFor,
} from './widgetForms.js'

export default {
	name: 'PageGridEditor',

	components: { CnDashboardGrid, NcButton, NcNoteCard },

	props: {
		/** The controller from createPageEditor(). */
		editor: {
			type: Object,
			required: true,
		},
	},

	data() {
		return {
			jsonError: '',
		}
	},

	computed: {
		/**
		 * @return {object} The editor's reactive state.
		 *
		 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
		 */
		state() {
			return this.editor.state
		},

		/**
		 * @return {object|null} The selected placement.
		 *
		 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
		 */
		selected() {
			return (
				this.state.widgets.find((w) => w.id === this.state.selectedId)
				|| null
			)
		},

		/**
		 * @return {Array<object>} The fields read from the selected widget's props.
		 *
		 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
		 */
		fields() {
			return this.selected ? fieldsFor(this.selected.widgetKey) : []
		},

		/**
		 * How the selected widget is configured: shared form, fields or JSON.
		 *
		 * @return {string} The mode.
		 * @spec openspec/specs/portal-page-designer/spec.md#requirement-a-widget-with-a-shared-configuration-form-must-be-configured-through-it-req-pie-003
		 */
		mode() {
			if (!this.selected) {
				return 'fields'
			}
			return inspectorModeFor(this.selected.widgetKey, {
				registry: dashboardWidgetRegistry,
				isPublic: isPublicWidget,
				fields: this.fields,
			})
		},

		/**
		 * @return {object|null} The shared form component.
		 *
		 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
		 */
		sharedForm() {
			return this.selected
				? sharedFormFor(this.selected.widgetKey, dashboardWidgetRegistry)
				: null
		},

		/**
		 * @return {object|null} The placement as the shared form reads it.
		 *
		 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
		 */
		formWidget() {
			return this.selected ? formWidgetFor(this.selected) : null
		},

		/**
		 * @return {string} The selected widget's props as JSON.
		 *
		 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
		 */
		propsJson() {
			return JSON.stringify(this.selected?.props || {}, null, 2)
		},
	},

	watch: {
		'state.selectedId': function () {
			this.jsonError = ''
		},
	},

	mounted() {
		window.addEventListener('keydown', this.onKeydown)
	},

	beforeUnmount() {
		window.removeEventListener('keydown', this.onKeydown)
	},

	methods: {
		/**
		 * Translate. Imported rather than taken from the page's globals, so the
		 * editor works the same in the admin app and on the portal.
		 *
		 * @param {string} app The app id.
		 * @param {string} text The source text.
		 * @param {object} vars The placeholders.
		 * @return {string} The translation.
		 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
		 */
		t(app, text, vars) {
			return translate(app, text, vars)
		},

		/**
		 * Ctrl+Z undoes, Ctrl+Shift+Z and Ctrl+Y redo, outside text fields.
		 *
		 * @param {KeyboardEvent} event The key event.
		 * @return {void}
		 * @spec openspec/specs/portal-page-designer/spec.md#requirement-editor-changes-must-be-undoable-and-redoable-req-pie-004
		 */
		onKeydown(event) {
			const intent = historyIntent(event)
			if (!intent || this.state.kind !== 'grid') {
				return
			}
			event.preventDefault()
			if (intent === 'undo') {
				this.editor.undo()
			} else {
				this.editor.redo()
			}
		},

		/**
		 * The grid item's accessible name.
		 *
		 * @param {object} item The placement.
		 * @return {string} The name.
		 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
		 */
		itemLabel(item) {
			return this.t('portaliq', 'Widget {key}', { key: item.widgetKey })
		},

		/**
		 * Select a placement from a keyboard activation.
		 *
		 * @param {object} payload The grid's activate payload.
		 * @return {void}
		 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
		 */
		onActivate(payload) {
			if (payload?.item?.id) {
				this.editor.select(payload.item.id)
			}
		},

		/**
		 * @param {string} key The widget key.
		 * @return {boolean} Whether the public site renders it.
		 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
		 */
		isPublic(key) {
			return isPublicWidget(key)
		},

		/**
		 * @param {string} key The widget key.
		 * @return {object|null} The preview component.
		 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
		 */
		previewFor(key) {
			return previewComponentFor(key)
		},

		/**
		 * The props a preview gets. `markdown` is remapped to `source`, as the
		 * site renderer does; data-backed props are left to the host.
		 *
		 * @param {object} widget The placement.
		 * @return {object} The props.
		 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
		 */
		previewProps(widget) {
			const props = widget.props || {}
			if (widget.widgetKey === 'markdown') {
				return { source: props.markdown || '' }
			}
			return props
		},

		/**
		 * @param {object} field The field.
		 * @return {string} The value in the control.
		 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
		 */
		fieldValue(field) {
			const value = this.selected?.props?.[field.name]
			if (field.kind === 'json') {
				return value === undefined ? '' : JSON.stringify(value, null, 2)
			}
			return value === undefined ? '' : String(value)
		},

		/**
		 * Take a typed value. A JSON field that does not parse is not written and
		 * says so: a broken string in `cards` renders as nothing on the page.
		 *
		 * @param {object} field The field.
		 * @param {string} raw The typed value.
		 * @return {void}
		 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
		 */
		onFieldInput(field, raw) {
			this.jsonError = ''
			if (field.kind === 'number') {
				this.editor.setProp(field.name, raw === '' ? undefined : Number(raw))
				return
			}
			if (field.kind !== 'json') {
				this.editor.setProp(field.name, raw)
				return
			}
			if (raw.trim() === '') {
				this.editor.setProp(field.name, undefined)
				return
			}
			try {
				this.editor.setProp(field.name, JSON.parse(raw))
			} catch {
				this.jsonError = this.t(
					'portaliq',
					'That value is not valid JSON, so it was not applied.',
				)
			}
		},

		/**
		 * Take the whole props object typed as JSON.
		 *
		 * @param {string} raw The typed JSON.
		 * @return {void}
		 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
		 */
		onJsonInput(raw) {
			this.jsonError = ''
			try {
				const parsed = JSON.parse(raw || '{}')
				if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
					throw new Error('not an object')
				}
				this.editor.replaceProps(parsed)
			} catch {
				this.jsonError = this.t(
					'portaliq',
					'That value is not valid JSON, so it was not applied.',
				)
			}
		},

		/**
		 * Take what a shared form emitted as the widget's props.
		 *
		 * @param {object} content The form content.
		 * @return {void}
		 * @spec openspec/specs/portal-page-designer/spec.md#requirement-a-widget-with-a-shared-configuration-form-must-be-configured-through-it-req-pie-003
		 */
		onFormContent(content) {
			this.editor.replaceProps(propsFromFormContent(content))
		},
	},
}
</script>

<style scoped>
.page-grid-editor__panes {
	display: flex;
	gap: 16px;
	align-items: flex-start;
}

.page-grid-editor__canvas {
	flex: 1 1 auto;
	min-width: 0;
}

.page-grid-editor__inspector {
	flex: 0 0 320px;
	max-width: 100%;
	border-inline-start: 1px solid var(--color-border);
	padding-inline-start: 16px;
}

.page-grid-editor__cell {
	height: 100%;
	display: flex;
	flex-direction: column;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	overflow: hidden;
}

.page-grid-editor__cell--selected {
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 2px var(--color-primary-element);
}

.page-grid-editor__cell--warned {
	border-style: dashed;
}

.page-grid-editor__cell-bar {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
	padding: 2px 4px 2px 8px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-background-hover);
}

.page-grid-editor__cell-key {
	font-family: monospace;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.page-grid-editor__cell-body {
	flex: 1 1 auto;
	overflow: auto;
	padding: 8px;
}

.page-grid-editor__placeholder {
	color: var(--color-text-maxcontrast);
	font-style: italic;
	margin: 0;
}

.page-grid-editor__hint {
	color: var(--color-text-maxcontrast);
}

.page-grid-editor__error {
	color: var(--color-error-text, var(--color-error));
}

.page-grid-editor__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 12px;
}

.page-grid-editor__input {
	width: 100%;
}

.page-grid-editor__source {
	white-space: pre-wrap;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
}

@media (max-width: 1024px) {
	.page-grid-editor__panes {
		flex-direction: column;
	}

	.page-grid-editor__inspector {
		border-inline-start: none;
		border-top: 1px solid var(--color-border);
		padding-inline-start: 0;
		padding-top: 16px;
		flex-basis: auto;
		width: 100%;
	}
}
</style>
