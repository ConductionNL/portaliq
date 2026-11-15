// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// The Traffic page's arithmetic (portal-traffic-analytics): how a range of
// `portalTrafficDaily` records becomes four numbers, a series, and three
// ranked lists. Pure, so a node test can check it without a browser, and
// so every widget on the page reads ONE summary instead of each folding
// the records its own way and disagreeing.

import { pageSiteUrl } from './pageSiteUrl.js'

/**
 * How many ranked rows a widget shows.
 */
export const TOP = 10

/**
 * The four switches that change what a portal knows about a person
 * rather than how much it counts (CONTRACT section 4, Ruben's decisions
 * 2, 4 and 6). Each is off unless literally true.
 */
export const SENSITIVE_SWITCHES = [
	'persistClientId',
	'accountLinking',
	'heatmaps',
	'sessionRecording',
]

/**
 * The UTC dates of the last `days` days, oldest first, ending today.
 *
 * @param {number} days How many days.
 * @param {Date}   now  The clock.
 * @return {Array<string>} Dates as YYYY-MM-DD.
 */
export function lastDays(days, now) {
	const out = []
	const end = Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate())
	for (let i = days - 1; i >= 0; i--) {
		out.push(new Date(end - i * 86400000).toISOString().substring(0, 10))
	}
	return out
}

/**
 * The most days a custom range may span. A year of daily records is what
 * the store fetches; asking for more would silently show less.
 */
export const MAX_RANGE_DAYS = 366

/**
 * The UTC dates from `from` to `to` inclusive, oldest first; empty when
 * either is not a date or the order is reversed, and capped at
 * MAX_RANGE_DAYS from the start.
 *
 * @param {string} from YYYY-MM-DD.
 * @param {string} to   YYYY-MM-DD.
 * @return {Array<string>} Dates as YYYY-MM-DD.
 */
export function daysBetween(from, to) {
	const start = parseDay(from)
	const end = parseDay(to)
	if (start === null || end === null || start > end) {
		return []
	}
	const out = []
	for (let at = start; at <= end && out.length < MAX_RANGE_DAYS; at += 86400000) {
		out.push(new Date(at).toISOString().substring(0, 10))
	}
	return out
}

/**
 * A YYYY-MM-DD string as a UTC midnight timestamp, or null.
 *
 * @param {unknown} value The string.
 * @return {number|null} The timestamp.
 */
function parseDay(value) {
	if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
		return null
	}
	const at = Date.parse(value + 'T00:00:00Z')
	return Number.isFinite(at) ? at : null
}

/**
 * The dimensions the Visitors widget breaks down, in display order, with
 * the rollup field each reads.
 */
export const BREAKDOWNS = [
	{ key: 'deviceType', field: 'devices' },
	{ key: 'browser', field: 'browsers' },
	{ key: 'os', field: 'os' },
	{ key: 'language', field: 'languages' },
	{ key: 'region', field: 'regions' },
]

/** The names of a counted value's row: `{value, count}`. */
const VALUE_COUNT = { key: 'value', count: 'count' }

/** The names of a counted term's row: `{term, count}`. */
const TERM_COUNT = { key: 'term', count: 'count' }

/**
 * Whether a portal enabled a dimension. A portal that named none has the
 * contract's defaults (referrer and title), so every breakdown is off.
 *
 * @param {object|null} portal The portal object.
 * @param {string}      key    The dimension.
 * @return {boolean} True when listed.
 */
export function hasDimension(portal, key) {
	const dimensions = portal && portal.traffic && portal.traffic.dimensions
	if (!Array.isArray(dimensions)) {
		return false
	}
	return dimensions.includes(key)
}

/**
 * A portal's usable segments (portal-traffic-reporting): those with an
 * id and at least one condition. The server refuses more than this does;
 * a segment listed here that the server dropped simply has no rows.
 *
 * @param {object|null} portal The portal object.
 * @return {Array<{id: string, name: string}>} The segments, in declared order.
 */
export function segmentsOf(portal) {
	const segments = portal && portal.traffic && portal.traffic.segments
	if (!Array.isArray(segments)) {
		return []
	}
	const seen = {}
	return segments
		.filter((s) => isUsableSegment(s) && !seen[s.id] && (seen[s.id] = true))
		.map((s) => ({ id: s.id, name: String(s.name || s.id) }))
}

/**
 * Whether a segment can be reported: a plain id and at least one condition.
 *
 * @param {object|null} s The segment.
 * @return {boolean} True when usable.
 */
function isUsableSegment(s) {
	return Boolean(
		s
			&& typeof s.id === 'string'
			&& /^[A-Za-z0-9_-]{1,64}$/.test(s.id)
			&& Array.isArray(s.conditions)
			&& s.conditions.length > 0,
	)
}

