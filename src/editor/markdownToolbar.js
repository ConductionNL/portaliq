/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The text a toolbar button writes into a text block.
 *
 * An editor shapes a "Tekst" block with buttons (Kop, Vet, Cursief, Lijst,
 * Link) instead of typing markdown, and the block still STORES markdown
 * (`props.markdown`), so every page written before keeps rendering. Each
 * function here takes the textarea's value and selection and returns the new
 * value and the selection to put back, so the button, the keyboard shortcut
 * and the unit test all run the same code without a DOM.
 *
 * A selection is `{value, start, end}` with `start <= end`, as
 * `selectionStart` and `selectionEnd` report it.
 *
 * @spec openspec/changes/editor-text-toolbar/specs/portal-page-designer/spec.md#requirement-an-editor-must-be-able-to-shape-a-text-block-without-knowing-markdown
 */

/**
 * A toolbar transformation: a selection in, the new text and selection out.
 *
 * @typedef {(selection: {value: string, start: number, end: number}) => {value: string, start: number, end: number}} Transform
 */

/**
 * The marker a heading line starts with.
 *
 * @type {string}
 */
export const HEADING_MARKER = '## '

/**
 * The marker a list line starts with.
 *
 * @type {string}
 */
export const LIST_MARKER = '- '

/**
 * Clamp a selection to the value, so a stale selection never slices past it.
 *
 * @param {{value: string, start: number, end: number}} selection The selection.
 * @return {{value: string, start: number, end: number}} A valid selection.
 */
function normalise({ value, start, end }) {
	const text = typeof value === 'string' ? value : ''
	const from = Math.max(0, Math.min(Number(start) || 0, text.length))
	const to = Math.max(from, Math.min(Number(end) || 0, text.length))
	return { value: text, start: from, end: to }
}

/**
 * Where the line holding `index` starts.
 *
 * @param {string} value The text.
 * @param {number} index A position in it.
 * @return {number} The offset of that line's first character.
 */
function lineStart(value, index) {
	return value.lastIndexOf('\n', index - 1) + 1
}

/**
 * Where the line holding `index` ends (the offset of its newline, or the end).
 *
 * @param {string} value The text.
 * @param {number} index A position in it.
 * @return {number} The offset just past that line's last character.
 */
function lineEnd(value, index) {
	const newline = value.indexOf('\n', index)
	return newline === -1 ? value.length : newline
}

/**
 * Wrap the selection in a marker, or take the marker away when it is there.
 *
 * Whitespace at the edges of the selection stays outside the marker: a
 * double-click selects "word " in some browsers, and `**word **` does not
 * render as bold. With nothing selected the two markers are written with the
 * cursor between them, so the editor types the bold word next.
 *
 * @param {{value: string, start: number, end: number}} selection The selection.
 * @param {string} marker The marker, `**` or `_`.
 * @return {{value: string, start: number, end: number}} The new text and selection.
 */
function wrap(selection, marker) {
	const { value } = normalise(selection)
	let { start, end } = normalise(selection)

	while (start < end && /\s/.test(value[start])) {
		start++
	}
	while (end > start && /\s/.test(value[end - 1])) {
		end--
	}

	const size = marker.length
	const before = value.slice(start - size, start)
	const after = value.slice(end, end + size)
	if (start >= size && before === marker && after === marker) {
		return {
			value:
				value.slice(0, start - size)
				+ value.slice(start, end)
				+ value.slice(end + size),
			start: start - size,
			end: end - size,
		}
	}

	return {
		value:
			value.slice(0, start)
			+ marker
			+ value.slice(start, end)
			+ marker
			+ value.slice(end),
		start: start + size,
		end: end + size,
	}
}

/**
 * Make the line the cursor is on a heading, or a plain line again.
 *
 * A line that already starts with `## ` loses it. A line that starts with
 * another heading level (`# `, `### `) becomes `## `, the level the site's text
 * block uses for a section. The selection moves with the text.
 *
 * @param {{value: string, start: number, end: number}} selection The selection.
 * @return {{value: string, start: number, end: number}} The new text and selection.
 *
 * @spec openspec/changes/editor-text-toolbar/specs/portal-page-designer/spec.md#requirement-an-editor-must-be-able-to-shape-a-text-block-without-knowing-markdown
 */
export function applyHeading(selection) {
	const { value, start, end } = normalise(selection)
	const from = lineStart(value, start)
	const line = value.slice(from, lineEnd(value, from))

	let removed = 0
	let added = HEADING_MARKER
	if (line.startsWith(HEADING_MARKER)) {
		removed = HEADING_MARKER.length
		added = ''
	} else {
		const existing = /^#{1,6} /.exec(line)
		removed = existing ? existing[0].length : 0
	}

	// A position inside the marker that went lands just after the new one;
	// every other position moves by the difference in length.
	const shift = (position) =>
		position < from + removed
			? from + added.length
			: position - removed + added.length
	return {
		value: value.slice(0, from) + added + value.slice(from + removed),
		start: shift(start),
		end: shift(end),
	}
}

