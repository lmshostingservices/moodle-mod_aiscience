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
 * Automated checks every generated experiment must pass before a teacher can publish it.
 *
 * @package    mod_aiscience
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class checks {
    /** @var int Largest experiment accepted, in bytes. */
    const MAXBYTES = 400000;

    /**
     * Run the static checks.
     *
     * @param string $html
     * @return array list of ['key','label','pass','detail']
     */
    public static function run(string $html): array {
        $has = fn(string $re) => (bool)preg_match($re, $html);
        $sliders = preg_match_all('~type\s*=\s*["\']?range~i', $html);
        preg_match_all('~data-stage\s*=\s*["\']?(\d)~i', $html, $m);
        $stages = count(array_unique($m[1] ?? []));
        $network = $has('~\b(?:src|href)\s*=\s*["\']\s*(?:https?:)?//~i')
            || $has('~\bfetch\s*\(|XMLHttpRequest|WebSocket|sendBeacon|\bimport\s*\(|EventSource~')
            || $has('~\blocation(?:\.href)?\s*=(?!=)|\blocation\.(?:assign|replace)\s*\(|\bwindow\.open\s*\(~');
        $list = [
            ['network', 'Self-contained, no network calls', !$network, $network ? 'Found an outside link or network call' : ''],
            ['stages', 'All six stages present', $stages >= 6, "$stages found"],
            ['sliders', 'Students control the variables with sliders', $sliders >= 1, "$sliders found"],
            ['drag', 'Students can drag the apparatus', $has('~pointerdown|pointermove~'), ''],
            ['scene', 'Animated scene and live chart', $has('~<svg|<canvas~i') && $has('~requestAnimationFrame~'), ''],
            ['autoplay', 'Autoplay with pause, stamped for notes', $has('~LabKit\.time~') && $has('~LabKit\.onControl~'), ''],
            ['learning', 'Prediction, findings table and challenge for learning',
                $has('~predict~i') && $has('~<table|findings~i') && $has('~challenge~i'), ''],
            ['voice', 'Voiceover reads instructions aloud', $has('~speechSynthesis|LabKit\.speak~'), ''],
            ['dictate', 'Students can speak their written answers', $has('~LabKit\.dictate~'), ''],
            ['submit', 'Reports results to the Moodle gradebook', $has('~LabKit\.submit~'), ''],
            ['marking', 'Marking guide for auto-marking', ($g = guide::from_html($html)) !== null && count($g['questions']) >= 3,
                $g ? count($g['questions']) . ' questions' : 'No marking guide found'],
            ['size', 'Small enough to load fast', strlen($html) <= self::MAXBYTES, round(strlen($html) / 1024) . ' KB'],
        ];
        return array_map(fn($c) => ['key' => $c[0], 'label' => $c[1], 'pass' => (bool)$c[2], 'detail' => $c[3]], $list);
    }

    /**
     * Whether every check passed.
     *
     * @param array $results
     * @return bool
     */
    public static function all_pass(array $results): bool {
        foreach ($results as $r) {
            if (empty($r['pass'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Failure list for the AI repair prompt.
     *
     * @param array $results
     * @return string
     */
    public static function failures(array $results): string {
        $out = [];
        foreach ($results as $r) {
            if (empty($r['pass'])) {
                $out[] = '- ' . $r['label'] . ($r['detail'] ? ' (' . $r['detail'] . ')' : '');
            }
        }
        return implode("\n", $out);
    }
}
