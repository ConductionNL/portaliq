<?php

/**
 * Edge paths of the public assistant: the channel fails closed when hermiq's
 * entry point cannot be built or answers with the wrong shape, the source
 * scope reads a portal's declared settings, and the personal-details filter
 * recognises a real BSN by the eleven test.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Service\Assistant
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Assistant;

use OCA\Portaliq\Service\Assistant\PersonalDetailsFilter;
use OCA\Portaliq\Service\Assistant\PublicAssistantChannel;
use OCA\Portaliq\Service\Assistant\PublicSourceScope;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * An entry point whose converse() returns something other than an array of
 * sources.
 */
class StringReplyEntry {

	/**
	 * Answer with a bare string, not the array shape the channel expects.
	 *
	 * @param string $question The question.
	 * @param string $portal The portal slug.
	 * @param string $locale The locale.
	 * @param array<string, mixed> $scope The source scope.
	 * @param string $conversationId The conversation id.
	 * @param array<int, mixed> $tools The tools allowed.
	 *
	 * @return string
	 */
	public function converse(string $question, string $portal, string $locale, array $scope, string $conversationId, array $tools): string {
		return 'not an array';
	}
}

/**
 * @covers \OCA\Portaliq\Service\Assistant\PublicAssistantChannel
 * @covers \OCA\Portaliq\Service\Assistant\PublicSourceScope
 * @covers \OCA\Portaliq\Service\Assistant\PersonalDetailsFilter
 */
class PublicAssistantEdgesTest extends TestCase {

	/**
	 * A channel whose container answers with the given entry, under the given
	 * entry class name.
	 *
	 * @param ContainerInterface $container The container.
	 * @param string $class The entry class name.
	 *
	 * @return PublicAssistantChannel
	 */
	private function channel(ContainerInterface $container, string $class): PublicAssistantChannel {
		return new PublicAssistantChannel(
			$container,
			$this->createMock(LoggerInterface::class),
			new PublicSourceScope(),
			new PersonalDetailsFilter(),
			$class
		);
	}//end channel()

	/**
	 * A container that returns the given instance for the entry class.
	 *
	 * @param object $entry The instance to return.
	 *
	 * @return ContainerInterface
	 */
	private function returning(object $entry): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($entry);

		return $container;
	}//end returning()

	public function testTheChannelIsUnavailableWhenTheEntryPointCannotBeBuilt(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no service'));
		// The class exists so the build is attempted, then throws.
		$channel = $this->channel($container, StringReplyEntry::class);

		$this->assertFalse($channel->isAvailable());
		$this->assertSame(
			['status' => 'unavailable', 'answer' => null, 'sources' => [], 'removed' => false, 'conversationId' => ''],
			$channel->ask(portal: ['slug' => 'z'], question: 'Wanneer?', locale: 'nl')
		);
	}//end testTheChannelIsUnavailableWhenTheEntryPointCannotBeBuilt()

	public function testAnEntryWithoutConverseIsNotAnEntry(): void {
		// A real class that exists but has no converse(): never an entry.
		$channel = $this->channel($this->returning(new \stdClass()), \stdClass::class);

		$this->assertFalse($channel->isAvailable());
	}//end testAnEntryWithoutConverseIsNotAnEntry()

	public function testANonArrayReplyYieldsNoSourcesAndAbstains(): void {
		$channel = $this->channel($this->returning(new StringReplyEntry()), StringReplyEntry::class);

		$reply = $channel->ask(portal: ['slug' => 'z'], question: 'Wanneer?', locale: 'nl');

		// No usable sources: the channel abstains and shows no generated text.
		$this->assertSame('abstained', $reply['status']);
		$this->assertNull($reply['answer']);
		$this->assertSame([], $reply['sources']);
	}//end testANonArrayReplyYieldsNoSourcesAndAbstains()

	public function testTheScopeReadsAPortalsDeclaredAssistantSettings(): void {
		$scope = new PublicSourceScope();

		$declared = ['sources' => ['page', 'faq'], 'enabled' => true];
		$this->assertSame($declared, $scope->settingsFor(portal: ['assistant' => $declared]));
		// A portal without a settings array, or no portal, reads the empty one.
		$this->assertSame([], $scope->settingsFor(portal: ['assistant' => 'nope']));
		$this->assertSame([], $scope->settingsFor(portal: null));
	}//end testTheScopeReadsAPortalsDeclaredAssistantSettings()

	public function testTheElevenTestAcceptsARealBsnAndRejectsOtherDigitRuns(): void {
		$filter = new PersonalDetailsFilter();

		// 111222333 satisfies the eleven test (the fixture the channel tests use).
		$this->assertTrue($filter->passesElevenTest(digits: '111222333'));
		// Eight or ten digits is not a BSN shape at all.
		$this->assertFalse($filter->passesElevenTest(digits: '12345678'));
		$this->assertFalse($filter->passesElevenTest(digits: '1234567890'));
		// Nine digits that do not satisfy the weighted sum.
		$this->assertFalse($filter->passesElevenTest(digits: '111222334'));
		// All zeroes pass the modulo but are excluded by the non-zero rule.
		$this->assertFalse($filter->passesElevenTest(digits: '000000000'));
	}//end testTheElevenTestAcceptsARealBsnAndRejectsOtherDigitRuns()
}//end class
