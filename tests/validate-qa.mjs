import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const qa = JSON.parse(fs.readFileSync(path.join(root, 'config/qa.json'), 'utf8'));

assert.equal(qa.schema_version, 1);
assert.deepEqual(qa.viewports.map(({ width }) => width), [1440, 1024, 768, 390, 375]);
assert.equal(qa.routes.length, 9);
assert.equal(new Set(qa.routes).size, qa.routes.length);
assert.equal(qa.budgets.horizontal_overflow_px, 0);
assert.ok(qa.budgets.minimum_touch_target_px >= 44);
assert.ok(qa.budgets.maximum_local_image_bytes <= 200000);

console.log('Contrato de QA válido.');
