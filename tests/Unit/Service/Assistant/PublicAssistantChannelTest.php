<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Assistant;

use OCA\Portaliq\Service\Assistant\PersonalDetailsFilter;
use OCA\Portaliq\Service\Assistant\PublicAssistantChannel;
use OCA\Portaliq\Service\Assistant\PublicSourceScope;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A stand-in for hermiq's entry point: answers what the test hands it and
 * records the named arguments it was called with.
 */
class FakeAssistantEntry {

	/**
	 * The reply, or an exception to throw.
	 *
	 * @var array<string, mixed>|RuntimeException
	 */
	public array|RuntimeException $reply = [];

	/**
	 * Every call's arguments.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $calls = [];

	/**
	 * Hermiq's converse(), with the named parameters the channel passes.
	 *
	 * @param string $question The question.
	 * @param string $portal The portal slug.
	 * @param string $locale The locale.
	 * @param array<string, mixed> $scope The source scope.
	 * @param string $conversationId The conversation id.
	 * @param array<int, mixed> $tools The tools allowed.
	 *
	 * @return array<string, mixed>
	 */
	public function converse(string $question, string $portal, string $locale, array $scope, string $conversationId, array $tools): array {
		$this->calls[] = compact('question', 'portal', 'locale', 'scope', 'conversationId', 'tools');
		if ($this->reply instanceof RuntimeException) {
			throw $this->reply;
		}

		return $this->reply;
	}
}

/**
 * search-assistant-from-public-content REQ-SAP-002, REQ-SAP-004, REQ-SAP-005:
 * the adapter forwards no identity, removes personal details and asks for no tool.
 *
 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t03
 */
class PublicAssistantChannelTest extends TestCase {

	private function channel(object $entry, string $class=FakeAssistantEntry::class): PublicAssistantChannel {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($entry);

		return new PublicAssistantChannel(
			$container,
			$this->createMock(LoggerInterface::class),
			new PublicSourceScope(),
			new PersonalDetailsFilter(),
			$class,
		);
	}

	private function answered(): FakeAssistantEntry {
		$entry        = new FakeAssistantEntry();
		$entry->reply = [
			'answer'         => 'Op Koningsdag wordt het afval een dag later opgehaald.',
			'conversationId' => 'c-1',
			'sources'        => [['schema' => 'page', 'title' => 'Afvalkalender feestdagen', 'url' => '/afval', 'route' => 'afval']],
		];
		return $entry;
	}

	public function testNoIdentityForwarded(): void {
		$entry = $this->answered();
		$this->channel($entry)->ask(portal: ['slug' => 'z', 'organisation' => 'org-1', 'subjectRef' => 's'], question: 'Wanneer?', locale: 'nl', conversationId: 'c-0');

		$this->assertCount(1, $entry->calls);
		$this->assertSame(['question', 'portal', 'locale', 'scope', 'conversationId', 'tools'], array_keys($entry->calls[0]));
		$this->assertSame('z', $entry->calls[0]['portal']);
		$this->assertSame('c-0', $entry->calls[0]['conversationId']);
		$this->assertStringNotContainsString('org-1', json_encode($entry->calls[0]));

	}//end testNoIdentityForwarded()

	public function testCitizenServiceNumberRemoved(): void {
		$entry = $this->answered();
		$reply = $this->channel($entry)->ask(portal: ['slug' => 'z'], question: 'Mijn bsn is 111222333 en mail mij op ans@example.nl of 06 12345678', locale: 'nl');

		$this->assertSame('Mijn bsn is [removed] en mail mij op [removed] of [removed]', $entry->calls[0]['question']);
		$this->assertTrue($reply['removed']);

	}//end testCitizenServiceNumberRemoved()

	public function testANumberThatFailsTheElevenTestStays(): void {
		$entry = $this->answered();
		$reply = $this->channel($entry)->ask(portal: ['slug' => 'z'], question: 'Postbus 123456789 openen?', locale: 'nl');

		$this->assertSame('Postbus 123456789 openen?', $entry->calls[0]['question']);
		$this->assertFalse($reply['removed']);

	}//end testANumberThatFailsTheElevenTestStays()

	public function testNoToolsRequested(): void {
		$entry = $this->answered();
		$this->channel($entry)->ask(portal: ['slug' => 'z'], question: 'Vraag een parkeervergunning voor me aan', locale: 'nl');

		$this->assertSame([], $entry->calls[0]['tools']);

	}//end testNoToolsRequested()

	public function testAnAnswerWithASourceIsShown(): void {
		$reply = $this->channel($this->answered())->ask(portal: ['slug' => 'z'], question: 'Afval?', locale: 'nl');

		$this->assertSame('answered', $reply['status']);
		$this->assertSame('Afvalkalender feestdagen', $reply['sources'][0]['title']);
		$this->assertSame('c-1', $reply['conversationId']);

	}//end testAnAnswerWithASourceIsShown()

	public function testAnAnswerWithoutAnAdmittedSourceIsAnAbstention(): void {
		$entry        = new FakeAssistantEntry();
		$entry->reply = [
			'answer'  => 'Een verzonnen antwoord.',
			'sources' => [
				['schema' => 'portalMessage', 'title' => 'Bericht', 'url' => '/mijn/berichten'],
				['schema' => 'page', 'title' => 'Zonder link', 'url' => 'javascript:alert(1)'],
			],
		];
		$reply = $this->channel($entry)->ask(portal: ['slug' => 'z'], question: 'Iets?', locale: 'nl');

		$this->assertSame('abstained', $reply['status']);
		$this->assertNull($reply['answer']);
		$this->assertSame([], $reply['sources']);

	}//end testAnAnswerWithoutAnAdmittedSourceIsAnAbstention()

	public function testTheChannelIsUnavailableWithoutHermiq(): void {
		$channel = $this->channel(new FakeAssistantEntry(), 'OCA\\Hermiq\\Service\\DoesNotExist');

		$this->assertFalse($channel->isAvailable());
		$this->assertSame('unavailable', $channel->ask(portal: ['slug' => 'z'], question: 'Hallo', locale: 'nl')['status']);

	}//end testTheChannelIsUnavailableWithoutHermiq()

	public function testAFailingEntryPointIsUnavailableNotAnAnswer(): void {
		$entry        = new FakeAssistantEntry();
		$entry->reply = new RuntimeException('model down');

		$this->assertSame('unavailable', $this->channel($entry)->ask(portal: ['slug' => 'z'], question: 'Hallo', locale: 'nl')['status']);

	}//end testAFailingEntryPointIsUnavailableNotAnAnswer()
}//end class
