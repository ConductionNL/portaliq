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

  THE EDITOR IS SHARED. The grid, the inspector, undo and redo, the payloads
  and the version-checked save live in src/editor/ (createPageEditor() and
  PageGridEditor.vue), which the portal edit mode mounts too. This view is the
  admin host: its toolbar and its dialogs, nothing more.

  WRITES GO STRAIGHT TO OPENREGISTER (ADR-022). There is no Portaliq controller
  in front of them, which is exactly why the `page` schema carries the editor
  groups in its authorization block — see lib/Service/PageEditorService.php.
  A refusal here is OpenRegister refusing, not this view deciding.

  @spec openspec/specs/portal-page-designer/spec.md#requirement-a-pages-widget-grid-must-be-editable-by-direct-manipulation
  @spec openspec/changes/portal-in-place-editing/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
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
						v-if="state.hasDraft"
						class="designer__draft"
						data-testid="designer-has-draft">
						{{ t('portaliq', 'unpublished draft') }}
					</span>
					<span
						v-if="state.kind === 'markdown'"
						class="designer__draft"
						data-testid="designer-markdown">
						{{ t('portaliq', 'markdown page') }}
					</span>
				</p>
			</div>

			<div class="designer__actions">
				<NcButton
					data-testid="designer-add-widget"
					:disabled="state.loading || state.kind !== 'grid'"
					@click="paletteOpen = true">
					{{ t('portaliq', 'Add widget') }}
				</NcButton>
				<NcButton
					data-testid="designer-undo"
					:disabled="!state.canUndo || state.saving"
					:aria-label="t('portaliq', 'Undo (Ctrl+Z)')"
					:title="t('portaliq', 'Undo (Ctrl+Z)')"
					@click="editor.undo()">
					{{ t('portaliq', 'Undo') }}
				</NcButton>
				<NcButton
					data-testid="designer-redo"
					:disabled="!state.canRedo || state.saving"
					:aria-label="t('portaliq', 'Redo (Ctrl+Shift+Z)')"
					:title="t('portaliq', 'Redo (Ctrl+Shift+Z)')"
					@click="editor.redo()">
					{{ t('portaliq', 'Redo') }}
				</NcButton>
				<NcButton
					data-testid="designer-save-draft"
					:disabled="state.loading || state.saving"
					@click="editor.saveDraft()">
					{{ t('portaliq', 'Save draft') }}
				</NcButton>
				<NcButton
					variant="primary"
					data-testid="designer-publish"
					:disabled="state.loading || state.saving"
					@click="editor.publish()">
					{{ t('portaliq', 'Publish') }}
				</NcButton>
				<NcButton
					data-testid="designer-media"
					:disabled="state.loading"
					@click="mediaOpen = true">
					{{ t('portaliq', 'Media') }}
				</NcButton>
				<NcButton
					data-testid="designer-history"
					:disabled="state.loading"
					@click="historyOpen = true">
					{{ t('portaliq', 'History') }}
				</NcButton>
				<NcButton
					v-if="state.hasDraft"
					data-testid="designer-discard-draft"
					:disabled="state.loading || state.saving"
					@click="editor.discard()">
					{{ t('portaliq', 'Discard draft') }}
				</NcButton>
				<!--
					THE WAY BACK. An editor arrives here from a floating control
					on the page they were reading; the link carries the page's own
					route, so it returns them to that page.
				-->
				<NcButton
					v-if="page.route"
					:href="siteUrl"
					data-testid="designer-view-on-site">
					{{ t('portaliq', 'View on the site') }}
				</NcButton>
			</div>
		</header>

		<!-- One line of state, always in the same place. A failed save in a
		     toast that has already faded is work lost silently. -->
		<NcNoteCard v-if="state.error" type="error" data-testid="designer-error">
			<p>{{ state.error }}</p>
			<NcButton
				v-if="state.conflict"
				data-testid="designer-reload"
				@click="editor.load()">
				{{ t('portaliq', 'Reload the page') }}
			</NcButton>
		</NcNoteCard>
		<NcNoteCard
			v-else-if="state.notice"
			type="success"
			data-testid="designer-notice">
			{{ state.notice }}
		</NcNoteCard>
		<NcNoteCard
			v-else-if="state.dirty"
			type="warning"
			data-testid="designer-dirty">
			{{ t('portaliq', 'Unsaved changes. Save the draft to keep them.') }}
		</NcNoteCard>

		<div v-if="state.loading" class="designer__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<PageGridEditor v-else :editor="editor" />

		<WidgetPaletteDialog v-model:open="paletteOpen" @choose="editor.addWidget" />
		<MediaPickerDialog
			v-model:open="mediaOpen"
			:portal="page.portal || ''"
			@choose="useMedia" />
		<PageHistoryDialog
			v-model:open="historyOpen"
			:pageId="pageId"
			:busy="state.saving"
			@restore="restoreVersion" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { reactive } from 'vue'
