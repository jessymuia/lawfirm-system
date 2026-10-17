const AxeBuilder = require('@axe-core/playwright').default;
const { expect } = require('@playwright/test');

/**
 * UI-15: the automated accessibility gate. Runs axe-core against the page as it is rendered now, for WCAG 2.1 A and AA,
 * and fails on any violation, naming the rule, the elements and how to fix them. Nothing is exempted: a page that
 * needs an exception should be fixed instead.
 */
const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

async function expectAccessible(page, label, { include } = {}) {
  let builder = new AxeBuilder({ page }).withTags(WCAG_TAGS);
  if (include) builder = builder.include(include);
  const { violations } = await builder.analyze();
  const report = violations.map((v) => `${v.id} (${v.impact}): ${v.help}\n${v.nodes.slice(0, 5)
    .map((n) => `    ${n.target.join(' ')}\n      ${n.failureSummary.split('\n').slice(1).join(' ').trim()}`).join('\n')}`);
  expect(report, `${label}: accessibility violations (WCAG 2.1 AA)\n${report.join('\n')}`).toEqual([]);
}

module.exports = { expectAccessible };
