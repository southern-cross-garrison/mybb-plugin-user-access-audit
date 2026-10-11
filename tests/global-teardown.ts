import { closeDb } from './helpers/db';
import { resetLegionApiStub } from './helpers/legion-api';
import { releaseSuiteLock } from './helpers/suite-lock';

export default async function globalTeardown(): Promise<void> {
	try {
		await closeDb();
		// Otherwise the stand-in answers as the last test left it - a broken list of units or
		// squads, say - for anybody trying the board by hand afterwards.
		resetLegionApiStub();
	} finally {
		releaseSuiteLock();
	}
}
