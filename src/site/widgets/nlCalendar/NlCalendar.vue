<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	What is coming for the visitor, on a CMS page (site-nlds-widget-palette
	T9, design D1 row 9): the items of a contributing app's `calendar` block,
	named by its page, from the rows the shell's subject-scoped reads answer
	for each of its sources, drawn as date tiles. Signed out it shows a
	sign-in prompt and reads nothing (REQ-SNW-012).
-->
<template>
	<div class="nl-calendar" data-testid="nl-calendar">
		<SignInPrompt v-if="!signedIn" v-bind="promptProps" />
		<CalendarTiles
			v-else
			:items="items"
			:limit="rowLimit"
			:loading="loading"
			:label="label || (calendarBlock ? calendarBlock.label || '' : '')"
			:range="chosenRange"
			:t="shellProps.t"
			:locale="shellProps.locale" />
	</div>
</template>

<script>
import CalendarTiles from '../../components/mijn/CalendarTiles.vue'
import SignInPrompt from '../../components/mijn/SignInPrompt.vue'
import { itemsInRange } from '../../../shared/listWindow.js'
import { calendarItems } from '../../../shared/recordPage.js'
import { ownBlock, ownStore } from '../ownData.js'
import ownRowsMixin from '../ownRowsMixin.js'

import '@utrecht/button-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

/** The ranges a placement may choose. */
const RANGES = ['day', 'week', 'month']

/**
 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
 */
export default {
	name: 'NlCalendar',

	components: { CalendarTiles, SignInPrompt },

	mixins: [ownRowsMixin],

	props: {
		/** The page of the app whose `calendar` block the widget draws. */
		page: { type: String, default: '' },
		/** `day`, `week` or `month`; the block's own range otherwise. */
		range: { type: String, default: '' },
	},

	data() {
		return { store: {} }
	},

	computed: {
		/**
		 * @return {object|null} The app's calendar block the placement names.
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
		 */
		calendarBlock() {
			return ownBlock(this.mijn, this.app, this.page, 'calendar')
		},

		/**
		 * @return {string} The range in effect.
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
		 */
		chosenRange() {
			return RANGES.includes(this.range)
				? this.range
				: this.calendarBlock?.range || ''
		},

		/**
		 * @return {Array<object>} The block's items in the range, in date order.
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
		 */
		items() {
			if (!this.calendarBlock) {
				return []
			}
			return itemsInRange(
				calendarItems(this.calendarBlock, this.store, null),
				this.chosenRange,
				new Date(),
			)
		},
	},

	methods: {
		/**
		 * Read every source collection of the calendar block.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
		 */
		async load() {
			if (!this.signedIn || !this.calendarBlock) {
				return
			}
			this.loading = true
			const ids = (this.calendarBlock.sources || []).map(
				(source) => source?.collection,
			)
			const { store, failed } = await ownStore(this.mijn, this.app, ids)
			this.loading = false
			this.failed = failed
			this.store = store
		},
	},
}
</script>
