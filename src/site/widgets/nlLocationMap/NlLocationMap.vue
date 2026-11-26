<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The organisation's places: chips per kind of place, a sketch map with a
	numbered marker per place, and the same numbers in the list beside it
	(board LocatiesKaart). The list carries every fact, so the map is an
	extra for sighted visitors and never the only way to a place. The sketch
	is drawn from the authored coordinates; nothing is loaded from a map
	service. "Route" opens a route planner in a new window.
-->
<template>
	<section class="nl-location-map" :aria-labelledby="headingId" data-testid="nl-location-map">
		<p v-if="intro" class="utrecht-paragraph nl-location-map__intro">
			{{ intro }}
		</p>
		<div v-if="chips.length > 0" class="nl-location-map__chips" role="group" :aria-label="filterLabel">
			<button
				v-for="chip in chips"
				:key="chip.value"
				type="button"
				class="utrecht-button nl-location-map__chip"
				:class="{ 'nl-location-map__chip--on': chosen === chip.value }"
				:aria-pressed="chosen === chip.value ? 'true' : 'false'"
				@click="chosen = chip.value">
				{{ chip.label }}
			</button>
		</div>
		<div class="nl-location-map__body">
			<div v-if="markers.length > 0" class="nl-location-map__map">
				<svg
					:viewBox="viewBox"
					role="img"
					:aria-label="mapLabel"
					class="nl-location-map__sketch"
					focusable="false">
					<rect :width="box.width" :height="box.height" class="nl-location-map__ground" />
					<rect
						:x="ground.park.x"
						:y="ground.park.y"
						:width="ground.park.width"
						:height="ground.park.height"
						rx="18"
						class="nl-location-map__park" />
					<path :d="ground.water" class="nl-location-map__water" />
					<path
						v-for="(street, index) in ground.streets"
						:key="`street-${index}`"
						:d="street"
						class="nl-location-map__street" />
					<g v-for="marker in markers" :key="marker.number" :transform="`translate(${marker.x} ${marker.y})`">
						<circle r="16" class="nl-location-map__marker" />
						<text text-anchor="middle" dominant-baseline="central" class="nl-location-map__marker-number">
							{{ marker.number }}
						</text>
					</g>
					<text
						v-for="label in labels"
						:key="`label-${label.number}`"
						:x="label.x"
						:y="label.y"
						:text-anchor="label.anchor"
						dominant-baseline="central"
						class="nl-location-map__marker-name">
						{{ label.name }}
					</text>
				</svg>
				<p class="nl-location-map__caption">
					{{ sketchCaption }}
				</p>
				<div class="nl-location-map__zoom">
					<button type="button" class="utrecht-button" :aria-label="zoomInLabel" :disabled="zoom >= 3" @click="zoom += 1">
						+
					</button>
					<button type="button" class="utrecht-button" :aria-label="zoomOutLabel" :disabled="zoom <= 1" @click="zoom -= 1">
						−
					</button>
				</div>
			</div>
			<div class="nl-location-map__list">
				<h2 :id="headingId" class="utrecht-heading-2">
					{{ headingText }}
				</h2>
				<ol class="nl-location-map__places">
					<li v-for="place in shown" :key="place.number" class="nl-location-map__place">
						<span class="nl-location-map__number" aria-hidden="true">{{ place.number }}</span>
						<div>
							<h3 class="utrecht-heading-3">
								{{ place.name }}
							</h3>
							<p v-if="place.address" class="utrecht-paragraph">
								{{ place.address }}
							</p>
							<p v-if="place.opening" class="utrecht-paragraph nl-location-map__opening">
								{{ place.opening }}
							</p>
							<p
								v-if="openLine(place)"
								class="utrecht-paragraph nl-location-map__open-now"
								:class="{ 'nl-location-map__open-now--closed': !isOpen(place) }"
								data-testid="nl-location-map-open-now">
								{{ openLine(place) }}
							</p>
							<a
								v-if="route(place)"
								class="utrecht-button-link utrecht-button-link--html-a utrecht-button-link--secondary-action nl-location-map__route"
								:href="route(place)"
								target="_blank"
								rel="noopener noreferrer">
								<svg
									class="nl-location-map__route-icon"
									viewBox="0 0 24 24"
									aria-hidden="true"
									focusable="false">
									<circle cx="6" cy="18" r="2.5" />
									<circle cx="18" cy="6" r="2.5" />
									<path d="M8.5 18H15a3 3 0 0 0 0-6H9a3 3 0 0 1 0-6h6.5" />
								</svg>
								{{ routeLabel }}<span class="nl-location-map__hidden">, {{ place.name }}, {{ newWindowLabel }}</span>
							</a>
							<ul v-if="place.links.length > 0" class="utrecht-link-list nl-location-map__links">
								<li v-for="link in place.links" :key="link.href" class="utrecht-link-list__item">
									<a class="utrecht-link" :href="addressFor(link.href)">{{ link.label }}</a>
								</li>
							</ul>
						</div>
					</li>
				</ol>
				<p v-if="hasRoute" class="utrecht-paragraph nl-location-map__note">
					{{ routeNote }}
				</p>
			</div>
		</div>
	</section>
