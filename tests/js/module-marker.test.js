import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, existsSync } from 'node:fs';
import { dirname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

// Node 22 guesses that a .js file with import/export is an ES module; Node 18 and 20.16 do not and load it as
// CommonJS. A run on Node 22 alone would hide a missing marker, so this test reads the files and the nearest
// package.json itself and does not depend on that guess.

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

// The apps whose scripts Node imports in tests/js. The Admin scripts are not imported by any Node test yet.
const SCRIPT_FOLDERS = ['templates/shop/assets', 'templates/pos/assets', 'tests/js'];

const MODULE_SYNTAX = /^\s*(import\s+[\w{*'"]|export\s+(default|const|let|var|function|class|async|\{|\*))/m;

function scripts(folder) {
  const found = [];
  for (const entry of readdirSync(folder, { withFileTypes: true })) {
    const path = join(folder, entry.name);
    if (entry.isDirectory()) found.push(...scripts(path));
    else if (entry.name.endsWith('.js')) found.push(path);
  }
  return found;
}

/** The "type" of the nearest package.json above a file, or null when there is none before the project root. */
export function nearestType(file) {
  for (let dir = dirname(file); dir.startsWith(root); dir = dirname(dir)) {
    const candidate = join(dir, 'package.json');
    if (existsSync(candidate)) return JSON.parse(readFileSync(candidate, 'utf8')).type ?? 'commonjs';
    if (dir === root) break;
  }
  return null;
}

test('the guard recognises module syntax and ignores comments that only mention it', () => {
  assert.equal(MODULE_SYNTAX.test("import { a } from './a.js';\n"), true);
  assert.equal(MODULE_SYNTAX.test("// shared\nexport const A = 1;\n"), true);
  assert.equal(MODULE_SYNTAX.test('/**\n * import or export in a comment\n */\nconst a = 1;\n'), false);
});

for (const folder of SCRIPT_FOLDERS) {
  test(`every script in ${folder} that uses import or export has a package.json with "type": "module"`, () => {
    const files = scripts(join(root, folder));
    assert.ok(files.length > 0, `no scripts found in ${folder}`);
    const unmarked = files
      .filter((file) => MODULE_SYNTAX.test(readFileSync(file, 'utf8')))
      .filter((file) => nearestType(file) !== 'module')
      .map((file) => relative(root, file).split(sep).join('/'));
    assert.deepEqual(unmarked, [], 'ES module scripts without a "type": "module" package.json nearby (Node 18 and 20 load them as CommonJS)');
  });
}
