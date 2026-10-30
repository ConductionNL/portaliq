/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The data behind the location map block: the authored locations made safe,
 * the filter chips, a marker position on the sketch map and the route link.
 * No imports, so tests/site-location-map.spec.mjs runs it in node.
 *
 * @spec openspec/changes/site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
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
	return value !== ''
		&& value !== null
		&& Number.isFinite(number)
		&& number >= min
		&& number <= max
		? number
		: null
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
		.map((link) => ({
			label: String(link?.label || '').trim(),
			href: String(link?.href || '').trim(),
		}))
		.filter(
			(link) =>
				link.label !== ''
				&& /^(\/(?!\/)|https?:\/\/|mailto:|tel:)/i.test(link.href),
		)
}

/**
 * The locations as the block draws them: a name is required, everything
 * else is optional, and each gets its number in the order authored.
 *
 * @param {unknown} locations The authored locations.
 * @return {Array<{number: number, name: string, address: string, opening: string, category: string, lat: number|null, lon: number|null, links: Array<{label: string, href: string}>}>}
 * @spec openspec/changes/site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
 */
export function normaliseLocations(locations) {
	return (Array.isArray(locations) ? locations : [])
		.filter((location) => location && String(location.name || '').trim() !== '')
		.map((location, index) => ({
			number: index + 1,
			name: String(location.name).trim(),
			address: String(location.address || '').trim(),
			opening: String(location.opening || '').trim(),
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
 * @spec openspec/changes/site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
 */
export function categoryChips(locations, allLabel) {
	const categories = [
		...new Set(
			locations
				.map((location) => location.category)
				.filter((category) => category !== ''),
		),
	]
	if (categories.length < 2) {
		return []
	}
	return [
		{ value: '', label: allLabel },
		...categories.map((category) => ({ value: category, label: category })),
	]
}

/**
 * Where each located marker sits on the sketch map: the box around all
 * located places, scaled into MAP_BOX with its margin, north up. One place
 * sits in the middle.
 *
 * @param {Array<{number: number, lat: number|null, lon: number|null}>} locations The normalised locations.
 * @return {Array<{number: number, x: number, y: number}>}
 * @spec openspec/changes/site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
 */
export function markerPositions(locations) {
	const located = locations.filter(
		(location) => location.lat !== null && location.lon !== null,
	)
	if (located.length === 0) {
		return []
	}
	const lats = located.map((location) => location.lat)
	const lons = located.map((location) => location.lon)
	const [south, north, west, east] = [
		Math.min(...lats),
		Math.max(...lats),
		Math.min(...lons),
		Math.max(...lons),
	]
	const innerWidth = MAP_BOX.width - 2 * MAP_BOX.margin
	const innerHeight = MAP_BOX.height - 2 * MAP_BOX.margin
	const span = (low, high) => high - low || 1
	return located.map((location) => ({
		number: location.number,
		x: Math.round(
			east === west
				? MAP_BOX.width / 2
				: MAP_BOX.margin
						+ ((location.lon - west) / span(west, east)) * innerWidth,
		),
		y: Math.round(
			north === south
				? MAP_BOX.height / 2
				: MAP_BOX.margin
						+ ((north - location.lat) / span(south, north))
							* innerHeight,
		),
	}))
}

/**
 * A route planner link to a location: its coordinates when known, else its
 * address. Opens on OpenStreetMap; nothing is requested from it until the
 * visitor follows the link.
 *
 * @param {{lat: number|null, lon: number|null, address: string}} location The location.
 * @return {string} The address, or '' when there is nothing to route to.
 * @spec openspec/changes/site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
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