</template>

<script>
import { runtimeConfig } from '../../lib/contentApi.js'
import { linkAddress } from '../../lib/shellData.js'
import { addressOfRoute } from '../../lib/siteAddress.js'
import {
	categoryChips,
	MAP_BOX,
	markerLabels,
	markerPositions,
	normaliseLocations,
	openUntil,
	routeHref,
	sketchGround,
} from './locationMap.js'

import '@utrecht/button-css/dist/index.css'
import '@utrecht/button-link-css/dist/index.css'
import '@utrecht/heading-2-css/dist/index.css'
import '@utrecht/heading-3-css/dist/index.css'
import '@utrecht/link-css/dist/index.css'
import '@utrecht/link-list-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

/** A number per mounted block, so every heading id on the page is unique. */
let instances = 0

export default {
	name: 'NlLocationMap',

	props: {
		/** The heading above the list; `{count}` becomes the number shown. */
		heading: { type: String, default: '{count} locaties' },
		/** A line above the chips and the map. */
		intro: { type: String, default: '' },
		/** The places: `{name, address, opening, category, lat, lon, links}`. */
		locations: { type: Array, default: () => [] },
		/** The chip that shows every place. */
		allLabel: { type: String, default: 'Alle locaties' },
		/** The words the block reads out, for a portal in another language. */
		filterLabel: { type: String, default: 'Soort locatie' },
		mapLabel: { type: String, default: 'Kaart met de genummerde locaties uit de lijst' },
		zoomInLabel: { type: String, default: 'Inzoomen' },
		zoomOutLabel: { type: String, default: 'Uitzoomen' },
		routeLabel: { type: String, default: 'Route' },
		newWindowLabel: { type: String, default: 'opent in een nieuw venster' },
		routeNote: { type: String, default: 'Route opent een routeplanner in een nieuw venster.' },
		/** The line under the sketch: it is no real map. */
		sketchCaption: { type: String, default: 'Kaart: schets, geen echte kaartgegevens' },
		/** The open-now line; `{time}` becomes the closing time. */
		openUntilLabel: { type: String, default: 'Nu open tot {time} uur' },
		closedLabel: { type: String, default: 'Nu gesloten' },
	},

	/**
	 * The state. `now` stays null until the block runs in the browser, so a
	 * page drawn without JavaScript shows no open-now line that could be
	 * stale.
	 *
	 * @return {{headingId: string, chosen: string, zoom: number, now: Date|null}} The state.
	 * @spec openspec/changes/archive/2026-11-26-site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
	 * @spec openspec/changes/archive/2026-11-26-site-location-map/tasks.md#t04
	 */
	data() {
		instances += 1
		return { headingId: `nl-location-map-${instances}`, chosen: '', zoom: 1, now: null }
	},

	computed: {
		/**
		 * @return {Array<object>} Every authored place, made safe.
		 * @spec openspec/changes/archive/2026-11-26-site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
		 */
		places() {
			return normaliseLocations(this.locations)
		},

		/**
		 * @return {Array<object>} The places of the chosen kind.
		 * @spec openspec/changes/archive/2026-11-26-site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
		 */
		shown() {
			return this.chosen === '' ? this.places : this.places.filter((place) => place.category === this.chosen)
		},

		/**
		 * @return {Array<{value: string, label: string}>} The chips.
		 * @spec openspec/changes/archive/2026-11-26-site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
		 */
		chips() {
			return categoryChips(this.places, this.allLabel)
		},

		/**
		 * @return {Array<{number: number, x: number, y: number}>} The markers of the places shown.
		 * @spec openspec/changes/archive/2026-11-26-site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
		 */
		markers() {
			const shown = new Set(this.shown.map((place) => place.number))
			return markerPositions(this.places).filter((marker) => shown.has(marker.number))
		},

		/**
		 * @return {Array<object>} The name beside each marker shown.
		 * @spec openspec/changes/archive/2026-11-26-site-location-map/tasks.md#t04
		 */
		labels() {
			return markerLabels(this.markers, this.places)
		},

		/**
		 * @return {object} The streets, water and park of the sketch.
		 * @spec openspec/changes/archive/2026-11-26-site-location-map/tasks.md#t04
		 */
		ground() {
			return sketchGround()
		},

		/**
		 * @return {object} The drawing box.
		 * @spec openspec/changes/archive/2026-11-26-site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
		 */
		box() {
			return MAP_BOX
		},

		/**
		 * The part of the drawing in view: the whole box, or its middle at a
		 * closer zoom.
		 *
		 * @return {string}
		 * @spec openspec/changes/archive/2026-11-26-site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
		 */
		viewBox() {
			const width = MAP_BOX.width / this.zoom
			const height = MAP_BOX.height / this.zoom
			return `${(MAP_BOX.width - width) / 2} ${(MAP_BOX.height - height) / 2} ${width} ${height}`
		},

		/**
		 * @return {string} The heading with the number of places shown.
		 * @spec openspec/changes/archive/2026-11-26-site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
		 */
		headingText() {
			return this.heading.replace('{count}', String(this.shown.length))
		},

		/**
		 * @return {boolean} Whether any place has a route link.
		 * @spec openspec/changes/archive/2026-11-26-site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
		 */
		hasRoute() {
			return this.shown.some((place) => routeHref(place) !== '')
		},
	},

	/**
	 * Reads the clock once the block is in the browser.
	 *
	 * @return {void}
	 * @spec openspec/changes/archive/2026-11-26-site-location-map/tasks.md#t04
	 */
	mounted() {
		this.now = new Date()
	},

	methods: {
		/**
		 * @param {object} place A place.
		 * @return {boolean} Whether its hours hold the current moment.
		 * @spec openspec/changes/archive/2026-11-26-site-location-map/tasks.md#t04
		 */
		isOpen(place) {
			return this.now !== null && typeof openUntil(place.hours, this.now) === 'string'
		},

		/**
		 * @param {object} place A place.
		 * @return {string} "Nu open tot 17:00 uur", "Nu gesloten", or '' without hours or a clock.
		 * @spec openspec/changes/archive/2026-11-26-site-location-map/tasks.md#t04
		 */
		openLine(place) {
			if (this.now === null) {
				return ''
			}
			const until = openUntil(place.hours, this.now)
			if (until === null) {
				return ''
			}
			return until === false ? this.closedLabel : this.openUntilLabel.replace('{time}', until)
		},

		/**
		 * @param {object} place A place.
		 * @return {string} Its route planner link, or ''.
		 * @spec openspec/changes/archive/2026-11-26-site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
		 */
		route(place) {
			return routeHref(place)
		},

		/**
		 * A place's own link in the site's own addressing: under the app path
		 * a bare `/afspraak-maken` leaves the site (seen live on the Zuiddrecht
		 * rehearsal, 25 Nov 2026). Another link stays as authored.
		 *
		 * @param {string} href The authored destination.
		 * @return {string} The href.
		 * @spec openspec/specs/site-navigation/spec.md#requirement-a-block-link-to-a-site-page-carries-the-sites-own-address
		 */
		addressFor(href) {
			if (typeof window === 'undefined') {
				return href
			}
			return linkAddress(href, (route) =>
				addressOfRoute(
					route,
					window.location.href,
					runtimeConfig(),
				).toString(),
			)
		},
	},
}
</script>