/**
 * The portals a roll-up portal sums (portal-traffic-reporting), or []
 * for an ordinary portal.
 *
 * @param {object|null} portal The portal object.
 * @return {Array<string>} The member slugs.
 */
export function rollupOf(portal) {
	const members = portal && portal.traffic && portal.traffic.rollupOf
	if (!Array.isArray(members)) {
		return []
	}
	return members.filter(
		(m) => typeof m === 'string' && m !== '' && m !== portal.slug,
	)
}

/**
 * Whether a portal record measures traffic at all.
 *
 * @param {object|null} portal The portal object.
 * @return {boolean} True when `traffic.enabled` is literally true.
 */
export function isMeasured(portal) {
	return Boolean(portal && portal.traffic && portal.traffic.enabled === true)
}

/**
 * The sensitive switches a portal has on, in contract order.
 *
 * @param {object|null} portal The portal object.
 * @return {Array<string>} The switch names that are literally true.
 */
export function warnedSwitches(portal) {
	const sensitive = (portal && portal.traffic && portal.traffic.sensitive) || {}
	return SENSITIVE_SWITCHES.filter((key) => sensitive[key] === true)
}

/**
 * Fold a range of daily records into what the page shows.
 *
 * Records outside `dates` are ignored; a date with no record is a zero on
 * the series, not a gap, so a chart's x axis stays a calendar.
 *
 * @param {Array<object>} records The `portalTrafficDaily` objects, any order.
 * @param {Array<string>} dates   The dates of the range, oldest first.
 * @return {{totals: object, series: object, days: number, visitors: object, breakdowns: object, pages: Array<object>, sources: Array<object>, searches: Array<object>, zeroResultSearches: Array<object>, searchesWithoutCount: number, goals: Array<object>, conversionRate: number, funnels: Array<object>, forms: Array<object>, notFound: Array<object>, errors: Array<object>, customDimensions: object, experiments: Array<object>, heatmaps: Array<object>, hasData: boolean}} The summary.
 */
export function summarise(records, dates) {
	const byDate = recordsByDate(records)
	const run = newSummaryRun(dates)
	dates.forEach((date) => {
		if (byDate[date]) {
			run.hasData = true
		}
		foldDay(run, byDate[date] || {})
	})
	return finishSummary(run)
}

/**
 * The records by their date; a record without a date string is left out.
 *
 * @param {Array<object>|null} records The daily records, any order.
 * @return {Record<string, object>} The record per date.
 */
function recordsByDate(records) {
	const byDate = {}
	;(records || []).forEach((record) => {
		if (record && typeof record.date === 'string') {
			byDate[record.date] = record
		}
	})
	return byDate
}

/**
 * The accumulators of one summary.
 *
 * @param {Array<string>} dates The dates of the range, oldest first.
 * @return {object} The run.
 */
function newSummaryRun(dates) {
	const breakdowns = {}
	BREAKDOWNS.forEach((b) => {
		breakdowns[b.key] = {}
	})
	return {
		dates,
		totals: { pageViews: 0, sessions: 0, visitors: 0, engagedSessions: 0 },
		series: { dates, pageViews: [], sessions: [], visitors: [] },
		pages: {},
		channels: {},
		visitors: newVisitors(),
		breakdowns,
		outcomes: newOutcomes(),
		hasData: false,
	}
}

/**
 * The visitor accumulators.
 *
 * New versus returning, and accounts, are only known where a day's
 * record carries a number. A null is "not available", and one day of
 * numbers among nulls is still a number: the flags say whether ANY
 * day in the range could tell, so a cookieless portal reads "not
 * available" rather than a zero (Ruben, decision 2).
 *
 * @return {object} Empty visitor counts.
 */
function newVisitors() {
	return {
		visitors: 0,
		newVisitors: 0,
		returningVisitors: 0,
		accounts: 0,
		newReturningAvailable: false,
		accountsAvailable: false,
	}
}

/**
 * Fold one day's record into the summary.
 *
 * @param {object} run    The summary's accumulators.
 * @param {object} record The day's record, `{}` for a day without one.
 * @return {void}
 */
function foldDay(run, record) {
	foldTotals(run, record)
	foldVisitors(run.visitors, record)
	foldBreakdowns(run.breakdowns, record)
	foldPages(run.pages, record)
	foldOutcomes(run.outcomes, record)
	foldReferrers(run.channels, record)
}

/**
 * A day's page views, sessions, visitors and engaged sessions, added to
 * the totals and the series.
 *
 * @param {object} run    The summary's accumulators.
 * @param {object} record The day's record.
 * @return {void}
 */
function foldTotals(run, record) {
	const pageViews = num(record.pageViews)
	const sessions = num(record.sessions)
	const dayVisitors = num(record.visitors)
	run.totals.pageViews += pageViews
	run.totals.sessions += sessions
	run.totals.visitors += dayVisitors
	run.totals.engagedSessions += num(record.engagedSessions)
	run.series.pageViews.push(pageViews)
	run.series.sessions.push(sessions)
	run.series.visitors.push(dayVisitors)
}

