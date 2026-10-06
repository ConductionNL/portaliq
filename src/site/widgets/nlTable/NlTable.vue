<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A table of rows and columns (design D1 row 96).

	The headers are `th scope="col"`, which is what lets a screen reader say
	which column a cell belongs to, and a short row is padded rather than
	drawn: cells sliding under the wrong header is a wrong table, not an untidy
	one.
-->
<template>
	<table
		class="utrecht-table"
		:class="{ 'nl-table--boxed': display === 'boxed' }"
		data-testid="nl-table">
		<caption
			v-if="caption"
			class="utrecht-table__caption"
			:class="{ 'nl-table__caption--hidden': !captionVisible }">
			{{
				caption
			}}
		</caption>
		<thead v-if="safeColumns.length" class="utrecht-table__header">
			<tr class="utrecht-table__row">
				<th
					v-for="column in safeColumns"
					:key="column"
					class="utrecht-table__header-cell"
					scope="col">
					{{ column }}
				</th>
			</tr>
		</thead>
		<tbody class="utrecht-table__body">
			<tr
				v-for="(row, index) in safeRows"
				:key="index"
				class="utrecht-table__row">
				<td
					v-for="(cell, cellIndex) in row"
					:key="`${index}-${cellIndex}`"
					class="utrecht-table__cell">
					{{ cell }}
				</td>
			</tr>
		</tbody>
	</table>
</template>

<script>
import '@utrecht/table-css/dist/index.css'

export default {
	name: 'NlTable',

	props: {
		/** What the table shows. */
		caption: { type: String, default: '' },
		/** The column headers. */
		columns: { type: Array, default: () => [] },
		/** The rows, each a list of cells. */
		rows: { type: Array, default: () => [] },
		/** `plain` or `boxed` (a bordered, rounded box with a tinted header row). */
		display: { type: String, default: 'plain' },
		/** Show the caption; off keeps it for screen readers only. */
		captionVisible: { type: Boolean, default: true },
	},

	computed: {
		/**
		 * @return {Array<string>} The headers as text.
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		safeColumns() {
			return (this.columns || []).map((column) => String(column ?? '').trim())
		},

		/**
		 * Every row padded to the width of the header, so a short row does not
		 * shift the cells after it under the wrong column.
		 *
		 * @return {Array<Array<string>>} The rows.
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		safeRows() {
			const width = Math.max(this.safeColumns.length, 1)
			return (this.rows || []).map((row) => {
				const cells = (Array.isArray(row) ? row : [row]).map((cell) =>
					String(cell ?? '').trim(),
				)
				while (cells.length < width) {
					cells.push('')
				}

				return cells.slice(0, width)
			})
		},
	},
}
</script>

<style scoped>
/* The boxed display (Zuiddrecht board Contentpagina). Tokens only. */
.nl-table--boxed {
	border: 1px solid
		var(--nldesign-color-border-dark, var(--utrecht-color-grey-80, currentcolor));
	border-radius: var(
		--nldesign-website-border-radius-large,
		var(--utrecht-border-radius-md, 0.75rem)
	);
	border-spacing: 0;
	overflow: hidden;
}

.nl-table--boxed .utrecht-table__header-cell {
	--nl-surface: var(
		--nldesign-component-content-surface-background-color,
		transparent
	);
	background: var(--nldesign-color-surface, var(--nl-surface));
}

.nl-table--boxed .utrecht-table__header-cell,
.nl-table--boxed .utrecht-table__cell {
	padding: 0.75rem 1rem;
	border-block-end: 0;
}

.nl-table--boxed .utrecht-table__body .utrecht-table__cell {
	border-block-start: 1px solid
		var(--nldesign-color-border-dark, var(--utrecht-color-grey-80, currentcolor));
}

.nl-table__caption--hidden {
	position: absolute;
	inline-size: 1px;
	block-size: 1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
}
</style>
