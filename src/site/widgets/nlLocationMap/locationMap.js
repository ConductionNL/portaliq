/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The data behind the location map block: the authored locations made safe,
 * the filter chips, a marker position on the sketch map and the route link.
 * No imports, so tests/site-location-map.spec.mjs runs it in node.
 *
 * @spec openspec/changes/archive/2026-11-26-site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
 */

/** The sketch map's drawing box, in SVG units. */
export const MAP_BOX = { width: 640, height: 400, margin: 40 }

/**
 * A finite number in a range, or null.
 *
 * @param {unknown} value The authored value.
 * @param {number} min The lowest allowed.
 * @param {number} max The highest allowed.
 * @return {number|null}
 */
function coordinate(value, min, max) {
	const number = Number(value)
	return value !== '' && value !== null && Number.isFinite(number) && number >= min && number <= max ? number : null
}

/**
 * The authored links of a location that have a text and an address this
 * site may follow.
 *
 * @param {unknown} links The authored links.
 * @return {Array<{label: string, href: string}>}
 */
function safeLinks(links) {
	return (Array.isArray(links) ? links : [])
		.map((link) => ({ label: String(link?.label || '').trim(), href: String(link?.href || '').trim() }))
		.filter((link) => link.label !== '' && /^(\/(?!\/)|https?:\/\/|mailto:|tel:)/i.test(link.href))
}

/**
 * The weekday names an author may use as a key in `hours`, Dutch and
 * English, each to its JavaScript day number (0 is Sunday).
 */
const WEEKDAYS = {
	zo: 0, zondag: 0, sun: 0, sunday: 0,
	ma: 1, maandag: 1, mon: 1, monday: 1,
	di: 2, dinsdag: 2, tue: 2, tuesday: 2,
	wo: 3, woensdag: 3, wed: 3, wednesday: 3,
	do: 4, donderdag: 4, thu: 4, thursday: 4,
	vr: 5, vrijdag: 5, fri: 5, friday: 5,
	za: 6, zaterdag: 6, sat: 6, saturday: 6,
}

/**
 * Minutes after midnight of a written time ("8.30", "08:30", "17"), or null.
 *
 * @param {string} text The time as written.
 * @return {number|null}
 */
function minutesOf(text) {
	const match = /^(\d{1,2})(?:[.:](\d{2}))?$/.exec(text.trim())
	if (match === null) {
		return null
	}
	const total = (Number(match[1]) * 60) + Number(match[2] || 0)
	return Number(match[2] || 0) < 60 && total <= 1440 ? total : null
}

/**
 * The opening hours per weekday as the block reads them: each authored day
 * (`{ma: '8.30-17.00', za: '9.00-12.00, 13.00-15.00'}`) to its ranges in
 * minutes. A day or range that cannot be read is left out, so a typo shows
 * no open-now line rather than a wrong one.
 *
 * @param {unknown} hours The authored hours.
 * @return {Object<number, Array<{open: number, close: number}>>}
 * @spec openspec/changes/archive/2026-11-26-site-location-map/tasks.md#t04
 */
export function normaliseHours(hours) {
	const days = {}
	if (hours === null || typeof hours !== 'object' || Array.isArray(hours)) {
		return days
	}
	for (const [key, value] of Object.entries(hours)) {
		const day = WEEKDAYS[String(key).trim().toLowerCase()]
		if (day === undefined || typeof value !== 'string') {
			continue
		}
		const ranges = value.split(',')
			.map((range) => range.split(/[-–]/))
			.filter((parts) => parts.length === 2)
			.map(([open, close]) => ({ open: minutesOf(open), close: minutesOf(close) }))
			.filter((range) => range.open !== null && range.close !== null && range.open < range.close)
		if (ranges.length > 0) {
			days[day] = ranges
		}
	}
	return days
}

/**
 * Whether a place is open at a moment, by its authored hours: the closing
 * time of the range that holds the moment, or `false` when none does, or
 * null when the place has no hours to read (then the block says nothing).
 *
 * @param {Object<number, Array<{open: number, close: number}>>} hours The normalised hours.
 * @param {Date} moment The moment, in the visitor's clock.
 * @return {string|false|null} The closing time as "17:00", false, or null.
 * @spec openspec/changes/archive/2026-11-26-site-location-map/tasks.md#t04
 */
export function openUntil(hours, moment) {
	if (Object.keys(hours).length === 0) {
		return null
	}
	const minute = (moment.getHours() * 60) + moment.getMinutes()
	const range = (hours[moment.getDay()] || []).find((candidate) => minute >= candidate.open && minute < candidate.close)
	if (range === undefined) {
		return false
	}
	const pad = (number) => String(number).padStart(2, '0')
	return `${pad(Math.floor(range.close / 60))}:${pad(range.close % 60)}`
}

/**
 * The locations as the block draws them: a name is required, everything
 * else is optional, and each gets its number in the order authored.
 *
 * @param {unknown} locations The authored locations.
 * @return {Array<{number: number, name: string, address: string, opening: string, hours: object, category: string, lat: number|null, lon: number|null, links: Array<{label: string, href: string}>}>}
 * @spec openspec/changes/archive/2026-11-26-site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
 * @spec openspec/changes/archive/2026-11-26-site-location-map/tasks.md#t04
 */
