<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\ActionOptionsNormaliser;
use OCA\Portaliq\Contribution\ManifestValueNormaliser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Options providers per whitelisted field: unknown kinds, malformed static
 * options and incomplete collection providers are dropped.
 */
#[CoversClass(ActionOptionsNormaliser::class)]
#[UsesClass(ManifestValueNormaliser::class)]
class ActionOptionsNormaliserProvidersTest extends TestCase {

	private ActionOptionsNormaliser $normaliser;

	protected function setUp(): void {
		$this->normaliser = new ActionOptionsNormaliser(new ManifestValueNormaliser());
	}//end setUp()

	public function testAnActionWithoutProvidersIsReturnedUntouched(): void {
		$action = ['id' => 'apply'];
		$this->assertSame($action, $this->normaliser->normaliseOptionsProviders($action, ['type']));
	}//end testAnActionWithoutProvidersIsReturnedUntouched()

	public function testANonArrayProvidersEntryIsRemoved(): void {
		$out = $this->normaliser->normaliseOptionsProviders(['id' => 'apply', 'optionsProviders' => 'nope'], ['type']);
		$this->assertSame(['id' => 'apply'], $out);
	}//end testANonArrayProvidersEntryIsRemoved()

	public function testAnUnknownProviderKindIsDropped(): void {
		$out = $this->normaliser->normaliseOptionsProviders(
			['optionsProviders' => ['type' => ['type' => 'remote', 'url' => 'https://x']]],
			['type']
		);
		$this->assertSame([], $out['optionsProviders']);
	}//end testAnUnknownProviderKindIsDropped()

	public function testAStaticProviderWithoutAnOptionsListIsDropped(): void {
		$out = $this->normaliser->normaliseOptionsProviders(
			['optionsProviders' => ['type' => ['type' => 'static', 'options' => 'a,b']]],
			['type']
		);
		$this->assertSame([], $out['optionsProviders']);
	}//end testAStaticProviderWithoutAnOptionsListIsDropped()

	public function testAStaticProviderKeepsOnlyWellFormedOptionsAndCastsIntValues(): void {
		$out = $this->normaliser->normaliseOptionsProviders(
			[
				'optionsProviders' => [
					'type'   => [
						'type'    => 'static',
						'options' => [
							'loose',
							['value' => 3, 'label' => 'Drie'],
							['value' => 'a', 'label' => 'A'],
							['value' => 1.5, 'label' => 'Float'],
							['value' => 'b'],
						],
					],
					'hidden' => ['type' => 'static', 'options' => [['value' => 'x', 'label' => 'X']]],
				],
			],
			['type']
		);

		$this->assertSame(
			['type' => ['type' => 'static', 'options' => [['value' => '3', 'label' => 'Drie'], ['value' => 'a', 'label' => 'A']]]],
			$out['optionsProviders']
		);
	}//end testAStaticProviderKeepsOnlyWellFormedOptionsAndCastsIntValues()

	public function testAStaticProviderWithNoUsableOptionIsDropped(): void {
		$out = $this->normaliser->normaliseOptionsProviders(
			['optionsProviders' => ['type' => ['type' => 'static', 'options' => ['x', ['value' => null, 'label' => 'N']]]]],
			['type']
		);
		$this->assertSame([], $out['optionsProviders']);
	}//end testAStaticProviderWithNoUsableOptionIsDropped()

	public function testACollectionProviderNeedsAllFourKeysAndKeepsAnOptionalDescription(): void {
		$complete = ['type' => 'collection', 'register' => 'r', 'schema' => 's', 'labelField' => 'name', 'valueField' => 'id'];
		$out = $this->normaliser->normaliseOptionsProviders(
			[
				'optionsProviders' => [
					'a' => $complete + ['descriptionField' => 'summary'],
					'b' => $complete + ['descriptionField' => ''],
					'c' => array_merge($complete, ['schema' => '']),
				],
			],
			['a', 'b', 'c']
		);

		$this->assertSame(
			[
				'a' => ['type' => 'collection', 'register' => 'r', 'schema' => 's', 'labelField' => 'name', 'valueField' => 'id', 'descriptionField' => 'summary'],
				'b' => ['type' => 'collection', 'register' => 'r', 'schema' => 's', 'labelField' => 'name', 'valueField' => 'id'],
			],
			$out['optionsProviders']
		);
	}//end testACollectionProviderNeedsAllFourKeysAndKeepsAnOptionalDescription()
}//end class
