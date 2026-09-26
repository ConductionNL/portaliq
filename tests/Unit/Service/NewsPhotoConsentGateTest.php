<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\GuardianAudienceFixtureReader;
use OCA\Portaliq\Service\NewsPhotoConsentGate;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-photos-in-a-news-item-are-gated-by-the-target-childs-photo-consent
 */
class NewsPhotoConsentGateTest extends TestCase {

	public function testAnyWithheldConsentStripsAllPhotos(): void {
		$reader = $this->createMock(GuardianAudienceFixtureReader::class);
		$reader->method('childPhotoConsentGranted')->willReturnMap([
			['child-granted', GuardianAudienceFixtureReader::PURPOSE_NEWS, true],
			['child-withheld', GuardianAudienceFixtureReader::PURPOSE_NEWS, false],
		]);

		$gate = new NewsPhotoConsentGate($reader);
		$item = [
			'title' => 'Foto\'s sportdag',
			'body' => 'text',
			'target' => ['childRefs' => ['child-granted', 'child-withheld']],
			'photoRefs' => ['photo-1.jpg', 'photo-2.jpg'],
		];

		$result = $gate->apply($item);

		$this->assertSame('Foto\'s sportdag', $result['title']);
		$this->assertSame('text', $result['body']);
		$this->assertSame([], $result['photoRefs']);
	}//end testAnyWithheldConsentStripsAllPhotos()

	public function testAllGrantedConsentServesPhotosUnchanged(): void {
		$reader = $this->createMock(GuardianAudienceFixtureReader::class);
		$reader->method('childPhotoConsentGranted')->willReturn(true);

		$gate = new NewsPhotoConsentGate($reader);
		$item = ['target' => ['childRefs' => ['child-1', 'child-2']], 'photoRefs' => ['photo-1.jpg']];

		$result = $gate->apply($item);

		$this->assertSame(['photo-1.jpg'], $result['photoRefs']);
	}//end testAllGrantedConsentServesPhotosUnchanged()

	public function testUnresolvableConsentFailsClosedToWithheld(): void {
		$reader = $this->createMock(GuardianAudienceFixtureReader::class);
		// No fixture row at all for this child — the reader itself already
		// fails closed to false; the gate must not treat that as granted.
		$reader->method('childPhotoConsentGranted')->willReturn(false);

		$gate = new NewsPhotoConsentGate($reader);
		$item = ['target' => ['childRefs' => ['child-unknown']], 'photoRefs' => ['photo-1.jpg']];

		$this->assertSame([], $gate->apply($item)['photoRefs']);
	}//end testUnresolvableConsentFailsClosedToWithheld()

	public function testNoPhotosOrNoChildTargetPassesThroughUnchanged(): void {
		$reader = $this->createMock(GuardianAudienceFixtureReader::class);
		$reader->expects($this->never())->method('childPhotoConsentGranted');

		$gate = new NewsPhotoConsentGate($reader);

		$this->assertSame([], $gate->apply(['target' => ['groupRefs' => ['g1']], 'photoRefs' => []])['photoRefs']);
		$this->assertArrayNotHasKey('photoRefs', $gate->apply(['target' => ['groupRefs' => ['g1']]]));
	}//end testNoPhotosOrNoChildTargetPassesThroughUnchanged()
}//end class
