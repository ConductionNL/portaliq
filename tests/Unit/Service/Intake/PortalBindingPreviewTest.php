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
	 * submit-creates-the-case-directly REQ-PIFO-007: the preview shows the
	 * findings the publish check would refuse on, from the same check.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-form-binding-names-its-destination-and-is-valid-against-it-req-pifo-007
	 */
	public function testAResolvedBindingShowsWhatStopsItBeingPublished(): void {
		$resolver = $this->getMockBuilder(PortalFormBindingResolver::class)->disableOriginalConstructor()->onlyMethods(['render'])->getMock();
		$resolver->method('render')->willReturn(['kind' => 'hosted', 'resolvesToNoForm' => false, 'formName' => 'Bezwaar']);
		$finding = ['property' => 'communicationChannel', 'code' => 'required-unmapped', 'message' => 'Nobody asks for it.'];
		$intake  = $this->createMock(\OCA\Portaliq\Service\Intake\DestinationIntake::class);
		$intake->expects($this->once())->method('check')->willReturn([$finding]);

		$described = (new PortalBindingPreview(resolver: $resolver, intake: $intake))->describe(binding: ['status' => 'draft']);

		$this->assertSame([$finding], $described['findings']);
		$this->assertSame([], $this->previewAnswering(render: ['kind' => 'hosted', 'resolvesToNoForm' => false, 'formName' => 'X'])->describe(binding: [])['findings']);
	}//end testAResolvedBindingShowsWhatStopsItBeingPublished()

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
	 * operate-show-per-case-type: a binding for a case type the portal hides
	 * opens no form, and the administrator is told that is why.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
	 */
	public function testAHiddenCaseTypeIsNamedAsTheReason(): void {
		$preview = $this->previewAnswering(
			render: ['kind' => 'hosted', 'resolvesToNoForm' => true, 'reason' => 'hiddenCaseType']
		);

		$described = $preview->describe(binding: ['formName' => 'Handhavingsverzoek']);

		$this->assertSame(PortalBindingPreview::RESOLVES_TO_NONE, $described['state']);
		$this->assertSame(PortalBindingPreview::REASON_HIDDEN_CASE_TYPE, $described['reason']);
		$this->assertStringContainsString('does not show its case type', $described['message']);
	}//end testAHiddenCaseTypeIsNamedAsTheReason()

	/**
	 * REQ-ICQ-003: the administrator reads why a form with a condition the
	 * portal cannot check opens nothing, and what to change.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-a-condition-the-portal-cannot-check-refuses-the-form-req-icq-003
	 */
	public function testAnUnsupportedConditionIsNamedAsTheReason(): void {
		$preview = $this->previewAnswering(
			render: ['kind' => 'hosted', 'resolvesToNoForm' => true, 'reason' => 'unsupportedCondition']
		);

		$described = $preview->describe(binding: ['formName' => 'Verhuizing']);

		$this->assertSame(PortalBindingPreview::RESOLVES_TO_NONE, $described['state']);
		$this->assertSame(PortalBindingPreview::REASON_UNSUPPORTED_CONDITION, $described['reason']);
		$this->assertSame(
			'This form uses a condition the portal cannot check. Change it to a condition on another answer.',
			$described['message']
		);
	}//end testAnUnsupportedConditionIsNamedAsTheReason()

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

	/**
	 * Every resolution is described exactly as recorded.
	 *
	 * @return void
	 */
	public function testEveryResolutionIsDescribedAsRecorded(): void {
		$cases = [
			[['kind' => 'external', 'resolvesToNoForm' => false, 'destination' => 'example.org'], []],
			[['kind' => 'external', 'resolvesToNoForm' => false], []],
			[['kind' => 'hosted', 'resolvesToNoForm' => false, 'formName' => 'Bezwaar'], ['formName' => 'Other']],
			[['resolvesToNoForm' => false], []],
			[['kind' => 'hosted', 'resolvesToNoForm' => true, 'reason' => 'hiddenCaseType'], ['formName' => ' Asked ']],
			[['kind' => 'hosted', 'resolvesToNoForm' => true, 'reason' => 'unsupportedCondition'], []],
			[['kind' => 'external', 'resolvesToNoForm' => true, 'reason' => 'x'], ['formName' => 'Asked']],
			[['kind' => 'hosted', 'resolvesToNoForm' => true], ['formName' => ' Asked ']],
			[['kind' => 'hosted', 'resolvesToNoForm' => true], ['formName' => '  ']],
			[['resolvesToNoForm' => 'yes'], []],
		];

		$described = [];
		foreach ($cases as [$render, $binding]) {
			$described[] = $this->previewAnswering(render: $render)->describe(binding: $binding);
		}

		$this->assertSame(
			[
				[
					'state' => 'external',
					'formName' => null,
					'reason' => null,
					'askedFor' => null,
					'destination' => 'example.org',
					'message' => 'This entry sends people to example.org. Nothing is filled in here, so nothing arrives here either.',
				],
				[
					'state' => 'external',
					'formName' => null,
					'reason' => null,
					'askedFor' => null,
					'destination' => '',
					'message' => 'This entry sends people to another website. Nothing is filled in here, so nothing arrives here either.',
				],
				[
					'findings' => [],
					'state' => 'resolved',
					'formName' => 'Bezwaar',
					'reason' => null,
					'askedFor' => null,
					'destination' => null,
					'message' => 'This entry opens "Bezwaar" today.',
				],
				[
					'findings' => [],
					'state' => 'resolved',
					'formName' => '',
					'reason' => null,
					'askedFor' => null,
					'destination' => null,
					'message' => 'This entry opens "" today.',
				],
				[
					'state' => 'resolves_to_none',
					'formName' => null,
					'reason' => 'hidden_case_type',
					'askedFor' => 'Asked',
					'destination' => null,
					'message' => 'This entry opens no form: this portal does not show its case type. Show it again under Case types on the portal\'s page.',
				],
				[
					'state' => 'resolves_to_none',
					'formName' => null,
					'reason' => 'unsupported_condition',
					'askedFor' => null,
					'destination' => null,
					'message' => 'This form uses a condition the portal cannot check. Change it to a condition on another answer.',
				],
				[
					'state' => 'resolves_to_none',
					'formName' => null,
					'reason' => 'external_without_address',
					'askedFor' => 'Asked',
					'destination' => null,
					'message' => 'This entry is set to send people to another website, but no address is filled in, so nobody can start it.',
				],
				[
					'state' => 'resolves_to_none',
					'formName' => null,
					'reason' => 'named_form_not_published',
					'askedFor' => 'Asked',
					'destination' => null,
					'message' => 'This entry opens no form today. It asks for "Asked", and no form of that name is published to this audience. Publish it, or point the entry at a form that is.',
				],
				[
					'state' => 'resolves_to_none',
					'formName' => null,
					'reason' => 'no_form_for_type_and_audience',
					'askedFor' => null,
					'destination' => null,
					'message' => 'This entry opens no form today. No published form matches its type and audience, so a citizen who reaches it sees nothing to fill in.',
				],
				[
					'findings' => [],
					'state' => 'resolved',
					'formName' => '',
					'reason' => null,
					'askedFor' => null,
					'destination' => null,
					'message' => 'This entry opens "" today.',
				],
			],
			$described
		);
	}//end testEveryResolutionIsDescribedAsRecorded()
}//end class
