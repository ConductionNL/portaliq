// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The words of the mijn omgeving components, in Dutch and English. Keys are
// the English source text. They live here, beside the components that load on
// demand, and not in src/shared/i18n: that catalogue sits in the site's entry,
// which has almost no room left under its budget.
//
// Residents read the "u" form. No em-dashes.
//
// Imports nothing, so tests/mijn-components.spec.mjs reads it as node.
//
// @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004

export default {
	nl: {
		'Before {date}': 'Voor {date}',
		'{count} days left': 'Nog {count} dagen',
		'1 day left': 'Nog 1 dag',
		'Due today': 'Vandaag',
		Overdue: 'Verlopen',
		New: 'Nieuw',
		Loading: 'Bezig met laden',
		'You have nothing to do right now.': 'U hoeft nu niets te doen.',
		'You have no messages yet.': 'U heeft nog geen berichten.',
		'All messages': 'Alle berichten',
		'Your messages could not be loaded.':
			'Uw berichten konden niet worden geladen.',
		'Today at {time}': 'Vandaag om {time} uur',
		'{date} at {time}': '{date} om {time} uur',
	},
	en: {
		'Before {date}': 'Before {date}',
		'{count} days left': '{count} days left',
		'1 day left': '1 day left',
		'Due today': 'Due today',
		Overdue: 'Overdue',
		New: 'New',
		Loading: 'Loading',
		'You have nothing to do right now.': 'You have nothing to do right now.',
		'You have no messages yet.': 'You have no messages yet.',
		'All messages': 'All messages',
		'Your messages could not be loaded.': 'Your messages could not be loaded.',
		'Today at {time}': 'Today at {time}',
		'{date} at {time}': '{date} at {time}',
	},
}
