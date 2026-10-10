<?php
// SPDX-License-Identifier: EUPL-1.2
//
// The plain search form (site-honest-without-javascript REQ-SHJ-003): a GET
// to the plain page with the route kept, so it works without JavaScript.
// Included by site-plain.php with $block set to a `search` block.

declare(strict_types=1);

/** @var array $block */
$form = (array)($block['form'] ?? []);
?>
<form method="get" action="<?php p((string)($form['action'] ?? '')); ?>" role="search" class="pq-plain__search">
    <input type="hidden" name="route" value="<?php p((string)($form['route'] ?? '/')); ?>">
    <?php if ((string)($form['portal'] ?? '') !== '') { ?>
    <input type="hidden" name="portal" value="<?php p((string)$form['portal']); ?>">
    <?php } ?>
    <label class="utrecht-form-label" for="pq-plain-search"><?php p((string)($form['label'] ?? '')); ?></label>
    <input id="pq-plain-search" class="utrecht-textbox utrecht-textbox--html-input" type="search" name="_search" value="<?php p((string)($form['query'] ?? '')); ?>">
    <button type="submit" class="utrecht-button utrecht-button--primary-action"><?php p((string)($form['button'] ?? '')); ?></button>
</form>
