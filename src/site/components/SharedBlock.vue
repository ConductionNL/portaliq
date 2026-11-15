<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A block shared by the portals of one organisation (site-shared-page-blocks).

	The content API has already put the block's published widgets into
	`widgets`; this draws them as a nested grid in the placement's cell, with
	the same renderer and the same context as the page. A block that is
	missing, unpublished or another organisation's arrives with `unavailable`
	and no widgets, and draws nothing: a visitor sees no hole and no reason.
-->
<template>
	<div
		v-if="shown"
		class="pq-shared-block"
		data-testid="shared-block"
		:data-block="block">
		<WidgetGrid v-bind="host" :widgets="widgets" />
	</div>
</template>

<script>
import WidgetGrid from './WidgetGrid.vue'

/**
 * @spec openspec/changes/site-shared-page-blocks/tasks.md#t04
 */
export default {
	name: 'SharedBlock',

	components: { WidgetGrid },

	props: {
		/** The block's id, as the page places it. */
		block: { type: String, default: '' },
		/** The block's published widgets, put here by the content API. */
		widgets: { type: Array, default: () => [] },
		/** Set by the content API when the block cannot be shown on this portal. */
		unavailable: { type: Boolean, default: false },
		/** The page's own props (portal, session, route), handed on to the nested grid. */
		host: { type: Object, default: () => ({}) },
	},

	computed: {
		/**
		 * @return {boolean} Whether there is anything to draw.
		 * @spec openspec/changes/site-shared-page-blocks/tasks.md#t04
		 */
		shown() {
			return !this.unavailable && this.widgets.length > 0
		},
	},
}
</script>
