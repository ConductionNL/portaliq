<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	What happened to one of the visitor's records, on a CMS page
	(site-nlds-widget-palette T9, design D1 row 17): the record the route
	names (`/zaak/<id>`), found among the rows the shell's subject-scoped read
	answered for the named collection, as a contact timeline. A route naming
	a record that is not the visitor's finds nothing and shows nothing.
	Signed out the widget shows a sign-in prompt and reads nothing
	(REQ-SNW-012).
-->
<template>
	<div class="nl-timeline" data-testid="nl-timeline">
		<template v-if="signedIn">
			<TimelineBlock
				v-if="record"
				:block="{ collection, label }"
				:collection="ownCollection"
				:record="record"
				:api="mijn.api"
				:t="mijn.t || null"
				:locale="mijn.locale || 'nl'" />
			<p
				v-else-if="!loading"
				class="utrecht-paragraph"
				data-testid="nl-timeline-none">
				{{ tr('There is nothing to show here yet.') }}
			</p>
		</template>
		<SignInPrompt
			v-else
			:label="label"
			:signInRoutes="signInRoutes"
			:locale="locale" />
	</div>
</template>

<script>
import SignInPrompt from '../../components/mijn/SignInPrompt.vue'
import TimelineBlock from '../../components/mijn/TimelineBlock.vue'
import { mijnTranslator } from '../../components/mijn/rows.js'
import { ownRecord } from '../ownData.js'
import ownRows from '../ownRowsMixin.js'

import '@utrecht/button-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

/**
 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
 */
export default {
	name: 'NlTimeline',

	components: { SignInPrompt, TimelineBlock },

	mixins: [ownRows],

	computed: {
		/**
		 * @return {object|null} The visitor's own row the route names.
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
		 */
		record() {
			return ownRecord(this.rows, this.routeParam)
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
}
</script>
