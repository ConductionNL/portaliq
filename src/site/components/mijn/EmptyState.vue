<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	What a list says when it has nothing: one sentence naming what is empty
	("U heeft nog geen berichten."), and, when there is one, the thing to do
	as a link. Never a bare dash or an empty table.
-->
<template>
	<div class="pq-empty-state" data-testid="mijn-empty-state">
		<p class="utrecht-paragraph pq-empty-state__text">{{ text }}</p>
		<a
			v-if="actionLabel && actionRoute"
			class="utrecht-link pq-empty-state__action"
			:href="href"
			@click="onClick">
			{{ actionLabel }}
		</a>
	</div>
</template>

<script>
import { siteHref } from './rows.js'

/**
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-loading-and-empty-states-must-say-what-is-happening-req-smo-009
 */
export default {
	name: 'EmptyState',

	props: {
		/** The sentence that says what is empty. */
		text: { type: String, required: true },
		/** The thing to do, or ''. */
		actionLabel: { type: String, default: '' },
		/** The in-site route of that thing, or ''. */
		actionRoute: { type: String, default: '' },
	},

	emits: ['navigate'],

	computed: {
		/**
		 * @return {string} The action's real address.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-loading-and-empty-states-must-say-what-is-happening-req-smo-009
		 */
		href() {
			return siteHref(this.actionRoute)
		},
	},

	methods: {
		/**
		 * @param {MouseEvent} event The click.
		 * @return {void}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-loading-and-empty-states-must-say-what-is-happening-req-smo-009
		 */
		onClick(event) {
			if (event?.ctrlKey || event?.metaKey || event?.shiftKey) {
				return
			}
			event?.preventDefault?.()
			this.$emit('navigate', this.actionRoute)
		},
	},
}
</script>

<style scoped>
.pq-empty-state {
	padding-block: var(--utrecht-space-block-md, 1rem);
}

.pq-empty-state__text {
	margin: 0;
}
</style>
