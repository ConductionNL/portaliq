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
		'Try again': 'Opnieuw proberen',
		'Your tasks could not be loaded.': 'Uw taken konden niet worden geladen.',
		'Your conversations could not be loaded.':
			'Uw gesprekken konden niet worden geladen.',
		'Your cases could not be loaded.': 'Uw zaken konden niet worden geladen.',
		'What you still have to do could not be loaded.':
			'Wat u nog moet doen kon niet worden geladen.',
		'Where your case stands could not be loaded.':
			'Waar uw zaak staat kon niet worden geladen.',
		'You have no running cases.': 'U heeft geen lopende zaken.',
		'You have no cases yet.': 'U heeft nog geen zaken.',
		'There are no steps to show yet.': 'Er zijn nog geen stappen om te tonen.',
		'All cases': 'Alle zaken',
		'Case {reference}': 'Zaak {reference}',
		'Step {current} of {total}': 'Stap {current} van {total}',
		'Answer by {date}': 'Antwoord uiterlijk {date}',
		Done: 'Gereed',
		'Current step': 'Huidige stap',
		'Still to come': 'Nog niet begonnen',
		Closed: 'Afgerond',
		'Welcome, {name}': 'Welkom, {name}',
		Welcome: 'Welkom',
		'What you still have to do': 'Dit moet u nog doen',
		'Running cases': 'Lopende zaken',
		'New messages': 'Nieuwe berichten',
		'Choose for whom': 'Kies voor wie',
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
		'Try again': 'Try again',
		'Your tasks could not be loaded.': 'Your tasks could not be loaded.',
		'Your conversations could not be loaded.':
			'Your conversations could not be loaded.',
		'Your cases could not be loaded.': 'Your cases could not be loaded.',
		'What you still have to do could not be loaded.':
			'What you still have to do could not be loaded.',
		'Where your case stands could not be loaded.':
			'Where your case stands could not be loaded.',
		'You have no running cases.': 'You have no running cases.',
		'You have no cases yet.': 'You have no cases yet.',
		'There are no steps to show yet.': 'There are no steps to show yet.',
		'All cases': 'All cases',
		'Case {reference}': 'Case {reference}',
		'Step {current} of {total}': 'Step {current} of {total}',
		'Answer by {date}': 'Answer by {date}',
		Done: 'Done',
		'Current step': 'Current step',
		'Still to come': 'Still to come',
		Closed: 'Closed',
		'Welcome, {name}': 'Welcome, {name}',
		Welcome: 'Welcome',
		'What you still have to do': 'What you still have to do',
		'Running cases': 'Running cases',
		'New messages': 'New messages',
		'Choose for whom': 'Choose for whom',
	},
}
