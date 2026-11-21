<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The visitor's cases on a CMS page (site-nlds-widget-palette T9, design D1
	row 11): the case cards of one `cases` collection of a contributing app,
	read through the shell's subject-scoped api. Signed out it shows a sign-in
	prompt and sends no case request (REQ-SNW-012).
-->
<template>
	<div class="nl-cases" data-testid="nl-cases">
		<CasesBlock
			v-if="signedIn"
			:block="{
				collection,
				open: openOnly,
				limit: rowLimit,
				label,
			}"
			:collection="ownCollection"
			:rows="rows"
			:loading="loading"
			:failed="failed"
			:api="mijn.api"
			:app="app"
			:nav="mijn.nav || []"
			:t="mijn.t || null"
			:locale="mijn.locale || 'nl'"
			@navigate="$emit('navigate', $event)"
			@retry="load" />
		<SignInPrompt
			v-else
			:label="label"
			:signInRoutes="signInRoutes"
			:locale="locale" />
	</div>
</template>

<script>
import CasesBlock from '../../components/mijn/CasesBlock.vue'
import SignInPrompt from '../../components/mijn/SignInPrompt.vue'
import ownRows from '../ownRowsMixin.js'

import '@utrecht/button-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

/**
 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
 */
export default {
	name: 'NlCases',

	components: { CasesBlock, SignInPrompt },

	mixins: [ownRows],

	props: {
		/** Only the running cases. */
		openOnly: { type: Boolean, default: false },
	},

	emits: ['navigate'],
}
</script>