import MediaPickerDialog from '../dialogs/MediaPickerDialog.vue'
import PageHistoryDialog from '../dialogs/PageHistoryDialog.vue'
import WidgetPaletteDialog from '../dialogs/WidgetPaletteDialog.vue'
import PageGridEditor from '../editor/PageGridEditor.vue'
import { createPageEditor, createPageSaver } from '../editor/index.js'
import { withMedia } from '../lib/mediaLibrary.js'
import { pageSiteUrl } from '../lib/pageSiteUrl.js'
import { defaultSizeFor } from '../lib/pageWidgetCatalogue.js'

export default {
	name: 'PageLayoutDesigner',

	components: {
		NcButton,
		NcLoadingIcon,
		MediaPickerDialog,
		NcNoteCard,
		PageGridEditor,
		PageHistoryDialog,
		WidgetPaletteDialog,
	},

	data() {
		return {
			editor: this.makeEditor(),
			paletteOpen: false,
			historyOpen: false,
			mediaOpen: false,
		}
	},

	computed: {
		/**
		 * The page identifier from the route.
		 *
		 * @return {string} The id.
		 *
		 * @spec openspec/specs/portal-page-designer/spec.md#requirement-a-pages-widget-grid-must-be-editable-by-direct-manipulation
		 */
		pageId() {
			return String(this.$route?.params?.id || '')
		},

		/** @return {object} The editor's state. */
		state() {
			return this.editor.state
		},

		/**
		 * The whole stored page, as the editor loaded it.
		 *
		 * @return {object} The page.
		 */
		page() {
			return this.editor.state.page
		},

		/**
		 * Where this page is served on the public site.
		 *
		 * @return {string} The site URL for this page's route.
		 *
		 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-designer-must-be-reachable-from-the-page-administration-surfaces
		 */
		siteUrl() {
			return pageSiteUrl(this.page, generateUrl)
		},
	},

	mounted() {
		this.editor.load()
	},

	methods: {
		/**
		 * The shared editor over OpenRegister's object API.
		 *
		 * @return {object} The editor.
		 *
		 * @spec openspec/changes/portal-in-place-editing/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
		 */
		makeEditor() {
			const pageId = String(this.$route?.params?.id || '')
			const saver = createPageSaver({
				get: (url) => axios.get(url),
				put: (url, payload, config) => axios.put(url, payload, config),
				url: (id) =>
					generateUrl(
						`/apps/openregister/api/objects/portaliq/page/${encodeURIComponent(id)}`,
					),
			})
			return createPageEditor({ saver, pageId, t, reactive, defaultSizeFor })
		},

		/**
		 * Store a library image as the page's hero or share image. Not part of
		 * the draft: like the page's other fields, the next save writes it.
		 *
		 * @param {{item: object, target: string}} choice The item and where it goes.
		 * @return {void}
		 *
		 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
		 */
		useMedia({ item, target }) {
			this.mediaOpen = false
			this.editor.updatePage((page) => withMedia(page, item, target))
		},

		/**
		 * Put an earlier published version in the draft.
		 *
		 * @param {object} version A version from the page history.
		 * @return {Promise<void>} Resolves when written.
		 *
		 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
		 */
		async restoreVersion(version) {
			this.historyOpen = false
			await this.editor.restore(version)
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

.designer__loading {
	display: flex;
	justify-content: center;
	padding: 48px 0;
}
</style>