/**
 * A day's visitors, new and returning visitors and accounts.
 *
 * @param {object} visitors The visitor accumulators.
 * @param {object} record   The day's record.
 * @return {void}
 */
function foldVisitors(visitors, record) {
	visitors.visitors += num(record.visitors)
	if (isCount(record.newVisitors) || isCount(record.returningVisitors)) {
		visitors.newReturningAvailable = true
		visitors.newVisitors += num(record.newVisitors)
		visitors.returningVisitors += num(record.returningVisitors)
	}
	if (isCount(record.accounts)) {
		visitors.accountsAvailable = true
		visitors.accounts += num(record.accounts)
	}
}

/**
 * A day's device, browser, system, language and region counts.
 *
 * @param {object} breakdowns The count per value per breakdown.
 * @param {object} record     The day's record.
 * @return {void}
 */
function foldBreakdowns(breakdowns, record) {
	BREAKDOWNS.forEach((b) => {
		const map = record[b.field]
		if (isCountMap(map)) {
			addCounts(breakdowns[b.key], map)
		}
	})
}

/**
 * Whether a value is a map of counts: an object that is not a list.
 *
 * @param {unknown} map The value.
 * @return {boolean} True for a map.
 */
function isCountMap(map) {
	return Boolean(map) && typeof map === 'object' && !Array.isArray(map)
}

/**
 * Add a map's counts to a total per value, leaving out the empty value.
 *
 * @param {Record<string, number>} target The totals, added to.
 * @param {Record<string, number>} map    The counts.
 * @return {void}
 */
function addCounts(target, map) {
	Object.keys(map).forEach((value) => {
		if (value !== '') {
			target[value] = (target[value] || 0) + num(map[value])
		}
	})
}

/**
 * A day's views, entrances and exits per page.
 *
 * @param {object} pages  The row per path.
 * @param {object} record The day's record.
 * @return {void}
 */
function foldPages(pages, record) {
	list(record.pages).forEach((page) => {
		const path = String(page.path || '')
		if (path === '') {
			return
		}
		const row = pages[path] || { path, views: 0, entrances: 0, exits: 0 }
		row.views += num(page.views)
		row.entrances += num(page.entrances)
		row.exits += num(page.exits)
		pages[path] = row
	})
}

/**
 * A day's referrals per channel and host.
 *
 * @param {object} channels The row per channel.
 * @param {object} record   The day's record.
 * @return {void}
 */
function foldReferrers(channels, record) {
	list(record.referrers).forEach((referrer) => {
		const channel = String(referrer.channel || 'direct')
		const host = String(referrer.host || '')
		const row = channels[channel] || { channel, count: 0, hosts: {} }
		row.count += num(referrer.count)
		if (host !== '') {
			row.hosts[host] = (row.hosts[host] || 0) + num(referrer.count)
		}
		channels[channel] = row
	})
}

/**
 * The summary the page shows, from the folded range.
 *
 * @param {object} run The summary's accumulators.
 * @return {object} The summary.
 */
function finishSummary(run) {
	return {
		totals: run.totals,
		series: run.series,
		days: run.dates.length,
		visitors: run.visitors,
		breakdowns: rankedBreakdowns(run.breakdowns),
		pages: rank(Object.values(run.pages), 'views'),
		sources: rank(Object.values(run.channels), 'count').map(sourceRow),
		...finishOutcomes(run.outcomes),
		hasData: run.hasData,
	}
}

/**
 * Each breakdown's values, ranked by count.
 *
 * @param {object} breakdowns The count per value per breakdown.
 * @return {object} The ranked rows per breakdown.
 */
function rankedBreakdowns(breakdowns) {
	const ranked = {}
	BREAKDOWNS.forEach((b) => {
		ranked[b.key] = rankedCounts(breakdowns[b.key], VALUE_COUNT)
	})
	return ranked
}

/**
 * One channel's row: its count and its top three hosts.
 *
 * @param {{channel: string, count: number, hosts: object}} row The channel's row.
 * @return {{channel: string, count: number, hosts: Array<string>}} The source.
 */
function sourceRow(row) {
	return {
		channel: row.channel,
		count: row.count,
		hosts: rankedCounts(row.hosts, { key: 'host', count: 'count' })
			.slice(0, 3)
			.map((h) => h.host),
	}
}

/**
 * A map of counts as rows, ranked by count.
 *
 * @param {Record<string, number>} counts The count per key.
 * @param {{key: string, count: string}} names The row's field names for the key and the count.
 * @return {Array<object>} The top rows.
 */
function rankedCounts(counts, names) {
	return rank(
		Object.keys(counts).map((key) => ({
			[names.key]: key,
			[names.count]: counts[key],
		})),
		names.count,
	)
}

