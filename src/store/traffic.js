// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// The Traffic page's shared state (portal-traffic-analytics): which portal
// is selected and the daily rollups for it. Five widgets read this one
// store so the selector on the first of them drives the other four, and
// the records are fetched once, not five times.
//
// Everything is read through OpenRegister's object API via the shared
// object store (ADR-022): the rollups are ordinary objects, so the
// reporting API and the export come for free and this app adds no
// endpoint.

import { useObjectStore } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import {
	daysBetween,
	lastDays,
	segmentsOf,
	summarise,
} from '../lib/trafficSummary.js'

/**
 * The register every Portaliq schema lives in.
 */
const REGISTER = 'portaliq'

/**
 * The preset ranges, in days, and the one that is not a preset.
 */
export const RANGE_PRESETS = ['7', '30', '90']

/**
 * The preset the page opens on.
 */
const DEFAULT_PRESET = '30'

export const useTrafficReportStore = defineStore('portaliq-traffic-report', {
	state: () => ({
		portals: [],
		portalSlug: '',
		records: [],
		loadingPortals: false,
		loadingRecords: false,
		error: '',
		// The range every widget reads (portal-traffic-visitors-and-geo):
		// a preset number of days ending today, or a custom start and end.
		rangePreset: DEFAULT_PRESET,
		customFrom: '',
		customTo: '',
		// The segment every widget reads (portal-traffic-reporting): the
		// id of one of the portal's saved segments, or '' for all visits.
		// The records hold every segment's rows; the summary folds the
		// selected one's.
		segment: '',
	}),

	getters: {
		/**
		 * The selected portal object, or null.
		 *
		 * @param {object} state The state.
		 * @return {object|null} The portal.
		 */
		portal: (state) =>
			state.portals.find((p) => p && p.slug === state.portalSlug) || null,

		/**
		 * The dates the page covers, oldest first: the preset's last days,
		 * or the custom range when it is complete and in order. An
		 * incomplete custom range shows nothing rather than a guess.
		 *
		 * @param {object} state The state.
		 * @return {Array<string>} YYYY-MM-DD.
		 *
		 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-traffic-page-must-let-the-reader-choose-the-range
		 */
		dates: (state) => {
			if (state.rangePreset === 'custom') {
				return daysBetween(state.customFrom, state.customTo)
			}
			return lastDays(Number(state.rangePreset) || 30, new Date())
		},

		/**
		 * The portal's usable segments, `{id, name}` each.
		 *
		 * @return {Array<{id: string, name: string}>} The segments.
		 *
		 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-segment-must-be-a-saved-filter-over-sessions
		 */
		segments() {
			return segmentsOf(this.portal)
		},

		/**
		 * The loaded records of the selected segment only. A record
		 * written before segments existed has no `segment` and counts as
		 * all visits.
		 *
		 * @return {Array<object>} The records.
		 *
		 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-segment-must-be-a-saved-filter-over-sessions
		 */
		segmentRecords() {
			return this.records.filter(
				(r) => String(r.segment || '') === this.segment,
			)
		},

		/**
		 * The folded summary of the selected segment's records.
		 *
		 * @return {object} See `summarise`.
		 *
		 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
		 */
		summary() {
			return summarise(this.segmentRecords, this.dates)
		},

		/**
		 * The export download for what the page shows: the portal, the
		 * range and the segment (portal-traffic-reporting).
		 *
		 * @return {string} The URL, or '' when there is nothing to export.
		 *
		 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-the-daily-records-must-be-exportable
		 */
		exportUrl() {
			const dates = this.dates
			if (!this.portalSlug || dates.length === 0) {
				return ''
			}
			const query = new URLSearchParams({
				portal: this.portalSlug,
				from: dates[0],
				to: dates[dates.length - 1],
				segment: this.segment,
				format: 'csv',
			})
			return (
				generateUrl('/apps/portaliq/api/traffic/export')
				+ '?'
				+ query.toString()
			)
		},
	},

	actions: {
		/**
		 * Point the shared object store at the two schemas, once.
		 *
		 * @return {object} The shared object store.
		 *
		 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
		 */
		objects() {
			const store = useObjectStore()
			if (!store.objectTypeRegistry || !store.objectTypeRegistry.portal) {
				store.registerObjectType('portal', 'portal', REGISTER)
			}
			if (
				!store.objectTypeRegistry
				|| !store.objectTypeRegistry.portalTrafficDaily
			) {
				store.registerObjectType(
					'portalTrafficDaily',
					'portalTrafficDaily',
					REGISTER,
				)
			}
			return store
		},

		/**
		 * Load the portals and select the first measured one (else the
		 * first), then its records.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
		 */
		async load() {
			this.loadingPortals = true
			this.error = ''
			try {
				const rows = await this.objects().fetchCollection('portal', {
					_limit: 200,
				})
				this.portals = (rows || []).filter(
					(p) => p && typeof p.slug === 'string',
				)
				if (!this.portal) {
					const measured = this.portals.find(
						(p) => p.traffic && p.traffic.enabled === true,
					)
					this.portalSlug = (measured || this.portals[0] || {}).slug || ''
				}
			} catch (error) {
				this.error = String((error && error.message) || error)
			} finally {
				this.loadingPortals = false
			}
			await this.loadRecords()
		},

		/**
		 * Select a portal and load its records.
		 *
		 * @param {string} slug The portal slug.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
		 */
		async select(slug) {
			if (slug === this.portalSlug) {
				return
			}
			this.portalSlug = slug
			this.segment = ''
			await this.loadRecords()
		},

		/**
		 * Choose a segment, or '' for all visits. No reload: the records
		 * of every segment are already here.
		 *
		 * @param {string} id The segment id.
		 * @return {void}
		 *
		 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-segment-must-be-a-saved-filter-over-sessions
		 */
		setSegment(id) {
			const value = String(id || '')
			if (value !== '' && !this.segments.some((s) => s.id === value)) {
				return
			}
			this.segment = value
		},

		/**
		 * Choose a preset range: 7, 30 or 90 days, or 'custom'.
		 *
		 * No reload: the records are the portal's, and the range only
		 * decides which of them the summary folds.
		 *
		 * @param {string} preset One of RANGE_PRESETS or 'custom'.
		 * @return {void}
		 *
		 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-traffic-page-must-let-the-reader-choose-the-range
		 */
		setRange(preset) {
			const value = String(preset)
			if (value !== 'custom' && !RANGE_PRESETS.includes(value)) {
				return
			}
			this.rangePreset = value
		},

		/**
		 * Set the custom start and end, as YYYY-MM-DD, and switch to it.
		 *
		 * @param {string} from The first day.
		 * @param {string} to   The last day.
		 * @return {void}
		 *
		 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-traffic-page-must-let-the-reader-choose-the-range
		 */
		setCustomRange(from, to) {
			this.customFrom = String(from || '')
			this.customTo = String(to || '')
			this.rangePreset = 'custom'
		},

		/**
		 * Load the selected portal's daily records.
		 *
		 * The filter is by portal only: the range and the segment are
		 * applied by the summary, so changing either costs no request, and
		 * the request needs no operator syntax the object API might spell
		 * differently across versions. A year of records is under four
		 * hundred rows per segment; the limit leaves room for a handful.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
		 */
		async loadRecords() {
			this.records = []
			if (!this.portalSlug) {
				return
			}
			this.loadingRecords = true
			this.error = ''
			try {
				const rows = await this.objects().fetchCollection(
					'portalTrafficDaily',
					{
						portal: this.portalSlug,
						_limit: 2000,
					},
				)
				this.records = (rows || []).filter(
					(r) =>
						r
						&& r.portal === this.portalSlug
						&& typeof r.date === 'string',
				)
			} catch (error) {
				this.error = String((error && error.message) || error)
			} finally {
				this.loadingRecords = false
			}
		},
	},
})
