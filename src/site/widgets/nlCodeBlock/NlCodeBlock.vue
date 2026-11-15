<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A block of code, shown as it was written (design D1 row 15).

	Text inside `pre`/`code`, interpolated rather than bound as HTML: a code
	example is the one place an author's angle brackets must stay angle
	brackets.
-->
<template>
	<figure class="utrecht-figure" data-testid="nl-code-block">
		<pre class="nl-code-block"><code>{{ code }}</code></pre>
		<figcaption v-if="label" class="utrecht-figure__caption">
			{{ label }}
		</figcaption>
	</figure>
</template>

<script>
import '@nl-design-system-candidate/code-block-css/code-block.css'
import '@utrecht/figure-css/dist/index.css'

export default {
	name: 'NlCodeBlock',

	props: {
		/** The code to show. */
		code: { type: String, default: '' },
		/** What the example shows. */
		label: { type: String, default: '' },
	},
}
</script>