/**
 * The accumulators for the outcome fields (portal-traffic-outcomes).
 *
 * @return {object} Empty accumulators.
 */
function newOutcomes() {
	return {
		searches: {},
		zeroResults: {},
		withoutCount: 0,
		goals: {},
		goalOrder: [],
		converted: 0,
		sessions: 0,
		funnels: {},
		funnelOrder: [],
		forms: {},
		notFound: {},
		errors: {},
		dimensions: {},
		experiments: {},
		experimentOrder: [],
		heatmaps: {},
	}
}

/** The outcome fields of a day, folded in this order. */
const OUTCOME_FOLDS = [
	foldSearches,
	foldZeroResults,
	foldConversions,
	foldGoals,
	foldFunnels,
	foldForms,
	foldNotFound,
	foldErrors,
	foldExperiments,
	foldHeatmaps,
	foldDimensions,
]

/**
 * Fold one day's outcome fields into the accumulators, one field at a
 * time.
 *
 * A goal's or a funnel's rows are matched by id across days, so a goal
 * renamed mid-range is one row under its latest name. The conversion
 * rate is re-derived from sessions, not averaged: a day with one session
 * and a day with a thousand do not weigh the same.
 *
 * @param {object} outcomes The accumulators.
 * @param {object} record   The day's record.
 * @return {void}
 */
function foldOutcomes(outcomes, record) {
	OUTCOME_FOLDS.forEach((fold) => fold(outcomes, record))
}

/**
 * A day's search terms.
 *
 * @param {object} outcomes The accumulators.
 * @param {object} record   The day's record.
 * @return {void}
 */
function foldSearches(outcomes, record) {
	addTermCounts(outcomes.searches, record.searches)
}

/**
 * Terms that found nothing, and the searches that never said
 * (portal-traffic-zero-result-searches).
 *
 * @param {object} outcomes The accumulators.
 * @param {object} record   The day's record.
 * @return {void}
 */
function foldZeroResults(outcomes, record) {
	addTermCounts(outcomes.zeroResults, record.zeroResultSearches)
	outcomes.withoutCount += num(record.searchesWithoutCount)
}

/**
 * Add each row's count to its term, leaving out the empty term.
 *
 * @param {Record<string, number>} target The count per term, added to.
 * @param {Array<object>} rows The `{term, count}` rows.
 * @return {void}
 */
function addTermCounts(target, rows) {
	list(rows).forEach((row) => {
		const term = String(row.term || '')
		if (term !== '') {
			target[term] = (target[term] || 0) + num(row.count)
		}
	})
}

/**
 * A day's sessions and the sessions its conversion rate converted.
 *
 * @param {object} outcomes The accumulators.
 * @param {object} record   The day's record.
 * @return {void}
 */
function foldConversions(outcomes, record) {
	const sessions = num(record.sessions)
	outcomes.sessions += sessions
	outcomes.converted += Math.round(
		Math.min(1, Math.max(0, Number(record.conversionRate) || 0)) * sessions,
	)
}

/**
 * A day's goals, matched by id.
 *
 * @param {object} outcomes The accumulators.
 * @param {object} record   The day's record.
 * @return {void}
 */
function foldGoals(outcomes, record) {
	list(record.goals).forEach((goal) => {
		const id = String(goal.id || goal.goal || '')
		if (id !== '') {
			addGoal(goalRow(outcomes, id), goal)
		}
	})
}

/**
 * The goal's row, created in first-seen order.
 *
 * @param {object} outcomes The accumulators.
 * @param {string} id       The goal's id.
 * @return {object} The row.
 */
function goalRow(outcomes, id) {
	if (!outcomes.goals[id]) {
		outcomes.goals[id] = {
			id,
			name: '',
			conversions: 0,
			completions: 0,
			value: 0,
		}
		outcomes.goalOrder.push(id)
	}
	return outcomes.goals[id]
}

/**
 * Add one day's numbers of a goal to its row; the latest name wins.
 *
 * @param {object} row  The goal's row.
 * @param {object} goal The day's goal.
 * @return {void}
 */
function addGoal(row, goal) {
	row.name = String(goal.name || row.name || row.id)
	row.conversions += num(goal.conversions)
	row.completions += num(goal.completions)
	row.value += Number(goal.value) > 0 ? Number(goal.value) : 0
}

/**
 * A day's funnels, matched by id, their steps by position.
 *
 * @param {object} outcomes The accumulators.
 * @param {object} record   The day's record.
 * @return {void}
 */
function foldFunnels(outcomes, record) {
	list(record.funnels).forEach((funnel) => {
		const id = String(funnel.id || '')
		if (id === '') {
			return
		}
		if (!outcomes.funnels[id]) {
			outcomes.funnels[id] = { id, name: '', steps: [] }
			outcomes.funnelOrder.push(id)
		}
		const row = outcomes.funnels[id]
		row.name = String(funnel.name || row.name || id)
		addFunnelSteps(row.steps, funnel.steps)
	})
}

