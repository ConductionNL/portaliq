<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A timed task on the site (portal-take-assessment): the tests a pupil can
	start, their attempts, the question screen with a countdown, and the result
	once it is released. Every step is one of the collection's five endpoint
	actions, forwarded by portaliq to the leaf app, which keeps every rule. The
	countdown follows the server's deadline, extra time included.
-->
<template>
	<section v-if="attempt" class="pq-timedtask">
		<h3 class="utrecht-heading-3">{{ attempt.title }}</h3>
		<p class="utrecht-paragraph pq-timedtask-clock" aria-hidden="true">
			{{ clockText }}
		</p>
		<p class="sr-only" aria-live="polite">{{ spokenClock }}</p>
		<p class="utrecht-paragraph">
			{{
				tr('Question {current} of {total}', {
					current: index + 1,
					total: attempt.items.length,
				})
			}}
		</p>
		<TimedTaskItem
			v-if="currentItem"
			:key="currentItem.itemId"
			:item="currentItem"
			:value="answers[currentItem.itemId]"
			:t="tr"
			@change="answer(currentItem.itemId, $event)" />
		<p class="utrecht-paragraph pq-help" aria-live="polite">
			{{ currentUnsaved ? tr('Not saved yet') : tr('Saved') }}
		</p>
		<div class="pq-timedtask-actions">
			<button
				type="button"
				class="utrecht-button utrecht-button--secondary-action"
				:disabled="index === 0"
				@click="go(index - 1)">
				{{ tr('Previous') }}
			</button>
			<button
				type="button"
				class="utrecht-button utrecht-button--secondary-action"
				:disabled="index >= attempt.items.length - 1"
				@click="go(index + 1)">
				{{ tr('Next') }}
			</button>
			<button
				type="button"
				class="utrecht-button utrecht-button--primary-action"
				@click="confirming = true">
				{{ tr('Hand in') }}
			</button>
		</div>
		<div
			v-if="confirming"
			role="alertdialog"
			aria-live="assertive"
			class="pq-timedtask-confirm">
			<p class="utrecht-paragraph">
				{{
					tr(
						'Hand in your test? You have answered {answered} of {total} questions.',
						{ answered: answeredCount, total: attempt.items.length },
					)
				}}
			</p>
			<button
				type="button"
				class="utrecht-button utrecht-button--primary-action"
				@click="handIn(false)">
				{{ tr('Yes, hand in') }}
			</button>
			<button
				type="button"
				class="utrecht-button utrecht-button--secondary-action"
				@click="confirming = false">
				{{ tr('Keep working') }}
			</button>
		</div>
	</section>

	<section v-else class="pq-timedtask">
		<h3 v-if="collection.label" class="utrecht-heading-3">
			{{ collection.label }}
		</h3>
		<p v-if="notice" class="utrecht-paragraph pq-success" role="status">
			{{ notice }}
		</p>
		<template v-if="result !== undefined">
			<p v-if="result === null" class="utrecht-paragraph" role="status">
				<span aria-hidden="true">…</span>
				<span class="sr-only">{{ tr('Loading…') }}</span>
			</p>
			<p v-else-if="result.released !== true" class="utrecht-paragraph">
				{{ tr('Your result is not available yet.') }}
			</p>
			<div v-else class="pq-timedtask-result">
				<h4 class="utrecht-heading-4">{{ tr('Your result') }}</h4>
				<p v-if="typeof result.score === 'number'" class="utrecht-paragraph">
					{{
						tr('Score: {score} of {maxScore}', {
							score: result.score,
							maxScore: result.maxScore ?? '',
						})
					}}
				</p>
				<p
					v-if="typeof result.passed === 'boolean'"
					class="utrecht-paragraph">
					{{ result.passed ? tr('Passed') : tr('Not passed') }}
				</p>
				<p
					v-if="
						typeof result.feedback === 'string' && result.feedback !== ''
					"
					class="utrecht-paragraph">
					{{ result.feedback }}
				</p>
				<ol>
					<li v-for="item in resultItems" :key="item.itemId">
						<p class="utrecht-paragraph">{{ item.prompt }}</p>
						<p class="utrecht-paragraph">
							{{ tr('Your answer') }}: {{ answerText(item.response) }}
						</p>
						<p
							v-if="typeof item.score === 'number'"
							class="utrecht-paragraph">
							{{
								tr('Score: {score} of {maxScore}', {
									score: item.score,
									maxScore: item.maxScore ?? '',
								})
							}}
						</p>
					</li>
				</ol>
			</div>
			<button
				type="button"
				class="utrecht-button utrecht-button--subtle"
				@click="result = undefined">
				{{ tr('Back to tests') }}
			</button>
		</template>

		<h4 class="utrecht-heading-4">{{ tr('Tests you can take') }}</h4>
		<p v-if="tasks === null" class="utrecht-paragraph" role="status">
			<span aria-hidden="true">…</span>
			<span class="sr-only">{{ tr('Loading…') }}</span>
		</p>
		<p v-else-if="tasks.length === 0" class="utrecht-paragraph">
			{{ tr('No tests are open for you right now.') }}
		</p>
		<ul class="pq-timedtask-tasks">
			<li v-for="task in tasks || []" :key="task.taskId">
				<strong>{{ task.title }}</strong>
				<span v-if="typeof task.timeLimitMinutes === 'number'">
					{{
						tr('Time limit: {minutes} minutes', {
							minutes: task.timeLimitMinutes,
						})
					}}
				</span>
				<span
					v-if="
						typeof task.extraTimeMinutes === 'number'
						&& task.extraTimeMinutes > 0
					">
					{{
						tr('Including {minutes} minutes of extra time', {
							minutes: task.extraTimeMinutes,
						})
					}}
				</span>
				<span
					v-if="
						task.needsAccessCode === true && task.state !== 'in-progress'
					"
					class="utrecht-form-field">
					<label
						class="utrecht-form-label"
						:for="`tt-code-${task.taskId}`"
						>{{ tr('Access code') }}</label
					>
					<input
						:id="`tt-code-${task.taskId}`"
						type="text"
						class="utrecht-textbox"
						autocomplete="off"
						:value="codes[task.taskId] || ''"
						@input="
							codes = { ...codes, [task.taskId]: $event.target.value }
						" />
				</span>
				<button
					type="button"
					class="utrecht-button utrecht-button--secondary-action"
					@click="start(task)">
					{{ task.state === 'in-progress' ? tr('Continue') : tr('Start') }}
				</button>
			</li>
		</ul>
		<p v-if="error" class="utrecht-paragraph pq-error" role="alert">
			{{ error }}
		</p>
		<template v-if="attempts.length > 0">
			<h4 class="utrecht-heading-4">{{ tr('Your attempts') }}</h4>
			<ul class="pq-timedtask-attempts">
				<li v-for="row in attempts" :key="attemptIdOf(row)">
					<span>{{ row.assessmentTitle || row.assessmentId }}</span>
					<span v-if="row.lifecycle"> {{ row.lifecycle }}</span>
					<button
						v-if="row.lifecycle !== 'in-progress' && attemptIdOf(row)"
						type="button"
						class="utrecht-button utrecht-button--subtle"
						@click="showResult(attemptIdOf(row))">
						{{ tr('View result') }}
					</button>
				</li>
			</ul>
		</template>
	</section>
