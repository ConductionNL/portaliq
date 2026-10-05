<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The ways in this portal offers (design D1 row 55).

	THE WAYS IN ARE THE PORTAL'S, NOT THE PLACEMENT'S. A page cannot invent a
	sign-in route: the shell hands down what the portal declares (DigiD,
	eHerkenning, the broker), and this widget renders those and nothing else. A
	placement that could name its own address would be a phishing surface on a
	government page.
-->
<template>
	<div class="nl-signin" data-testid="nl-sign-in">
		<h2 v-if="heading" class="utrecht-heading-3">{{ heading }}</h2>
		<ul class="nl-signin__list">
			<li v-for="way in safeWays" :key="way.id" class="nl-signin__item">
				<a
					class="utrecht-button utrecht-button--primary-action"
					:class="{ 'utrecht-digid-button': way.id === 'digid' }"
					:href="way.href"
					:data-testid="`nl-sign-in-${way.id}`">
					{{ way.label }}
				</a>
			</li>
		</ul>
		<p
			v-if="safeWays.length === 0"
			class="utrecht-paragraph"
			data-testid="nl-sign-in-none">
			{{ noneLabel }}
		</p>
	</div>
</template>

<script>
import '@utrecht/digid-button-css/dist/index.css'
import '@utrecht/button-css/dist/index.css'
import '@utrecht/heading-3-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

export default {
	name: 'NlSignIn',

	props: {
		/** The heading above the buttons. */
		heading: { type: String, default: '' },
		/** The ways in the portal declares: `{id, label, href}`. */
		ways: { type: Array, default: () => [] },
		/** What is said when the portal declares none. */
		noneLabel: {
			type: String,
			default: 'Dit portaal heeft nog geen manier om in te loggen.',
		},
	},

	computed: {
		/**
		 * The ways in that name a route INSIDE this site. An absolute address is
		 * refused: a sign-in link to another origin is the one link on a
		 * government page that must never be authorable.
		 *
		 * @return {Array<object>} The ways in.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		safeWays() {
			return (this.ways || [])
				.map((way) => ({
					id: String(way?.id ?? '').trim(),
					label: String(way?.label ?? '').trim(),
					href: String(way?.href ?? '').trim(),
				}))
				.filter(
					(way) =>
						way.id !== ''
						&& way.label !== ''
						&& way.href.startsWith('/')
						&& !way.href.startsWith('//'),
				)
		},
	},
}
</script>

<style scoped>
/* Layout only; the buttons bring their own colours from their packages. */
.nl-signin__list {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
	margin: 0;
	padding: 0;
	list-style: none;
}
</style>
