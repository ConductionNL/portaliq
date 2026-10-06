<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A balk across the top of a page: a storing, an onderhoudsmelding, a
	mededeling (design D1 row 61).

	NL Design System publishes no CSS for this one, so it styles itself from
	`--utrecht-*` tokens alone (design D5): the kinds reuse the alert tokens, so
	a portal that themes its meldingen themes this with them.
-->
<template>
	<div
		v-if="!closed"
		class="nl-banner"
		:class="`nl-banner--${safeKind}`"
		:role="urgent ? 'alert' : 'status'"
		:aria-live="urgent ? 'assertive' : 'polite'"
		data-testid="nl-banner">
		<!-- A banner with only a text keeps the markup it had; a lead or a
		     link draws the structured line. -->
		<p
			v-if="!lead && !link"
			class="utrecht-paragraph nl-banner__text"
			:class="{ container: band }">
			{{ text }}
		</p>
		<p
			v-else
			class="utrecht-paragraph nl-banner__text nl-banner__text--parts"
			:class="{ container: band }">
			<strong v-if="lead" class="nl-banner__lead">{{ lead }}</strong>
			<span>{{ text }}</span>
			<a
				v-if="link"
				class="utrecht-link nl-banner__link"
				:href="link.href"
				data-testid="nl-banner-link"
				@click="open($event, link)"
				>{{ linkLabel }}</a
			>
		</p>
		<button
			v-if="closable"
			type="button"
			class="utrecht-button utrecht-button--subtle-action"
			data-testid="nl-banner-close"
			@click="closed = true">
			{{ closeLabel }}
		</button>
	</div>
</template>

<script>
import { authoredLink, staysInSite } from '../../components/mijn/links.js'

import '@utrecht/link-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'
import '@utrecht/button-css/dist/index.css'

export default {
	name: 'NlBanner',

	props: {
		/** `info`, `ok`, `warning`, `error` or `notice` (a soft attention strip). */
		kind: { type: String, default: 'info' },
		/** The bold words before the text ("Let op"). */
		lead: { type: String, default: '' },
		/** The text the visitor reads. */
		text: { type: String, default: '' },
		/** The words of the link after the text. */
		linkLabel: { type: String, default: '' },
		/** Where that link goes. */
		linkHref: { type: String, default: '' },
		/** Paint edge to edge, the text in the page's container (the grid reads this too). */
		band: { type: Boolean, default: false },
		/** Whether a visitor may close it. */
		closable: { type: Boolean, default: false },
		/** The text on the close button. */
		closeLabel: { type: String, default: 'Sluiten' },
	},

	emits: ['navigate'],

	data() {
		return {
			/** Whether the visitor closed it. */
			closed: false,
		}
	},

	computed: {
		/**
		 * @return {string} One of info, ok, warning, error or notice.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-a-banner-may-carry-a-lead-and-a-link
		 */
		safeKind() {
			return ['info', 'ok', 'warning', 'error', 'notice'].includes(this.kind)
				? this.kind
				: 'info'
		},

		/**
		 * @return {object|null} The link after the text, when it has words and an address.
		 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-a-banner-may-carry-a-lead-and-a-link
		 */
		link() {
			return this.linkLabel.trim() ? authoredLink(this.linkHref) : null
		},

		/**
		 * WHAT IT ANNOUNCES: a storingsmelding or an error interrupts, because a
		 * visitor needs it before they start; an info banner waits its turn. The
		 * close button is a real button, so closing it is a key press away and
		 * the banner does not come back on the same page view.
		 *
		 * @return {boolean} Whether it interrupts.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		urgent() {
			return this.safeKind === 'warning' || this.safeKind === 'error'
		},
	},

	methods: {
		/**
		 * A plain click on a page of this site stays in the site.
		 *
		 * @param {MouseEvent} event The click.
		 * @param {object} link The link.
		 * @return {void}
		 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-a-banner-may-carry-a-lead-and-a-link
		 */
		open(event, link) {
			if (staysInSite(event, link)) {
				event.preventDefault()
				this.$emit('navigate', link.route)
			}
		},
	},
}
</script>

<style scoped>
/*
 * TOKENS ONLY, no literal colours. Every value here is an `--utrecht-*`
 * reference with a token fallback, so a portal's own set themes this band
 * like it themes the alerts. `tests/widget-tokens.spec.mjs` fails on a hex,
 * an rgb() or a named colour in this file.
 */
.nl-banner {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--utrecht-space-inline-md, 1rem);
	padding: var(--utrecht-space-block-sm, 0.5rem)
		var(--utrecht-space-inline-md, 1rem);
	border-block-end: var(--utrecht-alert-border-width, 1px) solid
		var(--utrecht-alert-info-border-color, var(--utrecht-color-grey-30));
	background-color: var(
		--utrecht-alert-info-background-color,
		var(--utrecht-document-background-color)
	);
	color: var(--utrecht-alert-info-color, var(--utrecht-document-color));
}

.nl-banner--ok {
	border-block-end-color: var(
		--utrecht-alert-ok-border-color,
		var(--utrecht-color-green-30)
	);
	background-color: var(
		--utrecht-alert-ok-background-color,
		var(--utrecht-document-background-color)
	);
	color: var(--utrecht-alert-ok-color, var(--utrecht-document-color));
}

.nl-banner--warning {
	border-block-end-color: var(
		--utrecht-alert-warning-border-color,
		var(--utrecht-color-orange-30)
	);
	background-color: var(
		--utrecht-alert-warning-background-color,
		var(--utrecht-document-background-color)
	);
	color: var(--utrecht-alert-warning-color, var(--utrecht-document-color));
}

.nl-banner--error {
	border-block-end-color: var(
		--utrecht-alert-error-border-color,
		var(--utrecht-color-red-30)
	);
	background-color: var(
		--utrecht-alert-error-background-color,
		var(--utrecht-document-background-color)
	);
	color: var(--utrecht-alert-error-color, var(--utrecht-document-color));
}

/* The soft attention strip (Zuiddrecht "Let op"): the site's own notice
   tokens, else the warning alert's. */
.nl-banner--notice {
	--nl-banner-notice-line: var(
		--utrecht-alert-warning-border-color,
		var(--utrecht-color-orange-30)
	);
	--nl-banner-notice-ground: var(--utrecht-alert-warning-background-color, Canvas);
	--nl-banner-notice-ink: var(--utrecht-alert-warning-color, CanvasText);
	border-block-end-color: var(
		--nldesign-website-notice-border-color,
		var(--nl-banner-notice-line)
	);
	background-color: var(
		--nldesign-website-notice-background-color,
		var(--nl-banner-notice-ground)
	);
	color: var(--nldesign-website-notice-color, var(--nl-banner-notice-ink));
}

.nl-banner__text {
	margin: 0;
}

.nl-banner__text--parts {
	display: flex;
	flex-wrap: wrap;
	gap: 0.375rem 0.75rem;
	align-items: baseline;
}

.nl-banner__text.container {
	flex: 1;
	padding-block: 0.25rem;
}

.nl-banner .utrecht-link.nl-banner__link {
	color: inherit;
	font-weight: 600;
}
</style>
