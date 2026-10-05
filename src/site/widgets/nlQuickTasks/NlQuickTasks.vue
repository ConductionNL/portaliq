<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The tasks a portal is visited for, as one card of tiles
	(site-school-blocks, "Direct regelen").

	Each tile is ONE link: icon, label and arrow inside it, so the whole row
	is the target and a screen reader reads one name. The icons are
	decorative. With `overlap` the card is pulled up over the band above it,
	as the home boards draw it over the hero; a phone keeps the overlap
	smaller so the hero's buttons stay clear.
-->
<template>
	<section
		class="nl-quick-tasks"
		:class="{ 'nl-quick-tasks--overlap': overlap }"
		:aria-labelledby="heading ? headingId : null"
		data-testid="nl-quick-tasks">
		<div class="nl-quick-tasks__card">
			<h2
				v-if="heading"
				:id="headingId"
				class="utrecht-heading-2 nl-quick-tasks__heading">
				{{ heading }}
			</h2>
			<ul
				class="nl-quick-tasks__list"
				:style="{ '--nl-quick-tasks-columns': columnCount }">
				<li
					v-for="(task, index) in tasks"
					:key="index"
					class="nl-quick-tasks__item">
					<a
						class="nl-quick-tasks__link"
						:href="task.link.href"
						data-testid="nl-quick-task"
						@click="open($event, task.link)">
						<span
							v-if="task.icon"
							class="nl-quick-tasks__icon"
							aria-hidden="true">
							<svg viewBox="0 0 24 24" focusable="false">
								<path :d="task.icon" />
							</svg>
						</span>
						<span class="nl-quick-tasks__label">{{ task.label }}</span>
						<svg
							class="nl-quick-tasks__arrow"
							viewBox="0 0 24 24"
							aria-hidden="true"
							focusable="false">
							<path d="M9 5l7 7-7 7" />
						</svg>
					</a>
				</li>
			</ul>
			<p v-if="more" class="utrecht-paragraph nl-quick-tasks__more">
				<a
					class="utrecht-link"
					:href="more.href"
					@click="open($event, more)"
					>{{ moreLabel }}</a
				>
			</p>
		</div>
	</section>
</template>

<script>
import { authoredLink, staysInSite } from '../../components/mijn/links.js'
import icons from './icons.js'

import '@utrecht/heading-2-css/dist/index.css'
import '@utrecht/link-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

/** The most tiles one card shows. */
const MAX_TASKS = 12

/**
 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-task-list-shows-a-portals-most-asked-tasks-as-tiles
 */
