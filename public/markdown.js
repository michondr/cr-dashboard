// Minimal, self-contained Markdown → HTML renderer for MR descriptions.
//
// Safety contract: the source text is HTML-escaped before any transform runs,
// so the returned markup only ever contains tags generated below (plus the
// user's own text as escaped content). Callers may assign the result via
// innerHTML without re-escaping; nothing from the source can become markup.

const ESCAPE = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
};

function escapeHtml(text) {
    return String(text).replace(/[&<>"']/g, (ch) => ESCAPE[ch]);
}

function unescapeHtml(text) {
    return text
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&quot;/g, '"')
        .replace(/&#39;/g, "'")
        .replace(/&amp;/g, '&');
}

// Only http(s), mailto, and relative/fragment URLs may become anchors; anything
// else (javascript:, data:, ...) is left as literal text.
function safeHref(url) {
    const decoded = unescapeHtml(url.trim());
    if (/^(https?:|mailto:)/i.test(decoded) || decoded.startsWith('/') || decoded.startsWith('#')) {
        return decoded;
    }

    return null;
}

// Inline transforms over a single (raw, unescaped) line. Order matters: code
// spans and links are protected first so emphasis never touches them.
function inline(raw) {
    let out = escapeHtml(raw);

    // Inline code first; its content is already escaped and never re-parsed.
    out = out.replace(/`([^`\n]+)`/g, (_, code) => `<code>${code}</code>`);

    // Images become links (tooltips must not embed remote images), links are
    // validated so only safe schemes survive.
    out = out.replace(/!\[([^\]]*)\]\(([^)\s]+)\)/g, (_, alt, url) => {
        const href = safeHref(url);
        if (!href) {
            return `![${alt}](${url})`;
        }

        return `<a href="${escapeHtml(href)}">${escapeHtml(alt) || escapeHtml(href)}</a>`;
    });
    out = out.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (_, label, url) => {
        const href = safeHref(url);
        if (!href) {
            return `[${label}](${url})`;
        }

        return `<a href="${escapeHtml(href)}">${label}</a>`;
    });

    // Bold, then italic, then strikethrough. Underscores only count as emphasis
    // when they sit on a word boundary, so `snake_case` is left alone.
    out = out.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
    out = out.replace(/(^|[\s([{`>])\*([^*\n]+)\*(?!\*)/g, '$1<em>$2</em>');
    out = out.replace(/(^|[\s([{`>])_([^_\n]+)_/g, '$1<em>$2</em>');
    out = out.replace(/~~([^~\n]+)~~/g, '<del>$1</del>');

    return out;
}

// A run of consecutive (already split) lines rendered as a paragraph; a single
// newline becomes a line break, matching GitLab's description rendering.
function paragraph(lines) {
    return inline(lines.join('\n')).replace(/\n/g, '<br>');
}

function renderBlocks(lines) {
    const html = [];
    let i = 0;

    while (i < lines.length) {
        const line = lines[i];

        // Fenced code block (``` or ```lang ... ```).
        if (/^```/.test(line)) {
            const buffer = [];
            i++;
            while (i < lines.length && !/^```/.test(lines[i])) {
                buffer.push(lines[i]);
                i++;
            }
            i++; // skip the closing fence
            html.push(`<pre><code>${escapeHtml(buffer.join('\n'))}</code></pre>`);
            continue;
        }

        // Horizontal rule: a line of only dashes/asterisks/underscores.
        if (/^\s*(?:-{3,}|\*{3,}|_{3,})\s*$/.test(line)) {
            html.push('<hr>');
            i++;
            continue;
        }

        // Heading: `#`..`###### `.
        const heading = /^(#{1,6})\s+(.*)$/.exec(line);
        if (heading) {
            const level = heading[1].length;
            html.push(`<h${level}>${inline(heading[2])}</h${level}>`);
            i++;
            continue;
        }

        // Blockquote: consecutive `>` lines, paragraph-joined inside.
        if (/^>\s?/.test(line)) {
            const buffer = [];
            while (i < lines.length && /^>\s?/.test(lines[i])) {
                buffer.push(lines[i].replace(/^>\s?/, ''));
                i++;
            }
            html.push(`<blockquote>${paragraph(buffer)}</blockquote>`);
            continue;
        }

        // Unordered list, with GitLab task-list markers (`- [ ]`, `- [x]`).
        if (/^\s*[-*+]\s+/.test(line)) {
            const buffer = [];
            while (i < lines.length && /^\s*[-*+]\s+/.test(lines[i])) {
                buffer.push(lines[i].replace(/^\s*[-*+]\s+/, ''));
                i++;
            }
            const items = buffer.map((item) => {
                const task = /^\[([ xX])\]\s+/.exec(item);
                if (task) {
                    const checked = task[1].toLowerCase() === 'x';
                    const rest = item.replace(/^\[[ xX]\]\s+/, '');
                    const box = `<input type="checkbox" disabled${checked ? ' checked' : ''}>`;

                    return `<li>${box}${inline(rest)}</li>`;
                }

                return `<li>${inline(item)}</li>`;
            }).join('');
            html.push(`<ul>${items}</ul>`);
            continue;
        }

        // Ordered list: `1.`, `1)` etc.
        if (/^\s*\d+[.)]\s+/.test(line)) {
            const buffer = [];
            while (i < lines.length && /^\s*\d+[.)]\s+/.test(lines[i])) {
                buffer.push(lines[i].replace(/^\s*\d+[.)]\s+/, ''));
                i++;
            }
            const items = buffer.map((item) => `<li>${inline(item)}</li>`).join('');
            html.push(`<ol>${items}</ol>`);
            continue;
        }

        // Paragraph: everything until the next blank line.
        const buffer = [];
        while (i < lines.length && lines[i].trim() !== '') {
            buffer.push(lines[i]);
            i++;
        }
        while (i < lines.length && lines[i].trim() === '') {
            i++;
        }
        html.push(`<p>${paragraph(buffer)}</p>`);
    }

    return html.join('\n');
}

export function renderMarkdown(source) {
    if (!source) {
        return '';
    }

    return renderBlocks(String(source).replace(/\r\n/g, '\n').split('\n'));
}
