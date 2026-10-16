<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	"My tasks" (portal-task-delivery): the resident's open portal tasks, read
	and completed through portaliq's bearer-guarded proxy, and what the apps
	ask of the resident in their `tasks` blocks, each opening its own page. The completion form
	checks the task's frozen upload rules before sending and names each
	refusal in plain language; the server checks everything again. "View task"
	in the inbox opens one task here directly. A task that names the app action
	completing it (`metadata.completeVia`) opens that action's form instead,
	with the task uuid preset, because OpenRegister refuses a direct completion.
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

				<template v-else-if="via.named">
					<SchemaForm
						v-if="via.action"
						:action="via.action"
						:api="api"
						:t="tr"
						:preset="{ [via.taskField]: detail.task.uuid }"
						:send="sendVia"
						@submitted="viaDone" />
					<p
						v-else
						class="utrecht-paragraph pq-task-refusal"
						role="status">
						{{
							tr(
								'The form for this task is not available to you right now.',
							)
						}}
					</p>
				</template>

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

		<!-- The list as Den Haag action rows (site-mijn-omgeving-components
		     REQ-SMO-004, REQ-SMO-009): a skeleton while it loads, a sentence
		     when it is empty, each task one button with its deadline badge. -->
		<Skeleton v-else-if="loading" :label="mt('Loading')" />

		<!-- A read that failed says so and offers to try again; it never
		     reads as "no open tasks" (site-mijn-omgeving-components
		     REQ-SMO-009). -->
		<LoadError
			v-else-if="failed && contributed.length === 0"
			:text="mt('Your tasks could not be loaded.')"
			:retryLabel="mt('Try again')"
			@retry="loadList" />

		<EmptyState
			v-else-if="tasks.length === 0 && contributed.length === 0"
			:text="tr('No open tasks.')" />

		<template v-else>
			<LoadError
				v-if="failed"
				:text="mt('Your tasks could not be loaded.')"
				:retryLabel="mt('Try again')"
				@retry="loadList" />
			<!-- The portal tasks, then what the apps ask of the resident
			     (mijn-taken-lists-contributed-tasks): each opens the page that
			     shows it. -->
			<ul class="pq-tasks">
				<ActionRow
					v-for="task in tasks"
					:key="task.uuid"
					:title="task.displayTitle || task.title || tr('Task')"
					:button="true"
					:badges="badgesOf(task)"
					@open="openTask(task.uuid)" />
				<ActionRow
					v-for="entry in contributed"
					:key="entry.key"
					:title="entry.title || tr('Task')"
					:route="entry.route"
					:badges="badgesOf({ dueAt: entry.due })"
					data-testid="tasks-contributed-row"
					@open="openContributed(entry)" />
			</ul>
		</template>
	</div>
</template>

