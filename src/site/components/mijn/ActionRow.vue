<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	One task or message as a Den Haag action row: a list item with ONE control
	that names the row. With a route it is a link with a real address, so a new
	tab works; a plain click stays in the site and emits `open`. A row that
	opens something on the same page is a button. A row with nowhere to go is
	text. The badges sit inside the control, so a screen reader hears them with
	its name.
-->
<template>
	<li class="pq-action-row" data-testid="mijn-action-row">
		<component
			:is="tag"
			class="denhaag-action denhaag-action--single pq-action-row__control"
			:class="{ 'pq-action-row--unread': unread }"
			:href="tag === 'a' ? href : undefined"
			:type="tag === 'button' ? 'button' : undefined"
			:aria-current="current ? 'true' : undefined"
			@click="onClick">
			<span class="denhaag-action__row">
				<span class="denhaag-action__content">
					<span class="pq-action-row__title">{{ title }}</span>
					<span v-if="meta" class="pq-action-row__meta">{{ meta }}</span>
				</span>
			</span>
			<span
				v-if="badges.length > 0 || tag !== 'div'"
				class="denhaag-action__context">
				<span v-if="badges.length > 0" class="denhaag-action__details">
					<DataBadge
						v-for="badge in badges"
						:key="badge.text"
						:text="badge.text"
						:state="badge.state || 'neutral'"
						:datetime="badge.datetime || ''" />
				</span>
				<svg
					v-if="tag !== 'div'"
					class="denhaag-action__link-icon pq-action-row__chevron"
					viewBox="0 0 24 24"
					aria-hidden="true"
					focusable="false">
					<path
						d="M9 6l6 6-6 6"
						fill="none"
						stroke="currentColor"
						stroke-width="2" />
				</svg>
			</span>
		</component>
	</li>
</template>

<script>
import DataBadge from './DataBadge.vue'
import { siteHref } from './rows.js'

/**
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
 */
export default {
	name: 'ActionRow',

	components: { DataBadge },

	props: {
		/** The row's name, which is also the control's name. */
		title: { type: String, required: true },
		/** One line under the title, or ''. */
		meta: { type: String, default: '' },
		/** The in-site route the row opens, or '' for none. */
		route: { type: String, default: '' },
		/** Whether the row opens something on this page (a button). */
		button: { type: Boolean, default: false },
		/** Whether the row is the one on screen (`aria-current`). */
		current: { type: Boolean, default: false },
		/** Whether the row is unread; pair it with a "Nieuw" badge. */
		unread: { type: Boolean, default: false },
		/** The badges: `{text, state?, datetime?}`. */
		badges: { type: Array, default: () => [] },
	},

	emits: ['open'],

	computed: {
		/**
		 * @return {string} a, button or div.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
		 */
		tag() {
			if (this.route) {
				return 'a'
			}
			return this.button ? 'button' : 'div'
		},

		/**
		 * @return {string} The link's real address.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
		 */
		href() {
			return siteHref(this.route)
		},
	},

	methods: {
		/**
		 * A plain click stays in the site; a click meant for a new tab or
		 * window is the browser's.
		 *
		 * @param {MouseEvent} event The click.
		 * @return {void}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
		 */
		onClick(event) {
			if (this.tag === 'div') {
				return
			}
			if (
				this.tag === 'a'
				&& (event?.ctrlKey
					|| event?.metaKey
					|| event?.shiftKey
					|| event?.button === 1)
			) {
				return
			}
			event?.preventDefault?.()
			this.$emit('open', this.route)
		},
	},
}
</script>

<style>
/* The action row's layout: the package's own CSS, nothing else of it. */
@import '@gemeente-denhaag/action/index.css';
</style>

<style scoped>
.pq-action-row {
	list-style: none;
}

/* Our control is a link or a button, not Den Haag's div: reset what the
   browser adds and let the row fill its line. */
.pq-action-row__control {
	box-sizing: border-box;
	inline-size: 100%;
	margin: 0;
	background-color: var(--denhaag-action-background-color, transparent);
	border-color: var(
		--denhaag-action-border-color,
		var(--utrecht-color-grey-80, #ccc)
	);
	color: var(--denhaag-action-color, var(--utrecht-document-color, inherit));
	font: inherit;
	text-align: start;
	text-decoration: none;
	cursor: pointer;
}

div.pq-action-row__control {
	cursor: default;
}

.pq-action-row__control:focus-visible {
	outline: var(--utrecht-focus-outline-width, 2px)
		var(--utrecht-focus-outline-style, solid)
		var(--utrecht-focus-outline-color, currentcolor);
	outline-offset: 2px;
}

.pq-action-row__title {
	display: block;
}

.pq-action-row--unread .pq-action-row__title {
	font-weight: var(--denhaag-action-content-bold-font-weight, bold);
}

.pq-action-row__meta {
	display: block;
	font-size: 0.875em;
}

.pq-action-row__chevron {
	inline-size: var(--denhaag-action-link-icon-width, 1.25rem);
	block-size: var(--denhaag-action-link-icon-width, 1.25rem);
}
</style>
