<?php
/**
 * The wiki's Markdown, rendered to HTML on the server.
 *
 * Everything is escaped first, so a page can only produce the tags made
 * here. Besides ordinary Markdown (headings, lists, tables, code, quotes,
 * links, images) it knows three things of the wiki's own:
 *
 *   [[credit-memos]] or [[credit-memos|Refunds]]  - a link to another wiki page
 *   [Credit Memos](admin:sales_creditmemo)        - a link into this admin
 *   > [!TIP] / [!NOTE] / [!WARNING] on a quote's first line - a callout
 *
 * Admin links are built here with the admin's secret URL key, which a link
 * written by hand could not carry.
 */
class YSRTech_AdminAssistant_Model_Wiki_Markdown
{
    /** @var callable fn(string $urlKey): ?string the page's title, or null when there is none */
    private $_pageTitle;

    /** @var callable fn(string $urlKey): string */
    private $_pageUrl;

    /** @var callable fn(string $route): string */
    private $_adminUrl;

    /** @var array<int, array{level:int, id:string, text:string}> */
    private array $_headings = [];

    public function __construct($args = [])
    {
        $this->_pageTitle = $args['page_title'] ?? static fn (string $k) => null;
        $this->_pageUrl   = $args['page_url'] ?? static fn (string $k) => '#' . $k;
        $this->_adminUrl  = $args['admin_url'] ?? static fn (string $r) => '#';
    }

    /**
     * The headings of the last page rendered, for its table of contents.
     *
     * @return array<int, array{level:int, id:string, text:string}>
     */
    public function headings(): array
    {
        return $this->_headings;
    }

