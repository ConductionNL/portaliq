<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Guards the contract-v2 register config delta (the WHOLE config change of
 * this slice): portalAccount gains the server-managed `claims` object property
 * with a nil-UUID example, both versions bump to 0.2.0 for the repair-path
 * re-import, `required` stays untouched (union-merge caution, migration.md),
 * and the dev seed accounts carry placeholders only.
 *
 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T4
 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T9
 */
class PortaliqRegisterConfigTest extends TestCase {

	private const NIL_UUID = '00000000-0000-0000-0000-000000000000';

	/**
	 * @var array<string, mixed>
	 */
	private static array $register = [];

	/**
	 * Assert a schema is NOT readable by an anonymous visitor.
	 *
	 * Reads `authorization.read`, which is the only thing that decides this.
	 * OpenRegister has a special `public` group; its presence in a read rule
	 * is what grants anonymous access, and its absence is what withholds it.
	 *
	 * @param string $name The schema name.
	 *
	 * @return void
	 */
	private function assertNeverPublic(string $name): void {
		$schema = self::$register['components']['schemas'][$name];
		$read = $schema['authorization']['read'] ?? [];

		$this->assertNotEmpty($read, $name . ' must declare its read authorization');

		$groups = array_map(
			static fn ($rule) => is_array($rule) ? ($rule['group'] ?? null) : $rule,
			$read
		);

		$this->assertNotContains('public', $groups, $name . ' must never be anonymously readable');
	}//end assertNeverPublic()


	public static function setUpBeforeClass(): void {
		$json = (string)file_get_contents(__DIR__ . '/../../../lib/Settings/portaliq_register.json');
		self::$register = (array)json_decode($json, true);

	}//end setUpBeforeClass()

