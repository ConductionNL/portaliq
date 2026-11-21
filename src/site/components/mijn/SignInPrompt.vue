<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	What a Mijn omgeving widget shows a visitor who is not signed in
	(site-nlds-widget-palette REQ-SNW-012): one sentence and the portal's own
	ways in. It reads and fetches nothing, so a CMS page with a case list sends
	no case request for a visitor without a session. Without a declared way in
	it links to the signed-in area, which asks for one.
-->
<template>
	<section class="pq-sign-in-prompt" data-testid="mijn-sign-in-prompt">
		<component :is="`h${level}`" v-if="label" class="utrecht-heading-3">
			{{ label }}
		</component>
		<p class="utrecht-paragraph">{{ tr('Sign in to see this.') }}</p>
		<p v-for="way in ways" :key="way.id" class="utrecht-paragraph">
			<a
				class="utrecht-button utrecht-button--primary-action"
				:href="way.href"
				:data-testid="`mijn-sign-in-${way.id}`">
				{{ way.label }}
			</a>
		</p>
		<p v-if="ways.length === 0" class="utrecht-paragraph">
			<a
				class="utrecht-link"
				:href="accountHref"
				data-testid="mijn-sign-in-account"
				>{{ tr('Sign in') }}</a
			>
		</p>
	</section>
</template>

<script>
import { signInWays } from '../../widgets/ownData.js'
import { mijnTranslator, siteHref } from './rows.js'

/**
 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
 */
export default {
	name: 'SignInPrompt',

	props: {
		/** The widget's heading, '' for none. */
		label: { type: String, default: '' },
		/** The heading level. */
		level: { type: Number, default: 2 },
		/** The portal's sign-in routes, as the shell derived them. */
		signInRoutes: { type: Array, default: () => [] },
		/** The page language. */
		locale: { type: String, default: 'nl' },
	},

	computed: {
		/**
		 * @return {(key: string) => string} The translator.
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
		 */
		tr() {
			return mijnTranslator(null, this.locale)
		},

		/**
		 * @return {Array<{id: string, label: string, href: string}>} The portal's ways in.
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
		 */
		ways() {
			return signInWays(this.signInRoutes)
		},

		/**
		 * @return {string} The address of the signed-in area.
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
		 */
		accountHref() {
			return siteHref('/mijn')
		},
	},
}
</script>
