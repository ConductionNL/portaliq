<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<component :is="filler" v-if="filler" v-bind="$attrs" />
	<div v-else hidden :data-slot="name" :data-testid="`collections-slot-${name}`" />
</template>

<script>
import { defineAsyncComponent, markRaw } from 'vue'
import { blockSlotLoader } from '../../pages/collections/blockSlots.js'

/**
 * A place on a contribution page that another slice fills (see
 * src/site/pages/collections/blockSlots.js). Everything handed to it, props
 * and listeners alike, goes to the filler. Unfilled, it leaves a hidden
 * marker and nothing a resident sees.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-contribution-page-must-render-its-blocks-req-srp-014
 */
export default {
	name: 'SlotHost',

	inheritAttrs: false,

	props: {
		/** The place, one of BLOCK_SLOTS. */
		name: { type: String, required: true },
	},

	computed: {
		filler() {
			const loader = blockSlotLoader(this.name)
			return loader ? markRaw(defineAsyncComponent(loader)) : null
		},
	},
}
</script>
