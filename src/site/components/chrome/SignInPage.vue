<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<!--
		THE SIGN-IN PAGE AS ROLE CARDS (site-chrome-follows-the-design, G-18).

		One card per way in, in the order the portal declares its modes: who it
		is for, what it opens, one button and a hint. The first card's button is
		the primary one. Under the cards a notice and a line for staff; beside
		them, when the portal writes one, a panel of what waits after signing
		in, with the theme's motif along its top.

		Loaded on demand by AccountArea, only for a portal that writes cards or
		page text: every other portal keeps its plain list of buttons.
	-->
	<div
		class="pq-signin"
		:class="{ 'pq-signin--with-panel': panel }"
		data-testid="site-signin-page">
		<div class="pq-signin__main">
			<h1
				class="utrecht-heading-1 pq-signin__title"
				data-testid="site-signin-title">
				{{ page.title || welcomeLabel }}
			</h1>
			<p class="utrecht-paragraph pq-signin__intro">
				{{ page.intro || introLabel }}
			</p>

			<ul v-if="routes.length" class="pq-signin__cards">
				<li
					v-for="(way, index) in routes"
					:key="way.mode"
					class="pq-signin__card"
					data-testid="site-signin-card">
					<div class="pq-signin__card-head">
						<span
							class="pq-signin__mark"
							:class="'pq-signin__mark--' + way.mode"
							aria-hidden="true">
							<template v-if="markText(way)">{{
								markText(way)
							}}</template>
							<svg
								v-else-if="iconPath(way)"
								viewBox="0 0 24 24"
								focusable="false">
								<path :d="iconPath(way)" fill="currentColor" />
							</svg>
						</span>
						<div>
							<h2 class="utrecht-heading-3 pq-signin__card-title">
								{{ (way.card && way.card.title) || way.label }}
							</h2>
							<p
								v-if="way.card && way.card.text"
								class="utrecht-paragraph pq-signin__card-text">
								{{ way.card.text }}
							</p>
						</div>
					</div>
					<a
						class="utrecht-button-link utrecht-button-link--html-a pq-signin__button"
						:class="
							index === 0
								? 'utrecht-button-link--primary-action'
								: 'utrecht-button-link--secondary-action'
						"
						:href="way.href"
						:data-mode="way.mode"
						data-testid="site-account-signin-route">
						{{ way.label }}
					</a>
					<p v-if="way.card && way.card.hint" class="pq-signin__hint">
						{{ way.card.hint }}
					</p>
					<!-- The one-click demo sign-in says so under its button
					     (example-resident-demo-login). -->
					<p
						v-if="way.demo"
						class="pq-signin__hint pq-signin__demo"
						data-testid="site-account-signin-demo">
						{{ demoLabel }}
					</p>
				</li>
			</ul>
			<p v-else class="utrecht-paragraph">
				{{ noWayLabel }}
			</p>

			<div
				v-if="page.notice"
				class="pq-signin__notice"
				role="note"
				data-testid="site-signin-notice">
				<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
					<path :d="INFO" fill="currentColor" />
				</svg>
				<div>
					<p v-if="page.notice.title" class="pq-signin__notice-title">
						{{ page.notice.title }}
					</p>
					<p>{{ page.notice.text }}</p>
				</div>
			</div>

			<p v-if="page.staffLink" class="utrecht-paragraph pq-signin__staff">
				{{ page.staffLink.text }}
				<a class="utrecht-link" :href="page.staffLink.href">{{
					page.staffLink.label
				}}</a>
			</p>
		</div>

		<aside
			v-if="panel"
			class="pq-signin__panel"
			:aria-label="panel.title || undefined"
			data-testid="site-signin-panel">
			<h2 v-if="panel.title" class="utrecht-heading-3 pq-signin__panel-title">
				{{ panel.title }}
			</h2>
			<ul class="pq-signin__points">
				<li
					v-for="point in panel.items"
					:key="point.title"
					class="pq-signin__point">
					<span class="pq-signin__point-mark" aria-hidden="true">
						<svg viewBox="0 0 24 24" focusable="false">
							<path
								:d="icons[point.icon] || CHECK"
								fill="currentColor" />
						</svg>
					</span>
					<span>
						<span class="pq-signin__point-title">{{ point.title }}</span>
						<span v-if="point.text" class="pq-signin__point-text">{{
							point.text
						}}</span>
					</span>
				</li>
			</ul>
		</aside>
	</div>
</template>

<script>
import icons from '../../lib/menuIcons.js'

// The button links' classes need their stylesheet, or the browser draws its own blue link.
import '@utrecht/button-link-css/dist/index.css'

/**
 * The sign-in page of a portal that writes its cards (`authentication.modeLabels`)
 * or its page text (`authentication.signInPage`). Strings come in as props.
 *
 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-sign-in-page-must-offer-each-way-in-as-a-card-for-its-role
 */
export default {
	name: 'SignInPage',

	props: {
		/** The sign-in routes, each with its `card` (authApi signInRoutes). */
		routes: { type: Array, default: () => [] },
		/** The portal's `authentication.signInPage`. */
		page: { type: Object, default: () => ({}) },
		welcomeLabel: { type: String, default: 'Welkom' },
		/** The note under a one-click demo way in. */
		demoLabel: {
			type: String,
			default: 'Alleen op deze demo. Er wordt geen wachtwoord gevraagd.',
		},

		introLabel: { type: String, default: 'Log in om uw gegevens te bekijken.' },
		noWayLabel: {
			type: String,
			default: 'Er is nog geen manier van inloggen ingesteld.',
		},
	},

	data() {
		return {
			icons,
			// Material Design Icons (Apache 2.0): information-outline, check.
			INFO: 'M11 9h2V7h-2m1 13c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8m0-18A10 10 0 0 0 2 12a10 10 0 0 0 10 10 10 10 0 0 0 10-10A10 10 0 0 0 12 2m-1 15h2v-6h-2v6Z',
			CHECK: 'M21 7 9 19l-5.5-5.5 1.41-1.41L9 16.17 19.59 5.59 21 7Z',
		}
	},

	computed: {
		/**
		 * @return {object|null} The side panel, when it holds a point.
		 */
		panel() {
			const panel = this.page.panel
			return panel && Array.isArray(panel.items) && panel.items.length > 0
				? panel
				: null
		},
	},

	methods: {
		/**
		 * The mark a government way in carries as text: DigiD and eHerkenning
		 * are known by their name on a dark tile, as their own sites show it.
		 *
		 * @param {object} way The route.
		 * @return {string} The text, or '' to draw an icon.
		 */
		markText(way) {
			return (
				{ digid: 'DigiD', eherkenning: 'eH', eidas: 'eIDAS' }[way.mode] || ''
			)
		},

		/**
		 * The card's icon: the one the portal names, else a person.
		 *
		 * @param {object} way The route.
		 * @return {string} SVG path data.
		 */
		iconPath(way) {
			return icons[way.card && way.card.icon] || icons.AccountOutline
		},
	},
}
</script>