<script>
import SchemaForm from '../../components/c/SchemaForm.vue'
import BusyStatus from '../../components/inbox/BusyStatus.vue'
import ActionRow from '../../components/mijn/ActionRow.vue'
import EmptyState from '../../components/mijn/EmptyState.vue'
import LoadError from '../../components/mijn/LoadError.vue'
import Skeleton from '../../components/mijn/Skeleton.vue'
import { forwardResult } from '../../components/c/forms.js'
import { deadlineBadge, mijnTranslator } from '../../components/mijn/rows.js'
import {
	contributedTaskEntries,
	contributedTaskSources,
} from './contributedTasks.js'
import {
	formatDate,
	keepRecordToOpen,
	sessionStore,
	takeTaskToOpen,
} from './inbox.js'
import { PAGE_EMITS, PAGE_PROPS } from './pageProps.js'
import {
	acceptAttribute,
	completeViaAction,
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

	components: {
		ActionRow,
		BusyStatus,
		EmptyState,
		LoadError,
		SchemaForm,
		Skeleton,
	},

	props: {
		...PAGE_PROPS,
		/** A task to open on arrival; else the one the inbox left. */
		initialTaskUuid: { type: String, default: '' },
	},

	emits: PAGE_EMITS,

	data() {
		return {
			loading: true,
			failed: false,
			tasks: [],
			contributed: [],
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
		 * @return {(key: string, vars?: object) => string} The translator of the mijn omgeving components.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
		 */
		mt() {
			return mijnTranslator(this.t, this.lang)
		},

		/**
		 * The app action the open task names as the one that completes it.
		 *
		 * @return {{named: boolean, app: string, action: object|null, taskField: string}} The action, or why none.
		 * @spec openspec/changes/a-task-opens-the-app-action-that-completes-it/specs/site-portal-parity/spec.md#requirement-a-task-that-names-its-app-action-must-open-that-action
		 */
		via() {
			return completeViaAction(this.detail?.task, this.contributions)
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

	/**
	 * Read on arrival.
	 *
	 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-complete-their-tasks-req-srp-035
	 */
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
			this.failed = false
			const portalTasks = (async () => {
				try {
					return await this.api.fetchTasks()
				} catch {
					return null
				}
			})()
			const [page, contributed] = await Promise.all([
				portalTasks,
				this.loadContributed(),
			])
			this.failed = !Array.isArray(page?.results) || page.failed === true
			this.tasks = this.failed ? [] : page.results
			this.contributed = contributed
			this.loading = false
		},

		/**
		 * What the apps ask of the resident: the rows of every collection a
		 * contribution shows in a `tasks` block. A collection that could not
		 * be read adds nothing.
		 *
		 * @return {Promise<Array<object>>} The entries.
		 * @spec openspec/changes/mijn-taken-lists-contributed-tasks/specs/site-mijn-omgeving/spec.md#requirement-my-tasks-lists-what-the-apps-ask-of-the-resident
		 */
		async loadContributed() {
			if (typeof this.api.fetchCollection !== 'function') {
				return []
			}
			const lists = await Promise.all(
				contributedTaskSources(this.contributions).map(async (source) => {
					let rows
					try {
						rows = await this.api.fetchCollection(source.collection, {
							orNull: true,
						})
					} catch {
						rows = null
					}
					return contributedTaskEntries(source, rows, this.nav)
				}),
			)
			return lists.flat()
		},

		/**
		 * Open what an app asks on the page that shows it, with its row picked.
		 *
		 * @param {{link: object, route: string}} entry The entry.
		 * @return {void}
		 * @spec openspec/changes/mijn-taken-lists-contributed-tasks/specs/site-mijn-omgeving/spec.md#requirement-my-tasks-lists-what-the-apps-ask-of-the-resident
		 */
		openContributed(entry) {
			if (!entry.route) {
				return
			}
			keepRecordToOpen(sessionStore(), entry.link)
			if (typeof this.navigate === 'function') {
				this.navigate(entry.route)
				return
			}
			this.$emit('navigate', entry.route)
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
		 * A task row's deadline badge: the server's overdue mark wins, else
		 * the deadline in words.
		 *
		 * @param {object} task A task row.
		 * @return {Array<object>} No badge or one.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
		 */
		badgesOf(task) {
			if (task?.overdue === true) {
				return [{ text: this.mt('Overdue'), state: 'error' }]
			}
			const badge = deadlineBadge(task?.dueAt, new Date(), this.mt, this.lang)
			return badge ? [badge] : []
		},

		/**
		 * Send the named action's answers through the portal's own forward,
		 * in the form's send shape.
		 *
		 * @param {object} body The answers, with the task uuid preset.
		 * @return {Promise<object>} `{ok, status, object, errors, invalid}`.
		 * @spec openspec/changes/a-task-opens-the-app-action-that-completes-it/specs/site-portal-parity/spec.md#requirement-a-task-that-names-its-app-action-must-open-that-action
		 */
		async sendVia(body) {
			const result = await this.api.forwardAction(
				this.via.app,
				this.via.action.id,
				body,
			)
			return forwardResult(result)
		},

		/**
		 * The app completed the task: thank the resident and read the list again.
		 *
		 * @return {void}
		 * @spec openspec/changes/a-task-opens-the-app-action-that-completes-it/specs/site-portal-parity/spec.md#requirement-a-task-that-names-its-app-action-must-open-that-action
		 */
		viaDone() {
			this.completed = true
			this.loadList()
			this.$emit('refresh')
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

.pq-task-form {
	display: flex;
	flex-direction: column;
	gap: 16px;
	margin-block: 16px;
}
</style>
