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
