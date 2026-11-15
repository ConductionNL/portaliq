<?php

/**
 * PlainMarkdown: the fixed subset renders, everything else is escaped, and a
 * link keeps only an http, https, mailto or relative target
 * (site-honest-without-javascript REQ-SHJ-002).
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Cms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/site-honest-without-javascript/tasks.md#2.3
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\PlainMarkdown;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlainMarkdown::class)]
class PlainMarkdownTest extends TestCase {

	/**
	 * Headings, paragraphs, lists, links, emphasis and code render as HTML.
	 *
	 * @return void
	 */
	public function testTheSubsetRenders(): void {
		$markdown = implode("\n", [
			'# Over ons',
			'',
			'Wij zijn de **gemeente** en u leest _hier_ wat wij *doen*.',
			'Zie [contact](/contact) of [de wet](https://wetten.overheid.nl/x).',
			'',
			'## Openingstijden ##',
			'- maandag',
			'* dinsdag',
			'',
			'1. Bel ons',
			'2) Kom langs',
			'',
			'Gebruik `<b>` niet en schrijf __snake_case_names__ als `a_b`.',
			'',
			'```',
			'<div>code</div>',
			'**geen nadruk**',
			'```',
		]);

		$this->assertSame(
			implode("\n", [
				'<h1>Over ons</h1>',
				'<p>Wij zijn de <strong>gemeente</strong> en u leest <em>hier</em> wat wij <em>doen</em>.'
				."\n".'Zie <a href="/contact">contact</a> of <a href="https://wetten.overheid.nl/x">de wet</a>.</p>',
				'<h2>Openingstijden</h2>',
				'<ul><li>maandag</li><li>dinsdag</li></ul>',
				'<ol><li>Bel ons</li><li>Kom langs</li></ol>',
				'<p>Gebruik <code>&lt;b&gt;</code> niet en schrijf <strong>snake_case_names</strong> als <code>a_b</code>.</p>',
				'<pre><code>&lt;div&gt;code&lt;/div&gt;'."\n".'**geen nadruk**</code></pre>',
			]),
			(new PlainMarkdown())->render($markdown)
		);
	}//end testTheSubsetRenders()

	/**
	 * A script element shows as text.
	 *
	 * @return void
	 */
	public function testScriptIsEscaped(): void {
		$html = (new PlainMarkdown())->render("Hallo <script>alert(1)</script>\n\n# <script>x</script>");

		$this->assertStringNotContainsString('<script', $html);
		$this->assertSame(
			"<p>Hallo &lt;script&gt;alert(1)&lt;/script&gt;</p>\n<h1>&lt;script&gt;x&lt;/script&gt;</h1>",
			$html
		);
	}//end testScriptIsEscaped()

	/**
	 * A link to a javascript or other unlisted scheme keeps its text and loses its target.
	 *
	 * @return void
	 */
	public function testAJavascriptLinkLosesItsTarget(): void {
		$markdown = new PlainMarkdown();

		$this->assertSame('<p>klik</p>', $markdown->render('[klik](javascript:void0)'));
		$this->assertSame('<p>klik</p>', $markdown->render('[klik](JaVaScRiPt:void0)'));
		$this->assertSame('<p>klik</p>', $markdown->render('[klik](data:text/html;base64,PHNjcmlwdD4=)'));
		$this->assertSame('<p>klik</p>', $markdown->render('[klik](vbscript:x)'));
		$this->assertSame('<p>klik</p>', $markdown->render('[klik](//evil.example/x)'), 'a protocol-relative address leaves the site');
		$this->assertSame('<p>klik</p>', $markdown->render('[klik](java%0ascript:x)'), 'a colon before the first slash reads as a scheme');
		$this->assertSame(
			'<p>[klik](javascript:alert(1))</p>',
			$markdown->render('[klik](javascript:alert(1))'),
			'a target with parentheses is no link at all'
		);
		$this->assertStringNotContainsString('href', $markdown->render("[a](javascript:x)\n- [b](javascript:y)"));
	}//end testAJavascriptLinkLosesItsTarget()

	/**
	 * Raw HTML, including attributes and entities, is escaped, also inside link text and targets.
	 *
	 * @return void
	 */
	public function testRawHtmlIsEscaped(): void {
		$markdown = new PlainMarkdown();

		$this->assertSame(
			'<p>&lt;img src=x onerror=&quot;alert(1)&quot;&gt; &amp;amp; &lt;!-- c --&gt;</p>',
			$markdown->render('<img src=x onerror="alert(1)"> &amp; <!-- c -->')
		);
		$this->assertSame(
			'<p><a href="/zoek?q=a&amp;b=&quot;c&quot;">&lt;b&gt;x&lt;/b&gt;</a></p>',
			$markdown->render('[<b>x</b>](/zoek?q=a&b="c")')
		);
	}//end testRawHtmlIsEscaped()

	/**
	 * Which targets a link may keep.
	 *
	 * @return void
	 */
	public function testAllowedTargets(): void {
		$markdown = new PlainMarkdown();

		foreach (['https://a.example', 'HTTP://a.example', 'mailto:info@a.example', '/contact', 'contact', '?q=1', '#top', 'map/a:b'] as $target) {
			$this->assertTrue($markdown->allowedTarget($target), $target);
		}

		foreach (['', 'javascript:x', 'ftp://a.example', 'tel:0612345678', '//a.example', 'a b', "/a\tb", 'x:y/z', '\\\\a.example'] as $target) {
			$this->assertFalse($markdown->allowedTarget($target), $target);
		}
	}//end testAllowedTargets()

	/**
	 * Input NUL bytes cannot forge an inline piece.
	 *
	 * @return void
	 */
	public function testANulByteCannotForgeAPiece(): void {
		$html = (new PlainMarkdown())->render("a \x000\x00 [b](/b)");

		$this->assertSame('<p>a 0 <a href="/b">b</a></p>', $html);
	}//end testANulByteCannotForgeAPiece()

	/**
	 * Empty input renders nothing; an unclosed fence runs to the end; Windows line ends read as lines.
	 *
	 * @return void
	 */
	public function testEdges(): void {
		$markdown = new PlainMarkdown();

		$this->assertSame('', $markdown->render(''));
		$this->assertSame('', $markdown->render("\n\n  \n"));
		$this->assertSame("<pre><code>a\n&lt;b&gt;</code></pre>", $markdown->render("~~~\na\n<b>"));
		$this->assertSame("<p>a</p>\n<p>b</p>", $markdown->render("a\r\n\r\nb"));
		$this->assertSame("<p>a</p>\n<ul><li>b</li></ul>", $markdown->render("a\n- b"), 'a list item ends a paragraph');
		$this->assertSame('<p>2 * 3 * 4 = 24 en a*b</p>', $markdown->render('2 * 3 * 4 = 24 en a*b'), 'a lone asterisk with a space is no emphasis');
	}//end testEdges()
}//end class
