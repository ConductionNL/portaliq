<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<p
		v-if="loading"
		class="utrecht-paragraph pq-collection-table__status"
		role="status"
		data-testid="collection-table-loading">
		{{ t('Loading…') }}
	</p>
	<p
		v-else-if="rows.length === 0"
		class="utrecht-paragraph pq-collection-table__empty"
		data-testid="collection-table-empty">
		<em>{{
			collection.kind === 'inbox' ? t('No messages.') : t('No items.')
		}}</em>
	</p>
	<div v-else class="pq-collection-table__scroll">
		<table
			class="utrecht-table pq-collection-table"
			:aria-labelledby="labelledby || undefined"
			data-testid="collection-table">
			<thead class="utrecht-table__header">
				<tr class="utrecht-table__row">
					<th
						v-for="column in columns"
						:key="column.field"
						scope="col"
						class="utrecht-table__header-cell">
						{{ column.label }}
					</th>
					<th
						v-if="actions.length > 0"
						scope="col"
						class="utrecht-table__header-cell">
						{{ t('Actions') }}
					</th>
				</tr>
			</thead>
			<tbody class="utrecht-table__body">
				<!-- The row click stays as a mouse convenience; the keyboard
				     path is the button in the first cell (WCAG 2.1.1,
				     portaliq#722). Enter and Space on a button fire its click,
				     and Tab reaches it. -->
				<tr
					v-for="(row, rowIndex) in rows"
					:key="keyOf(row, rowIndex)"
					class="utrecht-table__row"
					:class="{
						'pq-collection-table__row--selectable': selectable,
						'pq-collection-table__row--current': isCurrent(row),
					}"
					:aria-current="isCurrent(row) ? 'true' : undefined"
					:aria-busy="isBusy(row) ? 'true' : undefined"
					data-testid="collection-table-row"
					@click="selectable ? select(row) : undefined">
					<td
						v-for="(column, columnIndex) in columns"
						:key="column.field"
						class="utrecht-table__cell">
						<button
							v-if="selectable && columnIndex === 0"
							type="button"
							class="utrecht-button utrecht-button--subtle pq-collection-table__select"
							data-testid="collection-table-select"
							@click.stop="select(row)">
							{{ cellText(row, column) || t('Open') }}
						</button>
						<span
							v-else-if="
								column.render === 'badge'
								&& cellText(row, column) !== ''
							"
							class="utrecht-badge-status pq-collection-table__badge"
							:class="`pq-collection-table__badge--${badge(row, column)}`">
							{{ cellText(row, column) }}
						</span>
						<a
							v-else-if="
								column.render === 'link' && href(row, column) !== ''
							"
							class="utrecht-link"
							:href="href(row, column)">
							{{ cellText(row, column) }}
						</a>
						<template v-else>
							{{ cellText(row, column) }}
						</template>
					</td>
					<td
						v-if="actions.length > 0"
						class="utrecht-table__cell pq-collection-table__actions">
						<button
							v-for="action in actionsFor(row)"
							:key="action.id"
							type="button"
							class="utrecht-button utrecht-button--secondary-action pq-collection-table__action"
							:disabled="isBusy(row)"
							data-testid="collection-table-action"
							@click.stop="rowAction(action, row)">
							{{ action.label || action.id }}
						</button>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import {
	badgeModifier,
	deriveColumns,
	formatCell,
	rowIdOf,
	safeHref,
} from './cells.js'

/**
 * One collection's rows as a table (slice b, b2).
 *
 * Columns follow the app's declared `columns` with their labels and `render`
 * formatter, else the fields the rows carry, written as words. The rows are
 * already scoped to the resident and projected by the server, so a column
 * naming a field that was projected away renders blank, never leaks.
 *
 * Each row opens through a real button in its first cell, so a keyboard user
 * reaches every row with Tab. The open row carries `aria-current`. Row action
 * buttons show on the rows `offers` names, and are disabled on the row whose
 * action is running.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-collection-table-must-follow-the-declared-columns-req-srp-015
 */
export default {
	name: 'CollectionTable',

	props: {
		/** The collection: `id`, `kind`, `columns`. */
		collection: { type: Object, required: true },
		/** Its rows. */
		objects: { type: Array, default: () => [] },
		/** Whether the rows are still loading. */
		loading: { type: Boolean, default: false },
		/** Whether a row can be opened; the table emits `select` then. */
		selectable: { type: Boolean, default: false },
		/** The open row. */
		selectedRow: { type: Object, default: null },
		/** The row actions the buttons offer. */
		rowActions: { type: Array, default: () => [] },
		/** `(action, row) => boolean`: whether a row offers an action. */
		offers: { type: Function, default: null },
		/** The id of the row whose action is running. */
		busyRow: { type: [String, Number], default: null },
		/** The id of the heading that names the table. */
		labelledby: { type: String, default: '' },
		/** The translator. */
		t: { type: Function, required: true },
		/** The language. */
		locale: { type: String, default: 'nl' },
	},

	emits: ['select', 'rowAction'],

	computed: {
		rows() {
			return Array.isArray(this.objects) ? this.objects : []
		},

		columns() {
			return deriveColumns(this.collection, this.rows)
		},

		actions() {
			return Array.isArray(this.rowActions) ? this.rowActions : []
		},

		selectedId() {
			return rowIdOf(this.selectedRow)
		},
	},

	methods: {
		keyOf(row, index) {
			return rowIdOf(row) || `row-${index}`
		},

		isCurrent(row) {
			return this.selectedId !== undefined && this.selectedId === rowIdOf(row)
		},

		isBusy(row) {
			return (
				this.busyRow !== null
				&& this.busyRow !== undefined
				&& this.busyRow === rowIdOf(row)
			)
		},

		cellText(row, column) {
			return formatCell(row?.[column.field], column.render, {
				locale: this.locale,
				t: this.t,
			})
		},

		badge(row, column) {
			return badgeModifier(row?.[column.field])
		},

		href(row, column) {
			return safeHref(row?.[column.field])
		},

		actionsFor(row) {
			return this.actions.filter(
				(action) => !this.offers || this.offers(action, row),
			)
		},

		select(row) {
			this.$emit('select', row)
		},

		rowAction(action, row) {
			this.$emit('rowAction', action, row)
		},
	},
}
</script>

<style scoped>
.pq-collection-table__scroll {
	overflow-x: auto;
}

.pq-collection-table {
	inline-size: 100%;
}

/* A header cell centres by browser default; the values below it start at the
   inline edge, so the label sat over the gap between two columns. */
.pq-collection-table .utrecht-table__header-cell {
	text-align: start;
}

.pq-collection-table__row--selectable {
	cursor: pointer;
}

.pq-collection-table__row--current {
	background-color: var(
		--utrecht-table-row-selected-background-color,
		var(--nldesign-color-primary-light, Highlight)
	);
	color: var(--utrecht-table-row-selected-color, inherit);
}

.pq-collection-table__select {
	text-align: start;
}

.pq-collection-table__actions {
	white-space: nowrap;
}

.pq-collection-table__action + .pq-collection-table__action {
	margin-inline-start: var(--utrecht-space-inline-sm, 0.5rem);
}
</style>
