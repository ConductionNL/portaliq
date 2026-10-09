<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	One life domain of the resident (life-domain-theme-pages, board
	ThemaOverzicht): what to arrange (open tasks), what can be arranged
	(actions, by rules on the products held) and the products held, with their
	validity and an update action per row. The shell shows the heading; this
	page shows the intro and the blocks. Everything is gathered from the
	contributions tagged with the theme and read through their scoped reads.
-->
<template>
	<section
		class="pq-theme"
		:aria-busy="loading ? 'true' : undefined"
		data-testid="theme-page">
		<p v-if="theme.intro" class="utrecht-paragraph" data-testid="theme-intro">
			{{ theme.intro }}
		</p>
		<p v-if="loading" class="utrecht-paragraph" role="status">
			{{ t('Loading…') }}
		</p>
		<p v-else-if="isEmpty" class="utrecht-paragraph" data-testid="theme-empty">
			{{ t('Nothing for you at {theme} right now.', { theme: theme.title }) }}
		</p>
		<p
			v-if="notice !== ''"
			class="utrecht-paragraph"
			role="status"
			data-testid="theme-notice">
			{{ t(notice) }}
		</p>

		<template v-if="!loading">
			<section
				v-if="tasks.length > 0"
				aria-labelledby="pq-theme-tasks"
				data-testid="theme-tasks">
				<h2 id="pq-theme-tasks" class="utrecht-heading-2">
					{{ t('What do I need to arrange') }}
				</h2>
				<ul class="utrecht-unordered-list pq-theme__list">
					<li
						v-for="task in tasks.slice(0, 5)"
						:key="task.key"
						class="utrecht-unordered-list__item"
						data-testid="theme-task">
						<strong>{{ task.title }}</strong>
						<span v-if="task.due">{{
							t('Due {date}', { date: task.due })
						}}</span>
					</li>
				</ul>
				<button
					type="button"
					class="utrecht-link utrecht-link--button pq-theme__link"
					data-testid="theme-all-tasks"
					@click="navigate('__tasks__')">
					{{ t('View all tasks ({count})', { count: tasks.length }) }}
				</button>
			</section>

			<section
				v-if="offered.length > 0"
				aria-labelledby="pq-theme-actions"
				data-testid="theme-actions">
				<h2 id="pq-theme-actions" class="utrecht-heading-2">
					{{ t('What can I arrange') }}
				</h2>
				<ul class="pq-theme__cards">
					<li
						v-for="entry in offered"
						:key="entry.action.id"
						class="pq-theme__card">
						<strong>{{
							entry.action.label
							|| entry.action.title
							|| entry.action.id
						}}</strong>
						<span v-if="entry.action.description">{{
							entry.action.description
						}}</span>
					</li>
				</ul>
			</section>

			<section
				v-for="product in products"
				:key="`${product.app}:${product.collection.id}`"
				:aria-labelledby="`pq-theme-products-${product.collection.id}`"
				data-testid="theme-products">
				<h2
					:id="`pq-theme-products-${product.collection.id}`"
					class="utrecht-heading-2">
					{{
						t('My {products}', {
							products: theme.productsLabel || t('products'),
						})
					}}
				</h2>
				<p class="utrecht-paragraph" data-testid="theme-products-count">
					{{ countLine(product) }}
				</p>
				<ul class="utrecht-unordered-list pq-theme__list">
					<li
						v-for="item in shownProducts(product)"
						:key="item.view.id"
						class="utrecht-unordered-list__item"
						data-testid="theme-product">
						<strong>{{ item.view.title }}</strong>
						<span class="pq-theme__tag" :data-state="item.view.state">{{
							item.view.tag
						}}</span>
						<span v-if="item.view.meta">{{ item.view.meta }}</span>
						<span v-if="item.view.validUntil">{{
							item.view.validUntil
						}}</span>
						<span class="pq-theme__buttons">
							<button
								v-for="action in item.actions"
								:key="action.id"
								type="button"
								class="utrecht-button utrecht-button--secondary-action"
								data-testid="theme-product-action"
								@click="
									editing = {
										id: item.view.id,
										action,
										row: item.row,
									}
								">
								{{ action.label || action.title || action.id }}
							</button>
						</span>
						<ProductUpdateForm
							v-if="editing && editing.id === item.view.id"
							:action="editing.action"
							:row="editing.row"
							:api="api"
							:t="t"
							@cancel="editing = null"
							@saved="saved(product)" />
					</li>
				</ul>
				<button
					v-if="hasMore(product)"
					type="button"
					class="utrecht-link utrecht-link--button pq-theme__link"
					data-testid="theme-all-products"
					@click="showAll = !showAll">
					{{
						showAll
							? t('Show fewer')
							: t('View all {products}', {
									products: theme.productsLabel || t('products'),
								})
					}}
				</button>
			</section>
		</template>
	</section>
