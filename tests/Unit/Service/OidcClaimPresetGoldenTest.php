<?php

/**
 * Golden outcomes of merging a provider preset with an organisation's
 * override: the exact merged config, key order included, for every preset
 * and every kind of override, and the overrides that fail closed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T05
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\OidcClaimMapperService;
use PHPUnit\Framework\TestCase;

/**
 * The merged configs, recorded.
 */
class OidcClaimPresetGoldenTest extends TestCase {
	/**
	 * A bare preset merge for every provider: only the credentials given.
	 *
	 * @return void
	 */
	public function testEveryPresetMergesAsRecorded(): void {
		$credentials = ['issuer' => 'https://broker', 'clientId' => 'c', 'clientSecret' => 's'];
		$actual      = [];
		foreach (['digid', 'eherkenning', 'eidas', 'generic'] as $provider) {
			$actual[$provider] = (new OidcClaimMapperService())->applyPreset($provider, $credentials);
		}

		$expected = [];
		foreach ($actual as $provider => $config) {
			$expected[$provider] = [
				'provider'         => $provider,
				'label'            => $config['label'],
				'issuer'           => 'https://broker',
				'clientId'         => 'c',
				'clientSecret'     => 's',
				'scopes'           => $config['scopes'],
				'identityType'     => $config['identityType'],
				'identityRefClaim' => 'sub',
				'subjectRefMap'    => OidcClaimMapperService::SUBJECT_REF_DERIVE,
				'audienceMap'      => $config['audienceMap'],
				'loaClaim'         => 'acr',
				'loaMap'           => [],
				'branchClaim'      => '',
				'kvkClaim'         => '',
			];
		}

		$this->assertSame($expected, $actual);
		$this->assertSame(
			[
				'digid'       => ['DigiD', ['openid'], 'digid', 'client'],
				'eherkenning' => [$actual['eherkenning']['label'], $actual['eherkenning']['scopes'], 'eherkenning', $actual['eherkenning']['audienceMap']],
				'eidas'       => [$actual['eidas']['label'], $actual['eidas']['scopes'], 'eidas', 'client'],
				'generic'     => ['Inloggen', ['openid'], 'generic', 'supplier'],
			],
			array_map(static fn (array $c): array => [$c['label'], $c['scopes'], $c['identityType'], $c['audienceMap']], $actual)
		);
	}//end testEveryPresetMergesAsRecorded()

	/**
	 * Every override reaches its key, in the same place.
	 *
	 * @return void
	 */
	public function testEveryOverrideReachesItsKey(): void {
		$merged = (new OidcClaimMapperService())->applyPreset(
			'generic',
			[
				'label'        => 'Eigen',
				'issuer'       => 'i',
				'clientId'     => 'c',
				'clientSecret' => 's',
				'scopes'       => ['b' => 'openid', 'a' => 'profile'],
				'identityType' => 'eherkenning',
				'claimMap'     => ['identityRef' => 'kvk', 'subjectRef' => 'claim:pid', 'audience' => 'literal:business', 'branch' => 'vestiging', 'kvk' => 'urn:etoegang:kvk'],
				'loaClaim'     => 'loa',
				'loaMap'       => ['x' => 'high'],
			]
		);

		$this->assertSame(
			[
				'provider'         => 'generic',
				'label'            => 'Eigen',
				'issuer'           => 'i',
				'clientId'         => 'c',
				'clientSecret'     => 's',
				'scopes'           => ['openid', 'profile'],
				'identityType'     => 'eherkenning',
				'identityRefClaim' => 'kvk',
				'subjectRefMap'    => 'claim:pid',
				'audienceMap'      => 'literal:business',
				'loaClaim'         => 'loa',
				'loaMap'           => ['x' => 'high'],
				'branchClaim'      => 'vestiging',
				'kvkClaim'         => 'urn:etoegang:kvk',
			],
			$merged
		);
	}//end testEveryOverrideReachesItsKey()

	/**
	 * Junk overrides fall back to the preset; an empty identityType keeps the preset's.
	 *
	 * @return void
	 */
	public function testJunkOverridesFallBack(): void {
		$mapper = new OidcClaimMapperService();
		$plain  = $mapper->applyPreset('digid', []);
		$junk   = $mapper->applyPreset('digid', ['scopes' => [], 'claimMap' => 'junk', 'loaMap' => 'junk', 'identityType' => '']);
		$this->assertSame($plain, $junk);
		$this->assertSame(['openid'], $mapper->applyPreset('digid', ['scopes' => 'openid profile'])['scopes']);
		$this->assertSame('', $plain['issuer']);
	}//end testJunkOverridesFallBack()

	/**
	 * An unknown provider and an out-of-enum or non-string identityType fail closed.
	 *
	 * @return void
	 */
	public function testTheOverridesThatFailClosed(): void {
		$mapper = new OidcClaimMapperService();
		$this->assertSame(
			[null, null, null, null],
			[
				$mapper->applyPreset('unknown', ['issuer' => 'i']),
				$mapper->applyPreset('', []),
				$mapper->applyPreset('digid', ['identityType' => 'root']),
				$mapper->applyPreset('digid', ['identityType' => ['digid']]),
			]
		);
	}//end testTheOverridesThatFailClosed()
}//end class
