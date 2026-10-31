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
  @spec openspec/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
-->
<template>
	<div class="designer">
		<header class="designer__bar">
			<!-- Embedded in the page's own tab the page header already names
			     the page, its route and its status. -->
			<div v-if="!embedded" class="designer__identity">
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

			<div class="designer__toolbar">
				<div class="designer__actions">
					<!--
						A DISCLOSURE, NOT A DIALOG OPENER. The palette is a panel
						beside the canvas, so this button shows and hides it, and
						says which it is doing through `aria-expanded`.
					-->
					<NcButton
						variant="primary"
						data-testid="designer-add-widget"
						:disabled="state.loading || state.kind !== 'grid'"
						:aria-expanded="paletteOpen"
						aria-controls="widget-palette"
						@click="paletteOpen = !paletteOpen">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('portaliq', 'Add block') }}
					</NcButton>
					<NcButton
						data-testid="designer-undo"
						:disabled="!state.canUndo || state.saving"
						:aria-label="t('portaliq', 'Undo (Ctrl+Z)')"
						:title="t('portaliq', 'Undo (Ctrl+Z)')"
						@click="editor.undo()">
						<template #icon>
							<Undo :size="20" />
						</template>
					</NcButton>
					<NcButton
						data-testid="designer-redo"
						:disabled="!state.canRedo || state.saving"
						:aria-label="t('portaliq', 'Redo (Ctrl+Shift+Z)')"
						:title="t('portaliq', 'Redo (Ctrl+Shift+Z)')"
						@click="editor.redo()">
						<template #icon>
							<Redo :size="20" />
						</template>
					</NcButton>
					<NcButton
						v-if="!isBlock"
						data-testid="designer-media"
						:disabled="state.loading"
						@click="mediaOpen = true">
						<template #icon>
							<ImageMultipleOutline :size="20" />
						</template>
						{{ t('portaliq', 'Media') }}
					</NcButton>
				</div>
				<p
					v-if="state.dirty"
					class="designer__unsaved"
					role="status"
					data-testid="designer-unsaved">
					<AlertCircleOutline :size="18" />
					{{ t('portaliq', 'Unsaved changes') }}
				</p>
			</div>

			<div class="designer__actions">
				<NcButton
					v-if="state.hasDraft"
					data-testid="designer-discard-draft"
					:disabled="state.loading || state.saving"
					@click="editor.discard()">
					<template #icon>
						<DeleteOutline :size="20" />
					</template>
					{{ t('portaliq', 'Discard draft') }}
				</NcButton>
				<NcButton
					data-testid="designer-save-draft"
					:disabled="state.loading || state.saving"
					@click="editor.saveDraft()">
					<template #icon>
						<ContentSaveOutline :size="20" />
					</template>
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
					v-if="!isBlock"
					data-testid="designer-history"
					:disabled="state.loading"
					@click="historyOpen = true">
					{{ t('portaliq', 'History') }}
				</NcButton>
				<!--
					THE WAY BACK. An editor arrives here from a floating control
					on the page they were reading; the link carries the page's own
					route, so it returns them to that page.
				-->
				<NcButton
					v-if="page.route && !embedded"
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

		<div v-if="state.loading" class="designer__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<PageGridEditor
			v-else
			v-model:paletteOpen="paletteOpen"
			:editor="editor"
			:loadBlocks="loadBlocks"
			:loadRecordLists="loadRecordLists" />

		<MediaPickerDialog
			v-model:open="mediaOpen"
			:portal="page.portal || ''"
			:pageTitle="page.title || ''"
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
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import ImageMultipleOutline from 'vue-material-design-icons/ImageMultipleOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Redo from 'vue-material-design-icons/Redo.vue'
import Undo from 'vue-material-design-icons/Undo.vue'
import MediaPickerDialog from '../dialogs/MediaPickerDialog.vue'
import PageHistoryDialog from '../dialogs/PageHistoryDialog.vue'
import PageGridEditor from '../editor/PageGridEditor.vue'
import {
	blockToPage,
	createPageEditor,
	createPageSaver,
	pageToBlock,
	recordListsFrom,
} from '../editor/index.js'
import { withMedia } from '../lib/mediaLibrary.js'
import { pageSiteUrl } from '../lib/pageSiteUrl.js'
import { defaultSizeFor } from '../lib/pageWidgetCatalogue.js'

