<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The visitor's newest messages on a CMS page (site-nlds-widget-palette T9):
	the unified inbox, or one app's inbox collection when the placement names
	one, unread first. The inbox block reads through the shell's api; signed
	out the widget shows a sign-in prompt and reads nothing (REQ-SNW-012).
-->
<template>
	<div class="nl-inbox" data-testid="nl-inbox">
		<InboxBlock
			v-if="signedIn"
			:block="{ collection, limit: rowLimit, label }"
			:api="mijn.api"
			:app="app"
			:nav="mijn.nav || []"
			:t="mijn.t || null"
			:locale="mijn.locale || 'nl'"
			@navigate="$emit('navigate', $event)" />
		<SignInPrompt
			v-else
			:label="label"
			:signInRoutes="signInRoutes"
			:locale="locale" />
	</div>
</template>

<script>
import InboxBlock from '../../components/mijn/InboxBlock.vue'
import SignInPrompt from '../../components/mijn/SignInPrompt.vue'
import ownRows from '../ownRowsMixin.js'

import '@utrecht/button-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

/**
 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
 */
export default {
	name: 'NlInbox',

	components: { InboxBlock, SignInPrompt },

	mixins: [ownRows],

	emits: ['navigate'],

	methods: {
		/**
		 * The inbox block reads the messages itself, through the shell's api.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
		 */
		async load() {},
	},
}
</script>