export function normaliseLocations(locations) {
	return (Array.isArray(locations) ? locations : [])
		.filter((location) => location && String(location.name || '').trim() !== '')
		.map((location, index) => ({
			number: index + 1,
			name: String(location.name).trim(),
			address: String(location.address || '').trim(),
			opening: String(location.opening || '').trim(),
			hours: normaliseHours(location.hours),
			category: String(location.category || '').trim(),
			lat: coordinate(location.lat, -90, 90),
			lon: coordinate(location.lon, -180, 180),
			links: safeLinks(location.links),
		}))
}

/**
 * The filter chips: everything, then each category in the order it first
 * appears. No chips when there is fewer than two categories to choose from.
 *
 * @param {Array<{category: string}>} locations The normalised locations.
 * @param {string} allLabel The name of the chip that shows everything.
 * @return {Array<{value: string, label: string}>}
 * @spec openspec/changes/archive/2026-11-26-site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
 */
export function categoryChips(locations, allLabel) {
	const categories = [...new Set(locations.map((location) => location.category).filter((category) => category !== ''))]
	if (categories.length < 2) {
		return []
	}
	return [{ value: '', label: allLabel }, ...categories.map((category) => ({ value: category, label: category }))]
}

/**
 * Where each located marker sits on the sketch map: the box around all
 * located places, scaled into MAP_BOX with its margin, north up. One place
 * sits in the middle.
 *
 * @param {Array<{number: number, lat: number|null, lon: number|null}>} locations The normalised locations.
 * @return {Array<{number: number, x: number, y: number}>}
 * @spec openspec/changes/archive/2026-11-26-site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
 */
export function markerPositions(locations) {
	const located = locations.filter((location) => location.lat !== null && location.lon !== null)
	if (located.length === 0) {
		return []
	}
	const lats = located.map((location) => location.lat)
	const lons = located.map((location) => location.lon)
	const [south, north, west, east] = [Math.min(...lats), Math.max(...lats), Math.min(...lons), Math.max(...lons)]
	const innerWidth = MAP_BOX.width - (2 * MAP_BOX.margin)
	const innerHeight = MAP_BOX.height - (2 * MAP_BOX.margin)
	const span = (low, high) => (high - low) || 1
	return located.map((location) => ({
		number: location.number,
		x: Math.round(east === west ? MAP_BOX.width / 2 : MAP_BOX.margin + (((location.lon - west) / span(west, east)) * innerWidth)),
		y: Math.round(north === south ? MAP_BOX.height / 2 : MAP_BOX.margin + (((north - location.lat) / span(south, north)) * innerHeight)),
	}))
}

/**
 * The name label beside each marker: to the right of the marker, or to its
 * left when the marker sits in the right part of the map, so the label stays
 * inside the drawing.
 *
 * @param {Array<{number: number, x: number, y: number}>} markers The markers.
 * @param {Array<{number: number, name: string}>} locations The normalised locations.
 * @return {Array<{number: number, x: number, y: number, name: string, anchor: string}>}
 * @spec openspec/changes/archive/2026-11-26-site-location-map/tasks.md#t04
 */
export function markerLabels(markers, locations) {
	const names = new Map(locations.map((location) => [location.number, location.name]))
	const gap = 22
	return markers.map((marker) => {
		const left = marker.x > MAP_BOX.width * 0.6
		return {
			number: marker.number,
			x: left ? marker.x - gap : marker.x + gap,
			y: marker.y,
			name: names.get(marker.number) || '',
			anchor: left ? 'end' : 'start',
		}
	})
}

/**
 * The ground of the sketch map, drawn the same for every block: a river
 * across the lower part, a park and a few streets. It is decoration that
 * says "map" at a glance; it carries no geography, which the caption under
 * the map says in words.
 *
 * @return {{water: string, park: {x: number, y: number, width: number, height: number}, streets: Array<string>}}
 * @spec openspec/changes/archive/2026-11-26-site-location-map/tasks.md#t04
 */
export function sketchGround() {
	const { width, height } = MAP_BOX
	return {
		water: `M0 ${height * 0.78} C ${width * 0.25} ${height * 0.7}, ${width * 0.5} ${height * 0.9}, ${width} ${height * 0.8} L ${width} ${height * 0.9} C ${width * 0.5} ${height}, ${width * 0.25} ${height * 0.8}, 0 ${height * 0.88} Z`,
		park: { x: width * 0.08, y: height * 0.1, width: width * 0.2, height: height * 0.24 },
		streets: [
			`M0 ${height * 0.45} L ${width} ${height * 0.4}`,
			`M${width * 0.38} 0 L ${width * 0.42} ${height}`,
			`M${width * 0.7} 0 L ${width * 0.62} ${height * 0.75}`,
			`M0 ${height * 0.62} L ${width * 0.4} ${height * 0.6}`,
		],
	}
}

/**
 * A route planner link to a location: its coordinates when known, else its
 * address. Opens on OpenStreetMap; nothing is requested from it until the
 * visitor follows the link.
 *
 * @param {{lat: number|null, lon: number|null, address: string}} location The location.
 * @return {string} The address, or '' when there is nothing to route to.
 * @spec openspec/changes/archive/2026-11-26-site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
 */
export function routeHref(location) {
	if (location.lat !== null && location.lon !== null) {
		return `https://www.openstreetmap.org/directions?to=${location.lat}%2C${location.lon}`
	}
	if (location.address !== '') {
		return `https://www.openstreetmap.org/search?query=${encodeURIComponent(location.address)}`
	}
	return ''
}
