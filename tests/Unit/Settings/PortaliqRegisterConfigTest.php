<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Guards the contract-v2 register config delta (the WHOLE config change of
 * this slice): portalAccount gains the server-managed `claims` object property
 * with a nil-UUID example, both versions bump to 0.2.0 for the repair-path
 * re-import, `required` stays untouched (union-merge caution, migration.md),
 * and the dev seed accounts carry placeholders only.
 *
 * @spec openspec/changes/contract-v2/tasks.md#T4
 * @spec openspec/changes/contract-v2/tasks.md#T9
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
		// like the raw events. Additive.
		// Every new schema is listed in
		// `components.registers.portaliq.schemas` (ImportHandler binds only
		// what is listed there) and declares a non-empty `read` rule.
		$this->assertSame('0.21.0', self::$register['info']['version']);
		$this->assertSame('0.5.0', self::$register['components']['schemas']['portalTrafficDaily']['version']);
		$this->assertSame('0.4.0', self::$register['components']['schemas']['portalTrafficEvent']['version']);
		$this->assertSame('0.1.0', self::$register['components']['schemas']['portalTrafficRecording']['version']);
		$this->assertSame(['admin'], self::$register['components']['schemas']['portalTrafficRecording']['authorization']['read']);
		$this->assertContains('portalTrafficRecording', self::$register['components']['registers']['portaliq']['schemas']);
		$this->assertSame('0.3.0', self::$register['components']['schemas']['page']['version']);
		$this->assertSame('0.6.0', self::$register['components']['schemas']['portal']['version']);
		$this->assertSame('0.5.0', self::$register['components']['schemas']['portalAccount']['version']);
		$this->assertSame('0.2.0', self::$register['components']['schemas']['portalPage']['version']);
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
		$this->assertSame(['email'], $notification['properties']['channel']['enum']);
		$this->assertSame(['sent', 'failed'], $notification['properties']['status']['enum']);

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
			['state', 'nonce', 'codeVerifier', 'org', 'provider', 'expiresAt'],
			$state['required']
		);

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
