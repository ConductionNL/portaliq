<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<section
		class="pq-contribution-page"
		:data-page="currentPage ? currentPage.id : undefined"
		data-testid="contribution-page">
		<p
			v-if="recordNotFound"
			class="utrecht-paragraph pq-contribution-page__notice"
			role="status"
			data-testid="contribution-page-record-not-found">
			{{ tr('This record is not in your list, so nothing of it is shown.') }}
		</p>

		<!-- A page that switches between records (`records`): for whom it
		     shows, as a radio group; the choice is the route
		     (site-mijn-omgeving-components REQ-SMO-008). -->
		<RecordSwitcher
			v-if="switching && recordRows.length > 0"
			:rows="recordRows"
			:chosen="recordId"
			:titleFields="recordPage.titleFields || []"
			:subtitleFields="recordPage.subtitleFields || []"
			:subtitles="switcherSubtitles"
			:legend="switcherLegend"
			:name="`pq-switch-${currentPage ? currentPage.id : 'page'}`"
			@choose="choose" />

		<!-- A record page (contribution-record-page): the record's name and
		     the way back once one is open, a hint while the list shows. -->
		<!-- A page whose record is its heading, while no record is open
		     (zuiddrecht-resident-pages-match-the-boards). -->
		<h1
			v-if="recordHeads && !activeRecord"
			id="site-account-title"
			class="utrecht-heading-2"
			data-testid="site-account-title">
			{{ entry ? entry.label : '' }}
		</h1>
		<div
			v-if="recordPage && activeRecord"
			class="pq-record__head"
			:class="{ 'pq-record__head--titled': recordHeads }"
			:data-record="recordId"
			data-testid="record-head">
			<!-- The record's name as the page's h1, the page label and the
			     record's reference as its eyebrow. -->
			<div v-if="recordHeads" class="pq-record__titles">
				<p class="pq-record__eyebrow" data-testid="record-eyebrow">
					{{ eyebrow }}
				</p>
				<h1
					:id="recordHeadingId"
					ref="recordHeading"
					class="utrecht-heading-2 pq-record__title pq-record__title--h1"
					tabindex="-1"
					data-testid="site-account-title">
					{{ recordName }}
				</h1>
			</div>
			<h2
				v-else
				:id="recordHeadingId"
				ref="recordHeading"
				class="utrecht-heading-2 pq-record__title"
				tabindex="-1">
				{{ recordName }}
			</h2>
			<button
				v-if="recordRows.length > 1 && !switching"
				type="button"
				class="utrecht-button utrecht-button--secondary-action"
				data-testid="record-back"
				@click="closeRecord">
				{{ tr('Back to {label}', { label: backLabel }) }}
			</button>
		</div>
		<p
			v-else-if="recordPage && recordRows.length > 1 && !switching"
			class="utrecht-paragraph"
			data-testid="record-hint">
			{{ tr('Open a name to see everything about it.') }}
		</p>

		<!-- The blocks in two columns, framed, with a link in their heading
		     row, where the page declares it (mijn-overview-follows-the-boards);
		     otherwise the shells draw nothing of their own. -->
		<BlockShell
			kind="grid"
			:wrap="layout.columns"
			@navigate="$emit('navigate', $event)">
			<BlockShell
				v-for="item in visibleBlocks"
				:key="item.index"
				kind="block"
				:wrap="shellWraps(item)"
				:classes="blockClasses(item.block)"
				:place="placeStyle(layout, item.index)"
				:more="moreOf(item)"
				:type="item.block.type || ''"
				@navigate="$emit('navigate', $event)">
				<!-- Tabs over a list (mijn-lists-follow-the-boards). -->
				<ListTabs
					v-if="
						item.kind === 'table'
						&& hasTabs(item)
						&& item.block.display !== 'chips'
					"
					:tabs="item.block.tabs"
					:chosen="tabOf(item)"
					:label="headingOf(item)"
					@choose="tabs = { ...tabs, [item.index]: $event }" />
				<RichTextBlock
					v-if="item.kind === 'richText'"
					:markdown="item.block.markdown || ''" />

				<!-- A text filled from the open record, as text (REQ-SMO-027). -->
				<div
					v-else-if="item.kind === 'template'"
					class="pq-contribution-page__template"
					data-testid="contribution-page-template">
					<p
						v-for="(sentence, s) in templateOf(item)"
						:key="s"
						class="utrecht-paragraph">
						{{ sentence }}
					</p>
				</div>

				<!-- Quick tiles: cta blocks that open a page or a route (REQ-SMO-024). -->
				<QuickTiles
					v-else-if="item.kind === 'tiles'"
					:tiles="tilesOf(item)"
					@open="openTile" />

				<!-- The overview's opening (site-school-blocks). -->
				<GreetingBlock
					v-else-if="item.kind === 'greeting'"
					:block="item.block"
					:session="session"
					:route="
						item.block.page ? tileTarget(item.block).route || '' : ''
					"
					:pageHeading="homeHeading && item.index === firstGreetingIndex"
					:t="tr"
					:locale="lang"
					@navigate="$emit('navigate', $event)">
					<template v-if="item.action" #action>
						<SlotHost
							name="action"
							:block="{
								type: 'cta',
								action: item.block.action,
								label: item.block.label,
							}"
							:action="item.action"
							:contribution="currentContribution"
							:api="api"
							:t="tr"
							:locale="lang"
							@created="
								(object, written) =>
									afterWrite(written || item.action)
							" />
					</template>
				</GreetingBlock>

				<!-- Rows as cards with a progress figure (REQ-SMO-028), and the
			     status, note and coming-up parts (site-school-blocks). -->
				<ProgressCards
					v-else-if="
						item.kind === 'table' && item.block.display === 'cards'
					"
					:rows="tableWindow(item).rows"
					:block="item.block"
					:collection="item.collection"
					:titleFields="item.collection.titleFields || []"
					:statusRows="
						item.block.status
							? loadedOf({ id: item.block.status.collection })
							: null
					"
					:today="today || undefined"
					:rowPageRoute="rowPageRouteOf(item)"
					:t="tr"
					:locale="lang"
					@navigate="$emit('navigate', $event)" />

				<!-- Dated rows, bars and mark chips (site-school-blocks). -->
				<component
					:is="displayComponent(item.block.display)"
					v-else-if="
						item.kind === 'table' && displayComponent(item.block.display)
					"
					:rows="tableWindow(item).rows"
					:block="item.block"
					:collection="item.collection"
					:label="showsHeading(item) ? headingOf(item) : ''"
					:level="sectionLevel"
					:loading="loadedOf(item.collection).loading"
					:rowPageRoute="rowPageRouteOf(item)"
					:today="today || undefined"
					:t="tr"
					:locale="lang"
					@navigate="$emit('navigate', $event)">
					<!-- Grouped marks take their tabs under the summary, as the
					     board draws them (mijn-lists-follow-the-boards). -->
					<template
						v-if="hasTabs(item) && item.block.display === 'chips'"
						#tabs>
						<ListTabs
							:tabs="item.block.tabs"
							:chosen="tabOf(item)"
							:label="headingOf(item)"
							@choose="tabs = { ...tabs, [item.index]: $event }" />
					</template>
				</component>

				<div
					v-else-if="item.kind === 'table'"
					class="pq-contribution-page__collection"
					:data-collection="item.collection.id"
					data-testid="contribution-page-collection">
					<!-- One heading per title: a collection named like the page it
				     is on is already titled by the shell's h1, whose id it then
				     takes as its label. -->
					<component
						:is="`h${sectionLevel}`"
						v-if="showsHeading(item)"
						:id="headingId(item)"
						class="utrecht-heading-3">
						{{ headingOf(item) }}
					</component>
					<!-- A collection that declares groupByField shows one table per
				     child, each named by its own heading
				     (collection-group-by-field). -->
					<template
						v-for="group in groupsOf(item)"
						:key="group.value || '_rest'">
						<h3
							:id="groupHeadingId(item, group)"
							class="utrecht-heading-4 pq-contribution-page__group"
							data-testid="contribution-page-group">
							{{ group.label || tr('Other') }}
						</h3>
						<CollectionTable
							:collection="item.collection"
							:objects="group.rows"
							:loading="loadedOf(item.collection).loading"
							:selectable="true"
							:selectedRow="selected[item.collection.id] || null"
							:rowActions="item.tableActions"
							:offers="offers"
							:busyRow="busyRow"
							:labelledby="groupHeadingId(item, group)"
							:t="tr"
							:locale="lang"
							@select="select(item.collection, $event)"
							@rowAction="
								(action, row) => onRowAction(item, action, row)
							" />
					</template>
					<CollectionTable
						v-if="groupsOf(item).length === 0"
						:collection="item.collection"
						:objects="tableWindow(item).rows"
						:loading="loadedOf(item.collection).loading"
						:selectable="true"
						:selectedRow="selected[item.collection.id] || null"
						:rowActions="item.tableActions"
						:offers="offers"
						:busyRow="busyRow"
						:labelledby="labelOf(item)"
						:t="tr"
						:locale="lang"
						@select="select(item.collection, $event)"
						@rowAction="
							(action, row) => onRowAction(item, action, row)
						" />
					<!-- A block that shows its first rows (`limit`) leads to all of
				     them: the collection's own page, else the rest here
				     (site-mijn-omgeving-components REQ-SMO-021). -->
					<p
						v-if="groupsOf(item).length === 0 && tableWindow(item).more"
						class="utrecht-paragraph pq-contribution-page__more"
						data-testid="contribution-page-more">
						<a
							v-if="allRouteOf(item)"
							class="utrecht-link"
							:href="hrefOf(allRouteOf(item))"
							@click.prevent="$emit('navigate', allRouteOf(item))"
							>{{ seeAll(item) }}</a
						>
						<button
							v-else
							type="button"
							class="utrecht-button utrecht-button--subtle"
							@click="expanded = { ...expanded, [item.index]: true }">
							{{ seeAll(item) }}
						</button>
					</p>
					<!-- Sign and decline get their own step, every other endpoint
				     row action the plain confirm step: slice c fills it. -->
					<SlotHost
						v-if="pending && pending.collectionId === item.collection.id"
						:key="pendingKey"
						name="rowAction"
						:dialog="pending.dialog"
						:action="pending.action"
						:viewAction="item.viewAction"
						:collection="item.collection"
						:row="pending.row"
						:api="api"
						:t="tr"
						:locale="lang"
						@done="afterWrite(item.collection)"
						@close="pending = null" />
				</div>

				<DetailCard
					v-else-if="item.kind === 'detail'"
					:collection="item.collection"
					:row="detailRow(item)"
					:quietWhenEmpty="item.quietWhenEmpty === true"
					:api="api"
					:proposeAction="item.proposeAction"
					:label="item.block.label || ''"
					:level="sectionLevel"
					:showTimeline="item.block.timeline !== false"
					:t="tr"
					:locale="lang" />

				<!-- One figure as a segmented bar (site-school-blocks). -->
				<SegmentedFigure
					v-else-if="
						item.kind === 'kpi' && item.block.display === 'segmented'
					"
					:row="kpiRow(item)"
					:block="item.block"
					:label="item.block.label || ''"
					:level="sectionLevel"
					:loading="loadedOf(item.collection).loading"
					:t="tr"
					:locale="lang" />

				<KpiCards
					v-else-if="item.kind === 'kpi'"
					:cards="item.block.cards || []"
					:row="kpiRow(item)"
					:loading="loadedOf(item.collection).loading"
					:label="item.block.label || ''"
					:caption="item.block.caption || null"
					:display="item.block.display || ''"
					:level="sectionLevel"
					:t="tr"
					:locale="lang" />

				<!-- One day as a timetable (calendar-timetable-display). -->
				<TimetableDay
					v-else-if="
						item.kind === 'calendar'
						&& item.block.display === 'timetable'
					"
					:items="calendarOf(item)"
					:range="item.block.range || 'day'"
					:firstLabel="item.block.firstLabel || ''"
					:loading="calendarLoading(item)"
					:label="item.block.label || ''"
					:level="sectionLevel"
					:today="today || undefined"
					:t="tr"
					:locale="lang" />

				<!-- The same items as date tiles (site-school-blocks). -->
				<CalendarTiles
					v-else-if="
						item.kind === 'calendar' && item.block.display === 'tiles'
					"
					:items="calendarOf(item)"
					:range="item.block.range || ''"
					:loading="calendarLoading(item)"
					:label="item.block.label || ''"
					:level="sectionLevel"
					:today="today || undefined"
					:t="tr"
					:locale="lang" />

				<CalendarBlock
					v-else-if="item.kind === 'calendar'"
					:items="calendarOf(item)"
					:loading="calendarLoading(item)"
					:label="item.block.label || ''"
					:level="sectionLevel"
					:today="today || undefined"
					:t="tr"
					:locale="lang" />

				<NewsBlock
					v-else-if="item.kind === 'news'"
					:api="api"
					:limit="item.block.limit || 3"
					:label="item.block.label || ''"
					:level="sectionLevel"
					:record="activeRecord"
					:contribution="currentContribution"
					:groups="openRecordGroups"
					:initialFeed="initialFeed"
					:t="tr"
					:locale="lang"
					@navigate="$emit('navigate', $event)" />

				<!-- What the resident still has to do and their newest messages
			     (site-mijn-omgeving-components), loaded on demand. -->
				<TasksBlock
					v-else-if="item.kind === 'tasks'"
					:block="item.block"
					:collection="item.collection"
					:rows="rowsOf(item)"
					:loading="loadedOf(item.collection).loading"
					:failed="loadedOf(item.collection).failed === true"
					:app="currentContribution ? currentContribution.app || '' : ''"
					:nav="nav"
					:level="sectionLevel"
					:t="tr"
					:locale="lang"
					:today="today || undefined"
					@navigate="$emit('navigate', $event)"
					@retry="reload(item.collection)" />

				<CasesBlock
					v-else-if="item.kind === 'cases'"
					:block="item.block"
					:collection="item.collection"
					:rows="rowsOf(item)"
					:loading="loadedOf(item.collection).loading"
					:failed="loadedOf(item.collection).failed === true"
					:api="api"
					:app="currentContribution ? currentContribution.app || '' : ''"
					:nav="nav"
					:level="sectionLevel"
					:t="tr"
					:locale="lang"
					:today="today || undefined"
					@navigate="$emit('navigate', $event)"
					@retry="reload(item.collection)" />

				<StepsBlock
					v-else-if="item.kind === 'steps'"
					:block="item.block"
					:collection="item.collection"
					:record="activeRecord"
					:api="api"
					:level="sectionLevel"
					:route="
						item.block.page ? tileTarget(item.block).route || '' : ''
					"
					:t="tr"
					:locale="lang"
					:today="today || undefined"
					@navigate="$emit('navigate', $event)" />

				<DocumentsBlock
					v-else-if="item.kind === 'documents'"
					:block="item.block"
					:collection="item.collection"
					:record="activeRecord"
					:api="api"
					:level="sectionLevel"
					:t="tr"
					:locale="lang" />

				<TimelineBlock
					v-else-if="item.kind === 'timeline'"
					:block="item.block"
					:collection="item.collection"
					:record="activeRecord"
					:api="api"
					:level="sectionLevel"
					:t="tr"
					:locale="lang" />

				<InboxBlock
					v-else-if="item.kind === 'inbox'"
					:block="item.block"
					:record="activeRecord"
					:api="api"
					:app="currentContribution ? currentContribution.app || '' : ''"
					:nav="nav"
					:level="sectionLevel"
					:t="tr"
					:locale="lang"
					:today="today || undefined"
					@navigate="$emit('navigate', $event)" />

				<SlotHost
					v-else-if="item.kind === 'citizenCase'"
					name="citizenCase"
					:block="item.block"
					:collection="item.collection"
					:quietWhenEmpty="item.quietWhenEmpty === true"
					:row="selected[item.collection.id] || null"
					:api="api"
					:t="tr"
					:locale="lang" />

				<SlotHost
					v-else-if="item.kind === 'timedTask'"
					name="timedTask"
					:block="item.block"
					:collection="item.collection"
					:app="currentContribution ? currentContribution.app || '' : ''"
					:attempts="loadedOf(item.collection).objects"
					:api="api"
					:t="tr"
					:locale="lang"
					@changed="afterWrite(item.collection)" />

				<SlotHost
					v-else-if="item.kind === 'action' || item.kind === 'cta'"
					name="action"
					:block="withTitle(item.block)"
					:action="item.action"
					:contribution="currentContribution"
					:api="api"
					:t="tr"
					:locale="lang"
					@created="
						(object, written) => afterWrite(written || item.action)
					" />
			</BlockShell>
		</BlockShell>
	</section>
