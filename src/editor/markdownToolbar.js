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
	const range = { ...withoutEdgeSpace(value, normalise(selection)), marker }
	return isWrapped(value, range) ? unwrapped(value, range) : wrapped(value, range)
}

/**
 * The selection without the white space at its edges.
 *
 * @param {string} value The text.
 * @param {{start: number, end: number}} selection A valid selection.
 * @return {{start: number, end: number}} The selection, trimmed.
 */
function withoutEdgeSpace(value, selection) {
	let { start, end } = selection
	while (start < end && /\s/.test(value[start])) {
		start++
	}
	while (end > start && /\s/.test(value[end - 1])) {
		end--
	}
	return { start, end }
}

/**
 * @param {string} value The text.
 * @param {{start: number, end: number, marker: string}} range The trimmed selection and the marker.
 * @return {boolean} Whether the marker is on both sides of the selection already.
 */
function isWrapped(value, range) {
	const { start, end, marker } = range
	const size = marker.length
	return (
		start >= size
		&& value.slice(start - size, start) === marker
		&& value.slice(end, end + size) === marker
	)
}

/**
 * @param {string} value The text.
 * @param {{start: number, end: number, marker: string}} range The trimmed selection and the marker.
 * @return {{value: string, start: number, end: number}} The text without the marker around the selection.
 */
function unwrapped(value, range) {
	const { start, end } = range
	const size = range.marker.length
	return {
		value:
			value.slice(0, start - size)
			+ value.slice(start, end)
			+ value.slice(end + size),
		start: start - size,
		end: end - size,
	}
}

/**
 * @param {string} value The text.
 * @param {{start: number, end: number, marker: string}} range The trimmed selection and the marker.
 * @return {{value: string, start: number, end: number}} The text with the marker around the selection.
 */
