<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A kanttekening beside the content (design D1 row 60).

	An `aside`, and deliberately NOT announced: a note is context an author
	added, not news that arrived, so it is read where it sits like any other
	text.
-->
<template>
	<aside class="utrecht-note" data-testid="nl-note">
		<p v-if="heading" class="utrecht-heading-3">{{ heading }}</p>
		<p class="utrecht-paragraph">{{ text }}</p>
	</aside>
</template>

<script>
import '@utrecht/note-css/dist/index.css'
import '@utrecht/heading-3-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

export default {
	name: 'NlNote',

	props: {
		/** The heading above the text. */
		heading: { type: String, default: '' },
		/** The text the visitor reads. */
		text: { type: String, default: '' },
	},
}
</script>
