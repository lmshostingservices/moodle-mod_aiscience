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
 * Ready-made, science-checked experiments shipped with the plugin.
 *
 * Each lives in library/<key>.html with its plan and brief in library/index.json.
 *
 * @package    mod_aiscience
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class library {
    /**
     * All library entries.
     *
     * @return array key => entry
     */
    public static function all(): array {
        global $CFG;
        static $all = null;
        if ($all === null) {
            $file = $CFG->dirroot . '/mod/aiscience/library/index.json';
            $list = is_readable($file) ? json_decode(file_get_contents($file), true) : [];
            $all = [];
            foreach ($list ?: [] as $e) {
                $all[$e['key']] = $e;
            }
        }
        return $all;
    }

    /**
     * Options for a select menu, grouped label "Title · Year · Strand".
     *
     * @return array
     */
    public static function options(): array {
        $out = [];
        foreach (self::all() as $key => $e) {
            $out[$key] = $e['title'] . ' · ' . $e['year'] . ' · ' . $e['strand'];
        }
        return $out;
    }

    /**
     * Experiment HTML for a key.
     *
     * @param string $key
     * @return string
     */
    public static function html(string $key): string {
        global $CFG;
        $key = clean_param($key, PARAM_ALPHANUMEXT);
        $file = $CFG->dirroot . '/mod/aiscience/library/' . $key . '.html';
        return is_readable($file) ? file_get_contents($file) : '';
    }

    /**
     * Install a library experiment into an activity as a new published version.
     *
     * @param int $aiscienceid
     * @param string $key
     * @param int $userid
     * @return int|null version id
     */
    public static function install(int $aiscienceid, string $key, int $userid): ?int {
        global $DB;
        $all = self::all();
        if (empty($all[$key]) || !($html = self::html($key))) {
            return null;
        }
        $e = $all[$key];
        $instance = $DB->get_record('aiscience', ['id' => $aiscienceid], '*', MUST_EXIST);
        // Fill empty brief fields from the library entry so results pages make sense.
        $fill = ['question' => 'question', 'ivariable' => 'ivariable', 'dvariable' => 'dvariable',
            'yearlevel' => 'year', 'curriculum' => 'curriculum', 'equipment' => 'equipment', 'safety' => 'safety'];
        $update = (object)['id' => $aiscienceid, 'librarykey' => $key];
        foreach ($fill as $field => $src) {
            if (trim((string)$instance->{$field}) === '' && !empty($e[$src])) {
                $update->{$field} = $e[$src];
            }
        }
        $DB->update_record('aiscience', $update);
        $now = time();
        $DB->set_field('aiscience_version', 'status', 'retired', ['aiscienceid' => $aiscienceid, 'status' => 'published']);
        $num = (int)$DB->get_field_sql(
            'SELECT MAX(versionnum) FROM {aiscience_version} WHERE aiscienceid = ?',
            [$aiscienceid]
        ) + 1;
        $versionid = $DB->insert_record('aiscience_version', (object)[
            'aiscienceid' => $aiscienceid, 'versionnum' => $num, 'status' => 'published', 'source' => 'library',
            'plan' => json_encode($e['plan'] ?? []), 'html' => $html, 'checks' => json_encode(checks::run($html)),
            'model' => 'library:' . $key, 'createdby' => $userid, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->set_field('aiscience', 'currentversion', $versionid, ['id' => $aiscienceid]);
        return $versionid;
    }

    /**
     * Bring every unedited ready-made version up to the current library page. Teacher edits and AI
     * refinements are separate versions, so they are never touched. Call from upgrade steps when the
     * library changes.
     *
     * @return int versions refreshed
     */
    public static function refresh_all(): int {
        global $DB;
        $lib = self::all();
        $n = 0;
        $rs = $DB->get_recordset_select(
            'aiscience_version',
            "source = 'library' AND " . $DB->sql_like('model', '?'),
            ['library:%'],
            '',
            'id, model'
        );
        foreach ($rs as $v) {
            $key = substr($v->model, 8);
            if (!isset($lib[$key])) {
                continue;
            }
            $html = self::html($key);
            $DB->update_record('aiscience_version', (object)['id' => $v->id, 'html' => $html,
                'checks' => json_encode(checks::run($html)), 'timemodified' => time()]);
            $n++;
        }
        $rs->close();
        return $n;
    }
}
