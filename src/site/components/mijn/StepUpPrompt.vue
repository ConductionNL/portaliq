<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<!--
		ASKING FOR A HIGHER LOGIN (REQ-SCR-004, board Inloggen).

		A page whose collection the resident's login is too low for. It names
		the login that reaches it and offers that login's card, the way the
		Inloggen board draws it, and nothing of what the page holds: no item,
		no count. After signing in the resident comes back to this page.
	-->
	<section
		class="pq-step-up"
		:data-collection="stepUp.collection"
		:data-min-trust="stepUp.minTrust"
		data-testid="site-step-up">
		<template v-if="ways.length > 0">
			<p class="utrecht-paragraph" data-testid="site-step-up-text">
				{{
					t('For these papers you must log in with {providers}.', {
						providers,
					})
				}}
			</p>
			<ul class="pq-step-up__cards">
				<li
					v-for="(way, index) in ways"
					:key="way.key || way.mode"
					class="pq-step-up__card"
					data-testid="site-step-up-card">
					<h2 class="utrecht-heading-3 pq-step-up__title">
						{{ (way.card && way.card.title) || way.label }}
					</h2>
					<p
						v-if="way.card && way.card.text"
						class="utrecht-paragraph pq-step-up__card-text">
						{{ way.card.text }}
					</p>
					<a
						class="utrecht-button-link utrecht-button-link--html-a"
						:class="
							index === 0
								? 'utrecht-button-link--primary-action'
								: 'utrecht-button-link--secondary-action'
						"
						:href="way.href"
						:data-mode="way.mode"
						data-testid="site-step-up-route">
						{{ way.label }}
					</a>
				</li>
			</ul>
		</template>
		<p v-else class="utrecht-paragraph" data-testid="site-step-up-none">
			{{ t('Logging in with DigiD is not available here yet.') }}
		</p>
	</section>
</template>

<script>
import { providerPhrase, stepUpWays } from '../../lib/stepUp.js'

// The button links' classes need their stylesheet, or the browser draws its own blue link.
import '@utrecht/button-link-css/dist/index.css'

/**
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t6
 */
export default {
	name: 'StepUpPrompt',

	props: {
		/** The aggregate's step-up entry: `{app, collection, minTrust}`. */
		stepUp: { type: Object, required: true },
		/** The portal's sign-in routes (authApi signInRoutes). */
		routes: { type: Array, default: () => [] },
		/** The path to come back to; the page on screen when empty. */
		back: { type: String, default: '' },
		t: { type: Function, required: true },
	},

	computed: {
		/**
		 * The routes that reach the level, each coming back here.
		 *
		 * @return {Array<object>} The routes.
		 *
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t6
		 */
		ways() {
			return stepUpWays(this.routes, this.back || currentPath())
		},

		/**
		 * @return {string} "DigiD or eHerkenning", in the site's language.
		 *
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t6
		 */
		providers() {
			return providerPhrase(this.ways, this.t)
		},
	},
}

/**
 * The page on screen as an in-site path, or '' outside a browser.
 *
 * @return {string} The path with its query.
 */
function currentPath() {
	if (typeof window === 'undefined' || !window.location) {
		return ''
	}
	return window.location.pathname + window.location.search
}
</script>

<style scoped>
.pq-step-up__cards {
	display: grid;
	gap: var(--utrecht-space-block-md, 1rem);
	grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
	list-style: none;
	margin: 0;
	padding: 0;
}

.pq-step-up__card {
	border: 1px solid var(--utrecht-color-grey-80, var(--color-border));
	border-radius: var(--utrecht-border-radius-md, 4px);
	padding: var(--utrecht-space-block-md, 1rem);
}

.pq-step-up__title {
	margin-block: 0 var(--utrecht-space-block-sm, 0.5rem);
}
</style>
