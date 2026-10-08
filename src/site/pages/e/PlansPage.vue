<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	"Samenwerken" (shared-plans-with-a-caseworker, board Plannen): the plans a
	resident owns or takes part in, with counts and a card each, the dialog to
	start one, and a plan opened in place. A resident reads only their own
	plans; the server decides.
-->
<template>
	<section
		class="pq-plans"
		aria-labelledby="pq-plans-title"
		data-testid="plans-page">
		<template v-if="openId === ''">
			<div class="pq-plans__head">
				<h1 id="pq-plans-title" class="utrecht-heading-2">
					{{ words.title }}
				</h1>
				<button
					type="button"
					class="utrecht-button utrecht-button--primary-action"
					data-testid="plans-new"
					@click="starting = true">
					{{ words.newPlan }}
				</button>
			</div>
			<p class="utrecht-paragraph">
				{{ words.intro }}
			</p>
			<p v-if="problem !== ''" class="utrecht-paragraph pq-plans__error" role="alert">
				{{ problem }}
			</p>
			<p v-if="loading" class="utrecht-paragraph" role="status">
				{{ words.loading }}
			</p>
			<template v-else>
				<h2 class="utrecht-heading-3">
					{{ words.yourPlans }}
				</h2>
				<div class="pq-plans__chips" role="group" :aria-label="words.yourPlans">
					<button
						v-for="chip in chips"
						:key="chip"
						type="button"
						class="utrecht-button"
						:class="chip === chipChosen ? 'utrecht-button--primary-action' : 'utrecht-button--secondary-action'"
						:aria-pressed="chip === chipChosen ? 'true' : 'false'"
						:data-testid="`plans-chip-${chip}`"
						@click="chipChosen = chip">
						{{ words[chip] }} ({{ count(chip) }})
					</button>
				</div>
				<p v-if="shown.length === 0" class="utrecht-paragraph" data-testid="plans-empty">
					{{ words.noPlans }}
				</p>
				<ul v-else class="pq-plans__cards">
					<li v-for="plan in shown" :key="plan.id" class="pq-plans__card" data-testid="plan-card">
						<h3 class="utrecht-heading-4">
							{{ plan.title }}
						</h3>
						<span class="pq-plans__tag" :data-state="plan.state">{{ words[plan.state] }}</span>
						<span v-if="daysText(plan)">{{ daysText(plan) }}</span>
						<span v-if="plan.goal">{{ words.goal }}: {{ plan.goal }}</span>
						<span v-if="plan.endDate">{{ words.endDate }}: {{ plan.endDate }}</span>
						<span>{{ fill(words.openActions, { count: plan.openActions }) }}</span>
						<span>{{ sharedText(plan) }}</span>
						<span class="pq-plans__people">
							<span v-for="person in plan.participants" :key="person.ref" class="pq-plans__person">
								<span class="pq-plans__initials" aria-hidden="true">{{ initials(person.displayName) }}</span>
								{{ person.displayName }}
							</span>
						</span>
						<span v-if="plan.totalActions > 0">{{ fill(words.progress, { done: plan.doneActions, total: plan.totalActions }) }}</span>
						<button
							type="button"
							class="utrecht-button utrecht-button--secondary-action"
							data-testid="plan-open"
							@click="openId = plan.id">
							{{ words.open }}: {{ plan.title }}
						</button>
					</li>
				</ul>
			</template>
		</template>
		<PlanPage
			v-else
			:planId="openId"
			:api="api"
			:session="session"
			:locale="locale"
			@back="closed"
			@changed="load" />
		<PlanStartModal
			v-if="starting"
			:api="api"
			:locale="locale"
			@cancel="starting = false"
			@started="started" />
	</section>
</template>

<script>
import PlanStartModal from '../../modals/e/PlanStartModal.vue'
import PlanPage from './PlanPage.vue'
import { plansApi } from '../../../shared/areaApi.js'
import { chipCount, daysLine, fill, plansOfChip, planWords } from '../../lib/plans.js'
import { initialsOf } from './contacts.js'

