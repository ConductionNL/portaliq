<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	Terms and what they mean, as "In het kort" reads them (design D1 row 24).
-->
<template>
	<dl class="utrecht-data-list" data-testid="nl-description-list">
		<div
			v-for="(item, index) in safeItems"
			:key="`${index}-${item.term}`"
			class="utrecht-data-list__item">
			<dt class="utrecht-data-list__item-key">{{ item.term }}</dt>
			<dd class="utrecht-data-list__item-value">{{ item.description }}</dd>
		</div>
	</dl>
</template>

<script>
import '@utrecht/data-list-css/dist/index.css'

export default {
	name: 'NlDescriptionList',

	props: {
		/** The rows: `{term, description}`. */
		items: { type: Array, default: () => [] },
	},

	computed: {
		/**
		 * @return {Array<object>} The rows that name something.
		 */
		safeItems() {
			return (this.items || [])
				.map((item) => ({
					term: String(item?.term ?? '').trim(),
					description: String(item?.description ?? '').trim(),
				}))
				.filter((item) => item.term !== '')
		},
	},
}
</script>
