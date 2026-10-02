<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The install offer (parent-pwa-installability), ported from the
	`portaliq-install-banner` in src/portal/App.jsx. The browser's own offer
	(`beforeinstallprompt`) is captured so the site can show its own control.
	A browser that never makes the offer (Safari, or an app that is already
	installed) gets nothing at all: the banner renders only while an offer is
	held, by construction rather than by a flag. "Not now" hides it for this
	page view (REQ-SRP-046).
-->
<template>
	<section
		v-if="visible"
		class="pq-install-banner utrecht-alert"
		:aria-label="t('Install this app')"
		data-testid="install-banner">
		<p class="utrecht-paragraph">
			{{ t('Install this app on your device?') }}
		</p>
		<div class="pq-install-banner__buttons">
			<button
				type="button"
				class="utrecht-button utrecht-button--primary-action"
				data-testid="install-accept"
				@click="install">
				{{ t('Install') }}
			</button>
			<button
				type="button"
				class="utrecht-button utrecht-button--secondary-action"
				data-testid="install-dismiss"
				@click="dismiss">
				{{ t('Not now') }}
			</button>
		</div>
	</section>
</template>

<script>
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
	},

	emits: ['installed', 'dismiss'],

	data() {
		return {
			/** The captured `beforeinstallprompt` event, or null when the browser made no offer. */
			offer: null,
			dismissed: false,
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
			return this.offer !== null && this.dismissed === false
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
		 * "Not now": hide the banner for this page view.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-install-offer-must-be-dismissible-req-srp-046
		 */
		dismiss() {
			this.dismissed = true
			this.$emit('dismiss')
		},
	},
}
</script>

<style scoped>
.pq-install-banner {
	margin-block-end: var(--utrecht-space-block-md, 1rem);
}

.pq-install-banner__buttons {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}
</style>