	public function testRegisterJsonParsesAndVersionsAreBumped(): void {
		$this->assertNotSame([], self::$register, 'register JSON must parse');
		// 0.12.0: declared `components.registers.portaliq` — the register
		// itself. OpenRegister's ImportHandler creates a Register row from that
		// key and nowhere else on the main/beta lines, so until now a clean
		// install created 9 schemas and ZERO registers and every
		// GET /api/objects/portaliq/<schema> answered HTTP 404
		// "Register not found: 'portaliq'". The version bump is load-bearing:
		// importFromApp is version-gated, so a frozen info.version would never
		// re-import and the register would stay absent on existing installs.
		// 0.11.0 (portalSession 0.3.0): declared `authTime` on portalSession.
		// PortalSessionService::mintSession() has always written it, but the
		// schema never described it, so OpenRegister's MagicMapper discarded it
		// on EVERY session mint ("Discarding 1 property the schema \"Portal
		// Session\" does not declare: authTime") and the stored row silently
		// lost the origin-login timestamp. Additive.
		// 0.10.0 (portalPage 0.2.0): re-seeded the SUPPLIER demo portalPage that
		// portal-page-provisioning deleted without carrying over (only the
		// citizen page shipped, so a fresh install contributed nothing to a
		// supplier), and declared the contract-v3 vocabulary the normaliser
		// already consumes but the schema never described — `label`/`kind`/
		// `rowActions`/`defaultSort`/`filesUpload`/`filesDownload` on
		// collections, `submitLabel`/`successMessage`/`fieldConfigs`/
		// `optionsProviders` on actions, and the contribution-level
		// `notifications` opt-in. All additive.
		// 0.9.0: renamed portalAuditEntry's `id` property to `targetId` so it no
		// longer collides with OpenRegister's reserved object-id key (which made
		// every append-only audit write fail). 0.8.0 added the `portalPage` schema
		// (data-provisioned portal contributions, ADR-046). Both additive.
		// 0.14.0 (portal 0.2.0): declared `tagline` — the supporting line under
		// the portal name in the footer's logo block. The reference carries one
		// and this schema had no field for it, so every portal's footer showed
		// a bare title with no way to change it.
		// 0.15.0 (page 0.2.0): declared `draftBody` — a page's unpublished layout,
		// in exactly the shape of `body`, written by the page designer and never
		// projected by the content API — and closed the schema's write rules,
		// which did not exist at all. A schema with no create/update/delete rule
		// is default-OPEN to every authenticated user in OpenRegister, so any
		// account on the instance could rewrite a published portal page; the
		// rules now name the configured editor groups (empty = admins only).
		// 0.17.0 (page 0.3.0, portal 0.3.0): two changes met there. Development
		// declared `kind` (site or external) and the `traffic` block, and added
		// the `portalTrafficEvent` and `portalTrafficDaily` schemas
		// (portal-traffic-analytics); contribution-landing-page-action added
		// the `form` and `landingPageSubmission` schemas and `heroImage` on
		// `page`. 0.18.0 (portalTrafficDaily 0.2.0): `returningVisitors` and
		// `accounts` arrive and `newVisitors` becomes nullable
		// (portal-traffic-visitors-and-geo): null in cookieless mode, because
		// a daily hash cannot say whether it was here yesterday and a zero
		// would claim it can. 0.19.0 (portalTrafficDaily 0.3.0, portal 0.4.0,
		// portalTrafficEvent 0.2.0): goals, funnels, forms, missing pages and
		// custom dimensions (portal-traffic-outcomes); the form and
		// not-found events join the enum. 0.20.0 (portalTrafficDaily 0.4.0,
		// portal 0.5.0, portalTrafficEvent 0.3.0): segments, roll-ups,
		// scheduled reports, alerts, the server token and script errors
		// (portal-traffic-reporting); `js_error` joins the enum and the daily
		// record gains `segment`, `rollupOf`, `members` and `errors`. 0.21.0
		// (portalTrafficDaily 0.5.0, portal 0.6.0, portalTrafficEvent 0.4.0,
		// portalTrafficRecording 0.1.0 new): page experiments, heatmaps and
		// session recording (portal-traffic-experiments); `heat_click` and
		// `heat_scroll` join the enum, the daily record gains `experiments`
		// and `heatmaps`, and the recording schema arrives, admin-readable
		// like the raw events. Additive. 0.22.0 (portalAuditEntry 0.2.0,
		// portalCaseType 0.1.0 new, portalCase 0.1.0 new): `complete` joins
		// the audit verb enum — a seam-confirmed portal-task completion is
		// audited like a create (WOO-569); hardValidation would otherwise
		// refuse the write silently. And the demo case and its type arrive,
		// so what a citizen may write on their own case is demonstrable
		// without a case app installed
		// (what-the-citizen-may-write-on-their-own-case). Both are
		// authenticated-read only, like every other portal-facing schema:
		// what may be WRITTEN is decided by the case type, through portaliq,
		// never by a grant on the schema. 0.23.0 (portalPage 0.3.0): the
		// `citizenCase` block type joins the page block enum, which the
		// resolver already accepted, so a contribution carrying the citizen
		// case block can be saved at all. Additive. 0.24.0 (portalAccount
		// 0.8.0): the repair of a drift, not a feature. Seven schemas landed
		// between #596 and #616 (portalMandate, portalInvitation,
		// portalReferenceLink, portalAccessRequest, portalFormBinding,
		// portalIntakeSubmission, changeProposal) and portalAccount went
		// 0.5.0 -> 0.8.0 with them, while `info.version` stayed at 0.23.0 and
		// `components.registers.portaliq.schemas` was never extended. The
		// seven therefore provisioned as schemas and bound to no register,
		// which is the silent partial outage the sibling test below exists to
		// make loud, and the unchanged info.version meant no upgrade would
		// have re-imported the fix either. This test could not say so: every
		// PHPUnit cell aborted before it ran, on `occ app:enable dossiq`.
		// Additive.
		// 0.25.0: `traffic` declares `widget: "json"`, so the block renders in
		// the portal form at all. `fieldsFromSchema` (nextcloud-vue
		// src/utils/schema.js) drops an object property carrying neither a
		// widget nor a $ref, which is why measurement could not be switched on
		// from the interface. Its own CONFIGURATION version because 0.24.0 was
		// already taken by the schema-binding fix: a change that shares a
		// version with one already imported never re-imports, and the field
		// would have stayed missing.
		// 0.27.0 (portalTrafficDaily 0.6.0): each row of `pages` gains
		// `sessions`, `visitors`, `engagedSessions`, `referrers` and
		// `outbound` (portal-page-traffic), and `path` is the in-site route.
		// Additive: a row written before them simply lacks them, and the
		// page endpoint reads the absence as "not counted", never zero.
		// Written as 0.26.0 on its branch; development took 0.26.0 first for
		// portalAuditEntry (below), so this change moved to 0.27.0 or it
		// would never re-import on an instance already at 0.26.0.
		// The `portal` SCHEMA version deliberately stays at 0.6.0. An earlier
		// draft of this comment said 0.7.0 and the assertion below said 0.6.0;
		// the assertion was right. ImportHandler treats a schema's version as
		// "an OPTIMISATION, not the source of truth" and re-imports whenever
		// `schemaContentDiffers()` sees different `properties`, which adding a
		// `widget` key does. Bumping it here would not help either: this
		// configuration version is already published, and a version-only edit
		// under the SAME configuration version is exactly the no-op the
		// paragraph above describes.
		// That the pattern reaches the form is not assumed: integriq's
		// `consumer` schema already stores three `type: object` properties
		// carrying `widget: "json"` (authorizationConfiguration, rateLimit,
		// quota), so OpenRegister demonstrably persists the key rather than
		// dropping it on save.
		// 0.26.0 (portalAuditEntry 0.2.0): `complete` joins the audit verb
		// enum. Described under 0.22.0 above, where this branch first wrote
		// it; development reached 0.24.0 and then 0.25.0 first, so it is
		// re-parented here.
		// The bump is the point, not bookkeeping: OpenRegister re-imports a
		// register only when `info.version` moves, so leaving this at 0.25.0
		// would ship the enum to a clean install and to nobody else --
		// exactly the silent non-upgrade the 0.24.0 and 0.25.0 notes above
		// describe.
		// Additive.
		// 0.28.0 (portalAccount 0.9.0): `notificationChannels` joins the
		// schema (notification-preferences-per-role) -- shipped on its own
		// branch with the schema property added but NO version bump anywhere,
		// which is exactly the silent-non-upgrade failure mode this test
		// exists to catch: an instance already on any prior version would
		// never have picked the property up. Caught and fixed at merge time,
		// moved to 0.28.0 because development had already taken 0.27.0 for
		// portalTrafficDaily's per-page rows (above) by the time this merged.
		// Additive.
		// 0.27.0 (portalPoll/portalPollResponse): written as 0.27.0 on its own
		// branch, added `portalPoll`/`portalPollResponse` (parent-polls,
		// learniq round-1 finding 9.9) and listed both in
		// `components.registers.portaliq.schemas`. Development took 0.27.0
		// first for portalTrafficDaily's per-page `pages` rows (a parallel
		// branch, same race the 0.26.0 note above describes), so THIS change
		// moved to 0.28.0 on merge, and then to 0.29.0 because development
		// took 0.28.0 for portalAccount's `notificationChannels` (above) first.
		// It would never re-import on an instance already at 0.28.0 otherwise.
		// Additive; no content conflict with either change, only the version
		// number.
		// 0.30.0 (newsItem/newsletter/guardianAudienceFixture): added on the
		// news-and-newsletter-authoring branch with NO version bump (it still
		// read 0.27.0), so an instance already on 0.29.0 would never import
		// the three schemas. Bumped past development's 0.29.0 at merge time.
		// Additive.
		// 0.31.0 (schoolEvent/eventRsvp/eventSignup): added on the
		// events-and-signups branch with NO version bump (it still read
		// 0.27.0). That branch also carried its own copy of
		// guardianAudienceFixture; development's (0.30.0, news) copy is the
		// one kept. Bumped past development's 0.30.0 at merge time. Additive.
		// 0.32.0 (messageThread/guardianMessage/groupStaffFixture): added on the
		// guardian-direct-messages branch with NO version bump (it still read
		// 0.27.0); its own guardianAudienceFixture copy was a subset of
		// development's, which is kept. Bumped past development's 0.31.0 at
		// merge time. Additive.
		// 0.33.0 (pushSubscription/notificationQuietHours/pendingPush): added on the
		// push-notifications-quiet-hours branch with NO version bump (it still
		// read 0.27.0); its own guardianAudienceFixture copy was a subset of
		// development's, which is kept. Bumped past development's 0.32.0 at
		// merge time. Additive.
		// 0.33.1: no schema change; bumped past development's 0.33.0 when the assignment-portal-file-upload branch landed.
		// 0.34.0 (activityOffer/activitySignup/activityAttendance): term-long
		// activities with places, a waiting list and attendance per session
		// (extracurricular-activity-offer). Sign-ups and attendance hold
		// children's data, so their read rule is `admin` only. Additive.
		// 0.35.0 (activityOffer 0.2.0, activitySignup 0.2.0): an activity can
		// require a guardian's consent to a stated text, kept on the sign-up as
		// agreed, and say photos are taken (activity-parental-consent). Additive.
		// 0.35.1: no schema change; bumped past development's 0.35.0 when the portal-take-assessment branch landed.
		// 0.35.2: no schema change; bumped past development's 0.35.1 when the contribution-pay-screen branch landed.
		// 0.35.3 (activityOffer 0.2.1, activitySignup 0.2.1): descriptions only;
		// portaliq, not shillinq, writes `paymentRequestRef` from the raise
		// answer (activity-offer-contract-fix). The branch read 0.35.2, which
		// development had already taken; bumped past it at merge time.
		// 0.36.0 (portalAccount 0.10.0, guardianMessage 0.2.0): a guardian picks
		// the language school messages are shown in (`messageLanguage`), and a
		// message keeps its AI translations with their provenance next to the
		// original body (`translations`) (translated-message-notice, D24). Additive.
		// 0.36.1 (portalCaseType 0.2.0): the report declaration names an optional
		// `handlerGroup`, the group whose members may read, answer and ask about
		// a report beside the custodian group (portaliq#799). Additive.
		// 0.36.2 (portalReporterContact 0.2.0): what a reporter gave is readable
		// by `admin` only, not by every signed-in user; portaliq reads it only
		// inside an allowed reveal, with RBAC off (portaliq#800).
		// 0.37.0 (newsItem 0.2.0): a news item keeps its AI translations with
		// their provenance next to the original body (`translations`), the
		// same shape a guardianMessage keeps (news-item-translation, D24). Additive.
		// 0.37.1 (newsItem 0.2.1): a translation entry also carries the title,
		// translated with the body under the same notice
		// (news-title-and-newsletter-translation). Description only.
		// 0.38.0 (newsletter 0.2.0): a newsletter keeps the AI translations of
		// its own title (`translations`), in the shape a newsItem keeps
		// (newsletter-title-translation). Additive.
		// 0.39.0 (portalMessage 0.5.0, portalNotification 0.2.0, portalAccount
		// 0.11.0): a message names the record it is about (`recordLink`), a
		// notification attempt can be a `push`, and an account keeps its
		// per-kind notice choices (`notificationPreferences`)
		// (inbox-notifications-and-preferences). Additive.
		// 0.40.0 (portal 0.7.0): a portal lists the case types it does not
		// show to residents (`hiddenCaseTypes`) (operate-show-per-case-type).
		// Additive; empty shows every case type, as before.
		// 0.41.0 (portalAvailabilityDaily 0.1.0, portalAvailabilityOutage
		// 0.1.0): each published portal's availability per day and its
		// outages, read by administrators only (operate-availability-report).
		// Additive.
		// Every new schema is listed in
		// `components.registers.portaliq.schemas` (ImportHandler binds only
		// what is listed there) and declares a non-empty `read` rule.
		// 0.42.0 (portalNotification 0.3.0, portalAccount 0.12.0): the
		// government message box channel (inbox-berichtenbox-channel). The
		// channel enum gains `messageBox`, the status enum `delivered`, `read`
		// and `simulated`; `externalMessageId`, `recordLink` and `refusalCode`
		// are new; the account's preferences describe `messageBox.enabled`.
		// Additive.
		// 0.43.0 (page 0.4.0): a page's search-engine fields `seoTitle`,
		// `seoDescription`, `seoNoindex` and `seoImage`
		// (site-page-seo-history-and-media). Additive.
		// 0.44.0 (media 0.1.0): a portal's media library
		// (site-page-seo-history-and-media T06). New schema, additive.
		// 0.45.0 (portalOidcState 0.2.0): a state row names its login `route`,
		// and `codeVerifier` is no longer required, because an integriq broker
		// row has none (signin-integriq-broker-login T03). Additive.
		// 0.47.0 (portalNotice 0.1.0): a portal's maintenance and warning
		// notices (operate-maintenance-notice T01). New schema, additive.
		// 0.46.0 (portal 0.8.0): the portal's shell, `headerVariant`,
		// `authentication.register` and `registerLabel`, `footer` and
		// `regions` (portal-theme-blocks-and-contributed-pages tasks 4-7);
		// page 0.5.0: `body.clearedRegions` and `draftBody.clearedRegions`.
		// Additive.
		$this->assertSame('0.47.0', self::$register['info']['version']);
		$this->assertSame('0.47.0', self::$register['components']['registers']['portaliq']['version']);
		$this->assertSame('0.5.0', self::$register['components']['schemas']['page']['version']);
		$this->assertSame(70, self::$register['components']['schemas']['page']['properties']['seoTitle']['maxLength']);
		$this->assertSame(160, self::$register['components']['schemas']['page']['properties']['seoDescription']['maxLength']);
		$this->assertSame('boolean', self::$register['components']['schemas']['page']['properties']['seoNoindex']['type']);
		foreach (['portalAvailabilityDaily', 'portalAvailabilityOutage'] as $availability) {
			$this->assertSame('0.1.0', self::$register['components']['schemas'][$availability]['version']);
			$this->assertSame(['admin'], self::$register['components']['schemas'][$availability]['authorization']['read']);
			$this->assertContains($availability, self::$register['components']['registers']['portaliq']['schemas']);
		}
		$this->assertSame(['site-error', 'timeout', 'health-degraded', 'no-check'], self::$register['components']['schemas']['portalAvailabilityOutage']['properties']['cause']['enum']);
		$this->assertSame('array', self::$register['components']['schemas']['portal']['properties']['hiddenCaseTypes']['type']);
		$this->assertSame(['typeId'], self::$register['components']['schemas']['portal']['properties']['hiddenCaseTypes']['items']['required']);
		$this->assertSame('object', self::$register['components']['schemas']['portalMessage']['properties']['recordLink']['type']);
		$this->assertSame('object', self::$register['components']['schemas']['portalAccount']['properties']['notificationPreferences']['type']);
		$this->assertSame('0.2.0', self::$register['components']['schemas']['newsletter']['version']);
		$this->assertArrayHasKey('translations', self::$register['components']['schemas']['newsletter']['properties']);
		$this->assertSame('array', self::$register['components']['schemas']['newsletter']['properties']['translations']['type']);
		$this->assertStringContainsString('title', self::$register['components']['schemas']['newsletter']['properties']['translations']['description']);
		$this->assertSame('0.2.1', self::$register['components']['schemas']['newsItem']['version']);
		$this->assertStringContainsString('title', self::$register['components']['schemas']['newsItem']['properties']['translations']['description']);
		$this->assertArrayHasKey('translations', self::$register['components']['schemas']['newsItem']['properties']);
		$this->assertSame('0.2.0', self::$register['components']['schemas']['portalReporterContact']['version']);
		$this->assertSame('0.2.0', self::$register['components']['schemas']['portalCaseType']['version']);
		$this->assertArrayHasKey('handlerGroup', self::$register['components']['schemas']['portalCaseType']['properties']['portalReportDeclaration']['properties']);
		$this->assertSame('0.2.0', self::$register['components']['schemas']['guardianMessage']['version']);
		$this->assertArrayHasKey('translations', self::$register['components']['schemas']['guardianMessage']['properties']);
		$this->assertArrayHasKey('messageLanguage', self::$register['components']['schemas']['portalAccount']['properties']);
		$this->assertSame('0.2.1', self::$register['components']['schemas']['activityOffer']['version']);
		$this->assertSame('0.2.1', self::$register['components']['schemas']['activitySignup']['version']);
		$this->assertSame('0.1.0', self::$register['components']['schemas']['activityAttendance']['version']);
		$this->assertArrayHasKey('consent', self::$register['components']['schemas']['activitySignup']['properties']);

		$this->assertSame(['admin'], self::$register['components']['schemas']['activitySignup']['authorization']['read']);
		$this->assertSame(['admin'], self::$register['components']['schemas']['activityAttendance']['authorization']['read']);
		$this->assertArrayNotHasKey('fee', self::$register['components']['schemas']['activityOffer']['properties'], 'D19: an activity holds no amount');
		$this->assertArrayNotHasKey('amount', self::$register['components']['schemas']['activityOffer']['properties'], 'D19: an activity holds no amount');
		$this->assertSame('0.2.0', self::$register['components']['schemas']['portalAuditEntry']['version']);
		$this->assertContains('complete', self::$register['components']['schemas']['portalAuditEntry']['properties']['verb']['enum']);
		$this->assertSame('0.1.0', self::$register['components']['schemas']['portalCase']['version']);
		$this->assertSame(['authenticated'], self::$register['components']['schemas']['portalCase']['authorization']['read']);
		$this->assertSame('0.6.0', self::$register['components']['schemas']['portalTrafficDaily']['version']);
		$this->assertSame('0.4.0', self::$register['components']['schemas']['portalTrafficEvent']['version']);
		$this->assertSame('0.1.0', self::$register['components']['schemas']['portalTrafficRecording']['version']);
		$this->assertSame(['admin'], self::$register['components']['schemas']['portalTrafficRecording']['authorization']['read']);
		$this->assertContains('portalTrafficRecording', self::$register['components']['registers']['portaliq']['schemas']);
		$this->assertSame('0.5.0', self::$register['components']['schemas']['page']['version']);
		$this->assertSame('0.8.0', self::$register['components']['schemas']['portal']['version']);
		$this->assertSame('0.12.0', self::$register['components']['schemas']['portalAccount']['version']);
		$this->assertSame('0.3.0', self::$register['components']['schemas']['portalPage']['version']);
		$this->assertSame('0.3.0', self::$register['components']['schemas']['portalSession']['version']);

	}//end testRegisterJsonParsesAndVersionsAreBumped()

