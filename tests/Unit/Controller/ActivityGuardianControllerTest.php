<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\ActivityGuardianController;
use OCA\Portaliq\Service\ActivityFeedReader;
use OCA\Portaliq\Service\ActivitySignupService;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Guardian endpoints for activities (extracurricular-activity-offer): the
 * subject comes from the bearer only, and every service answer maps to the
 * contract's status code.
 *
 * @spec openspec/changes/extracurricular-activity-offer/contract.md
 */
class ActivityGuardianControllerTest extends TestCase {
	/**
	 * The controller with a subject (or none) and service doubles.
	 *
	 * @param string|null $subjectRef The bearer's subjectRef, null for none.
	 * @param ActivitySignupService|null $signups The sign-up double.
	 * @param ActivityFeedReader|null $feed The feed double.
	 *
	 * @return ActivityGuardianController
	 */
	private function controller(?string $subjectRef, ?ActivitySignupService $signups = null, ?ActivityFeedReader $feed = null): ActivityGuardianController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn($subjectRef === null ? null : ['subjectRef' => $subjectRef]);

		return new ActivityGuardianController(
			$request,
			$session,
			($feed ?? $this->createMock(ActivityFeedReader::class)),
			($signups ?? $this->createMock(ActivitySignupService::class))
		);
	}//end controller()

	/**
	 * Without a bearer every endpoint is 401 and no service is asked.
	 *
	 * @return void
	 */
	public function testNoBearerIs401BeforeAnyServiceCall(): void {
		$signups = $this->createMock(ActivitySignupService::class);
		$signups->expects($this->never())->method('signUp');
		$signups->expects($this->never())->method('withdraw');
		$feed = $this->createMock(ActivityFeedReader::class);
		$feed->expects($this->never())->method('feedFor');

		$controller = $this->controller(null, $signups, $feed);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->feed()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->signup('a', 'child')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->withdraw('a', 'child')->getStatus());
	}//end testNoBearerIs401BeforeAnyServiceCall()

	/**
	 * Sign-up answers map to 200, 404, 409, 422 and 502; the subject passed on
	 * is the bearer's.
	 *
	 * @return void
	 */
	public function testSignupAnswersMapToTheContract(): void {
		$signups = $this->createMock(ActivitySignupService::class);
		$signups->method('signUp')->willReturnCallback(
			function (string $subjectRef, string $activityId, string $childRef, string $note) {
				$this->assertSame('guardian-anna-devries', $subjectRef);
				return match ($childRef) {
					'placed' => ['status' => 'confirmed'],
					'waiting' => ['status' => 'waitlisted', 'position' => 3],
					'foreign' => ['error' => ActivitySignupService::REASON_NOT_FOUND],
					'twice' => ['error' => ActivitySignupService::REASON_DUPLICATE],
					'late' => ['error' => ActivitySignupService::REASON_CLOSED],
					'full' => ['error' => ActivitySignupService::REASON_FULL],
					default => ['error' => ActivitySignupService::REASON_UNAVAILABLE],
				};
			}
		);
		$controller = $this->controller('guardian-anna-devries', $signups);

		$this->assertSame(['status' => 'confirmed'], $controller->signup('a', 'placed')->getData());
		$this->assertSame(['status' => 'waitlisted', 'position' => 3], $controller->signup('a', 'waiting')->getData());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->signup('a', 'foreign')->getStatus());
		$this->assertSame(Http::STATUS_CONFLICT, $controller->signup('a', 'twice')->getStatus());
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $controller->signup('a', 'late')->getStatus());
		$this->assertSame(['error' => 'activity_full'], $controller->signup('a', 'full')->getData());
		$this->assertSame(Http::STATUS_BAD_GATEWAY, $controller->signup('a', 'broken')->getStatus());
	}//end testSignupAnswersMapToTheContract()

	/**
	 * Withdraw is 204 on success and 404 otherwise; the feed is the reader's.
	 *
	 * @return void
	 */
	public function testWithdrawAndFeed(): void {
		$signups = $this->createMock(ActivitySignupService::class);
		$signups->method('withdraw')->willReturnCallback(fn (string $s, string $a, string $child) => $child === 'mine' ? null : ActivitySignupService::REASON_NOT_FOUND);
		$feed = $this->createMock(ActivityFeedReader::class);
		$feed->method('feedFor')->with('guardian-anna-devries')->willReturn([['id' => 'schaak', 'placesLeft' => 2]]);

		$controller = $this->controller('guardian-anna-devries', $signups, $feed);

		$this->assertSame(Http::STATUS_NO_CONTENT, $controller->withdraw('a', 'mine')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->withdraw('a', 'someone-else')->getStatus());
		$this->assertSame([['id' => 'schaak', 'placesLeft' => 2]], $controller->feed()->getData());
	}//end testWithdrawAndFeed()
}//end class
