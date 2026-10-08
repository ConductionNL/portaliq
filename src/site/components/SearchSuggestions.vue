<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<!--
		THE SUGGESTION LIST (search-suggestions-while-typing, board Zoeken).

		A listbox under a search input: the typed part of each title in bold, the
		kind as a tag on the right, the no row active until an arrow key picks one. It owns no input: the
		host binds `role="combobox"`, `aria-expanded`, `aria-controls` and
		`aria-activedescendant` from the `state` event, passes the typed text as
		`query` and the keys through `onKey()`. The live region says how many
		suggestions opened.
	-->
	<div class="pq-suggest" data-testid="search-suggestions">
		<ul
			v-if="open"
			:id="listId"
			class="pq-suggest__list"
			role="listbox"
			:aria-label="label"
			data-testid="search-suggestions-list">
			<li
				v-for="(item, index) in suggestions"
				:id="optionId(index)"
				:key="item.id"
				class="pq-suggest__option"
				:class="{ 'pq-suggest__option--active': index === active }"
				role="option"
				:aria-selected="index === active ? 'true' : 'false'"
				:data-testid="`search-suggestion-${index}`"
				@mousedown.prevent="choose(item)">
				<span class="pq-suggest__title"
					><strong>{{ part(item).typed }}</strong
					>{{ part(item).rest }}</span
				>
				<span class="pq-suggest__kind">{{ kindName(item.kind) }}</span>
			</li>
		</ul>
		<p
			class="sr-only"
			role="status"
			aria-live="polite"
			data-testid="search-suggestions-live">
			{{ announced }}
		</p>
	</div>
</template>

<script>
import {
	announcement,
	createSuggester,
	DEFAULT_ENDPOINT,
	moveActive,
	typedPart,
} from '../lib/searchSuggestions.js'

/**
 * Publication title suggestions under a search input, from the federation
 * endpoint the search already uses. Every string is a prop.
 *
 * @spec openspec/changes/search-suggestions-while-typing/specs/portaliq-cms/spec.md#requirement-the-search-box-suggests-publications-while-you-type-req-sst-001
 * @spec openspec/changes/search-suggestions-while-typing/specs/portaliq-cms/spec.md#requirement-the-suggestion-list-works-by-keyboard-and-screen-reader-req-sst-002
 */
export default {
	name: 'SearchSuggestions',

	props: {
		/** The text typed in the search input. */
		query: { type: String, default: '' },
		/** The federation endpoint to ask. */
		endpoint: { type: String, default: DEFAULT_ENDPOINT },
		/** Id of the listbox, for the input's `aria-controls`. */
		listId: { type: String, default: 'woo-suggesties' },
		/** The listbox's accessible name. */
		label: { type: String, default: 'Suggesties' },
		/** Announcement for several suggestions, with `{n}`. */
		manyLabel: { type: String, default: '{n} suggesties' },
		/** Announcement for one suggestion. */
		oneLabel: { type: String, default: '1 suggestie' },
		/** Tag names by kind. */
		kindLabels: {
			type: Object,
			default: () => ({
				publication: 'Publicatie',
				document: 'Document',
				subject: 'Onderwerp',
			}),
		},
	},

	emits: ['choose', 'state'],

	data() {
		return { suggestions: [], active: -1, closed: false, announced: '' }
	},

	computed: {
		/**
		 * @return {boolean} Whether the list shows.
		 */
		open() {
			return this.suggestions.length > 0 && !this.closed
		},
	},

	watch: {
		query(value) {
			this.closed = false
			this.suggester.input(value)
		},

		open() {
			this.publish()
		},

		active() {
			this.publish()
		},
	},

	created() {
		this.suggester = createSuggester({
			endpoint: this.endpoint,
			origin: typeof window !== 'undefined' ? window.location.origin : '',
			onChange: (list) => {
				this.suggestions = list
				this.active = -1
				this.announced =
					list.length > 0
						? announcement(list.length, this.manyLabel, this.oneLabel)
						: ''
			},
		})
	},

	beforeUnmount() {
		this.suggester.close()
	},

	methods: {
		/**
		 * @param {number} index The row.
		 * @return {string} The option's id.
		 */
		optionId(index) {
			return `${this.listId}-${index}`
		},

		part(item) {
			return typedPart(item.title, this.query)
		},

		kindName(kind) {
			return this.kindLabels[kind] || this.kindLabels.publication
		},

		/** Tell the host what to put on the input. */
		publish() {
			this.$emit('state', {
				expanded: this.open,
				controls: this.listId,
				activeId:
					this.open && this.active >= 0 ? this.optionId(this.active) : '',
			})
		},

		choose(item) {
			this.closed = true
			this.$emit('choose', item)
		},

		/**
		 * The host's keydown: arrow keys move, Enter opens the active row,
		 * Escape closes and keeps the text, Tab closes. Returns true when the
		 * key was used (the host then prevents its default).
		 *
		 * @param {KeyboardEvent} event The key event.
		 * @return {boolean} True when handled.
		 */
		onKey(event) {
			if (event.key === 'Escape' || event.key === 'Tab') {
				const wasOpen = this.open
				this.closed = true
				return event.key === 'Escape' && wasOpen
			}
			if (!this.open) {
				return false
			}
			if (event.key === 'Enter') {
				if (this.active < 0) {
					// Nothing picked: Enter runs the search.
					return false
				}
				this.choose(this.suggestions[this.active])
				return true
			}
			const next = moveActive(this.active, this.suggestions.length, event.key)
			if (next === null) {
				return false
			}
			this.active = next
			return true
		},
	},
}
</script>

<style scoped>
.pq-suggest {
	position: relative;
}

.pq-suggest__list {
	position: absolute;
	inset-inline: 0;
	z-index: 20;
	margin: 0;
	padding: 0;
	list-style: none;
	background: var(--utrecht-form-control-background-color, Canvas);
	border: 1px solid var(--utrecht-form-control-border-color, currentcolor);
}

.pq-suggest__option {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
	min-block-size: 48px;
	padding-inline: var(--utrecht-space-inline-md, 1rem);
	cursor: pointer;
}

.pq-suggest__option--active {
	background: var(--utrecht-color-grey-90, rgba(0, 0, 0, 0.08));
	outline: 2px solid var(--utrecht-focus-outline-color, currentcolor);
	outline-offset: -2px;
}

.pq-suggest__kind {
	font-size: 0.875rem;
	opacity: 0.8;
}
</style>