	/**
	 * The register must declare ITSELF, listing exactly its own schemas.
	 *
	 * OpenRegister's ImportHandler creates a Register row only from
	 * `components.registers` (ImportHandler.php:1514) on the main/beta lines.
	 * Without it a clean install provisions the schemas and no register, the
	 * import still reports success, and every object route 404s — a silent
	 * outage this test exists to make loud.
	 *
	 * The schema list is asserted to be the EXACT set of schema SLUGS, not
	 * components.schemas keys: ImportHandler keys its schemasMap by
	 * $schema->getSlug(), so a register listing the keys binds ZERO schemas
	 * while still looking correctly declared. A register listing only SOME
	 * schemas is the same silent partial outage, one schema at a time.
	 */
	public function testRegisterDeclaresItselfWithExactlyItsOwnSchemaSlugs(): void {
		$registers = (self::$register['components']['registers'] ?? []);
		$this->assertArrayHasKey('portaliq', $registers, 'the portaliq register must be declared');

		$declared = $registers['portaliq'];
		$this->assertSame('portaliq', $declared['slug']);
		$this->assertSame(
			self::$register['info']['version'],
			$declared['version'],
			'the register version must track info.version, or a later schema change never re-imports'
		);

		$expected = [];
		foreach ((self::$register['components']['schemas'] ?? []) as $key => $schema) {
			$expected[] = ($schema['slug'] ?? $key);
		}

		sort($expected);
		$actual = $declared['schemas'];
		sort($actual);

		$this->assertSame($expected, $actual, 'the register must list exactly its own schema slugs');

	}//end testRegisterDeclaresItselfWithExactlyItsOwnSchemaSlugs()

