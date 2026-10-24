---
title: Ask a question on the public site
sidebar_label: Ask a question
---

# Ask a question on the public site

A visitor types a question and gets a short answer from your published content, with the pages it came from. The widget is called **Vraag het de assistent** in the page designer.

## What it reads

The assistant reads three things and nothing else:

- the published pages of this portal,
- the glossary of this portal,
- the published publications.

It never reads a resident's own records. The widget is anonymous: it sends no sign-in and no account, even when the visitor is signed in.

## What the visitor sees

- Before the first question: "Answers come from an AI assistant and can be wrong. Check the page it links to."
- Next to the input: "Do not type personal details such as your citizen service number."
- An answer, with its sources as links.
- When nothing in your content supports an answer, no answer at all: "I could not find this in our information. You can contact us." with a link to your contact page.

A citizen service number, an email address or a Dutch phone number in a question is replaced by `[removed]` before it leaves, and the visitor is told so. This catches what is recognisable. It is not a guarantee.

The assistant cannot act. It files nothing and changes nothing. Asked to do something, it explains where to do it and links the form page.

## Where the model runs

Not in Portaliq. Hermiq runs the conversation and the model. Portaliq keeps no question and no answer. The traffic report counts one `assistant_asked` event per question and never its text.

## How to turn it on

1. Install Hermiq with its entry point for channel adapters. Without it the widget does not show.
2. Set `assistant.enabled` to `true` on the portal. It is off by default.
3. Optionally list page routes in `assistant.excludedRoutes` that the assistant must leave out.
4. Add the widget to a page in the page designer. It is only offered once the portal turned it on.
5. Add `assistant_asked` to the traffic events of the portal if you want to count use.
