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
			<p
				v-if="problem !== ''"
				class="utrecht-paragraph pq-plans__error"
				role="alert">
				{{ problem }}
			</p>
			<p v-if="loading" class="utrecht-paragraph" role="status">
				{{ words.loading }}
			</p>
			<template v-else>
				<h2 class="utrecht-heading-3">
					{{ words.yourPlans }}
				</h2>
				<div
					class="pq-plans__chips"
					role="group"
					:aria-label="words.yourPlans">
					<button
						v-for="chip in chips"
						:key="chip"
						type="button"
						class="utrecht-button"
						:class="
							chip === chipChosen
								? 'utrecht-button--primary-action'
								: 'utrecht-button--secondary-action'
						"
						:aria-pressed="chip === chipChosen ? 'true' : 'false'"
						:data-testid="`plans-chip-${chip}`"
						@click="chipChosen = chip">
						{{ words[chip] }} ({{ count(chip) }})
					</button>
				</div>
				<p
					v-if="shown.length === 0"
					class="utrecht-paragraph"
					data-testid="plans-empty">
					{{ words.noPlans }}
				</p>
				<ul v-else class="pq-plans__cards">
					<li
						v-for="plan in shown"
						:key="plan.id"
						class="pq-plans__card"
						data-testid="plan-card">
						<div class="pq-plans__title">
							<h3 class="utrecht-heading-4">
								<button
									type="button"
									class="pq-plans__open"
									data-testid="plan-open"
									@click="openId = plan.id">
									{{ plan.title }}
								</button>
							</h3>
							<span class="pq-plans__tag" :data-state="plan.state">{{
								words[plan.state]
							}}</span>
							<span v-if="daysText(plan)" class="pq-plans__tag pq-plans__tag--ending">{{
								daysText(plan)
							}}</span>
						</div>
						<p v-if="plan.goal" class="utrecht-paragraph pq-plans__goal">
							<strong>{{ words.goal }}:</strong> {{ plan.goal }}
						</p>
						<dl class="pq-plans__meta">
							<div v-if="plan.endDate">
								<dt>{{ words.endDate }}</dt>
								<dd>{{ dateText(plan.endDate) }}</dd>
							</div>
							<div>
								<dt>{{ words.openActionsLabel }}</dt>
								<dd>{{ plan.openActions }}</dd>
							</div>
							<div>
								<dt>{{ words.shared }}</dt>
								<dd>{{ sharedText(plan) }}</dd>
							</div>
						</dl>
						<div class="pq-plans__foot">
							<span class="pq-plans__people">
								<span class="pq-plans__faces" aria-hidden="true">
									<span
										v-for="person in plan.participants"
										:key="person.ref"
										class="pq-plans__initials">{{ initials(person.displayName) }}</span>
								</span>
								<span class="pq-plans__names">{{ peopleText(plan) }}</span>
							</span>
							<span v-if="plan.totalActions > 0" class="pq-plans__progress">
								<span>{{
									fill(words.progress, {
										done: plan.doneActions,
										total: plan.totalActions,
									})
								}}</span>
								<progress
									class="pq-plans__bar"
									:value="plan.doneActions"
									:max="plan.totalActions"
									aria-hidden="true" />
							</span>
						</div>
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
import {
	chipCount,
	daysLine,
	endingSoon,
	fill,
	plansOfChip,
	planWords,
} from '../../lib/plans.js'
import { initialsOf } from './contacts.js'
import { longDate, readerLocale } from './format.js'

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
			return planWords(
				this.locale || globalThis.document?.documentElement?.lang || '',
			)
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
			if (plan.state === 'done' || !endingSoon(plan.daysLeft)) {
				return ''
			}
			return daysLine(plan.daysLeft, this.words)
		},

		/**
		 * @param {string} date An end date, `YYYY-MM-DD`.
		 * @return {string} "20 oktober 2026" in the reader's language, or the date as it came.
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t10
		 */
		dateText(date) {
			return longDate(date, readerLocale(this.locale)) || date
		},

		/**
		 * @param {{participants: Array<{ref: string, displayName: string}>}} plan A card.
		 * @return {string} "Sanne de Vries (u), Mark Jansen": the people in the plan.
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t10
		 */
		peopleText(plan) {
			const viewer = this.session?.subjectRef || this.session?.subject?.subjectRef || ''
			return (plan.participants || [])
				.filter((person) => person.displayName)
				.map((person) => (person.ref === viewer ? `${person.displayName} (${this.words.you.toLocaleLowerCase()})` : person.displayName))
				.join(', ')
		},

		/**
		 * @param {{role: string, sharedBy: string}} plan A card.
		 * @return {string} Who made it.
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
		 */
		sharedText(plan) {
			return plan.role === 'owner'
				? this.words.madeByYou
				: fill(this.words.sharedBy, { name: plan.sharedBy })
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
/*
 * The list as board Plannen draws it (shared-plans-with-a-caseworker T10):
 * one card per plan in a column, the title a link with its state and, in
 * the last 14 days, the ending tag beside it; the goal; end date, open
 * actions and who shared it on one line; the people and the progress bar at
 * the foot. Colours come from the theme tokens.
 */
.pq-plans > * + * {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}

.pq-plans__head,
.pq-plans__chips,
.pq-plans__people,
.pq-plans__title {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}

.pq-plans__head {
	justify-content: space-between;
}

.pq-plans__cards {
	display: flex;
	flex-direction: column;
	gap: var(--utrecht-space-block-sm, 0.75rem);
	list-style: none;
	margin: 0;
	padding: 0;
}

.pq-plans__card {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	padding: var(--utrecht-space-block-md, 1rem);
	border: var(--utrecht-border-width-sm, 1px) solid
		var(--nldesign-color-border-light, var(--utrecht-color-grey-80, color-mix(in srgb, currentcolor 18%, transparent)));
	border-radius: var(--utrecht-border-radius-md, 0.375rem);
}

.pq-plans__title h3 {
	margin: 0;
}

.pq-plans__open {
	min-block-size: 24px;
	padding: 0;
	border: 0;
	background: none;
	color: var(--utrecht-link-color, LinkText);
	font: inherit;
	text-decoration: underline;
	cursor: pointer;
}

.pq-plans__tag {
	padding: 0.125rem 0.5rem;
	border-radius: 999px;
	background: var(--nldesign-color-primary-light, var(--utrecht-color-grey-90, Canvas));
	font-size: 0.75em;
}

.pq-plans__tag[data-state='action'] {
	background: var(--utrecht-feedback-warning-background-color, var(--nldesign-color-warning-light, Canvas));
}

.pq-plans__tag--ending {
	background: var(--utrecht-feedback-danger-background-color, var(--nldesign-color-error-light, Canvas));
	color: var(--utrecht-feedback-danger-color, currentcolor);
}

.pq-plans .pq-plans__goal {
	margin: 0;
}

.pq-plans__meta {
	display: flex;
	flex-wrap: wrap;
	gap: 0.25rem 1.5rem;
	margin: 0;
	font-size: 0.875em;
}

.pq-plans__meta div {
	display: flex;
	gap: 0.375rem;
}

.pq-plans__meta dt {
	color: var(--nldesign-color-text-muted, var(--utrecht-color-grey-30, GrayText));
}

.pq-plans__meta dd {
	margin: 0;
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}

.pq-plans__foot {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 0.5rem 1.5rem;
	padding-block-start: 0.5rem;
	border-block-start: 1px solid
		var(--nldesign-color-border-light, var(--utrecht-color-grey-80, color-mix(in srgb, currentcolor 18%, transparent)));
}

.pq-plans__faces {
	display: inline-flex;
}

.pq-plans__faces .pq-plans__initials + .pq-plans__initials {
	margin-inline-start: -0.5rem;
}

.pq-plans__initials {
	display: inline-grid;
	place-items: center;
	inline-size: 2rem;
	block-size: 2rem;
	border: 2px solid var(--utrecht-document-background-color, Canvas);
	border-radius: 50%;
	background: var(--nldesign-color-primary-light, var(--utrecht-color-grey-90, Canvas));
	font-size: 0.75em;
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}

.pq-plans__names {
	font-size: 0.875em;
}

.pq-plans__progress {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	min-inline-size: min(18rem, 100%);
	font-size: 0.875em;
}

.pq-plans__bar {
	inline-size: 100%;
	block-size: 0.375rem;
	accent-color: var(--utrecht-feedback-success-color, var(--nldesign-color-success, currentcolor));
}

.pq-plans__chips .utrecht-button {
	border-radius: 999px;
}

.pq-plans__error {
	color: var(--utrecht-feedback-danger-color, currentcolor);
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}
</style>