/**
 * Add a day's funnel steps to the summed steps.
 *
 * @param {Array<object>} steps The summed steps, added to.
 * @param {Array<object>} day   The day's steps.
 * @return {void}
 */
function addFunnelSteps(steps, day) {
	list(day).forEach((step, index) => {
		if (!steps[index]) {
			steps[index] = { name: '', sessions: 0 }
		}
		steps[index].name = String(step.name || steps[index].name)
		steps[index].sessions += num(step.sessions)
	})
}

/**
 * A day's forms: starts, submits and abandons, and per field the time
 * spent and how often it was left on.
 *
 * @param {object} outcomes The accumulators.
 * @param {object} record   The day's record.
 * @return {void}
 */
function foldForms(outcomes, record) {
	list(record.forms).forEach((form) => {
		const formId = String(form.formId || '')
		if (formId === '') {
			return
		}
		const row = outcomes.forms[formId] || newFormRow(formId)
		row.starts += num(form.starts)
		row.submits += num(form.submits)
		row.abandons += num(form.abandons)
		addFormFields(row.fields, form.fields)
		outcomes.forms[formId] = row
	})
}

/**
 * An empty row for a form.
 *
 * @param {string} formId The form's id.
 * @return {object} The row.
 */
function newFormRow(formId) {
	return {
		formId,
		starts: 0,
		submits: 0,
		abandons: 0,
		fields: {},
	}
}

/**
 * Add a day's form fields to the summed fields.
 *
 * @param {object} fields The summed row per field id, added to.
 * @param {Array<object>} day The day's fields.
 * @return {void}
 */
function addFormFields(fields, day) {
	list(day).forEach((field) => {
		const fieldId = String(field.fieldId || '')
		if (fieldId === '') {
			return
		}
		const f = fields[fieldId] || newFieldRow(fieldId)
		if (num(field.avgMs) > 0) {
			f.msSum += num(field.avgMs)
			f.days += 1
		}
		f.abandonedHere += num(field.abandonedHere)
		fields[fieldId] = f
	})
}

/**
 * An empty row for a form field.
 *
 * @param {string} fieldId The field's id.
 * @return {object} The row.
 */
function newFieldRow(fieldId) {
	return {
		fieldId,
		msSum: 0,
		days: 0,
		abandonedHere: 0,
	}
}

/**
 * A day's hits on pages that do not exist.
 *
 * @param {object} outcomes The accumulators.
 * @param {object} record   The day's record.
 * @return {void}
 */
function foldNotFound(outcomes, record) {
	list(record.notFound).forEach((row) => {
		const path = String(row.path || '')
		if (path !== '') {
			outcomes.notFound[path] = (outcomes.notFound[path] || 0) + num(row.hits)
		}
	})
}

/**
 * Script errors (portal-traffic-reporting): one row per message and
 * source across the days, the pages merged.
 *
 * @param {object} outcomes The accumulators.
 * @param {object} record   The day's record.
 * @return {void}
 */
function foldErrors(outcomes, record) {
	list(record.errors).forEach((error) => {
		const message = String(error.message || '')
		if (message === '') {
			return
		}
		const key = message + '\u0000' + String(error.source || '')
		const row = outcomes.errors[key] || newErrorRow(message, error)
		row.hits += num(error.hits)
		addErrorPages(row.pages, error.pages)
		outcomes.errors[key] = row
	})
}

/**
 * An empty row for one script error message and its source.
 *
 * @param {string} message The message.
 * @param {object} error   The day's error, for its source.
 * @return {object} The row.
 */
function newErrorRow(message, error) {
	return {
		message,
		source: String(error.source || ''),
		hits: 0,
		pages: [],
	}
}

/**
 * Add a day's pages of an error, up to TOP distinct ones.
 *
 * @param {Array<string>} pages The error's pages, added to.
 * @param {Array<string>} day   The day's pages.
 * @return {void}
 */
function addErrorPages(pages, day) {
	list(day).forEach((page) => {
		if (pages.length < TOP && !pages.includes(page)) {
			pages.push(String(page))
		}
	})
}

/**
 * Page experiments (portal-traffic-experiments): variants merged by
 * id across the days, the counts summed; the verdict is re-derived
 * from the sums when the summary is finished, never averaged.
 *
 * @param {object} outcomes The accumulators.
 * @param {object} record   The day's record.
 * @return {void}
 */
function foldExperiments(outcomes, record) {
	list(record.experiments).forEach((experiment) => {
		const id = String(experiment.id || '')
		if (id === '') {
			return
		}
		const row = experimentRow(outcomes, id)
		row.name = String(experiment.name || row.name || id)
		row.status = String(experiment.status || row.status)
		addVariants(row, experiment.variants)
	})
}

