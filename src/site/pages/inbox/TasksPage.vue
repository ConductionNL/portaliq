<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	"My tasks" (portal-task-delivery): the resident's open portal tasks, read
	and completed through portaliq's bearer-guarded proxy. The completion form
	checks the task's frozen upload rules before sending and names each
	refusal in plain language; the server checks everything again. "View task"
	in the inbox opens one task here directly.
-->
<template>
	<div class="pq-tasks-page">
		<template v-if="detail">
			<BusyStatus v-if="detail.loading" :t="tr" />

			<section v-else-if="detail.refusal" class="pq-task-detail">
				<p class="utrecht-paragraph pq-task-refusal" role="alert">
					{{ tr(detail.refusal) }}
				</p>
				<button
					type="button"
					class="utrecht-button utrecht-button--subtle"
					@click="backToList">
					{{ tr('Back to the list') }}
				</button>
			</section>

			<section v-else class="pq-task-detail">
				<h2 class="utrecht-heading-2">
					{{ detail.task.title || tr('Task') }}
				</h2>
				<p
					v-if="detail.task.description"
					class="utrecht-paragraph pq-task-description">
					{{ detail.task.description }}
				</p>
				<p v-if="due(detail.task)" class="utrecht-paragraph pq-task-due">
					{{ tr('Finish before {date}', { date: due(detail.task) }) }}
					<strong
						v-if="detail.task.overdue === true"
						class="pq-task-overdue">
						· {{ tr('Overdue') }}
					</strong>
				</p>

				<p
					v-if="completed"
					class="utrecht-paragraph pq-task-done"
					role="status">
					{{ tr('Your task has been submitted. Thank you.') }}
				</p>

				<form v-else class="pq-task-form" @submit.prevent="submit">
					<div class="utrecht-form-field pq-task-upload">
						<label class="utrecht-form-label" for="portaliq-task-files">
							{{
								rules.required
									? tr('Add a file (required)')
									: tr('Add a file (optional)')
							}}
						</label>
						<ul
							v-if="ruleLines.length > 0"
							id="portaliq-task-file-rules"
							class="pq-task-upload-rules">
							<li v-for="line in ruleLines" :key="line">
								{{ line }}
							</li>
						</ul>
						<input
							id="portaliq-task-files"
							type="file"
							class="pq-task-file-input"
							:multiple="rules.maxFiles > 1"
							:accept="accept"
							:aria-required="rules.required ? 'true' : undefined"
							:aria-describedby="
								ruleLines.length > 0
									? 'portaliq-task-file-rules'
									: undefined
							"
							@change="pick($event.target.files)" />
					</div>

					<div class="utrecht-form-field pq-task-comment">
						<label
							class="utrecht-form-label"
							for="portaliq-task-comment">
							{{ tr('Comment (optional)') }}
						</label>
						<textarea
							id="portaliq-task-comment"
							v-model="comment"
							class="utrecht-textarea"
							rows="4" />
					</div>

					<p
						v-if="formError"
						class="utrecht-paragraph pq-error"
						role="alert">
						{{ formError }}
					</p>

					<button
						type="submit"
						class="utrecht-button utrecht-button--primary-action"
						:disabled="busy">
						{{ busy ? tr('Sending…') : tr('Submit task') }}
					</button>
				</form>

				<button
					type="button"
					class="utrecht-button utrecht-button--subtle"
					@click="backToList">
					{{ tr('Back to the list') }}
				</button>
			</section>
		</template>

		<BusyStatus v-else-if="loading" :t="tr" />

		<p v-else-if="tasks.length === 0" class="utrecht-paragraph pq-empty">
			<em>{{ tr('No open tasks.') }}</em>
		</p>

		<ul v-else class="pq-tasks">
			<li v-for="task in tasks" :key="task.uuid" class="pq-task-row">
				<button
					type="button"
					class="utrecht-button utrecht-button--subtle pq-task-open"
					@click="openTask(task.uuid)">
					<span class="pq-task-title">{{
						task.displayTitle || task.title
					}}</span>
					<span v-if="task.dueAt" class="pq-task-row-due">
						{{ tr('Finish before {date}', { date: due(task) }) }}
						<strong v-if="task.overdue === true" class="pq-task-overdue">
							· {{ tr('Overdue') }}
						</strong>
					</span>
				</button>
			</li>
		</ul>
	</div>
</template>

<script>
import BusyStatus from '../../components/inbox/BusyStatus.vue'
import { formatDate, sessionStore, takeTaskToOpen } from './inbox.js'
import { PAGE_EMITS, PAGE_PROPS } from './pageProps.js'
import {
	acceptAttribute,
	refusalKey,
	sizeInMb,
	uploadRules,
	validateFiles,
} from './tasks.js'
import { pageLocale, withStrings } from './translate.js'

