// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// Line icons for task tiles (site-school-blocks), drawn on a 24 by 24 grid
// with a round 1.9 stroke, so a tile list reads as one family. A name not in
// this list draws no icon: an icon is decorative, the label carries the
// meaning. Names say what the icon shows, not what a portal uses it for.
//
// @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-task-list-shows-a-portals-most-asked-tasks-as-tiles

export default {
	alert: 'M12 3v10M12 17v.5M5 21h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2z',
	chat: 'M4 5h16v11H8l-4 4z',
	calendar: 'M4 6h16v14H4zM4 10h16M8 3v4M16 3v4',
	calendarLines: 'M4 6h16v14H4zM4 10h16M8 3v4M16 3v4M8 14h3M13 14h3',
	clock: 'M12 3a9 9 0 1 1 0 18 9 9 0 0 1 0-18zM12 7v5l3 2',
	document: 'M6 3h9l4 4v14H6zM14 3v5h5M9 13h7M9 17h5',
	documentGrade: 'M6 3h9l4 4v14H6zM14 3v5h5M9 17l2-5 2 5M9.7 15.5h2.6M15 12v5',
	home: 'M3 11l9-7 9 7M5 10v10h14V10M10 20v-5h4v5',
	book: 'M4 5a2 2 0 0 1 2-2h14v16H6a2 2 0 0 0-2 2zM4 21V5M9 7h7',
	bookLines: 'M4 5a2 2 0 0 1 2-2h14v16H6a2 2 0 0 0-2 2zM4 21V5M9 7h7M9 11h5',
	bookStack:
		'M5 4h11a3 3 0 0 1 3 3v13H8a3 3 0 0 1-3-3zM5 17a3 3 0 0 1 3-3h11M9 8h6',
	personPlus:
		'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21c1-4 4-6 7-6s6 2 7 6M19 8v6M16 11h6',
	heartPlus:
		'M12 20s-7-4.5-7-10a4 4 0 0 1 7-2.5A4 4 0 0 1 19 10c0 5.5-7 10-7 10zM12 9v4M10 11h4',
	sun: 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8zM12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M5 19l1.5-1.5M17.5 6.5L19 5',
	pencil: 'M4 20h4l10-10-4-4L4 16zM13 7l4 4',
	card: 'M4 6h16v12H4zM4 10h16M8 15h3',
	plusBox: 'M12 5v14M5 12h14M4 4h16v16H4z',
	building: 'M4 21V7l8-4 8 4v14M9 21v-6h6v6M8 10h.01M12 10h.01M16 10h.01',
}
