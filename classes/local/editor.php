<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_aiscience\local;

/**
 * Teacher editing without AI: find the wording students read and the slider settings in any
 * experiment page, wherever they sit (page text, script strings, HTML built inside scripts),
 * and write changes back by exact position so nothing else in the page moves.
 *
 * @package    mod_aiscience
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class editor {
    /** Most phrases offered for editing. */
    const MAXTEXTS = 800;

    /** A JavaScript template-literal quote. */
    const BACKTICK = "\x60";

    /**
     * Everything a teacher can edit in a page.
     *
     * @param string $html
     * @return array ['texts' => [...], 'numbers' => [...]]
     */
    public static function extract(string $html): array {
        $texts = [];
        $steps = self::step_map($html);
        $names = self::step_names($html);
        foreach (self::scan_texts($html) as $i => $t) {
            $step = self::step_at($steps, $t['start']);
            $texts[] = ['id' => 't' . $i, 'text' => $t['text'], 'where' => $t['where'], 'step' => $step,
                'stepname' => $step ? 'Step ' . $step . ' · ' . ($names[$step - 1] ?? 'Step ' . $step) : 'Throughout'];
        }
        $numbers = [];
        foreach (self::scan_numbers($html) as $i => $n) {
            $numbers[] = ['id' => 'n' . $i, 'label' => $n['label'], 'unit' => $n['unit'], 'min' => $n['vals']['min'] ?? null,
                'max' => $n['vals']['max'] ?? null, 'step' => $n['vals']['step'] ?? null, 'start' => $n['vals']['start'] ?? null];
        }
        return ['texts' => $texts, 'numbers' => $numbers];
    }

    /**
     * Apply teacher edits.
     *
     * @param string $html
     * @param array $texts id => new text
     * @param array $numbers id => ['min' =>, 'max' =>, 'step' =>, 'start' =>]
     * @return array [string $html, int $changedtexts, int $changednumbers]
     */
    public static function apply(string $html, array $texts, array $numbers): array {
        $patches = [];
        $ct = 0;
        $cn = 0;
        foreach (self::scan_texts($html) as $i => $t) {
            if (!array_key_exists('t' . $i, $texts)) {
                continue;
            }
            $new = self::plain((string)$texts['t' . $i], 2000);
            if ($new === $t['text']) {
                continue;
            }
            if ($new === '') {
                throw self::problem('A phrase cannot be empty. Change it to a few words instead.');
            }
            $patches[] = [$t['start'], $t['len'], self::encode($new, $t['ctx'], $t['q'])];
            $ct++;
        }
        foreach (self::scan_numbers($html) as $i => $n) {
            $want = $numbers['n' . $i] ?? null;
            if (!is_array($want)) {
                continue;
            }
            $vals = $n['vals'];
            foreach ($vals as $k => $v) {
                if (isset($want[$k]) && $want[$k] !== '' && is_numeric($want[$k])) {
                    $vals[$k] = (float)$want[$k];
                }
            }
            $min = $vals['min'] ?? null;
            $max = $vals['max'] ?? null;
            if ($min !== null && $max !== null && $min >= $max) {
                throw self::problem('"' . $n['label'] . '": the lowest value must be below the highest.');
            }
            if (isset($vals['step']) && $vals['step'] <= 0) {
                throw self::problem('"' . $n['label'] . '": the step must be more than zero.');
            }
            if (isset($vals['start']) && $min !== null && $max !== null) {
                $vals['start'] = max($min, min($max, $vals['start']));
            }
            $changed = false;
            foreach ($n['pos'] as $k => [$start, $len]) {
                $out = self::num($vals[$k]);
                if ((float)substr($html, $start, $len) != $vals[$k]) {
                    $patches[] = [$start, $len, $out];
                    $changed = true;
                }
            }
            $cn += $changed ? 1 : 0;
        }
        usort($patches, fn($a, $b) => $b[0] <=> $a[0]);
        foreach ($patches as [$start, $len, $out]) {
            $html = substr_replace($html, $out, $start, $len);
        }
        return [$html, $ct, $cn];
    }

    /**
     * An error whose message the teacher or student actually sees.
     *
     * @param string $message
     * @return \moodle_exception
     */
    public static function problem(string $message): \moodle_exception {
        return new \moodle_exception('generalexceptionmessage', 'error', '', $message);
    }

    /**
     * Plain text a person typed: no control characters, trimmed, limited length.
     *
     * @param string $text
     * @param int $max
     * @return string
     */
    public static function plain(string $text, int $max): string {
        $text = preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~u', '', $text) ?? '';
        $text = str_replace("\r\n", "\n", $text);
        return \core_text::substr(trim($text), 0, $max);
    }

    /**
     * The step names students see, from the page's step list.
     *
     * @param string $html
     * @return array
     */
    protected static function step_names(string $html): array {
        if (preg_match('~STEPS\s*=\s*\[([^\]]{5,400})\]~', $html, $m) && preg_match_all('~(["\'])(.*?)\1~', $m[1], $n)) {
            return array_slice($n[2], 0, 12);
        }
        return ['Get started', 'Predict', 'Set up', 'Collect', 'Analyze', 'Report'];
    }

    /**
     * Landmarks that tell which step the text after them belongs to: sections marked data-stage, the
     * experiment's settings (predictions, checkpoints, challenge, report questions) and the functions
     * that draw each step. Sorted by position.
     *
     * @param string $html
     * @return array list of [position, step]
     */
    protected static function step_map(string $html): array {
        $marks = [];
        preg_match_all('~data-stage\s*=\s*["\']?(\d)~i', $html, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[1] as [$n, $off]) {
            $marks[] = [$off, (int)$n];
        }
        $landmarks = [
            '~\b(?:predictions|PRED)\s*[:=]\s*\[|function\s+renderChoices\b~' => 2,
            '~\b(?:checkpoints|CHECKPOINTS)\s*[:=]\s*\[|function\s+(?:renderFindings|showCheckpoint|startAuto)\b~' => 4,
            '~\bchallenge\s*:\s*\{|function\s+(?:renderAnalyze|renderPredCheck|updCh|updChallenge)\b~' => 5,
            '~\b(?:cer|PROMPTS)\s*[:=]\s*\[|function\s+(?:renderPrompt|reportChecks|showCheck|reportText|finish|showFinal)\b~' => 6,
            '~\b(?:intro|title|question)\s*:\s*["\']~' => 1,
        ];
        foreach ($landmarks as $re => $step) {
            preg_match_all($re, $html, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[0] as [, $off]) {
                $marks[] = [$off, $step];
            }
        }
        // Text after the page template that is not in a step function belongs to no single step.
        preg_match_all(
            '~function\s+(?:html|go|bindSpeak|readEls|readStage|pickVoice|stopVO|persist)\b~',
            $html,
            $m,
            PREG_OFFSET_CAPTURE
        );
        foreach ($m[0] as [, $off]) {
            $marks[] = [$off, 0];
        }
        usort($marks, fn($a, $b) => $a[0] <=> $b[0]);
        return $marks;
    }

    /**
     * The step for a position.
     *
     * @param array $marks
     * @param int $pos
     * @return int 0 when not tied to one step
     */
    protected static function step_at(array $marks, int $pos): int {
        $step = 0;
        foreach ($marks as [$off, $n]) {
            if ($off > $pos) {
                break;
            }
            $step = $n;
        }
        return $step;
    }

    /**
     * Number without trailing zeros.
     *
     * @param float $v
     * @return string
     */
    protected static function num(float $v): string {
        $s = rtrim(rtrim(sprintf('%.6F', $v), '0'), '.');
        return $s === '-0' ? '0' : $s;
    }

    /**
     * Encode a phrase for where it lives.
     *
     * @param string $text
     * @param string $ctx html, js or jshtml
     * @param string $q quote character for js
     * @return string
     */
    protected static function encode(string $text, string $ctx, string $q): string {
        if ($ctx !== 'js') {
            $text = htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        if ($ctx === 'html') {
            return $text;
        }
        $text = str_replace(['\\', "\n", $q], ['\\\\', '\\n', '\\' . $q], $text);
        if ($q === self::BACKTICK) {
            $text = str_replace('${', '\\${', $text);
        }
        if ($ctx === 'js') {
            // Keep script strings free of markup so they stay plain text on the next edit.
            $text = str_replace(['<', '>'], ['\\u003c', '\\u003e'], $text);
        }
        return str_ireplace('</script', '<\\/script', $text);
    }

    /**
     * Decode a JS string body for display.
     *
     * @param string $s
     * @return string
     */
    protected static function unjs(string $s): string {
        return preg_replace_callback('~\\\\(u\{[0-9a-fA-F]+\}|u[0-9a-fA-F]{4}|x[0-9a-fA-F]{2}|.)~s', function ($m) {
            $c = $m[1];
            if ($c[0] === 'u' && strlen($c) > 1) {
                $hex = trim(substr($c, 1), '{}');
                return \core_text::code2utf8(hexdec($hex));
            }
            if ($c[0] === 'x' && strlen($c) === 3) {
                return \core_text::code2utf8(hexdec(substr($c, 1)));
            }
            return ['n' => "\n", 't' => "\t", 'r' => '', '0' => ''][$c] ?? $c;
        }, $s);
    }

    /**
     * Whether a phrase is something a student reads rather than code.
     *
     * @param string $t
     * @param string $ctx
     * @return bool
     */
    protected static function readable(string $t, string $ctx): bool {
        $t = trim($t);
        if ($t === '' || \core_text::strlen($t) > 2000 || !preg_match('~\p{L}~u', $t)) {
            return false;
        }
        if (
            preg_match('~=>|===|!==|\{|\}|\$\{|&&|\|\||;\s*$|^\s*[.#\[]|\bfunction\b|\bvar\(|rgba?\(|\d(px|rem|em|vh|vw|ms)\b|'
                . '^[a-z]+:[a-z]|\.(js|css|png|svg|html)\b|^\w+\(|\bdocument\.|\bwindow\.|\breturn\b~', $t)
        ) {
            return false;
        }
        $words = preg_match_all('~\p{L}[\p{L}\'’]+~u', $t);
        $single = !preg_match('~\s~u', $t);
        if ($single && preg_match('~[-_]|[a-z][A-Z]|\d~', $t)) {
            return false; // Identifier, class name or id.
        }
        $codeish = '%="|=\s*$|^\s*"\s|\[[\w-]+[\]=]|\b(?:data|aria)-[a-z]|^[\w-]+\s*[>+~]\s*[\w.]|\s[>+~]\s[\w.#]'
            . '|^\(\s*(?:prefers-|min-|max-|orientation|hover|pointer)%';
        if (preg_match($codeish, $t)) {
            return false; // Attribute fragments and CSS selectors.
        }
        if ($ctx === 'js') {
            // Plain script strings: sentence-like only, so class lists and event names stay out.
            return $words >= 2 && (preg_match('~[A-Z]~', $t) || preg_match('~[.?!:…]~u', $t) || $words >= 3)
                && !preg_match('~^[a-z\-\s]+$~', $t);
        }
        return $words >= 1 && \core_text::strlen($t) >= 2;
    }

    /**
     * Find every editable phrase with its exact position.
     *
     * @param string $html
     * @return array list of ['start','len','text','ctx','q','where']
     */
    protected static function scan_texts(string $html): array {
        $out = [];
        $add = function (int $start, string $raw, string $ctx, string $q) use (&$out) {
            // Keep surrounding spaces where they are; edit only the words.
            if (!preg_match('~^(\s*)(.*?)(\s*)$~s', $raw, $m) || $m[2] === '') {
                return;
            }
            $core = $m[2];
            $text = $ctx === 'js' ? self::unjs($core) : ($ctx === 'jshtml' ? html_entity_decode(
                self::unjs($core),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            ) : html_entity_decode($core, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $text = trim(preg_replace('~[ \t\r\n]+~u', ' ', $text));
            if (!self::readable($text, $ctx === 'jshtml' ? 'html' : $ctx)) {
                return;
            }
            // Collapsed whitespace would be lost on save, so skip multi-line source text.
            if (preg_match('~\n~', $core) && $ctx === 'html') {
                $text = trim(preg_replace('~\s+~u', ' ', $text));
            }
            $out[] = ['start' => $start + strlen($m[1]), 'len' => strlen($core), 'text' => $text, 'ctx' => $ctx, 'q' => $q,
                'where' => $ctx === 'html' ? 'page' : 'script'];
        };
        // Split into raw-text blocks (script, style, comments) and markup.
        preg_match_all(
            '~<script\b([^>]*)>(.*?)</script\s*>|<style\b[^>]*>.*?</style\s*>|<!--.*?-->|<title\b[^>]*>.*?</title>~is',
            $html,
            $blocks,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );
        $cursor = 0;
        $markup = function (int $from, int $to) use ($html, $add) {
            if ($to <= $from) {
                return;
            }
            $part = substr($html, $from, $to - $from);
            preg_match_all('~(?<=>)([^<>]+)(?=<)~', $part, $mm, PREG_OFFSET_CAPTURE);
            foreach ($mm[1] as [$raw, $off]) {
                $add($from + $off, $raw, 'html', '');
            }
        };
        foreach ($blocks as $b) {
            $markup($cursor, $b[0][1]);
            $cursor = $b[0][1] + strlen($b[0][0]);
            if (isset($b[2]) && $b[2][1] >= 0 && !preg_match('~type\s*=\s*["\']?(?!text/javascript|module)[a-z]+/~i', $b[1][0])) {
                foreach (self::js_strings($b[2][0]) as [$off, $len, $q]) {
                    $body = substr($b[2][0], $off, $len);
                    $abs = $b[2][1] + $off;
                    if (strpbrk($body, '<>') !== false) {
                        // HTML built inside a script string: offer only the text between its tags. A template
                        // chunk can start or end in the middle of a tag, so those edges are not text.
                        $pieces = preg_split('~<[^<>]*>|^[^<]*>|<[^>]*$~', $body, -1, PREG_SPLIT_OFFSET_CAPTURE);
                        foreach ($pieces as [$raw, $poff]) {
                            $add($abs + $poff, $raw, 'jshtml', $q);
                        }
                    } else {
                        $add($abs, $body, 'js', $q);
                    }
                }
            }
        }
        $markup($cursor, strlen($html));
        usort($out, fn($a, $b) => $a['start'] <=> $b['start']);
        return array_slice($out, 0, self::MAXTEXTS);
    }

    /**
     * String literal bodies in a script: [offset, length, quote]. Template literals are split at ${...}.
     *
     * @param string $js
     * @return array
     */
    protected static function js_strings(string $js): array {
        $out = [];
        $n = strlen($js);
        $i = 0;
        $prev = '';
        $depth = [];
        while ($i < $n) {
            $c = $js[$i];
            if ($c === '/' && $i + 1 < $n && $js[$i + 1] === '/') {
                $e = strpos($js, "\n", $i);
                $i = $e === false ? $n : $e;
                continue;
            }
            if ($c === '/' && $i + 1 < $n && $js[$i + 1] === '*') {
                $e = strpos($js, '*/', $i + 2);
                $i = $e === false ? $n : $e + 2;
                continue;
            }
            if ($c === '/' && ($prev === '' || strpos('(,=:[!&|?{};+-*%<>~^', $prev) !== false)) {
                // Regular expression literal.
                $i++;
                $class = false;
                while ($i < $n && ($class || $js[$i] !== '/') && $js[$i] !== "\n") {
                    if ($js[$i] === '\\') {
                        $i++;
                    } else if ($js[$i] === '[') {
                        $class = true;
                    } else if ($js[$i] === ']') {
                        $class = false;
                    }
                    $i++;
                }
                $i++;
                $prev = ')';
                continue;
            }
            if ($c === '}' && $depth && end($depth) === 0) {
                // End of a ${...} inside a template literal: continue the template.
                array_pop($depth);
                $i = self::template($js, $i + 1, $out, $depth);
                $prev = ')';
                continue;
            }
            if ($c === '{' && $depth) {
                $depth[count($depth) - 1]++;
            } else if ($c === '}' && $depth) {
                $depth[count($depth) - 1]--;
            }
            if ($c === '"' || $c === "'") {
                $s = $i + 1;
                $i++;
                while ($i < $n && $js[$i] !== $c && $js[$i] !== "\n") {
                    $i += $js[$i] === '\\' ? 2 : 1;
                }
                $out[] = [$s, $i - $s, $c];
                $i++;
                $prev = ')';
                continue;
            }
            if ($c === self::BACKTICK) {
                $i = self::template($js, $i + 1, $out, $depth);
                $prev = ')';
                continue;
            }
            if (!ctype_space($c)) {
                $prev = $c;
                if (ctype_alnum($c) || $c === '_' || $c === '$') {
                    $prev = 'a';
                }
            }
            $i++;
        }
        return $out;
    }

    /**
     * Read a template literal chunk from $i; record its body; stop at ` or ${.
     *
     * @param string $js
     * @param int $i
     * @param array $out
     * @param array $depth
     * @return int next position
     */
    protected static function template(string $js, int $i, array &$out, array &$depth): int {
        $n = strlen($js);
        $s = $i;
        while ($i < $n) {
            if ($js[$i] === '\\') {
                $i += 2;
                continue;
            }
            if ($js[$i] === self::BACKTICK) {
                $out[] = [$s, $i - $s, self::BACKTICK];
                return $i + 1;
            }
            if ($js[$i] === '$' && $i + 1 < $n && $js[$i + 1] === '{') {
                $out[] = [$s, $i - $s, self::BACKTICK];
                $depth[] = 0;
                return $i + 2;
            }
            $i++;
        }
        return $n;
    }

    /**
     * Slider settings: range inputs with fixed numbers, and script settings objects
     * such as {name:"String length", unit:"cm", min:10, max:100, step:1, default:40}.
     *
     * @param string $html
     * @return array list of ['label','unit','vals' => [min,max,step,start], 'pos' => [key => [start,len]]]
     */
    protected static function scan_numbers(string $html): array {
        $out = [];
        $numre = '-?\d+(?:\.\d+)?';
        preg_match_all('~<input\b[^>]*\btype\s*=\s*["\']?range\b[^>]*>~i', $html, $tags, PREG_OFFSET_CAPTURE);
        foreach ($tags[0] as [$tag, $off]) {
            $vals = [];
            $pos = [];
            foreach (['min' => 'min', 'max' => 'max', 'step' => 'step', 'value' => 'start'] as $attr => $key) {
                if (preg_match('~\s' . $attr . '\s*=\s*(["\']?)(' . $numre . ')\1(?=[\s>/])~i', $tag, $m, PREG_OFFSET_CAPTURE)) {
                    $vals[$key] = (float)$m[2][0];
                    $pos[$key] = [$off + $m[2][1], strlen($m[2][0])];
                }
            }
            if (!isset($vals['min'], $vals['max']) || $vals['min'] >= $vals['max']) {
                continue; // Templated or a playback scrubber.
            }
            $id = preg_match('~\sid\s*=\s*["\']?([\w-]+)~', $tag, $m) ? $m[1] : '';
            $label = preg_match('~aria-label\s*=\s*"([^"]+)"~i', $tag, $m) ? $m[1] : '';
            if (
                $label === '' && $id !== '' && preg_match('~<label\b[^>]*\bfor\s*=\s*["\']?' . preg_quote($id, '~')
                    . '\b[^>]*>(.*?)</label>~is', $html, $m)
            ) {
                $label = trim(strip_tags($m[1]));
            }
            $label = trim(preg_replace('~\s*-?\d+(?:\.\d+)?\s*\S{0,3}$~u', '', html_entity_decode($label, ENT_QUOTES, 'UTF-8')));
            $out[] = ['label' => $label ?: ('Slider ' . $id), 'unit' => '',
                'vals' => $vals, 'pos' => $pos];
        }
        // Settings objects in scripts (no nested braces).
        preg_match_all(
            '~\{[^{}]*\bmin\s*:\s*' . $numre . '[^{}]*\bmax\s*:\s*' . $numre . '[^{}]*\}~',
            $html,
            $objs,
            PREG_OFFSET_CAPTURE
        );
        foreach ($objs[0] as [$obj, $off]) {
            if (!preg_match('~\b(?:name|label|title)\s*:\s*(["\'])(.+?)\1~', $obj, $lm)) {
                continue;
            }
            $vals = [];
            $pos = [];
            foreach (
                ['min' => 'min', 'max' => 'max', 'step' => 'step', 'default' => 'start', 'value' => 'start',
                    'start' => 'start'] as $k => $key
            ) {
                if (
                    !isset($vals[$key]) && preg_match(
                        '~(?<![\w.])' . $k . '\s*:\s*(' . $numre . ')(?=\s*[,}])~',
                        $obj,
                        $m,
                        PREG_OFFSET_CAPTURE
                    )
                ) {
                    $vals[$key] = (float)$m[1][0];
                    $pos[$key] = [$off + $m[1][1], strlen($m[1][0])];
                }
            }
            if ($vals['min'] >= $vals['max']) {
                continue;
            }
            $unit = preg_match('~\bunit\s*:\s*(["\'])(.*?)\1~', $obj, $um) ? $um[2] : '';
            $out[] = ['label' => $lm[2], 'unit' => $unit, 'vals' => $vals, 'pos' => $pos];
        }
        usort($out, fn($a, $b) => reset($a['pos'])[0] <=> reset($b['pos'])[0]);
        return $out;
    }
}
