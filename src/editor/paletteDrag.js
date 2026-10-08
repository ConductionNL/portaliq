/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * paletteDrag: the media type a dragged palette entry carries.
 *
 * ITS OWN MODULE, not an export on the palette component. The canvas
 * (PageGridEditor.vue) has to read the type to decide whether a drag is one of
 * ours, and it used to import that constant from the palette's `.vue` file,
 * which made the editor depend on the palette for a string. Both sides now read
 * one module and neither knows about the other.
 */

/**
 * The media type a dragged palette entry carries.
 *
 * Its own type rather than `text/plain`, so a drop that did not come from the
 * palette is not mistaken for one that did.
 *
 * @type {string}
 */
export const PALETTE_DRAG_TYPE = 'application/x-portaliq-widget'
