<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	"Mijn acties" (personal-action-list, board ActieBewerken): the actions a
	resident owns or was given, with and without a plan, in the table the
	board draws, and the dialog to add or edit one. The server decides what
	the resident reads and may change.
-->
<template>
	<section
		class="pq-actions"
		aria-labelledby="pq-actions-title"
		data-testid="actions-page">
		<div class="pq-actions__head">
			<h1 id="pq-actions-title" class="utrecht-heading-2">
				{{ words.title }}
			</h1>
			<button
				type="button"
				class="utrecht-button utrecht-button--primary-action"
				data-testid="actions-add"
				@click="open(null)">
				{{ words.addAction }}
			</button>
		</div>
		<p class="utrecht-paragraph">
			{{ words.intro }}
		</p>
		<p
			v-if="notice !== ''"
			class="utrecht-paragraph"
			role="status"
			data-testid="actions-notice">
			{{ notice }}
		</p>
		<p
			v-if="problem !== ''"
			class="utrecht-paragraph pq-actions__error"
			role="alert"
			data-testid="actions-problem">
			{{ problem }}
		</p>
		<p v-if="loading" class="utrecht-paragraph" role="status">
			{{ words.loading }}
		</p>
		<template v-else>
			<p class="utrecht-paragraph" data-testid="actions-progress">
				{{ fill(words.progress, { done: list.done, total: list.total }) }}
			</p>
			<p
				v-if="list.actions.length === 0"
				class="utrecht-paragraph"
				data-testid="actions-empty">
				{{ words.noActions }}
			</p>
			<table
				v-else
				class="utrecht-table pq-actions__table"
				data-testid="actions-table">
				<caption class="pq-actions__sr">
					{{
						words.title
					}}
				</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ words.action }}
						</th>
						<th scope="col">
							{{ words.status }}
						</th>
						<th scope="col">
							{{ words.due }}
						</th>
						<th scope="col">
							{{ words.who }}
						</th>
						<th scope="col">
							<span class="pq-actions__sr">{{ words.edit }}</span>
						</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="action in list.actions"
						:key="action.id"
						data-testid="actions-row">
						<td>
							<span class="pq-actions__title">{{ action.title }}</span>
							<span class="pq-actions__sub">
								{{ words[action.kind] || action.kind }}
								<template v-if="action.planTitle">
									·
									{{
										fill(words.plan, { plan: action.planTitle })
									}}
								</template>
							</span>
						</td>
						<td>
							<span
								class="pq-actions__status"
								:data-status="action.status">
								{{ words[action.status] || action.status }}
							</span>
						</td>
						<td>
							{{ dayText(action.endDate, locale || pageLocale) }}
							<span v-if="daysLeft(action)" class="pq-actions__sub">
								{{ daysLeft(action) }}
							</span>
						</td>
						<td>
							<span class="pq-actions__who">
								<span
									class="pq-actions__initials"
									aria-hidden="true"
									>{{ initials(action.assigneeName) }}</span
								>
								{{ action.assigneeName }}
							</span>
						</td>
						<td>
							<button
								type="button"
								class="utrecht-button utrecht-button--subtle"
								:disabled="action.readOnly"
								:aria-label="`${words.edit}: ${action.title}`"
								data-testid="actions-edit"
								@click="open(action)">
								<span aria-hidden="true">✎</span>
							</button>
						</td>
					</tr>
				</tbody>
			</table>
		</template>
		<ActionEditModal
			v-if="editing !== null"
			:api="api"
			:action="editing.action"
			:assignees="list.assignees"
			:planId="editing.action ? editing.action.plan || '' : ''"
			:viewer="viewer"
			:locale="locale"
			@saved="closed(words.saved)"
			@deleted="closed(words.saved)"
			@cancel="editing = null" />
	</section>
</template>

<script>
import ActionEditModal from '../../modals/e/ActionEditModal.vue'
import { actionsApi } from '../../../shared/areaApi.js'
import { actionWords, daysLeftLine, dayText, fill } from '../../lib/actions.js'
import { initialsOf } from './contacts.js'

/**
 * @spec openspec/changes/personal-action-list/tasks.md#t03
 */
