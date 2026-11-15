<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A video the portal hosts itself (design D1 row 100).

	`preload="none"`, so a page with a video on it costs a visitor nothing
	until they press play. Captions are the author's to add on the file; the
	widget cannot invent them, and that is a limitation worth knowing rather
	than hiding.
-->
<template>
	<figure class="utrecht-figure" data-testid="nl-video">
		<!-- eslint-disable-next-line vuejs-accessibility/media-has-caption -->
		<video class="nl-video__player" :src="src" controls preload="none"></video>
		<figcaption v-if="caption" class="utrecht-figure__caption">
			{{ caption }}
		</figcaption>
	</figure>
</template>

<script>
import '@utrecht/figure-css/dist/index.css'

export default {
	name: 'NlVideo',

	props: {
		/** Where the video is. */
		src: { type: String, default: '' },
		/** The caption under it. */
		caption: { type: String, default: '' },
	},
}
</script>
