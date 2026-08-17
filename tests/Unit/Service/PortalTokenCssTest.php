<?php

/**
 * Unit tests for PortalTokenCss.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalTokenCss;
use PHPUnit\Framework\TestCase;

/**
 * Authored values that end up inside a stylesheet served to every anonymous
 * visitor of a government portal. These tests are mostly about what does NOT
 * come out the other side.
 */
class PortalTokenCssTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var PortalTokenCss
	 */
	private PortalTokenCss $css;


	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->css = new PortalTokenCss();
	}//end setUp()


	/**
	 * A portal that overrides nothing emits NOTHING — not an empty rule.
	 *
	 * An empty `:root {}` is a request, a parse and a cache entry for a portal
	 * that made no decision.
	 *
	 * @return void
	 */
	public function testAPortalWithNoOverridesEmitsNothing(): void {
		$this->assertSame('', $this->css->render(portal: ['slug' => 'x']));
		$this->assertSame('', $this->css->render(portal: ['slug' => 'x', 'tokens' => []]));
	}//end testAPortalWithNoOverridesEmitsNothing()


	/**
	 * An override reaches the stylesheet — the positive control.
	 *
	 * Without it every refusal below is satisfied by a renderer that emits
	 * nothing at all, and "no attacker CSS" would be indistinguishable from
	 * "no CSS".
	 *
	 * @return void
	 */
	public function testAnOverrideIsRendered(): void {
		$out = $this->css->render(
			portal: ['slug' => 'conduction-klant', 'tokens' => ['nldesign-color-primary' => '#21468B']]
		);

		$this->assertStringContainsString('--nldesign-color-primary: #21468B;', $out);
		$this->assertStringContainsString(':root {', $out);
	}//end testAnOverrideIsRendered()


	/**
	 * The natural spelling — with leading dashes — is accepted.
	 *
	 * An author copying a token name out of a stylesheet types `--name`.
	 * Rejecting that would be a rule nobody can discover.
	 *
	 * @return void
	 */
	public function testTheLeadingDashesAreOptional(): void {
		$out = $this->css->render(portal: ['tokens' => ['--nldesign-color-text' => '#111111']]);
		$this->assertStringContainsString('--nldesign-color-text: #111111;', $out);
		$this->assertStringNotContainsString('----', $out);
	}//end testTheLeadingDashesAreOptional()


	/**
	 * A VALUE CANNOT ESCAPE ITS DECLARATION.
	 *
	 * This is the one that matters. A value carrying `}` closes the rule, and
	 * everything after it is attacker-authored CSS on a public government page
	 * — enough to overlay or hide a login form. Dropped, not escaped.
	 *
	 * @return void
	 */
	public function testAValueCannotCloseTheRule(): void {
		$out = $this->css->render(
			portal: [
				'tokens' => [
					'nldesign-color-primary' => '#fff; } body { display: none } .x {',
					'nldesign-color-text' => '#111111',
				],
			]
		);

		$this->assertStringNotContainsString('display: none', $out);
		$this->assertStringNotContainsString('body {', $out);
		// The well-formed sibling still made it: one bad value must not cost a
		// portal its whole theme.
		$this->assertStringContainsString('--nldesign-color-text: #111111;', $out);
	}//end testAValueCannotCloseTheRule()


	/**
	 * `url()` is refused — it fetches.
	 *
	 * The request alone tells a third party who is reading the page, which on a
	 * government portal is the visitor's business and nobody else's.
	 *
	 * @return void
	 */
	public function testAFetchingValueIsRefused(): void {
		foreach (
			[
				'url(https://tracker.example/pixel.png)',
				'URL(//evil.example/x)',
				'image-set(url(x))',
			] as $value
		) {
			$out = $this->css->render(portal: ['tokens' => ['nldesign-color-background' => $value]]);
			$this->assertSame('', $out, 'must refuse: ' . $value);
		}
	}//end testAFetchingValueIsRefused()


	/**
	 * A name outside the token vocabulary is dropped.
	 *
	 * The vocabulary is the contract the bridge and the components are written
	 * against; a portal inventing `--anything` is describing something no
	 * component reads.
	 *
	 * @return void
	 */
	public function testAnUnknownTokenFamilyIsDropped(): void {
		$out = $this->css->render(
			portal: [
				'tokens' => [
					'evil-thing' => 'red',
					'nldesign-color-primary' => '#21468B',
				],
			]
		);

		$this->assertStringNotContainsString('evil-thing', $out);
		$this->assertStringContainsString('--nldesign-color-primary', $out);
	}//end testAnUnknownTokenFamilyIsDropped()


	/**
	 * The slug in the generated comment cannot close the comment.
	 *
	 * @return void
	 */
	public function testTheCommentCannotBeClosedByTheSlug(): void {
		$out = $this->css->render(
			portal: ['slug' => 'evil*/ body{display:none} /*', 'tokens' => ['nldesign-color-primary' => 'red']]
		);

		$this->assertStringNotContainsString('display:none', $out);
	}//end testTheCommentCannotBeClosedByTheSlug()


	/**
	 * Ordinary token values survive — colours, lengths, stacks and `var()`.
	 *
	 * The allow-list has to be wide enough to be useful, or every portal works
	 * around it and the layer is dead.
	 *
	 * @return void
	 */
	public function testOrdinaryTokenValuesAreAccepted(): void {
		$accepted = $this->css->declarations(
			portal: [
				'tokens' => [
					'nldesign-color-primary' => 'rgb(33, 70, 139)',
					'nldesign-header-padding-inline' => '56px',
					'nldesign-body-font-family' => "'Figtree', system-ui, sans-serif",
					'nldesign-nav-link-color' => 'var(--nldesign-color-primary)',
					'utrecht-link-color' => '#21468B',
				],
			]
		);

		$this->assertCount(5, $accepted);
	}//end testOrdinaryTokenValuesAreAccepted()


}//end class
