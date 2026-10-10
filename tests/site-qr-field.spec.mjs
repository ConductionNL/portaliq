#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-qr-field.spec.mjs: a `qr` column draws a link as a QR code in the
// browser (link-field-qr-code). The first test reads the drawn code back to
// text, so it proves what the code says, not that an SVG exists.
//
// Usage:
//   node --test tests/site-qr-field.spec.mjs
//
// @spec openspec/changes/link-field-qr-code/specs/portal-contribution-contract/spec.md

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { qrHref, qrLabel } from '../src/site/components/collections/cells.js'
import { BLOCKS_M, byteCapacity, encodeQr, formatBits, reedSolomon, TOTAL_CODEWORDS, versionBits } from '../src/site/lib/qr.js'
import { decodeQr } from './support/qr-decode.mjs'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

const CODE = 'src/site/components/collections/QrCode.vue'
const VALUE = 'src/site/components/collections/QrValue.vue'
const TABLE = 'src/site/components/collections/CollectionTable.vue'
const CARD = 'src/site/components/collections/DetailCard.vue'
const OFFER = 'openid-credential-offer://?credential_offer_uri=https%3A%2F%2Fissuer.example%2Foffers%2F8f2c1d'

/**
 * The modules of a drawn SVG, read back from its path.
 *
 * @param {string} html The rendered SVG.
 * @return {Array<Array<boolean>>} The modules.
 */
function modulesOf(html) {
	const side = Number(/viewBox="0 0 (\d+) \d+"/i.exec(html)[1]) - 8
	const modules = Array.from({ length: side }, () => new Array(side).fill(false))
	for (const [, x, y, run] of html.matchAll(/M(\d+) (\d+)h(\d+)v1h-\d+z/g)) {
		for (let i = 0; i < Number(run); i++) {
			modules[Number(y) - 4][Number(x) - 4 + i] = true
		}
	}
	return modules
}

test('a wallet offer is drawn, and the drawn code reads back as the offer', async () => {
	const html = await renderSfc(CODE, { value: OFFER, label: 'Add to wallet' })
	const decoded = decodeQr(modulesOf(html))
	assert.equal(decoded.text, OFFER, 'the code says the offer')
	assert.equal(decoded.blocksHold, true, 'every block holds under its error correction')
	assert.equal(decoded.copiesAgree, true, 'both copies of the format bits agree')
	assert.equal(qrHref(OFFER, 'https://portaal.example'), OFFER)
	assert.equal(qrHref('openid4vp://?request_uri=https%3A%2F%2Fv.example%2Fr', ''), 'openid4vp://?request_uri=https%3A%2F%2Fv.example%2Fr')
})

test('the encoder reads back for every size, and its tables and vectors are the standard\'s', () => {
	for (const length of [1, 14, 15, 26, 27, 42, 43, 62, 84, 106, 122, 152, 180, 213, 214, 400, 666]) {
		const text = Array.from({ length }, (_, i) => String.fromCharCode(33 + ((i * 7) % 90))).join('')
		const symbol = encodeQr(text)
		assert.ok(symbol, `${length} bytes encode`)
		const decoded = decodeQr(symbol.modules)
		assert.deepEqual([decoded.text === text, decoded.blocksHold, decoded.copiesAgree], [true, true, true], `${length} bytes (version ${symbol.version})`)
		assert.ok(byteCapacity(symbol.version) >= length && (symbol.version === 1 || byteCapacity(symbol.version - 1) < length), `the smallest version holds ${length}`)
	}
	assert.equal(encodeQr('x'.repeat(667)), null, 'too long for version 20 is no code')
	assert.equal(encodeQr(''), null)
	assert.equal(decodeQr(encodeQr('Zoë €5 — ✓').modules).text, 'Zoë €5 — ✓', 'any Unicode')
	TOTAL_CODEWORDS.forEach((total, index) => {
		const [ec, groups] = BLOCKS_M[index]
		assert.equal(groups.reduce((sum, [count, data]) => sum + count * (data + ec), 0), total, `version ${index + 1} adds up`)
	})
	// Vectors from the standard's worked examples.
	assert.deepEqual(reedSolomon([32, 91, 11, 120, 209, 114, 220, 77, 67, 64, 236, 17, 236, 17, 236, 17], 10), [196, 35, 39, 119, 235, 215, 231, 226, 93, 23])
	assert.equal(formatBits(0).toString(2).padStart(15, '0'), '101010000010010')
	assert.equal(versionBits(7).toString(2).padStart(18, '0'), '000111110010010100')
	assert.equal(encodeQr('HELLO').size, 21, 'version 1 is 21 modules wide')
})

