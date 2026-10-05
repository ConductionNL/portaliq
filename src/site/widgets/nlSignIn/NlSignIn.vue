<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The ways in this portal offers (design D1 row 55), as a row of buttons or
	as a card that invites the visitor to their own area (site-school-blocks).

	THE WAYS IN ARE THE PORTAL'S, NOT THE PLACEMENT'S. A page cannot invent a
	sign-in route: the shell hands down what the portal declares (DigiD,
	eHerkenning, the broker), and this widget renders those and nothing else.
	A placement that could name its own address would be a phishing surface on
	a government page. The card only words itself: heading, intro, the points
	it lists and a note.
-->
<template>
	<div
		v-if="display === 'card'"
		class="nl-signin-card"
		:class="`nl-signin-card--${tone === 'light' ? 'light' : 'inverse'}`"
		data-testid="nl-sign-in">
		<h2 v-if="heading" class="utrecht-heading-3 nl-signin-card__heading">
			{{ heading }}
		</h2>
		<p v-if="intro" class="utrecht-paragraph nl-signin-card__intro">
			{{ intro }}
		</p>
		<ul v-if="pointList.length > 0" class="nl-signin-card__points">
			<li
				v-for="(point, index) in pointList"
				:key="index"
				class="nl-signin-card__point">
				<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
					<path d="M5 12.5l4.5 4.5L19 7" />
				</svg>
				<span>{{ point }}</span>
			</li>
		</ul>
		<a
			class="utrecht-button nl-signin-card__button"
			:class="
				tone === 'light'
					? 'utrecht-button--primary-action'
					: 'utrecht-button--secondary-action'
			"
			:href="button.href"
			:data-testid="`nl-sign-in-${button.id}`"
			@click="open">
			{{ button.label }}
		</a>
		<p v-if="note" class="nl-signin-card__note">{{ note }}</p>
	</div>

	<div v-else class="nl-signin" data-testid="nl-sign-in">
		<h2 v-if="heading" class="utrecht-heading-3">{{ heading }}</h2>
		<ul class="nl-signin__list">
			<li v-for="way in waysIn" :key="way.id" class="nl-signin__item">
				<a
					class="utrecht-button utrecht-button--primary-action"
					:class="{ 'utrecht-digid-button': way.id === 'digid' }"
					:href="way.href"
					:data-testid="`nl-sign-in-${way.id}`">
					{{ way.label }}
				</a>
			</li>
		</ul>
		<p
			v-if="waysIn.length === 0"
			class="utrecht-paragraph"
			data-testid="nl-sign-in-none">
			{{ noneLabel || say('none') }}
		</p>
	</div>
</template>

<script>
import { authoredLink, staysInSite } from '../../components/mijn/links.js'
import { interpolate, pageLocale } from '../../pages/inbox/translate.js'
import { cardButton, safeWays } from './signIn.js'
import strings from './strings.js'

import '@utrecht/digid-button-css/dist/index.css'
import '@utrecht/button-css/dist/index.css'
import '@utrecht/heading-3-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