	/**
	 * Every property `PortalSessionService::mintSession()` writes MUST be
	 * declared on the portalSession schema. An undeclared key is not an error
	 * anywhere in the stack — OpenRegister's MagicMapper drops it with a log
	 * line and the write still returns success — so this is the only place the
	 * loss is detectable. Regression guard for the discarded `authTime`.
	 *
	 * @return void
	 */
	public function testPortalSessionDeclaresEveryPropertyTheMinterWrites(): void {
		$declared = array_keys(
			(array)self::$register['components']['schemas']['portalSession']['properties']
		);

		// The literal payload of PortalSessionService::mintSession()'s
		// createObject() call. Keep in step with it.
		$written = [
			'subjectRef',
			'audience',
			'organisation',
			'jti',
			'trustLevel',
			'issuedAt',
			'expiresAt',
			'revoked',
			'authTime',
		];

		foreach ($written as $property) {
			$this->assertContains(
				$property,
				$declared,
				"portalSession must declare `$property` — mintSession() writes it, and MagicMapper silently discards anything the schema does not declare."
			);
		}

	}//end testPortalSessionDeclaresEveryPropertyTheMinterWrites()

	/**
	 * The `audience` property on portalAccount and portalSession MUST be an
	 * open string (no enum) so contract-v2 audiences (citizen, student, parent,
	 * …) can be persisted without a schema change. Regression guard for #20:
	 * the closed [supplier, client] enum blocked every new-audience account at
	 * the data layer.
	 *
	 * @return void
	 */
	public function testAudienceIsOpenStringNotEnumConstrained(): void {
		foreach (['portalAccount', 'portalSession'] as $slug) {
			$audience = self::$register['components']['schemas'][$slug]['properties']['audience'];
			$this->assertSame('string', $audience['type']);
			$this->assertArrayNotHasKey('enum', $audience, "$slug.audience must not be enum-constrained (#20)");
		}

	}//end testAudienceIsOpenStringNotEnumConstrained()

