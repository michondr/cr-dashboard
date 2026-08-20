import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const publicDir = path.resolve(here, '../../public');
const fixturePath = path.join(here, 'fixture-data.json');
const fixture = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));

const port = Number(process.env.PORT) || 8790;

const MIME = {
    '.html': 'text/html; charset=utf-8',
    '.js': 'text/javascript; charset=utf-8',
    '.css': 'text/css; charset=utf-8',
    '.json': 'application/json; charset=utf-8',
};

const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');

    if (url.pathname === '/api/data') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify(fixture));

        return;
    }

    // Single-MR payload, mirroring GET /api/mr/{id}: the row shape from the
    // fixture's mrs list, 404 with mr:null when unknown.
    const mrMatch = url.pathname.match(/^\/api\/mr\/(\d+)$/);
    if (mrMatch && req.method === 'GET') {
        const mr = fixture.mrs.find((candidate) => String(candidate.id) === mrMatch[1]) || null;
        res.writeHead(mr === null ? 404 : 200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ mr }));

        return;
    }

    if (url.pathname === '/api/refresh' && req.method === 'POST') {
        res.writeHead(202, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ accepted: true, reason: 'queued', cooldown_remaining: 0 }));

        return;
    }

    if (url.pathname === '/api/refresh' && req.method === 'GET') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ active: false, total: 0, done: 0 }));

        return;
    }

    if (url.pathname === '/api/presence') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ online: global.__presenceOnline ?? 3 }));

        return;
    }

    if (url.pathname === '/.well-known/mercure') {
        // A minimal SSE stub standing in for the Mercure hub: keeps the
        // connection open and, if a test primed `global.__ssePending`
        // (see /__test/sse below), replays those events once subscribed.
        res.writeHead(200, {
            'Content-Type': 'text/event-stream',
            'Cache-Control': 'no-cache',
            Connection: 'keep-alive',
        });
        res.write(':ok\n\n');
        for (const payload of global.__ssePending || []) {
            res.write(`data: ${JSON.stringify(payload)}\n\n`);
        }
        global.__ssePending = [];
        global.__sseRes = res;
        req.on('close', () => res.end());

        return;
    }

    // Test-only helper: POST an MR object here to add it to (or replace it
    // in) the fixture's /api/data response, so a `changed` SSE event fired
    // afterwards has something new to refetch (see follow-up F2's splice
    // test).
    if (url.pathname === '/__test/mr' && req.method === 'POST') {
        let body = '';
        req.on('data', (chunk) => {
            body += chunk;
        });
        req.on('end', () => {
            const mr = JSON.parse(body);
            fixture.mrs = [...fixture.mrs.filter((existing) => existing.id !== mr.id), mr];
            res.writeHead(204);
            res.end();
        });

        return;
    }

    // Test-only helper: POST a JSON payload here and it is immediately
    // forwarded as a Mercure SSE event to any connected EventSource (or
    // queued for the next one to connect).
    if (url.pathname === '/__test/sse' && req.method === 'POST') {
        let body = '';
        req.on('data', (chunk) => {
            body += chunk;
        });
        req.on('end', () => {
            const payload = JSON.parse(body);
            if (global.__sseRes) {
                global.__sseRes.write(`data: ${JSON.stringify(payload)}\n\n`);
            } else {
                global.__ssePending = [...(global.__ssePending || []), payload];
            }
            res.writeHead(204);
            res.end();
        });

        return;
    }

    const relative = url.pathname === '/' ? 'index.html' : url.pathname.replace(/^\/+/, '');
    const filePath = path.resolve(publicDir, relative);
    if (!filePath.startsWith(publicDir)) {
        res.writeHead(403);
        res.end('Forbidden');

        return;
    }

    fs.readFile(filePath, (err, data) => {
        if (err) {
            res.writeHead(404);
            res.end('Not found');

            return;
        }
        const ext = path.extname(filePath);
        res.writeHead(200, { 'Content-Type': MIME[ext] || 'application/octet-stream' });
        res.end(data);
    });
});

server.listen(port, () => {
    console.log(`Fixture server listening on http://127.0.0.1:${port}`);
});
