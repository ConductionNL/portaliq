<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The install offer (parent-pwa-installability), ported from the
	`portaliq-install-banner` in the React portal's App.jsx. The browser's own offer
	(`beforeinstallprompt`) is captured so the site can show its own control.
	A browser that never makes the offer (Safari, or an app that is already
	installed) gets nothing at all: the banner renders only while an offer is
	held, by construction rather than by a flag. "Not now" hides it for this
	page view (REQ-SRP-046).
-->
<template>
	<!--
		A DIALOG, NOT A STRIP IN THE FLOW. Seen on the Zuiddrecht demo (6 Oct
		2026): the offer rendered between the menu line and the first block
		and pushed the whole page down. It now floats over the page, centred,
		with the focus held inside it; Escape and the scrim answer "Not now".
	-->
	<div
		v-if="visible"
		class="pq-install-dialog"
		data-testid="install-banner"
		@keydown="onKey">
		<!-- The scrim is a mouse convenience with no meaning of its own: the
		     keyboard answers "Not now" with Escape (onKey above) or the
		     button, so it is presentational and never in the tab order. -->
		<div
			class="pq-install-dialog__scrim"
			role="presentation"
			tabindex="-1"
			@click="dismiss"
			@keydown.esc="dismiss" />
		<section
			ref="dialog"
			class="pq-install-dialog__panel"
			role="dialog"
			aria-modal="true"
			aria-labelledby="pq-install-dialog-title"
			tabindex="-1">
			<h2
				id="pq-install-dialog-title"
				class="utrecht-heading-3 pq-install-dialog__title">
				{{ t('Install this app') }}
			</h2>
			<p class="utrecht-paragraph">
				{{ t('Install this app on your device?') }}
			</p>
			<div class="pq-install-dialog__buttons">
				<button
					ref="first"
					type="button"
					class="utrecht-button utrecht-button--primary-action"
					data-testid="install-accept"
					@click="install">
					{{ t('Install') }}
				</button>
				<button
					ref="last"
					type="button"
					class="utrecht-button utrecht-button--secondary-action"
					data-testid="install-dismiss"
					@click="dismiss">
					{{ t('Not now') }}
				</button>
			</div>
		</section>
	</div>
</template>

<script>
/** Where "Not now" is kept, and for how long: thirty days. */
export const DISMISSED_KEY = 'portaliq-install-dismissed'
const DISMISSED_FOR = 30 * 24 * 60 * 60 * 1000

/**
 * The browser's local storage, or null where there is none or it throws
 * (a private window, a blocked origin).
 *
 * @return {Storage|null} The storage.
 */
function storageOf() {
	try {
		return typeof window === 'undefined' ? null : window.localStorage
	} catch {
		return null
	}
}

/**
 * Whether "Not now" was said within the last thirty days.
 *
 * @param {Storage|null} storage Where it is kept.
 * @return {boolean} True when the offer stays away.
 */
function isRemembered(storage) {
	try {
		const at = Number(storage?.getItem(DISMISSED_KEY) || 0)
		return at > 0 && Date.now() - at < DISMISSED_FOR
	} catch {
		return false
	}
}

/**
 * Keep "Not now" for the next page views.
 *
 * @param {Storage|null} storage Where it is kept.
 * @return {void}
 */
function remember(storage) {
	try {
		storage?.setItem(DISMISSED_KEY, String(Date.now()))
	} catch {
		// Nothing to keep it in: the dialog comes back on the next page, as before.
	}
}