	public function testPortalAccountClaimsPropertyIsServerManagedShape(): void {
		$account = self::$register['components']['schemas']['portalAccount'];

		$claims = $account['properties']['claims'];
		$this->assertSame('object', $claims['type']);
		// The documented example demonstrates {appId: {claimName: uuid}} with a
		// nil UUID — placeholders only, never a real identifier.
		$this->assertSame(self::NIL_UUID, $claims['example']['pipelinq']['linkedContactId']);

		// The claim map lives on a never-public schema (Risk 3 in proposal.md).
		$this->assertNeverPublic('portalAccount');

	}//end testPortalAccountClaimsPropertyIsServerManagedShape()

	public function testPortalAccountRequiredListIsUnchanged(): void {
		// Union-merge caution (migration.md): the additive property must not
		// touch the required list — claims stays OPTIONAL.
		$this->assertSame(
			['audience', 'subjectRef', 'organisation'],
			self::$register['components']['schemas']['portalAccount']['required']
		);

	}//end testPortalAccountRequiredListIsUnchanged()

	/**
	 * portal-notifications-dispatch T01: the new `portalNotification` log
	 * schema is never publicly readable/writable, and `portalAccount` gains the
	 * optional `needsAlternativeContact` fallback flag (WMEBV notificatieplicht,
	 * ~Awb 2:11) without touching the `required` list.
	 */
	public function testPortalNotificationSchemaIsAddedAndNeverPublic(): void {
		$schemas = self::$register['components']['schemas'];
		$this->assertArrayHasKey('portalNotification', $schemas, 'portalNotification schema must exist');

		$notification = $schemas['portalNotification'];
		$this->assertNeverPublic('portalNotification');
		$this->assertSame(
			['accountRef', 'ruleKey', 'channel', 'status', 'attempts', 'lastAttemptAt'],
			$notification['required']
		);
		$this->assertSame(['email', 'push', 'messageBox'], $notification['properties']['channel']['enum']);
		$this->assertSame(['sent', 'failed', 'delivered', 'read', 'simulated'], $notification['properties']['status']['enum']);

		$account = $schemas['portalAccount'];
		$this->assertSame('boolean', $account['properties']['needsAlternativeContact']['type']);
		// Union-merge caution (migration.md): the additive property must not
		// touch the required list — needsAlternativeContact stays OPTIONAL.
		$this->assertSame(['audience', 'subjectRef', 'organisation'], $account['required']);

	}//end testPortalNotificationSchemaIsAddedAndNeverPublic()

	/**
	 * portal-oidc-broker-login T01/T08: `portalAccount.identityType` gains the
	 * additive `generic` enum member (a broker-agnostic OIDC provider preset
	 * for a broker that is none of digid/eherkenning/eidas), and the new
	 * `portalOidcState` schema (single-use start→callback state/nonce/PKCE
	 * storage) is never publicly readable/writable and never touches
	 * `portalAccount`'s `required` list.
	 */
	public function testPortalOidcStateSchemaIsAddedAndNeverPublic(): void {
		$schemas = self::$register['components']['schemas'];
		$this->assertArrayHasKey('portalOidcState', $schemas, 'portalOidcState schema must exist');

		$state = $schemas['portalOidcState'];
		$this->assertNeverPublic('portalOidcState');
		$this->assertSame(
			['state', 'nonce', 'org', 'provider', 'expiresAt'],
			$state['required']
		);
		$this->assertSame('0.2.0', $state['version']);
		$this->assertSame(['oidc', 'broker'], $state['properties']['route']['enum']);

		$account = $schemas['portalAccount'];
		$this->assertSame(
			['eherkenning', 'digid', 'eidas', 'generic', 'dev'],
			$account['properties']['identityType']['enum']
		);
		// Union-merge caution (migration.md): the additive enum member must not
		// touch the required list.
		$this->assertSame(['audience', 'subjectRef', 'organisation'], $account['required']);

	}//end testPortalOidcStateSchemaIsAddedAndNeverPublic()

