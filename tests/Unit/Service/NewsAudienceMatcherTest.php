<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\NewsAudienceMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Pins the "school OR group OR child, any match" rule, including the
 * fail-closed empty-target case.
 *
 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsitem-is-authored-per-school-group-or-child-and-tracks-read-receipts
 */
class NewsAudienceMatcherTest extends TestCase {

	public function testMatchesOnSchool(): void {
		$this->assertTrue(NewsAudienceMatcher::matches(
			['schoolRef' => 'school-a'],
			['schoolRef' => 'school-a', 'groupRefs' => [], 'childRefs' => []]
		));
	}//end testMatchesOnSchool()

	public function testMatchesOnGroupIntersection(): void {
		$this->assertTrue(NewsAudienceMatcher::matches(
			['groupRefs' => ['groep-5a']],
			['schoolRef' => '', 'groupRefs' => ['groep-3b', 'groep-5a'], 'childRefs' => []]
		));
	}//end testMatchesOnGroupIntersection()

	public function testMatchesOnChildIntersection(): void {
		$this->assertTrue(NewsAudienceMatcher::matches(
			['childRefs' => ['child-x']],
			['schoolRef' => '', 'groupRefs' => [], 'childRefs' => ['child-x']]
		));
	}//end testMatchesOnChildIntersection()

	public function testNoOverlapDoesNotMatch(): void {
		$this->assertFalse(NewsAudienceMatcher::matches(
			['groupRefs' => ['groep-5a']],
			['schoolRef' => 'other-school', 'groupRefs' => ['groep-7a'], 'childRefs' => ['child-y']]
		));
	}//end testNoOverlapDoesNotMatch()

	public function testEmptyTargetMatchesNobody(): void {
		$this->assertFalse(NewsAudienceMatcher::matches(
			[],
			['schoolRef' => 'school-a', 'groupRefs' => ['groep-5a'], 'childRefs' => ['child-x']]
		));
	}//end testEmptyTargetMatchesNobody()

	public function testEmptyAudienceMatchesNothing(): void {
		$this->assertFalse(NewsAudienceMatcher::matches(
			['schoolRef' => 'school-a', 'groupRefs' => ['groep-5a'], 'childRefs' => ['child-x']],
			[]
		));
	}//end testEmptyAudienceMatchesNothing()
}//end class