export default {
	name: 'ActionsPage',

	components: { ActionEditModal },

	props: {
		/** The session as `/portal/api/session` returns it. */
		session: { type: Object, default: null },
		/** The portal api. */
		api: { type: Object, required: true },
		/** The reader's locale; the page's `<html lang>` when empty. */
		locale: { type: String, default: '' },
	},

	data() {
		return {
			list: { actions: [], done: 0, total: 0, assignees: [] },
			loading: true,
			problem: '',
			notice: '',
			editing: null,
		}
	},

	computed: {
		/**
		 * @return {string} The page's language.
		 * @spec openspec/changes/personal-action-list/tasks.md#t03
		 */
		pageLocale() {
			return globalThis.document?.documentElement?.lang || ''
		},

		/**
		 * @return {Record<string, string>} The words in the reader's language.
		 * @spec openspec/changes/personal-action-list/tasks.md#t03
		 */
		words() {
			return actionWords(this.locale || this.pageLocale)
		},

		/**
		 * @return {string} The viewer's subject reference.
		 * @spec openspec/changes/personal-action-list/tasks.md#t03
		 */
		viewer() {
			return String(this.session?.subjectRef || '')
		},
	},

	/**
	 * Read the actions when the page opens.
	 *
	 * @spec openspec/changes/personal-action-list/tasks.md#t03
	 */
	async mounted() {
		await this.load()
	},

	methods: {
		fill,
		dayText,
		initials: initialsOf,

		/**
		 * @param {{endDate: string, status: string}} action An action.
		 * @return {string} "nog 6 dagen", or ''.
		 * @spec openspec/changes/personal-action-list/tasks.md#t03
		 */
		daysLeft(action) {
			return daysLeftLine(
				action,
				new Date().toISOString().slice(0, 10),
				this.words,
			)
		},

		/**
		 * Read the actions again.
		 *
		 * @return {Promise<void>} Resolves when read.
		 * @spec openspec/changes/personal-action-list/tasks.md#t03
		 */
		async load() {
			const list = await actionsApi(this.api).fetchActions()
			if (list === null) {
				this.problem = this.words.failed
			} else {
				this.list = list
				this.problem = ''
			}
			this.loading = false
		},

		/**
		 * Open the dialog for an action, or empty to add one.
		 *
		 * @param {object|null} action The action.
		 * @return {void}
		 * @spec openspec/changes/personal-action-list/tasks.md#t03
		 */
		open(action) {
			this.notice = ''
			this.editing = { action }
		},

		/**
		 * The dialog saved or deleted: say so and read the list again.
		 *
		 * @param {string} notice What to say.
		 * @return {Promise<void>} Resolves when read.
		 * @spec openspec/changes/personal-action-list/tasks.md#t03
		 */
		async closed(notice) {
			this.editing = null
			this.notice = notice
			await this.load()
		},
	},
}
</script>

<style scoped>
.pq-actions > * + * {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}

.pq-actions__head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: var(--utrecht-space-inline-md, 1rem);
}

.pq-actions__table {
	inline-size: 100%;
}

.pq-actions__title {
	display: block;
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}

.pq-actions__sub {
	display: block;
	font-size: var(--utrecht-typography-scale-sm-font-size, 0.875rem);
	color: var(--utrecht-color-grey-30, currentcolor);
}

.pq-actions__status {
	display: inline-block;
	padding-inline: var(--utrecht-space-inline-xs, 0.375rem);
	border-radius: var(--utrecht-border-radius-sm, 0.25rem);
	background: var(--utrecht-color-grey-95, transparent);
}

.pq-actions__who {
	display: inline-flex;
	align-items: center;
	gap: var(--utrecht-space-inline-xs, 0.375rem);
}

.pq-actions__initials {
	display: inline-grid;
	place-items: center;
	inline-size: 2rem;
	block-size: 2rem;
	border-radius: 50%;
	font-size: var(--utrecht-typography-scale-sm-font-size, 0.875rem);
	background: var(--utrecht-color-grey-90, transparent);
}

.pq-actions__error {
	color: var(--utrecht-feedback-danger-color, currentcolor);
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}

.pq-actions__sr {
	position: absolute;
	inline-size: 1px;
	block-size: 1px;
	overflow: hidden;
	clip-path: inset(50%);
}
</style>