	public function testSeedAccountsUsePlaceholdersAndProveBothClaimStates(): void {
		$objects = (array)(self::$register['components']['objects'] ?? []);
		$accounts = [];
		foreach ($objects as $object) {
			if ((($object['@self']['schema'] ?? '') === 'portalAccount') === true) {
				$accounts[$object['@self']['slug']] = $object;
			}
		}

		$this->assertArrayHasKey('dev-supplier-account', $accounts);
		$this->assertArrayHasKey('dev-client-account', $accounts);
		$this->assertArrayHasKey('second-supplier-account', $accounts);

		// Object 1 proves the claim shape end-to-end (nil UUID only)...
		$supplier = $accounts['dev-supplier-account'];
		$this->assertSame(self::NIL_UUID, $supplier['claims']['portaliq']['exampleContactId']);
		$this->assertSame('dev-supplier', $supplier['subjectRef']);

		// ...and Object 2 proves the fail-closed-empty path (no claims).
		$this->assertSame([], $accounts['dev-client-account']['claims']);

		// Placeholders only: every seeded identityRef screams EXAMPLE.
		foreach ($accounts as $slug => $account) {
			$this->assertStringStartsWith('EXAMPLE_', (string)$account['identityRef'], "seed '{$slug}' must use placeholder identityRef");
		}

	}//end testSeedAccountsUsePlaceholdersAndProveBothClaimStates()

	/**
	 * Every value the message box channel writes into a `portalNotification`
	 * fits the schema, checked with a JSON Schema validator against the real
	 * fragment (inbox-berichtenbox-channel, REQ-MBC-003): the row a send
	 * writes, and each status integriq can report back. And the schema holds
	 * no property that could carry the recipient.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-portaliq-asks-integriq-to-send-and-records-the-answer-req-mbc-003
	 */
	public function testTheMessageBoxRowsFitThePortalNotificationSchema(): void {
		$schema = self::$register['components']['schemas']['portalNotification'];
		$this->assertSame('0.3.0', $schema['version']);
		$this->assertSame('0.12.0', self::$register['components']['schemas']['portalAccount']['version']);
		$jsonSchema = json_decode((string)json_encode(['type' => 'object', 'required' => $schema['required'], 'properties' => $schema['properties']]), false);

		$row = [
			'accountRef' => 'account-1',
			'organisation' => 'venray',
			'ruleKey' => 'message.created',
			'appId' => 'dossiq',
			'channel' => 'messageBox',
			'status' => 'sent',
			'attempts' => 0,
			'lastAttemptAt' => '2026-09-29T10:00:00+00:00',
			'externalMessageId' => 'b1e2c3d4-0000-4000-8000-000000000001',
			'recordLink' => ['app' => 'dossiq', 'collection' => 'berichten', 'id' => 'bericht-1'],
		];
		foreach (['sent', 'failed', 'delivered', 'read', 'simulated'] as $status) {
			$result = (new Validator())->validate(json_decode((string)json_encode(['status' => $status] + $row), false), $jsonSchema);
			$this->assertTrue($result->isValid(), "status {$status} fits the schema");
		}

		$refused = ['status' => 'failed', 'refusalCode' => 'not_installed'] + $row;
		unset($refused['externalMessageId']);
		$result = (new Validator())->validate(json_decode((string)json_encode($refused), false), $jsonSchema);
		$this->assertTrue($result->isValid(), 'a refusal fits the schema');

		$result = (new Validator())->validate(json_decode((string)json_encode(['channel' => 'postcard'] + $row), false), $jsonSchema);
		$this->assertFalse($result->isValid(), 'the channel enum still refuses an unknown channel');

		foreach (array_keys($schema['properties']) as $property) {
			$this->assertDoesNotMatchRegularExpression('/recipient|bsn|identity/i', $property, 'no property can carry the recipient');
		}

	}//end testTheMessageBoxRowsFitThePortalNotificationSchema()

	/**
	 * site-page-seo-history-and-media T06 (REQ-SPH-004): a media item names its
	 * portal, kind and status, validated with the real schema fragment. The
	 * alternative-text rule for an image is portaliq's own
	 * (MediaRulesTest): OpenRegister keeps no conditional schema rule.
	 *
	 * @return void
	 */
	public function testAMediaItemNamesItsPortalKindAndStatus(): void {
		$schema = self::$register['components']['schemas']['media'];
		$this->assertSame('0.1.0', $schema['version']);
		$this->assertContains('media', self::$register['components']['registers']['portaliq']['schemas']);
		$jsonSchema = json_decode((string)json_encode(['type' => 'object', 'required' => $schema['required'], 'properties' => $schema['properties']]), false);

		$valid = static fn (array $item): bool => (new Validator())->validate(json_decode((string)json_encode($item), false), $jsonSchema)->isValid();

		$this->assertTrue($valid(['portal' => 'gemeente', 'title' => 'Stadhuis', 'kind' => 'image', 'status' => 'published', 'alt' => 'Het stadhuis aan de Markt']));
		$this->assertTrue($valid(['portal' => 'gemeente', 'title' => 'Reglement', 'kind' => 'file', 'status' => 'draft']));
		$this->assertFalse($valid(['portal' => 'gemeente', 'title' => 'X', 'kind' => 'video', 'status' => 'draft']), 'the kind is image or file');
		$this->assertFalse($valid(['title' => 'X', 'kind' => 'file', 'status' => 'draft']), 'an item belongs to a portal');
		$this->assertArrayNotHasKey('if', $schema, 'OpenRegister would drop a conditional rule on import');

		$groups = array_map(static fn ($rule) => is_array($rule) ? ($rule['group'] ?? null) : $rule, $schema['authorization']['read']);
		$this->assertNotContains('public', $groups, 'the public reach an item through the content API, never through OpenRegister');
	}//end testAMediaItemNamesItsPortalKindAndStatus()