export default {
	name: 'InstallBanner',

	props: {
		/** The translator `t(key, vars)`. */
		t: {
			type: Function,
			required: true,
		},

		/** The window to listen on (test seam); the page's own window by default. */
		win: {
			type: Object,
			default: () => (typeof window === 'undefined' ? null : window),
		},

		/** Where "Not now" is remembered (test seam); the browser's local storage by default. */
		storage: {
			type: Object,
			default: () => storageOf(),
		},
	},

	emits: ['installed', 'dismiss'],

	data() {
		return {
			/** The captured `beforeinstallprompt` event, or null when the browser made no offer. */
			offer: null,
			dismissed: false,
			/** "Not now" said on an earlier page view, and not yet worn off. */
			remembered: isRemembered(this.storage),
		}
	},

	computed: {
		/**
		 * Whether the banner shows: an offer is held and the resident has not said "Not now".
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-install-offer-must-be-dismissible-req-srp-046
		 */
		visible() {
			return (
				this.offer !== null
				&& this.dismissed === false
				&& this.remembered === false
			)
		},
	},

	watch: {
		/**
		 * The focus moves into the dialog when it opens, and back to where it
		 * was when it closes, as a modal must.
		 *
		 * @param {boolean} open Whether it is on screen.
		 * @return {void}
		 *
		 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-the-install-offer-is-a-dialog-over-the-page-that-remembers-not-now
		 */
		visible(open) {
			if (open) {
				this.opener = this.win?.document?.activeElement || null
				this.$nextTick(() => this.$refs.first?.focus())
			} else if (this.opener && typeof this.opener.focus === 'function') {
				this.opener.focus()
				this.opener = null
			}
		},
	},

	mounted() {
		if (this.win) {
			this.win.addEventListener('beforeinstallprompt', this.onOffer)
			this.win.addEventListener('appinstalled', this.onInstalled)
		}
	},

	unmounted() {
		if (this.win) {
			this.win.removeEventListener('beforeinstallprompt', this.onOffer)
			this.win.removeEventListener('appinstalled', this.onInstalled)
		}
	},

	methods: {
		/**
		 * Keep the browser's offer instead of letting it show its own bar.
		 *
		 * @param {Event} event The `beforeinstallprompt` event.
		 * @return {void}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-install-offer-must-be-dismissible-req-srp-046
		 */
		onOffer(event) {
			event.preventDefault()
			this.offer = event
		},

		/**
		 * The app was installed (from here or from the browser's own menu).
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-install-offer-must-be-dismissible-req-srp-046
		 */
		onInstalled() {
			this.offer = null
			this.$emit('installed')
		},

		/**
		 * Hand the offer back to the browser. An offer can be used once, so
		 * it is dropped whatever the resident answers.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-install-offer-must-be-dismissible-req-srp-046
		 */
		async install() {
			const offer = this.offer
			if (offer === null) {
				return
			}

			this.offer = null
			try {
				await offer.prompt()
			} catch {
				// The browser refused to show its dialog; there is nothing
				// the resident can do about that, so the banner just goes.
			}
		},

		/**
		 * "Not now": hide the dialog for this page view, and remember it for
		 * the next ones, so it does not come back on every page.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-install-offer-must-be-dismissible-req-srp-046
		 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-the-install-offer-is-a-dialog-over-the-page-that-remembers-not-now
		 */
		dismiss() {
			this.dismissed = true
			remember(this.storage)
			this.$emit('dismiss')
		},

		/**
		 * Escape answers "Not now"; Tab stays inside the dialog.
		 *
		 * @param {KeyboardEvent} event The key.
		 * @return {void}
		 *
		 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-the-install-offer-is-a-dialog-over-the-page-that-remembers-not-now
		 */
		onKey(event) {
			if (event.key === 'Escape') {
				event.preventDefault()
				this.dismiss()
				return
			}
			if (event.key !== 'Tab') {
				return
			}
			const first = this.$refs.first
			const last = this.$refs.last
			if (!first || !last) {
				return
			}
			const active = this.win?.document?.activeElement
			if (
				event.shiftKey
				&& (active === first || active === this.$refs.dialog)
			) {
				event.preventDefault()
				last.focus()
			} else if (!event.shiftKey && active === last) {
				event.preventDefault()
				first.focus()
			}
		},
	},
}
</script>

<style scoped>
/* Over the page, never in its flow. Tokens only; the scrim is the page's
   text colour at low opacity so it follows the theme. */
.pq-install-dialog,
.pq-install-dialog.container {
	position: fixed;
	inset: 0;
	z-index: 1000;
	display: flex;
	align-items: center;
	justify-content: center;
	max-inline-size: none;
	padding: 1rem;
}

.pq-install-dialog__scrim {
	position: absolute;
	inset: 0;
	background: var(--utrecht-document-color, CanvasText);
	opacity: 0.4;
}

.pq-install-dialog__panel {
	position: relative;
	inline-size: min(100%, 28rem);
	padding: 1.5rem;
	border-radius: var(
		--nldesign-website-border-radius-large,
		var(--utrecht-border-radius-md, 0.75rem)
	);
	background: var(--utrecht-document-background-color, Canvas);
	color: var(--utrecht-document-color, CanvasText);
	box-shadow: 0 8px 32px
		var(--nldesign-component-content-card-shadow-color, transparent);
}

.pq-install-dialog__title {
	margin: 0 0 0.5rem;
}

.pq-install-dialog__buttons {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
	margin-block-start: 1rem;
}
</style>