</template>

<script>
import { defineAsyncComponent } from 'vue'
import CalendarBlock from '../../components/collections/CalendarBlock.vue'
import CollectionTable from '../../components/collections/CollectionTable.vue'
import DetailCard from '../../components/collections/DetailCard.vue'
import KpiCards from '../../components/collections/KpiCards.vue'
import NewsBlock from '../../components/collections/NewsBlock.vue'
import RichTextBlock from '../../components/collections/RichTextBlock.vue'
import SlotHost from '../../components/collections/SlotHost.vue'
import BlockShell from './BlockShell.vue'
import { asksInput } from '../../../shared/actionInput.js'
import {
	anyGrouped,
	groupFieldOf,
	groupLabelCollection,
	groupRows,
} from '../../../shared/collectionGroups.js'
import {
	itemsInRange,
	listOrder,
	skipRows,
	sortRows,
	windowRows,
} from '../../../shared/listWindow.js'
import {
	consumeOpenTarget,
	forgetOpenTarget,
	navKeyFor,
} from '../../../shared/openRecord.js'
import { routeForNav } from '../../../shared/portalNav.js'
import {
	allGroups,
	calendarItems,
	narrowToRecord,
	pickRow,
	recordGroups,
	recordTitle,
	withLookups,
} from '../../../shared/recordPage.js'
import { isEndpointRowAction, offersRowAction } from '../../../shared/rowAction.js'
import { dialogFor } from '../../../shared/signing.js'
import { rowIdOf } from '../../components/collections/cells.js'
import { blocks as mijnBlocks } from '../../components/mijn/index.js'
import { rowsForTab } from '../../components/mijn/lists.js'
import { mijnTranslator, siteHref } from '../../components/mijn/rows.js'
import { ctaLabel, fillTemplate } from '../../components/mijn/template.js'
import { keepRecordToOpen, sessionStore as tabStore } from '../inbox/inbox.js'
import { createCollectionLoader, openRecordState } from './collectionLoader.js'
import { resolveBlocks } from './pageBlocks.js'
import { blockClasses, blockPlaces, placeStyle } from './pageLayout.js'
import { collectionsTranslator, pageLocale } from './translate.js'

