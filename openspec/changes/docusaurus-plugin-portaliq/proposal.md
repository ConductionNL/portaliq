---
kind: code
---

# Proposal: docusaurus-plugin-portaliq

## Summary

A new project — a Docusaurus plugin that builds a documentation site from
Portaliq's headless content API: menu tree into the sidebar, markdown pages
into docs, glossary terms into a begrippenlijst. Consumes the public API only.

Chain link 11 of `hydra/openspec/changes/portaliq-phase-two`. Specified here
because Portaliq owns the contract; the code lands in its own repository.

## Motivation

Two reasons, and the second matters more.

**Practical.** The working tree carries 10+ Docusaurus sites —
`conduction-website`, `nldesign/docs`, `opencatalogi/docs`,
`openconnector/docs`, `doriath/docs`, `larpingapp/docs`, `openwoo-app-website`,
`nextcloud-app-template/docs` and more — each authoring markdown in its own
repository. There is no editorial surface for anyone who does not commit to a
git repo, and no way to share a glossary or a navigation structure between
them.

**Architectural.** A CMS is headless when something that is *not* its own
renderer can reproduce a site from its API. This plugin is that test, run
continuously. If it ever needs a Portaliq internal, the finding is that the API
is incomplete — not that the plugin needs a workaround.

## Affected Projects

- [ ] `docusaurus-plugin-portaliq` — **new repository.** A Docusaurus plugin
      fetching content at build time and emitting docs pages, sidebar items and
      a glossary route.
- [ ] `portaliq` — no code change. This change records the contract and the
      conformance obligation.

## Design notes

**Build-time, not runtime.** Docusaurus is a static-site generator; content is
fetched during the build. A site therefore keeps working when Portaliq is
unreachable — it just does not update.

**Markdown passes through.** The API serves markdown source, so the plugin
hands it to Docusaurus's existing pipeline rather than converting HTML back.

**One website per site by default.** The plugin is configured with a website
identifier and an API base; content is scoped to that site.

**Failure is loud.** A build that cannot reach Portaliq, or receives fewer
pages than the site declares it expects, fails rather than silently publishing
a site with missing pages. A documentation site that quietly loses half its
content looks exactly like one that never had it.

## Risks

- **A build-time dependency on a live service** makes documentation builds
  fail for reasons unrelated to documentation. A cached last-good snapshot and
  an explicit opt-in to use it are the mitigation, not silent fallback.
- **Credentials in a build.** A site pulling non-public content needs a token
  in CI; the plugin must not write it into build output.
- **The conformance obligation is easy to let slide.** If the plugin starts
  needing internals and gets them, the headless property is lost quietly.
