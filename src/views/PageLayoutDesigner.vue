<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  PageLayoutDesigner — direct-manipulation editing of a portal page's widget
  grid.

  THE GRID IS THE FLEET'S GRID. `CnDashboardGrid` already carries the
  drag/resize engine, the collision handling and the keyboard repositioning
  every dashboard in the fleet uses, and its layout item shape — `{id, gridX,
  gridY, gridWidth, gridHeight, ...}` — is byte-for-byte the manifest-v2
  widgetEntry a portal page already stores. So no mapping layer exists here,
  deliberately: a translation between two shapes that are already the same is a
  place for them to drift apart.

  WRITES GO STRAIGHT TO OPENREGISTER (ADR-022). There is no Portaliq controller
  in front of them, which is exactly why the `page` schema carries the editor
  groups in its authorization block — see lib/Service/PageEditorService.php.
  A refusal here is OpenRegister refusing, not this view deciding.

  @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-a-pages-widget-grid-must-be-editable-by-direct-manipulation
-->
<template>
	<div class="designer">
		<header class="designer__bar">
			<div class="designer__identity">
				<h2 class="designer__title" data-testid="designer-title">
					{{ page.title || t('portaliq', 'Page') }}
				</h2>
				<p class="designer__route">
					<code>{{ page.route }}</code>
					<span class="designer__status" data-testid="designer-status">{{
						page.status
					}}</span>
					<span
						v-if="hasDraft"
						class="designer__draft"
						data-testid="designer-has-draft">
						{{ t('portaliq', 'unpublished draft') }}
					</span>
				</p>
			</div>

			<div class="designer__actions">
				<NcButton
					data-testid="designer-add-widget"
					:disabled="loading"
					@click="paletteOpen = true">
					{{ t('portaliq', 'Add widget') }}
				</NcButton>
				<NcButton
					data-testid="designer-save-draft"
					:disabled="loading || saving"
					@click="saveDraft">
					{{ t('portaliq', 'Save draft') }}
				</NcButton>
				<NcButton
					variant="primary"
					data-testid="designer-publish"
					:disabled="loading || saving"
					@click="publish">
					{{ t('portaliq', 'Publish') }}
				</NcButton>
				<NcButton
					v-if="hasDraft"
					data-testid="designer-discard-draft"
					:disabled="loading || saving"
					@click="discardDraft">
					{{ t('portaliq', 'Discard draft') }}
				</NcButton>
			</div>
		</header>

		<!-- One line of state, always in the same place. A designer that
		     reports a failed save in a toast that has already faded is a
		     designer that loses work silently. -->
		<NcNoteCard v-if="error" type="error" data-testid="designer-error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard v-else-if="notice" type="success" data-testid="designer-notice">
			{{ notice }}
		</NcNoteCard>
		<NcNoteCard v-else-if="dirty" type="warning" data-testid="designer-dirty">
			{{ t('portaliq', 'Unsaved changes. Save the draft to keep them.') }}
		</NcNoteCard>

		<div v-if="loading" class="designer__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<div v-else class="designer__panes">
			<section class="designer__canvas" data-testid="designer-canvas">
				<CnDashboardGrid
					v-if="widgets.length"
					:layout="widgets"
					:editable="true"
					:columns="12"
					@layoutChange="onLayoutChange"
					@itemActivate="onActivate">
					<template #widget="{ item }">
						<!--
							A POINTER AFFORDANCE OVER AN ALREADY-KEYBOARD-OPERABLE
							CONTROL. The grid ITEM around this cell is the tab
							stop: `CnDashboardGrid` makes each item focusable,
							moves it with the arrow keys and emits
							`item-activate` on Enter or Space, which selects it
							here. So selection is fully keyboard-operable
							without this element.

							It is `tabindex="-1"` rather than `0` for exactly
							that reason — a second tab stop per widget would
							make a ten-widget page twenty stops to cross, all of
							them doing what the first one already did. The role
							and the key handler are still declared, because an
							element that responds to a click must say what it is
							and respond to a key when it is reached
							programmatically.
						-->
						<div
							class="designer__cell"
							:class="{
								'designer__cell--selected': item.id === selectedId,
								'designer__cell--warned': !isPublic(item.widgetKey),
							}"
							role="button"
							tabindex="-1"
							:aria-pressed="item.id === selectedId"
							:aria-label="
								t('portaliq', 'Select the {key} widget', {
									key: item.widgetKey,
								})
							"
							:data-testid="`designer-widget-${item.id}`"
							:data-widget-key="item.widgetKey"
							@click="select(item.id)"
							@keydown.enter.prevent="select(item.id)"
							@keydown.space.prevent="select(item.id)">
							<header class="designer__cell-bar">
								<span class="designer__cell-key">{{
									item.widgetKey
								}}</span>
								<NcButton
									variant="tertiary"
									:aria-label="t('portaliq', 'Remove this widget')"
									:data-testid="`designer-remove-${item.id}`"
									@click.stop="remove(item.id)">
									✕
								</NcButton>
							</header>

							<!--
								Previewed with the SAME resolution the public
								renderer uses, so a placeholder here means a
								placeholder there. A preview that rendered a
								widget the site will not mount would be a
								demonstration of something that never happens.
							-->
							<div class="designer__cell-body">
								<component
									:is="previewFor(item.widgetKey)"
									v-if="previewFor(item.widgetKey)"
									v-bind="previewProps(item)" />
								<p
									v-else
									class="designer__cell-placeholder"
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

				<p v-else class="designer__empty" data-testid="designer-empty">
					{{
						t(
							'portaliq',
							'This page has no widgets yet. Add one to start.',
						)
					}}
				</p>
			</section>

			<aside class="designer__inspector" data-testid="designer-inspector">
				<h3>{{ t('portaliq', 'Widget') }}</h3>
				<p v-if="!selected" class="designer__hint">
					{{
						t(
							'portaliq',
							'Select a widget on the page to edit its content.',
						)
					}}
				</p>

				<template v-else>
					<p class="designer__hint">
						<code>{{ selected.widgetKey }}</code>
					</p>

					<div
						v-for="field in fields"
						:key="field.name"
						class="designer__field">
						<label :for="`field-${field.name}`">{{ field.label }}</label>
						<textarea
							v-if="field.kind === 'text' || field.kind === 'json'"
							:id="`field-${field.name}`"
							class="designer__input"
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
							@change="setProp(field.name, $event.target.checked)" />
						<input
							v-else
							:id="`field-${field.name}`"
							class="designer__input"
							:type="field.kind === 'number' ? 'number' : 'text'"
							:data-testid="`designer-field-${field.name}`"
							:value="fieldValue(field)"
							@input="onFieldInput(field, $event.target.value)" />
					</div>

					<p v-if="!fields.length" class="designer__hint">
						{{
							t(
								'portaliq',
								'This widget declares no editable fields in this build.',
							)
						}}
					</p>
				</template>
			</aside>
		</div>

		<WidgetPaletteDialog v-model:open="paletteOpen" @choose="addWidget" />
	</div>
