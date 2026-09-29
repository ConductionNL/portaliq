<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SiteEditMode: edit the page on screen, on the portal itself.

  LOADED ONLY FOR AN EDITOR. This is the root of the editor bundle
  (`js/portaliq-site-editor.js`, entry `siteEditorMain.js`), which the site
  loads with a script tag when an editor chooses "Deze pagina bewerken" and
  mounts where the page was. So the grid engine, the widget forms and this
  toolbar never reach a visitor's first load (REQ-PIE-007). It is the second
  host of the shared editor: the grid, the inspector, undo, the payloads and
  the version-checked save are the SAME createPageEditor() and
  PageGridEditor.vue the admin designer uses.

  IN THE PORTAL'S THEME. It renders inside the site's own root, so the portal's
  design tokens apply; the Nextcloud tokens the shared components read are
  given portal-neutral values below, because the site loads no Nextcloud CSS.

  @spec openspec/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-a-page-in-place-on-the-portal-req-pie-006
-->
<template>
	<div v-if="!ready" class="pq-site-editor__loading">
		<NcLoadingIcon :size="32" />
	</div>
	<section
		v-else
		class="pq-site-editor"
		data-testid="site-edit-mode"
		:aria-label="t('portaliq', 'Edit this page')">
		<header class="pq-site-editor__bar">
			<p class="pq-site-editor__status" role="status">
				<strong>{{ t('portaliq', 'Editing') }}</strong>
				<span v-if="state.hasDraft">{{
					t('portaliq', 'unpublished draft')
				}}</span>
				<span v-if="state.kind === 'markdown'">{{
					t('portaliq', 'markdown page')
				}}</span>
			</p>
			<div class="pq-site-editor__actions">
				<NcButton
					data-testid="site-edit-add"
					:disabled="state.loading || state.kind !== 'grid'"
					@click="paletteOpen = true">
					{{ t('portaliq', 'Add widget') }}
				</NcButton>
				<NcButton
					data-testid="site-edit-undo"
					:disabled="!state.canUndo || state.saving"
					:title="t('portaliq', 'Undo (Ctrl+Z)')"
					@click="editor.undo()">
					{{ t('portaliq', 'Undo') }}
				</NcButton>
				<NcButton
					data-testid="site-edit-redo"
					:disabled="!state.canRedo || state.saving"
					:title="t('portaliq', 'Redo (Ctrl+Shift+Z)')"
					@click="editor.redo()">
					{{ t('portaliq', 'Redo') }}
				</NcButton>
				<NcButton
					data-testid="site-edit-save"
					:disabled="state.loading || state.saving"
					@click="editor.saveDraft()">
					{{ t('portaliq', 'Save draft') }}
				</NcButton>
				<NcButton
					variant="primary"
					data-testid="site-edit-publish"
					:disabled="state.loading || state.saving"
					@click="publish">
					{{ t('portaliq', 'Publish') }}
				</NcButton>
				<NcButton
					v-if="state.hasDraft"
					data-testid="site-edit-discard"
					:disabled="state.loading || state.saving"
					@click="editor.discard()">
					{{ t('portaliq', 'Discard draft') }}
				</NcButton>
				<NcButton
					data-testid="site-edit-history"
					:disabled="state.loading"
					@click="historyOpen = true">
					{{ t('portaliq', 'History') }}
				</NcButton>
				<NcButton
					data-testid="site-edit-pages"
					:pressed="panel === 'pages'"
					@click="toggle('pages')">
					{{ t('portaliq', 'Pages') }}
				</NcButton>
				<NcButton
					data-testid="site-edit-menu"
					:pressed="panel === 'menu'"
					@click="toggle('menu')">
					{{ t('portaliq', 'Menu') }}
				</NcButton>
				<NcButton data-testid="site-edit-leave" @click="leave">
					{{ t('portaliq', 'Stop editing') }}
				</NcButton>
			</div>
		</header>

		<NcNoteCard v-if="state.error" type="error" data-testid="site-edit-error">
			<p>{{ state.error }}</p>
			<NcButton v-if="state.conflict" @click="editor.load()">
				{{ t('portaliq', 'Reload the page') }}
			</NcButton>
		</NcNoteCard>
		<NcNoteCard
			v-else-if="state.notice"
			type="success"
			data-testid="site-edit-notice">
			{{ state.notice }}
		</NcNoteCard>
		<NcNoteCard
			v-else-if="leaving"
			type="warning"
			data-testid="site-edit-unsaved">
			<p>
				{{
					t(
						'portaliq',
						'You have unsaved changes. Save the draft first, or stop editing and lose them.',
					)
				}}
			</p>
			<NcButton @click="leave(true)">
				{{ t('portaliq', 'Stop editing and lose the changes') }}
			</NcButton>
		</NcNoteCard>
		<NcNoteCard v-else-if="state.dirty" type="warning">
			{{ t('portaliq', 'Unsaved changes. Save the draft to keep them.') }}
		</NcNoteCard>

		<!-- The rest of the portal, from the portal (A3). -->
		<SitePagesPanel
			v-if="panel === 'pages'"
			:portal="portal"
			:currentPageId="pageId" />
		<SiteMenuPanel v-if="panel === 'menu'" :portal="portal" />

		<div v-if="state.loading" class="pq-site-editor__loading">
			<NcLoadingIcon :size="32" />
		</div>
		<PageGridEditor v-else :editor="editor" />

		<WidgetPaletteDialog
			v-model:open="paletteOpen"
			publicOnly
			@choose="editor.addWidget" />
		<PageHistoryDialog
			v-model:open="historyOpen"
			:pageId="pageId"
			:busy="state.saving"
			@restore="restore" />
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { register, translate } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { reactive } from 'vue'
import PageHistoryDialog from '../dialogs/PageHistoryDialog.vue'
import WidgetPaletteDialog from '../dialogs/WidgetPaletteDialog.vue'
import PageGridEditor from './PageGridEditor.vue'
import SiteMenuPanel from './SiteMenuPanel.vue'
import SitePagesPanel from './SitePagesPanel.vue'
import { defaultSizeFor } from '../lib/pageWidgetCatalogue.js'
import { createPageEditor, createPageSaver } from './index.js'

