<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Contribution
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://portaliq.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\CaseMessagesProviderMethod;
use OCA\Portaliq\Contribution\TimelineProviderMethod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * A collection's `messages` is kept only as `{label, provider}` with a
 * provider name portaliq may call; anything else drops the key.
 *
 * @spec openspec/changes/capability-rows-said-and-planned/tasks.md#t07
 */
#[CoversClass(CaseMessagesProviderMethod::class)]
#[UsesClass(TimelineProviderMethod::class)]
class CaseMessagesProviderMethodTest extends TestCase {

	public function testAWellFormedDeclarationIsKeptWithoutExtraKeys(): void {
		$out = (new CaseMessagesProviderMethod())->normalise(['id' => 'mijnZaken', 'messages' => ['label' => 'Berichten', 'provider' => 'caseMessages', 'extra' => 'x']]);

		$this->assertSame(['id' => 'mijnZaken', 'messages' => ['label' => 'Berichten', 'provider' => 'caseMessages']], $out);
	}//end testAWellFormedDeclarationIsKeptWithoutExtraKeys()

	public function testALabelThatIsNotTextBecomesEmpty(): void {
		$out = (new CaseMessagesProviderMethod())->normalise(['messages' => ['label' => ['nl' => 'Berichten'], 'provider' => 'caseMessages']]);

		$this->assertSame(['label' => '', 'provider' => 'caseMessages'], $out['messages']);
	}//end testALabelThatIsNotTextBecomesEmpty()

	public function testAReservedOrMalformedProviderDropsTheKey(): void {
		$method = new CaseMessagesProviderMethod();
		foreach (['getContribution', 'Case-Messages', '__call', '', 12] as $provider) {
			$this->assertSame(['id' => 'c'], $method->normalise(['id' => 'c', 'messages' => ['provider' => $provider]]), var_export($provider, true));
		}

		$this->assertSame(['id' => 'c'], $method->normalise(['id' => 'c', 'messages' => 'caseMessages']), 'not a map');
	}//end testAReservedOrMalformedProviderDropsTheKey()

	public function testACollectionWithoutMessagesIsUnchanged(): void {
		$this->assertSame(['id' => 'c', 'timeline' => ['provider' => 'x']], (new CaseMessagesProviderMethod())->normalise(['id' => 'c', 'timeline' => ['provider' => 'x']]));
	}//end testACollectionWithoutMessagesIsUnchanged()
}//end class
