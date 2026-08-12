<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\OidcClaimMapperService;
use PHPUnit\Framework\TestCase;

/**
 * portal-oidc-broker-login T05/T11: provider presets merge with a per-org
 * override; claims map to identityType/identityRef/subjectRef/audience with
 * subjectRef ALWAYS either the `derive` keyword or a validated-claim value —
 * NEVER a client-suppliable literal; and the LoA→trust mapping under-
 * privileges on ambiguity (an unmapped/missing LoA — or a malformed `loaMap`
 * VALUE — normalises to `low`, never higher).
 *
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T05
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T11
 * @spec openspec/specs/supplier-portal/spec.md#broker-loa-maps-to-portal-trust-under-privileging-on-ambiguity
 * @spec openspec/specs/supplier-portal/spec.md#the-subject-reference-is-server-derived-never-client-supplied
 */
class OidcClaimMapperServiceTest extends TestCase {

	private OidcClaimMapperService $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new OidcClaimMapperService();

	}//end setUp()

	// -- applyPreset() --------------------------------------------------------

	public function testApplyPresetRejectsAnUnknownProvider(): void {
		$this->assertNull($this->mapper->applyPreset('not-a-real-provider', []));

	}//end testApplyPresetRejectsAnUnknownProvider()

	public function testApplyPresetFillsProviderDefaultsWhenTheOrgOmitsThem(): void {
		$merged = $this->mapper->applyPreset('digid', ['issuer' => 'https://broker.example', 'clientId' => 'c1', 'clientSecret' => 's1']);

		$this->assertNotNull($merged);
		$this->assertSame('digid', $merged['identityType']);
		$this->assertSame('DigiD', $merged['label']);
		$this->assertSame(['openid'], $merged['scopes']);
		$this->assertSame('client', $merged['audienceMap']);
		$this->assertSame('sub', $merged['identityRefClaim']);
		$this->assertSame('derive', $merged['subjectRefMap']);
		$this->assertSame('acr', $merged['loaClaim']);

	}//end testApplyPresetFillsProviderDefaultsWhenTheOrgOmitsThem()

	public function testApplyPresetHonoursAnOrgScopesOverride(): void {
		$merged = $this->mapper->applyPreset('eherkenning', ['issuer' => 'i', 'clientId' => 'c', 'clientSecret' => 's', 'scopes' => ['openid', 'kvk']]);

		$this->assertSame(['openid', 'kvk'], $merged['scopes']);

	}//end testApplyPresetHonoursAnOrgScopesOverride()

	public function testApplyPresetGenericAllowsAnIdentityTypeOverride(): void {
		$merged = $this->mapper->applyPreset('generic', ['issuer' => 'i', 'clientId' => 'c', 'clientSecret' => 's', 'identityType' => 'eidas']);

		$this->assertSame('eidas', $merged['identityType']);

	}//end testApplyPresetGenericAllowsAnIdentityTypeOverride()

	public function testApplyPresetRejectsAnInvalidIdentityTypeOverride(): void {
		// `portalAccount.identityType` is a closed register enum — an
		// override outside it must fail closed, never silently pass through.
		$this->assertNull($this->mapper->applyPreset('generic', ['issuer' => 'i', 'clientId' => 'c', 'clientSecret' => 's', 'identityType' => 'not-a-valid-enum-value']));

	}//end testApplyPresetRejectsAnInvalidIdentityTypeOverride()

	// -- mapClaims() ------------------------------------------------------------

	public function testMapClaimsDerivesSubjectRefByDefault(): void {
		$config = $this->mapper->applyPreset('eherkenning', ['issuer' => 'i', 'clientId' => 'c', 'clientSecret' => 's']);
		$mapped = $this->mapper->mapClaims(['sub' => 'kvk-12345678'], $config);

		$this->assertSame('eherkenning', $mapped['identityType']);
		$this->assertSame('kvk-12345678', $mapped['identityRef']);
		// 'derive' means the CALLER (PortalAccountService) mints/reuses —
		// never a claim value, and NEVER a request parameter.
		$this->assertNull($mapped['subjectRef']);
		$this->assertSame('supplier', $mapped['audience']);

	}//end testMapClaimsDerivesSubjectRefByDefault()

	public function testMapClaimsReadsAConfiguredSubjectRefClaim(): void {
		$config = $this->mapper->applyPreset('eherkenning', ['issuer' => 'i', 'clientId' => 'c', 'clientSecret' => 's', 'claimMap' => ['subjectRef' => 'claim:pairwise_id']]);
		$mapped = $this->mapper->mapClaims(['sub' => 'kvk-1', 'pairwise_id' => 'stable-ref-1'], $config);

		$this->assertSame('stable-ref-1', $mapped['subjectRef']);

	}//end testMapClaimsReadsAConfiguredSubjectRefClaim()

	public function testMapClaimsFailsClosedWhenAConfiguredSubjectRefClaimIsAbsent(): void {
		$config = $this->mapper->applyPreset('eherkenning', ['issuer' => 'i', 'clientId' => 'c', 'clientSecret' => 's', 'claimMap' => ['subjectRef' => 'claim:missing_claim']]);

		$this->assertNull($this->mapper->mapClaims(['sub' => 'kvk-1'], $config));

	}//end testMapClaimsFailsClosedWhenAConfiguredSubjectRefClaimIsAbsent()

	public function testMapClaimsTreatsAnyOtherLiteralSubjectRefMappingAsDeriveNeverAStaticValue(): void {
		// A bare literal (not `derive`, not `claim:...`) can never be a safe
		// per-user subjectRef (it would collide across every user) — it must
		// be treated as `derive`, never as a dangerous shared literal.
		$config = $this->mapper->applyPreset('eherkenning', ['issuer' => 'i', 'clientId' => 'c', 'clientSecret' => 's', 'claimMap' => ['subjectRef' => 'some-literal-typo']]);
		$mapped = $this->mapper->mapClaims(['sub' => 'kvk-1'], $config);

		$this->assertNull($mapped['subjectRef']);

	}//end testMapClaimsTreatsAnyOtherLiteralSubjectRefMappingAsDeriveNeverAStaticValue()

	public function testMapClaimsFailsClosedWhenIdentityRefClaimIsAbsent(): void {
		$config = $this->mapper->applyPreset('eherkenning', ['issuer' => 'i', 'clientId' => 'c', 'clientSecret' => 's']);

		$this->assertNull($this->mapper->mapClaims([], $config));

	}//end testMapClaimsFailsClosedWhenIdentityRefClaimIsAbsent()

	public function testMapClaimsSupportsAFixedAudienceLiteral(): void {
		$config = $this->mapper->applyPreset('generic', ['issuer' => 'i', 'clientId' => 'c', 'clientSecret' => 's', 'claimMap' => ['audience' => 'citizen']]);
		$mapped = $this->mapper->mapClaims(['sub' => 'x'], $config);

		$this->assertSame('citizen', $mapped['audience']);

	}//end testMapClaimsSupportsAFixedAudienceLiteral()

	public function testMapClaimsSupportsAClaimReferencedAudience(): void {
		$config = $this->mapper->applyPreset('generic', ['issuer' => 'i', 'clientId' => 'c', 'clientSecret' => 's', 'claimMap' => ['audience' => 'claim:role']]);
		$mapped = $this->mapper->mapClaims(['sub' => 'x', 'role' => 'client'], $config);

		$this->assertSame('client', $mapped['audience']);

	}//end testMapClaimsSupportsAClaimReferencedAudience()

	public function testMapClaimsFailsClosedWhenAClaimReferencedAudienceIsAbsent(): void {
		$config = $this->mapper->applyPreset('generic', ['issuer' => 'i', 'clientId' => 'c', 'clientSecret' => 's', 'claimMap' => ['audience' => 'claim:role']]);

		$this->assertNull($this->mapper->mapClaims(['sub' => 'x'], $config));

	}//end testMapClaimsFailsClosedWhenAClaimReferencedAudienceIsAbsent()

	// -- mapLoaToTrust() --------------------------------------------------------

	public function testARecognisedLoaMapsToItsConfiguredTrust(): void {
		$config = $this->mapper->applyPreset('digid', ['issuer' => 'i', 'clientId' => 'c', 'clientSecret' => 's', 'loaMap' => ['urn:broker:loa:high' => 'high', 'urn:broker:loa:mid' => 'substantial']]);

		$this->assertSame('high', $this->mapper->mapLoaToTrust(['acr' => 'urn:broker:loa:high'], $config));
		$this->assertSame('substantial', $this->mapper->mapLoaToTrust(['acr' => 'urn:broker:loa:mid'], $config));

	}//end testARecognisedLoaMapsToItsConfiguredTrust()

	public function testAnUnmappedLoaMapsToLowNeverHigher(): void {
		$config = $this->mapper->applyPreset('digid', ['issuer' => 'i', 'clientId' => 'c', 'clientSecret' => 's', 'loaMap' => ['urn:broker:loa:high' => 'high']]);

		$this->assertSame('low', $this->mapper->mapLoaToTrust(['acr' => 'urn:broker:loa:unknown-value'], $config));

	}//end testAnUnmappedLoaMapsToLowNeverHigher()

	public function testAMissingLoaClaimMapsToLow(): void {
		$config = $this->mapper->applyPreset('digid', ['issuer' => 'i', 'clientId' => 'c', 'clientSecret' => 's', 'loaMap' => ['urn:broker:loa:high' => 'high']]);

		$this->assertSame('low', $this->mapper->mapLoaToTrust([], $config));

	}//end testAMissingLoaClaimMapsToLow()

	public function testAMalformedLoaMapValueNormalisesToLowNotAFatalError(): void {
		// A typo'd loaMap VALUE (not one of low|substantial|high) must still
		// fail closed to `low` via the shared trust normaliser — never throw,
		// never widen.
		$config = $this->mapper->applyPreset('digid', ['issuer' => 'i', 'clientId' => 'c', 'clientSecret' => 's', 'loaMap' => ['urn:broker:loa:high' => 'SUPER-ADMIN-TRUST-TYPO']]);

		$this->assertSame('low', $this->mapper->mapLoaToTrust(['acr' => 'urn:broker:loa:high'], $config));

	}//end testAMalformedLoaMapValueNormalisesToLowNotAFatalError()

	public function testACustomLoaClaimNameIsHonoured(): void {
		$config = $this->mapper->applyPreset('generic', ['issuer' => 'i', 'clientId' => 'c', 'clientSecret' => 's', 'loaClaim' => 'assurance_level', 'loaMap' => ['high-assurance' => 'high']]);

		$this->assertSame('high', $this->mapper->mapLoaToTrust(['assurance_level' => 'high-assurance'], $config));

	}//end testACustomLoaClaimNameIsHonoured()

	public function testProviderLabelsListsAllFourPresets(): void {
		$labels = $this->mapper->providerLabels();

		$this->assertSame(
			['digid' => 'DigiD', 'eherkenning' => 'eHerkenning', 'eidas' => 'eIDAS', 'generic' => 'Inloggen'],
			$labels
		);

	}//end testProviderLabelsListsAllFourPresets()

}//end class
