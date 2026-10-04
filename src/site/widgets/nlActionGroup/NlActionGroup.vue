<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	Several buttons side by side (design D1 row 2).

	It renders the button widget rather than its own markup, so one widget
	decides what a button is and a group of them cannot drift from a single one.
-->
<template>
	<div class="utrecht-action-group" data-testid="nl-action-group">
		<NlButtonLink
			v-for="(button, index) in safeButtons"
			:key="`${index}-${button.href}`"
			:label="button.label"
			:href="button.href"
			:kind="button.kind" />
	</div>
</template>

<script>
import NlButtonLink from '../nlButtonLink/NlButtonLink.vue'

import '@utrecht/action-group-css/dist/index.css'

export default {
	name: 'NlActionGroup',

	components: {
		NlButtonLink,
	},

	props: {
		/** The buttons: `{label, href, kind}`. */
		buttons: { type: Array, default: () => [] },
	},

	computed: {
		/**
		 * @return {Array<object>} The buttons that have a text and an address.
		 */
		safeButtons() {
			return (this.buttons || [])
				.filter(
					(button) => button && String(button.href || '').trim() !== '',
				)
				.map((button) => ({
					label: String(button.label || '').trim(),
					href: String(button.href).trim(),
					kind: String(button.kind || 'primary'),
				}))
		},
	},
}
</script>
