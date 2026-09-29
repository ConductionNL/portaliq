<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  MediaPickerDialog: the published items of the page's portal media library
  (site-page-seo-history-and-media T08). An image can become the page's hero
  image or share image; any item gives a markdown reference to paste into a
  text widget. The dialog only hands the choice to the designer, which stores
  a media:<id> reference, so replacing the item's file later updates the page.
  See src/lib/mediaLibrary.js.

  @spec openspec/changes/site-page-seo-history-and-media/specs/site-page-seo-history-and-media/spec.md
-->
<template>
	<NcDialog
		:name="t('portaliq', 'Media')"
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
		<ul v-else class="media__list">
			<li
				v-for="item in items"
				:key="item.id"
				class="media__item"
				data-testid="media-picker-item">
				<span class="media__title">{{ item.title }}</span>
				<span class="media__actions">
					<template v-if="item.kind === 'image'">
						<NcButton
							data-testid="media-picker-hero"
							@click="choose(item, 'hero')">
							{{ t('portaliq', 'Use as hero image') }}
						</NcButton>
						<NcButton
							data-testid="media-picker-share"
							@click="choose(item, 'share')">
							{{ t('portaliq', 'Use as share image') }}
						</NcButton>
					</template>
					<NcButton
						data-testid="media-picker-reference"
						@click="copyReference(item)">
						{{ t('portaliq', 'Copy for a text') }}
					</NcButton>
				</span>
			</li>
		</ul>
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
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { createMediaLibrary, markdownReference } from '../lib/mediaLibrary.js'

export default {
	name: 'MediaPickerDialog',

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

		/** The page's portal slug. */
		portal: {
			type: String,
			default: '',
		},
	},

	emits: ['update:open', 'choose'],

	data() {
		return {
			state: 'loading',
			items: [],
			copied: false,
		}
	},

	watch: {
		open: {
			immediate: true,
			/**
			 * Read the library each time the dialog opens.
			 *
			 * @param {boolean} isOpen Whether the dialog is open.
			 * @return {Promise<void>}
			 * @spec openspec/changes/site-page-seo-history-and-media/specs/site-page-seo-history-and-media/spec.md
			 */
			async handler(isOpen) {
				if (!isOpen) {
					return
				}
				this.state = 'loading'
				this.copied = false
				const library = createMediaLibrary({
					get: (path) => axios.get(generateUrl(path)),
				})
				const result = await library.load(this.portal)
				this.state = result.state
				this.items = result.items
			},
		},
	},

	methods: {
		/**
		 * Hand an image and its place to the designer.
		 *
		 * @param {object} item The item.
		 * @param {'hero'|'share'} target Which image.
		 * @return {void}
		 * @spec openspec/changes/site-page-seo-history-and-media/specs/site-page-seo-history-and-media/spec.md
		 */
		choose(item, target) {
			this.$emit('choose', { item, target })
		},

		/**
		 * Put a markdown reference on the clipboard.
		 *
		 * @param {object} item The item.
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-page-seo-history-and-media/specs/site-page-seo-history-and-media/spec.md
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

.media__list {
	list-style: none;
	margin: 0;
	padding: 0;
	max-height: 60vh;
	overflow-y: auto;
}

.media__item {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
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
