<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A quotation, with who said it (design D1 row 6).

	The source sits in a `figcaption` beside the `blockquote` rather than inside
	it: what somebody said and who said it are two things, and a screen reader
	should not read the attribution as part of the quotation.
-->
<template>
	<figure class="utrecht-figure" data-testid="nl-quote">
		<blockquote class="utrecht-blockquote">
			<p class="utrecht-paragraph">{{ text }}</p>
		</blockquote>
		<figcaption v-if="source" class="utrecht-figure__caption">
			{{ source }}
		</figcaption>
	</figure>
</template>

<script>
import '@utrecht/blockquote-css/dist/index.css'
import '@utrecht/figure-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

export default {
	name: 'NlQuote',

	props: {
		/** The quotation. */
		text: { type: String, default: '' },
		/** Who said it. */
		source: { type: String, default: '' },
	},
}
</script>
