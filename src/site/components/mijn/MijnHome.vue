<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	What /mijn opens on (design D4): a greeting, then "Dit moet u nog doen"
	(the open portal tasks), then every page a contribution marks
	`home: true`, each under its app's name when there are several. Without a
	home page it shows the running cases of every `cases` collection and the
	newest messages instead. It is never blank: with nothing to do it says so.
-->
<template>
	<section class="pq-mijn-home" data-testid="mijn-home">
		<h1 id="site-account-title" class="utrecht-heading-2">{{ greeting }}</h1>

		<section
			v-if="showTasks"
			class="pq-mijn-home__tasks"
			aria-labelledby="pq-mijn-home-tasks"
			data-testid="mijn-home-tasks">
			<h2 id="pq-mijn-home-tasks" class="utrecht-heading-3">
				{{ tr('What you still have to do') }}
			</h2>
			<Skeleton
				v-if="tasks === null && !tasksFailed"
				:label="tr('Loading')"
				:rows="2" />
			<LoadError
				v-else-if="tasksFailed"
				:text="tr('Your tasks could not be loaded.')"
				:retryLabel="tr('Try again')"
				@retry="loadTasks" />
			<EmptyState
				v-else-if="tasks.length === 0"
				:text="tr('You have nothing to do right now.')" />
			<ul v-else class="pq-mijn-home__list">
				<ActionRow
					v-for="task in tasks"
					:key="task.uuid"
					:title="task.displayTitle || task.title || ''"
					:route="tasksRoute"
					:badges="badgesOf(task)"
					@open="openTask(task)" />
			</ul>
		</section>

		<template v-if="homeEntries.length > 0">
			<section
				v-for="entry in homeEntries"
				:key="entry.key"
				class="pq-mijn-home__page"
				:data-page="entry.page.id"
				data-testid="mijn-home-page">
				<h2 v-if="homeEntries.length > 1" class="utrecht-heading-3">
					{{ appNameOf(entry) }}
				</h2>
				<ContributionPage
					:entry="entry"
					:api="api"
					:contributions="contributions"
					:nav="nav"
					:t="t"
					:locale="locale"
					@navigate="$emit('navigate', $event)" />
			</section>
		</template>

		<template v-else>
			<ContributionPage
				v-for="overview in caseOverviews"
				:key="overview.key"
				:entry="overview"
				:api="api"
				:contributions="contributions"
				:nav="nav"
				:t="t"
				:locale="locale"
				@navigate="$emit('navigate', $event)" />
			<InboxBlock
				:block="{ type: 'inbox', limit: 2, label: tr('New messages') }"
				:api="api"
				:nav="nav"
				:level="2"
				:t="t"
				:locale="locale"
				@navigate="$emit('navigate', $event)" />
		</template>
	</section>
</template>

<script>
import { defineAsyncComponent } from 'vue'
import ActionRow from './ActionRow.vue'
import EmptyState from './EmptyState.vue'
import InboxBlock from './InboxBlock.vue'
import LoadError from './LoadError.vue'
import Skeleton from './Skeleton.vue'
import {
	keepTaskToOpen,
	sessionStore,
	TASKS_ROUTE,
} from '../../pages/inbox/inbox.js'
import { caseOverviewsOf, homeEntriesOf } from './home.js'
import { deadlineBadge, mijnTranslator } from './rows.js'

/**
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-mijn-must-open-on-what-the-resident-still-has-to-do-req-smo-007
 */
