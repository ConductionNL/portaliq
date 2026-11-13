<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The choices for a block that reads an app's public index
  (editor-blocks-read-public-app-data, task 4): the app and kind, then the
  categories, filters or columns that kind declares. The options come from
  the apps' own declarations, never from a list in portaliq. The block's JSON
  field stays below it for what the choices do not cover.
-->
<template>
	<fieldset class="public-source-picker" :data-testid="`designer-picker-${picker}`">
		<template v-if="picker === 'source'">
			<label :for="`${idPrefix}-kind`">{{ t('portaliq', 'App and kind') }}</label>
			<select
				:id="`${idPrefix}-kind`"
				class="page-grid-editor__input"
				:value="kindValue(source)"
				@change="emitSource(withKind(source, kinds, $event.target.value))">
				<option value="">
					{{ t('portaliq', 'No app') }}
				</option>
				<option
					v-for="entry in kinds"
					:key="`${entry.app}/${entry.type}`"
					:value="`${entry.app}/${entry.type}`">
					{{ entry.label }} ({{ entry.app }})
				</option>
			</select>

			<template v-if="kind && widgetKey === 'nlEventList' && kind.categories.length > 0">
				<p class="public-source-picker__legend">
					{{ t('portaliq', 'Categories') }}
				</p>
				<label
					v-for="category in kind.categories"
					:key="category"
					class="public-source-picker__check">
					<input
						type="checkbox"
						:checked="(source.categories || []).includes(category)"
						@change="emitSource(withCategory(source, kind, category, $event.target.checked))">
					{{ category }}
				</label>
			</template>

			<template v-if="kind && widgetKey === 'nlPublicTable'">
				<div
					v-for="(values, label, index) in kind.filters"
					:key="label"
					class="public-source-picker__filter">
					<label :for="`${idPrefix}-filter-${index}`">{{ label }}</label>
					<select
						:id="`${idPrefix}-filter-${index}`"
						class="page-grid-editor__input"
						:value="filterValue(source, label)"
						@change="emitSource(withFilter(source, kind, label, $event.target.value))">
						<option value="">
							{{ t('portaliq', 'Any') }}
						</option>
						<option :value="visitor">
							{{ t('portaliq', 'The visitor\'s own') }}
						</option>
						<option
							v-for="value in values"
							:key="value"
							:value="value">
							{{ value }}
						</option>
					</select>
				</div>
			</template>
		</template>

		<template v-else-if="picker === 'columns' && kind && kind.columns.length > 0">
			<p class="public-source-picker__legend">
				{{ t('portaliq', 'Columns') }}
			</p>
			<label
				v-for="column in kind.columns"
				:key="column.key"
				class="public-source-picker__check">
				<input
					type="checkbox"
					:checked="hasColumn(blockProps.columns, column.key)"
					@change="$emit('set', 'columns', withColumn(blockProps.columns, kind, column.key, $event.target.checked))">
				{{ column.label }}
			</label>
		</template>
	</fieldset>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	filterValue,
	hasColumn,
	kindFor,
	kindValue,
	VISITOR,
	withCategory,
	withColumn,
	withFilter,
	withKind,
} from './publicSource.js'

export default {
	name: 'PublicSourcePicker',

	props: {
		/** `source` for the app, kind and its narrowing; `columns` for a table's columns. */
		picker: { type: String, required: true },
		/** The block's widget key. */
		widgetKey: { type: String, required: true },
		/** The block's props. */
		blockProps: { type: Object, default: () => ({}) },
		/** The kinds the apps declare. */
		kinds: { type: Array, default: () => [] },
	},

	emits: ['set'],

	data() {
		return { visitor: VISITOR }
	},

	computed: {
		/** @return {object} The block's source. */
		source() {
			const source = this.blockProps.source
			return source && typeof source === 'object' && !Array.isArray(source) ? source : {}
		},

		/** @return {object|null} The declared kind the block reads. */
		kind() {
			return kindFor(this.kinds, this.source)
		},

		/** @return {string} The prefix of this picker's control ids. */
		idPrefix() {
			return `picker-${this.picker}`
		},
	},

	methods: {
		t,
		filterValue,
		hasColumn,
		kindValue,
		withCategory,
		withColumn,
		withFilter,
		withKind,

		/**
		 * @param {object|undefined} source The new source.
		 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-4
		 */
		emitSource(source) {
			this.$emit('set', 'source', source)
		},
	},
}
</script>

<style scoped>
.public-source-picker {
	border: none;
	margin: 0 0 8px;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.public-source-picker__legend {
	margin: 4px 0 0;
	font-weight: bold;
}

.public-source-picker__check {
	display: flex;
	gap: 6px;
	align-items: center;
}

.public-source-picker__filter {
	display: flex;
	flex-direction: column;
	gap: 2px;
}
</style>
