// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// The Traffic page's arithmetic (portal-traffic-analytics): how a range of
// `portalTrafficDaily` records becomes four numbers, a series, and three
// ranked lists. Pure, so a node test can check it without a browser, and
// so every widget on the page reads ONE summary instead of each folding
// the records its own way and disagreeing.

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
		.filter(
			(s) =>
				s
				&& typeof s.id === 'string'
				&& /^[A-Za-z0-9_-]{1,64}$/.test(s.id)
				&& Array.isArray(s.conditions)
				&& s.conditions.length > 0
				&& !seen[s.id]
				&& (seen[s.id] = true),
		)
		.map((s) => ({ id: s.id, name: String(s.name || s.id) }))
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
 * @return {{totals: object, series: object, days: number, visitors: object, breakdowns: object, pages: Array<object>, transitions: Array<object>, sources: Array<object>, searches: Array<object>, goals: Array<object>, conversionRate: number, funnels: Array<object>, forms: Array<object>, notFound: Array<object>, errors: Array<object>, customDimensions: object, experiments: Array<object>, heatmaps: Array<object>, hasData: boolean}} The summary.
 */
export function summarise(records, dates) {
	const byDate = {}
	;(records || []).forEach((record) => {
		if (record && typeof record.date === 'string') {
			byDate[record.date] = record
		}
	})

	const totals = { pageViews: 0, sessions: 0, visitors: 0, engagedSessions: 0 }
	const series = { dates, pageViews: [], sessions: [], visitors: [] }
	const pages = {}
	const transitions = {}
	const channels = {}
	// New versus returning, and accounts, are only known where a day's
	// record carries a number. A null is "not available", and one day of
	// numbers among nulls is still a number: the flags say whether ANY
	// day in the range could tell, so a cookieless portal reads "not
	// available" rather than a zero (Ruben, decision 2).
	const visitors = {
		visitors: 0,
		newVisitors: 0,
		returningVisitors: 0,
		accounts: 0,
		newReturningAvailable: false,
		accountsAvailable: false,
	}
	const breakdowns = {}
	BREAKDOWNS.forEach((b) => {
		breakdowns[b.key] = {}
	})
	const outcomes = newOutcomes()
	let hasData = false

	dates.forEach((date) => {
		const record = byDate[date] || {}
		const pageViews = num(record.pageViews)
		const sessions = num(record.sessions)
		const dayVisitors = num(record.visitors)
		if (byDate[date]) {
			hasData = true
		}
		totals.pageViews += pageViews
		totals.sessions += sessions
		totals.visitors += dayVisitors
		totals.engagedSessions += num(record.engagedSessions)
		series.pageViews.push(pageViews)
		series.sessions.push(sessions)
		series.visitors.push(dayVisitors)
		visitors.visitors += dayVisitors
		if (isCount(record.newVisitors) || isCount(record.returningVisitors)) {
			visitors.newReturningAvailable = true
			visitors.newVisitors += num(record.newVisitors)
			visitors.returningVisitors += num(record.returningVisitors)
		}
		if (isCount(record.accounts)) {
			visitors.accountsAvailable = true
			visitors.accounts += num(record.accounts)
		}
		BREAKDOWNS.forEach((b) => {
			const map = record[b.field]
			if (!map || typeof map !== 'object' || Array.isArray(map)) {
				return
			}
			Object.keys(map).forEach((value) => {
				if (value === '') {
					return
				}
				breakdowns[b.key][value] =
					(breakdowns[b.key][value] || 0) + num(map[value])
			})
		})

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

		list(record.transitions).forEach((edge) => {
			const key = String(edge.from || '') + ' ' + String(edge.to || '')
			const row = transitions[key] || {
				from: String(edge.from || ''),
				to: String(edge.to || ''),
				count: 0,
			}
			row.count += num(edge.count)
			transitions[key] = row
		})

		foldOutcomes(outcomes, record, sessions)

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
	})

	const ranked = {}
	BREAKDOWNS.forEach((b) => {
		ranked[b.key] = rank(
			Object.keys(breakdowns[b.key]).map((value) => ({
				value,
				count: breakdowns[b.key][value],
			})),
			'count',
		)
	})

	return {
		totals,
		series,
		days: dates.length,
		visitors,
		breakdowns: ranked,
		pages: rank(Object.values(pages), 'views'),
		transitions: rank(Object.values(transitions), 'count'),
		sources: rank(Object.values(channels), 'count').map((row) => ({
			channel: row.channel,
			count: row.count,
			hosts: rank(
				Object.keys(row.hosts).map((host) => ({
					host,
					count: row.hosts[host],
				})),
				'count',
			)
				.slice(0, 3)
				.map((h) => h.host),
		})),
		...finishOutcomes(outcomes),
		hasData,
	}
}

