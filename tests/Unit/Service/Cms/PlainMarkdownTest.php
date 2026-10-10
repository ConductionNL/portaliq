<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\PlainMarkdown;
use PHPUnit\Framework\TestCase;

/**
 * The plain version renders a page body from markdown on the server, for a
 * visitor without JavaScript (site-honest-without-javascript REQ-SHJ-002).
 *
 * @covers \OCA\Portaliq\Service\Cms\PlainMarkdown
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-the-server-renders-a-plain-version-of-every-public-page-req-shj-002
 */
class PlainMarkdownTest extends TestCase {

	/**
	 * @return void
	 */
	public function testTheSubsetRenders(): void {
		$html = (new PlainMarkdown())->toHtml(
			"# Over ons\n\nWij zijn de **gemeente** en _helpen_ u.\nTweede regel met `code`.\n\n"
			."- een\n- twee [contact](/contact)\n\n1. eerste\n2. tweede\n\n"
			."```\nif (a < b) {}\n```\n\n### Mail\n\n[Mail ons](mailto:info@example.nl) of [lees](https://example.nl/a?b=1&c=2)."
		);

		$this->assertSame(
			"<h1>Over ons</h1>\n"
			."<p>Wij zijn de <strong>gemeente</strong> en <em>helpen</em> u. Tweede regel met <code>code</code>.</p>\n"
			."<ul><li>een</li><li>twee <a href=\"/contact\">contact</a></li></ul>\n"
			."<ol><li>eerste</li><li>tweede</li></ol>\n"
			."<pre><code>if (a &lt; b) {}</code></pre>\n"
			."<h3>Mail</h3>\n"
			."<p><a href=\"mailto:info@example.nl\">Mail ons</a> of <a href=\"https://example.nl/a?b=1&amp;c=2\">lees</a>.</p>",
			$html
		);
	}//end testTheSubsetRenders()

	/**
	 * @return void
	 */
	public function testScriptIsEscaped(): void {
		$html = (new PlainMarkdown())->toHtml("Hallo <script>alert(1)</script>\n\n```\n<script>x</script>\n```");

		$this->assertStringNotContainsString('<script', $html);
		$this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
		$this->assertStringContainsString('<pre><code>&lt;script&gt;x&lt;/script&gt;</code></pre>', $html);
	}//end testScriptIsEscaped()

	/**
	 * @return void
	 */
	public function testAJavascriptLinkLosesItsTarget(): void {
		$markdown = new PlainMarkdown();
		$html     = $markdown->toHtml(
			"[klik](javascript:alert(1)) [twee](JaVaScRiPt:alert(2)) [drie](data:text/html,x) [vier](//elders.example/x)"
		);

		$this->assertStringNotContainsString('href', $html);
		$this->assertStringNotContainsString('javascript:', strtolower($html));
		$this->assertStringContainsString('klik', $html);
		$this->assertFalse($markdown->isSafeTarget(href: "java\tscript:alert(1)"));
		$this->assertTrue($markdown->isSafeTarget(href: '/site/plain?route=/x'));
		$this->assertTrue($markdown->isSafeTarget(href: 'over-ons'));
	}//end testAJavascriptLinkLosesItsTarget()

	/**
	 * @return void
	 */
	public function testRawHtmlIsEscaped(): void {
		$html = (new PlainMarkdown())->toHtml('<img src=x onerror="alert(1)"> & <a href="/x">a</a> "quote"');

		$this->assertSame(
			'<p>&lt;img src=x onerror=&quot;alert(1)&quot;&gt; &amp; &lt;a href=&quot;/x&quot;&gt;a&lt;/a&gt; &quot;quote&quot;</p>',
			$html
		);
	}//end testRawHtmlIsEscaped()
}//end class
