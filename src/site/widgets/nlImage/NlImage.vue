<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	An image, with a caption when the author wrote one (design D1 rows 50 and 29).

	`alt` is a field of its own and always offered, because an image on a
	government page without it is a WCAG failure the author is the only one who
	can fix. An empty `alt` is a valid answer for decoration, which is why it is
	not refused.
-->
<template>
	<figure v-if="caption" class="utrecht-figure" data-testid="nl-image">
		<img class="utrecht-img" :src="src" :alt="alt" />
		<figcaption class="utrecht-figure__caption">{{ caption }}</figcaption>
	</figure>
	<img v-else class="utrecht-img" :src="src" :alt="alt" data-testid="nl-image" />
</template>

<script>
import '@utrecht/img-css/dist/index.css'
import '@utrecht/figure-css/dist/index.css'

export default {
	name: 'NlImage',

	props: {
		/** Where the image is. */
		src: { type: String, default: '' },
		/** What there is to see, for somebody who cannot see it. */
		alt: { type: String, default: '' },
		/** The caption under it. */
		caption: { type: String, default: '' },
	},
}
</script>