export default {
	name: 'NlQuickTasks',

	props: {
		/** The card's heading. */
		heading: { type: String, default: '' },
		/** The tiles: `{label, href, icon?}`. */
		items: { type: Array, default: () => [] },
		/** Columns on a wide screen: 2, 3 or 4. */
		columns: { type: [Number, String], default: 3 },
		/** The link under the tiles. */
		moreLabel: { type: String, default: '' },
		/** Its address. */
		moreHref: { type: String, default: '' },
		/** Pull the card up over the band above it. */
		overlap: { type: Boolean, default: false },
	},

	emits: ['navigate'],

	computed: {
		/**
		 * The tiles that have a label and a usable address, at most twelve.
		 *
		 * @return {Array<{label: string, link: object, icon: string}>} The tiles.
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-task-list-shows-a-portals-most-asked-tasks-as-tiles
		 */
		tasks() {
			return (Array.isArray(this.items) ? this.items : [])
				.map((item) => ({
					label: String(item?.label ?? '').trim(),
					link: authoredLink(item?.href),
					icon: Object.hasOwn(icons, item?.icon) ? icons[item.icon] : '',
				}))
				.filter((task) => task.label !== '' && task.link !== null)
				.slice(0, MAX_TASKS)
		},

		/**
		 * @return {number} 2, 3 or 4.
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-task-list-shows-a-portals-most-asked-tasks-as-tiles
		 */
		columnCount() {
			const count = Number(this.columns)
			return [2, 3, 4].includes(count) ? count : 3
		},

		/**
		 * @return {object|null} The link under the tiles.
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-task-list-shows-a-portals-most-asked-tasks-as-tiles
		 */
		more() {
			return this.moreLabel.trim() ? authoredLink(this.moreHref) : null
		},

		/**
		 * @return {string} An id for the heading, unique enough per page.
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-task-list-shows-a-portals-most-asked-tasks-as-tiles
		 */
		headingId() {
			return `nl-quick-tasks-${this.heading.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`
		},
	},

	methods: {
		/**
		 * A plain click on a route stays in the site.
		 *
		 * @param {MouseEvent} event The click.
		 * @param {object} link The link.
		 * @return {void}
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-task-list-shows-a-portals-most-asked-tasks-as-tiles
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
.nl-quick-tasks {
	position: relative;
	z-index: 1;
}

.nl-quick-tasks--overlap {
	margin-block-start: calc(-1 * var(--nl-quick-tasks-overlap, 4.25rem));
}

.nl-quick-tasks__card {
	padding: 2rem 2rem 1.25rem;
	border-radius: var(
		--nldesign-website-border-radius-large,
		var(--utrecht-border-radius-md, 0.75rem)
	);
	background: var(--utrecht-document-background-color, Canvas);
	box-shadow: 0 2px 14px
		var(--nldesign-component-content-card-shadow-color, transparent);
	border: 1px solid
		var(--nldesign-component-content-card-border-color, transparent);
}

.nl-quick-tasks__heading {
	margin: 0 0 0.75rem;
}

.nl-quick-tasks__list {
	display: grid;
	grid-template-columns: repeat(var(--nl-quick-tasks-columns, 3), minmax(0, 1fr));
	column-gap: 2.5rem;
	margin: 0;
	padding: 0;
	list-style: none;
}

.nl-quick-tasks__item {
	border-block-end: 1px solid
		var(--nldesign-color-border, var(--utrecht-color-grey-90, currentcolor));
}

.nl-quick-tasks__link {
	display: flex;
	align-items: center;
	gap: 0.875rem;
	min-block-size: 4rem;
	color: var(--utrecht-document-color, CanvasText);
	font-weight: 600;
	text-decoration: none;
}

.nl-quick-tasks__link:hover .nl-quick-tasks__label {
	text-decoration: underline;
}

.nl-quick-tasks__link:focus-visible {
	outline: var(--utrecht-focus-outline-width, 2px)
		var(--utrecht-focus-outline-style, solid)
		var(--utrecht-focus-outline-color, currentcolor);
	outline-offset: 2px;
}

.nl-quick-tasks__icon {
	flex: none;
	display: flex;
	align-items: center;
	justify-content: center;
	inline-size: 2.5rem;
	block-size: 2.5rem;
	border-radius: 50%;
	background: var(
		--nldesign-color-primary-light,
		var(--utrecht-color-grey-90, transparent)
	);
	color: var(
		--nldesign-color-primary-hover,
		var(--utrecht-document-color, currentcolor)
	);
}

.nl-quick-tasks__icon svg {
	inline-size: 1.375rem;
	block-size: 1.375rem;
	fill: none;
	stroke: currentcolor;
	stroke-width: 1.9;
	stroke-linecap: round;
	stroke-linejoin: round;
}

.nl-quick-tasks__label {
	flex: 1;
}

.nl-quick-tasks__arrow {
	flex: none;
	inline-size: 1.125rem;
	block-size: 1.125rem;
	fill: none;
	stroke: var(--nldesign-color-primary, var(--utrecht-link-color, currentcolor));
	stroke-width: 2.6;
	stroke-linecap: round;
}

.nl-quick-tasks__more {
	margin: 1.125rem 0 0;
	font-weight: 600;
}

@media (max-width: 900px) {
	.nl-quick-tasks__list {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}
}

@media (max-width: 600px) {
	.nl-quick-tasks--overlap {
		margin-block-start: calc(-0.5 * var(--nl-quick-tasks-overlap, 4.25rem));
	}

	.nl-quick-tasks__card {
		padding: 1.5rem 1.25rem 1rem;
	}

	.nl-quick-tasks__list {
		grid-template-columns: minmax(0, 1fr);
	}
}
</style>