/**
 * Make the selection bold (`**text**`), or plain when it already is.
 *
 * @param {{value: string, start: number, end: number}} selection The selection.
 * @return {{value: string, start: number, end: number}} The new text and selection.
 *
 * @spec openspec/changes/editor-text-toolbar/specs/portal-page-designer/spec.md#requirement-an-editor-must-be-able-to-shape-a-text-block-without-knowing-markdown
 */
export function applyBold(selection) {
	return wrap(selection, '**')
}

/**
 * Make the selection italic (`_text_`), or plain when it already is.
 *
 * @param {{value: string, start: number, end: number}} selection The selection.
 * @return {{value: string, start: number, end: number}} The new text and selection.
 *
 * @spec openspec/changes/editor-text-toolbar/specs/portal-page-designer/spec.md#requirement-an-editor-must-be-able-to-shape-a-text-block-without-knowing-markdown
 */
export function applyItalic(selection) {
	return wrap(selection, '_')
}

/**
 * Make every selected line a list item, or plain lines when they all are.
 *
 * Empty lines inside the selection stay empty: a `- ` on a blank line is an
 * empty bullet on the page. With nothing selected the cursor's line becomes
 * the item, also when it is empty, so the editor can start a list by typing.
 * The selection afterwards covers the lines that changed.
 *
 * @param {{value: string, start: number, end: number}} selection The selection.
 * @return {{value: string, start: number, end: number}} The new text and selection.
 *
 * @spec openspec/changes/editor-text-toolbar/specs/portal-page-designer/spec.md#requirement-an-editor-must-be-able-to-shape-a-text-block-without-knowing-markdown
 */
export function applyList(selection) {
	const { value, start, end } = normalise(selection)
	const from = lineStart(value, start)
	// A selection that ends right after a newline does not take in the next
	// line: the editor selected whole lines and the cursor sits on the next.
	const last = end > start && value[end - 1] === '\n' ? end - 1 : end
	const to = lineEnd(value, last)
	const lines = value.slice(from, to).split('\n')

	const filled = lines.filter((line) => line.trim() !== '')
	const allItems =
		filled.length > 0 && filled.every((line) => line.startsWith(LIST_MARKER))

	let changed
	if (allItems) {
		changed = lines.map((line) =>
			line.startsWith(LIST_MARKER) ? line.slice(LIST_MARKER.length) : line,
		)
	} else if (lines.length === 1) {
		changed = lines[0].startsWith(LIST_MARKER) ? lines : [LIST_MARKER + lines[0]]
	} else {
		changed = lines.map((line) =>
			line.trim() === '' || line.startsWith(LIST_MARKER)
				? line
				: LIST_MARKER + line,
		)
	}

	const block = changed.join('\n')
	const result = value.slice(0, from) + block + value.slice(to)

	if (start === end) {
		const delta = block.length - (to - from)
		const cursor = Math.max(from, start + delta)
		return { value: result, start: cursor, end: cursor }
	}

	return { value: result, start: from, end: from + block.length }
}

/**
 * Turn the selection into a link to `url`: `[text](url)`.
 *
 * With nothing selected the address is also the link's text, so the page
 * never shows an empty link. An empty or blank address changes nothing. The
 * cursor lands after the link, where the editor carries on typing.
 *
 * @param {{value: string, start: number, end: number}} selection The selection.
 * @param {string} url The address the editor gave.
 * @return {{value: string, start: number, end: number}} The new text and selection.
 *
 * @spec openspec/changes/editor-text-toolbar/specs/portal-page-designer/spec.md#requirement-an-editor-must-be-able-to-shape-a-text-block-without-knowing-markdown
 */
export function applyLink(selection, url) {
	const { value, start, end } = normalise(selection)
	const address = String(url || '').trim()
	if (address === '') {
		return { value, start, end }
	}

	const text = value.slice(start, end).trim() || address
	const link = `[${text}](${address})`
	const cursor = start + link.length
	return {
		value: value.slice(0, start) + link + value.slice(end),
		start: cursor,
		end: cursor,
	}
}

/**
 * The transformation a keyboard shortcut asks for, or null.
 *
 * Ctrl+B and Ctrl+I, or Cmd on a Mac. A shortcut with Shift or Alt is left to
 * the browser and to assistive technology.
 *
 * @param {{key: string, ctrlKey?: boolean, metaKey?: boolean, shiftKey?: boolean, altKey?: boolean}} event The key event.
 * @return {Transform|null} `applyBold`, `applyItalic`, or null.
 *
 * @spec openspec/changes/editor-text-toolbar/specs/portal-page-designer/spec.md#requirement-an-editor-must-be-able-to-shape-a-text-block-without-knowing-markdown
 */
export function shortcutFor(event) {
	if (
		!event
		|| !(event.ctrlKey || event.metaKey)
		|| event.shiftKey
		|| event.altKey
	) {
		return null
	}

	const key = String(event.key || '').toLowerCase()
	if (key === 'b') {
		return applyBold
	}
	if (key === 'i') {
		return applyItalic
	}
	return null
}
