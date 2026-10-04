# Lane log: r3-portal (portaliq part, clone pq-guard)

Resume rule: read this file, `git status`, continue from the first change not marked DONE.
This file is never staged.

## 1a. contribution-pay-screen: DONE
- Branch `feat/contribution-pay-screen` (--no-track from origin/development db4a6cf).
- Artifacts written (proposal, contract, spec delta, design, test-plan, tasks), openspec validate 0.
- Built: RowActionResolver (+CollectionConfigNormaliser delegation, noticeField), PortalRowActionController +
  route portalRowAction#forward, PortalActionForwarder::isForwardable, rowAction.js, portalApi.forwardRowAction,
  RowActionConfirm.jsx, CollectionTable offers prop, PageView confirm step + notice, i18n, theme.css, docs page,
  README row. Tests: RowActionResolverTest (5), PortalRowActionControllerTest (9), PortalActionForwarderTest (1),
  tests/row-action.spec.mjs (11). Mutation check on rowWhen guard and stamp caught.
- Diff checks so far: phpcs/phpmd/phpstan/psalm on touched lib 0; eslint 0; stylelint 0; format 0; build:portal 0.
- Next: check:specs, npm run lint, check:strict once, hydra gates, commit, push, PR, opsx-verify.
- check:specs 0; npm run lint 0; check:strict 1 (only the 29 inherited PHPUnit errors: 13 RenameDutchColumnsTest
  Doctrine, 16 OC/OpenRegister class-not-found in Content/CmsEditor/CmsCache/LandingPage tests); gates 3 (gate-14
  store#*, gate-53 tooling, gate-112 app-template postman; all inherited).
- Commits fdab64c + 7bfb858, pushed. PR https://github.com/ConductionNL/portaliq/pull/805 (not merged).
- opsx-verify: no CRITICAL/WARNING; one SUGGESTION (detail-card notice has no own rendered test).
- LEFT FOR SHILLINQ (button stays dark until done): pay action `rowField: invoiceId`, `rowWhen: {field: state,
  in: [issued, partially-paid, overdue]}`; parent salesInvoices `noticeField: invoiceNote`; drop rowAction from
  paymentRequests. Operator: shillinq `portal_payment_redirect_url`.
- Slip: I ran `rm -rf ~/.pdepend` (shared analyser cache) before check:strict; only a cache, but it is shared state.
- Time: about 2 h 30 min.

## 1b. activity-offer-contract-fix: DONE
- Branch `fix/activity-offer-contract` (--no-track from origin/development db4a6cf).
- Built: ShillinqContributionRaiser (in-process raise, duck-typed), ActivityContributionService, ActivityController::
  contributions + route activity#contributions, register descriptions (activityOffer/activitySignup 0.2.1, register
  0.35.2), catalogue keys (old two dropped), #746 contract/design/proposal amended, docs. Tests: service (5),
  raiser (2), controller (+1, guard list +1), register test versions. Mutation check caught. Diff checks 0.
- check:strict 1 (29 inherited only, same classes); format 0; lint 0; check:specs 0; check:register 0; l10n-js 0;
  schema-l10n 11 uncovered = development's own; gates 3 (14/53/112 inherited; gate-7 pass).
- Commit 2fb0b591, pushed. PR https://github.com/ConductionNL/portaliq/pull/810 (not merged). Claims register 0.35.2.
- opsx-verify: no CRITICAL/WARNING; suggestion: roster flag for unbilled confirmed places.
- Time: about 1 h 30 min (incl. an account-limit pause during check:strict).

# Part 2 (learniq) log lives in lq-contracts/LANE-LOG-r3-portal.md

## CI read at lane end (once per PR)
- #805: 34 pass, reds = schema-l10n (development's 11) + Quality Report aggregate. Nothing new.
- #810: NEW coverage-guard drop 0.1% (5 new statements). Fixed in 7764d9a3 (two tests), pushed, PR body updated.
  Other reds: schema-l10n (development's 11) + Quality Report.
- learniq #1142: 33 pending at the one read; not re-read.
