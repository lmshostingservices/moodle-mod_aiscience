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
 * Marking guide carried inside an experiment page as <script type="application/json" id="labkit-marking">:
 * for each report question, its part of Claim-Evidence-Reasoning, a model answer, key ideas to look for
 * (synonyms separated by |), misconceptions to watch for and how many of the student's own numbers to use.
 *
 * @package    mod_aiscience
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guide {
    /**
     * Read and clean the guide from a page, or null when the page has none.
     *
     * @param string $html
     * @return array|null
     */
    public static function from_html(string $html): ?array {
        if (!preg_match('~<script[^>]+id\s*=\s*["\']labkit-marking["\'][^>]*>(.*?)</script>~is', $html, $m)) {
            return null;
        }
        $g = json_decode(str_replace('<\\/', '</', $m[1]), true);
        if (!is_array($g) || empty($g['questions']) || !is_array($g['questions'])) {
            return null;
        }
        $list = fn($a, $n, $len) => array_values(array_slice(array_filter(array_map(
            fn($x) => is_scalar($x) ? editor::plain((string)$x, $len) : '',
            is_array($a) ? $a : []
        )), 0, $n));
        $qs = [];
        foreach ($g['questions'] as $q) {
            if (!is_array($q) || empty($q['k']) || !is_scalar($q['k'])) {
                continue;
            }
            $part = $q['part'] ?? 'other';
            $qs[] = [
                'k' => substr(clean_param((string)$q['k'], PARAM_ALPHANUMEXT), 0, 64),
                'q' => editor::plain((string)($q['q'] ?? ''), 500),
                'part' => in_array($part, ['claim', 'evidence', 'reasoning'], true) ? $part : 'other',
                'model' => editor::plain((string)($q['model'] ?? ''), 1000),
                'ideas' => $list($q['ideas'] ?? [], 8, 400),
                'wrong' => $list($q['wrong'] ?? [], 12, 100),
                'numbers' => max(0, min(5, (int)($q['numbers'] ?? 0))),
                'match' => $list($q['match'] ?? [], 6, 40),
                'because' => !empty($q['because']),
            ];
            if (count($qs) >= 30) {
                break;
            }
        }
        if (!$qs) {
            return null;
        }
        return ['iv' => editor::plain((string)($g['iv'] ?? ''), 80), 'dv' => editor::plain((string)($g['dv'] ?? ''), 80),
            'unit' => editor::plain((string)($g['unit'] ?? ''), 20), 'idea' => editor::plain((string)($g['idea'] ?? ''), 200),
            'questions' => $qs];
    }
}
