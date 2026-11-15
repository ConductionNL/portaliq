<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A zijpaneel that slides in from the edge (design D1 row 27).
-->
<template>
	<div class="nl-drawer" data-testid="nl-drawer">
		<button
			ref="openButton"
			type="button"
			class="utrecht-button utrecht-button--secondary-action"
			data-testid="nl-drawer-open"
			@click="show">
			{{ buttonLabel }}
		</button>

		<!--
			A DRAWER IS A DIALOG THAT COMES FROM THE SIDE. Native `<dialog>`
			again, for Escape and the focus containment; the sliding is CSS. A
			div with a transform would look the same and strand a keyboard user
			inside it.
		-->
		<dialog
			ref="dialog"
			class="utrecht-drawer nl-drawer__panel"
			data-testid="nl-drawer-panel"
			@close="onClose">
			<h2 v-if="heading" class="utrecht-heading-3">{{ heading }}</h2>
			<p class="utrecht-paragraph">{{ text }}</p>
			<button
				ref="closeButton"
				type="button"
				class="utrecht-button utrecht-button--subtle-action"
				data-testid="nl-drawer-close"
				@click="hide">
				{{ closeLabel }}
			</button>
		</dialog>
	</div>
</template>

<script>
import '@utrecht/drawer-css/dist/index.css'
import '@utrecht/button-css/dist/index.css'
import '@utrecht/heading-3-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

export default {
	name: 'NlDrawer',

	props: {
		/** The text on the button that opens it. */
		buttonLabel: { type: String, default: '' },
		/** The heading inside. */
		heading: { type: String, default: '' },
		/** The text inside. */
		text: { type: String, default: '' },
		/** The text on the close button. */
		closeLabel: { type: String, default: 'Sluiten' },
	},

	methods: {
		/**
		 * Open it modally and put focus on the way out.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		show() {
			this.$refs.dialog?.showModal?.()
			this.$nextTick(() => {
				this.$refs.closeButton?.focus?.()
			})
		},

		/**
		 * Close it from the button.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		hide() {
			this.$refs.dialog?.close?.()
		},

		/**
		 * However it was dismissed, Escape included, focus goes back to the
		 * button that opened it.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		onClose() {
			this.$refs.openButton?.focus?.()
		},
	},
}
</script>

<style scoped>
/*
 * The panel's geometry only: Utrecht's drawer CSS draws the rest, and every
 * value here is a token reference. No literal colour.
 */
.nl-drawer__panel {
	inline-size: min(28rem, 92vw);
	max-block-size: 100vh;
	margin-inline-start: auto;
	margin-block: 0;
	padding: var(--utrecht-space-block-md, 1rem);
	border: 0;
	background-color: var(--utrecht-document-background-color, Canvas);
	color: var(--utrecht-document-color, CanvasText);
}

.nl-drawer__panel::backdrop {
	background-color: var(--utrecht-document-color, CanvasText);
	opacity: 0.4;
}
</style>
