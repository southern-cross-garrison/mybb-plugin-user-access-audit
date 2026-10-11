import fs from 'node:fs';
import path from 'node:path';
import { FORUM_DIR, REPO_ROOT } from './config';

/**
 * The 501st Legion API stand-in (tests/stubs/legion-api.php).
 *
 * Provisioning points access_audit_api_url at it, so the plugin never reaches the real
 * API from the suite. Every test starts with the list of units below and no member
 * stubbed: a Legion ID with no answer gets a 503, the API being down.
 */
const STUB_FILE = path.join(FORUM_DIR, 'e2e-legion-api.php');
const STUB_DIR = path.join(FORUM_DIR, 'e2e-legion-api');
const REQUEST_LOG = path.join(STUB_DIR, 'requests.log');

/** The board's own unit, as provisioned. */
export const OUR_UNIT = { id: 80, name: 'Southern Cross Garrison' };
export const OTHER_UNIT = { id: 43, name: 'Midsouth Garrison' };
export const OUTPOST = { id: 55, name: 'Saudi Arabian Outpost' };

/**
 * The squads the stand-in lists: two of this unit's, the first of which the board audits
 * as provisioned, and one of another unit's.
 *
 * /squads does not say which unit a squad is in, so the plugin reads it from the records
 * of the squad's officers, which every test starts with stubbed: Illawarra's first officer
 * has no public record, so it is placed by its second.
 */
export const SQUAD = { id: 301, name: 'Hunter Valley Squad', officers: [40001] };
export const OTHER_SQUAD = { id: 302, name: 'Illawarra Squad', officers: [40002, 40003] };
export const UNAUDITED_SQUAD = { id: 102, name: 'Snowy Range Squad', officers: [40004] };

/** Shaped as the real /squads is, under "squad", in the API's own order. */
export const SQUADS = [UNAUDITED_SQUAD, SQUAD, OTHER_SQUAD].map((squad) => ({
	squadId: squad.id,
	name: squad === OTHER_SQUAD ? ` ${squad.name}` : squad.name,
	logoPrefix: squad.name.replace(/ /g, '-'),
	area: 'Somewhere',
	url: 'example.test',
	unitLogo: '',
	unitLogoSmall: '',
	officers: squad.officers.map((legionId) => ({
		officeAcronym: 'SL',
		office: 'Squad Leader',
		legionId,
		formattedLegionId: `TK ${legionId}`,
		fullName: 'Squad Officer',
	})),
}));

/** Stub the squad officers' records, which place each squad in its unit. */
export function stubSquadOfficers(): void {
	stubLegionApi(40001, legionApiMember(40001, { squad: SQUAD }));
	stubLegionApi(40002, CLASSIFIED);
	stubLegionApi(40003, legionApiMember(40003, { squad: OTHER_SQUAD }));
	stubLegionApi(40004, legionApiMember(40004, { unit: OTHER_UNIT, squad: UNAUDITED_SQUAD }));
}

/** Shaped as the real /garrisons is, officers and all, in the API's own order. */
export const UNITS = [OUR_UNIT, OTHER_UNIT, OUTPOST].map((unit) => ({
	garrisonId: unit.id,
	name: unit === OUTPOST ? `${unit.name} ` : unit.name,
	logoPrefix: unit.name.replace(/ /g, '-'),
	area: 'Somewhere',
	url: 'example.test',
	unitType: unit === OUTPOST ? 'Outpost' : 'Garrison',
	unitLogo: '',
	unitLogoSmall: '',
	officers: [],
}));

export type LegionApiResponse =
	{ status?: number; body: unknown } | { status?: number; raw: string };

/** Put the stub in the forum root. Run by global setup. */
export function installLegionApiStub(): void {
	fs.copyFileSync(path.join(REPO_ROOT, 'tests', 'stubs', 'legion-api.php'), STUB_FILE);
	resetLegionApiStub();
}

/**
 * Back to the stock lists of units and squads, their officers and no members, and forget
 * every logged request.
 */
