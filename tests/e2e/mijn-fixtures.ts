/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The portalPage records the mijn omgeving specs seed, from
 * fixtures/mijn-omgeving-pages.json, with this run's stamp and audience
 * filled in. The same file is validated against the real portalPage schema
 * by tests/Unit/Settings/PortalPageSchemaTest.php, so a seed the register
 * would refuse, or a key it would drop, fails in PHPUnit first.
 */

import { readFileSync } from 'node:fs'
import { join } from 'node:path'

const FIXTURES = JSON.parse(
	readFileSync(join(__dirname, 'fixtures', 'mijn-omgeving-pages.json'), 'utf8'),
) as Record<string, unknown>

/**
 * Fill `{stamp}` and `{audience}` in every string of a value.
 *
 * @param value The value.
 * @param stamp This run's stamp.
 * @param audience The audience to seed for.
 * @return The filled copy.
 */
function fill(value: unknown, stamp: number, audience: string): unknown {
	if (typeof value === 'string') {
		return value
			.split('{stamp}')
			.join(String(stamp))
			.split('{audience}')
			.join(audience)
	}
	if (Array.isArray(value)) {
		return value.map((item) => fill(item, stamp, audience))
	}
	if (value !== null && typeof value === 'object') {
		return Object.fromEntries(
			Object.entries(value).map(([key, item]) => [
				key,
				fill(item, stamp, audience),
			]),
		)
	}
	return value
}

/**
 * One seeded portalPage record.
 *
 * @param name The fixture's name.
 * @param stamp This run's stamp.
 * @param audience The audience to seed for.
 * @return The record to post.
 */
export function pageFixture(
	name: string,
	stamp: number,
	audience: string,
): Record<string, unknown> {
	const fixture = FIXTURES[name]
	if (!fixture) {
		throw new Error(`No portalPage fixture named ${name}`)
	}
	return fill(fixture, stamp, audience) as Record<string, unknown>
}