function wrapped(value, range) {
	const { start, end, marker } = range
	return {
		value:
			value.slice(0, start)
			+ marker
			+ value.slice(start, end)
			+ marker
			+ value.slice(end),
		start: start + marker.length,
		end: end + marker.length,
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
	const { removed, added } = headingEdit(value.slice(from, lineEnd(value, from)))

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
 * What a line's heading toggle takes away and puts in: `## ` goes, any other
 * level becomes `## `, a plain line gets `## `.
 *
 * @param {string} line The line.
 * @return {{removed: number, added: string}} The length of the marker that goes and the marker that comes.
 */
function headingEdit(line) {
	if (line.startsWith(HEADING_MARKER)) {
		return { removed: HEADING_MARKER.length, added: '' }
	}
	const existing = /^#{1,6} /.exec(line)
	return { removed: existing ? existing[0].length : 0, added: HEADING_MARKER }
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
	const to = lineEnd(value, lastSelected(value, { start, end }))
	const block = toggledList(value.slice(from, to).split('\n')).join('\n')
	const edit = { value: value.slice(0, from) + block + value.slice(to), block, from, to }
	return listSelection(edit, { start, end })
}

/**
 * A selection that ends right after a newline does not take in the next
 * line: the editor selected whole lines and the cursor sits on the next.
 *
 * @param {string} value The text.
 * @param {{start: number, end: number}} range The selection.
 * @return {number} The last position the selection takes in.
 */
function lastSelected(value, range) {
	const { start, end } = range
	return end > start && value[end - 1] === '\n' ? end - 1 : end
}

/**
 * @param {Array<string>} lines The selected lines.
 * @return {boolean} Whether every line with text is a list item.
 */
function isAllItems(lines) {
	const filled = lines.filter((line) => line.trim() !== '')
	return filled.length > 0 && filled.every((line) => line.startsWith(LIST_MARKER))
}

/**
 * @param {string} line A line.
 * @return {string} The line without its list marker.
 */
function withoutListMarker(line) {
	return line.startsWith(LIST_MARKER) ? line.slice(LIST_MARKER.length) : line
}

/**
 * @param {string} line A line.
 * @return {string} The line as a list item; an empty line stays empty.
 */
function withListMarker(line) {
	return line.trim() === '' || line.startsWith(LIST_MARKER)
		? line
		: LIST_MARKER + line
}

/**
 * The lines as list items, or as plain lines when they all are items. One
 * line becomes an item also when it is empty.
 *
 * @param {Array<string>} lines The selected lines.
 * @return {Array<string>} The changed lines.
 */
function toggledList(lines) {
	if (isAllItems(lines)) {
		return lines.map(withoutListMarker)
	}
	if (lines.length === 1) {
		return lines[0].startsWith(LIST_MARKER) ? lines : [LIST_MARKER + lines[0]]
	}
	return lines.map(withListMarker)
}

/**
 * The text after the list toggle with its selection: the cursor moves with
 * its line, a selection covers the lines that changed.
 *
 * @param {{value: string, block: string, from: number, to: number}} edit The new text, the changed lines and where they were.
 * @param {{start: number, end: number}} range The selection before.
 * @return {{value: string, start: number, end: number}} The new text and selection.
 */
function listSelection(edit, range) {
	const { value, block, from, to } = edit
	if (range.start === range.end) {
		const cursor = Math.max(from, range.start + block.length - (to - from))
		return { value, start: cursor, end: cursor }
	}
	return { value, start: from, end: from + block.length }
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

/** The transformation of each shortcut key. */
const SHORTCUTS = new Map([
	['b', applyBold],
	['i', applyItalic],
])

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
	if (!isPlainCommand(event)) {
		return null
	}
	return SHORTCUTS.get(String(event.key || '').toLowerCase()) || null
}

/**
 * @param {{ctrlKey?: boolean, metaKey?: boolean, shiftKey?: boolean, altKey?: boolean}|null} event The key event.
 * @return {boolean} Whether it is pressed with Ctrl or Cmd, and without Shift and Alt.
 */
function isPlainCommand(event) {
	return (
		Boolean(event)
		&& (event.ctrlKey || event.metaKey)
		&& !event.shiftKey
		&& !event.altKey
	)
}

/**
 * @param {number} current The index of the button that has focus.
 * @param {number} count How many buttons the toolbar has.
 * @return {number} The index, within the toolbar.
 */
function focusedIndex(current, count) {
	return Math.max(0, Math.min(Number(current) || 0, count - 1))
}

/**
 * @param {number} from The focused index.
 * @param {number} count How many buttons.
 * @return {number} The next button, wrapping around.
 */
function nextButton(from, count) {
	return (from + 1) % count
}

/**
 * @param {number} from The focused index.
 * @param {number} count How many buttons.
 * @return {number} The previous button, wrapping around.
 */
function previousButton(from, count) {
	return (from - 1 + count) % count
}

/**
 * @return {number} The first button.
 */
function firstButton() {
	return 0
}

/**
 * @param {number} from The focused index.
 * @param {number} count How many buttons.
 * @return {number} The last button.
 */
function lastButton(from, count) {
	return count - 1
}

/** Where each key moves the focus. */
const FOCUS_MOVES = new Map([
	['ArrowRight', nextButton],
	['ArrowLeft', previousButton],
	['Home', firstButton],
	['End', lastButton],
])

/**
 * The toolbar button a key moves the focus to, or null for any other key.
 *
 * The WAI-ARIA toolbar pattern: Left and Right move to the previous and next
 * button and wrap around, Home and End go to the first and last. Together
 * with a roving tabindex (only the current button has `tabindex="0"`) this
 * makes the toolbar one Tab stop.
 *
 * @param {number} current The index of the button that has focus.
 * @param {string} key The `KeyboardEvent.key`.
 * @param {number} count How many buttons the toolbar has.
 * @return {number|null} The index to focus, or null.
 *
 * @spec openspec/changes/editor-text-toolbar/specs/portal-page-designer/spec.md#requirement-an-editor-must-be-able-to-shape-a-text-block-without-knowing-markdown
 */
export function toolbarIndexFor(current, key, count) {
	if (!Number.isInteger(count) || count < 1) {
		return null
	}
	const move = FOCUS_MOVES.get(key)
	return move ? move(focusedIndex(current, count), count) : null
}

/**
 * The tabindex of each toolbar button: 0 for the current one, -1 for the rest.
 *
 * @param {number} current The index of the current button.
 * @param {number} count How many buttons the toolbar has.
 * @return {Array<number>} One tabindex per button.
 *
 * @spec openspec/changes/editor-text-toolbar/specs/portal-page-designer/spec.md#requirement-an-editor-must-be-able-to-shape-a-text-block-without-knowing-markdown
 */
export function rovingTabindexes(current, count) {
	const size = Number.isInteger(count) && count > 0 ? count : 0
	const active = Math.max(0, Math.min(Number(current) || 0, size - 1))
	return Array.from({ length: size }, (_, index) => (index === active ? 0 : -1))
}
