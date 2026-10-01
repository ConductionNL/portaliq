<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<section
		class="pq-item-list"
		:aria-busy="answer === null ? 'true' : undefined"
		data-testid="item-list">
		<h3 class="utrecht-heading-4">
			{{ heading }}
		</h3>
		<p v-if="answer === null" class="utrecht-paragraph" role="status">
			{{ t('Loading…') }}
		</p>
		<p
			v-else-if="answer === false"
			class="utrecht-paragraph pq-item-list__empty">
			{{ t('The items could not be loaded.') }}
		</p>
		<template v-else>
			<p
				v-if="rows.length === 0"
				class="utrecht-paragraph pq-item-list__empty">
				<em>{{ t('Nothing in this dossier yet.') }}</em>
			</p>
			<ul v-else class="utrecht-unordered-list pq-item-list__items">
				<li
					v-for="item in rows"
					:key="item.id || item.title"
					class="utrecht-unordered-list__item"
					data-testid="item-list-item">
					<a v-if="item.href" class="utrecht-link" :href="item.href">{{
						item.title
					}}</a>
					<span v-else>{{ item.title }}</span>
					<strong
						v-if="item.notPublic"
						class="utrecht-badge-status pq-item-list__not-public"
						data-testid="item-not-public">
						{{ t('No longer public') }}
					</strong>
					<p v-if="item.note" class="utrecht-paragraph pq-item-list__note">
						{{ item.note }}
					</p>
					<button
						v-if="canRemove && item.id"
						type="button"
						class="utrecht-button utrecht-button--subtle"
						:aria-label="t('Remove {title}', { title: item.title })"
						data-testid="item-list-remove"
						@click="remove(item.id)">
						{{ t('Remove') }}
					</button>
				</li>
			</ul>
		</template>
		<p v-if="message !== ''" class="utrecht-paragraph" role="status">
			{{ message }}
		</p>
	</section>
</template>

<script>
import { itemRows, removeItem } from '../../../shared/itemList.js'
import { rowIdOf } from './cells.js'

/**
 * What is in a record, as its app lists it (slice b, b7; my-dossiers): a
 * dossier's publications with their links and notes. An item whose
 * publication is no longer public stays, marked so. With a declared remove
 * action, each item can be removed on its own.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-an-item-list-must-show-and-remove-items-req-srp-020
 */
export default {
	name: 'ItemList',

	props: {
		/** The collection, with `itemList`. */
		collection: { type: Object, required: true },
		/** The record on screen. */
		row: { type: Object, required: true },
		/** The portal api (`fetchItems`, `forwardRowAction`). */
		api: { type: Object, default: null },
		/** The translator. */
		t: { type: Function, required: true },
		/**
		 * The answer to start from, for a server render or a test; the list
		 * reads its own on mount.
		 */
		initialAnswer: { type: [Boolean, Object], default: null },
	},

	data() {
		return {
			answer: this.initialAnswer,
			message: '',
		}
	},

	computed: {
		rowId() {
			return rowIdOf(this.row)
		},

		heading() {
			return this.collection.itemList?.label || this.t('In this dossier')
		},

		rows() {
			return this.answer ? itemRows(this.answer) : []
		},

		canRemove() {
			const action = this.collection.itemList?.removeAction
			return typeof action === 'string' && action !== ''
		},
	},

	watch: {
		rowId() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the record's items.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.answer = null
			let body = null
			if (
				this.rowId
				&& this.api
				&& typeof this.api.fetchItems === 'function'
			) {
				try {
					body = await this.api.fetchItems(this.collection, this.rowId)
				} catch {
					body = null
				}
			}
			this.answer = body || false
		},

		/**
		 * Remove one item, then read the list again.
		 *
		 * @param {string} itemId The item.
		 * @return {Promise<void>}
		 */
		async remove(itemId) {
			const result = await removeItem(
				this.api,
				this.collection,
				this.row,
				itemId,
			)
			this.message = result.ok
				? this.t('Removed.')
				: this.t('This can no longer be done for this item.')
			if (result.ok) {
				await this.load()
			}
		},
	},
}
</script>

<style scoped>
.pq-item-list__not-public {
	margin-inline-start: var(--utrecht-space-inline-sm, 0.5rem);
}
</style>