/**
 * The accumulators for the outcome fields (portal-traffic-outcomes).
 *
 * @return {object} Empty accumulators.
 */
function newOutcomes() {
	return {
		searches: {},
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

/**
 * Fold one day's outcome fields into the accumulators.
 *
 * A goal's or a funnel's rows are matched by id across days, so a goal
 * renamed mid-range is one row under its latest name. The conversion
 * rate is re-derived from sessions, not averaged: a day with one session
 * and a day with a thousand do not weigh the same.
 *
 * @param {object} outcomes The accumulators.
 * @param {object} record   The day's record.
 * @param {number} sessions The day's sessions.
 * @return {void}
 */
function foldOutcomes(outcomes, record, sessions) {
	list(record.searches).forEach((row) => {
		const term = String(row.term || '')
		if (term !== '') {
			outcomes.searches[term] = (outcomes.searches[term] || 0) + num(row.count)
		}
	})

	outcomes.sessions += sessions
	outcomes.converted += Math.round(
		Math.min(1, Math.max(0, Number(record.conversionRate) || 0)) * sessions,
	)
	list(record.goals).forEach((goal) => {
		const id = String(goal.id || goal.goal || '')
		if (id === '') {
			return
		}
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
		const row = outcomes.goals[id]
		row.name = String(goal.name || row.name || id)
		row.conversions += num(goal.conversions)
		row.completions += num(goal.completions)
		row.value += Number(goal.value) > 0 ? Number(goal.value) : 0
	})

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
		list(funnel.steps).forEach((step, index) => {
			if (!row.steps[index]) {
				row.steps[index] = { name: '', sessions: 0 }
			}
			row.steps[index].name = String(step.name || row.steps[index].name)
			row.steps[index].sessions += num(step.sessions)
		})
	})

	list(record.forms).forEach((form) => {
		const formId = String(form.formId || '')
		if (formId === '') {
			return
		}
		const row = outcomes.forms[formId] || {
			formId,
			starts: 0,
			submits: 0,
			abandons: 0,
			fields: {},
		}
		row.starts += num(form.starts)
		row.submits += num(form.submits)
		row.abandons += num(form.abandons)
		list(form.fields).forEach((field) => {
			const fieldId = String(field.fieldId || '')
			if (fieldId === '') {
				return
			}
			const f = row.fields[fieldId] || {
				fieldId,
				msSum: 0,
				days: 0,
				abandonedHere: 0,
			}
			if (num(field.avgMs) > 0) {
				f.msSum += num(field.avgMs)
				f.days += 1
			}
			f.abandonedHere += num(field.abandonedHere)
			row.fields[fieldId] = f
		})
		outcomes.forms[formId] = row
	})

	list(record.notFound).forEach((row) => {
		const path = String(row.path || '')
		if (path !== '') {
			outcomes.notFound[path] = (outcomes.notFound[path] || 0) + num(row.hits)
		}
	})

	// Script errors (portal-traffic-reporting): one row per message and
	// source across the days, the pages merged.
	list(record.errors).forEach((error) => {
		const message = String(error.message || '')
		if (message === '') {
			return
		}
		const key = message + '\u0000' + String(error.source || '')
		const row = outcomes.errors[key] || {
			message,
			source: String(error.source || ''),
			hits: 0,
			pages: [],
		}
		row.hits += num(error.hits)
		list(error.pages).forEach((page) => {
			if (row.pages.length < TOP && !row.pages.includes(page)) {
				row.pages.push(String(page))
			}
		})
		outcomes.errors[key] = row
	})

	// Page experiments (portal-traffic-experiments): variants merged by
	// id across the days, the counts summed; the verdict is re-derived
	// from the sums when the summary is finished, never averaged.
	list(record.experiments).forEach((experiment) => {
		const id = String(experiment.id || '')
		if (id === '') {
			return
		}
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
		const row = outcomes.experiments[id]
		row.name = String(experiment.name || row.name || id)
		row.status = String(experiment.status || row.status)
		list(experiment.variants).forEach((variant) => {
			const variantId = String(variant.id || '')
			if (variantId === '') {
				return
			}
			if (!row.variants[variantId]) {
				row.variants[variantId] = {
					id: variantId,
					name: '',
					sessions: 0,
					conversions: 0,
				}
				row.order.push(variantId)
			}
			const summed = row.variants[variantId]
			summed.name = String(variant.name || summed.name || variantId)
			summed.sessions += num(variant.sessions)
			summed.conversions += num(variant.conversions)
		})
	})

	// Heatmaps: the click grid and the scroll deciles summed per page.
	list(record.heatmaps).forEach((heatmap) => {
		const path = String(heatmap.path || '')
		if (path === '') {
			return
		}
		const row = outcomes.heatmaps[path] || {
			path,
			samples: 0,
			cells: {},
			scroll: new Array(10).fill(0),
		}
		row.samples += num(heatmap.samples)
		list(heatmap.clicks).forEach((cell) => {
			const key = num(cell.x) + ':' + num(cell.y)
			row.cells[key] = (row.cells[key] || 0) + num(cell.count)
		})
		list(heatmap.scroll).forEach((count, index) => {
			if (index < 10) {
				row.scroll[index] += num(count)
			}
		})
		outcomes.heatmaps[path] = row
	})

	const dimensions = record.customDimensions
	if (dimensions && typeof dimensions === 'object' && !Array.isArray(dimensions)) {
		Object.keys(dimensions).forEach((id) => {
			const map = dimensions[id]
			if (!map || typeof map !== 'object' || Array.isArray(map)) {
				return
			}
			const target = outcomes.dimensions[id] || {}
			Object.keys(map).forEach((value) => {
				target[value] = (target[value] || 0) + num(map[value])
			})
			outcomes.dimensions[id] = target
		})
	}
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
	const customDimensions = {}
	Object.keys(outcomes.dimensions).forEach((id) => {
		customDimensions[id] = rank(
			Object.keys(outcomes.dimensions[id]).map((value) => ({
				value,
				count: outcomes.dimensions[id][value],
			})),
			'count',
		)
	})

	return {
		searches: rank(
			Object.keys(outcomes.searches).map((term) => ({
				term,
				count: outcomes.searches[term],
			})),
			'count',
		),
		goals: outcomes.goalOrder.map((id) => outcomes.goals[id]),
		conversionRate:
			outcomes.sessions > 0
				? Math.round((outcomes.converted / outcomes.sessions) * 1000) / 1000
				: 0,
		funnels: outcomes.funnelOrder.map((id) => ({
			id,
			name: outcomes.funnels[id].name,
			steps: outcomes.funnels[id].steps.map((step, index, steps) => {
				const previous = index === 0 ? null : steps[index - 1].sessions
				return {
					name: step.name,
					sessions: step.sessions,
					dropOff:
						previous === null || previous <= 0
							? 0
							: Math.round(
									((previous - step.sessions) / previous) * 1000,
								) / 1000,
				}
			}),
		})),
		forms: rank(
			Object.values(outcomes.forms).map((form) => {
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
					completionRate:
						form.starts > 0
							? Math.round((form.submits / form.starts) * 1000) / 1000
							: 0,
					fields,
					leaveField: worst ? worst.fieldId : '',
				}
			}),
			'starts',
		),
		notFound: rank(
			Object.keys(outcomes.notFound).map((path) => ({
				path,
				hits: outcomes.notFound[path],
			})),
			'hits',
		),
		errors: rank(Object.values(outcomes.errors), 'hits'),
		customDimensions,
		experiments: outcomes.experimentOrder.map((id) => {
			const row = outcomes.experiments[id]
			const variants = row.order.map((variantId) => {
				const variant = row.variants[variantId]
				return {
					...variant,
					rate:
						variant.sessions > 0
							? Math.round(
									(variant.conversions / variant.sessions) * 1000,
								) / 1000
							: 0,
				}
			})
			return {
				id,
				name: row.name,
				status: row.status,
				variants,
				...verdict(variants),
			}
		}),
		heatmaps: Object.values(outcomes.heatmaps)
			.map((row) => ({
				path: row.path,
				samples: row.samples,
				clicks: Object.keys(row.cells).map((key) => {
					const [x, y] = key.split(':').map(Number)
					return { x, y, count: row.cells[key] }
				}),
				scroll: row.scroll,
			}))
			.sort((a, b) => b.samples - a.samples),
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
 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-winner-must-only-be-named-with-enough-sessions-and-a-significant-difference
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
 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-winner-must-only-be-named-with-enough-sessions-and-a-significant-difference
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