/**
 * The experiment's row, created in first-seen order.
 *
 * @param {object} outcomes The accumulators.
 * @param {string} id       The experiment's id.
 * @return {object} The row.
 */
function experimentRow(outcomes, id) {
	if (!outcomes.experiments[id]) {
		outcomes.experiments[id] = {
			id,
			name: '',
			status: '',
			variants: {},
			order: [],
		}
		outcomes.experimentOrder.push(id)
	}
	return outcomes.experiments[id]
}

/**
 * Add a day's variants of an experiment to its row, matched by id.
 *
 * @param {object} row  The experiment's row.
 * @param {Array<object>} day The day's variants.
 * @return {void}
 */
function addVariants(row, day) {
	list(day).forEach((variant) => {
		const variantId = String(variant.id || '')
		if (variantId === '') {
			return
		}
		const summed = variantRow(row, variantId)
		summed.name = String(variant.name || summed.name || variantId)
		summed.sessions += num(variant.sessions)
		summed.conversions += num(variant.conversions)
	})
}

/**
 * The variant's row in its experiment, created in first-seen order.
 *
 * @param {object} row       The experiment's row.
 * @param {string} variantId The variant's id.
 * @return {object} The variant's row.
 */
function variantRow(row, variantId) {
	if (!row.variants[variantId]) {
		row.variants[variantId] = {
			id: variantId,
			name: '',
			sessions: 0,
			conversions: 0,
		}
		row.order.push(variantId)
	}
	return row.variants[variantId]
}

/**
 * Heatmaps: the click grid and the scroll deciles summed per page.
 *
 * @param {object} outcomes The accumulators.
 * @param {object} record   The day's record.
 * @return {void}
 */
function foldHeatmaps(outcomes, record) {
	list(record.heatmaps).forEach((heatmap) => {
		const path = String(heatmap.path || '')
		if (path === '') {
			return
		}
		const row = outcomes.heatmaps[path] || newHeatmapRow(path)
		row.samples += num(heatmap.samples)
		addHeatmap(row, heatmap)
		outcomes.heatmaps[path] = row
	})
}

/**
 * An empty heatmap row for a page: no clicks, ten empty scroll deciles.
 *
 * @param {string} path The page.
 * @return {object} The row.
 */
function newHeatmapRow(path) {
	return {
		path,
		samples: 0,
		cells: {},
		scroll: new Array(10).fill(0),
	}
}

/**
 * Add a day's clicks and scroll deciles of a page to its row.
 *
 * @param {object} row     The page's row.
 * @param {object} heatmap The day's heatmap.
 * @return {void}
 */
function addHeatmap(row, heatmap) {
	list(heatmap.clicks).forEach((cell) => {
		const key = num(cell.x) + ':' + num(cell.y)
		row.cells[key] = (row.cells[key] || 0) + num(cell.count)
	})
	list(heatmap.scroll).forEach((count, index) => {
		if (index < 10) {
			row.scroll[index] += num(count)
		}
	})
}

/**
 * A day's custom dimensions: the count per value per dimension.
 *
 * @param {object} outcomes The accumulators.
 * @param {object} record   The day's record.
 * @return {void}
 */
function foldDimensions(outcomes, record) {
	const dimensions = record.customDimensions
	if (!isCountMap(dimensions)) {
		return
	}
	Object.keys(dimensions).forEach((id) => {
		const map = dimensions[id]
		if (!isCountMap(map)) {
			return
		}
		const target = outcomes.dimensions[id] || {}
		Object.keys(map).forEach((value) => {
			target[value] = (target[value] || 0) + num(map[value])
		})
		outcomes.dimensions[id] = target
	})
}

/**
 * The accumulators as the widgets read them: ranked lists, a drop-off per
 * funnel step re-derived from the summed sessions, and per form the field
 * most people left on.
 *
 * @param {object} outcomes The accumulators.
 * @return {object} searches, goals, conversionRate, funnels, forms, notFound, errors, customDimensions.
 */
function finishOutcomes(outcomes) {
	return {
		searches: rankedCounts(outcomes.searches, TERM_COUNT),
		zeroResultSearches: rankedCounts(outcomes.zeroResults, TERM_COUNT),
		searchesWithoutCount: outcomes.withoutCount,
		goals: outcomes.goalOrder.map((id) => outcomes.goals[id]),
		conversionRate: share(outcomes.converted, outcomes.sessions),
		funnels: outcomes.funnelOrder.map((id) => funnelView(outcomes.funnels[id])),
		forms: rank(Object.values(outcomes.forms).map(formView), 'starts'),
		notFound: rankedCounts(outcomes.notFound, { key: 'path', count: 'hits' }),
		errors: rank(Object.values(outcomes.errors), 'hits'),
		customDimensions: rankedDimensions(outcomes.dimensions),
		experiments: outcomes.experimentOrder.map((id) =>
			experimentView(outcomes.experiments[id]),
		),
		heatmaps: Object.values(outcomes.heatmaps)
			.map(heatmapView)
			.sort((a, b) => b.samples - a.samples),
	}
}

