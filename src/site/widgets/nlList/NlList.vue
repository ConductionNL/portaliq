<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A bulleted or numbered list (design D1 rows 99 and 63).
-->
<template>
	<component
		:is="ordered ? 'ol' : 'ul'"
		:class="ordered ? 'utrecht-ordered-list' : 'utrecht-unordered-list'"
		data-testid="nl-list">
		<li
			v-for="(item, index) in safeItems"
			:key="`${index}-${item}`"
			:class="
				ordered
					? 'utrecht-ordered-list__item'
					: 'utrecht-unordered-list__item'
			">
			{{ item }}
		</li>
	</component>
</template>

<script>
import '@utrecht/unordered-list-css/dist/index.css'
import '@utrecht/ordered-list-css/dist/index.css'

export default {
	name: 'NlList',

	props: {
		/** The lines. */
		items: { type: Array, default: () => [] },
		/** Numbered instead of bulleted. */
		ordered: { type: Boolean, default: false },
	},

	computed: {
		/**
		 * @return {Array<string>} The lines that have text.
		 */
		safeItems() {
			return (this.items || [])
				.map((item) => String(item ?? '').trim())
				.filter((item) => item !== '')
		},
	},
}
</script>
