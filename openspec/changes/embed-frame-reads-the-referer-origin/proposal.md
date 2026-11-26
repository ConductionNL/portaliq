# Proposal: embed-frame-reads-the-referer-origin

## Why

help-portaliq-1 (25 Nov 2026, 13:40Z) found that every real embed of `/portal/embed` is refused with "This form may not be shown on this website": `PortalEmbedController` judges only the `Origin` header, and a browser sends no `Origin` on an iframe's document GET (only `Referer` and `Sec-Fetch-Dest: iframe`). The e2e "the frame actually renders the form" stayed red for that reason (portaliq #2739). Ruben decided (25 Nov 2026 15:15 CET): fall back to the Referer's origin when Origin is absent, with the same allow-list.

## What changes

- The frame route and the frame's submit read the framing origin from `Origin`; when the request carries no `Origin`, from the origin (`scheme://host[:port]`) of its `Referer`.
- The same allow-list judges either, before the form is resolved. A request with neither header, or with a Referer that is no absolute URL, still gets the refusal.
- An `Origin` header that is sent decides on its own; an allowed Referer does not rescue a disallowed Origin.
- A submission records only the Referer's origin, never its path or query.
- `frame-ancestors` stays the browser-side boundary, unchanged.

## Out of scope

- Serving the frame to any caller (option b) and a documented proxy (option c): not chosen.
- Live check in a real browser framing the form from an allowed site: the live lane.