import 'gridstack/dist/gridstack.css'
import '@conduction/nextcloud-vue/css/index.css'

/**
 * Load the app's Dutch catalogue into the translator.
 *
 * The site is a standalone document: Nextcloud's layout, which normally
 * delivers the app's translations, is not on it. So the editor brings its own,
 * as a chunk of its own, and only for a Dutch page: English is the source text.
 *
 * @return {Promise<void>} Resolves when registered.
 *
 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-a-page-in-place-on-the-portal-req-pie-006
 */
async function loadCatalogue() {
	const lang = String(document.documentElement.lang || 'nl').toLowerCase()
	if (!lang.startsWith('nl')) {
		return
	}
	const catalogue = await import(
		/* webpackChunkName: "site-editor-nl" */ '../../l10n/nl.json'
	)
	register('portaliq', (catalogue.default || catalogue).translations || {})
}

export default {
	name: 'SiteEditMode',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		PageGridEditor,
		PageHistoryDialog,
		SiteMenuPanel,
		SitePagesPanel,
		WidgetPaletteDialog,
	},

	props: {
		/** The page object's id, from the editing context. */
		pageId: {
			type: String,
			required: true,
		},

		/** The portal slug, for the pages and the menu panels. */
		portal: {
			type: String,
			default: '',
		},
	},

	emits: ['leave', 'saved'],

	data() {
		return {
			editor: createPageEditor({
				saver: createPageSaver({
					get: (url) => axios.get(url),
					put: (url, payload, config) => axios.put(url, payload, config),
					url: (id) =>
						generateUrl(
							`/apps/openregister/api/objects/portaliq/page/${encodeURIComponent(id)}`,
						),
				}),
				pageId: this.pageId,
				t: translate,
				reactive,
				defaultSizeFor,
			}),

			ready: false,
			paletteOpen: false,
			historyOpen: false,
			leaving: false,
			panel: '',
		}
	},

	computed: {
		/**
		 * @return {object} The editor's state.
		 *
		 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-a-page-in-place-on-the-portal-req-pie-006
		 */
		state() {
			return this.editor.state
		},
	},

	/**
	 * Bring the Dutch catalogue, then load the page into the editor.
	 *
	 * @return {Promise<void>} Resolves when the page is loaded.
	 *
	 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-a-page-in-place-on-the-portal-req-pie-006
	 */
	async mounted() {
		try {
			await loadCatalogue()
		} catch {
			// Without the catalogue the editor speaks English. That is a worse
			// editor, not a broken one, so it still opens.
		}
		this.ready = true
		await this.editor.load()
	},

	methods: {
		/**
		 * Translate.
		 *
		 * @param {string} app The app id.
		 * @param {string} text The source text.
		 * @param {object} vars The placeholders.
		 * @return {string} The translation.
		 *
		 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-a-page-in-place-on-the-portal-req-pie-006
		 */
		t(app, text, vars) {
			return translate(app, text, vars)
		},

		/**
		 * Open or close the pages or the menu panel.
		 *
		 * @param {string} name The panel.
		 * @return {void}
		 *
		 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-pages-must-form-a-tree-an-editor-manages-from-the-portal-req-pie-010
		 */
		toggle(name) {
			this.panel = this.panel === name ? '' : name
		},

		/**
		 * Publish, and tell the site to show the published page again.
		 *
		 * @return {Promise<void>} Resolves when published or refused.
		 *
		 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-a-page-in-place-on-the-portal-req-pie-006
		 */
		async publish() {
			await this.editor.publish()
			if (!this.state.error) {
				this.$emit('saved')
			}
		},

		/**
		 * Put an earlier version in the draft.
		 *
		 * @param {object} version A version from the page history.
		 * @return {Promise<void>} Resolves when written.
		 *
		 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-a-page-in-place-on-the-portal-req-pie-006
		 */
		async restore(version) {
			this.historyOpen = false
			await this.editor.restore(version)
		},

		/**
		 * Leave edit mode. With unsaved changes the first press asks; the
		 * second, explicit one leaves and drops them.
		 *
		 * @param {boolean} force Leave even with unsaved changes.
		 * @return {void}
		 *
		 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-a-page-in-place-on-the-portal-req-pie-006
		 */
		leave(force = false) {
			if (this.state.dirty && force !== true) {
				this.leaving = true
				return
			}
			this.$emit('leave')
		},
	},
}
</script>

