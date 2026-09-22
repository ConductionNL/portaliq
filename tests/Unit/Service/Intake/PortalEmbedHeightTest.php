<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\PortalEmbedGuard;
use OCA\Portaliq\Service\Intake\PortalEmbedHeight;
use PHPUnit\Framework\TestCase;

/**
 * embedded-intake-form T05: the height negotiation, and what happens when the
 * host never replies.
 *
 * 🔴 AN EMBED WITH NO FLOOR COLLAPSES AND EXPLAINS NOTHING. An iframe sized
 * purely from a `postMessage` that never arrives renders at whatever the host's
 * CSS left it, and on plenty of sites that is zero: a blank strip where a form
 * should be. Nothing errors, nothing logs, the host page looks fine, and the
 * municipality finds out when a citizen telephones to say the form is missing.
 *
 * So the minimum is DECLARED in the snippet and holds in every no-message case:
 * before the first message, if the message never comes, and if the site's web
 * team pasted the iframe without the listener at all.
 *
 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
 */
class PortalEmbedHeightTest extends TestCase {

	private PortalEmbedHeight $height;

	/**
	 * Wire the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->height = new PortalEmbedHeight();
	}//end setUp()

	/**
	 * 🔴 NO MESSAGE MEANS THE DECLARED MINIMUM, NOT ZERO. Every shape a missing
	 * report can take resolves to the floor.
	 *
	 * @return void
	 */
	public function testNoMessageMeansTheDeclaredMinimum(): void {
		foreach ([null, '', 'tall', [], false] as $nothing) {
			$this->assertSame(
				PortalEmbedHeight::MINIMUM_HEIGHT,
				$this->height->heightFor(reported: $nothing),
				'a missing or unusable report must fall back to the declared minimum'
			);
		}
	}//end testNoMessageMeansTheDeclaredMinimum()

	/**
	 * 🔴 AND A REPORT BELOW THE FLOOR DOES NOT SHRINK IT. A form that measures
	 * itself at 40 pixels mid-render would otherwise hide itself just as
	 * effectively as never reporting at all.
	 *
	 * @return void
	 */
	public function testAReportBelowTheFloorIsRaisedToIt(): void {
		$this->assertSame(PortalEmbedHeight::MINIMUM_HEIGHT, $this->height->heightFor(reported: 40));
		$this->assertSame(PortalEmbedHeight::MINIMUM_HEIGHT, $this->height->heightFor(reported: 0));
		$this->assertSame(PortalEmbedHeight::MINIMUM_HEIGHT, $this->height->heightFor(reported: -900));
	}//end testAReportBelowTheFloorIsRaisedToIt()

	/**
	 * A real report is honoured, so the floor is a floor and not a fixed size.
	 *
	 * @return void
	 */
	public function testATallerFormIsHonoured(): void {
		$this->assertSame(1320, $this->height->heightFor(reported: 1320));
		$this->assertSame(1320, $this->height->heightFor(reported: 1319.6));
	}//end testATallerFormIsHonoured()

	/**
	 * A report far beyond any page has measured something other than itself,
	 * and honouring it leaves the host scrolling through empty space.
	 *
	 * @return void
	 */
	public function testAnAbsurdReportIsCapped(): void {
		$this->assertSame(PortalEmbedHeight::MAXIMUM_HEIGHT, $this->height->heightFor(reported: 6000000));
	}//end testAnAbsurdReportIsCapped()

	/**
	 * The floor is the guard's own minimum, so the snippet and the frame cannot
	 * drift to two different numbers.
	 *
	 * @return void
	 */
	public function testTheFloorIsTheGuardsOwnMinimum(): void {
		$this->assertSame(PortalEmbedGuard::MINIMUM_HEIGHT, PortalEmbedHeight::MINIMUM_HEIGHT);
	}//end testTheFloorIsTheGuardsOwnMinimum()