<style scoped>
/* Layout and theme tokens only. */
.nl-location-map__intro {
	margin: 0 0 1rem;
}

.nl-location-map__chips {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
	margin-block-end: 1.25rem;
}

.nl-location-map__chip {
	min-block-size: 2.75rem;
	border-radius: 999px;
}

.nl-location-map__chip--on {
	background: var(--nldesign-color-primary, LinkText);
	color: var(--nldesign-color-primary-contrast, Canvas);
}

.nl-location-map__body {
	display: grid;
	grid-template-columns: minmax(0, 3fr) minmax(0, 2fr);
	gap: 2rem;
}

@media (max-width: 48rem) {
	.nl-location-map__body {
		grid-template-columns: minmax(0, 1fr);
	}
}

.nl-location-map__map {
	position: relative;
}

.nl-location-map__sketch {
	display: block;
	inline-size: 100%;
	block-size: auto;
	border-radius: var(--nldesign-website-border-radius-large, var(--utrecht-border-radius-md, 0.75rem));
}

.nl-location-map__ground {
	fill: var(--nldesign-color-primary-light, var(--utrecht-color-grey-90, Canvas));
}

/* The sketch's ground (T04): park, water and streets, theme tokens only. */
.nl-location-map__park {
	fill: var(--nldesign-color-success-light, var(--utrecht-color-green-90, Canvas));
}

