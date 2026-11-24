<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	For whom a CMS page shows (site-nlds-widget-palette T9): a radio group
	over the visitor's own rows of the named collection (a guardian's
	children), read through the shell's subject-scoped api. The record the
	route carries is the chosen one; choosing another opens the authored page
	with that record's id after it. Signed out it shows a sign-in prompt and
	reads nothing (REQ-SNW-012).
-->
<template>
	<div class="nl-record-switcher" data-testid="nl-record-switcher">
		<SignInPrompt v-if="!signedIn" v-bind="promptProps" />
		<RecordSwitcher
			v-else-if="rows.length > 0"
			:rows="rows"
			:chosen="routeParam"
			:titleFields="titleList"
			:subtitleFields="subtitleList"
			:legend="label || tr('Choose for whom')"
			:name="`nl-record-switcher-${collection}`"
			@choose="choose" />
		<p
			v-else-if="!loading"
			class="utrecht-paragraph"
			data-testid="nl-record-switcher-none">
			{{ tr('There is nothing to show here yet.') }}
		</p>
	</div>
</template>

<script>
import RecordSwitcher from '../../components/mijn/RecordSwitcher.vue'
import SignInPrompt from '../../components/mijn/SignInPrompt.vue'
import { mijnTranslator } from '../../components/mijn/rows.js'
import { ownRecord, recordRouteOf } from '../ownData.js'
import ownRows from '../ownRowsMixin.js'

import '@utrecht/button-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

/**
 * @param {unknown} value A list of field names, or one.
 * @return {Array<string>} The names.
 */
function namesOf(value) {
	return (Array.isArray(value) ? value : [value]).filter(
		(name) => typeof name === 'string' && name.trim() !== '',
	)
}

/**
 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
 */
export default {
	name: 'NlRecordSwitcher',

	components: { RecordSwitcher, SignInPrompt },

	mixins: [ownRows],

	props: {
		/** The fields that name a row. */
		titleFields: { type: [Array, String], default: () => [] },
		/** The fields under its name. */
		subtitleFields: { type: [Array, String], default: () => [] },
		/** The in-site page a chosen record opens, its id after it. */
		route: { type: String, default: '' },
	},

	emits: ['navigate'],

	computed: {
		/**
		 * @return {Array<string>} The title fields.
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
		 */
		titleList() {
			const own = namesOf(this.titleFields)
			return own.length > 0 ? own : namesOf(this.ownCollection?.titleFields)
		},

		/**
		 * @return {Array<string>} The subtitle fields.
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
		 */
		subtitleList() {
			return namesOf(this.subtitleFields)
		},

		/**
		 * @return {(key: string) => string} The translator.
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
		 */
		tr() {
			return mijnTranslator(
				this.mijn?.t || null,
				this.mijn?.locale || this.locale,
			)
		},
	},

	methods: {
		/**
		 * Open the authored page for the chosen record, one of the visitor's own.
		 *
		 * @param {string} id The chosen row's id.
		 * @return {void}
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
		 */
		choose(id) {
			const next = ownRecord(this.rows, id)
				? recordRouteOf(this.route, id)
				: ''
			if (next !== '') {
				this.$emit('navigate', next)
			}
		},
	},
}
</script>