	/**
	 * 🔴 A MESSAGE FROM ANYWHERE BUT THE FRAME IS REFUSED. A host page's
	 * listener hears every frame on the page, including ones the site owner did
	 * not put there.
	 *
	 * @return void
	 */
	public function testAMessageFromAnotherOriginIsRefused(): void {
		$message = ['type' => PortalEmbedHeight::MESSAGE_TYPE, 'height' => 900];

		$this->assertFalse(
			$this->height->accepts(message: $message, origin: 'https://adverteerder.nl', frameOrigin: 'https://portaal.nl')
		);
		$this->assertTrue(
			$this->height->accepts(message: $message, origin: 'https://portaal.nl', frameOrigin: 'https://portaal.nl')
		);
	}//end testAMessageFromAnotherOriginIsRefused()

	/**
	 * A message of another shape is refused, so a bare `{height: 900}` from
	 * anything else on the page cannot resize this frame.
	 *
	 * @return void
	 */
	public function testAMessageOfAnotherShapeIsRefused(): void {
		$this->assertFalse(
			$this->height->accepts(
				message: ['height' => 900],
				origin: 'https://portaal.nl',
				frameOrigin: 'https://portaal.nl'
			)
		);
	}//end testAMessageOfAnotherShapeIsRefused()

	/**
	 * An unknown frame origin refuses everything rather than accepting
	 * anything: failing open here would be a stranger resizing the frame.
	 *
	 * @return void
	 */
	public function testAnUnknownFrameOriginRefusesEverything(): void {
		$this->assertFalse(
			$this->height->accepts(
				message: ['type' => PortalEmbedHeight::MESSAGE_TYPE],
				origin: 'https://portaal.nl',
				frameOrigin: ''
			)
		);
	}//end testAnUnknownFrameOriginRefusesEverything()

	/**
	 * 🔴 THE SNIPPET CARRIES THE FLOOR, so pasting the iframe ALONE still shows
	 * the form. That is the property that makes this safe to hand to somebody
	 * else's web team, who may well paste only the first half.
	 *
	 * @return void
	 */
	public function testTheIframeAloneCarriesTheFloor(): void {
		$snippet = $this->height->snippet(frameUrl: 'https://portaal.nl/embed', title: 'Melding');

		$this->assertStringContainsString('min-height:'.PortalEmbedHeight::MINIMUM_HEIGHT.'px', $snippet['iframe']);
		$this->assertSame(PortalEmbedHeight::MINIMUM_HEIGHT, $snippet['minimumHeight']);
	}//end testTheIframeAloneCarriesTheFloor()

	/**
	 * The listener checks the frame's origin and the message type, and clamps
	 * at both ends, so the copied snippet enforces the same rules the service
	 * does rather than trusting whatever arrives.
	 *
	 * @return void
	 */
	public function testTheListenerChecksTheOriginAndClampsBothEnds(): void {
		$snippet = $this->height->snippet(frameUrl: 'https://portaal.nl:8443/embed', title: 'Melding');

		$this->assertStringContainsString('"https://portaal.nl:8443"', $snippet['listener']);
		$this->assertStringContainsString('event.origin !==', $snippet['listener']);
		$this->assertStringContainsString(PortalEmbedHeight::MESSAGE_TYPE, $snippet['listener']);
		$this->assertStringContainsString('Math.max('.PortalEmbedHeight::MINIMUM_HEIGHT, $snippet['listener']);
		$this->assertStringContainsString('Math.min('.PortalEmbedHeight::MAXIMUM_HEIGHT, $snippet['listener']);
	}//end testTheListenerChecksTheOriginAndClampsBothEnds()

	/**
	 * The title is escaped, so a form named with a quote cannot break out of
	 * the attribute in a snippet somebody pastes onto a public site.
	 *
	 * @return void
	 */
	public function testTheTitleIsEscapedInTheSnippet(): void {
		$snippet = $this->height->snippet(frameUrl: 'https://portaal.nl/embed', title: 'Melding" onload="alert(1)');

		$this->assertStringNotContainsString('onload="alert(1)"', $snippet['iframe']);
		$this->assertStringContainsString('&quot;', $snippet['iframe']);
	}//end testTheTitleIsEscapedInTheSnippet()
}//end class
