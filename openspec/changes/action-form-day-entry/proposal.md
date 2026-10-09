# Proposal: action-form-day-entry

## Why

The Esdoornveen board MobielDetail has Milan write the hours of one BPV day on his phone ("Uren schrijven", maandag 5 oktober, Bakker Techniek BV). Proof run 3 (9 October 2026) found no such form: the site only has the generic week form.

The form on the board asks for:

- "Begonnen om" and "Gestopt om", as times;
- "Pauze": three cards side by side, "Geen", "30 min" and "60 min";
- "Dat is vandaag 8 uur": the hours, worked out, never typed;
- "Wat heb je gedaan?", with "Eén of twee zinnen is genoeg.";
- "Bij welk werkproces hoort dit?": check cards with the code above the name ("B1-K1-W3 Bouwt mechatronische systemen op"), four shown and "Alle 6 werkprocessen tonen";
- "Foto toevoegen", with a line on asking first;
- "Versturen naar Petra Bakker", and under it "Bewaren en later versturen";
- a line under the buttons: "Petra keurt je uren goed of stuurt ze terug met een vraag."

Action forms already ask text, numbers, dates, choice cards, files and a count (site-multi-step-forms, count-field, site-action-forms). Five pieces are missing, and each is generic: any app can use them.

## What changes

- A field whose schema format is `time` is a time input (`input: time`).
- A field config may declare `computed: {kind: "duration", from, to, minus?}`: the form works the hours out from two time fields minus a pause in minutes, shows them as a line with the field's label ("Dat is vandaag 8 uur"), and sends them as a number. The server works them out again from the same times (`EntryFormKeys::stamp`), so a typed total never stands. A computed value that reads a field the action does not have, or itself, is dropped.
- A field config may declare `widget: "checks"` on a field with options: check cards, more than one may be ticked, sent as a list in the order of the options. `shown: N` shows the first N cards and a "Alle N tonen" button; a ticked card always shows. A `collection` options provider may name a `noteField`: a short line above each option's label (the work process code).
- Choice cards may declare `inline: true`: short cards side by side, wrapping on a narrow screen. A ticked choice card gets the theme's light primary ground, as on the boards.
- A file field may declare `buttonLabel` ("Foto toevoegen").
- A create action may declare `saveForLater: {label, defaults}`: a second button that writes the same entry with these defaults over the action's own, so it waits until it is sent. The client only says `saveForLater: true`; the values come from the manifest, never from the request. The required fields stay required.
- An action may declare `footnote`: one line under the buttons.

## The learniq declaration (FIX-L)

learniq needs a day record first: a `bpv-hour-day` schema with `bpvPlacementId`, `learnerRef`, `date` (format date), `startTime` and `stopTime` (format time), `pauseMinutes` (integer, enum 0, 30, 60), `hours` (number), `description`, `werkprocessen` (array of werkproces ids), `photo` and `lifecycle` (`concept`, `submitted`, `approved`, `returned`). The week totals can then be summed from the days. Then, on the student's site pages:

```php
[
	'id' => 'writeHourDay',
	'type' => 'create',
	'register' => 'learniq',
	'schema' => 'bpv-hour-day',
	'label' => 'Uren schrijven',
	'submitLabel' => 'Versturen naar je praktijkopleider',
	'subjectField' => 'learnerRef',
	'fields' => ['bpvPlacementId', 'date', 'startTime', 'stopTime', 'pauseMinutes', 'hours', 'description', 'werkprocessen', 'photo'],
	'fieldConfigs' => [
		'bpvPlacementId' => ['label' => 'Leerbedrijf', 'required' => true],
		'date' => ['label' => 'Welke dag?', 'required' => true, 'widget' => 'dateChoices', 'dateChoices' => 2],
		'startTime' => ['label' => 'Begonnen om', 'required' => true, 'size' => 'small'],
		'stopTime' => ['label' => 'Gestopt om', 'required' => true, 'size' => 'small'],
		'pauseMinutes' => ['label' => 'Pauze', 'widget' => 'choices', 'inline' => true],
		'hours' => ['label' => 'Dat is vandaag', 'computed' => ['kind' => 'duration', 'from' => 'startTime', 'to' => 'stopTime', 'minus' => 'pauseMinutes']],
		'description' => ['label' => 'Wat heb je gedaan?', 'help' => 'Eén of twee zinnen is genoeg.', 'size' => 'large'],
		'werkprocessen' => ['label' => 'Bij welk werkproces hoort dit?', 'help' => 'Je mag er meer kiezen.', 'widget' => 'checks', 'shown' => 4],
		'photo' => ['label' => 'Foto', 'type' => 'file', 'accept' => ['image/*'], 'buttonLabel' => 'Foto toevoegen', 'help' => 'Een foto van je werk helpt bij je beoordeling. Vraag eerst of je in het bedrijf mag fotograferen.'],
	],
	'optionsProviders' => [
		'pauseMinutes' => ['type' => 'static', 'options' => [['value' => 0, 'label' => 'Geen'], ['value' => 30, 'label' => '30 min'], ['value' => 60, 'label' => '60 min']]],
		'bpvPlacementId' => ['type' => 'collection', 'register' => 'learniq', 'schema' => 'bpv-placement', 'valueField' => 'id', 'labelField' => 'trainingCompanyName'],
		'werkprocessen' => ['type' => 'collection', 'register' => 'learniq', 'schema' => 'werkproces', 'valueField' => 'id', 'labelField' => 'title', 'noteField' => 'code'],
	],
	'defaults' => ['lifecycle' => 'submitted'],
	'saveForLater' => ['label' => 'Bewaren en later versturen', 'defaults' => ['lifecycle' => 'concept']],
	'footnote' => 'Je praktijkopleider keurt je uren goed of stuurt ze terug met een vraag.',
]
```

Notes for learniq:

- The board names the trainer ("Versturen naar Petra Bakker"). A label with a name from the data is not in this change; the declaration above uses "je praktijkopleider".
- `bpvPlacementId` with one placement is filled in by itself (a required select with one option).
- The page around the form (eyebrow "maandag 5 oktober · Bakker Techniek BV", back link "BPV-uren") is the record page header of `record-detail-page`.

## Not in this change

- Changing a saved entry ("Aanpassen"): an update action on the day record, with the same keys.
- A label filled from the data.