.nl-location-map__water {
	fill: var(--nldesign-color-info-light, var(--utrecht-color-blue-90, Canvas));
}

.nl-location-map__street {
	fill: none;
	stroke: var(--utrecht-document-background-color, Canvas);
	stroke-width: 10;
	stroke-linecap: round;
}

.nl-location-map__marker-name {
	fill: var(--utrecht-document-color, CanvasText);
	stroke: var(--utrecht-document-background-color, Canvas);
	stroke-width: 4;
	paint-order: stroke;
	font-weight: 700;
	font-size: 14px;
}

.nl-location-map__caption {
	margin: 0.5rem 0 0;
	font-size: 0.875rem;
	color: var(--utrecht-document-color, CanvasText);
}

.nl-location-map__marker {
	fill: var(--nldesign-color-primary, LinkText);
	stroke: var(--nldesign-color-primary-contrast, Canvas);
	stroke-width: 3;
}

.nl-location-map__marker-number {
	fill: var(--nldesign-color-primary-contrast, Canvas);
	font-weight: 700;
	font-size: 16px;
}

.nl-location-map__zoom {
	position: absolute;
	inset-block-start: 0.75rem;
	inset-inline-end: 0.75rem;
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.nl-location-map__zoom .utrecht-button {
	min-inline-size: 2.75rem;
	min-block-size: 2.75rem;
}

.nl-location-map__list h2 {
	margin-block-start: 0;
}

.nl-location-map__places {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	list-style: none;
	margin: 0;
	padding: 0;
}

/* Each place is a bordered card, as the board draws it (T04). */
.nl-location-map__place {
	display: grid;
	grid-template-columns: 2rem minmax(0, 1fr);
	gap: 0.75rem;
	padding: 1rem;
	border: 1px solid var(--nldesign-color-border-light, var(--utrecht-color-grey-80, currentcolor));
	border-radius: var(--nldesign-website-border-radius-large, var(--utrecht-border-radius-md, 0.75rem));
	background: var(--utrecht-document-background-color, Canvas);
}

.nl-location-map__open-now {
	font-weight: 700;
	color: var(--nldesign-color-success, var(--utrecht-color-green-30, CanvasText));
}

.nl-location-map__open-now--closed {
	color: var(--utrecht-document-color, CanvasText);
}

.nl-location-map__route {
	display: inline-flex;
	align-items: center;
	gap: 0.5rem;
	margin-block-start: 0.5rem;
}

.nl-location-map__route-icon {
	inline-size: 1.25rem;
	block-size: 1.25rem;
	fill: none;
	stroke: currentcolor;
	stroke-width: 2;
	stroke-linecap: round;
	stroke-linejoin: round;
}

.nl-location-map__number {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	inline-size: 2rem;
	block-size: 2rem;
	border-radius: 50%;
	background: var(--nldesign-color-primary, LinkText);
	color: var(--nldesign-color-primary-contrast, Canvas);
	font-weight: 700;
}

.nl-location-map__place h3,
.nl-location-map__place p {
	margin: 0 0 0.25rem;
}

.nl-location-map__links {
	display: flex;
	flex-wrap: wrap;
	gap: 0.25rem 1.25rem;
	margin: 0.25rem 0 0;
}

/*
 * A place's links (Route, Afspraak maken) are 24px targets. As bare text they
 * were 19px high with less than 24px between them, and WCAG 2.2 SC 2.5.8
 * (target size, minimum) asks 24 by 24 CSS pixels or that much space; axe
 * flagged every one on the Zuiddrecht Locaties page.
 * @spec openspec/changes/archive/2026-11-26-site-location-map/tasks.md#t03
 */
.nl-location-map__links .utrecht-link {
	display: inline-flex;
	align-items: center;
	min-block-size: 24px;
}

.nl-location-map__hidden {
	position: absolute;
	inline-size: 1px;
	block-size: 1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
}

.nl-location-map__note {
	margin-block-start: 1rem;
}
</style>