</template>

<script>
import TimedTaskItem from './TimedTaskItem.vue'
import {
	createSaveQueue,
	formatClock,
	initialResponse,
	isAnswered,
	secondsLeft,
	startAttempt,
	submitAttempt,
} from '../../../shared/timedTask.js'
import { withStrings } from '../../pages/inbox/translate.js'

/**
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-pupil-must-be-able-to-take-a-timed-task-req-srp-036
 */
export default {
	name: 'TimedTaskView',

	components: { TimedTaskItem },

	props: {
		/** The normalised `timedTask` collection. */
		collection: { type: Object, required: true },
		/** The contributing app. */
		app: { type: String, required: true },
		/** The subject's attempts (the collection rows). */
		attempts: { type: Array, default: () => [] },
		/** The shared portal API. */
		api: { type: Object, required: true },
		/** The translator. */
		t: { type: Function, default: null },
		/** The page language. */
		locale: { type: String, default: '' },
		/** The tests to show at once (tests; else they are read). */
		initialTasks: { type: Array, default: null },
	},

	emits: ['changed'],

	data() {
		return {
			tasks: this.initialTasks,
			codes: {},
			error: null,
			attempt: null,
			index: 0,
			answers: {},
			saveState: { unsaved: [], failed: [], closed: false },
			confirming: false,
			now: Date.now(),
			receivedAt: 0,
			result: undefined,
			notice: null,
		}
	},

	computed: {
		/**
		 * @return {(key: string, vars?: object) => string} The translator.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-pupil-must-be-able-to-take-a-timed-task-req-srp-036
		 */
		tr() {
			return withStrings(this.t, this.locale)
		},

		/**
		 * @return {object} The five action ids.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-pupil-must-be-able-to-take-a-timed-task-req-srp-036
		 */
		block() {
			return this.collection.timedTask || {}
		},

		/**
		 * @return {number|null} Seconds left, or null for no limit.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-pupil-must-be-able-to-take-a-timed-task-req-srp-036
		 */
		left() {
			if (!this.attempt) {
				return null
			}
			return secondsLeft(
				this.attempt.deadlineAt,
				this.attempt.serverNow,
				this.receivedAt,
				this.now,
			)
		},

		/**
		 * @return {string} The visible clock.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-pupil-must-be-able-to-take-a-timed-task-req-srp-036
		 */
		clockText() {
			return this.left === null
				? this.tr('No time limit')
				: this.tr('Time left: {time}', { time: formatClock(this.left) })
		},

		/**
		 * @return {string} The clock a screen reader hears, once a minute.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-pupil-must-be-able-to-take-a-timed-task-req-srp-036
		 */
		spokenClock() {
			return this.left === null
				? ''
				: this.tr('Time left: {time}', {
						time: formatClock(this.left - (this.left % 60)),
					})
		},

		/**
		 * @return {object|null} The question on screen.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-pupil-must-be-able-to-take-a-timed-task-req-srp-036
		 */
		currentItem() {
			return this.attempt ? this.attempt.items[this.index] || null : null
		},

		/**
		 * @return {boolean} Whether the question on screen waits to be saved.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-pupil-must-be-able-to-take-a-timed-task-req-srp-036
		 */
		currentUnsaved() {
			return this.currentItem
				? this.saveState.unsaved.includes(this.currentItem.itemId)
				: false
		},

		/**
		 * @return {number} How many questions have an answer.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-pupil-must-be-able-to-take-a-timed-task-req-srp-036
		 */
		answeredCount() {
			return this.attempt
				? this.attempt.items.filter((i) =>
						isAnswered(i, this.answers[i.itemId]),
					).length
				: 0
		},

		/**
		 * @return {Array<object>} The result's items.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-pupil-must-be-able-to-take-a-timed-task-req-srp-036
		 */
		resultItems() {
			return Array.isArray(this.result?.items) ? this.result.items : []
		},
	},

	watch: {
		/**
		 * Hand in when the clock reaches zero.
		 *
		 * @param {number|null} value Seconds left.
		 * @return {void}
		 */
		left(value) {
			if (this.attempt && value === 0) {
				this.handIn(true)
			}
		},

		/**
		 * Hand in when the leaf app closed the attempt.
		 *
		 * @param {object} value The save state.
		 * @return {void}
		 */
		saveState(value) {
			if (this.attempt && value.closed) {
				this.handIn(true)
			}
		},
	},

	created() {
		this.queue = null
		this.timer = null
		this.submitting = false
		if (this.tasks === null) {
			this.loadTasks()
		}
	},

	beforeUnmount() {
		this.stopClock()
	},

	methods: {
		/**
		 * Read the tests the pupil can start.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-pupil-must-be-able-to-take-a-timed-task-req-srp-036
		 */
		async loadTasks() {
			const answer = await this.api.forwardAction(
				this.app,
				this.block.available,
				{},
			)
			this.tasks =
				answer?.ok && Array.isArray(answer.body?.tasks)
					? answer.body.tasks
					: []
		},

		/**
		 * Start or resume a test.
		 *
		 * @param {object} task The test from `available`.
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-pupil-must-be-able-to-take-a-timed-task-req-srp-036
		 */
		async start(task) {
			this.error = null
			const outcome = await startAttempt(
				this.api,
				this.app,
				this.block,
				task.taskId,
				this.codes[task.taskId] || '',
			)
			if (!outcome.ok) {
				this.error =
					outcome.message || this.tr('This test could not be started.')
				return
			}
			const started = outcome.attempt
			const initial = {}
			for (const item of started.items) {
				initial[item.itemId] = initialResponse(
					item,
					started.responses[item.itemId],
				)
			}
			this.queue = createSaveQueue(
				(itemId, value) =>
					this.api.forwardAction(this.app, this.block.answer, {
						attemptId: started.attemptId,
						itemId,
						response: value,
					}),
				(state) => {
					this.saveState = state
				},
			)
			this.submitting = false
			this.receivedAt = Date.now()
			this.now = this.receivedAt
			this.answers = initial
			this.index = 0
			this.confirming = false
			this.notice = null
			this.result = undefined
			this.saveState = { unsaved: [], failed: [], closed: false }
			this.attempt = started
			this.startClock()
		},

		/**
		 * @return {void}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-pupil-must-be-able-to-take-a-timed-task-req-srp-036
		 */
		startClock() {
			this.stopClock()
			if (this.attempt?.deadlineAt && typeof setInterval === 'function') {
				this.timer = setInterval(() => {
					this.now = Date.now()
				}, 1000)
			}
		},

		/**
		 * @return {void}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-pupil-must-be-able-to-take-a-timed-task-req-srp-036
		 */
		stopClock() {
			if (this.timer) {
				clearInterval(this.timer)
				this.timer = null
			}
		},

		/**
		 * Keep and queue one answer.
		 *
		 * @param {string} itemId The question.
		 * @param {string|Array<string>|Record<string, string>} value The answer.
		 * @return {void}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-pupil-must-be-able-to-take-a-timed-task-req-srp-036
		 */
		answer(itemId, value) {
			this.answers = { ...this.answers, [itemId]: value }
			this.queue.set(itemId, value)
		},

		/**
		 * Go to another question, saving on the way.
		 *
		 * @param {number} next The question's place.
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-pupil-must-be-able-to-take-a-timed-task-req-srp-036
		 */
		async go(next) {
			this.index = next
			await this.queue.flush()
		},

		/**
		 * Hand the attempt in, by the pupil or because time is up.
		 *
		 * @param {boolean} byClock Whether the deadline triggered it.
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-pupil-must-be-able-to-take-a-timed-task-req-srp-036
		 */
		async handIn(byClock) {
			if (this.submitting || !this.attempt) {
				return
			}
			this.submitting = true
			const outcome = await submitAttempt(
				this.api,
				this.app,
				this.block,
				this.attempt.attemptId,
				this.queue,
			)
			if (!outcome.allSaved) {
				this.notice = this.tr(
					'Some answers could not be saved. Check your connection and try again.',
				)
			} else {
				this.notice = byClock
					? this.tr('Time is up. Your test has been handed in.')
					: this.tr('Your test is handed in.')
			}
			const finished = this.attempt.attemptId
			this.stopClock()
			this.attempt = null
			this.confirming = false
			this.$emit('changed')
			this.loadTasks()
			await this.showResult(finished)
		},

		/**
		 * Read and show one attempt's result.
		 *
		 * @param {string} attemptId The attempt.
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-pupil-must-be-able-to-take-a-timed-task-req-srp-036
		 */
		async showResult(attemptId) {
			this.result = null
			const answer = await this.api.forwardAction(
				this.app,
				this.block.result,
				{ attemptId },
			)
			this.result = answer?.ok ? answer.body : { released: false }
		},

		/**
		 * @param {object} row An attempt row.
		 * @return {string|undefined} Its id.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-pupil-must-be-able-to-take-a-timed-task-req-srp-036
		 */
		attemptIdOf(row) {
			return row.id || row['@self']?.id
		},

		/**
		 * @param {string|Array<string>|object|null} response A stored answer.
		 * @return {string} It as text.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-pupil-must-be-able-to-take-a-timed-task-req-srp-036
		 */
		answerText(response) {
			return typeof response === 'string'
				? response
				: JSON.stringify(response ?? '')
		},
	},
}
</script>

<style scoped>
.pq-timedtask-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.pq-timedtask-clock {
	font-weight: bold;
}
</style>
