<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\PortalBindingPreview;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use PHPUnit\Framework\TestCase;

/**
 * portal-intake-form-as-an-object T03: an administrator is told what the
 * binding resolves to today, and told plainly when it resolves to nothing.
 *
 * 🔴 A BINDING THAT RESOLVES TO NOTHING LOOKS EXACTLY LIKE ONE THAT IS FINE.
 * The type tuple, the audience and the form name can all be perfectly
 * well-formed while the form they point at is unpublished, published to a
 * different audience, or gone. The admin page renders the same either way, and
 * the first person to find out is a citizen meeting an empty form on a
 * Saturday.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */
class PortalBindingPreviewTest extends TestCase {

	/**
	 * A preview over a resolver stubbed to answer one resolution.
	 *
	 * @param array<string, mixed> $render What the resolver answers.
	 *
	 * @return PortalBindingPreview The preview.
	 */
	private function previewAnswering(array $render): PortalBindingPreview {
		$resolver = $this->getMockBuilder(PortalFormBindingResolver::class)
			->disableOriginalConstructor()
			->onlyMethods(['render'])
			->getMock();
		$resolver->method('render')->willReturn($render);

		return new PortalBindingPreview(resolver: $resolver);
	}//end previewAnswering()

	/**
	 * A healthy binding names the form it opens.
	 *
	 * @return void
	 */
	public function testAResolvedBindingNamesTheFormItOpensToday(): void {
		$preview = $this->previewAnswering(
			render: ['kind' => 'hosted', 'resolvesToNoForm' => false, 'formName' => 'Melding openbare ruimte']
		);

		$described = $preview->describe(binding: []);

		$this->assertSame(PortalBindingPreview::RESOLVED, $described['state']);
		$this->assertSame('Melding openbare ruimte', $described['formName']);
		$this->assertStringContainsString('Melding openbare ruimte', $described['message']);
	}//end testAResolvedBindingNamesTheFormItOpensToday()

	/**
	 * 🔴 A BINDING THAT RESOLVES TO NOTHING SAYS SO IN WORDS. An empty field
	 * where a form name should be reads as "nobody has filled this in yet",
	 * which is a different problem with a different fix.
	 *
	 * @return void
	 */
	public function testABindingThatResolvesToNoneSaysSo(): void {
		$preview = $this->previewAnswering(
			render: ['kind' => 'hosted', 'resolvesToNoForm' => true, 'reason' => 'no_published_form_for_audience']
		);

		$described = $preview->describe(binding: []);

		$this->assertSame(PortalBindingPreview::RESOLVES_TO_NONE, $described['state']);
		$this->assertStringContainsString('opens no form today', $described['message']);
	}//end testABindingThatResolvesToNoneSaysSo()

	/**
	 * 🔴 AND IT INVENTS NO NAME. A stale or configured-but-unresolved name
	 * tells an administrator the binding is working.
	 *
	 * @return void
	 */
	public function testAFailedResolutionNamesNoForm(): void {
		$preview = $this->previewAnswering(render: ['kind' => 'hosted', 'resolvesToNoForm' => true]);

		$described = $preview->describe(binding: ['formName' => 'Melding openbare ruimte']);

		$this->assertNull($described['formName']);
	}//end testAFailedResolutionNamesNoForm()

	/**
	 * When the binding named a form, the refusal repeats the name it asked for
	 * and says what to do, so an administrator is sent to the right screen.
	 *
	 * @return void
	 */
	public function testTheRefusalRepeatsTheNameTheBindingAskedFor(): void {
		$preview = $this->previewAnswering(render: ['kind' => 'hosted', 'resolvesToNoForm' => true]);

		$message = $preview->describe(binding: ['formName' => 'Melding openbare ruimte'])['message'];

		$this->assertStringContainsString('Melding openbare ruimte', $message);
		$this->assertStringContainsString('Publish it', $message);
	}//end testTheRefusalRepeatsTheNameTheBindingAskedFor()

	/**
	 * With no name configured, the message explains the audience mismatch
	 * rather than printing an empty pair of quotes.
	 *
	 * @return void
	 */
	public function testAnUnnamedBindingExplainsTheAudienceInstead(): void {
		$preview = $this->previewAnswering(render: ['kind' => 'hosted', 'resolvesToNoForm' => true]);

		$message = $preview->describe(binding: [])['message'];

		$this->assertStringContainsString('type and audience', $message);
		$this->assertStringNotContainsString('""', $message);
	}//end testAnUnnamedBindingExplainsTheAudienceInstead()

	/**
	 * An external binding says where people are sent, and is not reported as a
	 * failure: sending somebody elsewhere is a working configuration.
	 *
	 * @return void
	 */
	public function testAnExternalBindingNamesItsDestination(): void {
		$preview = $this->previewAnswering(
			render: ['kind' => 'external', 'resolvesToNoForm' => false, 'destination' => 'mijn.gemeente.nl']
		);

		$described = $preview->describe(binding: []);

		$this->assertSame(PortalBindingPreview::EXTERNAL, $described['state']);
		$this->assertStringContainsString('mijn.gemeente.nl', $described['message']);
	}//end testAnExternalBindingNamesItsDestination()

	/**
	 * An external binding with no address is a failure, and says the specific
	 * thing that is wrong rather than the generic one.
	 *
	 * @return void
	 */
	public function testAnExternalBindingWithNoAddressIsAFailure(): void {
		$preview = $this->previewAnswering(
			render: ['kind' => 'external', 'resolvesToNoForm' => true, 'externalUrl' => '']
		);

		$described = $preview->describe(binding: []);

		$this->assertSame(PortalBindingPreview::RESOLVES_TO_NONE, $described['state']);
		$this->assertStringContainsString('no address is filled in', $described['message']);
	}//end testAnExternalBindingWithNoAddressIsAFailure()

	/**
	 * `needsAttention` is true for exactly the failing case, so a list can mark
	 * the broken entries without opening each one.
	 *
	 * @return void
	 */
	public function testNeedsAttentionIsTrueOnlyWhenItResolvesToNone(): void {
		$broken = $this->previewAnswering(render: ['kind' => 'hosted', 'resolvesToNoForm' => true]);
		$fine = $this->previewAnswering(
			render: ['kind' => 'hosted', 'resolvesToNoForm' => false, 'formName' => 'Melding']
		);

		$this->assertTrue($broken->needsAttention(binding: []));
		$this->assertFalse($fine->needsAttention(binding: []));
	}//end testNeedsAttentionIsTrueOnlyWhenItResolvesToNone()
}//end class
