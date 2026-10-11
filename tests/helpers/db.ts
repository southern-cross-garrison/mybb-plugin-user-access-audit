import fs from 'node:fs';
import path from 'node:path';
import mysql, { Connection, RowDataPacket, ResultSetHeader } from 'mysql2/promise';
import { DB, FORUM_DIR, TABLE_PREFIX } from './config';
import { runPhp } from './container';

let connection: Connection | null = null;

export async function db(): Promise<Connection> {
	if (!connection) {
		connection = await mysql.createConnection({
			...DB,
			multipleStatements: true,
			dateStrings: true,
		});
	}
	return connection;
}

export async function closeDb(): Promise<void> {
	if (connection) {
		await connection.end();
		connection = null;
	}
}

export async function query<T extends RowDataPacket>(
	sql: string,
	params: unknown[] = []
): Promise<T[]> {
	const [rows] = await (await db()).execute<T[]>(sql, params);
	return rows;
}

export async function queryOne<T extends RowDataPacket>(
	sql: string,
	params: unknown[] = []
): Promise<T | null> {
	const rows = await query<T>(sql, params);
	return rows[0] ?? null;
}

export async function execute(sql: string, params: unknown[] = []): Promise<ResultSetHeader> {
	const [result] = await (await db()).execute<ResultSetHeader>(sql, params);
	return result;
}

export const T = (name: string) => `${TABLE_PREFIX}${name}`;

// ---------------------------------------------------------------------------
// Fixtures written by scripts/provision.php
// ---------------------------------------------------------------------------

export interface Fixtures {
	password: string;
	groups: {
		unit: number;
		legion: number;
		reserve: number;
		command: number;
		squad: number;
		otherSquad: number;
	};
	profileFields: { legion_id: number; costumes: number };
	users: Record<string, number>;
}

let fixturesCache: Fixtures | null = null;

export function fixtures(): Fixtures {
	if (!fixturesCache) {
		const file = path.join(FORUM_DIR, 'access-audit-fixtures.json');
		if (!fs.existsSync(file)) {
			throw new Error(`Fixture map not found at ${file}. Run scripts/bootstrap.sh first.`);
		}
		fixturesCache = JSON.parse(fs.readFileSync(file, 'utf8')) as Fixtures;
	}
	return fixturesCache;
}

export const uid = (username: string): number => {
	const id = fixtures().users[username];
	if (!id) {
		throw new Error(`Unknown fixture user "${username}"`);
	}
	return id;
};

/** MyBB's own groups. */
export const REGISTERED = 2;
export const BANNED = 7;

// ---------------------------------------------------------------------------
// Resetting between tests
// ---------------------------------------------------------------------------

const backupOf = (table: string) => `${TABLE_PREFIX}${table}_e2e_backup`;

const GROUPS_BACKUP = backupOf('user_groups');
const FIELDS_BACKUP = backupOf('userfields');
const SETTINGS_BACKUP = backupOf('access_audit_settings');

/**
 * Take a copy of what the tests change: every member's groups and profile fields, and the
 * plugin's settings. Not the users table whole - logging in writes to it too.
 */
export async function snapshotBoardState(): Promise<void> {
	const conn = await db();
	await conn.query(`DROP TABLE IF EXISTS ${GROUPS_BACKUP}`);
	await conn.query(
		`CREATE TABLE ${GROUPS_BACKUP} AS SELECT uid, usergroup, additionalgroups, displaygroup FROM ${T('users')}`
	);

	await conn.query(`DROP TABLE IF EXISTS ${FIELDS_BACKUP}`);
	await conn.query(`CREATE TABLE ${FIELDS_BACKUP} AS SELECT * FROM ${T('userfields')}`);

	await conn.query(`DROP TABLE IF EXISTS ${SETTINGS_BACKUP}`);
	await conn.query(
		`CREATE TABLE ${SETTINGS_BACKUP} AS SELECT name, value FROM ${T('settings')} WHERE name LIKE 'access\\_audit\\_%'`
	);
}

/**
 * Put every member's groups and Legion ID back as provisioned, forget every answer the
 * plugin kept from the API, and empty the administrator log.
 *
 * The settings are only put back when a test changed them, since that needs MyBB to
 * rebuild its settings file - the slowest part of a reset.
 */