	/**
	 * operate-maintenance-notice T01 (REQ-OMN-001, REQ-OMN-003): a notice has
	 * a required end, a level, the surfaces it shows on and a status,
	 * validated with the real schema fragment. The public never read it
	 * through OpenRegister: only the active notices reach them, from
	 * portaliq's own endpoints.
	 *
	 * @return void
	 */
	public function testANoticeNeedsAnEndAndNamesWhereItShows(): void {
		$schema = self::$register['components']['schemas']['portalNotice'];
		$this->assertSame('0.1.0', $schema['version']);
		$this->assertContains('portalNotice', self::$register['components']['registers']['portaliq']['schemas']);
		$jsonSchema = json_decode((string)json_encode(['type' => 'object', 'required' => $schema['required'], 'properties' => $schema['properties']]), false);

		$valid = static fn (array $notice): bool => (new Validator())->validate(json_decode((string)json_encode($notice), false), $jsonSchema)->isValid();
		$notice = [
			'portal'   => 'gemeente',
			'message'  => 'Saturday from 22:00 to 02:00 you cannot submit requests.',
			'level'    => 'warning',
			'startsAt' => '2026-10-03T10:00:00+02:00',
			'endsAt'   => '2026-10-04T02:00:00+02:00',
			'surfaces' => ['site', 'portal'],
			'status'   => 'published',
		];

		$this->assertTrue($valid($notice));
		$this->assertTrue($valid($notice + ['linkLabel' => 'Meer over het onderhoud', 'linkUrl' => 'https://www.example.nl/onderhoud']));
		$this->assertFalse($valid(array_diff_key($notice, ['endsAt' => true])), 'no notice without an end');
		$this->assertFalse($valid(['surfaces' => ['intranet']] + $notice), 'a notice shows on the site or the portal');
		$this->assertFalse($valid(['surfaces' => []] + $notice), 'a notice shows somewhere');
		$this->assertFalse($valid(['level' => 'critical'] + $notice), 'information or a warning');
		$this->assertFalse($valid(['message' => str_repeat('x', 281)] + $notice), 'at most 280 characters');
		$this->assertFalse($valid(['linkUrl' => 'http://www.example.nl'] + $notice), 'only an https link');

		$groups = array_map(static fn ($rule) => is_array($rule) ? ($rule['group'] ?? null) : $rule, $schema['authorization']['read']);
		$this->assertNotContains('public', $groups, 'the public reach only the active notices, through portaliq');
	}//end testANoticeNeedsAnEndAndNamesWhereItShows()

	/**
	 * portal-theme-blocks-and-contributed-pages REQ-PTB-004: the portal
	 * declares its header shape and register destination, validated with the
	 * real schema fragment. An undeclared field would be accepted, echoed and
	 * not stored (045b168), so each one is a schema property.
	 *
	 * @return void
	 */
	public function testThePortalDeclaresItsHeaderShapeAndRegisterPage(): void {
		$schema = self::$register['components']['schemas']['portal'];
		$valid  = $this->portalValidator(schema: $schema);

		$this->assertTrue($valid(['title' => 'Docs', 'slug' => 'docs', 'headerVariant' => 'single', 'authentication' => ['modes' => ['digid'], 'register' => '/registreren', 'registerLabel' => 'Account maken']]));
		$this->assertFalse($valid(['title' => 'Docs', 'slug' => 'docs', 'headerVariant' => 'triple']), 'the header shape is double or single');
		$this->assertNotEmpty($schema['properties']['headerVariant']['description']);
		$this->assertNotEmpty($schema['properties']['authentication']['properties']['register']['description']);
	}//end testThePortalDeclaresItsHeaderShapeAndRegisterPage()

	/**
	 * portal-theme-blocks-and-contributed-pages REQ-PTB-005: the footer the
	 * content API projects is a schema property, validated with the real
	 * fragment.
	 *
	 * @return void
	 */
	public function testThePortalDeclaresItsFooter(): void {
		$schema = self::$register['components']['schemas']['portal'];
		$valid  = $this->portalValidator(schema: $schema);

		$this->assertTrue($valid(['title' => 'Docs', 'footer' => [
			'description' => 'Eén loket',
			'colophon'    => 'Gemeente Voorbeeld',
			'socials'     => [['label' => 'Mastodon', 'href' => 'https://social.example', 'icon' => 'mastodon']],
			'legalLinks'  => [['label' => 'Privacy', 'href' => '/privacy']],
			'badges'      => [['label' => 'ISO 27001', 'href' => 'https://cert.example']],
		]]));
		$this->assertFalse($valid(['title' => 'Docs', 'footer' => ['socials' => 'https://social.example']]), 'socials is a list');
		$this->assertSame(['description', 'colophon', 'socials', 'legalLinks', 'badges'], array_keys($schema['properties']['footer']['properties']));
	}//end testThePortalDeclaresItsFooter()

	/**
	 * portal-theme-blocks-and-contributed-pages REQ-PTB-009: a portal fills
	 * regions and a page empties them, validated with the real fragments.
	 *
	 * @return void
	 */
	public function testThePortalFillsRegionsAndAPageEmptiesThem(): void {
		$portal = self::$register['components']['schemas']['portal'];
		$valid  = $this->portalValidator(schema: $portal);

		$this->assertTrue($valid(['title' => 'Docs', 'regions' => ['hero' => [['widgetKey' => 'hero', 'props' => ['title' => 'Welkom']]], 'footer' => []]]));
		$this->assertFalse($valid(['title' => 'Docs', 'regions' => ['hero' => [['props' => ['title' => 'Welkom']]]]]), 'a region widget names its widget key');
		$this->assertSame(['header', 'hero', 'main', 'aside', 'footer'], array_keys($portal['properties']['regions']['properties']));

		$page = self::$register['components']['schemas']['page'];
		foreach (['body', 'draftBody'] as $body) {
			$fragment = json_decode((string)json_encode($page['properties'][$body]), false);
			$check    = static fn (array $value): bool => (new Validator())->validate(json_decode((string)json_encode($value), false), $fragment)->isValid();
			$this->assertTrue($check(['type' => 'grid', 'widgets' => [], 'clearedRegions' => ['hero', 'aside']]), $body);
			$this->assertFalse($check(['type' => 'grid', 'widgets' => [], 'clearedRegions' => ['sidebar']]), $body.' clears known regions only');
		}
	}//end testThePortalFillsRegionsAndAPageEmptiesThem()

	/**
	 * A validator for portal records against the real schema fragment.
	 *
	 * @param array $schema The portal schema.
	 *
	 * @return \Closure(array): bool
	 */
	private function portalValidator(array $schema): \Closure {
		$jsonSchema = json_decode((string)json_encode(['type' => 'object', 'properties' => $schema['properties']]), false);

		return static fn (array $portal): bool => (new Validator())->validate(json_decode((string)json_encode($portal), false), $jsonSchema)->isValid();
	}//end portalValidator()

