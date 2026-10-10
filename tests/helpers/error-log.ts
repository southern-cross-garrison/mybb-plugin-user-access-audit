import fs from 'node:fs';
import path from 'node:path';
import { FORUM_DIR } from './config';
import { runPhp } from './container';

/** Empty both logs, so what is read back afterwards was written since. */
export async function clearErrorLogs(): Promise<void> {
	await runPhp(`
@unlink('/var/www/html/cache/mybb_errors.log');
@unlink('/var/log/php_errors.log');
echo "cleared";
`);
}

/**
 * Everything MyBB's error log and PHP's have recorded since clearErrorLogs(). MyBB's log
 * is a run of `<error>` blocks and PHP's one line per error, so each entry is judged whole.
 */
export async function readErrorLogs(): Promise<string> {
	const log = await runPhp(`
foreach(array('/var/www/html/cache/mybb_errors.log', '/var/log/php_errors.log') as $file)
{
    if(file_exists($file)) { echo file_get_contents($file); }
}
echo "END_OF_LOG";
`);

	const entries = log.replace('END_OF_LOG', '').match(/<error>[\s\S]*?<\/error>|[^\n]+/g) ?? [];
	return entries
		.filter((entry) => entry.trim() !== '')
		.join('\n')
		.trim();
}

/**
 * MyBB's own error log, read from the bind mount rather than through the container. Every
 * warning a page raises lands here (the board logs rather than shows them, see
 * scripts/provision.php), so watching it is how the suite notices a notice.
 */
const MYBB_ERROR_LOG = path.join(FORUM_DIR, 'cache', 'mybb_errors.log');

/** Where MyBB's error log ends now, so what is read back later was written since. */
export function errorLogMark(): number {
	try {
		return fs.statSync(MYBB_ERROR_LOG).size;
	} catch {
		return 0;
	}
}

/** The entries MyBB has logged since a mark. */
export function errorLogSince(mark: number): string {
	let text = '';
	try {
		const size = fs.statSync(MYBB_ERROR_LOG).size;
		// Smaller than the mark: something emptied the log in between (clearErrorLogs()).
		const start = size < mark ? 0 : mark;
		const fd = fs.openSync(MYBB_ERROR_LOG, 'r');
		const buffer = Buffer.alloc(size - start);
		fs.readSync(fd, buffer, 0, buffer.length, start);
		fs.closeSync(fd);
		text = buffer.toString('utf8');
	} catch {
		return '';
	}

	const entries = text.match(/<error>[\s\S]*?<\/error>/g) ?? [];
	return entries.join('\n').trim();
}