export async function resetBoardState(): Promise<void> {
	const conn = await db();
	await conn.query(
		`UPDATE ${T('users')} u INNER JOIN ${GROUPS_BACKUP} b ON b.uid = u.uid
        SET u.usergroup = b.usergroup, u.additionalgroups = b.additionalgroups, u.displaygroup = b.displaygroup`
	);

	await conn.query(`DELETE FROM ${T('userfields')}`);
	await conn.query(`INSERT INTO ${T('userfields')} SELECT * FROM ${FIELDS_BACKUP}`);

	// Members a test created go with their rows.
	await conn.query(
		`DELETE u FROM ${T('users')} u LEFT JOIN ${GROUPS_BACKUP} b ON b.uid = u.uid WHERE b.uid IS NULL`
	);

	// Absent only while plugin-setup has the plugin uninstalled.
	const [tables] = await conn.query(`SHOW TABLES LIKE '${T('access_audit_lookups')}'`);
	if ((tables as unknown[]).length > 0) {
		await conn.query(`DELETE FROM ${T('access_audit_lookups')}`);
	}

	await conn.query(`TRUNCATE TABLE ${T('adminlog')}`);

	const [changed] = await conn.query(
		`SELECT COUNT(*) AS total FROM ${SETTINGS_BACKUP} b
       LEFT JOIN ${T('settings')} s ON s.name = b.name
      WHERE s.value IS NULL OR s.value <> b.value`
	);
	if (Number((changed as RowDataPacket[])[0].total) > 0) {
		await conn.query(
			`UPDATE ${T('settings')} s INNER JOIN ${SETTINGS_BACKUP} b ON b.name = s.name SET s.value = b.value`
		);
		await runPhp('rebuild_settings(); echo "rebuilt";');
	}
}

// ---------------------------------------------------------------------------
// Members
// ---------------------------------------------------------------------------

export interface MemberGroups {
	usergroup: number;
	additionalgroups: number[];
	displaygroup: number;
}

/** A member's groups, additional groups sorted. */
export async function getGroups(username: string): Promise<MemberGroups> {
	const row = await queryOne<RowDataPacket>(
		`SELECT usergroup, additionalgroups, displaygroup FROM ${T('users')} WHERE uid = ?`,
		[uid(username)]
	);
	if (!row) throw new Error(`User ${username} not found`);
	return {
		usergroup: Number(row.usergroup),
		additionalgroups: String(row.additionalgroups)
			.split(',')
			.map(Number)
			.filter((gid) => gid > 0)
			.sort((a, b) => a - b),
		displaygroup: Number(row.displaygroup),
	};
}

/** Put a member in these groups, without going through a page. */
export async function setGroups(
	username: string,
	{ usergroup = REGISTERED, additionalgroups = [] as number[], displaygroup = 0 } = {}
): Promise<void> {
	await execute(
		`UPDATE ${T('users')} SET usergroup = ?, additionalgroups = ?, displaygroup = ? WHERE uid = ?`,
		[usergroup, additionalgroups.join(','), displaygroup, uid(username)]
	);
}

/** Change what a member has typed in their Legion ID field. */
export async function setLegionId(username: string, value: string): Promise<void> {
	await execute(
		`UPDATE ${T('userfields')} SET fid${fixtures().profileFields.legion_id} = ? WHERE ufid = ?`,
		[value, uid(username)]
	);
}

// ---------------------------------------------------------------------------
// Board state
// ---------------------------------------------------------------------------

export async function getSetting(name: string): Promise<string> {
	const row = await queryOne<RowDataPacket>(`SELECT value FROM ${T('settings')} WHERE name = ?`, [
		name,
	]);
	return String(row?.value ?? '');
}

/** The cached API answers, by Legion ID. */
export async function lookups(): Promise<RowDataPacket[]> {
	return query<RowDataPacket>(`SELECT * FROM ${T('access_audit_lookups')} ORDER BY legion_id`);
}

/** The newest administrator log entries, newest first. */
export async function adminLog(): Promise<RowDataPacket[]> {
	return query<RowDataPacket>(
		`SELECT module, action, data FROM ${T('adminlog')} ORDER BY dateline DESC, uid DESC`
	);
}
