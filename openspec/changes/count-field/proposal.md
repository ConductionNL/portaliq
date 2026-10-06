## Why

The Warmtepompacademie's course page asks an employer how many people she books (board Artikel: "Aantal deelnemers", a − and + around the number, and "3 deelnemers × [PRIJS]" under it). The site form could only offer a plain number box. School portal plan G-27/W2-2: field type `count`.

## What Changes

- An action field MAY declare `widget: count` with `min` and `max` (whole numbers 0 to 999, `max` at least `min`; defaults 1 and 99), `unit` (`{one, other}`) and `priceLabel` (authored text). The server keeps the companions that fit and drops the widget on a field with options, a date or a file.
- The site draws it as a stepper (`CountStepper`): a − button, the number, a + button, and a live line "3 deelnemers × [PRIJS]". The value sent is the whole number, kept between `min` and `max`; with nothing chosen the form sends `min`.
- New site strings "One less" / "Eén minder" and "One more" / "Eén meer" for the buttons' accessible names.

## Not in this change

A computed total ("[TOTAAL]"): prices are placeholders on the boards (deviation D-11).