    public function render(string $markdown): string
    {
        $this->_headings = [];
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));

        return $this->_blocks($lines);
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function _blocks(array $lines): string
    {
        $out = [];
        $n   = count($lines);
        $i   = 0;

        while ($i < $n) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $i++;
                continue;
            }

            // Fenced code
            if (preg_match('/^\s*```/', $line)) {
                $code = [];
                $i++;
                while ($i < $n && !preg_match('/^\s*```/', $lines[$i])) {
                    $code[] = $lines[$i];
                    $i++;
                }
                $i++;
                $out[] = '<pre><code>' . self::esc(implode("\n", $code)) . '</code></pre>';
                continue;
            }

            // Heading
            if (preg_match('/^(#{1,4})\s+(.+?)\s*#*\s*$/', $line, $m)) {
                $level = strlen($m[1]);
                $text  = $m[2];
                $id    = $this->_slug($text);
                if ($level >= 2 && $level <= 3) {
                    $this->_headings[] = ['level' => $level, 'id' => $id, 'text' => strip_tags($this->_inline($text))];
                }
                $out[] = '<h' . $level . ' id="' . self::esc($id) . '">' . $this->_inline($text) . '</h' . $level . '>';
                $i++;
                continue;
            }

            // Horizontal rule
            if (preg_match('/^\s*(-{3,}|\*{3,})\s*$/', $line)) {
                $out[] = '<hr />';
                $i++;
                continue;
            }

            // Table: a header row and a separator row
            if (strpos($line, '|') !== false && $i + 1 < $n && preg_match('/^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/', $lines[$i + 1])) {
                $head = $this->_cells($line);
                $i += 2;
                $rows = [];
                while ($i < $n && strpos($lines[$i], '|') !== false && trim($lines[$i]) !== '') {
                    $rows[] = $this->_cells($lines[$i]);
                    $i++;
                }
                $html = '<div class="wk-table"><table><thead><tr>';
                foreach ($head as $c) {
                    $html .= '<th>' . $this->_inline($c) . '</th>';
                }
                $html .= '</tr></thead><tbody>';
                foreach ($rows as $r) {
                    $html .= '<tr>';
                    foreach ($r as $c) {
                        $html .= '<td>' . $this->_inline($c) . '</td>';
                    }
                    $html .= '</tr>';
                }
                $out[] = $html . '</tbody></table></div>';
                continue;
            }

            // Quote, or a callout
            if (preg_match('/^\s*>/', $line)) {
                $quote = [];
                while ($i < $n && preg_match('/^\s*>\s?(.*)$/', $lines[$i], $m)) {
                    $quote[] = $m[1];
                    $i++;
                }
                $kind = '';
                if ($quote && preg_match('/^\[!(NOTE|TIP|WARNING|IMPORTANT)\]\s*$/i', trim($quote[0]), $m)) {
                    $kind = strtolower($m[1]);
                    array_shift($quote);
                }
                $label = ['note' => 'Note', 'tip' => 'Tip', 'warning' => 'Warning', 'important' => 'Important'][$kind] ?? '';
                $out[] = '<blockquote' . ($kind ? ' class="wk-callout wk-' . $kind . '"' : '') . '>'
                    . ($label ? '<p class="wk-callout-title">' . $label . '</p>' : '')
                    . $this->_blocks($quote) . '</blockquote>';
                continue;
            }

            // List (with one level of nesting by indentation)
            if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $line)) {
                [$html, $i] = $this->_list($lines, $i);
                $out[] = $html;
                continue;
            }

            // Paragraph
            $para = [];
            while ($i < $n && trim($lines[$i]) !== '' && !preg_match('/^\s*(```|#{1,4}\s|>|([-*+]|\d+[.)])\s+)/', $lines[$i])
                && !(strpos($lines[$i], '|') !== false && $i + 1 < $n && preg_match('/^\s*\|?\s*:?-{2,}/', $lines[$i + 1]))) {
                $para[] = trim($lines[$i]);
                $i++;
            }
            $out[] = '<p>' . implode('<br />', array_map([$this, '_inline'], $para)) . '</p>';
        }

        return implode("\n", $out);
    }

    /**
     * @return array{0:string, 1:int} The list's HTML and the index after it
     */
    private function _list(array $lines, int $i): array
    {
        $n = count($lines);
        preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $lines[$i], $m);
        $indent  = strlen(str_replace("\t", '    ', $m[1]));
        $ordered = ctype_digit($m[2][0]);
        $items   = [];

        while ($i < $n) {
            $line = $lines[$i];

            if (trim($line) === '') {
                // A blank line ends the list unless the list goes on after it
                if ($i + 1 < $n && preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $lines[$i + 1], $mm) && strlen(str_replace("\t", '    ', $mm[1])) >= $indent) {
                    $i++;
                    continue;
                }
                break;
            }

            if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+(.*)$/', $line, $mm)) {
                $depth = strlen(str_replace("\t", '    ', $mm[1]));
                if ($depth < $indent) {
                    break;
                }
                if ($depth > $indent && $items) {
                    [$sub, $i] = $this->_list($lines, $i);
                    $items[count($items) - 1] .= $sub;
                    continue;
                }
                $items[] = $this->_inline($mm[3]);
                $i++;
                continue;
            }

            // A wrapped line continues the item before it
            if ($items && preg_match('/^\s{2,}\S/', $line)) {
                $items[count($items) - 1] .= ' ' . $this->_inline(trim($line));
                $i++;
                continue;
            }

            break;
        }

        $tag = $ordered ? 'ol' : 'ul';

        return ['<' . $tag . '>' . implode('', array_map(static fn ($it) => '<li>' . $it . '</li>', $items)) . '</' . $tag . '>', $i];
    }

    private function _cells(string $row): array
    {
        $row = trim($row);
        $row = preg_replace('/^\||\|$/', '', $row);

        // A pipe inside `code` (e.g. `[[page|Text]]`) is not a cell border
        $cells = [];
        $cell  = '';
        $code  = false;

        foreach (preg_split('//u', $row, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            if ($ch === '`') {
                $code = !$code;
            }
            if ($ch === '|' && !$code) {
                $cells[] = trim($cell);
                $cell    = '';
                continue;
            }
            $cell .= $ch;
        }
        $cells[] = trim($cell);

        return $cells;
    }

    private function _slug(string $text): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', strip_tags($text)), '-'));
        $base = $slug !== '' ? $slug : 'section';
        $slug = $base;
        $k    = 2;
        $used = array_column($this->_headings, 'id');
        while (in_array($slug, $used, true)) {
            $slug = $base . '-' . $k++;
        }

        return $slug;
    }

    /**
     * Inline formatting on one line of text. The text is escaped first; the
     * patterns below can only add the tags they name.
     */
    public function _inline(string $text): string
    {
        $codes = [];
        $text  = preg_replace_callback('/`([^`]+)`/', static function ($m) use (&$codes) {
            $codes[] = $m[1];

            return "\x01" . (count($codes) - 1) . "\x01";
        }, $text);

        $links = [];
        $keep  = static function (string $html) use (&$links): string {
            $links[] = $html;

            return "\x02" . (count($links) - 1) . "\x02";
        };

        // Wiki links: [[url-key]] or [[url-key|text]]
        $text = preg_replace_callback('/\[\[([a-z0-9][a-z0-9-]*)(?:\|([^\]]+))?\]\]/i', function ($m) use ($keep) {
            $key   = strtolower($m[1]);
            $title = call_user_func($this->_pageTitle, $key);
            $label = isset($m[2]) && $m[2] !== '' ? $m[2] : ($title ?? $key);

            return $keep('<a class="wk-link' . ($title === null ? ' wk-missing' : '') . '" href="' . self::esc(call_user_func($this->_pageUrl, $key)) . '"'
                . ($title === null ? ' title="No page yet"' : '') . '>' . self::esc($label) . '</a>');
        }, $text);

        // Images: ![alt](https://... or /media/...)
        $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', static function ($m) use ($keep) {
            if (!preg_match('#^(https://|/media/|/skin/)#i', $m[2])) {
                return self::esc($m[1]);
            }

            return $keep('<img src="' . self::esc($m[2]) . '" alt="' . self::esc($m[1]) . '" loading="lazy" />');
        }, $text);

        // Links: [text](https://...), [text](admin:route), [text](#anchor)
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) use ($keep) {
            $label = self::esc($m[1]);
            $url   = $m[2];

            if (stripos($url, 'admin:') === 0) {
                $route = trim(substr($url, 6), '/');
                if (!preg_match('#^[a-z0-9_]+(/[a-z0-9_]+){0,2}(/[a-z0-9_]+/[a-z0-9_.-]+)*$#i', $route)) {
                    return $label;
                }

                return $keep('<a class="wk-admin" href="' . self::esc(call_user_func($this->_adminUrl, $route)) . '">' . $label . '</a>');
            }

            if (preg_match('#^https?://#i', $url)) {
                return $keep('<a href="' . self::esc($url) . '" target="_blank" rel="noopener noreferrer nofollow">' . $label . '</a>');
            }

            if (preg_match('/^#[a-z0-9-]+$/i', $url)) {
                return $keep('<a href="' . self::esc($url) . '">' . $label . '</a>');
            }

            return $label; // anything else (javascript:, data:, relative paths) stays text
        }, $text);

        $text = self::esc($text);
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/(^|[\s(])\*([^*\s][^*]*?)\*(?=[\s).,;:!?]|$)/', '$1<em>$2</em>', $text);
        $text = preg_replace('/(^|[\s(])_([^_\s][^_]*?)_(?=[\s).,;:!?]|$)/', '$1<em>$2</em>', $text);
        $text = preg_replace_callback("/\x02(\d+)\x02/", static fn ($m) => $links[(int) $m[1]], $text);

        return preg_replace_callback("/\x01(\d+)\x01/", static fn ($m) => '<code>' . self::esc($codes[(int) $m[1]]) . '</code>', $text);
    }
}
