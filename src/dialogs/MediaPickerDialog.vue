<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  MediaPickerDialog: the published items of the page's portal media library
  (site-page-seo-history-and-media T08). An image can become the page's hero
  image or share image; any item gives a markdown reference to paste into a
  text widget. The dialog only hands the choice to the designer, which stores
  a media:<id> reference, so replacing the item's file later updates the page.
  See src/lib/mediaLibrary.js.

  @spec openspec/specs/site-page-seo-history-and-media/spec.md
-->
<template>
	<CnDialog
		:look="look"
		width="wizard"
		:name="t('portaliq', 'Media')"
		:eyebrow="eyebrow"
		:open="open"
		size="normal"
		data-testid="media-picker"
		@update:open="$emit('update:open', $event)">
		<p class="media__intro">
			{{
				t(
					'portaliq',
					"Published items of this portal's media library. Add or replace items on the Media page.",
				)
			}}
		</p>

		<NcLoadingIcon v-if="state === 'loading'" :size="32" />
		<NcNoteCard
			v-else-if="state === 'error'"
			type="error"
			data-testid="media-picker-error">
			{{ t('portaliq', 'The media library could not be loaded.') }}
		</NcNoteCard>
		<p v-else-if="state === 'empty'" data-testid="media-picker-empty">
			{{ t('portaliq', 'This portal has no published media yet.') }}
		</p>
		<template v-else>
			<ul class="media__grid" :aria-label="t('portaliq', 'Media library')">
				<li v-for="item in items" :key="item.id" class="media__cell">
					<button
						type="button"
						class="media__card"
						:class="{ 'media__card--selected': item.id === selectedId }"
						:aria-pressed="item.id === selectedId ? 'true' : 'false'"
						data-testid="media-picker-item"
						@click="select(item)">
						<img
							v-if="thumbnail(item)"
							class="media__thumb"
							:src="thumbnail(item)"
							:alt="item.alt"
							loading="lazy" />
						<span
							v-else
							class="media__thumb media__thumb--file"
							aria-hidden="true">
							{{ t('portaliq', 'File') }}
						</span>
						<span class="media__name" data-testid="media-picker-name">{{
							fileNames[item.id] || item.title
						}}</span>
						<span class="media__alt" data-testid="media-picker-alt">{{
							item.alt
						}}</span>
					</button>
				</li>
			</ul>

			<div v-if="selected" class="media__bar" data-testid="media-picker-bar">
				<span class="media__chosen">{{ selected.title }}</span>
				<span class="media__actions">
					<template v-if="selected.kind === 'image'">
						<NcButton
							data-testid="media-picker-hero"
							@click="choose(selected, 'hero')">
							{{ t('portaliq', 'Use as hero image') }}
						</NcButton>
						<NcButton
							data-testid="media-picker-share"
							@click="choose(selected, 'share')">
							{{ t('portaliq', 'Use as share image') }}
						</NcButton>
					</template>
					<NcButton
						data-testid="media-picker-reference"
						@click="copyReference(selected)">
						{{ t('portaliq', 'Copy for a text') }}
					</NcButton>
				</span>
			</div>
		</template>
		<p v-if="copied" role="status" class="media__copied">
			{{ t('portaliq', 'Copied. Paste it into a text widget.') }}
		</p>

		<template #actions>
			<NcButton
				data-testid="media-picker-close"
				@click="$emit('update:open', false)">
				{{ t('portaliq', 'Close') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import {
	createMediaLibrary,
	markdownReference,
	thumbnailPath,
} from '../lib/mediaLibrary.js'

export default {
	name: 'MediaPickerDialog',

	components: {
		NcButton,
		CnDialog,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		/** The look to draw in; a spawned dialog gets the app's (src/lib/dialogLook.js). */
		look: {
			type: String,
			default: undefined,
		},

		/** Whether the dialog is open. */
		open: {
			type: Boolean,
			default: false,
		},

		/** The page's portal slug. */
		portal: {
			type: String,
			default: '',
		},

		/** The title of the page the media is chosen for, named above the dialog title. */
		pageTitle: {
			type: String,
			default: '',
		},
	},

	emits: ['update:open', 'choose'],

	data() {
		return {
			state: 'loading',
			items: [],
			selectedId: '',
			fileNames: {},
			copied: false,
		}
	},

	computed: {
		/**
		 * The selected card's item, or null.
		 *
		 * @return {object|null}
		 * @spec openspec/specs/admin-dialogs-board-look/spec.md#requirement-the-media-picker-draws-the-library-as-cards
		 */
		selected() {
			return this.items.find((item) => item.id === this.selectedId) || null
		},

		/**
		 * The context line above the title: the page and what is done for it.
		 *
		 * @return {string}
		 * @spec openspec/specs/admin-dialogs-board-look/spec.md#requirement-an-admin-dialog-names-where-it-was-opened
		 */
		eyebrow() {
			return this.pageTitle
				? t('portaliq', '{title}, choose media', { title: this.pageTitle })
				: t('portaliq', 'Choose media')
		},
	},

	watch: {
		open: {
			immediate: true,
			/**
			 * Read the library each time the dialog opens.
			 *
			 * @param {boolean} isOpen Whether the dialog is open.
			 * @return {Promise<void>}
			 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
			 */
			async handler(isOpen) {
				if (!isOpen) {
					return
				}
				this.state = 'loading'
				this.copied = false
				this.selectedId = ''
				this.fileNames = {}
				const library = createMediaLibrary({
					get: (path) => axios.get(generateUrl(path)),
				})
				const result = await library.load(this.portal)
				this.state = result.state
				this.items = result.items
				this.selectedId = result.items[0]?.id || ''
				await Promise.all(
					result.items.map(async (item) => {
						const name = await library.fileName(item.id)
						this.fileNames = { ...this.fileNames, [item.id]: name }
					}),
				)
			},
		},
	},

	methods: {
		t,

		/**
		 * Select a card; one at a time.
		 *
		 * @param {object} item The item.
		 * @return {void}
		 * @spec openspec/specs/admin-dialogs-board-look/spec.md#requirement-the-media-picker-draws-the-library-as-cards
		 */
		select(item) {
			this.selectedId = item.id
			this.copied = false
		},

		/**
		 * The thumbnail address of an image, '' for a file.
		 *
		 * @param {object} item The item.
		 * @return {string}
		 * @spec openspec/specs/admin-dialogs-board-look/spec.md#requirement-the-media-picker-draws-the-library-as-cards
		 */
		thumbnail(item) {
			const path = thumbnailPath(item, this.portal)
			return path ? generateUrl(path) : ''
		},

		/**
		 * Hand an image and its place to the designer.
		 *
		 * @param {object} item The item.
		 * @param {'hero'|'share'} target Which image.
		 * @return {void}
		 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
		 */
		choose(item, target) {
			this.$emit('choose', { item, target })
		},

		/**
		 * Put a markdown reference on the clipboard.
		 *
		 * @param {object} item The item.
		 * @return {Promise<void>}
		 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
		 */
		async copyReference(item) {
			try {
				await navigator.clipboard.writeText(markdownReference(item))
				this.copied = true
			} catch {
				this.copied = false
			}
		},
	},
}
</script>

<style scoped>
.media__intro {
	margin-bottom: 12px;
	color: var(--color-text-maxcontrast);
}

.media__grid {
	list-style: none;
	margin: 0;
	padding: 0;
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
	gap: 12px;
	max-height: 60vh;
	overflow-y: auto;
}

.media__card {
	display: flex;
	flex-direction: column;
	gap: 4px;
	width: 100%;
	height: 100%;
	margin: 0;
	padding: 8px;
	text-align: start;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	cursor: pointer;
}

.media__card:hover {
	border-color: var(--color-border-dark);
}

.media__card--selected {
	border: 2px solid var(--color-primary-element);
	padding: 7px;
}

.media__thumb {
	display: block;
	width: 100%;
	aspect-ratio: 4 / 3;
	object-fit: cover;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
}

.media__thumb--file {
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--color-text-maxcontrast);
}

.media__name {
	font-weight: bold;
	overflow-wrap: anywhere;
}

.media__alt {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.media__bar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	margin-top: 12px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.media__chosen {
	font-weight: bold;
}

.media__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.media__copied {
	margin-top: 8px;
}
</style>
