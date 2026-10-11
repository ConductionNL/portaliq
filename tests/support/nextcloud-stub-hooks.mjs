// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Node resolve hook: the same swap webpack.site.js makes for the public
// bundles. When a module inside nextcloud-vue's `src/utils` imports
// @nextcloud/capabilities, /router, /auth or /axios, it gets
// src/site/lib/nextcloudStub.js instead. Without it, mounting the intake form
// or the embed form in node loads @nextcloud/axios, which reads the request
// token from `document` on import and throws. mount-sfc.mjs registers it with
// the synchronous, in-thread `module.registerHooks` (node 22.15+): the
// off-thread `module.register` slowed every later dynamic import enough that a
// lazy dialog missed a spec's `flush()`.

import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const STUB = pathToFileURL(
	join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'src', 'site', 'lib', 'nextcloudStub.js'),
).href

const PACKAGES = /^@nextcloud\/(capabilities|router|auth|axios)$/
const UTILS = /@conduction[\\/]nextcloud-vue[\\/]src[\\/]utils[\\/]/

/**
 * Resolve a stubbed package to the stub for importers inside nextcloud-vue's utils.
 *
 * @param {string} specifier The import specifier.
 * @param {object} context The resolve context.
 * @param {Function} nextResolve The next resolver.
 * @return {object|Promise<object>} The resolution.
 */
export function resolve(specifier, context, nextResolve) {
	if (PACKAGES.test(specifier) && UTILS.test(context.parentURL || '')) {
		return { url: STUB, shortCircuit: true }
	}
	return nextResolve(specifier, context)
}
