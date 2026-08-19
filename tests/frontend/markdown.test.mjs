import assert from 'node:assert/strict';
import test from 'node:test';

import { renderMarkdown } from '../../public/markdown.js';

test('returns an empty string for empty input', () => {
    assert.equal(renderMarkdown(''), '');
    assert.equal(renderMarkdown(null), '');
});

test('escapes HTML so stored GitLab content can never become markup', () => {
    const html = renderMarkdown('<script>alert(1)</script> & <b>x</b>');
    assert.equal(html, '<p>&lt;script&gt;alert(1)&lt;/script&gt; &amp; &lt;b&gt;x&lt;/b&gt;</p>');
});

test('renders headings, paragraphs and GitLab-style line breaks', () => {
    const html = renderMarkdown('## Title\n\nfirst line\nsecond line');
    assert.ok(html.includes('<h2>Title</h2>'));
    assert.ok(html.includes('<p>first line<br>second line</p>'));
});

test('renders bold, italic and strikethrough', () => {
    const html = renderMarkdown('**bold**, *italic*, _alt italic_, ~~gone~~');
    assert.ok(html.includes('<strong>bold</strong>'));
    assert.ok(html.includes('<em>italic</em>'));
    assert.ok(html.includes('<em>alt italic</em>'));
    assert.ok(html.includes('<del>gone</del>'));
});

test('leaves snake_case untouched', () => {
    const html = renderMarkdown('use snake_case_here');
    assert.ok(!html.includes('<em>'));
});

test('renders links and images as safe anchors with re-escaped hrefs', () => {
    const html = renderMarkdown('[site](https://example.test/a?b=1&c=2) ![pic](https://example.test/p.png)');
    assert.ok(html.includes('<a href="https://example.test/a?b=1&amp;c=2">site</a>'));
    assert.ok(html.includes('<a href="https://example.test/p.png">pic</a>'));
});

test('refuses javascript: and data: links', () => {
    const js = renderMarkdown('[x](javascript:alert(1))');
    const data = renderMarkdown('[x](data:text/html,hi)');
    assert.ok(!js.includes('<a '));
    assert.ok(!data.includes('<a '));
});

test('renders fenced code blocks with escaped content', () => {
    const html = renderMarkdown('```php\n$foo = "<bar>";\n```');
    assert.ok(html.includes('<pre><code>$foo = &quot;&lt;bar&gt;&quot;;</code></pre>'));
});

test('renders inline code', () => {
    const html = renderMarkdown('use `state` param');
    assert.ok(html.includes('<code>state</code>'));
});

test('renders unordered lists and task lists', () => {
    const html = renderMarkdown('- a\n- [ ] todo\n- [x] done');
    assert.ok(html.startsWith('<ul>'));
    assert.ok(html.includes('<li>a</li>'));
    assert.ok(html.includes('<input type="checkbox" disabled>'));
    assert.ok(html.includes('<input type="checkbox" disabled checked>'));
});

test('renders ordered lists', () => {
    const html = renderMarkdown('1. one\n2. two');
    assert.ok(html.includes('<ol><li>one</li><li>two</li></ol>'));
});

test('renders blockquotes and horizontal rules', () => {
    const html = renderMarkdown('> quoted text\n\n---');
    assert.ok(html.includes('<blockquote>quoted text</blockquote>'));
    assert.ok(html.includes('<hr>'));
});
