# Design: embedded-intake-form

## D1. An iframe, on purpose

The snippet is a script tag that writes one iframe pointing at the
portal's origin, plus a small `postMessage` listener for height and for
the submitted event. The alternative, rendering our form inline in the
host page, puts the visitor's keystrokes inside a document whose scripts
and CSS we do not control. For an intake form that collects a name, an
address and sometimes a reason for a request, that is not a trade-off
worth making.

## D2. The origin list is the security boundary, and it is per form

`portalForm.allowedOrigins[]`. The `Content-Security-Policy:
frame-ancestors` header for the frame route is built from the list of the
form being served. Not a wildcard, not per portal, not a setting an admin
can leave open: a form with an empty list serves to nobody and says so on
the page where the snippet is copied.

## D3. The submission path is the one that already exists

The frame posts to the same anonymous contribution create the portal's own
form uses. Nothing new validates, nothing new writes, nothing new decides
who may see the result. The one addition is that the submission records
the origin it came from, so an administrator can tell a gemeente.nl
submission from a portal one without guessing.

## D4. Fail closed at the frame, not at the form

An origin that is not allowed is refused before any schema is read, the
way `portal-contribution-contract` already refuses an anonymous caller
"without a schema being read". The frame renders a plain message, not a
form with a broken submit.

## D5. No session, stated as a property rather than a hope

The frame route sets no cookie and reads none. A visitor signed in to the
portal in another tab is anonymous inside the frame. This is what keeps a
third-party page from becoming a place where somebody's portal session can
be used.

## D6. What the visitor gets back

The case reference and a follow link with a one-time token, the same
anonymous fallback `portal-identity-space` already describes. A
municipality that wants an identified submission links to the portal's own
page instead; the frame does not host a login, because a login form inside
somebody else's page is the shape every phishing guide warns about.

## Risks

- **An admin who allows too much.** The snippet page names the origins the
  form will answer to, in plain language, next to the snippet. A wildcard
  is not available.
- **A host page that resizes badly.** Height is negotiated over
  `postMessage`; when the message does not arrive the frame keeps a
  declared minimum height rather than collapsing.
- **Spam.** The throttle is ADR-082's and applies per origin and per
  address. A form on a public page will be found by bots; the tasks
  require the throttle to be measured on the frame route, not assumed from
  the portal's own.
