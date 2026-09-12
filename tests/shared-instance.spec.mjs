#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// The guard that decides whether this suite may touch the instance it is aimed
// at, tested without an instance.
//
// PORTED FROM hydra/templates/e2e/shared-instance.test.ts.tmpl, which is
// written for vitest. Portaliq has no vitest: its JS unit tests are
// `node --test tests/*.spec.mjs` scripts chained into `npm run check:specs`,
// which is what the Spec Validation workflow runs. Same cases, `node:test` and
// `node:assert` instead of `describe`/`expect`. Node 24 imports the `.ts` guard
// directly by stripping its types, and both this repo's Spec Validation job and
// the shared quality workflow pin node 24, so no build step is involved.
//
// Worth testing rather than reading, because every case here is one somebody
// already got wrong: `http://127.0.0.1` parses with an EMPTY port, so a port
// comparison that trusts `URL.port` misses the shared instance written without
// one, and a flag holding `1` used to permit every shared instance the suite
// ever met.

import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
	APP_ID,
	assertInstancePermitted,
	isSharedOrigin,
	normaliseOrigin,
	occPrefix,
	SHARED_INSTANCE_FLAG,
} from './e2e/shared-instance.ts'

/** An environment with neither flag set. */
const NONE = {}

describe(`${APP_ID} shared-instance guard`, () => {
	it('folds every loopback spelling onto localhost', () => {
		assert.equal(normaliseOrigin('http://127.0.0.1:8080'), 'http://localhost:8080')
		assert.equal(normaliseOrigin('http://[::1]:8080'), 'http://localhost:8080')
		assert.equal(normaliseOrigin('http://localhost:8080/'), 'http://localhost:8080')
	})

	it('makes the implicit port explicit', () => {
		assert.equal(normaliseOrigin('http://127.0.0.1'), 'http://localhost:80')
		assert.equal(normaliseOrigin('https://example.org'), 'https://example.org:443')
	})

	it('calls loopback 80 and 8080 shared, and nothing else', () => {
		assert.equal(isSharedOrigin('http://localhost:8080'), true)
		assert.equal(isSharedOrigin('http://127.0.0.1'), true)
		assert.equal(isSharedOrigin('http://localhost:8095'), false)
		assert.equal(isSharedOrigin('http://nextcloud.example.org:8080'), false)
	})

	it('refuses a shared instance that no flag names', () => {
		assert.throws(
			() => assertInstancePermitted('http://localhost:8080', NONE),
			/SHARED development instance/,
		)
	})

	it('refuses a flag holding a boolean rather than an origin', () => {
		assert.throws(() =>
			assertInstancePermitted('http://localhost:8080', {
				[SHARED_INSTANCE_FLAG]: '1',
			}),
		)
	})

	it('refuses a flag that names a different origin', () => {
		assert.throws(() =>
			assertInstancePermitted('http://localhost:8080', {
				[SHARED_INSTANCE_FLAG]: 'http://localhost:80',
			}),
		)
	})

	it('permits the origin the flag names, in any loopback spelling', () => {
		assert.equal(
			assertInstancePermitted('http://localhost:8080', {
				[SHARED_INSTANCE_FLAG]: 'http://127.0.0.1:8080',
			}),
			'http://localhost:8080',
		)
	})

	it('accepts the fleet-wide spelling too', () => {
		assert.equal(
			assertInstancePermitted('http://localhost:8080', {
				E2E_ALLOW_SHARED_INSTANCE: 'http://localhost:8080',
			}),
			'http://localhost:8080',
		)
	})

	it('lets a disposable rig through without a flag', () => {
		assert.equal(
			assertInstancePermitted('http://localhost:8095', NONE),
			'http://localhost:8095',
		)
	})

	it('binds occ to a named container, and falls back to the server root', () => {
		assert.equal(occPrefix(NONE).join(' '), 'php occ')
		assert.equal(
			occPrefix({ NEXTCLOUD_CONTAINER: 'nextcloud' }).join(' '),
			'docker exec -u www-data nextcloud php occ',
		)
	})
})