	/**
	 * The public surface, pinned BY NAME.
	 *
	 * This is the list that decides what an anonymous visitor can read once
	 * `portal-public-search` ships, so it is asserted per schema rather than
	 * by count — a count passes while the wrong three are public, and the
	 * wrong ones here are sessions and submissions.
	 *
	 * ⚠️ `x-openregister.publicRead` / `publicWrite` USED TO SIT ON THESE
	 * SCHEMAS AND WERE NEVER A THING. Not an unenforced flag — not part of
	 * OpenRegister's schema contract at all: zero consumers in its lib, its
	 * JS, its migrations, or as a property on the Schema entity. They came in
	 * with the app scaffold (`nextcloud-app-template`, which ships them and no
	 * `authorization` block) and were removed here. Public access is granted
	 * ONLY by OR's special `public` group in a read rule.
	 *
	 * @return void
	 */
	public function testExactlyThreeSchemasAreReadableByAnonymousVisitors(): void {
		$schemas = self::$register['components']['schemas'];

		$public = [];
		foreach ($schemas as $name => $schema) {
			foreach (($schema['authorization']['read'] ?? []) as $rule) {
				$group = is_array($rule) ? ($rule['group'] ?? null) : $rule;
				if ($group === 'public') {
					$public[] = $name;
				}
			}
		}

		sort($public);
		$this->assertSame(
			['glossaryTerm', 'menu', 'page'],
			$public,
			'the anonymous-readable set changed — this is the portal public surface'
		);
	}//end testExactlyThreeSchemasAreReadableByAnonymousVisitors()


	/**
	 * Every schema declares its authorization; none relies on the default.
	 *
	 * OpenRegister's absent-authorization default is fail-OPEN today
	 * (`rbac-default-authenticated` changes that). Until it does, a schema
	 * that declares nothing is readable — so "we did not say" must not be a
	 * state any portaliq schema is in, whichever way the default lands.
	 *
	 * @return void
	 */
	public function testNoSchemaFallsThroughToTheRbacDefault(): void {
		$unmarked = [];
		foreach (self::$register['components']['schemas'] as $name => $schema) {
			if (empty($schema['authorization']['read'] ?? []) === true) {
				$unmarked[] = $name;
			}
		}

		$this->assertSame([], $unmarked, 'these schemas would inherit whatever the default happens to be');
	}//end testNoSchemaFallsThroughToTheRbacDefault()


	/**
	 * The portal record is NOT anonymously readable, and that is deliberate.
	 *
	 * `portal` carries `domains[].verificationToken` — the DNS proof-of-control
	 * nonce — and `authentication.oidc` provider configuration. A blanket
	 * public read would hand any anonymous caller another tenant's
	 * verification token, which is the whole of what stops a tenant claiming a
	 * domain it does not own.
	 *
	 * The portal's PUBLIC face is a curated projection served by the content
	 * API (title, slug, theme, logo, locales, authentication.modes) — chosen
	 * fields, not the row.
	 *
	 * @return void
	 */
	public function testThePortalRecordIsNotAnonymouslyReadable(): void {
		$portal = self::$register['components']['schemas']['portal'];

		$groups = array_map(
			static fn ($rule) => is_array($rule) ? ($rule['group'] ?? null) : $rule,
			$portal['authorization']['read']
		);

		$this->assertNotContains('public', $groups);
		$this->assertContains('authenticated', $groups);

		// The fields that make this decision non-negotiable. If either is ever
		// removed from the schema, revisit the rule rather than the test.
		$this->assertArrayHasKey('verificationToken', $portal['properties']['domains']['items']['properties']);
		$this->assertArrayHasKey('oidc', $portal['properties']['authentication']['properties']);
	}//end testThePortalRecordIsNotAnonymouslyReadable()


	/**
	 * A published page is anonymously readable; a draft is not.
	 *
	 * Expressed as a MATCH inside the rule rather than left to the reader,
	 * mirroring how opencatalogi gates `publication` on `publicationDate`. RBAC
	 * then answers both questions at once — may this caller read it, and is it
	 * ready to be seen — instead of relying on every call site to remember the
	 * second.
	 *
	 * @return void
	 */
	public function testPageIsPublicOnlyWhilePublished(): void {
		$read = self::$register['components']['schemas']['page']['authorization']['read'];

		$publicRule = null;
		foreach ($read as $rule) {
			if (is_array($rule) === true && ($rule['group'] ?? null) === 'public') {
				$publicRule = $rule;
			}
		}

		$this->assertNotNull($publicRule, 'page must carry a public read rule');
		$this->assertSame(['status' => 'published'], $publicRule['match'] ?? null);
		$this->assertContains('authenticated', $read, 'an editor must still read drafts');
	}//end testPageIsPublicOnlyWhilePublished()

		// The x-openregister-mcp dialect that used to be asserted here is GONE from
	// portalMessage, and this test with it. #112 added the dialect; with it in
	// place OpenRegister refuses to import the schema at all, so portalMessage
	// was the one schema of thirteen missing after every seed and the whole
	// portal SPA had no data surface. Bisected: ac96e1cc (before #112) seeds
	// green, c287056f (the #112 merge) and everything after fails.
	//
	// testIdentityAndSessionSchemasCarryNoMcpDialect below is KEPT. It asserts
	// that portalAccount, portalSession and exampleDocument carry no dialect,
	// which is still the rule that matters — no derived tool may return an IdP
	// claims blob or a session jti — and it will start doing real work again the
	// moment a dialect is reintroduced anywhere.

	/**
	 * portaliq-mcp-adoption T03: `portalAccount` (raw IdP claims),
	 * `portalSession` (live session/credential metadata) and `exampleDocument`
	 * (template scaffold, not a domain noun) MUST NOT carry `x-openregister-mcp`
	 * — for read verbs as well as write verbs, so no derived tool can ever
	 * return an IdP claims blob or a session `jti`.
	 *
	 * @return void
	 */
	public function testIdentityAndSessionSchemasCarryNoMcpDialect(): void {
		$schemas = self::$register['components']['schemas'];

		foreach (['portalAccount', 'portalSession', 'exampleDocument'] as $slug) {
			$this->assertArrayNotHasKey(
				'x-openregister-mcp',
				$schemas[$slug],
				"{$slug} must never declare x-openregister-mcp (Risk 1, portaliq-mcp-adoption)"
			);
		}

	}//end testIdentityAndSessionSchemasCarryNoMcpDialect()

}//end class
