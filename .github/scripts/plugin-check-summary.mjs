// Summarizes Plugin Check results (wp plugin check --format=json output) for
// the GitHub job summary. Usage: node plugin-check-summary.mjs <results-file>
import { appendFileSync, existsSync, readFileSync } from 'node:fs';

const file = process.argv[2];
const summary = process.env.GITHUB_STEP_SUMMARY;
const lines = ['## Plugin Check (WordPress.org build)', ''];

const results = [];
if (file && existsSync(file)) {
	let current = '';
	for (const line of readFileSync(file, 'utf8').split('\n')) {
		if (line.startsWith('FILE: ')) {
			current = line.slice(6).trim();
		} else if (line.trim().startsWith('[')) {
			try {
				for (const result of JSON.parse(line)) {
					results.push({ file: current, ...result });
				}
			} catch {
				// Not a results line.
			}
		}
	}

	const errors = results.filter((r) => r.type === 'ERROR');
	const warnings = results.filter((r) => r.type !== 'ERROR');
	lines.push(`**${errors.length} errors, ${warnings.length} warnings.** Errors fail this job; warnings are reported only.`, '');

	if (results.length > 0) {
		lines.push('| Type | File | Line | Code | Message |', '| --- | --- | --- | --- | --- |');
		for (const r of [...errors, ...warnings]) {
			const message = String(r.message ?? '').replace(/<[^>]+>/g, '').replace(/\|/g, '\\|').replace(/\s+/g, ' ').trim();
			lines.push(`| ${r.type} | \`${r.file}\` | ${r.line ?? ''} | \`${r.code}\` | ${message} |`);
		}
	}
	console.log(`Plugin Check: ${errors.length} errors, ${warnings.length} warnings.`);
} else {
	lines.push('No results file was produced. See the Run Plugin Check step log.');
	console.log('Plugin Check: no results file.');
}

if (summary) {
	appendFileSync(summary, `${lines.join('\n')}\n`);
}
