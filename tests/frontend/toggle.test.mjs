import assert from 'node:assert/strict';
import test from 'node:test';

import {
    MODE_MEAN,
    MODE_MEDIAN,
    cookieForMode,
    getMode,
    modeFromCookie,
    setMode,
    toggleMode,
} from '../../public/toggle.js';

test('modeFromCookie defaults to mean when the cookie is absent', () => {
    assert.equal(modeFromCookie(''), MODE_MEAN);
    assert.equal(modeFromCookie('foo=bar; baz=qux'), MODE_MEAN);
});

test('modeFromCookie reads the median cookie', () => {
    assert.equal(modeFromCookie('cr-mm=median; foo=bar'), MODE_MEDIAN);
});

test('modeFromCookie ignores other cookies that contain the name as a substring', () => {
    assert.equal(modeFromCookie('xcr-mm=median'), MODE_MEAN);
});

test('cookieForMode writes a persisted, same-site cookie', () => {
    const cookie = cookieForMode(MODE_MEDIAN);
    assert.ok(cookie.startsWith('cr-mm=median;'));
    assert.ok(cookie.includes('max-age=31536000'));
    assert.ok(cookie.includes('samesite=lax'));
});

test('getMode, setMode and toggleMode round-trip through document.cookie', () => {
    const originalDocument = globalThis.document;
    globalThis.document = { cookie: '' };
    try {
        assert.equal(getMode(), MODE_MEAN);
        setMode(MODE_MEDIAN);
        assert.equal(getMode(), MODE_MEDIAN);
        assert.equal(toggleMode(), MODE_MEAN);
        assert.equal(getMode(), MODE_MEAN);
    } finally {
        globalThis.document = originalDocument;
    }
});