/**
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-complete-their-tasks-req-srp-035
 */
export default {
	name: 'TasksPage',

	components: { BusyStatus },

	props: {
		...PAGE_PROPS,
		/** A task to open on arrival; else the one the inbox left. */
		initialTaskUuid: { type: String, default: '' },
	},

	emits: PAGE_EMITS,

	data() {
		return {
			loading: true,
			tasks: [],
			detail: null,
			comment: '',
			files: [],
			formError: '',
			busy: false,
			completed: false,
		}
	},

	computed: {
		/**
		 * @return {string} `nl` or `en`.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-complete-their-tasks-req-srp-035
		 */
		lang() {
			return pageLocale(this.locale)
		},

		/**
		 * @return {(key: string, vars?: object) => string} The translator.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-complete-their-tasks-req-srp-035
		 */
		tr() {
			return withStrings(this.t, this.lang)
		},

		/**
		 * @return {object} The open task's upload rules.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-complete-their-tasks-req-srp-035
		 */
		rules() {
			return uploadRules(this.detail?.task)
		},

		/**
		 * @return {Array<string>} The rules as sentences under the file field.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-complete-their-tasks-req-srp-035
		 */
		ruleLines() {
			const lines = []
			if (this.rules.maxFiles > 1) {
				lines.push(
					this.tr('You can add at most {count} file(s).', {
						count: this.rules.maxFiles,
					}),
				)
			}
			if (this.rules.maxSizeBytes > 0) {
				lines.push(
					this.tr('Maximum file size: {size} MB.', {
						size: sizeInMb(this.rules.maxSizeBytes),
					}),
				)
			}
			if (this.rules.acceptedTypes.length > 0) {
				lines.push(
					this.tr('Allowed file types: {types}.', {
						types: this.rules.acceptedTypes.join(', '),
					}),
				)
			}
			return lines
		},

		/**
		 * @return {string|undefined} The file input's accept value.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-complete-their-tasks-req-srp-035
		 */
		accept() {
			return acceptAttribute(this.rules.acceptedTypes)
		},
	},

	created() {
		this.loadList()
		const uuid = this.initialTaskUuid || takeTaskToOpen(sessionStore())
		if (uuid) {
			this.openTask(uuid)
		}
	},

	methods: {
		/**
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-complete-their-tasks-req-srp-035
		 */
		async loadList() {
			this.loading = true
			const page = await this.api.fetchTasks()
			this.tasks = Array.isArray(page?.results) ? page.results : []
			this.loading = false
		},

		/**
		 * Open one task, or say why it cannot be opened.
		 *
		 * @param {string} uuid The task uuid.
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-complete-their-tasks-req-srp-035
		 */
		async openTask(uuid) {
			this.comment = ''
			this.files = []
			this.formError = ''
			this.completed = false
			this.detail = { loading: true }
			const res = await this.api.fetchTask(uuid)
			this.detail = res?.ok
				? { task: res.task }
				: { refusal: refusalKey(res?.code) }
		},

		/**
		 * @return {void}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-complete-their-tasks-req-srp-035
		 */
		backToList() {
			this.detail = null
			this.loadList()
		},

		/**
		 * @param {FileList|Array<File>|null} list The chosen files.
		 * @return {void}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-complete-their-tasks-req-srp-035
		 */
		pick(list) {
			this.files = Array.from(list || [])
			this.formError = ''
		},

		/**
		 * @param {object} task A task row.
		 * @return {string} Its due date in the page language.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-complete-their-tasks-req-srp-035
		 */
		due(task) {
			return formatDate(task?.dueAt, this.lang)
		},

		/**
		 * Check the files, then send the comment and files through the proxy.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-complete-their-tasks-req-srp-035
		 */
		async submit() {
			const task = this.detail?.task
			if (!task || this.busy) {
				return
			}
			const violation = validateFiles(task, this.files, this.tr)
			if (violation) {
				this.formError = violation
				return
			}
			this.formError = ''
			this.busy = true
			const res = await this.api.completeTask(task.uuid, {
				comment: this.comment,
				files: this.files,
			})
			this.busy = false
			if (res?.ok) {
				this.completed = true
				this.loadList()
				this.$emit('refresh')
				return
			}
			this.formError = this.tr(refusalKey(res?.code))
		},
	},
}
</script>

<style scoped>
.pq-tasks {
	margin: 0;
	padding: 0;
	list-style: none;
}

.pq-task-open {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	text-align: start;
}

.pq-task-form {
	display: flex;
	flex-direction: column;
	gap: 16px;
	margin-block: 16px;
}
</style>