export default {
	name: 'MijnHome',

	components: {
		ActionRow,
		ContributionPage: defineAsyncComponent(
			() => import('../../pages/collections/ContributionPage.vue'),
		),

		EmptyState,
		InboxBlock,
		LoadError,
		Skeleton,
	},

	props: {
		/** The session, for the greeting. */
		session: { type: Object, default: null },
		/** The signed-in navigation. */
		nav: { type: Array, default: () => [] },
		/** The contributions aggregate. */
		contributions: { type: Object, default: null },
		/** The shared portal api. */
		api: { type: Object, default: null },
		/** The site translator. */
		t: { type: Function, default: null },
		/** The page language. */
		locale: { type: String, default: 'nl' },
		/** Tasks to start from, for a test. */
		initialTasks: { type: Array, default: null },
	},

	emits: ['navigate'],

	data() {
		return {
			tasks: this.initialTasks,
			tasksFailed: false,
			tasksRoute: TASKS_ROUTE,
		}
	},

	computed: {
		/**
		 * @return {(key: string, vars?: object) => string} The translator.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-mijn-must-open-on-what-the-resident-still-has-to-do-req-smo-007
		 */
		tr() {
			return mijnTranslator(this.t, this.locale)
		},

		/**
		 * "Welkom, Sanne": the name the header shows, never a number.
		 *
		 * @return {string}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-mijn-must-open-on-what-the-resident-still-has-to-do-req-smo-007
		 */
		greeting() {
			const name = String(
				this.session?.displayName || this.session?.name || '',
			).trim()
			const first = name.split(/\s+/)[0] || ''
			return first === ''
				|| /^\d+$/.test(first)
				|| name === this.session?.subjectRef
				? this.tr('Welcome')
				: this.tr('Welcome, {name}', { name: first })
		},

		/**
		 * @return {Array<object>} The home pages.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-mijn-must-open-on-what-the-resident-still-has-to-do-req-smo-007
		 */
		homeEntries() {
			return homeEntriesOf(this.nav)
		},

		/**
		 * @return {Array<object>} The default overview, without a home page.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-mijn-must-open-on-what-the-resident-still-has-to-do-req-smo-007
		 */
		caseOverviews() {
			return caseOverviewsOf(
				this.contributions?.contributions,
				this.tr('Running cases'),
			)
		},

		/**
		 * "Dit moet u nog doen" shows when the portal has tasks; with home
		 * pages only when something is open, without them always, so a
		 * resident with nothing to do reads that.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-mijn-must-open-on-what-the-resident-still-has-to-do-req-smo-007
		 */
		showTasks() {
			if (this.homeEntries.length === 0) {
				return true
			}
			return (
				this.tasksFailed
				|| (Array.isArray(this.tasks) && this.tasks.length > 0)
			)
		},
	},

	/**
	 * Read the open tasks on arrival.
	 *
	 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-mijn-must-open-on-what-the-resident-still-has-to-do-req-smo-007
	 */
	created() {
		if (this.tasks === null) {
			this.loadTasks()
		}
	},

	methods: {
		/**
		 * Read the portal's open tasks, soonest deadline first; a failed read
		 * says so.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-mijn-must-open-on-what-the-resident-still-has-to-do-req-smo-007
		 */
		async loadTasks() {
			this.tasks = null
			this.tasksFailed = false
			let page
			try {
				page = await this.api?.fetchTasks?.()
			} catch {
				page = null
			}
			if (!Array.isArray(page?.results) || page.failed === true) {
				this.tasksFailed = true
				return
			}
			const time = (task) => {
				const ms = Date.parse(task?.dueAt || '')
				return Number.isNaN(ms) ? Number.POSITIVE_INFINITY : ms
			}
			this.tasks = [...page.results].sort((a, b) => time(a) - time(b))
		},

		/**
		 * @param {object} task A task.
		 * @return {Array<object>} Its deadline badge, or none.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-mijn-must-open-on-what-the-resident-still-has-to-do-req-smo-007
		 */
		badgesOf(task) {
			if (task?.overdue === true) {
				return [{ text: this.tr('Overdue'), state: 'error' }]
			}
			const badge = deadlineBadge(
				task?.dueAt,
				new Date(),
				this.tr,
				this.locale,
			)
			return badge ? [badge] : []
		},

		/**
		 * Open one task on "Mijn taken".
		 *
		 * @param {object} task The task.
		 * @return {void}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-mijn-must-open-on-what-the-resident-still-has-to-do-req-smo-007
		 */
		openTask(task) {
			keepTaskToOpen(sessionStore(), task.uuid)
			this.$emit('navigate', TASKS_ROUTE)
		},

		/**
		 * @param {object} entry A home page's entry.
		 * @return {string} Its app's name.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-mijn-must-open-on-what-the-resident-still-has-to-do-req-smo-007
		 */
		appNameOf(entry) {
			return entry.contribution?.label || entry.contribution?.app || ''
		},
	},
}
</script>

<style scoped>
.pq-mijn-home > * + * {
	margin-block-start: var(--utrecht-space-block-lg, 1.5rem);
}

.pq-mijn-home__list {
	margin: 0;
	padding: 0;
}
</style>
