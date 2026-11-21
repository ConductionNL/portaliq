<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	Figures about the visitor on a CMS page (site-nlds-widget-palette T9,
	design D1 row 20): the figure cards of a contributing app's `kpi` block,
	named by its page, over the row the shell's subject-scoped read answers;
	or, without a source, the figures the author typed. Signed out it shows a
	sign-in prompt and reads nothing (REQ-SNW-012).
-->
<template>
	<div class="nl-figures" data-testid="nl-figures">
		<KpiCards
			v-if="signedIn"
			:cards="figures.cards"
			:row="figures.row"
			:loading="loading"
			:label="label || figures.label"
			:t="translate"
			:locale="shellProps.locale" />
		<SignInPrompt v-else v-bind="promptProps" />
	</div>
</template>

<script>
import KpiCards from '../../components/collections/KpiCards.vue'
import SignInPrompt from '../../components/mijn/SignInPrompt.vue'
import { pickRow } from '../../../shared/recordPage.js'
import { authoredFigures, ownBlock, ownCollection, ownRows } from '../ownData.js'
import ownRowsMixin from '../ownRowsMixin.js'

import '@utrecht/button-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

/**
 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
 */
export default {
	name: 'NlFigures',

	components: { KpiCards, SignInPrompt },

	mixins: [ownRowsMixin],

	props: {
		/** The page of the app whose `kpi` block the widget draws. */
		page: { type: String, default: '' },
		/** Figures the author typed, `{value, label}`, when there is no source. */
		items: { type: Array, default: () => [] },
	},

	computed: {
		/**
		 * @return {object|null} The app's kpi block the placement names.
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
		 */
		kpiBlock() {
			return ownBlock(this.mijn, this.app, this.page, 'kpi')
		},

		/**
		 * The cards and their row: the kpi block's over the visitor's row, else
		 * the authored figures.
		 *
		 * @return {{cards: Array<object>, row: object|null, label: string}} The figures.
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
		 */
		figures() {
			const block = this.kpiBlock
			if (block) {
				const cards = Array.isArray(block.cards) ? block.cards : []
				const row = pickRow(this.rows, block.pick)
				return { cards, row, label: block.label || '' }
			}
			return { ...authoredFigures(this.items), label: '' }
		},

		/**
		 * @return {(key: string) => string} The site translator, or the key.
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
		 */
		translate() {
			return typeof this.mijn?.t === 'function' ? this.mijn.t : (key) => key
		},
	},

	methods: {
		/**
		 * Read the kpi block's collection, when the placement names one.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
		 */
		async load() {
			const collection = this.kpiBlock
				? ownCollection(this.mijn, this.app, this.kpiBlock.collection)
				: null
			if (!this.signedIn || !collection) {
				return
			}
			this.loading = true
			const rows = await ownRows(this.mijn, collection)
			this.loading = false
			this.failed = rows === null
			this.rows = rows || []
		},
	},
}
</script>