export function resetLegionApiStub(): void {
	fs.rmSync(STUB_DIR, { recursive: true, force: true });
	fs.mkdirSync(STUB_DIR, { recursive: true });
	// Apache writes the request log, and on Linux it is not the user that made the folder.
	fs.chmodSync(STUB_DIR, 0o777);
	stubUnits({ body: { garrisons: UNITS } });
	stubSquads({ body: { squad: SQUADS } });
	stubSquadOfficers();
}

/** Say what /garrisons answers. */
export function stubUnits(response: LegionApiResponse): void {
	fs.writeFileSync(path.join(STUB_DIR, 'garrisons.json'), JSON.stringify(response));
}

/** Say what /squads answers. */
export function stubSquads(response: LegionApiResponse): void {
	fs.writeFileSync(path.join(STUB_DIR, 'squads.json'), JSON.stringify(response));
}

/** Make /squads answer 503. */
export function squadsDown(): void {
	fs.rmSync(path.join(STUB_DIR, 'squads.json'), { force: true });
}

/** Make /garrisons answer 503. */
export function unitsDown(): void {
	fs.rmSync(path.join(STUB_DIR, 'garrisons.json'), { force: true });
}

/** Say what the API answers for one Legion ID (digits, as the plugin requests it). */
export function stubLegionApi(legionId: number, response: LegionApiResponse): void {
	fs.writeFileSync(path.join(STUB_DIR, `${legionId}.json`), JSON.stringify(response));
}

/** Make one Legion ID answer 503 again. */
export function unstubLegionApi(legionId: number): void {
	fs.rmSync(path.join(STUB_DIR, `${legionId}.json`), { force: true });
}

/** A public member record, shaped as the real /legionId/{id} returns one. */
export function legionApiMember(
	legionId: number,
	{
		status = 'Active',
		unit = OUR_UNIT,
		fullName = 'Fixture Trooper',
		squad = null,
	}: {
		status?: string;
		unit?: { id: number; name: string };
		fullName?: string;
		squad?: { id: number; name: string } | null;
	} = {}
): LegionApiResponse {
	return {
		body: {
			legionId,
			formattedLegionId: `TK ${legionId}`,
			fullName,
			firstName: fullName.split(' ')[0],
			lastName: fullName.split(' ').slice(1).join(' '),
			joinDate: '2020-01-01T00:00:00.000Z',
			profileUrl: `https://www.501st.com/member/${legionId}`,
			primaryThumbnail: '',
			memberApproved: 'YES',
			memberStatus: status,
			memberStanding: 'Good',
			garrisonId: unit.id,
			garrisonName: unit.name,
			// A string, as the real API gives it, and empty for a member in no squad.
			squadId: squad ? String(squad.id) : '',
			squadName: squad ? squad.name : 'No Squad',
			roles: {},
		},
	};
}

/** What the API answers for a private record, a retired member, or an ID nobody holds. */
export const CLASSIFIED: LegionApiResponse = { body: { memberStatus: 'Classified Record' } };

/** Stub each fixture member's usual record, the one their provisioned groups match. */
export function stubFixtureMembers(): void {
	stubLegionApi(10001, legionApiMember(10001, { fullName: 'Unit Member' }));
	stubLegionApi(10002, legionApiMember(10002, { unit: OTHER_UNIT, fullName: 'Legion Member' }));
	stubLegionApi(10003, legionApiMember(10003, { fullName: 'Unit Commander' }));
	stubLegionApi(20001, CLASSIFIED);
	stubLegionApi(20002, CLASSIFIED);
	stubLegionApi(30001, legionApiMember(30001));
}

/** The paths the plugin has requested since the last reset, oldest first. */
export function legionApiRequests(): string[] {
	if (!fs.existsSync(REQUEST_LOG)) {
		return [];
	}
	return fs.readFileSync(REQUEST_LOG, 'utf8').split('\n').filter(Boolean);
}

/** The member lookups among them, the squad officers' included. */
export function memberRequests(): string[] {
	return legionApiRequests().filter((request) => request.startsWith('/legionId/'));
}
