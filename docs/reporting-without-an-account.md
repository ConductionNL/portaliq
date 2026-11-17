---
title: Report wrongdoing without an account
description: How a report reaches you from someone who will not say who they are, and who may later learn their name
---

# Report wrongdoing without an account

Someone wants to report wrongdoing at work. They will not log in, and they
will not leave an address. The Wet bescherming klokkenluiders (Wbk) says you
have to take that report anyway, come back to them, and keep their name out of
everybody's hands but one.

The portal runs that channel. Your case app decides the rules.

## What the reporter does

They open the reporting page and write the report. No login. No address. No
verification step in front of the form.

When they submit, the portal shows a receipt code once. It says plainly that
the code cannot be sent again and cannot be recovered. That code is the only
way back in, and it is the only thing the reporter leaves with.

They come back with the code and read what you wrote. They can answer there.
Neither side needs a name for the conversation.

Contact details are optional. A reporter who gives none has filed a complete
report.

## What you see

Open the report and you see the subject, the body and the answers. You do not
see who filed it, even when they left a name.

Write a note and it stays internal. Tick "visible to reporter" and the reporter
reads it on their next visit. Their own answers are always visible to them.

## Who may reveal, and what that costs

Learning who filed a report is a separate act, not a harder look.

1. You ask, and you say why. A request without a motivation is refused before
   anybody sees it.
2. The custodian answers. That is the group your case type named, and nobody
   else. An administrator who is not in that group is refused like anyone
   else, and is shown nothing at all.
3. The answer is written down either way. The record names who asked, the
   motivation, who decided, when, and which fields were shown. The values
   themselves are not copied onto it.

An allowed reveal also raises an event for your case app, so the identity it
then holds falls under its own retention rules.

## What your case type declares

Put a `portalReportDeclaration` on the case type:

| Field | What it does |
|---|---|
| `custodianGroup` | The Nextcloud group whose members may allow a reveal |
| `handlerGroup` | The Nextcloud group whose members read and answer the reports. Optional |
| `acknowledgementDays` | Days to acknowledge the report, shown to the reporter |
| `feedbackDays` | Days to give feedback, shown to the reporter |
| `excludeFromAnalytics` | Keeps the reporting pages out of visitor statistics |

The portal holds no term of its own. Declare seven days and the reporter sees
seven days. Declare nothing and the reporter sees no term, rather than one you
never promised.

A case type that names no custodian group has nobody who may reveal. That
refuses; it does not fall back to an administrator.

Only members of the handler group and of the custodian group see a report.
Everyone else signed in to Nextcloud gets the same answer as for a report that
does not exist. Name no handler group and only the custodian group handles the
reports: fewer people, never more.

## Setting up the channel

1. Declare `portalReportDeclaration` on the case type, with at least a
   `custodianGroup`, and a `handlerGroup` when the people who answer reports
   are not the people who may reveal.
2. Add the reporting pages to the portal's `traffic.excludedPaths`, for
   example `/meldpunt`. A path excludes itself and every page below it, so
   `/meldpunt` also covers `/meldpunt/gesprek`.
3. Place the `report` widget on the reporting page and the `reportThread`
   widget on the follow-up page. The page designer warns "This page is not
   excluded from visitor statistics. Add it before you publish." while a page
   with either widget is outside `traffic.excludedPaths`.
4. Handlers find the reports under "Reports of wrongdoing" in the app's menu,
   and custodians answer reveal requests under "Reveal requests".

## What the channel does not record

Set `excludeFromAnalytics` and add the reporting route to the portal's
`excludedPaths`. No visitor row is written for those pages, so nothing records
that somebody opened the form.

No client address is stored against a report. The challenge in front of the
form is the one the portal runs itself, so nothing leaves for an outside
vendor.

## Who has to run this

Every employer with fifty people or more, not only municipalities. The duty is
the same for a gemeente of nine hundred and a building firm of sixty.
