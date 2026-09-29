<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  PageHistoryDialog: the published versions of a page, newest first, with who
  published each and when (site-page-seo-history-and-media T04, T05).

  Restoring does not write here. The dialog hands the chosen version to the
  designer, which saves it as the page's draft through its own draft write, so
  the live page changes only when the editor publishes. See
  src/lib/pageHistory.js.

  @spec openspec/specs/site-page-seo-history-and-media/spec.md
-->
<template>
	<NcDialog
		:name="t('portaliq', 'Page history')"
		:open="open"
		size="normal"
		data-testid="page-history"
		@update:open="$emit('update:open', $event)">
		<p class="history__intro">
			{{
				t(
					'portaliq',
					'The published versions of this page, newest first. Restoring a version puts it in the draft. The live page changes only when you publish.',
				)
			}}
		</p>

		<NcLoadingIcon v-if="state === 'loading'" :size="32" />
		<NcNoteCard
			v-else-if="state === 'error'"
			type="error"
			data-testid="page-history-error">
			{{ t('portaliq', 'The history could not be loaded.') }}
		</NcNoteCard>
		<p v-else-if="state === 'empty'" data-testid="page-history-empty">
			{{ t('portaliq', 'This page has no published versions yet.') }}
		</p>
		<ol v-else class="history__list">
			<li
				v-for="version in versions"
				:key="version.id"
				class="history__item"
				data-testid="page-history-version">
				<span class="history__when">
					{{
						t('portaliq', 'Published on {date} by {name}', {
							date: formatDate(version.publishedAt),
							name: version.by || t('portaliq', 'an unknown editor'),
						})
					}}
				</span>
				<NcButton
					v-if="version.restorable"
					:disabled="busy"
					data-testid="page-history-restore"
					@click="restore(version)">
					{{ t('portaliq', 'Restore this version') }}
				</NcButton>
				<span v-else class="history__note">
					{{
						t(
							'portaliq',
							'This version was recorded without its content, so it cannot be restored.',
						)
					}}
				</span>
			</li>
		</ol>

		<template #actions>
			<NcButton
				data-testid="page-history-close"
				@click="$emit('update:open', false)">
				{{ t('portaliq', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { createPageHistory } from '../lib/pageHistory.js'

export default {
	name: 'PageHistoryDialog',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		/** Whether the dialog is open. */
		open: {
			type: Boolean,
			default: false,
		},

		/** The page object's id. */
		pageId: {
			type: String,
			default: '',
		},

		/** Whether the designer is writing, which disables restoring. */
		busy: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:open', 'restore'],

	data() {
		return {
			state: 'loading',
			versions: [],
		}
	},

	watch: {
		open: {
			immediate: true,
			/**
			 * Read the history each time the dialog opens, so a version
			 * published a moment ago is listed.
			 *
			 * @param {boolean} isOpen Whether the dialog is open.
			 * @return {Promise<void>}
			 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
			 */
			async handler(isOpen) {
				if (!isOpen || !this.pageId) {
					return
				}
				this.state = 'loading'
				const history = createPageHistory({
					get: (url) => axios.get(url),
					url: (path, params) =>
						generateUrl('/apps/portaliq' + path, params),
				})
				const result = await history.load(this.pageId)
				this.state = result.state
				this.versions = result.versions
			},
		},
	},

	methods: {
		/**
		 * Translate. Local rather than the admin app's global mixin, because
		 * the portal edit mode mounts this dialog on the public site too.
		 *
		 * @param {string} app The app id.
		 * @param {string} text The source text.
		 * @param {object} vars The placeholders.
		 * @return {string} The translation.
		 *
		 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-a-page-in-place-on-the-portal-req-pie-006
		 */
		t(app, text, vars) {
			return translate(app, text, vars)
		},

		/**
		 * A publication moment in the reader's locale.
		 *
		 * @param {string} iso The ISO timestamp.
		 * @return {string} The date and time.
		 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
		 */
		formatDate(iso) {
			const date = new Date(iso)
			return Number.isNaN(date.getTime()) ? iso : date.toLocaleString()
		},

		/**
		 * Hand the chosen version to the designer.
		 *
		 * @param {object} version The version.
		 * @return {void}
		 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
		 */
		restore(version) {
			this.$emit('restore', version)
		},
	},
}
</script>

<style scoped>
.history__intro {
	margin-bottom: 12px;
	color: var(--color-text-maxcontrast);
}

.history__list {
	list-style: none;
	margin: 0;
	padding: 0;
	max-height: 60vh;
	overflow-y: auto;
}

.history__item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.history__note {
	color: var(--color-text-maxcontrast);
}
</style>
