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
				:aria-label="searchBox.label || hint"
				:role="searchBox.suggest ? 'combobox' : null"
				:aria-autocomplete="searchBox.suggest ? 'list' : null"
				:aria-expanded="
					searchBox.suggest ? String(suggestState.expanded) : null
				"
				:aria-controls="searchBox.suggest ? suggestListId : null"
				:aria-activedescendant="suggestState.activeId || null"
				data-testid="site-header-search-input"
				@keydown="onSuggestKey" />
			<SearchSuggestions
				v-if="searchBox.suggest"
				ref="suggestions"
				:query="term"
				:listId="suggestListId"
				@state="suggestState = $event"
				@choose="openSuggestion" />
			<button type="submit" class="pq-header-tools__submit">
				<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
					<path
						:d="SEARCH"
						fill="none"
						stroke="currentColor"
						stroke-width="2.4"
						stroke-linecap="round" />
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
				<path
					:d="PERSON"
					fill="none"
					stroke="currentColor"
					stroke-width="2.4"
					stroke-linecap="round" />
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
				<path
					:d="MENU"
					fill="none"
					stroke="currentColor"
					stroke-width="2.4"
					stroke-linecap="round" />
			</svg>
			{{ menuLabel }}
		</button>
	</div>
</template>

<script>
import SearchSuggestions from '../SearchSuggestions.vue'
import { suggestionRoute } from '../../lib/searchSuggestions.js'
import { personOf } from './person.js'

// The button links' classes need their stylesheet, or the browser draws its own blue link.
import '@utrecht/button-link-css/dist/index.css'

/**
 * The search box, the way to the own area and the phone menu button of a
 * designed header. Every string is a prop, so it mounts at a public origin.
 *
 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-header-must-carry-the-search-box-and-one-way-to-the-own-area
 */
export default {
	name: 'HeaderTools',

	components: { SearchSuggestions },

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
			suggestListId: 'pq-header-suggest',
			suggestState: { expanded: false, activeId: '' },
			// Outlined, as the Zuiddrecht Kop and MobielHome boards draw them: a
			// magnifier, a person and three bars, stroked in the text colour.
			SEARCH: 'M4 11a7 7 0 1 0 14 0a7 7 0 1 0-14 0M20 20l-4-4',
			PERSON: 'M8 8a4 4 0 1 0 8 0a4 4 0 1 0-8 0M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6',
			MENU: 'M4 7h16M4 12h16M4 17h16',
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

	methods: {
		/**
		 * The search input's keys go to the suggestion list first.
		 *
		 * @param {KeyboardEvent} event The key event.
		 * @return {void}
		 *
		 * @spec openspec/changes/search-suggestions-while-typing/specs/portal-federated-search/spec.md#requirement-the-suggestion-list-works-by-keyboard-and-screen-reader-req-sst-002
		 */
		onSuggestKey(event) {
			const list = this.$refs.suggestions
			if (list && list.onKey(event)) {
				event.preventDefault()
			}
		},

		/**
		 * A suggestion opens its publication.
		 *
		 * @param {{id: string, kind: string}} item The suggestion.
		 * @return {void}
		 *
		 * @spec openspec/changes/search-suggestions-while-typing/specs/portal-federated-search/spec.md#requirement-the-search-box-suggests-publications-while-you-type-req-sst-001
		 */
		openSuggestion(item) {
			this.$emit('navigate', suggestionRoute(item))
		},
	},
}
</script>
