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
	<section
		class="nl-location-map"
		:aria-labelledby="headingId"
		data-testid="nl-location-map">
		<p v-if="intro" class="utrecht-paragraph nl-location-map__intro">
			{{ intro }}
		</p>
		<div
			v-if="chips.length > 0"
			class="nl-location-map__chips"
			role="group"
			:aria-label="filterLabel">
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
					<rect
						:width="box.width"
						:height="box.height"
						class="nl-location-map__ground" />
					<g
						v-for="marker in markers"
						:key="marker.number"
						:transform="`translate(${marker.x} ${marker.y})`">
						<circle r="16" class="nl-location-map__marker" />
						<text
							text-anchor="middle"
							dominant-baseline="central"
							class="nl-location-map__marker-number">
							{{ marker.number }}
						</text>
					</g>
				</svg>
				<div class="nl-location-map__zoom">
					<button
						type="button"
						class="utrecht-button"
						:aria-label="zoomInLabel"
						:disabled="zoom >= 3"
						@click="zoom += 1">
						+
					</button>
					<button
						type="button"
						class="utrecht-button"
						:aria-label="zoomOutLabel"
						:disabled="zoom <= 1"
						@click="zoom -= 1">
						−
					</button>
				</div>
			</div>
			<div class="nl-location-map__list">
				<h2 :id="headingId" class="utrecht-heading-2">
					{{ headingText }}
				</h2>
				<ol class="nl-location-map__places">
					<li
						v-for="place in shown"
						:key="place.number"
						class="nl-location-map__place">
						<span class="nl-location-map__number" aria-hidden="true">{{
							place.number
						}}</span>
						<div>
							<h3 class="utrecht-heading-3">
								{{ place.name }}
							</h3>
							<p v-if="place.address" class="utrecht-paragraph">
								{{ place.address }}
							</p>
							<p
								v-if="place.opening"
								class="utrecht-paragraph nl-location-map__opening">
								{{ place.opening }}
							</p>
							<ul class="utrecht-link-list nl-location-map__links">
								<li
									v-if="route(place)"
									class="utrecht-link-list__item">
									<a
										class="utrecht-link"
										:href="route(place)"
										target="_blank"
										rel="noopener noreferrer"
										>{{ routeLabel
										}}<span class="nl-location-map__hidden"
											>, {{ place.name }},
											{{ newWindowLabel }}</span
										></a
									>
								</li>
								<li
									v-for="link in place.links"
									:key="link.href"
									class="utrecht-link-list__item">
									<a class="utrecht-link" :href="link.href">{{
										link.label
									}}</a>
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
import {
	categoryChips,
	MAP_BOX,
	markerPositions,
	normaliseLocations,
	routeHref,
} from './locationMap.js'

import '@utrecht/button-css/dist/index.css'
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
		mapLabel: {
			type: String,
			default: 'Kaart met de genummerde locaties uit de lijst',
		},

		zoomInLabel: { type: String, default: 'Inzoomen' },
		zoomOutLabel: { type: String, default: 'Uitzoomen' },
		routeLabel: { type: String, default: 'Route' },
		newWindowLabel: { type: String, default: 'opent in een nieuw venster' },
		routeNote: {
			type: String,
			default: 'Route opent een routeplanner in een nieuw venster.',
		},
	},

	/**
	 * @return {{headingId: string, chosen: string, zoom: number}} The state.
	 * @spec openspec/changes/site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
	 */
	data() {
		instances += 1
		return { headingId: `nl-location-map-${instances}`, chosen: '', zoom: 1 }
	},

	computed: {
		/**
		 * @return {Array<object>} Every authored place, made safe.
		 * @spec openspec/changes/site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
		 */
		places() {
			return normaliseLocations(this.locations)
		},

		/**
		 * @return {Array<object>} The places of the chosen kind.
		 * @spec openspec/changes/site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
		 */
		shown() {
			return this.chosen === ''
				? this.places
				: this.places.filter((place) => place.category === this.chosen)
		},

		/**
		 * @return {Array<{value: string, label: string}>} The chips.
		 * @spec openspec/changes/site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
		 */
		chips() {
			return categoryChips(this.places, this.allLabel)
		},

		/**
		 * @return {Array<{number: number, x: number, y: number}>} The markers of the places shown.
		 * @spec openspec/changes/site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
		 */
		markers() {
			const shown = new Set(this.shown.map((place) => place.number))
			return markerPositions(this.places).filter((marker) =>
				shown.has(marker.number),
			)
		},

		/**
		 * @return {object} The drawing box.
		 * @spec openspec/changes/site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
		 */
		box() {
			return MAP_BOX
		},

		/**
		 * The part of the drawing in view: the whole box, or its middle at a
		 * closer zoom.
		 *
		 * @return {string}
		 * @spec openspec/changes/site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
		 */
		viewBox() {
			const width = MAP_BOX.width / this.zoom
			const height = MAP_BOX.height / this.zoom
			return `${(MAP_BOX.width - width) / 2} ${(MAP_BOX.height - height) / 2} ${width} ${height}`
		},

		/**
		 * @return {string} The heading with the number of places shown.
		 * @spec openspec/changes/site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
		 */
		headingText() {
			return this.heading.replace('{count}', String(this.shown.length))
		},

		/**
		 * @return {boolean} Whether any place has a route link.
		 * @spec openspec/changes/site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
		 */
		hasRoute() {
			return this.shown.some((place) => routeHref(place) !== '')
		},
	},

	methods: {
		/**
		 * @param {object} place A place.
		 * @return {string} Its route planner link, or ''.
		 * @spec openspec/changes/site-location-map/specs/portaliq-cms/spec.md#requirement-a-page-can-show-the-organisations-locations-on-a-map-req-slm-001
		 */
		route(place) {
			return routeHref(place)
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
	border-radius: var(
		--nldesign-website-border-radius-large,
		var(--utrecht-border-radius-md, 0.75rem)
	);
}

.nl-location-map__ground {
	fill: var(--nldesign-color-primary-light, var(--utrecht-color-grey-90, Canvas));
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
	list-style: none;
	margin: 0;
	padding: 0;
}

.nl-location-map__place {
	display: grid;
	grid-template-columns: 2rem minmax(0, 1fr);
	gap: 0.75rem;
	padding-block: 1rem;
	border-block-end: 1px solid
		var(
			--nldesign-color-border-light,
			var(--utrecht-color-grey-80, currentcolor)
		);
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
