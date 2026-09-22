<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\PortalEmbedGuard;
use PHPUnit\Framework\TestCase;

/**
 * embedded-intake-form REQ-EIF-001 and REQ-EIF-002: the origin list is the
 * boundary and it fails closed. A form with no allowed origins serves nobody,
 * `frame-ancestors` is built from that form's own list and is never a
 * wildcard, and the snippet is only offered for a form that can actually be
 * framed.
 *
 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
 */
class PortalEmbedGuardTest extends TestCase {

	public function testAFormWithNoAllowedOriginsServesNobody(): void {
		$guard = new PortalEmbedGuard();

		$this->assertFalse($guard->isEmbeddable(binding: ['allowedOrigins' => []]));
		$this->assertFalse($guard->allows(binding: ['allowedOrigins' => []], origin: 'https://www.gemeente.nl'));
		$this->assertSame("'none'", $guard->frameAncestors(binding: ['allowedOrigins' => []]));

	}//end testAFormWithNoAllowedOriginsServesNobody()

	public function testOnlyTheDeclaredOriginMayFrameTheForm(): void {
		$guard = new PortalEmbedGuard();
		$binding = ['allowedOrigins' => ['https://www.gemeente.nl']];

		$this->assertTrue($guard->allows(binding: $binding, origin: 'https://www.gemeente.nl'));
		$this->assertFalse($guard->allows(binding: $binding, origin: 'https://www.elders.nl'));

	}//end testOnlyTheDeclaredOriginMayFrameTheForm()

	public function testAHostThatMerelyEndsWithAnAllowedOneIsRefused(): void {
		$guard = new PortalEmbedGuard();
		$binding = ['allowedOrigins' => ['https://www.gemeente.nl']];

		$this->assertFalse($guard->allows(binding: $binding, origin: 'https://evil-www.gemeente.nl.example.org'));

	}//end testAHostThatMerelyEndsWithAnAllowedOneIsRefused()

	public function testASchemeIsPartOfTheOrigin(): void {
		$guard = new PortalEmbedGuard();
		$binding = ['allowedOrigins' => ['https://www.gemeente.nl']];

		$this->assertFalse($guard->allows(binding: $binding, origin: 'http://www.gemeente.nl'));

	}//end testASchemeIsPartOfTheOrigin()

	public function testAMissingOriginIsRefused(): void {
		$guard = new PortalEmbedGuard();
		$binding = ['allowedOrigins' => ['https://www.gemeente.nl']];

		$this->assertFalse($guard->allows(binding: $binding, origin: ''));
		$this->assertFalse($guard->allows(binding: $binding, origin: 'null'));

	}//end testAMissingOriginIsRefused()

	public function testFrameAncestorsIsNeverAWildcard(): void {
		$guard = new PortalEmbedGuard();

		$value = $guard->frameAncestors(binding: ['allowedOrigins' => ['https://www.gemeente.nl', 'https://www.gemeente.nl', 'nonsense']]);

		$this->assertSame('https://www.gemeente.nl', $value);
		$this->assertStringNotContainsString('*', $value);

	}//end testFrameAncestorsIsNeverAWildcard()

	public function testAPortIsPartOfTheOrigin(): void {
		$guard = new PortalEmbedGuard();
		$binding = ['allowedOrigins' => ['https://www.gemeente.nl:8443']];

		$this->assertTrue($guard->allows(binding: $binding, origin: 'https://www.gemeente.nl:8443'));
		$this->assertFalse($guard->allows(binding: $binding, origin: 'https://www.gemeente.nl'));

	}//end testAPortIsPartOfTheOrigin()

	public function testTheSnippetNamesTheOriginsAndIsWithheldWhenThereAreNone(): void {
		$guard = new PortalEmbedGuard();

		$offered = $guard->snippetFor(binding: ['allowedOrigins' => ['https://www.gemeente.nl'], 'route' => 'aanvragen/verhuizing'], frameUrl: 'https://portaal.gemeente.nl/portal/embed?route=aanvragen%2Fverhuizing');
		$withheld = $guard->snippetFor(binding: ['allowedOrigins' => []], frameUrl: 'https://portaal.gemeente.nl/portal/embed');

		$this->assertTrue($offered['embeddable']);
		$this->assertStringContainsString('<iframe', $offered['snippet']);
		$this->assertSame(['https://www.gemeente.nl'], $offered['origins']);

		$this->assertFalse($withheld['embeddable']);
		$this->assertSame('', $withheld['snippet']);

	}//end testTheSnippetNamesTheOriginsAndIsWithheldWhenThereAreNone()

}//end class
