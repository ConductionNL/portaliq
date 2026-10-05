<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<!--
		THE RIGHT HALF OF A DESIGNED HEADER (site-chrome-follows-the-design):
		the search box, then one button to the resident's own area while signed
		out, or the person chip and "Uitloggen" while signed in. On a phone the
		menu button opens the navigation bar and the search box.

		Loaded on demand by BrandHeader, only for a portal that declares an
		account label or a header search: the site's entry carries none of it.
	-->
	<div class="pq-header-tools" data-testid="site-header-tools">
		<form
			v-if="searchBox.enabled"
			class="pq-header-tools__search"
			:class="{ 'pq-header-tools__search--open': menuOpen }"
			role="search"
			data-testid="site-header-search"
			@submit.prevent="$emit('search', term.trim())">
			<input
				v-model="term"
				type="search"
				class="utrecht-textbox pq-header-tools__input"
				:placeholder="hint"
				:aria-label="hint"
				data-testid="site-header-search-input" />
			<button type="submit" class="pq-header-tools__submit">
				<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
					<path :d="SEARCH" fill="currentColor" />
				</svg>
				<span class="sr-only">{{ searchLabel }}</span>
			</button>
		</form>

		<template v-if="session">
			<a
				v-if="accountLink"
				class="pq-header-tools__chip"
				:href="accountLink.href"
				data-testid="site-own-area"
				@click.prevent="$emit('navigate', accountLink.route)">
				<span class="pq-header-tools__initials" aria-hidden="true">{{
					person ? person.initials : ''
				}}</span>
				<span class="pq-header-tools__who">
					<span
						class="pq-header-tools__name"
						data-testid="site-auth-subject"
						>{{ person ? person.name : accountLink.label }}</span
					>
					<span
						v-if="person && person.subline"
						class="pq-header-tools__subline"
						data-testid="site-auth-subline">
						{{ person.subline }}
					</span>
				</span>
			</a>
			<button
				type="button"
				class="pq-header-tools__signout"
				data-testid="site-signout"
				@click="$emit('signout')">
				{{ signOutLabel }}
			</button>
		</template>
		<a
			v-else-if="accountLabel"
			class="utrecht-button-link utrecht-button-link--html-a utrecht-button-link--primary-action pq-header-tools__account"
			:href="accountHref"
			data-testid="site-account-button"
			@click.prevent="$emit('navigate', accountRoute)">
			<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
				<path :d="PERSON" fill="currentColor" />
			</svg>
			{{ accountLabel }}
		</a>

		<button
			v-if="hasMenu"
			type="button"
			class="pq-header-tools__menu"
			:aria-expanded="String(menuOpen)"
			aria-controls="pq-site-navigation"
			data-testid="site-header-menu-toggle"
			@click="$emit('toggleMenu')">
			<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
				<path :d="MENU" fill="currentColor" />
			</svg>
			{{ menuLabel }}
		</button>
	</div>
</template>

<script>
import { personOf } from './person.js'

/**
 * The search box, the way to the own area and the phone menu button of a
 * designed header. Every string is a prop, so it mounts at a public origin.
 *
 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-header-must-carry-the-search-box-and-one-way-to-the-own-area
 */
export default {
	name: 'HeaderTools',

	props: {
		/** `{enabled, placeholder}` from headerSearchOf(). */
		searchBox: { type: Object, default: () => ({ enabled: false }) },
		/** The search button's name, and the box's when the portal names no hint. */
		searchLabel: { type: String, default: 'Zoeken' },
		/** The signed-out button's text; empty shows no button. */
		accountLabel: { type: String, default: '' },
		/** The own area's route and address. */
		accountRoute: { type: String, default: '/mijn' },
		accountHref: { type: String, default: '' },
		/** The visitor's portal session, or null. */
		session: { type: Object, default: null },
		/** `{route, href, label}` of the own area while signed in. */
		accountLink: { type: Object, default: null },
		signOutLabel: { type: String, default: 'Uitloggen' },
		/** Whether there is a navigation bar to open on a phone. */
		hasMenu: { type: Boolean, default: false },
		menuOpen: { type: Boolean, default: false },
		menuLabel: { type: String, default: 'Menu' },
	},

	emits: ['search', 'navigate', 'signout', 'toggleMenu'],

	data() {
		return {
			term: '',
			// Material Design Icons (Apache 2.0): magnify, account-outline, menu.
			SEARCH: 'M9.5 3A6.5 6.5 0 0 1 16 9.5c0 1.61-.59 3.09-1.56 4.23l.27.27h.79l5 5-1.5 1.5-5-5v-.79l-.27-.27A6.52 6.52 0 0 1 9.5 16 6.5 6.5 0 0 1 3 9.5 6.5 6.5 0 0 1 9.5 3m0 2C7 5 5 7 5 9.5S7 14 9.5 14 14 12 14 9.5 12 5 9.5 5Z',
			PERSON: 'M12 4a4 4 0 0 1 4 4 4 4 0 0 1-4 4 4 4 0 0 1-4-4 4 4 0 0 1 4-4m0 2a2 2 0 0 0-2 2 2 2 0 0 0 2 2 2 2 0 0 0 2-2 2 2 0 0 0-2-2m0 7c2.67 0 8 1.33 8 4v3H4v-3c0-2.67 5.33-4 8-4m0 1.9c-2.97 0-6.1 1.46-6.1 2.1v1.1h12.2V17c0-.64-3.13-2.1-6.1-2.1Z',
			MENU: 'M3 6h18v2H3V6m0 5h18v2H3v-2m0 5h18v2H3v-2Z',
		}
	},

	computed: {
		/**
		 * @return {string} The box's hint and accessible name.
		 */
		hint() {
			return this.searchBox.placeholder || this.searchLabel
		},

		/**
		 * @return {object|null} The person the chip shows.
		 */
		person() {
			return personOf(this.session)
		},
	},
}
</script>
