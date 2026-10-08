<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A paragraph of plain text (design D1 row 69).

	Text, not markup: a widget that rendered HTML from a field would be a second
	sanitisation posture beside the text widget's, and this app has one.
-->
<template>
	<p
		class="utrecht-paragraph"
		:class="{ 'utrecht-paragraph--lead': lead }"
		data-testid="nl-paragraph">
		{{ text }}
	</p>
</template>

<script>
import '@utrecht/paragraph-css/dist/index.css'

export default {
	name: 'NlParagraph',

	props: {
		/** The text the visitor reads. */
		text: { type: String, default: '' },
		/** Larger, as the first paragraph of a page (Utrecht's lead paragraph). */
		lead: { type: Boolean, default: false },
	},
}
</script>