test('a refused scheme renders as text and a path is made absolute', async () => {
	for (const value of ['javascript:alert(1)', 'data:text/html;base64,AAAA', 'ftp://example.nl/x', 'mailto:a@b.nl', 'file:///etc/passwd', '//evil.example/x', 'openid-credential-offer:/x', 'openid-credential-offer://a b', 'vbscript:x', '']) {
		assert.equal(qrHref(value, 'https://portaal.example'), '', JSON.stringify(value))
	}
	assert.equal(qrHref(null, ''), '')
	assert.equal(qrHref('/mijn/certificaten/1', 'https://portaal.example/'), 'https://portaal.example/mijn/certificaten/1')
	assert.equal(qrHref('/mijn/x', ''), '', 'a path without an origin cannot be scanned')
	assert.equal(qrHref(' https://example.nl/a ', ''), 'https://example.nl/a')

	const refused = await renderSfc(VALUE, { value: 'javascript:alert(1)', column: { linkLabel: 'Open' } })
	assert.match(refused, /data-testid="qr-text"/)
	assert.doesNotMatch(refused, /<a |<button|<svg|href=/, 'no link, no button, no code')
	const absolute = await renderSfc(VALUE, { value: '/mijn/x', column: {}, origin: 'https://portaal.example' })
	assert.match(absolute, /href="https:\/\/portaal\.example\/mijn\/x"/)
})

test('an empty value renders nothing, and a column names its link', async () => {
	const empty = await renderSfc(VALUE, { value: '', column: { linkLabel: 'Add to wallet' } })
	assert.doesNotMatch(empty, /<a |<button|<svg/)
	assert.doesNotMatch(await renderSfc(CODE, { value: '' }), /<svg/)
	assert.equal(qrLabel({ linkLabel: ' Add to wallet ', label: 'Wallet', field: 'w' }), 'Add to wallet')
	assert.equal(qrLabel({ label: 'Wallet', field: 'w' }), 'Wallet')
	assert.equal(qrLabel({ field: 'walletOfferUri' }), 'walletOfferUri')
	const card = await renderSfc(VALUE, { value: OFFER, column: { linkLabel: 'Add to wallet' }, locale: 'en' })
	assert.match(card, /<a [^>]*href="openid-credential-offer:\/\/[^"]*"[^>]*>Add to wallet<\/a>/, 'the link reads in its own words')
	assert.match(card, /data-testid="qr-address">openid-credential-offer:\/\//, 'the address stays visible as text')
	assert.match(card, /Scan this code with your phone/)
	assert.match(await renderSfc(VALUE, { value: OFFER, column: {}, locale: 'nl' }), /Scan deze code met je telefoon/)
})

test('the table shows the link and a toggle, and opens one code at a time', async () => {
	const closed = await renderSfc(VALUE, { value: OFFER, column: { linkLabel: 'Add to wallet' }, compact: true, open: false, locale: 'en' })
	assert.match(closed, /aria-expanded="false"[^>]*>\s*Show QR code/)
	assert.doesNotMatch(closed, /qr-address/, 'a closed cell draws no code')
	const open = await renderSfc(VALUE, { value: OFFER, column: {}, compact: true, open: true, locale: 'en' })
	assert.match(open, /aria-expanded="true"[^>]*>\s*Hide QR code/)
	assert.match(open, /qr-address/)

	const table = await loadSfc(TABLE)
	const vm = { openQr: '', qrKey: table.methods.qrKey }
	const column = { field: 'walletOfferUri' }
	table.methods.toggleQr.call(vm, { id: 'a' }, column)
	assert.equal(vm.openQr, 'a:walletOfferUri')
	table.methods.toggleQr.call(vm, { id: 'b' }, column)
	assert.equal(vm.openQr, 'b:walletOfferUri', 'opening another closes the first')
	table.methods.toggleQr.call(vm, { id: 'b' }, column)
	assert.equal(vm.openQr, '', 'the open one closes again')
	const source = readFileSync(TABLE, 'utf8')
	assert.match(source, /column\.linkLabel \|\| cellText\(row, column\)/, 'a link cell uses its own words')
	assert.match(readFileSync(CARD, 'utf8'), /<QrValue[^>]*:column="field"/, 'the record page draws the code')
})

test('the code ignores the theme, has an accessible name and is drawn without a request', async () => {
	let asked = 0
	globalThis.fetch = async () => { asked++; return { ok: true } }
	let html
	try {
		html = await renderSfc(CODE, { value: OFFER, label: 'Add to wallet', size: 160 })
	} finally {
		delete globalThis.fetch
	}
	assert.equal(asked, 0, 'no request leaves the page')
	assert.match(html, /role="img"/)
	assert.match(html, /aria-label="QR code for: Add to wallet"/)
	assert.match(html, /<rect[^>]*fill="#fff"/)
	assert.match(html, /<path[^>]*fill="#000"/)
	assert.doesNotMatch(html, /var\(--|currentColor|fill="(?!#fff"|#000")/, 'fixed black on white, no theme')
	const style = readFileSync(CODE, 'utf8').split('<style')[1] || ''
	assert.doesNotMatch(style, /color|background|filter|invert/, 'no theme colour can reach it')
	const named = await renderSfc(CODE, { value: OFFER, label: 'Wallet', nameTemplate: 'QR-code voor: {label}' })
	assert.match(named, /aria-label="QR-code voor: Wallet"/)
	const side = Number(/viewBox="0 0 (\d+) /i.exec(html)[1])
	assert.equal(side, encodeQr(OFFER).size + 8, 'a quiet zone of four modules on every side')
})
