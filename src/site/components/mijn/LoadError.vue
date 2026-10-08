<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	What a list says when it could not be loaded: an Utrecht alert with one
	plain sentence and a button to try again. A failed read is never shown as
	an empty list, because "nothing to do" and "could not ask" lead a
	resident to different conclusions.
-->
<template>
	<div
		class="utrecht-alert utrecht-alert--error pq-load-error"
		role="alert"
		data-testid="mijn-load-error">
		<div class="utrecht-alert__content">
			<p class="utrecht-alert__message utrecht-paragraph">{{ text }}</p>
			<button
				type="button"
				class="utrecht-button utrecht-button--secondary-action"
				data-testid="mijn-load-error-retry"
				@click="$emit('retry')">
				{{ retryLabel }}
			</button>
		</div>
	</div>
</template>

<script>
/**
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-loading-and-empty-states-must-say-what-is-happening-req-smo-009
 */
export default {
	name: 'LoadError',

	props: {
		/** The sentence that says what could not be loaded. */
		text: { type: String, required: true },
		/** The button's words, "Opnieuw proberen" in the page language. */
		retryLabel: { type: String, required: true },
	},

	emits: ['retry'],
}
</script>

<style>
/* The alert's look: the Utrecht alert CSS, loaded with this component. */
@import '@utrecht/alert-css/dist/index.css';
</style>

<style scoped>
.pq-load-error .utrecht-alert__content {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: var(--utrecht-space-block-sm, 0.5rem);
}

.pq-load-error .utrecht-alert__message {
	margin: 0;
}
</style>