/**
 * A part of a whole as a fraction to three decimals; 0 without a whole.
 *
 * @param {number} part  The part.
 * @param {number} whole The whole.
 * @return {number} The fraction.
 */
function share(part, whole) {
	return whole > 0 ? Math.round((part / whole) * 1000) / 1000 : 0
}

/**
 * Each custom dimension's values, ranked by count.
 *
 * @param {object} dimensions The count per value per dimension.
 * @return {object} The ranked rows per dimension.
 */
function rankedDimensions(dimensions) {
	const customDimensions = {}
	Object.keys(dimensions).forEach((id) => {
		customDimensions[id] = rankedCounts(dimensions[id], VALUE_COUNT)
	})
	return customDimensions
}

/**
 * A funnel with the drop-off of each step from the step before it.
 *
 * @param {{id: string, name: string, steps: Array<object>}} funnel The summed funnel.
 * @return {object} The funnel as the widget reads it.
 */
function funnelView(funnel) {
	return {
		id: funnel.id,
		name: funnel.name,
		steps: funnel.steps.map((step, index, steps) => ({
			name: step.name,
			sessions: step.sessions,
			dropOff: index === 0 ? 0 : dropOff(steps[index - 1].sessions, step.sessions),
		})),
	}
}

/**
 * The share of a step's sessions that did not reach the next step.
 *
 * @param {number} previous The previous step's sessions.
 * @param {number} sessions This step's sessions.
 * @return {number} The drop-off, 0 when the previous step had none.
 */
function dropOff(previous, sessions) {
	if (previous <= 0) {
		return 0
	}
	return Math.round(((previous - sessions) / previous) * 1000) / 1000
}

/**
 * A form with its completion rate, its fields ranked by how often they
 * were left on, and the field most people left on.
 *
 * @param {object} form The summed form.
 * @return {object} The form as the widget reads it.
 */
function formView(form) {
	const fields = rank(
		Object.values(form.fields).map((f) => ({
			fieldId: f.fieldId,
			avgMs: f.days > 0 ? Math.round(f.msSum / f.days) : 0,
			abandonedHere: f.abandonedHere,
		})),
		'abandonedHere',
	)
	const worst = fields.find((f) => f.abandonedHere > 0)
	return {
		formId: form.formId,
		starts: form.starts,
		submits: form.submits,
		abandons: form.abandons,
		completionRate: share(form.submits, form.starts),
		fields,
		leaveField: worst ? worst.fieldId : '',
	}
}

/**
 * An experiment with each variant's rate and the verdict over them.
 *
 * @param {object} row The summed experiment.
 * @return {object} The experiment as the widget reads it.
 */
function experimentView(row) {
	const variants = row.order.map((variantId) => {
		const variant = row.variants[variantId]
		return {
			...variant,
			rate: share(variant.conversions, variant.sessions),
		}
	})
	return {
		id: row.id,
		name: row.name,
		status: row.status,
		variants,
		...verdict(variants),
	}
}

/**
 * A page's heatmap with its click grid as cells.
 *
 * @param {object} row The page's summed heatmap.
 * @return {{path: string, samples: number, clicks: Array<object>, scroll: Array<number>}} The heatmap.
 */
function heatmapView(row) {
	return {
		path: row.path,
		samples: row.samples,
		clicks: Object.keys(row.cells).map((key) => {
			const [x, y] = key.split(':').map(Number)
			return { x, y, count: row.cells[key] }
		}),
		scroll: row.scroll,
	}
}

/**
 * Sessions each variant needs before a winner may be named, and the
 * confidence it needs; the same two numbers as the aggregation's.
 */
export const MIN_EXPERIMENT_SESSIONS = 30
export const WINNING_CONFIDENCE = 0.95

/**
 * The winner and the confidence for summed variant rows, the way the
 * aggregation decides it for one day: the two best rates compared by a
 * two-proportion z-test, a winner only with enough sessions everywhere
 * and the confidence at or above the threshold.
 *
 * @spec openspec/specs/portal-traffic-experiments/spec.md#requirement-a-winner-must-only-be-named-with-enough-sessions-and-a-significant-difference
 * @param {Array<{id: string, sessions: number, conversions: number}>} variants The rows.
 * @return {{winner: string, confidence: number, enough: boolean}} The verdict.
 */
