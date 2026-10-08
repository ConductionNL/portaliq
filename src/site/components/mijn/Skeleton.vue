<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A list while it loads: grey blocks in the shape of its rows, hidden from
	assistive technology, and ONE status message, "Bezig met laden", that a
	screen reader hears and a sighted reader does not need.
-->
<template>
	<div class="pq-skeleton" data-testid="mijn-skeleton">
		<p class="sr-only" role="status">{{ label }}</p>
		<div class="pq-skeleton__rows" aria-hidden="true">
			<div v-for="n in count" :key="n" class="pq-skeleton__row">
				<span class="pq-skeleton__bar pq-skeleton__bar--title" />
				<span class="pq-skeleton__bar pq-skeleton__bar--meta" />
			</div>
		</div>
	</div>
</template>

<script>
/**
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-loading-and-empty-states-must-say-what-is-happening-req-smo-009
 */
export default {
	name: 'LoadingSkeleton',

	props: {
		/** The status message, "Bezig met laden" in the page language. */
		label: { type: String, required: true },
		/** How many rows to draw. */
		rows: { type: Number, default: 3 },
	},

	computed: {
		/**
		 * @return {number} One to ten rows.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-loading-and-empty-states-must-say-what-is-happening-req-smo-009
		 */
		count() {
			return Math.min(Math.max(Math.round(this.rows) || 1, 1), 10)
		},
	},
}
</script>

<style scoped>
.pq-skeleton__row {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	padding-block: var(--utrecht-space-block-md, 1rem);
	border-block-end: 1px solid var(--utrecht-color-grey-90, #e6e6e6);
}

.pq-skeleton__bar {
	display: block;
	block-size: 0.875rem;
	border-radius: 0.25rem;
	background-color: var(--utrecht-color-grey-90, #e6e6e6);
}

.pq-skeleton__bar--title {
	inline-size: 60%;
}

.pq-skeleton__bar--meta {
	inline-size: 35%;
}

@media (prefers-reduced-motion: no-preference) {
	.pq-skeleton__bar {
		animation: pq-skeleton-pulse 1.5s ease-in-out infinite;
	}
}

@keyframes pq-skeleton-pulse {
	50% {
		opacity: 0.5;
	}
}
</style>
