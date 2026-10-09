## ADDED Requirements

### Requirement: This month keeps this week's past days

A calendar block drawn as tiles with `range: month` MUST start at this week's Monday, so the past
days of the current week stay in view, and MUST mark today's tile with `aria-current="date"`.
Without that range the tiles MUST start at today.

#### Scenario: A Thursday visitor
@e2e exclude Rendered in node: tests/site-look/month-keeps-this-week.spec.mjs
- GIVEN items on Thursday 1, Monday 5, Wednesday 7, Thursday 8 and Friday 9 October, and today Thursday 8 October
- WHEN "Deze maand" renders with `range: month`
- THEN it shows 5, 7, 8 and 9 October, with 8 October marked as today
