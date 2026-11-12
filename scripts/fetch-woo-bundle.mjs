#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// fetch-woo-bundle.mjs: puts the Open Tilburg WOO bundle in woo/.
//
// BundledSiteController serves woo/ on /apps/portaliq/woo. The bundle is the
// production build of ConductionNL/tilburg-woo-ui and is not built here; it is
// a release asset of that repository, pinned by URL and SHA-256 in
// scripts/woo-bundle.json. This script downloads it, refuses it when the
// checksum differs, and unpacks it into woo/. When woo/ already holds the
// pinned archive (woo/.bundle-sha256), it does nothing, so a rebuild needs no
// network.
//
// Usage: node scripts/fetch-woo-bundle.mjs   (also run by `npm run build`)

import { execFileSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { existsSync, mkdirSync, mkdtempSync, readFileSync, renameSync, rmSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const TARGET = join(ROOT, 'woo')
const MARKER = join(TARGET, '.bundle-sha256')
const pin = JSON.parse(readFileSync(join(ROOT, 'scripts', 'woo-bundle.json'), 'utf8'))

/**
 * Whether woo/ already holds the pinned archive.
 *
 * @return {boolean}
 */
function isCurrent() {
	return existsSync(MARKER) && readFileSync(MARKER, 'utf8').trim() === pin.sha256
}

/**
 * Download the pinned archive and check its SHA-256.
 *
 * @return {Promise<Buffer>} The archive.
 */
async function download() {
	const response = await fetch(pin.url)
	if (!response.ok) {
		throw new Error(`${pin.url} answered ${response.status}`)
	}
	const archive = Buffer.from(await response.arrayBuffer())
	const sha256 = createHash('sha256').update(archive).digest('hex')
	if (sha256 !== pin.sha256) {
		throw new Error(`${pin.url} has SHA-256 ${sha256}, scripts/woo-bundle.json pins ${pin.sha256}`)
	}
	return archive
}

/**
 * Unpack the archive into woo/, replacing what was there only once the
 * archive has unpacked completely.
 *
 * @param {Buffer} archive The checked archive.
 */
function install(archive) {
	// Beside woo/, so the final rename stays on one file system.
	const work = mkdtempSync(join(ROOT, '.woo-download-'))
	try {
		const file = join(work, 'bundle.tar.gz')
		const unpacked = join(work, 'woo')
		writeFileSync(file, archive)
		mkdirSync(unpacked)
		execFileSync('tar', ['-xzf', file, '-C', unpacked])
		writeFileSync(join(unpacked, '.bundle-sha256'), pin.sha256 + '\n')
		rmSync(TARGET, { recursive: true, force: true })
		renameSync(unpacked, TARGET)
	} finally {
		rmSync(work, { recursive: true, force: true })
	}
}

if (isCurrent()) {
	console.log(`[fetch-woo-bundle] woo/ already holds ${pin.sha256.slice(0, 12)}`)
} else {
	try {
		install(await download())
		console.log(`[fetch-woo-bundle] woo/ unpacked from ${pin.url}`)
	} catch (error) {
		console.error(`[fetch-woo-bundle] ${error.message}`)
		process.exit(1)
	}
}
