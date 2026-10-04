<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A venster an author opens from a button (design D1 rows 25, 4 and 58).

	The native `<dialog>`, as design D5 says: Escape, the backdrop and the
	focus containment are the browser's, which is exactly why a hand-built
	overlay is the wrong answer. What the widget adds is where focus lands and
	where it goes back to.
-->
<template>
	<div class="nl-dialog" data-testid="nl-dialog">
		<button
			ref="openButton"
			type="button"
			class="utrecht-button utrecht-button--primary-action"
			data-testid="nl-dialog-open"
			@click="show">
			{{ buttonLabel }}
		</button>

		<!--
			THE NATIVE ELEMENT, not a div with a z-index (design D5). The browser
			gives Escape, the backdrop, the top layer and the focus trap for a
			modal; a hand-built overlay gives none of those and usually forgets
			Escape. `@close` catches the Escape the browser handles itself, so the
			button gets focus back whichever way it was dismissed.
		-->
		<dialog
			ref="dialog"
			class="nl-dialog__window"
			:class="`nl-dialog__window--${safeVariant}`"
			data-testid="nl-dialog-window"
			@close="onClose">
			<h2 v-if="heading" class="utrecht-heading-3">{{ heading }}</h2>
			<p class="utrecht-paragraph">{{ text }}</p>
			<button
				ref="closeButton"
				type="button"
				class="utrecht-button utrecht-button--secondary-action"
				data-testid="nl-dialog-close"
				@click="hide">
				{{ closeLabel }}
			</button>
		</dialog>
	</div>
</template>

<script>
import '@utrecht/alert-dialog-css/dist/index.css'
import '@utrecht/button-css/dist/index.css'
import '@utrecht/heading-3-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

export default {
	name: 'NlDialog',

	props: {
		/** The text on the button that opens it. */
		buttonLabel: { type: String, default: '' },
		/** The heading inside. */
		heading: { type: String, default: '' },
		/** The text inside. */
		text: { type: String, default: '' },
		/** `dialog`, `modal` or `alert`. */
		variant: { type: String, default: 'dialog' },
		/** The text on the close button. */
		closeLabel: { type: String, default: 'Sluiten' },
	},

	computed: {
		/**
		 * @return {string} One of dialog, modal or alert.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		safeVariant() {
			return ['dialog', 'modal', 'alert'].includes(this.variant)
				? this.variant
				: 'dialog'
		},
	},

	methods: {
		/**
		 * Open it. A `modal` or an `alert` opens modally, which is what gives
		 * the backdrop and the browser's own focus containment; a plain
		 * `dialog` opens non-modally, so the page behind it stays usable.
		 *
		 * Focus goes to the close button, so the first thing a keyboard user
		 * meets inside is the way out.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		show() {
			const dialog = this.$refs.dialog
			if (!dialog) {
				return
			}

			if (this.safeVariant === 'dialog') {
				dialog.show?.()
			} else {
				dialog.showModal?.()
			}

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
		 * Whichever way it was dismissed, including the Escape the browser
		 * handles itself, focus returns to the button that opened it: a
		 * keyboard user ends up where they were rather than at the top of the
		 * page.
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
 * TOKENS ONLY: NL Design System publishes CSS for the alert dialog and for
 * buttons, but not for a plain dialog's own box (design D5), so the box is
 * drawn from `--utrecht-*` references with token fallbacks. No literal colour
 * appears here, and `tests/widget-tokens.spec.mjs` fails if one ever does.
 */
.nl-dialog__window {
	max-inline-size: min(40rem, 90vw);
	padding: var(--utrecht-space-block-md, 1rem);
	border: var(--utrecht-focus-outline-width, 1px) solid
		var(--utrecht-color-grey-30, currentcolor);
	border-radius: var(--utrecht-border-radius-md, 4px);
	background-color: var(--utrecht-document-background-color, Canvas);
	color: var(--utrecht-document-color, CanvasText);
}

.nl-dialog__window::backdrop {
	/* The browser draws it; the colour is the document's own ink, faded. */
	background-color: var(--utrecht-document-color, CanvasText);
	opacity: 0.4;
}
</style>
