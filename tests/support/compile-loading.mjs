// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Compiles src/portal/components/Loading.jsx next to a test's other compiled
// components, so a component that imports `./Loading.jsx` can be loaded by
// plain node. Every loading indicator in the portal is that component
// (portal-spa-nl-design-system-styling), so a test that compiles one portal
// component usually needs it too.

import babel from '@babel/core'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const SOURCE = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'src', 'portal', 'components', 'Loading.jsx')

/**
 * The compiled file's name, to put in place of `./Loading.jsx`.
 */
export const LOADING_MODULE = './components_Loading.mjs'

/**
 * Compile Loading.jsx into a directory.
 *
 * @param {string} outDir Where the test writes its compiled components.
 * @return {void}
 */
export function compileLoading(outDir) {
	const compiled = babel.transformSync(readFileSync(SOURCE, 'utf8'), {
		filename: SOURCE,
		babelrc: false,
		configFile: false,
		presets: [['@babel/preset-react', { runtime: 'automatic' }]],
	})
	mkdirSync(outDir, { recursive: true })
	writeFileSync(join(outDir, 'components_Loading.mjs'), compiled.code)
}
