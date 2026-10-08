<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	One plan (shared-plans-with-a-caseworker, board Plan): the goal, the
	actions, the note, the participants and the end date, with the alert in the
	last 14 days. Every participant changes the goal, the actions and the note;
	the owner alone changes who takes part, the end date, finishes or deletes
	the plan. The server decides what each person may do and this page only
	offers it.
-->
<template>
	<article class="pq-plan" :aria-busy="plan === null && !missing ? 'true' : undefined" data-testid="plan-page">
		<button
			type="button"
			class="utrecht-link utrecht-link--button pq-plan__back"
			data-testid="plan-back"
			@click="$emit('back')">
			{{ words.back }}
		</button>
		<p v-if="missing" class="utrecht-paragraph" role="alert" data-testid="plan-missing">
			{{ words.failed }}
		</p>
		<p v-else-if="plan === null" class="utrecht-paragraph" role="status">
			{{ words.loading }}
		</p>
		<template v-else>
			<div class="pq-plan__head">
				<h1 class="utrecht-heading-2" data-testid="plan-title">
					{{ plan.title }}
				</h1>
				<span class="pq-plan__tag" :data-state="plan.state">{{ words[plan.state] }}</span>
				<button
					type="button"
					class="utrecht-button utrecht-button--secondary-action"
					data-testid="plan-pdf"
					@click="pdf">
					{{ words.download }}
				</button>
			</div>
			<p v-if="notice !== ''" class="utrecht-paragraph" role="status" data-testid="plan-notice">
				{{ notice }}
			</p>
			<p v-if="problem !== ''" class="utrecht-paragraph pq-plan__error" role="alert" data-testid="plan-problem">
				{{ problem }}
			</p>
			<div
				v-if="plan.state === 'action'"
				class="utrecht-alert utrecht-alert--warning"
				role="status"
				data-testid="plan-alert">
				<strong>{{ fill(words.alert, { days: plan.daysLeft, date: plan.endDate }) }}</strong>
				<span>{{ fill(words.alertDetail, { count: plan.openActions }) }}</span>
			</div>

			<section aria-labelledby="pq-plan-goal">
				<h2 id="pq-plan-goal" class="utrecht-heading-3">
					{{ words.goal }}
				</h2>
				<template v-if="editing === 'goal'">
					<textarea v-model="draft" class="utrecht-textarea" data-testid="plan-goal-input" />
					<button type="button" class="utrecht-button utrecht-button--primary-action" data-testid="plan-goal-save" @click="saveField('goal')">
						{{ words.save }}
					</button>
					<button type="button" class="utrecht-button utrecht-button--secondary-action" @click="editing = ''">
						{{ words.cancel }}
					</button>
				</template>
				<template v-else>
					<p class="utrecht-paragraph" data-testid="plan-goal">
						{{ plan.goal }}
					</p>
					<button
						v-if="plan.canEdit"
						type="button"
						class="utrecht-button utrecht-button--subtle"
						data-testid="plan-goal-edit"
						@click="edit('goal', plan.goal)">
						{{ words.editGoal }}
					</button>
				</template>
			</section>

			<section aria-labelledby="pq-plan-actions">
				<h2 id="pq-plan-actions" class="utrecht-heading-3">
					{{ words.actions }}
				</h2>
				<p class="utrecht-paragraph">
					{{ fill(words.progress, { done: plan.doneActions, total: plan.totalActions }) }}
				</p>
				<table v-if="plan.actions.length > 0" class="utrecht-table" data-testid="plan-actions">
					<caption class="utrecht-table__caption">
						{{ words.actions }}: {{ plan.title }}
					</caption>
					<thead>
						<tr>
							<th scope="col">{{ words.actions }}</th>
							<th scope="col">{{ words.status }}</th>
							<th scope="col">{{ words.actionDate }}</th>
							<th scope="col">{{ words.actionWho }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="action in plan.actions" :key="action.id" data-testid="plan-action">
							<td>{{ action.title }}</td>
							<td>
								<select
									:value="action.status"
									:disabled="!plan.canEdit"
									:aria-label="`${words.status}: ${action.title}`"
									data-testid="plan-action-status"
									@change="setStatus(action, $event.target.value)">
									<option value="todo">{{ words.todo }}</option>
									<option value="doing">{{ words.doing }}</option>
									<option value="done">{{ words.doneStatus }}</option>
								</select>
							</td>
							<td>{{ action.endDate }}</td>
							<td>{{ action.assigneeName }}</td>
						</tr>
					</tbody>
				</table>
				<form v-if="plan.canEdit" class="pq-plan__add" novalidate @submit.prevent="addAction">
					<label for="pq-plan-new-action" class="utrecht-form-label">{{ words.actionTitle }}</label>
					<input id="pq-plan-new-action" v-model="newAction.title" class="utrecht-textbox" type="text" data-testid="plan-new-title" />
					<label for="pq-plan-new-date" class="utrecht-form-label">{{ words.actionDate }}</label>
					<input id="pq-plan-new-date" v-model="newAction.endDate" class="utrecht-textbox" type="date" data-testid="plan-new-date" />
					<label for="pq-plan-new-who" class="utrecht-form-label">{{ words.actionWho }}</label>
					<select id="pq-plan-new-who" v-model="newAction.assignee" data-testid="plan-new-who">
						<option v-for="person in plan.participants" :key="person.ref" :value="person.ref">
							{{ personName(person) }}
						</option>
					</select>
					<button type="submit" class="utrecht-button utrecht-button--secondary-action" data-testid="plan-add-action">
						{{ words.addAction }}
					</button>
				</form>
			</section>

			<section aria-labelledby="pq-plan-notes">
				<h2 id="pq-plan-notes" class="utrecht-heading-3">
					{{ words.notes }}
				</h2>
				<template v-if="editing === 'note'">
					<textarea v-model="draft" class="utrecht-textarea" data-testid="plan-note-input" />
					<button type="button" class="utrecht-button utrecht-button--primary-action" data-testid="plan-note-save" @click="saveField('note')">
						{{ words.save }}
					</button>
					<button type="button" class="utrecht-button utrecht-button--secondary-action" @click="editing = ''">
						{{ words.cancel }}
					</button>
				</template>
				<template v-else>
					<p class="utrecht-paragraph" data-testid="plan-note">
						{{ plan.note.text }}
					</p>
					<p v-if="plan.note.editedBy" class="utrecht-paragraph">
						{{ plan.note.editedBy }}, {{ plan.note.editedAt.slice(0, 10) }}
					</p>
					<button
						v-if="plan.canEdit"
						type="button"
						class="utrecht-button utrecht-button--subtle"
						data-testid="plan-note-edit"
						@click="edit('note', plan.note.text)">
						{{ words.editNote }}
					</button>
				</template>
			</section>

			<section aria-labelledby="pq-plan-people">
				<h2 id="pq-plan-people" class="utrecht-heading-3">
					{{ words.participants }}
				</h2>
				<ul class="utrecht-unordered-list">
					<li v-for="person in plan.participants" :key="person.ref" data-testid="plan-person">
						{{ personName(person) }}
						<button
							v-if="plan.isOwner && plan.canEdit && !person.isOwner"
							type="button"
							class="utrecht-button utrecht-button--subtle"
							data-testid="plan-person-remove"
							@click="removePerson(person)">
							{{ words.remove }}: {{ person.displayName }}
						</button>
					</li>
				</ul>
				<div v-if="plan.isOwner && plan.canEdit && addable.length > 0" class="pq-plan__add">
					<label for="pq-plan-add-person" class="utrecht-form-label">{{ words.addParticipant }}</label>
					<select id="pq-plan-add-person" v-model="newPerson" data-testid="plan-add-person">
						<option value="">-</option>
						<option v-for="contact in addable" :key="contact.id" :value="contact.id">
							{{ contact.displayName }}
						</option>
					</select>
					<button type="button" class="utrecht-button utrecht-button--secondary-action" data-testid="plan-add-person-submit" @click="addPerson">
						{{ words.addParticipant }}
					</button>
				</div>
			</section>

			<section aria-labelledby="pq-plan-end">
				<h2 id="pq-plan-end" class="utrecht-heading-3">
					{{ words.endDate }}
				</h2>
				<p class="utrecht-paragraph" data-testid="plan-end">
					{{ plan.endDate }} {{ daysText }}
				</p>
				<div v-if="plan.isOwner && plan.canEdit" class="pq-plan__add">
					<input v-model="endDraft" class="utrecht-textbox" type="date" :aria-label="words.endDate" data-testid="plan-end-input" />
					<button type="button" class="utrecht-button utrecht-button--secondary-action" data-testid="plan-end-save" @click="saveEnd">
						{{ words.save }}
					</button>
				</div>
			</section>

			<section v-if="plan.isOwner && plan.canEdit" class="pq-plan__owner">
				<button type="button" class="utrecht-button utrecht-button--secondary-action" data-testid="plan-done" @click="finish">
					{{ words.markDone }}
				</button>
				<button
					v-if="!confirming"
					type="button"
					class="utrecht-button utrecht-button--subtle"
					data-testid="plan-delete"
					@click="confirming = true">
					{{ words.deletePlan }}
				</button>
				<template v-else>
					<span>{{ words.sure }}</span>
					<button type="button" class="utrecht-button utrecht-button--primary-action" data-testid="plan-delete-confirm" @click="destroy">
						{{ words.deletePlan }}
					</button>
					<button type="button" class="utrecht-button utrecht-button--secondary-action" @click="confirming = false">
						{{ words.cancel }}
					</button>
				</template>
			</section>
		</template>
	</article>
</template>

<script>
import { plansApi } from '../../../shared/areaApi.js'
import { daysLine, fill, personLine, planWords } from '../../lib/plans.js'

/**
 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
 */
export default {
	name: 'PlanPage',

	props: {
		/** The plan to show. */
		planId: { type: String, required: true },
		/** The portal api. */
		api: { type: Object, required: true },
		/** The session, for the viewer's own reference. */
		session: { type: Object, default: null },
		/** The reader's locale; the page's `<html lang>` when empty. */
		locale: { type: String, default: '' },
	},

	emits: ['back', 'changed'],

	data() {
		return {
			plan: null,
			missing: false,
			problem: '',
			notice: '',
			editing: '',
			draft: '',
			endDraft: '',
			newAction: { title: '', endDate: '', assignee: '' },
			newPerson: '',
			confirming: false,
		}
	},

	computed: {
		/**
		 * @return {Record<string, string>} The words in the reader's language.
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
		 */
		words() {
			return planWords(this.locale || globalThis.document?.documentElement?.lang || '')
		},

		/**
		 * @return {string} How long the plan has left.
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
		 */
		daysText() {
			return this.plan.state === 'done' ? '' : daysLine(this.plan.daysLeft, this.words)
		},

		/**
		 * @return {Array<object>} The owner's approved contacts who are not in the plan yet.
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
		 */
		addable() {
			const inPlan = new Set((this.plan?.participants || []).map((person) => person.ref))
			return (this.plan?.contacts || []).filter((contact) => !inPlan.has(contact.ref))
		},
	},

	/**
	 * Read the plan when it opens.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
	 */
	async mounted() {
		await this.load()
	},

	methods: {
		fill,

		/**
		 * @param {{displayName: string, isOwner: boolean, ref: string}} person A participant.
		 * @return {string} "U, maker van het plan" for the viewer who made it.
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
		 */
		personName(person) {
			return personLine(person, String(this.session?.subjectRef || ''), this.words)
		},

		/**
		 * Read the plan again.
		 *
		 * @return {Promise<void>} Resolves when read.
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
		 */
		async load() {
			const plan = await plansApi(this.api).fetchPlan(this.planId)
			if (plan === null) {
				this.missing = true
				return
			}
			this.plan = plan
			this.endDraft = plan.endDate
			this.newAction.assignee = this.newAction.assignee || String(this.session?.subjectRef || plan.participants[0]?.ref || '')
		},

		/**
		 * Do one thing to the plan, say how it went and read the plan again.
		 *
		 * @param {string} action What to do (`planAction`).
		 * @param {object} args Its arguments.
		 * @return {Promise<boolean>} Whether it worked.
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
		 */
		async run(action, args) {
			this.problem = ''
			this.notice = ''
			const answer = await plansApi(this.api).planAction(action, { id: this.planId, ...args })
			if (!answer.ok) {
				this.problem = this.words.failed
				return false
			}
			this.notice = this.words.saved
			this.$emit('changed')
			await this.load()
			return true
		},

		/**
		 * Start editing the goal or the note.
		 *
		 * @param {string} field `goal` or `note`.
		 * @param {string} value Its current text.
		 * @return {void}
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
		 */
		edit(field, value) {
			this.editing = field
			this.draft = value
		},

		/**
		 * Save the goal or the note.
		 *
		 * @param {string} field `goal` or `note`.
		 * @return {Promise<void>} Resolves when answered.
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
		 */
		async saveField(field) {
			if (await this.run('update', { data: { [field]: this.draft } })) {
				this.editing = ''
			}
		},

		/**
		 * Save the end date. Owner only; the server refuses anyone else.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t05
		 */
		async saveEnd() {
			await this.run('update', { data: { endDate: this.endDraft } })
		},

		/**
		 * Mark the plan done.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
		 */
		async finish() {
			await this.run('update', { data: { status: 'done' } })
		},

		/**
		 * Delete the plan, then go back to the list.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
		 */
		async destroy() {
			const answer = await plansApi(this.api).planAction('delete', { id: this.planId })
			if (!answer.ok) {
				this.problem = this.words.failed
				return
			}
			this.$emit('back')
		},

		/**
		 * Change an action's status.
		 *
		 * @param {{id: string}} action The action.
		 * @param {string} status `todo`, `doing` or `done`.
		 * @return {Promise<void>} Resolves when answered.
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
		 */
		async setStatus(action, status) {
			await this.run('updateAction', { actionId: action.id, data: { status } })
		},

		/**
		 * Add the action in the form.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
		 */
		async addAction() {
			const data = { title: this.newAction.title.trim(), assignee: this.newAction.assignee }
			if (this.newAction.endDate !== '') {
				data.endDate = this.newAction.endDate
			}
			if (data.title === '') {
				this.problem = this.words.failed
				return
			}
			if (await this.run('addAction', { data })) {
				this.newAction.title = ''
				this.newAction.endDate = ''
			}
		},

		/**
		 * Add the chosen contact to the plan.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
		 */
		async addPerson() {
			if (this.newPerson !== '' && (await this.run('addParticipants', { data: { contactIds: [this.newPerson] } }))) {
				this.newPerson = ''
			}
		},

		/**
		 * Take a participant off the plan.
		 *
		 * @param {{ref: string}} person The participant.
		 * @return {Promise<void>} Resolves when answered.
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
		 */
		async removePerson(person) {
			await this.run('removeParticipant', { ref: person.ref })
		},

		/**
		 * Save the plan as a PDF.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t06
		 */
		async pdf() {
			this.problem = ''
			const answer = await plansApi(this.api).downloadPlanPdf(this.planId, this.plan.title)
			if (!answer.ok) {
				this.problem = answer.status === 503 ? this.words.pdfUnavailable : this.words.failed
			}
		},
	},
}
</script>

<style scoped>
.pq-plan > * + *,
.pq-plan section > * + * {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}

.pq-plan__head,
.pq-plan__add,
.pq-plan__owner {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}

.pq-plan__tag {
	padding: 0 var(--utrecht-space-inline-sm, 0.5rem);
	border: var(--utrecht-border-width-sm, 1px) solid var(--utrecht-color-grey-80, currentcolor);
	border-radius: 999px;
}

.pq-plan__back {
	background: none;
	border: 0;
	padding: 0;
	cursor: pointer;
	text-decoration: underline;
}

.pq-plan textarea {
	inline-size: 100%;
}

.pq-plan__error {
	color: var(--utrecht-feedback-danger-color, currentcolor);
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}
</style>
