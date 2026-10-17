#!/usr/bin/env node

/**
 * UI-10/Phase 5 prep: catalogs every raw hex color literal in the
 * frontend so a real branding pass (5.1) has a concrete, complete
 * worklist instead of starting from zero. This script does NOT choose
 * or apply any brand colors — that requires the firm's actual palette,
 * which doesn't exist yet (see the plan's Phase 5 gating note). It only
 * measures the current hardcoding debt and groups it by file so the
 * eventual sweep can be scoped and prioritized.
 *
 * Usage: node scripts/audit-hardcoded-colors.js
 *
 * UI-13: a CI gate since firm branding landed. Firm colours reach a component only through the design tokens
 * (var(--primary-*), set from the firm's colour at runtime), so a raw hex literal is a component that will not follow
 * the firm's branding. The gate is a ratchet: it fails when the count rises above the baseline
 * (scripts/hardcoded-colors-baseline.json), and asks for the baseline to be lowered when a sweep brings it down.
 */

const fs = require('fs');
const path = require('path');

const AS_CI_GATE = true;
const BASELINE_PATH = path.join(__dirname, 'hardcoded-colors-baseline.json');
const ROOT = path.resolve(__dirname, '..', 'resources', 'js', 'src');
const HEX_PATTERN = /#[0-9a-fA-F]{3,8}\b/g;
const EXCLUDE_DIRS = new Set(['node_modules', 'assets']); // assets/sass/design-tokens.scss and friends are the *source* of truth, not debt

function walk(dir, files = []) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        if (EXCLUDE_DIRS.has(entry.name)) continue;
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            walk(full, files);
        } else if (entry.name.endsWith('.vue') || entry.name.endsWith('.js')) {
            files.push(full);
        }
    }
    return files;
}

function auditFile(filePath) {
    const content = fs.readFileSync(filePath, 'utf8');
    const lines = content.split(/\r?\n/);
    const hits = [];

    lines.forEach((line, idx) => {
        const matches = line.match(HEX_PATTERN);
        if (matches) {
            matches.forEach((color) => hits.push({ line: idx + 1, color, text: line.trim().slice(0, 100) }));
        }
    });

    return hits;
}

function main() {
    const files = walk(ROOT);
    const report = [];
    let totalHits = 0;
    const colorFrequency = {};

    for (const file of files) {
        const hits = auditFile(file);
        if (hits.length) {
            report.push({ file: path.relative(ROOT, file), hits });
            totalHits += hits.length;
            hits.forEach((h) => {
                const normalized = h.color.toLowerCase();
                colorFrequency[normalized] = (colorFrequency[normalized] || 0) + 1;
            });
        }
    }

    report.sort((a, b) => b.hits.length - a.hits.length);

    console.log(`Hardcoded color audit — ${totalHits} raw hex literals across ${report.length} files\n`);

    const topColors = Object.entries(colorFrequency)
        .sort((a, b) => b[1] - a[1])
        .slice(0, 15);

    console.log('Most repeated raw colors (candidates for a design token first):');
    topColors.forEach(([color, count]) => console.log(`  ${color}  x${count}`));
    console.log('');

    console.log('Top 20 files by hardcoded-color count (prioritize these first in the sweep):');
    report.slice(0, 20).forEach((entry) => {
        console.log(`  ${entry.hits.length.toString().padStart(4)}  ${entry.file}`);
    });

    const reportPath = path.join(__dirname, 'hardcoded-colors-report.json');
    fs.writeFileSync(reportPath, JSON.stringify(report, null, 2));
    console.log(`\nFull per-line report written to ${path.relative(process.cwd(), reportPath)}`);

    if (AS_CI_GATE) {
        const baseline = JSON.parse(fs.readFileSync(BASELINE_PATH, 'utf8')).count;
        if (totalHits > baseline) {
            console.error(`\n${totalHits} hardcoded colors, above the baseline of ${baseline}: use a design token (var(--primary-*), var(--text-*), ...) instead of a hex literal.`);
            process.exit(1);
        }
        if (totalHits < baseline) {
            console.log(`\nDown from ${baseline} to ${totalHits}: lower the baseline in ${path.relative(process.cwd(), BASELINE_PATH)} to lock the gain in.`);
        }
    }
}

main();
