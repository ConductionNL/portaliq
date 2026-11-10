<?php

/**
 * Golden outcome of normalising a mixed notifications list: what is kept, in
 * which place, and every dropped reason in the order it is reported, with and
 * without a contributing app.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-case-app-declares-which-change-a-resident-hears-about-req-nap-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\NotificationRuleNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * A mixed list, recorded.
 */
class NotificationRuleNormaliserGoldenTest extends TestCase {
	/**
	 * Junk, plain keys and change rules, kept and dropped, for an app and for none.
	 *
	 * @return void
	 */
	public function testAMixedListNormalisesAsRecorded(): void {
		$collections = [
		    ['id' => 'mijnZaken', 'register' => 'zaken', 'schema' => 'zaak', 'scopeField' => 'initiator', 'label' => 'My cases', 'fields' => ['identifier', 'status', 'title']],
		    ['id' => 'viaZaken', 'register' => 'zaken', 'schema' => 'zaak', 'via' => ['collection' => 'x'], 'fields' => ['status']],
		];
		$list = [
		    '', 7, null, 'case.updated', 'opencatalogi.thing', 'pipelinq.question.answered', 'nobody.fires.this',
		    ['ruleKey' => 'case.updated', 'collection' => 'mijnZaken', 'on' => ['field' => 'status', 'operator' => 'changed'], 'titleField' => 'identifier'],
		    ['ruleKey' => 'x', 'collection' => 'viaZaken', 'on' => ['field' => 'status', 'operator' => 'changed']],
		    ['collection' => 'mijnZaken'],
		    'message.created',
		    ['ruleKey' => 'case.closed', 'collection' => 'mijnZaken', 'on' => ['field' => 'status', 'operator' => 'changed']],
		];
		$actual = [
			(new NotificationRuleNormaliser())->normalise($list, $collections, 'opencatalogi'),
			(new NotificationRuleNormaliser())->normalise($list, $collections),
		];

		$this->assertSame(
			array (
			  0 => 
			  array (
			    'kept' => 
			    array (
			      0 => 'case.updated',
			      1 => 'opencatalogi.thing',
			      2 => 
			      array (
			        'ruleKey' => 'case.updated',
			        'collection' => 'mijnZaken',
			        'on' => 
			        array (
			          'field' => 'status',
			          'operator' => 'changed',
			        ),
			        'titleField' => 'identifier',
			      ),
			      3 => 'message.created',
			      4 => 
			      array (
			        'ruleKey' => 'case.closed',
			        'collection' => 'mijnZaken',
			        'on' => 
			        array (
			          'field' => 'status',
			          'operator' => 'changed',
			        ),
			      ),
			    ),
			    'dropped' => 
			    array (
			      0 => 'rule x on viaZaken: the collection is not scoped by the subject reference on the record',
			      1 => 'a change rule needs a ruleKey and a collection',
			      2 => 'key pipelinq.question.answered: a portalMessage with it is sent for app "pipelinq", never for this one; use opencatalogi.<key>',
			      3 => 'key nobody.fires.this: a portalMessage with it is sent for app "nobody", never for this one; use opencatalogi.<key>',
			    ),
			  ),
			  1 => 
			  array (
			    'kept' => 
			    array (
			      0 => 'case.updated',
			      1 => 
			      array (
			        'ruleKey' => 'case.updated',
			        'collection' => 'mijnZaken',
			        'on' => 
			        array (
			          'field' => 'status',
			          'operator' => 'changed',
			        ),
			        'titleField' => 'identifier',
			      ),
			      2 => 'message.created',
			      3 => 
			      array (
			        'ruleKey' => 'case.closed',
			        'collection' => 'mijnZaken',
			        'on' => 
			        array (
			          'field' => 'status',
			          'operator' => 'changed',
			        ),
			      ),
			    ),
			    'dropped' => 
			    array (
			      0 => 'rule x on viaZaken: the collection is not scoped by the subject reference on the record',
			      1 => 'a change rule needs a ruleKey and a collection',
			      2 => 'key opencatalogi.thing: a portalMessage with it is sent for app "opencatalogi", never for this one; use <appId>.<key>',
			      3 => 'key pipelinq.question.answered: a portalMessage with it is sent for app "pipelinq", never for this one; use <appId>.<key>',
			      4 => 'key nobody.fires.this: a portalMessage with it is sent for app "nobody", never for this one; use <appId>.<key>',
			    ),
			  ),
			),
			$actual
		);
	}//end testAMixedListNormalisesAsRecorded()

	/**
	 * A list that is not an array keeps and drops nothing.
	 *
	 * @return void
	 */
	public function testANonListIsEmpty(): void {
		$this->assertSame(['kept' => [], 'dropped' => []], (new NotificationRuleNormaliser())->normalise('case.updated', []));
		$this->assertSame(['kept' => [], 'dropped' => []], (new NotificationRuleNormaliser())->normalise(null, []));
	}//end testANonListIsEmpty()
}//end class