export default {
	name: 'NlSignIn',

	props: {
		/** The heading above the buttons or on the card. */
		heading: { type: String, default: '' },
		/** The ways in the portal declares: `{id, label, href}`, from the host. */
		ways: { type: Array, default: () => [] },
		/** What is said when the portal declares none. */
		noneLabel: { type: String, default: '' },
		/** `buttons` or `card`. */
		display: { type: String, default: 'buttons' },
		/** The card's text under the heading. */
		intro: { type: String, default: '' },
		/** The card's check list. */
		points: { type: Array, default: () => [] },
		/** The card button's words. */
		buttonLabel: { type: String, default: '' },
		/** The sign-in page, when the portal offers several ways in. */
		signInHref: { type: String, default: '/mijn' },
		/** A small line under the button. */
		note: { type: String, default: '' },
		/** `inverse` (a dark card) or `light`. */
		tone: { type: String, default: 'inverse' },
		/** Whether the visitor is signed in, from the host. */
		signedIn: { type: Boolean, default: false },
	},

	emits: ['navigate'],

	computed: {
		/**
		 * @return {Array<object>} The ways in that name a route inside this site.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		waysIn() {
			return safeWays(this.ways)
		},

		/**
		 * @return {Array<string>} The card's points, empty ones left out.
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-the-sign-in-card-offers-the-portals-own-ways-in
		 */
		pointList() {
			return (Array.isArray(this.points) ? this.points : [])
				.map((point) => String(point ?? '').trim())
				.filter(Boolean)
		},

		/**
		 * @return {object} The card's one button, with a real address.
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-the-sign-in-card-offers-the-portals-own-ways-in
		 */
		button() {
			const button = cardButton({
				ways: this.waysIn,
				signedIn: this.signedIn,
				heading: this.heading,
				buttonLabel: this.buttonLabel.trim(),
				signInHref: this.signInHref,
				say: this.say,
			})
			return button.route
				? { ...button, href: authoredLink(button.route).href }
				: button
		},
	},

	methods: {
		/**
		 * @param {string} key A string key.
		 * @param {object} [vars] Placeholders.
		 * @return {string} The words in the page language.
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-the-sign-in-card-offers-the-portals-own-ways-in
		 */
		say(key, vars) {
			return interpolate((strings[pageLocale()] || strings.nl)[key], vars)
		},

		/**
		 * A plain click to a route in the site stays in the site; a way in is a
		 * real navigation to the sign-in edge.
		 *
		 * @param {MouseEvent} event The click.
		 * @return {void}
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-the-sign-in-card-offers-the-portals-own-ways-in
		 */
		open(event) {
			if (staysInSite(event, this.button)) {
				event.preventDefault()
				this.$emit('navigate', this.button.route)
			}
		},
	},
}
</script>

<style scoped>
/* Layout only for the row; the buttons bring their own colours. The card
   takes its ground from the theme's deep main colour and its marks from the
   accent, both tokens. */
.nl-signin__list {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
	margin: 0;
	padding: 0;
	list-style: none;
}

.nl-signin-card {
	display: flex;
	flex-direction: column;
	gap: 0.875rem;
	padding: 2rem;
	border-radius: var(
		--nldesign-website-border-radius-large,
		var(--utrecht-border-radius-md, 0.75rem)
	);
}

.nl-signin-card--inverse {
	background: var(
		--nldesign-color-primary-hover,
		var(--nldesign-color-primary, CanvasText)
	);
	color: var(--nldesign-color-primary-text, Canvas);
	--utrecht-heading-3-color: var(--nldesign-color-primary-text, Canvas);
	--utrecht-paragraph-color: var(--nldesign-color-primary-text, Canvas);
}

.nl-signin-card--light {
	background: var(--nldesign-color-primary-light, transparent);
	--utrecht-heading-3-color: var(
		--nldesign-color-primary-hover,
		var(--utrecht-document-color, CanvasText)
	);
}

.nl-signin-card__heading,
.nl-signin-card__intro {
	margin: 0;
}

.nl-signin-card__points {
	display: grid;
	gap: 0.625rem;
	margin: 0;
	padding: 0;
	list-style: none;
}

.nl-signin-card__point {
	display: flex;
	gap: 0.625rem;
}

.nl-signin-card__point svg {
	flex: none;
	inline-size: 1.25rem;
	block-size: 1.25rem;
	margin-block-start: 0.1875rem;
	fill: none;
	stroke: var(--nldesign-color-accent, currentcolor);
	stroke-width: 2.8;
	stroke-linecap: round;
	stroke-linejoin: round;
}

.nl-signin-card__button {
	justify-content: center;
	inline-size: 100%;
	margin-block-start: 0.375rem;
	font-weight: 700;
}

.nl-signin-card--inverse .nl-signin-card__button {
	--utrecht-button-secondary-action-background-color: var(
		--nldesign-color-primary-text,
		Canvas
	);
	--utrecht-button-secondary-action-color: var(
		--nldesign-color-primary-hover,
		CanvasText
	);
	--utrecht-button-secondary-action-border-color: var(
		--nldesign-color-primary-text,
		Canvas
	);
}

.nl-signin-card__note {
	margin: 0;
	font-size: 0.9375rem;
}
</style>
