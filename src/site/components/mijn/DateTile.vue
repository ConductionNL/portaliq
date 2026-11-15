<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A date as a small tile: the day large, the short month under it
	(site-school-blocks). The whole date is in a visually hidden text, so a
	screen reader reads "7 oktober 2026" rather than "7 okt".
-->
<template>
	<span v-if="lines" class="pq-date-tile" data-testid="date-tile">
		<span class="pq-date-tile__day" aria-hidden="true">{{ lines.day }}</span>
		<span class="pq-date-tile__month" aria-hidden="true">{{ lines.month }}</span>
		<span class="pq-date-tile__full">{{ full }}</span>
	</span>
</template>

<script>
import { dayAndMonth, longDate } from './dates.js'

/**
 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-dated-list-shows-each-date-as-a-tile-or-a-label
 */
export default {
	name: 'DateTile',

	props: {
		/** The date, ISO. */
		date: { type: String, required: true },
		/** The page language. */
		locale: { type: String, default: '' },
	},

	computed: {
		/**
		 * @return {{day: string, month: string}|null} The two lines.
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-dated-list-shows-each-date-as-a-tile-or-a-label
		 */
		lines() {
			return dayAndMonth(this.date, this.locale)
		},

		/**
		 * @return {string} The date in full, for assistive technology.
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-dated-list-shows-each-date-as-a-tile-or-a-label
		 */
		full() {
			return longDate(this.date, this.locale)
		},
	},
}
</script>

<style scoped>
.pq-date-tile {
	flex: none;
	display: inline-flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	inline-size: 3.375rem;
	block-size: 3.375rem;
	border-radius: var(
		--nldesign-website-border-radius,
		var(--utrecht-border-radius-md, 0.5rem)
	);
	background: var(--nldesign-color-primary-light, var(--utrecht-color-grey-90));
	color: var(--nldesign-color-primary-hover, var(--utrecht-document-color));
	line-height: 1.05;
}

.pq-date-tile__day {
	font-size: 1.25rem;
	font-weight: 700;
}

.pq-date-tile__month {
	font-size: 0.8125rem;
}

.pq-date-tile__full {
	position: absolute;
	inline-size: 1px;
	block-size: 1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
}
</style>