</template>

<script>
import { CnDashboardGrid } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import WidgetPaletteDialog from '../dialogs/WidgetPaletteDialog.vue'
import {
	defaultSizeFor,
	fieldsFor,
	isPublicWidget,
	previewComponentFor,
} from '../lib/pageWidgetCatalogue.js'

export default {
	name: 'PageLayoutDesigner',

	components: {
		CnDashboardGrid,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		WidgetPaletteDialog,
	},

	data() {
		return {
			// The whole stored object, minus its `@self` envelope. Kept whole
			// because the write is a REPLACE: a save that reconstructed the
			// object from the fields this view knows about would drop every
			// property it does not — silently, and only for pages an editor
			// had opened here.
			page: {},
			widgets: [],
			selectedId: '',
			paletteOpen: false,
			loading: true,
			saving: false,
			dirty: false,
			hasDraft: false,
			error: '',
			notice: '',
		}
	},

	computed: {
		/**
		 * The page identifier from the route.
		 *
		 * @return {string} The id.
		 */
		pageId() {
			return String(this.$route?.params?.id || '')
		},

		/**
		 * The selected widget placement.
		 *
		 * @return {object|null} The placement.
		 */
		selected() {
			return this.widgets.find((w) => w.id === this.selectedId) || null
		},

		/**
		 * The editable fields for the selected widget.
		 *
		 * @return {Array<object>} The fields.
		 */
		fields() {
			return this.selected ? fieldsFor(this.selected.widgetKey) : []
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Load the page and the layout to edit.
		 *
		 * THE DRAFT WINS when there is one. An editor who saved a draft and
		 * came back to a designer showing the published layout would conclude
		 * their work was lost, and would be right to.
		 *
		 * @return {Promise<void>} Resolves when loaded.
		 *
		 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-a-page-must-carry-a-draft-body-that-is-never-served-publicly
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(this.objectUrl())
				const page = { ...data }
				delete page['@self']
				this.page = page
				this.hasDraft = Boolean(page.draftBody)
				const body = page.draftBody || page.body || {}
				this.widgets = this.normalise(body.widgets || [])
				this.dirty = false
			} catch (error) {
				this.error = this.messageFor(
					error,
					t('portaliq', 'This page could not be loaded.'),
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * The OpenRegister object URL for this page.
		 *
		 * @return {string} The URL.
		 */
		objectUrl() {
			return generateUrl(
				`/apps/openregister/api/objects/portaliq/page/${encodeURIComponent(this.pageId)}`,
			)
		},

		/**
		 * Give every placement a stable id and a props object.
		 *
		 * The grid keys its items by id, and a placement without one gets a
		 * new key on every render — which tears down and recreates the DOM
		 * node mid-drag.
		 *
		 * @param {Array<object>} widgets The stored placements.
		 * @return {Array<object>} The normalised placements.
		 */
		normalise(widgets) {
			return widgets.map((widget, index) => ({
				slot: 'body',
				gridX: 0,
				gridY: index,
				gridWidth: 12,
				gridHeight: 4,
				...widget,
				id: widget.id || `widget-${index + 1}`,
				props: { ...(widget.props || {}) },
			}))
		},

		/**
		 * Take the grid's new geometry.
		 *
		 * Merged by id rather than assigned wholesale: the grid owns the
		 * geometry and nothing else, and a payload that round-tripped a
		 * placement's `props` through the layout engine would make the engine
		 * the owner of content it never reads.
		 *
		 * @param {Array<object>} layout The updated layout.
		 * @return {void}
		 *
		 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-a-pages-widget-grid-must-be-editable-by-direct-manipulation
		 */
		onLayoutChange(layout) {
			for (const item of layout) {
				const target = this.widgets.find((w) => w.id === item.id)
				if (!target) {
					continue
				}

				target.gridX = Number(item.gridX) || 0
				target.gridY = Number(item.gridY) || 0
				target.gridWidth = Number(item.gridWidth) || target.gridWidth
				target.gridHeight = Number(item.gridHeight) || target.gridHeight
			}

			this.dirty = true
			this.notice = ''
		},

		/**
		 * Select a placement from a keyboard activation.
		 *
		 * @param {object} payload The grid's activate payload.
		 * @return {void}
		 */
		onActivate(payload) {
			if (payload?.item?.id) {
				this.select(payload.item.id)
			}
		},

		/**
		 * Select a placement.
		 *
		 * @param {string} id The placement id.
		 * @return {void}
		 */
		select(id) {
			this.selectedId = id
		},

		/**
		 * Place a widget from the palette.
		 *
		 * It lands BELOW everything already on the page rather than at the
		 * first free cell: an author who adds a widget must be able to find it,
		 * and a grid that squeezes it into a gap somewhere in the middle looks
		 * like nothing happened.
		 *
		 * @param {string} key The widget key.
		 * @return {void}
		 *
		 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-a-pages-widget-grid-must-be-editable-by-direct-manipulation
		 */
		addWidget(key) {
			const size = defaultSizeFor(key)
			const bottom = this.widgets.reduce(
				(max, w) => Math.max(max, (w.gridY || 0) + (w.gridHeight || 1)),
				0,
			)

			this.widgets.push({
				id: this.nextId(key),
				widgetKey: key,
				slot: 'body',
				gridX: 0,
				gridY: bottom,
				gridWidth: size.gridWidth,
				gridHeight: size.gridHeight,
				props: {},
			})

			this.selectedId = this.widgets[this.widgets.length - 1].id
			this.dirty = true
			this.notice = ''
		},

		/**
		 * A placement id that is not taken yet.
		 *
		 * @param {string} key The widget key.
		 * @return {string} The id.
		 */
		nextId(key) {
			const taken = new Set(this.widgets.map((w) => w.id))
			let counter = 1
			let candidate = `${key}-${counter}`
			while (taken.has(candidate)) {
				counter += 1
				candidate = `${key}-${counter}`
			}

			return candidate
		},

		/**
		 * Remove a placement.
		 *
		 * @param {string} id The placement id.
		 * @return {void}
		 */
		remove(id) {
			this.widgets = this.widgets.filter((w) => w.id !== id)
			if (this.selectedId === id) {
				this.selectedId = ''
			}

			this.dirty = true
			this.notice = ''
		},

		/**
		 * Whether a widget key renders on a published page.
		 *
		 * @param {string} key The widget key.
		 * @return {boolean} True when it does.
		 */
		isPublic(key) {
			return isPublicWidget(key)
		},

		/**
		 * The preview component for a key, or null.
		 *
		 * @param {string} key The widget key.
		 * @return {object|null} The component.
		 */
		previewFor(key) {
			return previewComponentFor(key)
		},

		/**
		 * The props a preview is rendered with.
		 *
		 * `markdown` is remapped because the page stores `props.markdown` and
		 * the block receives `source` — the same mapping the site renderer
		 * performs.
		 *
		 * The data-backed blocks (glossary terms, contributed surfaces, the
		 * publication a detail block shows) are deliberately NOT filled in: the
		 * host supplies those on the site from the public contract, and a
		 * designer that invented them would preview content that the page does
		 * not contain.
		 *
		 * @param {object} widget The placement.
		 * @return {object} The props.
		 */
		previewProps(widget) {
			const props = widget.props || {}
			if (widget.widgetKey === 'markdown') {
				return { source: props.markdown || '' }
			}

			return props
		},

		/**
		 * The value shown in a field's control.
		 *
		 * @param {object} field The field.
		 * @return {string} The value.
		 */
		fieldValue(field) {
			const value = this.selected?.props?.[field.name]
			if (field.kind === 'json') {
				return value === undefined ? '' : JSON.stringify(value, null, 2)
			}

			return value === undefined ? '' : String(value)
		},

		/**
		 * Take a typed value into the selected placement's props.
		 *
		 * A JSON field that does not parse is NOT written and says so, rather
		 * than being stored as the string the author typed: a `cards` prop
		 * holding a broken string renders as nothing on the page, and the
		 * author would have no way to tell that from an empty list.
		 *
		 * @param {object} field The field.
		 * @param {string} raw   The typed value.
		 * @return {void}
		 */
		onFieldInput(field, raw) {
			if (field.kind === 'number') {
				this.setProp(field.name, raw === '' ? undefined : Number(raw))
				return
			}

			if (field.kind !== 'json') {
				this.setProp(field.name, raw)
				return
			}

			if (raw.trim() === '') {
				this.setProp(field.name, undefined)
				return
			}

			try {
				this.setProp(field.name, JSON.parse(raw))
				this.error = ''
			} catch {
				this.error = t(
					'portaliq',
					'That value is not valid JSON, so it was not applied.',
				)
			}
		},

		/**
		 * Write one prop on the selected placement.
		 *
		 * @param {string} name  The prop name.
		 * @param {string|number|boolean|object|undefined} value The value; `undefined` deletes it.
		 * @return {void}
		 */
		setProp(name, value) {
			if (!this.selected) {
				return
			}

			if (value === undefined) {
				delete this.selected.props[name]
			} else {
				this.selected.props[name] = value
			}

			this.dirty = true
			this.notice = ''
		},

		/**
		 * Save the layout as an unpublished draft.
		 *
		 * @return {Promise<void>} Resolves when saved.
		 *
		 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-a-page-must-carry-a-draft-body-that-is-never-served-publicly
		 */
		async saveDraft() {
			await this.write(
				{
					...this.page,
					draftBody: { type: 'grid', widgets: this.widgets },
				},
				t('portaliq', 'Draft saved. The published page is unchanged.'),
			)
		},

		/**
		 * Publish the layout, and clear the draft.
		 *
		 * @return {Promise<void>} Resolves when published.
		 *
		 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-a-page-must-carry-a-draft-body-that-is-never-served-publicly
		 */
		async publish() {
			const payload = {
				...this.page,
				body: { type: 'grid', widgets: this.widgets },
			}
			// The write is a REPLACE, so removing the key is how the draft is
			// cleared — there is no separate delete to issue.
			delete payload.draftBody

			await this.write(payload, t('portaliq', 'Published.'))
		},

		/**
		 * Throw the draft away and return to the published layout.
		 *
		 * @return {Promise<void>} Resolves when discarded.
		 *
		 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-a-page-must-carry-a-draft-body-that-is-never-served-publicly
		 */
		async discardDraft() {
			const payload = { ...this.page }
			delete payload.draftBody

			await this.write(payload, t('portaliq', 'Draft discarded.'))
		},

		/**
		 * Write the page object and reload from what was stored.
		 *
		 * Reloading rather than trusting the local copy is the point: what the
		 * next visitor sees is what OpenRegister kept, and a designer that
		 * displayed its own optimistic version would hide exactly the
		 * validation and authorization outcomes an editor needs to see.
		 *
		 * @param {object} payload The object to store.
		 * @param {string} notice  The message on success.
		 * @return {Promise<void>} Resolves when written.
		 */
		async write(payload, notice) {
			this.saving = true
			this.error = ''
			this.notice = ''
			try {
				await axios.put(this.objectUrl(), payload)
				await this.load()
				this.notice = notice
			} catch (error) {
				this.error = this.messageFor(
					error,
					t('portaliq', 'Saving failed. Nothing was changed.'),
				)
			} finally {
				this.saving = false
			}
		},

		/**
		 * A message for a failed request.
		 *
		 * A refusal is named as one. "Saving failed" over a 403 sends an editor
		 * to look for a bug in their content when the answer is that they are
		 * not in a group that may write pages.
		 *
		 * @param {object} error    The axios error.
		 * @param {string} fallback The generic message.
		 * @return {string} The message.
		 */
		messageFor(error, fallback) {
			const status = error?.response?.status
			if (status === 403 || status === 401) {
				return t(
					'portaliq',
					'You are not allowed to edit portal pages. An administrator sets the editor groups in the Portaliq admin settings.',
				)
			}

			if (status === 404) {
				return t('portaliq', 'This page no longer exists.')
			}

			return fallback
		},
	},
}
</script>

<style scoped>
.designer {
	padding: 12px;
}

.designer__bar {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	gap: 16px;
	flex-wrap: wrap;
	margin-bottom: 12px;
}

.designer__title {
	margin: 0;
}

.designer__route {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
	display: flex;
	gap: 8px;
	align-items: center;
}

.designer__draft {
	color: var(--color-warning-text, var(--color-text-maxcontrast));
}

.designer__actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.designer__panes {
	display: flex;
	gap: 16px;
	align-items: flex-start;
}

.designer__canvas {
	flex: 1 1 auto;
	min-width: 0;
}

.designer__inspector {
	flex: 0 0 320px;
	max-width: 100%;
	border-left: 1px solid var(--color-border);
	padding-left: 16px;
}

.designer__cell {
	height: 100%;
	display: flex;
	flex-direction: column;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	overflow: hidden;
}

.designer__cell--selected {
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 2px var(--color-primary-element);
}

.designer__cell--warned {
	border-style: dashed;
}

.designer__cell-bar {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
	padding: 2px 4px 2px 8px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-background-hover);
}

.designer__cell-key {
	font-family: monospace;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.designer__cell-body {
	flex: 1 1 auto;
	overflow: auto;
	padding: 8px;
}

.designer__cell-placeholder {
	color: var(--color-text-maxcontrast);
	font-style: italic;
	margin: 0;
}

.designer__empty,
.designer__hint {
	color: var(--color-text-maxcontrast);
}

.designer__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 12px;
}

.designer__input {
	width: 100%;
}

.designer__loading {
	display: flex;
	justify-content: center;
	padding: 48px 0;
}

@media (max-width: 1024px) {
	.designer__panes {
		flex-direction: column;
	}

	.designer__inspector {
		border-left: none;
		border-top: 1px solid var(--color-border);
		padding-left: 0;
		padding-top: 16px;
		flex-basis: auto;
		width: 100%;
	}
}
</style>