export default {
	name: 'PageLayoutDesigner',

	components: {
		AlertCircleOutline,
		ContentSaveOutline,
		DeleteOutline,
		ImageMultipleOutline,
		NcButton,
		NcLoadingIcon,
		MediaPickerDialog,
		NcNoteCard,
		PageGridEditor,
		PageHistoryDialog,
		Plus,
		Redo,
		Undo,
	},

	props: {
		/**
		 * Drawn inside the page's own Indeling tab (PageLayoutEditor), where the
		 * page header already names the page: no title line of its own.
		 *
		 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-layout-editor-is-a-tab-of-the-page
		 */
		embedded: {
			type: Boolean,
			default: false,
		},
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

		/**
		 * Whether this is a shared block (site-shared-page-blocks) rather than a page.
		 *
		 * @return {boolean} True on the shared block layout route.
		 * @spec openspec/changes/site-shared-page-blocks/tasks.md#t05
		 */
		isBlock() {
			return this.$route?.name === 'SharedBlockLayout'
		},

		/**
		 * @return {object} The editor's state.
		 *
		 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
		 */
		state() {
			return this.editor.state
		},

		/**
		 * The whole stored page, as the editor loaded it.
		 *
		 * @return {object} The page.
		 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
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
		 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
		 */
		makeEditor() {
			const pageId = String(this.$route?.params?.id || '')
			const isBlock = this.$route?.name === 'SharedBlockLayout'
			const schema = isBlock ? 'sharedBlock' : 'page'
			const saver = createPageSaver({
				// A shared block is edited as a page: translate at the edge.
				get: isBlock
					? async (url) => {
							const response = await axios.get(url)
							return { ...response, data: blockToPage(response.data) }
						}
					: (url) => axios.get(url),
				put: isBlock
					? (url, payload, config) =>
							axios.put(url, pageToBlock(payload), config)
					: (url, payload, config) => axios.put(url, payload, config),
				url: (id) =>
					generateUrl(
						`/apps/openregister/api/objects/portaliq/${schema}/${encodeURIComponent(id)}`,
					),
			})
			return createPageEditor({ saver, pageId, t, reactive, defaultSizeFor })
		},

		/**
		 * The published shared blocks an author can place.
		 *
		 * @return {Promise<Array<{id: string, title: string}>>} The blocks.
		 * @spec openspec/changes/site-shared-page-blocks/tasks.md#t05
		 */
		async loadBlocks() {
			const { data } = await axios.get(
				generateUrl('/apps/openregister/api/objects/portaliq/sharedBlock'),
				{ params: { status: 'published', _limit: 100 } },
			)
			return (data?.results || [])
				.map((row) => ({
					id: String(row['@self']?.id || row.id || ''),
					title: String(row.title || ''),
				}))
				.filter((block) => block.id !== '')
		},

		/**
		 * The public record lists the installed apps contribute to this page's
		 * portal, read from the same anonymous answer the site reads.
		 *
		 * @return {Promise<Array<{app: string, id: string, label: string}>>} The lists.
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
		 */
		async loadRecordLists() {
			const { data } = await axios.get(
				generateUrl('/apps/portaliq/api/content/contributions'),
				{ params: this.page.portal ? { portal: this.page.portal } : {} },
			)
			return recordListsFrom(data)
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

.designer__toolbar {
	display: flex;
	flex: 1 1 100%;
	flex-wrap: wrap;
	justify-content: space-between;
	align-items: center;
	gap: 8px 16px;
}

.designer__unsaved {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	margin: 0;
	color: var(--color-warning-text, var(--color-text-maxcontrast));
	font-size: 14px;
	font-weight: 600;
}

.designer__loading {
	display: flex;
	justify-content: center;
	padding: 48px 0;
}
</style>