/**
 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
 */
export default {
	name: 'PlansPage',

	components: { PlanPage, PlanStartModal },

	props: {
		/** The session as `/portal/api/session` returns it. */
		session: { type: Object, default: null },
		/** The portal api (`fetchPlans` and the rest). */
		api: { type: Object, required: true },
		/** The reader's locale; the page's `<html lang>` when empty. */
		locale: { type: String, default: '' },
	},

	data() {
		return {
			overview: { plans: [], counts: {} },
			loading: true,
			problem: '',
			starting: false,
			openId: '',
			chips: ['all', 'running', 'action', 'done'],
			chipChosen: 'all',
		}
	},

	computed: {
		/**
		 * @return {Record<string, string>} The words in the reader's language.
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
		 */
		words() {
			return planWords(this.locale || globalThis.document?.documentElement?.lang || '')
		},

		/**
		 * @return {Array<object>} The cards under the chosen chip.
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
		 */
		shown() {
			return plansOfChip(this.overview.plans, this.chipChosen)
		},
	},

	/**
	 * Read the plans when the page opens.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
	 */
	async mounted() {
		await this.load()
	},

	methods: {
		fill,
		initials: initialsOf,

		/**
		 * @param {string} chip A chip.
		 * @return {number} Its count.
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
		 */
		count(chip) {
			return chipCount(this.overview.counts, chip)
		},

		/**
		 * @param {{daysLeft: number|null, state: string}} plan A card.
		 * @return {string} How long it has left, or '' for a done plan.
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
		 */
		daysText(plan) {
			return plan.state === 'done' ? '' : daysLine(plan.daysLeft, this.words)
		},

		/**
		 * @param {{role: string, sharedBy: string}} plan A card.
		 * @return {string} Who made it.
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
		 */
		sharedText(plan) {
			return plan.role === 'owner' ? this.words.madeByYou : fill(this.words.sharedBy, { name: plan.sharedBy })
		},

		/**
		 * Read the plans again.
		 *
		 * @return {Promise<void>} Resolves when read.
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
		 */
		async load() {
			const overview = await plansApi(this.api).fetchPlans()
			if (overview === null) {
				this.problem = this.words.failed
			} else {
				this.overview = overview
				this.problem = ''
			}
			this.loading = false
		},

		/**
		 * A plan was started: close the dialog and open it.
		 *
		 * @param {string} id The new plan.
		 * @return {Promise<void>} Resolves when read.
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
		 */
		async started(id) {
			this.starting = false
			await this.load()
			this.openId = id
		},

		/**
		 * Back to the list, read again.
		 *
		 * @return {Promise<void>} Resolves when read.
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
		 */
		async closed() {
			this.openId = ''
			await this.load()
		},
	},
}
</script>

<style scoped>
.pq-plans > * + * {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}

.pq-plans__head,
.pq-plans__chips,
.pq-plans__people {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}

.pq-plans__head {
	justify-content: space-between;
}

.pq-plans__cards {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(18rem, 1fr));
	gap: var(--utrecht-space-block-md, 1rem);
	list-style: none;
	margin: 0;
	padding: 0;
}

.pq-plans__card {
	display: flex;
	flex-direction: column;
	gap: var(--utrecht-space-block-xs, 0.25rem);
	padding: var(--utrecht-space-block-md, 1rem);
	border: var(--utrecht-border-width-sm, 1px) solid var(--utrecht-color-grey-80, currentcolor);
}

.pq-plans__tag {
	align-self: flex-start;
	padding: 0 var(--utrecht-space-inline-sm, 0.5rem);
	border: var(--utrecht-border-width-sm, 1px) solid var(--utrecht-color-grey-80, currentcolor);
	border-radius: 999px;
}

.pq-plans__initials {
	display: inline-grid;
	place-items: center;
	inline-size: 2rem;
	block-size: 2rem;
	border-radius: 50%;
	background: var(--utrecht-color-grey-90, Canvas);
}

.pq-plans__error {
	color: var(--utrecht-feedback-danger-color, currentcolor);
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}
</style>
