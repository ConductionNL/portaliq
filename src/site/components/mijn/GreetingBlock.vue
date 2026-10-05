<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The overview's opening (site-school-blocks, `greeting` block): today's
	date, "Goedemorgen, Fatima" by the hour, and at most one call to action
	on the right. On /mijn it is the page's heading, with the id and test id
	the account heading always had, so a test that waits for that heading
	finds it either way. The first name comes from the session, never from
	the page.
-->
<template>
	<div class="pq-greeting" data-testid="mijn-greeting">
		<div class="pq-greeting__text">
			<p v-if="block.showDate !== false" class="pq-greeting__date">
				{{ today }}
			</p>
			<h1
				v-if="pageHeading"
				id="site-account-title"
				class="utrecht-heading-1 pq-greeting__title"
				data-testid="site-account-title">
				{{ words }}
			</h1>
			<h2 v-else class="utrecht-heading-2 pq-greeting__title">{{ words }}</h2>
		</div>
		<!-- An action opens where the page opens actions: the host fills this. -->
		<div v-if="$slots.action" class="pq-greeting__action">
			<slot name="action" />
		</div>
		<a
			v-else-if="cta"
			class="utrecht-button utrecht-button--primary-action pq-greeting__action"
			:href="cta.href"
			data-testid="mijn-greeting-action"
			@click="open">
			{{ block.label }}
		</a>
	</div>
</template>

<script>
import { greetingFor } from './greeting.js'
import { mijnTranslator, siteHref } from './rows.js'

/**
 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-greeting-block-opens-the-overview
 */
export default {
	name: 'GreetingBlock',

	props: {
		/** The greeting block. */
		block: { type: Object, required: true },
		/** The session, for the first name. */
		session: { type: Object, default: null },
		/** The route of the page the block names, resolved by the host. */
		route: { type: String, default: '' },
		/** Whether this greeting is the page's own heading (/mijn). */
		pageHeading: { type: Boolean, default: false },
		/** Now, for a test. */
		now: { type: Date, default: null },
		/** The site translator. */
		t: { type: Function, default: null },
		/** The page language. */
		locale: { type: String, default: 'nl' },
	},

	emits: ['navigate'],

	computed: {
		/**
		 * @return {Function} The translator.
		 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-greeting-block-opens-the-overview
		 */
		tr() {
			return mijnTranslator(this.t, this.locale)
		},

		/**
		 * @return {string} "Goedemorgen, Fatima".
		 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-greeting-block-opens-the-overview
		 */
		words() {
			return greetingFor(this.session, this.now || new Date(), this.tr)
		},

		/**
		 * @return {string} "Maandag 5 oktober 2026".
		 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-greeting-block-opens-the-overview
		 */
		today() {
			const text = new Intl.DateTimeFormat(
				String(this.locale).startsWith('en') ? 'en-GB' : 'nl-NL',
				{ weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' },
			).format(this.now || new Date())
			return text.charAt(0).toUpperCase() + text.slice(1)
		},

		/**
		 * @return {object|null} The call to action, with its address.
		 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-greeting-block-opens-the-overview
		 */
		cta() {
			if (!this.block.label) {
				return null
			}
			const route = this.block.route || this.route
			return route ? { route, href: siteHref(route) } : null
		},
	},

	methods: {
		/**
		 * A plain click on the call to action stays in the site.
		 *
		 * @param {MouseEvent} event The click.
		 * @return {void}
		 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-greeting-block-opens-the-overview
		 */
		open(event) {
			if (event?.ctrlKey || event?.metaKey || event?.shiftKey) {
				return
			}
			event?.preventDefault?.()
			this.$emit('navigate', this.cta.route)
		},
	},
}
</script>

<style scoped>
.pq-greeting {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	justify-content: space-between;
	gap: 1rem 2rem;
	margin-block-end: var(--utrecht-space-block-lg, 1.5rem);
}

.pq-greeting__date {
	margin: 0 0 0.25rem;
	color: var(
		--nldesign-color-text-muted,
		var(--utrecht-document-color, CanvasText)
	);
}

.pq-greeting__title {
	margin: 0;
}

.pq-greeting__action {
	flex: none;
}
</style>