/**
 * Where a record link is kept across the sign-in.
 *
 * @return {Storage|null}
 */
function sessionStore() {
	try {
		return typeof window !== 'undefined' ? window.sessionStorage : null
	} catch {
		return null
	}
}

/**
 * One contribution page (slice b, b1): its ordered blocks, rendered from the
 * contribution the page belongs to. The site's counterpart of the React
 * portal's PageView.jsx, registered under the `contribution` key.
 *
 * Tables, detail cards, item lists, timelines and rich text are built here.
 * Forms and buttons (`action`, `cta`), endpoint row action steps, change
 * proposals and attached actions (slice c), timed tasks (slice d) and the
 * resident's case (slice e) go to their named place (SlotHost, see
 * blockSlots.js), so this page never builds another slice's screen.
 *
 * On mount the page loads every collection its blocks read. After a write it
 * loads every collection on that register and schema again and emits
 * `unread` with the fresh count.
 *
 * A record link (`#open=<app>/<collection>/<id>`, kept across the sign-in)
 * that names a collection on this page selects that row, from the resident's
 * own scoped rows only. A record not in that list opens nothing and says so.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-contribution-page-must-render-its-blocks-req-srp-014
 */
export default {
	name: 'ContributionPage',

	components: {
		BlockShell,
		CalendarBlock,
		CollectionTable,
		DetailCard,
		KpiCards,
		NewsBlock,
		RichTextBlock,
		SlotHost,
		// Each loads with its action rows and Den Haag CSS only when a page
		// holds one (site-mijn-omgeving-components design D1).
		TasksBlock: defineAsyncComponent(mijnBlocks.tasks),
		InboxBlock: defineAsyncComponent(mijnBlocks.inbox),
		CasesBlock: defineAsyncComponent(mijnBlocks.cases),
		StepsBlock: defineAsyncComponent(mijnBlocks.steps),
		RecordSwitcher: defineAsyncComponent(mijnBlocks.recordSwitcher),
		QuickTiles: defineAsyncComponent(mijnBlocks.quickTiles),
		ProgressCards: defineAsyncComponent(mijnBlocks.progressCards),
		DocumentsBlock: defineAsyncComponent(mijnBlocks.documents),
		TimelineBlock: defineAsyncComponent(mijnBlocks.timeline),
		// site-school-blocks, each on demand as well.
		DateRows: defineAsyncComponent(mijnBlocks.dateRows),
		GradeBars: defineAsyncComponent(mijnBlocks.bars),
		MarkChips: defineAsyncComponent(mijnBlocks.chips),
		SegmentedFigure: defineAsyncComponent(mijnBlocks.segments),
		GreetingBlock: defineAsyncComponent(mijnBlocks.greeting),
		CalendarTiles: defineAsyncComponent(mijnBlocks.calendarTiles),
		TimetableDay: defineAsyncComponent(mijnBlocks.timetable),
		// mijn-lists-follow-the-boards
		ListTabs: defineAsyncComponent(mijnBlocks.listTabs),
	},

	// The shell hands every page the whole contract (session, portal, nav, …);
	// this page reads none of those, and they must not land on the DOM.
	inheritAttrs: false,

	props: {
		/** The session, for a greeting's first name (site-school-blocks). */
		session: { type: Object, default: null },
		/** Whether the page's first greeting is the screen's heading (/mijn). */
		homeHeading: { type: Boolean, default: false },
		/** The navigation entry: `key`, `label`, `page`, `contribution`. */
		entry: { type: Object, default: null },
		/** The page, when no entry carries it. */
		page: { type: Object, default: null },
		/** The contribution, when no entry carries it. */
		contribution: { type: Object, default: null },
		/** The shared portal api, bound to the resident's bearer. */
		api: { type: Object, default: null },
		/** The contributions aggregate, or its list. */
		contributions: { type: [Object, Array], default: null },
		/** The site translator. */
		t: { type: Function, default: null },
		/** The language. */
		locale: { type: String, default: '' },
		/** A record link to open, when the shell already read it. */
		openRecord: { type: Object, default: null },
		/** Rows to start from, by collection id, for a server render or a test. */
		initialData: { type: Object, default: null },
		/** Rows selected from the start, by collection id. */
		initialSelected: { type: Object, default: null },
		/** A news feed to start from, for a server render or a test. */
		initialFeed: { type: Array, default: null },
		/** Today, for the calendar; a test passes a fixed day. */
		today: { type: Date, default: null },
		/** Every navigation entry, so a task or message row finds its page. */
		nav: { type: Array, default: () => [] },
		/** The record the route chooses (`/mijn/<app>/<page>/<id>`), or ''. */
		routeRecordId: { type: String, default: '' },
	},

	emits: ['navigate', 'unread', 'refresh', 'recordOpened'],

	data() {
		return {
			store: { ...(this.initialData || {}) },
			selected: { ...(this.initialSelected || {}) },
			pending: null,
			busyRow: null,
			recordNotFound: false,
			/** The chosen tab of each list block, by its index. */
			tabs: {},
			target: this.openRecord,
			// The limited tables a resident asked to see whole, by block index.
			expanded: {},
			loader: null,
		}
	},

	computed: {
		/**
		 * The index of the page's first greeting block, -1 without one.
		 *
		 * @return {number}
		 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-greeting-block-opens-the-overview
		 */
		firstGreetingIndex() {
			const first = this.blocks.find((item) => item.kind === 'greeting')
			return first ? first.index : -1
		},

		currentPage() {
			return this.entry?.page || this.page || null
		},

		currentContribution() {
			return this.entry?.contribution || this.contribution || null
		},

		lang() {
			return pageLocale(this.locale)
		},

		tr() {
			return collectionsTranslator(this.t, this.lang)
		},

		blocks() {
			return resolveBlocks(this.currentPage, this.currentContribution)
		},

		/**
		 * The page's record declaration, or null for an ordinary page.
		 *
		 * @return {object|null}
		 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-a-page-may-be-the-record-page-of-a-collection
		 */
		recordPage() {
			return this.currentPage?.record || this.currentPage?.records || null
		},

		/**
		 * Whether the page switches between records (`records`): a record is
		 * always open, the first by default, and a switcher picks another.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-the-resident-must-see-and-switch-for-whom-they-act-req-smo-008
		 */
		switching() {
			return Boolean(this.currentPage?.records)
		},

		/**
		 * @return {string} The switcher's name for a screen reader.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-the-resident-must-see-and-switch-for-whom-they-act-req-smo-008
		 */
		/**
		 * The switcher's subtitle per record, from one related record
		 * (`records.subtitleLookup`, REQ-SMO-026): the child's group.
		 *
		 * @return {Record<string, string>}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-the-record-switcher-may-take-its-subtitle-from-a-related-record-req-smo-026
		 */
		switcherSubtitles() {
			const lookup = this.currentPage?.records?.subtitleLookup
			if (!lookup) {
				return {}
			}
			const rows = this.store[lookup.collection]?.objects || []
			const out = {}
			for (const record of this.recordRows) {
				const id = rowIdOf(record)
				const match = rows.find(
					(row) => String(row?.[lookup.matchField] ?? '') === id,
				)
				const value = match?.[lookup.valueField]
				if (id && typeof value === 'string' && value.trim() !== '') {
					out[id] = value.trim()
				}
			}
			return out
		},

		switcherLegend() {
			return mijnTranslator(this.t, this.lang)('Choose for whom')
		},

		recordRows() {
			return this.recordPage
				? this.store[this.recordPage.collection]?.objects || []
				: []
		},

		/**
		 * The open record: the row picked from the subject's own list, or the
		 * only row there is. A row outside that list never opens.
		 *
		 * @return {object|null}
		 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-a-page-may-be-the-record-page-of-a-collection
		 */
		activeRecord() {
			if (!this.recordPage) {
				return null
			}
			const rows = this.recordRows
			const picked = this.selected[this.recordPage.collection]
			const id = rowIdOf(picked)
			if (id) {
				return rows.find((row) => rowIdOf(row) === id) || null
			}
			const loading = this.store[this.recordPage.collection]?.loading
			if (this.switching && !loading && !this.target) {
				// A switching page opens on its first record.
				return rows[0] || null
			}
			return rows.length === 1 && !loading ? rows[0] : null
		},

		recordId() {
			return rowIdOf(this.activeRecord) || ''
		},

		recordName() {
			return recordTitle(this.activeRecord, this.recordPage?.titleFields)
		},

		recordHeadingId() {
			return `pq-record-${this.currentPage?.id || 'page'}`
		},

		/**
		 * Whether the open record's name is the page's h1 (the record
		 * declares `heading: record`).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-contribution-may-declare-the-board-displays
		 */
		recordHeads() {
			return this.recordPage?.heading === 'record'
		},

		/**
		 * The eyebrow over the record's name: the page label and the record's
		 * reference ("Uw zaak · 2026-0082").
		 *
		 * @return {string}
		 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-contribution-may-declare-the-board-displays
		 */
		eyebrow() {
			const record = this.activeRecord || {}
			const reference = ['identifier', 'reference']
				.map((field) => record[field])
				.find((value) => typeof value === 'string' && value.trim() !== '')
			return [this.entry?.label || this.currentPage?.label, reference]
				.filter(Boolean)
				.join(' · ')
		},

		backLabel() {
			const list = this.currentContribution?.collections?.find(
				(c) => c && c.id === this.recordPage?.collection,
			)
			return list?.label || this.entry?.label || ''
		},

		/**
		 * The blocks on screen: on a record page the list until a record is
		 * open, then every other block.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-a-page-may-be-the-record-page-of-a-collection
		 */
		/**
		 * Where each visible block stands (mijn-overview-follows-the-boards).
		 *
		 * @return {{columns: boolean, places: object}}
		 *
		 * @spec openspec/changes/mijn-overview-follows-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-block-may-stand-in-a-column-and-in-a-frame
		 */
		layout() {
			return blockPlaces(this.visibleBlocks)
		},

		visibleBlocks() {
			const record = this.recordPage
			if (!record) {
				return this.blocks
			}
			const isList = (item) =>
				item.kind === 'table' && item.collection?.id === record.collection
			if (!this.activeRecord) {
				return this.blocks.filter(
					(item) => isList(item) || item.kind === 'richText',
				)
			}
			return this.blocks.filter((item) => !isList(item))
		},

		openRecordGroups() {
			return recordGroups(
				this.currentContribution,
				this.store,
				this.activeRecord,
			)
		},

		/**
		 * The groups a group-bound row must be for: the open record's, else
		 * every child's.
		 *
		 * @return {Array<string>}
		 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-a-block-on-a-record-page-may-narrow-its-rows-to-the-open-record
		 */
		scopeGroups() {
			return this.activeRecord
				? this.openRecordGroups
				: allGroups(this.currentContribution, this.store)
		},

		/** Section headings sit one level below an open record's name. */
		sectionLevel() {
			return this.activeRecord ? 3 : 2
		},

		allContributions() {
			const list = Array.isArray(this.contributions)
				? this.contributions
				: this.contributions?.contributions
			return Array.isArray(list) && list.length > 0
				? list
				: [this.currentContribution].filter(Boolean)
		},

		pendingKey() {
			return this.pending
				? `${this.pending.action.id}:${rowIdOf(this.pending.row) || ''}`
				: ''
		},

		/**
		 * The record link to open on this page: one whose collection a block
		 * here reads, or the page's own record collection, so a route that
		 * chooses a record opens it.
		 *
		 * @return {object|null}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-the-resident-must-see-and-switch-for-whom-they-act-req-smo-008
		 */
		targetOnPage() {
			const target = this.target
			if (!target || target.app !== this.currentContribution?.app) {
				return null
			}
			return this.blocks.some(
				(item) =>
					item.collection && item.collection.id === target.collection,
			) || this.recordPage?.collection === target.collection
				? target
				: null
		},

		targetState() {
			const target = this.targetOnPage
			return target
				? openRecordState(this.store[target.collection], target)
				: { state: 'waiting' }
		},
	},

	watch: {
		targetState(next) {
			this.applyTarget(next)
		},

		currentPage() {
			this.selected = {}
			this.pending = null
			this.loadPage()
		},
	},

	/**
	 * Load the page's collections, and take the record to open from the
	 * route, else from a record link.
	 *
	 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-the-resident-must-see-and-switch-for-whom-they-act-req-smo-008
	 */
	mounted() {
		this.loader = createCollectionLoader({ api: this.api, store: this.store })
		if (!this.target && this.routeRecordId && this.recordPage) {
			// The route chooses the record (`/mijn/<app>/<page>/<id>`).
			this.target = {
				app: this.currentContribution?.app || '',
				collection: this.recordPage.collection,
				id: this.routeRecordId,
			}
		}
		if (!this.target) {
			this.target = consumeOpenTarget(
				window.location,
				window.history,
				sessionStore(),
			)
		}
		this.loadPage()
	},

	methods: {
		/**
		 * The component a collection block's display draws with, or null for
		 * the table (site-school-blocks).
		 *
		 * @param {string} display The block's display.
		 * @return {string|null} The component name.
		 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-collection-block-may-draw-its-rows-as-dated-rows-bars-chips-or-richer-cards
		 */
		displayComponent(display) {
			return (
				{ rows: 'DateRows', bars: 'GradeBars', chips: 'MarkChips' }[display]
				|| null
			)
		},

		/**
		 * Load every collection the page's blocks read, and the children's
		 * names when a table groups.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/collection-group-by-field/tasks.md#T3
		 */
		loadPage() {
			if (this.loader && this.currentPage) {
				this.loader.loadPage(this.currentPage, this.currentContribution)
				this.loadGroupLabels()
				this.loadRecordGroups()
			}
		},

		/**
		 * Load the rows that name the groups (the guardian's children), when
		 * a table on this page groups its rows.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/collection-group-by-field/tasks.md#T3
		 */
		loadGroupLabels() {
			const source = groupLabelCollection(this.currentContribution)
			const grouped = anyGrouped(
				this.blocks
					.filter((item) => item.kind === 'table')
					.map((item) => item.collection),
			)
			if (source && grouped && !this.store[source.id]) {
				this.loader.load(source)
			}
		},

		/**
		 * The groups of the guardian's children, when a news block on a record
		 * page narrows the news to one child's groups.
		 *
		 * @return {void}
		 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-a-news-block-must-show-the-subjects-latest-news
		 */
		loadRecordGroups() {
			const groups = this.currentContribution?.guardianAudience?.groups
			const source = (this.currentContribution?.collections || []).find(
				(c) => c && c.id === groups?.collection,
			)
			const groupBound = (scope) => Boolean(scope && scope.recordGroupsField)
			const wanted =
				(this.recordPage && this.blocks.some((item) => item.kind === 'news'))
				|| this.blocks.some(
					(item) =>
						groupBound(item.block)
						|| (item.block?.sources || []).some(groupBound),
				)
			if (source && wanted && !this.store[source.id]) {
				this.loader.load(source)
			}
		},

		/**
		 * A block's rows, narrowed to the open record when it names a record field.
		 *
		 * @param {object} item The page block.
		 * @return {Array<object>}
		 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-a-block-on-a-record-page-may-narrow-its-rows-to-the-open-record
		 */
		rowsOf(item) {
			const rows = narrowToRecord(
				this.loadedOf(item.collection).objects,
				item.block,
				this.activeRecord,
				this.scopeGroups,
			)
			return withLookups(
				rows,
				item.block?.lookups,
				this.store,
				this.activeRecord,
			)
		},

		/**
		 * The row a kpi block reads.
		 *
		 * @param {object} item The kpi block.
		 * @return {object|null}
		 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-a-kpi-block-must-show-figure-cards-from-one-row
		 */
		kpiRow(item) {
			return pickRow(this.rowsOf(item), item.block.pick)
		},

		/**
		 * A calendar block's items.
		 *
		 * @param {object} item The calendar block.
		 * @return {Array<object>}
		 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-a-calendar-block-must-show-dated-rows-as-a-list-and-a-month
		 */
		calendarOf(item) {
			// Only today, this week or this month when the block says so
			// (site-mijn-omgeving-components REQ-SMO-021).
			return itemsInRange(
				calendarItems(
					item.block,
					this.store,
					this.activeRecord,
					this.scopeGroups,
				),
				item.block.range,
				this.today || new Date(),
			)
		},

		/**
		 * The sentences of a template block, filled from the open record.
		 *
		 * @param {object} item The template block.
		 * @return {Array<string>}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-text-block-on-a-record-page-may-be-filled-from-the-record-req-smo-027
		 */
		templateOf(item) {
			const collection = (this.currentContribution?.collections || []).find(
				(c) => c && c.id === this.recordPage?.collection,
			)
			return fillTemplate(item.block.template, this.activeRecord, {
				whenEmpty: item.block.whenEmpty || {},
				fields: Array.isArray(collection?.fields) ? collection.fields : null,
				locale: this.lang,
			})
		},

		/**
		 * A cta block with `{title}` in its label filled with the open
		 * record's title.
		 *
		 * @param {object} block The block.
		 * @return {object}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-cta-block-may-open-a-page-or-a-site-route-for-the-open-record-with-the-record-in-its-label-req-smo-024
		 */
		withTitle(block) {
			if (block?.type !== 'cta') {
				return block
			}
			// A cta with `withRecord` on a record page carries the open record,
			// for an action that creates something about it
			// (case-actions-on-the-case-page).
			const withRecord =
				block.withRecord === true && this.recordId
					? { ...block, record: String(this.recordId) }
					: block
			if (!String(block.label || '').includes('{title}')) {
				return withRecord
			}
			return { ...withRecord, label: ctaLabel(block.label, this.recordName) }
		},

		/**
		 * The tiles of a run of page and route ctas: label and route.
		 *
		 * @param {object} item The tiles item.
		 * @return {Array<{key: string, label: string, route: string, block: object}>}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-cta-block-may-open-a-page-or-a-site-route-for-the-open-record-with-the-record-in-its-label-req-smo-024
		 */
		tilesOf(item) {
			return item.tiles
				.map((block, i) => {
					const target = this.tileTarget(block)
					return {
						key: `${item.index}:${i}`,
						label: ctaLabel(block.label, this.recordName),
						route: target.route,
						carriesRecord: target.carriesRecord,
						block,
					}
				})
				.filter((tile) => tile.route !== '')
		},

		/**
		 * Where a tile goes: the page's route, with the open record chosen
		 * when the tile says `withRecord` and the page is a record page; else
		 * the declared route.
		 *
		 * `carriesRecord` says whether the record is IN the route. It decides
		 * whether the open record is also kept in storage on the way out: a
		 * route that already names the record must not be, because the shell
		 * reads a kept record BACK (`followAccountRoute` → `openRecordEntry`)
		 * and replaces the route with the page that LISTS the collection,
		 * which on a family page is the page the tile sits on. Measured on
		 * :8090: the tile's href was right and the address never moved.
		 *
		 * @param {object} block The cta block.
		 * @return {{route: string, carriesRecord: boolean}} The target, route
		 *         '' when the page is not offered.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-cta-block-may-open-a-page-or-a-site-route-for-the-open-record-with-the-record-in-its-label-req-smo-024
		 */
		/**
		 * Whether a block gets a box of its own: on a page with columns, or
		 * when it declares a frame or a link (mijn-overview-follows-the-boards).
		 *
		 * @param {object} item A visible block.
		 * @return {boolean}
		 *
		 * @spec openspec/changes/mijn-overview-follows-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-block-may-stand-in-a-column-and-in-a-frame
		 */
		shellWraps(item) {
			return (
				this.layout.columns
				|| Boolean(item.block?.frame)
				|| Boolean(this.moreOf(item))
			)
		},

		/**
		 * @param {object} block A block.
		 * @return {Array<string>} Its wrapper classes (pageLayout.js).
		 * @spec openspec/changes/mijn-overview-follows-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-block-may-stand-in-a-column-and-in-a-frame
		 */
		blockClasses(block) {
			return blockClasses(block)
		},

		/**
		 * @param {object} layout From blockPlaces().
		 * @param {number} index The block's index.
		 * @return {object|undefined} Its place in the grid (pageLayout.js).
		 * @spec openspec/changes/mijn-overview-follows-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-block-may-stand-in-a-column-and-in-a-frame
		 */
		placeStyle(layout, index) {
			return placeStyle(layout, index)
		},

		/**
		 * A block's "Alle ..." link: its label, the route of the page or
		 * site route it names, and that route's address; null when the block
		 * declares none or its page is not offered.
		 *
		 * @param {object} item A visible block.
		 * @return {{label: string, route: string, href: string}|null}
		 *
		 * @spec openspec/changes/mijn-overview-follows-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-block-may-carry-a-link-to-all-of-it
		 */
		moreOf(item) {
			const more = item.block?.more
			if (!more || !more.label) {
				return null
			}
			const { route } = this.tileTarget({
				route: more.route,
				page: more.page,
			})
			return route
				? { label: more.label, route, href: this.hrefOf(route) }
				: null
		},

		tileTarget(block) {
			if (block.route) {
				return { route: block.route, carriesRecord: false }
			}
			const app = this.currentContribution?.app || ''
			const entry = (this.nav || []).find(
				(candidate) =>
					candidate.contribution?.app === app
					&& candidate.page?.id === block.page,
			)
			if (!entry) {
				return { route: '', carriesRecord: false }
			}
			const route = routeForNav(entry)
			const recordPage = entry.page.record || entry.page.records
			if (
				block.withRecord
				&& this.recordId
				&& recordPage
				&& recordPage.collection === this.recordPage?.collection
			) {
				return {
					route: `${route}/${encodeURIComponent(this.recordId)}`,
					carriesRecord: true,
				}
			}
			return { route, carriesRecord: false }
		},

		/**
		 * Where a tile goes. Kept for readers (and tests) that want the route
		 * alone.
		 *
		 * @param {object} block The cta block.
		 * @return {string} The route, or '' when the page is not offered.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-cta-block-may-open-a-page-or-a-site-route-for-the-open-record-with-the-record-in-its-label-req-smo-024
		 */
		tileRoute(block) {
			return this.tileTarget(block).route
		},

		/**
		 * Open a tile; with `withRecord` the open record is kept for the page
		 * that shows its collection, as a record link would.
		 *
		 * @param {object} tile The tile.
		 * @return {void}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-cta-block-may-open-a-page-or-a-site-route-for-the-open-record-with-the-record-in-its-label-req-smo-024
		 */
		openTile(tile) {
			if (
				tile.block.withRecord
				&& tile.carriesRecord !== true
				&& this.recordId
				&& this.recordPage
			) {
				// Only when the route cannot name the record itself: the page
				// shows the collection as a list, so it has to be told which
				// record to open. A route that names it must not be kept as
				// well, or the shell reads the kept record back and sends the
				// resident to the listing page instead (see tileTarget()).
				keepRecordToOpen(tabStore(), {
					app: this.currentContribution?.app || '',
					collection: this.recordPage.collection,
					id: this.recordId,
				})
			}
			this.$emit('navigate', tile.route)
		},

		/**
		 * The rows a table shows: in its declared order, at most its limit
		 * until the resident asks for the rest.
		 *
		 * @param {object} item The table block.
		 * @return {{rows: Array<object>, more: boolean}}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021
		 */
		tableWindow(item) {
			const sort = listOrder(item.block, item.collection)
			// The rows of the chosen tab (mijn-lists-follow-the-boards).
			const rows = this.hasTabs(item)
				? rowsForTab(this.rowsOf(item), item.block.tabs[this.tabOf(item)])
				: this.rowsOf(item)
			if (this.expanded[item.index]) {
				return {
					// Without the rows a highlight already shows (collection-skip).
					rows: skipRows(sortRows(rows, sort), item.block),
					more: false,
				}
			}
			return windowRows(rows, { ...item.block, sort })
		},

		/**
		 * Whether a list block shows tabs (mijn-lists-follow-the-boards).
		 *
		 * @param {object} item The page block.
		 * @return {boolean}
		 *
		 * @spec openspec/changes/mijn-lists-follow-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-list-may-show-its-rows-under-tabs
		 */
		hasTabs(item) {
			return Array.isArray(item.block?.tabs) && item.block.tabs.length > 1
		},

		/**
		 * @param {object} item The page block.
		 * @return {number} The index of its chosen tab, the first at first.
		 *
		 * @spec openspec/changes/mijn-lists-follow-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-list-may-show-its-rows-under-tabs
		 */
		tabOf(item) {
			return this.tabs[item.index] || 0
		},

		/**
		 * The route of the page a block's rows open (`rowPage`), or ''.
		 *
		 * @param {object} item The page block.
		 * @return {string}
		 *
		 * @spec openspec/changes/mijn-lists-follow-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-row-may-open-its-own-page
		 */
		rowPageRouteOf(item) {
			if (item.block?.rowPage) {
				return this.tileTarget({ page: item.block.rowPage }).route
			}
			// On a record page the list of its records opens each one here
			// ("Open een naam om alles daarover te zien").
			if (
				this.recordPage
				&& this.currentPage
				&& item.collection?.id === this.recordPage.collection
			) {
				return this.tileTarget({ page: this.currentPage.id }).route
			}
			return ''
		},

		/**
		 * The route of the page that shows a collection whole, when that is
		 * another page than this one; '' otherwise.
		 *
		 * @param {object} item The table block.
		 * @return {string}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021
		 */
		allRouteOf(item) {
			const others = (this.nav || []).filter(
				(candidate) => candidate.key !== this.entry?.key,
			)
			const key = navKeyFor(others, {
				app: this.currentContribution?.app || '',
				collection: item.collection.id,
			})
			const entry = others.find((candidate) => candidate.key === key)
			return entry ? routeForNav(entry) : ''
		},

		/**
		 * @param {object} item The table block.
		 * @return {string} "Bekijk alle cijfers".
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021
		 */
		seeAll(item) {
			const label = String(item.collection.label || '')
			return this.tr('See all {label}', {
				label: label.charAt(0).toLocaleLowerCase() + label.slice(1),
			})
		},

		/**
		 * @param {string} route An in-site route.
		 * @return {string} Its real address.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021
		 */
		hrefOf(route) {
			return siteHref(route)
		},

		calendarLoading(item) {
			return (item.block.sources || []).some(
				(source) => this.loadedOf({ id: source.collection }).loading,
			)
		},

		/**
		 * The row a detail block shows: on a record page the open record for
		 * the record collection, else the row picked in that table.
		 *
		 * @param {object} item The detail block.
		 * @return {object|null}
		 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-a-page-may-be-the-record-page-of-a-collection
		 */
		detailRow(item) {
			if (
				this.recordPage
				&& item.collection.id === this.recordPage.collection
			) {
				return this.activeRecord
			}
			return this.selected[item.collection.id] || null
		},

		/**
		 * Back to the list of a record page.
		 *
		 * @return {void}
		 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-a-page-may-be-the-record-page-of-a-collection
		 */
		closeRecord() {
			if (this.recordPage) {
				this.selected = {
					...this.selected,
					[this.recordPage.collection]: null,
				}
			}
		},

		/**
		 * A table block's rows in groups, or [] to render it as one table.
		 *
		 * @param {object} item The page block.
		 * @return {Array<{value: string, label: string, rows: Array<object>}>}
		 *
		 * @spec openspec/changes/collection-group-by-field/tasks.md#T3
		 */
		groupsOf(item) {
			if (this.activeRecord && item.block?.recordField) {
				// One record's rows need no heading per record.
				return []
			}
			const source = groupLabelCollection(this.currentContribution)
			return groupRows(
				sortRows(
					this.loadedOf(item.collection).objects,
					listOrder(item.block, item.collection),
				),
				groupFieldOf(item.collection),
				source ? this.store[source.id]?.objects || [] : [],
			)
		},

		/**
		 * The id of one group's heading, which labels that group's table.
		 *
		 * @param {object} item The page block.
		 * @param {object} group The group.
		 * @return {string}
		 *
		 * @spec openspec/changes/collection-group-by-field/tasks.md#T3
		 */
		groupHeadingId(item, group) {
			return `${this.headingId(item)}-group-${group.value ? group.value.replace(/[^A-Za-z0-9_-]/g, '') : 'rest'}`
		},

		/**
		 * Read one collection again, after its read failed.
		 *
		 * @param {object} collection The collection.
		 * @return {void}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-loading-and-empty-states-must-say-what-is-happening-req-smo-009
		 */
		reload(collection) {
			this.loader?.load(collection)
		},

		loadedOf(collection) {
			return (
				this.store[collection.id] || {
					loading: !this.initialData,
					objects: [],
				}
			)
		},

		headingId(item) {
			return `pq-collection-${item.index}-${item.collection.id}`
		},

		/**
		 * Whether a collection shows its own heading: it has a label, and
		 * the label is not the page title the shell already shows as h1.
		 *
		 * @param {object} item The page block.
		 * @return {boolean}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-contribution-page-must-render-its-blocks-req-srp-014
		 */
		showsHeading(item) {
			const label = this.headingOf(item)
			return label !== '' && label !== (this.entry && this.entry.label)
		},

		/**
		 * A collection block's heading: the block's own `label` ("Laatste
		 * cijfers"), else the collection's (collection-block-label).
		 *
		 * @param {object} item The page item.
		 * @return {string} The heading, or ''.
		 * @spec openspec/changes/collection-block-label/specs/portal-contribution-contract/spec.md#requirement-a-collection-block-keeps-its-own-heading
		 */
		headingOf(item) {
			return String(item.block?.label || item.collection?.label || '')
		},

		/**
		 * The id of the heading that names a collection's table: its own,
		 * else the shell's page title, else none.
		 *
		 * @param {object} item The page block.
		 * @return {string} The id, or ''.
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-contribution-page-must-render-its-blocks-req-srp-014
		 */
		labelOf(item) {
			if (this.showsHeading(item)) {
				return this.headingId(item)
			}
			return item.collection.label ? 'site-account-title' : ''
		},

		offers(action, row) {
			return offersRowAction(action, row)
		},

		/**
		 * Switch to another record: open it, and put it in the route so the
		 * choice survives a reload and a shared link.
		 *
		 * @param {string} id The chosen record's id.
		 * @return {void}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-the-resident-must-see-and-switch-for-whom-they-act-req-smo-008
		 */
		choose(id) {
			const row = this.recordRows.find(
				(candidate) => rowIdOf(candidate) === id,
			)
			if (!row || !this.recordPage) {
				return
			}
			this.select({ id: this.recordPage.collection }, row)
			if (this.entry) {
				this.$emit(
					'navigate',
					`${routeForNav(this.entry)}/${encodeURIComponent(id)}`,
				)
			}
		},

		select(collection, row) {
			this.selected = { ...this.selected, [collection.id]: row }
			if (this.recordPage && collection.id === this.recordPage.collection) {
				// Opening a record moves the focus to its name, so a keyboard
				// or screen reader user lands where the page changed.
				this.$nextTick(() => this.$refs.recordHeading?.focus?.())
			}
		},

		/**
		 * Select the linked record once its collection has loaded, or say it
		 * is not in the resident's list; then forget the link.
		 *
		 * @param {object} state What openRecordState answered.
		 * @return {void}
		 */
		applyTarget(state) {
			const target = this.targetOnPage
			if (!target || state.state === 'waiting') {
				return
			}
			if (state.state === 'found') {
				this.selected = { ...this.selected, [target.collection]: state.row }
			}
			this.recordNotFound = state.state === 'missing'
			forgetOpenTarget(sessionStore())
			this.target = null
			this.$emit('recordOpened', target)
		},

		/**
		 * A row button: an endpoint action opens its step below the table, a
		 * `type: update` action with fields to fill in opens its form there
		 * (site-action-forms), and a transition without fields runs at once
		 * with no field data.
		 *
		 * @param {object} item The resolved table block.
		 * @param {object} action The action.
		 * @param {object} row The row.
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-an-update-row-action-that-needs-input-must-open-its-form-on-the-row
		 */
		async onRowAction(item, action, row) {
			if (action.type === 'update' && asksInput(action)) {
				this.pending = {
					collectionId: item.collection.id,
					action,
					row,
					dialog: 'form',
				}
				return
			}
			if (isEndpointRowAction(action)) {
				this.pending = {
					collectionId: item.collection.id,
					action,
					row,
					dialog: dialogFor(action),
				}
				return
			}
			if (!this.loader) {
				return
			}
			this.busyRow = rowIdOf(row) || null
			try {
				await this.loader.transition(action, row, item.collection)
			} finally {
				this.busyRow = null
			}
		},

		/**
		 * After a write: load every collection on that register and schema
		 * again, and hand the fresh unread count to the shell.
		 *
		 * @param {{register: string, schema: string}} written What was written to.
		 * @return {Promise<void>}
		 */
		async afterWrite(written) {
			if (!this.loader || !written) {
				return
			}
			const unread = await this.loader.afterWrite(this.allContributions, {
				register: written.register,
				schema: written.schema,
			})
			if (typeof unread === 'number') {
				this.$emit('unread', unread)
			}
		},
	},
}
</script>

<style scoped>
.pq-contribution-page__collection {
	margin-block-end: var(--utrecht-space-block-lg, 1.5rem);
}

.pq-record__head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: var(--utrecht-space-block-sm, 0.5rem);
	margin-block-end: var(--utrecht-space-block-md, 1rem);
}

.pq-record__title {
	margin: 0;
}

/* The board's case head: the eyebrow in the muted colour over a large
   title, the way back at the end of the line. */
.pq-record__head--titled {
	align-items: flex-start;
	gap: 1rem;
}

.pq-record__titles {
	display: flex;
	flex-direction: column;
	gap: 0.375rem;
}

.pq-record__eyebrow {
	margin: 0;
	color: var(
		--thematiq-website-text-muted,
		var(--nldesign-color-text-muted, inherit)
	);
	font-size: 1rem;
}

.pq-record__title--h1 {
	line-height: 1.15;
}
</style>
