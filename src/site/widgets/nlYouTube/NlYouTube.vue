<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A YouTube video, embedded without its cookies (design D1 row 101).

	`youtube-nocookie.com`, `loading="lazy"` and a `title` on the frame: a
	government page should not set an advertising cookie for a visitor who
	never pressed play, should not pay for the frame until it is near, and must
	name the frame for somebody tabbing through the page.
-->
<template>
	<figure v-if="safeId" class="utrecht-figure" data-testid="nl-youtube">
		<iframe
			class="nl-youtube__frame"
			:src="`https://www.youtube-nocookie.com/embed/${safeId}`"
			:title="caption || 'YouTube'"
			loading="lazy"
			allowfullscreen
			referrerpolicy="strict-origin-when-cross-origin"></iframe>
		<figcaption v-if="caption" class="utrecht-figure__caption">
			{{ caption }}
		</figcaption>
	</figure>
</template>

<script>
import '@utrecht/figure-css/dist/index.css'

export default {
	name: 'NlYouTube',

	props: {
		/** The YouTube id, not a whole address. */
		videoId: { type: String, default: '' },
		/** The caption, which is also the frame's title. */
		caption: { type: String, default: '' },
	},

	computed: {
		/**
		 * The id, or '' when it is not one.
		 *
		 * An id and nothing else is accepted, because the src is built here: a
		 * field that took a whole URL would let an author point the frame at any
		 * origin they liked, which is not what a YouTube widget is for.
		 *
		 * @return {string} The id.
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		safeId() {
			const id = String(this.videoId || '').trim()
			return /^[A-Za-z0-9_-]{6,20}$/.test(id) ? id : ''
		},
	},
}
</script>