export function verdict(variants) {
	const rows = Array.isArray(variants) ? variants : []
	const enough =
		rows.length >= 2
		&& rows.every((v) => num(v.sessions) >= MIN_EXPERIMENT_SESSIONS)
	if (rows.length < 2) {
		return { winner: '', confidence: 0, enough: false }
	}
	const ranked = rows
		.slice()
		.sort(
			(a, b) =>
				num(b.conversions) / Math.max(1, num(b.sessions))
					- num(a.conversions) / Math.max(1, num(a.sessions))
				|| num(b.sessions) - num(a.sessions),
		)
	const confidence = zTest(
		num(ranked[0].conversions),
		num(ranked[0].sessions),
		num(ranked[1].conversions),
		num(ranked[1].sessions),
	)
	return {
		winner:
			enough && confidence >= WINNING_CONFIDENCE ? String(ranked[0].id) : '',
		confidence,
		enough,
	}
}

/**
 * The two-proportion z-test as a two-sided confidence, three decimals.
 *
 * @spec openspec/specs/portal-traffic-experiments/spec.md#requirement-a-winner-must-only-be-named-with-enough-sessions-and-a-significant-difference
 * @param {number} conversionsA Conversions of the first variant.
 * @param {number} sessionsA    Sessions of the first variant.
 * @param {number} conversionsB Conversions of the second variant.
 * @param {number} sessionsB    Sessions of the second variant.
 * @return {number} Between 0 and 1.
 */
export function zTest(conversionsA, sessionsA, conversionsB, sessionsB) {
	if (!(sessionsA > 0) || !(sessionsB > 0)) {
		return 0
	}
	const pooled = (conversionsA + conversionsB) / (sessionsA + sessionsB)
	const error = Math.sqrt(pooled * (1 - pooled) * (1 / sessionsA + 1 / sessionsB))
	if (!(error > 0)) {
		return 0
	}
	const score =
		Math.abs(conversionsA / sessionsA - conversionsB / sessionsB) / error
	const pValue = 2 * (1 - normalCdf(score))
	return Math.round(Math.max(0, Math.min(1, 1 - pValue)) * 1000) / 1000
}

/**
 * The standard normal cumulative distribution (Abramowitz and Stegun
 * 7.1.26), the same approximation the aggregation uses.
 *
 * @param {number} score The standard score.
 * @return {number} The mass below it.
 */
function normalCdf(score) {
	let arg = score / Math.SQRT2
	let sign = 1
	if (arg < 0) {
		sign = -1
		arg = -arg
	}
	const step = 1 / (1 + 0.3275911 * arg)
	const poly =
		(((1.061405429 * step - 1.453152027) * step + 1.421413741) * step
			- 0.284496736)
			* step
		+ 0.254829592
	const erf = 1 - poly * step * Math.exp(-arg * arg)
	return 0.5 * (1 + sign * erf)
}

/**
 * Sort descending by a numeric key and keep the top rows.
 *
 * @param {Array<object>} rows The rows.
 * @param {string}        key  The key to rank by.
 * @return {Array<object>} The top rows.
 */
function rank(rows, key) {
	return rows
		.slice()
		.sort((a, b) => b[key] - a[key])
		.slice(0, TOP)
}

/**
 * Whether a rollup field carries a count rather than "not available".
 *
 * @param {unknown} value The value.
 * @return {boolean} True for a finite number, false for null or absent.
 */
function isCount(value) {
	return typeof value === 'number' && Number.isFinite(value)
}

/**
 * A non-negative integer, or 0.
 *
 * @param {unknown} value The value.
 * @return {number} The number.
 */
function num(value) {
	const n = Number(value)
	return Number.isFinite(n) && n > 0 ? n : 0
}

/**
 * An array, or [].
 *
 * @param {unknown} value The value.
 * @return {Array} The list.
 */
function list(value) {
	return Array.isArray(value) ? value : []
}

/**
 * The public site's search page for one term (portal-traffic-zero-result-searches):
 * the portal's own search route, else `/zoeken`, opened with the term typed in.
 *
 * @param {object|null} portal The portal object (`slug`, `headerSearch.route`).
 * @param {string} term The search term.
 * @param {(path: string) => string} generateUrlFn Nextcloud's URL generator.
 * @return {string} The URL, or '' when there is no portal or term.
 *
 * @spec openspec/changes/portal-traffic-zero-result-searches/specs/portal-traffic-reporting/spec.md#requirement-the-traffic-page-shows-what-the-public-did-not-find-req-pzr-002
 */
export function searchLinkOf(portal, term, generateUrlFn) {
	const slug = String((portal && portal.slug) || '')
	if (slug === '' || String(term || '') === '') {
		return ''
	}
	const configured = String(
		(portal.headerSearch && portal.headerSearch.route) || '',
	)
	const route = /^\/(?!\/)/.test(configured) ? configured : '/zoeken'
	return `${pageSiteUrl({ route, portal: slug }, generateUrlFn)}&_search=${encodeURIComponent(term)}`
}