<style scoped>
.pq-site-editor {
	padding: 16px;
	margin: 0 auto;
	max-width: 1280px;
}

.pq-site-editor__bar {
	display: flex;
	justify-content: space-between;
	align-items: center;
	flex-wrap: wrap;
	gap: 12px;
	margin-bottom: 12px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	position: sticky;
	top: 0;
	z-index: 10;
}

.pq-site-editor__status {
	display: flex;
	gap: 8px;
	margin: 0;
}

.pq-site-editor__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.pq-site-editor__loading {
	display: flex;
	justify-content: center;
	padding: 48px 0;
}
</style>

<style>
/*
 * THE NEXTCLOUD TOKENS THE SHARED COMPONENTS READ, for a page that loads no
 * Nextcloud CSS. The site is a standalone document in the portal's own theme,
 * so buttons, dialogs and the grid would otherwise draw with no colours at all.
 * Set on `body` because dialogs are teleported out of the editor's root. The
 * portal's own NL Design System tokens are used where one exists. This
 * stylesheet only ever reaches an editor: it is part of the editor chunk.
 */
body {
	--color-main-background: var(--utrecht-document-background-color, #fff);
	--color-main-text: var(--utrecht-document-color, #1d1d1d);
	--color-text-maxcontrast: #5c5c5c;
	--color-border: #c7c7c7;
	--color-border-dark: #8f8f8f;
	--color-background-hover: #f2f2f2;
	--color-background-dark: #e6e6e6;
	--color-primary-element: var(
		--utrecht-button-primary-action-background-color,
		#0b5ea8
	);
	--color-primary-element-hover: var(
		--utrecht-button-primary-action-hover-background-color,
		#094d8a
	);
	--color-primary-element-text: var(--utrecht-button-primary-action-color, #fff);
	--color-primary-element-light: #e3eef8;
	--color-primary-element-light-hover: #d0e2f3;
	--color-primary-element-light-text: #0b5ea8;
	--color-error: #c9302c;
	--color-error-text: #a8201a;
	--color-success: #2d7b41;
	--color-success-text: #256a37;
	--color-warning: #a36b00;
	--color-warning-text: #7a5000;
	--color-info: #0b5ea8;
	--color-info-text: #0b5ea8;
	--border-radius: 3px;
	--border-radius-element: 8px;
	--border-radius-large: 10px;
	--border-radius-container: 12px;
	--default-clickable-area: 34px;
	--clickable-area-small: 24px;
	--default-grid-baseline: 4px;
	--default-font-size: 15px;
	--font-face: inherit;
}
</style>
