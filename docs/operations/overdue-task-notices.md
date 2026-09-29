---
title: Notices when a task is overdue
sidebar_label: Notices when a task is overdue
description: How a resident hears that a task they were given is past its deadline, and who decides when that happens
---

# Notices when a task is overdue

The organisation asks a resident for a document by a certain date. The date passes and nothing came in. The resident then gets a notice that the task is overdue, in the portal inbox and by e-mail.

## What the resident sees

A message in **Inbox**, for example "Your task is overdue: Send your latest payslip". The message says when the task was due. When the case type sets one, it also says what happens without a response. **Open** goes to the task in "Mijn taken", which shows "Overdue".

The e-mail reads "Your task in the portal of <organisation> is overdue". It carries the organisation name and a link to the portal. It never names the task or the case.

A reminder before the deadline reads differently: "Reminder: you have an open task in the portal of <organisation>".

## Who decides when a notice goes out

The case type does, not the portal. Its business timer in the case app sets how long after the deadline a notice goes out, and how often. The portal delivers each notice the case app records. It never decides by itself that a task is overdue, and it never sends a second notice on its own schedule.

So a task without a business timer gets no overdue notice. Set the timer on the case type to turn them on.

OpenRegister does not record overdue notices yet. Its timer sends reminders before the deadline only. Until it records them, residents get no overdue notice, whatever the timer says.

## When a notice fails

A notice of a kind the portal does not know is marked failed with "unknown delivery kind" and the kind's name. The resident gets nothing for it. The failure and its reason stay on the delivery record in OpenRegister.