</template>

<script>
import ProductUpdateForm from './ProductUpdateForm.vue'
import { longDate, readerLocale } from './format.js'
import {
	countLine,
	gather,
	offeredActions,
	PRODUCT_LIMIT,
	productView,
	rowActions,
	sortedProducts,
} from './themes.js'

/**
 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t03
 */
export default {
	name: 'ThemePage',

	components: { ProductUpdateForm },

	props: {
		/** The navigation entry of the theme: `theme` holds slug, title, intro and productsLabel. */
		entry: { type: Object, required: true },
		/** The contributions aggregate. */
		contributions: { type: Object, default: null },
		/** The portal api (`fetchCollection`, `updateObject`). */
		api: { type: Object, required: true },
		/** The translator `t(key, vars)`. */
		t: { type: Function, required: true },
		/** The shell's `navigate(key, params)`. */
		navigate: { type: Function, default: () => {} },
		/** The reader's locale; the page's `<html lang>` when empty. */
		locale: { type: String, default: '' },
		/** Today as `YYYY-MM-DD` (test seam). */
		today: { type: String, default: '' },
	},

	data() {
		return {
			loading: true,
			rows: {},
			editing: null,
			showAll: false,
			notice: '',
		}
	},

	computed: {
		/**
		 * @return {object} The theme: slug, title, intro, productsLabel.
		 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t03
		 */
		theme() {
			return (
				this.entry.theme || {
					slug: '',
					title: this.entry.label || '',
					intro: '',
					productsLabel: '',
				}
			)
		},

		/**
		 * @return {{tasks: Array<object>, products: Array<object>, actions: Array<object>}} What the theme gathers.
		 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t03
		 */
		gathered() {
			return gather(this.theme.slug, this.contributions?.contributions)
		},

		/**
		 * @return {Array<object>} The product collections with their rows.
		 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t05
		 */
		products() {
			return this.gathered.products
				.map((product) => ({
					...product,
					rows: this.rows[this.keyOf(product)] || [],
				}))
				.filter((product) => product.rows.length > 0)
		},

		/**
		 * @return {Array<object>} The open tasks of the theme, as rows to list.
		 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t03
		 */
		tasks() {
			return this.gathered.tasks.flatMap((task) =>
				(this.rows[this.keyOf(task)] || []).map((row, index) => ({
					key: `${this.keyOf(task)}:${row.id ?? index}`,
					title: String(
						row[task.collection.titleFields?.[0] ?? 'title']
							?? row.title
							?? row.name
							?? '',
					),
					due: this.dateOf(row[task.collection.dueField]),
				})),
			)
		},

		/**
		 * @return {Array<object>} The actions to offer: a `when` must hold for a product held.
		 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t06
		 */
		offered() {
			return offeredActions(this.gathered.actions, this.products)
		},

		/**
		 * @return {boolean} Whether there is nothing to show.
		 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t03
		 */
		isEmpty() {
			return (
				this.tasks.length === 0
				&& this.offered.length === 0
				&& this.products.length === 0
			)
		},

		/**
		 * @return {string} Today as `YYYY-MM-DD`.
		 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t05
		 */
		day() {
			return this.today || new Date().toISOString().slice(0, 10)
		},
	},

	/**
	 * Read the rows of every collection the theme gathers.
	 *
	 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t03
	 */
	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * @param {{app: string, collection: object}} entry A gathered collection.
		 * @return {string} Its key in `rows`.
		 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t03
		 */
		keyOf(entry) {
			return `${entry.app}:${entry.collection.id}`
		},

		/**
		 * Read the rows, each collection on its own: one that fails reads as empty.
		 *
		 * @return {Promise<void>} Resolves when read.
		 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t03
		 */
		async load() {
			const wanted = [...this.gathered.tasks, ...this.gathered.products]
			const rows = {}
			await Promise.all(
				wanted.map(async (entry) => {
					const answer = await this.api.fetchCollection(entry.collection, {
						orNull: true,
					})
					rows[this.keyOf(entry)] = Array.isArray(answer) ? answer : []
				}),
			)
			this.rows = rows
			this.loading = false
		},

		/**
		 * @param {string} value A date.
		 * @return {string} It as written for the reader, or ''.
		 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t05
		 */
		dateOf(value) {
			return value ? longDate(value, readerLocale(this.locale)) : ''
		},

		/**
		 * @param {{collection: object, rows: Array<object>}} product A product collection with its rows.
		 * @return {string} The count line.
		 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t05
		 */
		countLine(product) {
			return countLine(product.rows.length, product.collection, this.t)
		},

		/**
		 * @param {{collection: object, rows: Array<object>}} product A product collection with its rows.
		 * @return {boolean} Whether there are more products than the page shows, or any has expired.
		 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t05
		 */
		hasMore(product) {
			return (
				product.rows.length > PRODUCT_LIMIT
				|| sortedProducts(product.rows, product.collection, this.day).some(
					(row) =>
						productView(
							row,
							product.collection,
							this.day,
							(d) => d,
							this.t,
						).state === 'expired',
				)
			)
		},

		/**
		 * The products to list: valid first, at most three unless the whole list is asked for.
		 *
		 * @param {{app: string, collection: object, rows: Array<object>}} product A product collection with its rows.
		 * @return {Array<{row: object, view: object, actions: Array<object>}>} The rows to show.
		 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t05
		 */
		shownProducts(product) {
			const sorted = sortedProducts(product.rows, product.collection, this.day)
			const list = this.showAll ? sorted : sorted.slice(0, PRODUCT_LIMIT)
			return list.map((row) => ({
				row,
				view: productView(
					row,
					product.collection,
					this.day,
					(date) => this.dateOf(date),
					this.t,
				),
				actions: rowActions(row, product, this.gathered.actions),
			}))
		},

		/**
		 * An update was saved: close the form and read the products again, so the row shows the new value.
		 *
		 * @param {{app: string, collection: object}} product The product collection.
		 * @return {Promise<void>} Resolves when read.
		 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t05
		 */
		async saved(product) {
			this.editing = null
			this.notice = 'Your change is saved.'
			const answer = await this.api.fetchCollection(product.collection, {
				orNull: true,
			})
			this.rows = {
				...this.rows,
				[this.keyOf(product)]: Array.isArray(answer) ? answer : [],
			}
		},
	},
}
</script>

<style scoped>
.pq-theme > * + * {
	margin-block-start: var(--utrecht-space-block-lg, 1.5rem);
}

.pq-theme__list > li,
.pq-theme__cards > li {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}

.pq-theme__cards {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr));
	gap: var(--utrecht-space-block-md, 1rem);
	list-style: none;
	margin: 0;
	padding: 0;
}

.pq-theme__card {
	flex-direction: column;
	align-items: flex-start;
	padding: var(--utrecht-space-block-md, 1rem);
	border: var(--utrecht-border-width-sm, 1px) solid
		var(--utrecht-color-grey-80, currentcolor);
}

.pq-theme__tag {
	padding: 0 var(--utrecht-space-inline-sm, 0.5rem);
	border: var(--utrecht-border-width-sm, 1px) solid
		var(--utrecht-color-grey-80, currentcolor);
	border-radius: 999px;
}

.pq-theme__buttons {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}

.pq-theme__link {
	background: none;
	border: 0;
	padding: 0;
	cursor: pointer;
	text-decoration: underline;
}
</style>
