<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\GuardianAudienceFixtureReader;
use OCA\Portaliq\Service\NewsletterPreflightService;
use PHPUnit\Framework\TestCase;

/**
 * Pins that the preflight count and the send-refusal check both derive from
 * the SAME `guardiansMatching()` call, so a preview can never drift from an
 * actual send.
 *
 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsletter-send-is-preceded-by-a-recipient-count-preflight
 */
class NewsletterPreflightServiceTest extends TestCase {

	public function testPreflightCountMatchesActualDeliveryAudience(): void {
		$reader = $this->createMock(GuardianAudienceFixtureReader::class);
		$target = ['groupRefs' => ['groep-5a']];
		$reader->expects($this->exactly(2))->method('guardiansMatching')->with($target)
			->willReturn(['guardian-anna-devries', 'guardian-piet-bakker', 'guardian-x']);

		$service = new NewsletterPreflightService($reader);

		$this->assertSame(3, $service->countRecipients($target));
		$this->assertSame(['guardian-anna-devries', 'guardian-piet-bakker', 'guardian-x'], $service->recipients($target));
	}//end testPreflightCountMatchesActualDeliveryAudience()

	public function testSendingToAnEmptyResolvedAudienceIsRefused(): void {
		$reader = $this->createMock(GuardianAudienceFixtureReader::class);
		$reader->method('guardiansMatching')->willReturn([]);

		$service = new NewsletterPreflightService($reader);

		$this->assertSame(0, $service->countRecipients(['groupRefs' => ['empty-group']]));
		$this->assertTrue($service->sendIsRefused(['groupRefs' => ['empty-group']]));
	}//end testSendingToAnEmptyResolvedAudienceIsRefused()

	public function testANonEmptyAudienceIsNotRefused(): void {
		$reader = $this->createMock(GuardianAudienceFixtureReader::class);
		$reader->method('guardiansMatching')->willReturn(['guardian-anna-devries']);

		$service = new NewsletterPreflightService($reader);

		$this->assertFalse($service->sendIsRefused(['groupRefs' => ['groep-5a']]));
	}//end testANonEmptyAudienceIsNotRefused()
}//end class
