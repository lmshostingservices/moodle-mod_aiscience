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

namespace mod_aiscience\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_aiscience\local\manager;
use mod_aiscience\local\editor;
use mod_aiscience\local\checks;

/**
 * Teacher studio web services: plan, build, change, publish, results, grading.
 *
 * @package    mod_aiscience
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class studio extends external_api {
    /**
     * Shared setup.
     *
     * @param int $cmid
     * @param string $cap
     * @return array
     */
    protected static function setup(int $cmid, string $cap): array {
        $loaded = manager::load($cmid);
        self::validate_context($loaded[1]);
        require_capability($cap, $loaded[1]);
        return $loaded;
    }

    /**
     * Students this teacher may see here: enrolled with the submit capability, and in the
     * teacher's groups when the activity uses separate groups.
     *
     * @param \cm_info|\stdClass $cm
     * @param \context_module $context
     * @return array userid => true
     */
    protected static function visible_students($cm, \context_module $context): array {
        global $USER;
        $ids = array_keys(get_enrolled_users($context, 'mod/aiscience:submit', 0, 'u.id', null, 0, 0, true));
        $mode = groups_get_activity_groupmode($cm);
        if ($mode == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context)) {
            $members = [];
            foreach (groups_get_all_groups($cm->course, $USER->id, $cm->groupingid) as $g) {
                $members += array_flip(array_keys(groups_get_members($g->id, 'u.id')));
            }
            $ids = array_filter($ids, fn($id) => isset($members[$id]));
        }
        return array_fill_keys($ids, true);
    }

    /**
     * Stop a teacher acting on a student outside their groups.
     *
     * @param \cm_info|\stdClass $cm
     * @param \context_module $context
     * @param int $userid
     */
    protected static function require_student_visible($cm, \context_module $context, int $userid): void {
        if (!isset(self::visible_students($cm, $context)[$userid])) {
            throw new \required_capability_exception($context, 'moodle/site:accessallgroups', 'nopermissions', '');
        }
    }

    // Plan.

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function plan_parameters() {
        return new external_function_parameters(['cmid' => new external_value(PARAM_INT, 'Course module id')]);
    }

    /**
     * Queue a new plan.
     *
     * @param int $cmid
     * @return array
     */
    public static function plan($cmid) {
        global $USER;
        ['cmid' => $cmid] = self::validate_parameters(self::plan_parameters(), ['cmid' => $cmid]);
        [, $context, $instance] = self::setup($cmid, 'mod/aiscience:generate');
        if (!\mod_aiscience\ai\generator::available($context)) {
            throw new \moodle_exception('noprovider', 'mod_aiscience');
        }
        return ['versionid' => manager::new_version($instance, $USER->id, [], 'plan')];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function plan_returns() {
        return new external_single_structure(['versionid' => new external_value(PARAM_INT, 'Version id')]);
    }

    // Build.

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function build_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'versionid' => new external_value(PARAM_INT, 'Version with an approved plan'),
            'title' => new external_value(
                PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Text with < or >, cleaned on save.
                'Edited title',
            ),
            'objectives' => new external_value(
                PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Text with < or >, cleaned on save.
                'Edited objectives, one per line',
            ), ]);
    }

    /**
     * Approve the plan and queue the build.
     *
     * @param int $cmid
     * @param int $versionid
     * @param string $title
     * @param string $objectives
     * @return array
     */
    public static function build($cmid, $versionid, $title, $objectives) {
        global $DB, $USER;
        $p = self::validate_parameters(self::build_parameters(), compact('cmid', 'versionid', 'title', 'objectives'));
        [, $context, $instance] = self::setup($p['cmid'], 'mod/aiscience:generate');
        if (!\mod_aiscience\ai\generator::available($context)) {
            throw new \moodle_exception('noprovider', 'mod_aiscience');
        }
        $params = ['id' => $p['versionid'], 'aiscienceid' => $instance->id];
        $version = $DB->get_record('aiscience_version', $params, '*', MUST_EXIST);
        $plan = json_decode((string)$version->plan, true) ?: [];
        if (editor::plain($p['title'], 120) !== '') {
            $plan['title'] = editor::plain($p['title'], 120);
        }
        $obj = array_values(array_filter(array_map(fn($o) => editor::plain($o, 500), explode("\n", $p['objectives']))));
        if ($obj) {
            $plan['objectives'] = $obj;
        }
        $DB->update_record('aiscience_version', (object)['id' => $version->id, 'plan' => json_encode($plan),
            'status' => 'building', 'timemodified' => time()]);
        manager::queue($version->id, 'build', $USER->id);
        return ['versionid' => (int)$version->id];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function build_returns() {
        return self::plan_returns();
    }

    // Change by prompt.

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function change_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'instruction' => new external_value(
                PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Text with < or >, cleaned on save.
                'What to change',
            ),
            'basedon' => new external_value(PARAM_INT, 'Version to refine, 0 for the published one', VALUE_DEFAULT, 0), ]);
    }

    /**
     * Ask the AI to change the published experiment.
     *
     * @param int $cmid
     * @param string $instruction
     * @return array
     */
    public static function change($cmid, $instruction, $basedon = 0) {
        global $USER;
        $p = self::validate_parameters(self::change_parameters(), compact('cmid', 'instruction', 'basedon'));
        [, $context, $instance] = self::setup($p['cmid'], 'mod/aiscience:generate');
        if (!\mod_aiscience\ai\generator::available($context)) {
            throw new \moodle_exception('noprovider', 'mod_aiscience');
        }
        $instruction = editor::plain($p['instruction'], 4000);
        if ($instruction === '') {
            throw editor::problem('Say what to change.');
        }
        $base = self::base_version($instance, (int)$p['basedon']);
        $id = manager::new_version($instance, $USER->id, ['status' => 'building', 'plan' => (string)$base->plan,
            'instruction' => $instruction, 'basedon' => $base->id], 'build');
        return ['versionid' => $id];
    }

    /**
     * A finished version of this activity to edit or refine.
     *
     * @param \stdClass $instance
     * @param int $versionid 0 for the published one
     * @return \stdClass
     */
    protected static function base_version(\stdClass $instance, int $versionid): \stdClass {
        global $DB;
        $id = $versionid ?: (int)$instance->currentversion;
        $version = $id ? $DB->get_record('aiscience_version', ['id' => $id, 'aiscienceid' => $instance->id]) : false;
        if (
            !$version || trim((string)$version->html) === '' ||
                in_array($version->status, ['planning', 'planned', 'building', 'checking'])
        ) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '', 'Pick a finished version to start from.');
        }
        return $version;
    }

    // Content.

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function content_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'versionid' => new external_value(PARAM_INT, 'Version, 0 for the published one', VALUE_DEFAULT, 0), ]);
    }

    /**
     * The wording, slider settings and code a teacher can edit.
     *
     * @param int $cmid
     * @param int $versionid
     * @return array
     */
    public static function content($cmid, $versionid = 0) {
        global $DB;
        $p = self::validate_parameters(self::content_parameters(), compact('cmid', 'versionid'));
        [, , $instance] = self::setup($p['cmid'], 'mod/aiscience:generate');
        $version = self::base_version($instance, (int)$p['versionid']);
        $found = editor::extract((string)$version->html);
        $plan = json_decode((string)$version->plan, true) ?: [];
        $inprogress = $DB->count_records_select('aiscience_attempt', "aiscienceid = ? AND status = 'inprogress'", [$instance->id]);
        return ['versionid' => (int)$version->id, 'versionnum' => (int)$version->versionnum,
            'title' => (string)($plan['title'] ?? $instance->name), 'texts' => $found['texts'],
            'numbers' => array_map(
                fn($n) => array_map(fn($v) => $v === null ? '' : (is_float($v) ? (string)(0 + $v) : $v), $n),
                $found['numbers']
            ), 'html' => (string)$version->html, 'inprogress' => $inprogress];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function content_returns() {
        return new external_single_structure([
            'versionid' => new external_value(PARAM_INT, 'Version'),
            'versionnum' => new external_value(PARAM_INT, 'Number'),
            'title' => new external_value(PARAM_RAW, 'Title'), // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
            'texts' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_ALPHANUMEXT, 'Id'),
                'text' => new external_value(PARAM_RAW, 'Phrase'), // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                'where' => new external_value(PARAM_ALPHA, 'page or script'),
                'step' => new external_value(PARAM_INT, 'Step students see it in, 0 if throughout'),
                'stepname' => new external_value(
                    PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                    'Step label',
                ), ])),
            'numbers' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_ALPHANUMEXT, 'Id'),
                'label' => new external_value(PARAM_RAW, 'Label'), // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                'unit' => new external_value(PARAM_RAW, 'Unit'), // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                'min' => new external_value(PARAM_RAW, 'Lowest'), // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                'max' => new external_value(PARAM_RAW, 'Highest'), // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                'step' => new external_value(PARAM_RAW, 'Step'), // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                'start' => new external_value(
                    PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                    'Starting value',
                ), ])),
            'html' => new external_value(PARAM_RAW, 'Page code'), // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
            'inprogress' => new external_value(PARAM_INT, 'Students part way through'),
        ]);
    }

    // Save edit.

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function saveedit_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'versionid' => new external_value(PARAM_INT, 'Version the edits were made on'),
            'title' => new external_value(
                PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Text with < or >, cleaned on save.
                'Title',
                VALUE_DEFAULT,
                '',
            ),
            'texts' => new external_value(
                PARAM_RAW, // Pipeline-ignore: PARAM_RAW — JSON, decoded and validated.
                'JSON id => new phrase',
                VALUE_DEFAULT,
                '{}',
            ),
            'numbers' => new external_value(
                PARAM_RAW, // Pipeline-ignore: PARAM_RAW — JSON, decoded and validated.
                'JSON id => {min,max,step,start}',
                VALUE_DEFAULT,
                '{}',
            ),
            'html' => new external_value(
                PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Page code, checked and sandboxed.
                'Whole page code (code editor), empty to use the edits',
                VALUE_DEFAULT,
                '',
            ),
            'publish' => new external_value(PARAM_BOOL, 'Publish straight away if every check passes', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Save teacher edits as a new version, check it, and optionally publish it.
     *
     * @param int $cmid
     * @param int $versionid
     * @param string $title
     * @param string $texts
     * @param string $numbers
     * @param string $html
     * @param bool $publish
     * @return array
     */
    public static function saveedit($cmid, $versionid, $title = '', $texts = '{}', $numbers = '{}', $html = '', $publish = false) {
        global $DB, $USER;
        $p = self::validate_parameters(
            self::saveedit_parameters(),
            compact('cmid', 'versionid', 'title', 'texts', 'numbers', 'html', 'publish')
        );
        [, $context, $instance] = self::setup($p['cmid'], 'mod/aiscience:generate');
        $base = self::base_version($instance, (int)$p['versionid']);
        $plan = json_decode((string)$base->plan, true) ?: [];
        $summary = [];
        if (trim($p['html']) !== '') {
            if (strlen($p['html']) > checks::MAXBYTES * 2) {
                throw editor::problem('The page code is too large.');
            }
            $newhtml = $p['html'];
            $summary[] = 'Code edited';
        } else {
            $t = json_decode($p['texts'], true);
            $n = json_decode($p['numbers'], true);
            if (!is_array($t) || !is_array($n)) {
                throw editor::problem('The edits could not be read. Reload the page and try again.');
            }
            [$newhtml, $ct, $cn] = editor::apply((string)$base->html, $t, $n);
            if ($ct) {
                $summary[] = $ct . ' ' . ($ct === 1 ? 'phrase' : 'phrases') . ' reworded';
            }
            if ($cn) {
                $summary[] = $cn . ' ' . ($cn === 1 ? 'slider' : 'sliders') . ' changed';
            }
        }
        $newtitle = editor::plain($p['title'], 120);
        if ($newtitle !== '' && $newtitle !== ($plan['title'] ?? '')) {
            $plan['title'] = $newtitle;
            $summary[] = 'title changed';
        }
        if (!$summary || ($newhtml === $base->html && empty($summary))) {
            throw editor::problem('Nothing has changed yet.');
        }
        $results = checks::run($newhtml);
        $ok = checks::all_pass($results);
        $now = time();
        $num = (int)$DB->get_field_sql(
            'SELECT MAX(versionnum) FROM {aiscience_version} WHERE aiscienceid = ?',
            [$instance->id]
        ) + 1;
        $id = $DB->insert_record('aiscience_version', (object)['aiscienceid' => $instance->id, 'versionnum' => $num,
            'status' => $ok ? 'ready' : 'failed', 'source' => 'edit', 'plan' => json_encode($plan), 'html' => $newhtml,
            'checks' => json_encode($results), 'instruction' => ucfirst(implode(', ', $summary)) . '.',
            'error' => $ok ? '' : 'Some checks fail. Fix them in the code, or start again from a working version.',
            'model' => 'teacher', 'repairs' => 0, 'basedon' => $base->id, 'createdby' => $USER->id,
            'timecreated' => $now, 'timemodified' => $now]);
        $published = false;
        if ($ok && $p['publish']) {
            require_capability('mod/aiscience:publish', $context);
            manager::publish($instance, $id, $context);
            $published = true;
        }
        return ['versionid' => $id, 'status' => $published ? 'published' : ($ok ? 'ready' : 'failed'),
            'summary' => ucfirst(implode(', ', $summary)) . '.', 'checks' => json_encode($results)];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function saveedit_returns() {
        return new external_single_structure([
            'versionid' => new external_value(PARAM_INT, 'New version'),
            'status' => new external_value(PARAM_ALPHA, 'ready, failed or published'),
            'summary' => new external_value(
                PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                'What changed',
            ),
            'checks' => new external_value(
                PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                'Checks JSON',
            ),
        ]);
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function change_returns() {
        return self::plan_returns();
    }

    // Status.

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function status_parameters() {
        return self::plan_parameters();
    }

    /**
     * All versions and the pipeline state.
     *
     * @param int $cmid
     * @return array
     */
    public static function status($cmid) {
        global $DB;
        ['cmid' => $cmid] = self::validate_parameters(self::status_parameters(), ['cmid' => $cmid]);
        [, $context, $instance] = self::setup($cmid, 'mod/aiscience:generate');
        $versions = [];
        $fields = 'id, versionnum, status, source, plan, checks, instruction, model, error, repairs, basedon, '
            . 'timecreated, timemodified';
        $records = $DB->get_records('aiscience_version', ['aiscienceid' => $instance->id], 'versionnum DESC', $fields);
        foreach ($records as $v) {
            $versions[] = ['id' => (int)$v->id, 'versionnum' => (int)$v->versionnum, 'status' => $v->status,
                'source' => $v->source, 'plan' => (string)$v->plan, 'checks' => (string)$v->checks,
                'instruction' => (string)$v->instruction, 'basedon' => (int)$v->basedon, 'model' => (string)$v->model,
                'error' => (string)$v->error,
                'repairs' => (int)$v->repairs, 'current' => ($v->id == $instance->currentversion),
                'timemodified' => (int)$v->timemodified];
        }
        $prices = \mod_aiscience\ai\generator::provider() === 'mock' ? ['plan' => 1, 'build' => 10, 'refine' => 2, 'mark' => 1,
            'suggest' => 1] : (\mod_aiscience\ai\lmslabs::status()['prices'] ?? []);
        return ['available' => \mod_aiscience\ai\generator::available($context), 'versions' => $versions,
            'name' => format_string($instance->name), 'prices' => json_encode((object)$prices),
            'credits' => \mod_aiscience\local\credits::balance()];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function status_returns() {
        return new external_single_structure([
            'available' => new external_value(PARAM_BOOL, 'AI generation available'),
            'name' => new external_value(PARAM_TEXT, 'Activity name'),
            'prices' => new external_value(
                PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                'LMS Labs credit prices per task, JSON',
            ),
            'credits' => new external_value(PARAM_INT, 'LMS Labs credit balance, -1 if unknown, -2 if unlimited'),
            'versions' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Id'), 'versionnum' => new external_value(PARAM_INT, 'Number'),
                'status' => new external_value(PARAM_ALPHA, 'Status'),
                'source' => new external_value(PARAM_ALPHA, 'ai or library'),
                'plan' => new external_value(
                    PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                    'Plan JSON',
                ),
                'checks' => new external_value(
                    PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                    'Checks JSON',
                ),
                'instruction' => new external_value(
                    PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                    'Change request or edit summary',
                ),
                'basedon' => new external_value(PARAM_INT, 'Edited or refined from'),
                'model' => new external_value(PARAM_TEXT, 'Model'),
                'error' => new external_value(PARAM_RAW, 'Error'), // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                'repairs' => new external_value(PARAM_INT, 'Repair rounds'),
                'current' => new external_value(PARAM_BOOL, 'Published to students'),
                'timemodified' => new external_value(PARAM_INT, 'Modified'), ])), ]);
    }

    // Publish.

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function publish_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'versionid' => new external_value(PARAM_INT, 'Version to publish'), ]);
    }

    /**
     * Publish or roll back to a version.
     *
     * @param int $cmid
     * @param int $versionid
     * @return array
     */
    public static function publish($cmid, $versionid) {
        $p = self::validate_parameters(self::publish_parameters(), compact('cmid', 'versionid'));
        [, $context, $instance] = self::setup($p['cmid'], 'mod/aiscience:publish');
        manager::publish($instance, $p['versionid'], $context);
        return ['versionid' => $p['versionid']];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function publish_returns() {
        return self::plan_returns();
    }

    // Results.

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function results_parameters() {
        return self::plan_parameters();
    }

    /**
     * Class results with notebooks.
     *
     * @param int $cmid
     * @return array
     */
    public static function results($cmid) {
        global $DB, $PAGE;
        ['cmid' => $cmid] = self::validate_parameters(self::results_parameters(), ['cmid' => $cmid]);
        [$cm, $context, $instance] = self::setup($cmid, 'mod/aiscience:viewreports');
        $rows = [];
        $attempts = $DB->get_records('aiscience_attempt', ['aiscienceid' => $instance->id], 'timemodified DESC');
        $visible = self::visible_students($cm, $context);
        $versionsused = [(int)$instance->currentversion => true];
        $seen = [];
        $fs = get_file_storage();
        foreach ($attempts as $a) {
            if (isset($seen[$a->userid]) || !isset($visible[$a->userid])) {
                continue;
            }
            $seen[$a->userid] = true;
            $user = \core_user::get_user($a->userid);
            if (!$user) {
                continue;
            }
            $notes = [];
            foreach ($DB->get_records('aiscience_note', ['attemptid' => $a->id], 'timecreated ASC') as $n) {
                $url = '';
                if ($n->kind !== 'text') {
                    $files = $fs->get_area_files($context->id, 'mod_aiscience', 'note', $n->id, 'id', false);
                    if ($f = reset($files)) {
                        $url = \moodle_url::make_pluginfile_url(
                            $context->id,
                            'mod_aiscience',
                            'note',
                            $n->id,
                            '/',
                            $f->get_filename()
                        )->out(false);
                    }
                }
                $notes[] = ['kind' => $n->kind, 'content' => (string)$n->content, 'stagelabel' => (string)$n->stagelabel,
                    'exptime' => (string)$n->exptime, 'url' => $url, 'timecreated' => (int)$n->timecreated];
            }
            $rows[] = ['attemptid' => (int)$a->id, 'userid' => (int)$a->userid, 'fullname' => fullname($user),
                'status' => $a->status, 'stage' => (int)$a->stage, 'stagename' => (string)$a->stagename,
                'prediction' => (string)$a->prediction, 'phases' => (string)$a->phases, 'readings' => (int)$a->readings,
                'answers' => (string)$a->answers, 'report' => (string)$a->report,
                'grade' => $a->grade === null ? -1 : (float)$a->grade,
                'rubric' => (string)$a->rubric, 'feedback' => (string)$a->feedback, 'notes' => $notes,
                'timemodified' => (int)$a->timemodified, 'timecreated' => (int)$a->timecreated,
                'timesubmitted' => (int)($a->timesubmitted ?? 0), 'versionid' => (int)$a->versionid,
                'marks' => (string)($a->marks ?? '')];
            $versionsused[(int)$a->versionid] = true;
        }
        $plan = $instance->currentversion
            ? (string)$DB->get_field('aiscience_version', 'plan', ['id' => $instance->currentversion]) : '{}';
        $guides = [];
        foreach (array_keys($versionsused) as $vid) {
            $html = $vid ? (string)$DB->get_field('aiscience_version', 'html', ['id' => $vid, 'aiscienceid' => $instance->id]) : '';
            $guides[$vid] = $html !== '' ? \mod_aiscience\local\guide::from_html($html) : null;
        }
        $available = \mod_aiscience\ai\generator::available($context);
        return ['maxgrade' => (int)$instance->grade, 'aisuggest' => (bool)$instance->aisuggest && $available, 'plan' => $plan,
            'attempts' => $rows, 'guides' => json_encode($guides), 'current' => (int)$instance->currentversion,
            'aimark' => $available, 'credits' => \mod_aiscience\local\credits::balance(),
            'creditsused' => \mod_aiscience\local\credits::used((int)$instance->id), 'year' => (string)$instance->yearlevel];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function results_returns() {
        return new external_single_structure([
            'maxgrade' => new external_value(PARAM_INT, 'Max grade'),
            'aisuggest' => new external_value(PARAM_BOOL, 'AI suggestions on'),
            'plan' => new external_value(
                PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                'Current plan JSON',
            ),
            'guides' => new external_value(
                PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                'Marking guides by version id, JSON',
            ),
            'current' => new external_value(PARAM_INT, 'Published version'),
            'aimark' => new external_value(PARAM_BOOL, 'AI marking possible'),
            'credits' => new external_value(PARAM_INT, 'AI marking credits left'),
            'creditsused' => new external_value(PARAM_INT, 'Credits used by this activity'),
            'year' => new external_value(PARAM_RAW, 'Year level'), // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
            'attempts' => new external_multiple_structure(new external_single_structure([
                'attemptid' => new external_value(PARAM_INT, 'Attempt'), 'userid' => new external_value(PARAM_INT, 'User'),
                'fullname' => new external_value(PARAM_TEXT, 'Name'), 'status' => new external_value(PARAM_ALPHA, 'Status'),
                'stage' => new external_value(PARAM_INT, 'Stage'),
                'stagename' => new external_value(
                    PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                    'Stage name',
                ),
                'prediction' => new external_value(
                    PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                    'Prediction',
                ),
                'phases' => new external_value(
                    PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                    'Summary JSON',
                ),
                'readings' => new external_value(PARAM_INT, 'Readings'),
                'answers' => new external_value(
                    PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                    'Answers JSON',
                ),
                'report' => new external_value(
                    PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                    'Report',
                ),
                'grade' => new external_value(PARAM_FLOAT, 'Grade, -1 none'),
                'rubric' => new external_value(
                    PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                    'Rubric JSON',
                ),
                'feedback' => new external_value(
                    PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                    'Feedback',
                ),
                'timemodified' => new external_value(PARAM_INT, 'Modified'),
                'timecreated' => new external_value(PARAM_INT, 'Started'),
                'timesubmitted' => new external_value(PARAM_INT, 'Submitted, 0 if not'),
                'versionid' => new external_value(PARAM_INT, 'Version used'),
                'marks' => new external_value(
                    PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                    'Per-question marks JSON',
                ),
                'notes' => new external_multiple_structure(new external_single_structure([
                    'kind' => new external_value(PARAM_ALPHA, 'text, audio, image'),
                    'content' => new external_value(
                        PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                        'Text',
                    ),
                    'stagelabel' => new external_value(
                        PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                        'Stage',
                    ),
                    'exptime' => new external_value(
                        PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                        'Experiment time',
                    ),
                    'url' => new external_value(PARAM_URL, 'Media URL'), 'timecreated' => new external_value(PARAM_INT, 'Time'),
                ])), ])), ]);
    }

    // Grade.

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function grade_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'attemptid' => new external_value(PARAM_INT, 'Attempt'), 'claim' => new external_value(PARAM_INT, '0-4'),
            'evidence' => new external_value(PARAM_INT, '0-4'), 'reasoning' => new external_value(PARAM_INT, '0-4'),
            'feedback' => new external_value(
                PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Text with < or >, cleaned on save.
                'Feedback',
                VALUE_DEFAULT,
                '',
            ), ]);
    }

    /**
     * Save a rubric grade.
     *
     * @param int $cmid
     * @param int $attemptid
     * @param int $claim
     * @param int $evidence
     * @param int $reasoning
     * @param string $feedback
     * @return array
     */
    public static function grade($cmid, $attemptid, $claim, $evidence, $reasoning, $feedback = '') {
        global $DB, $USER;
        $p = self::validate_parameters(
            self::grade_parameters(),
            compact('cmid', 'attemptid', 'claim', 'evidence', 'reasoning', 'feedback')
        );
        [$cm, $context, $instance] = self::setup($p['cmid'], 'mod/aiscience:grade');
        $attempt = $DB->get_record(
            'aiscience_attempt',
            ['id' => $p['attemptid'], 'aiscienceid' => $instance->id],
            '*',
            MUST_EXIST
        );
        self::require_student_visible($cm, $context, (int)$attempt->userid);
        $grade = manager::grade(
            $instance,
            $attempt,
            ['claim' => $p['claim'], 'evidence' => $p['evidence'], 'reasoning' => $p['reasoning']],
            editor::plain($p['feedback'], 10000),
            $USER->id
        );
        return ['grade' => $grade ?? -1];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function grade_returns() {
        return new external_single_structure([
            'grade' => new external_value(PARAM_FLOAT, 'Grade, -1 when the activity is not graded'),
        ]);
    }

    // Suggest.

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function suggest_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'attemptid' => new external_value(PARAM_INT, 'Attempt'), ]);
    }

    /**
     * AI-suggested rubric scores.
     *
     * @param int $cmid
     * @param int $attemptid
     * @return array
     */
    public static function suggest($cmid, $attemptid) {
        global $DB, $USER;
        $p = self::validate_parameters(self::suggest_parameters(), compact('cmid', 'attemptid'));
        [$cm, $context, $instance] = self::setup($p['cmid'], 'mod/aiscience:grade');
        $attempt = $DB->get_record(
            'aiscience_attempt',
            ['id' => $p['attemptid'], 'aiscienceid' => $instance->id],
            '*',
            MUST_EXIST
        );
        self::require_student_visible($cm, $context, (int)$attempt->userid);
        if (!\mod_aiscience\ai\generator::available($context)) {
            throw new \moodle_exception('noprovider', 'mod_aiscience');
        }
        return \mod_aiscience\ai\generator::suggest_rubric(
            $instance,
            (string)$attempt->report,
            $context->id,
            $USER->id,
            (int)$attempt->id
        );
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function suggest_returns() {
        return new external_single_structure([
            'claim' => new external_value(PARAM_INT, '0-4'), 'evidence' => new external_value(PARAM_INT, '0-4'),
            'reasoning' => new external_value(PARAM_INT, '0-4'),
            'reason' => new external_value(PARAM_RAW, 'Why'), // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
            'feedback' => new external_value(
                PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                'Suggested feedback',
            ), ]);
    }

    // AI marking.

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function aimark_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'items' => new external_value(
                PARAM_RAW, // Pipeline-ignore: PARAM_RAW — JSON, decoded and validated.
                'JSON list of {attemptid, k, hint}',
            ),
        ]);
    }

    /**
     * Mark answers with AI, 1 credit per answer marked. Empty answers get 0 without using a credit.
     *
     * @param int $cmid
     * @param string $items
     * @return array
     */
    public static function aimark($cmid, $items) {
        global $DB, $USER;
        $p = self::validate_parameters(self::aimark_parameters(), compact('cmid', 'items'));
        [$cm, $context, $instance] = self::setup($p['cmid'], 'mod/aiscience:grade');
        if (!\mod_aiscience\ai\generator::available($context)) {
            throw new \moodle_exception('noprovider', 'mod_aiscience');
        }
        $list = json_decode($p['items'], true);
        if (!is_array($list) || count($list) > 100) {
            throw editor::problem('Send up to 100 answers at a time.');
        }
        \core_php_time_limit::raise(900);
        // Group by report: one AI request per student, 1 credit per answer marked.
        $groups = [];
        foreach ($list as $it) {
            $aid = (int)($it['attemptid'] ?? 0);
            $k = substr(clean_param((string)($it['k'] ?? ''), PARAM_ALPHANUMEXT), 0, 64);
            if ($aid && $k !== '') {
                $groups[$aid][$k] = editor::plain(is_scalar($it['hint'] ?? null) ? (string)$it['hint'] : '', 400);
            }
        }
        $out = [];
        $guides = [];
        $nocredits = false;
        foreach ($groups as $aid => $keys) {
            $a = $DB->get_record('aiscience_attempt', ['id' => $aid, 'aiscienceid' => $instance->id]);
            if (!$a) {
                continue;
            }
            self::require_student_visible($cm, $context, (int)$a->userid);
            if (!isset($guides[$a->versionid])) {
                $html = (string)$DB->get_field(
                    'aiscience_version',
                    'html',
                    ['id' => $a->versionid, 'aiscienceid' => $instance->id]
                );
                $guides[$a->versionid] = \mod_aiscience\local\guide::from_html($html) ?: ['questions' => []];
            }
            $answers = json_decode((string)$a->answers, true) ?: [];
            $batch = [];
            $logs = [];
            foreach ($keys as $k => $hint) {
                $q = null;
                foreach ($guides[$a->versionid]['questions'] as $gq) {
                    if ($gq['k'] === $k) {
                        $q = $gq;
                    }
                }
                $q = $q ?: ['k' => $k, 'q' => $k, 'part' => 'other', 'model' => '', 'ideas' => [], 'numbers' => 0,
                    'because' => false];
                $answer = trim((string)($answers[$k] ?? ''));
                if ($answer === '') {
                    $out[] = ['attemptid' => $aid, 'k' => $k, 'mark' => 0, 'why' => 'No answer.', 'charged' => false,
                        'error' => ''];
                    continue;
                }
                $logs[$k] = true;
                $batch[] = ['question' => $q, 'answer' => editor::plain($answer, 3000), 'hint' => $hint];
            }
            if (!$batch) {
                continue;
            }
            if ($nocredits) {
                foreach (array_keys($logs) as $k) {
                    $out[] = ['attemptid' => $aid, 'k' => $k, 'mark' => 0, 'why' => '', 'charged' => false,
                        'error' => get_string('lmslabs_nocredits', 'mod_aiscience')];
                }
                continue;
            }
            $data = [];
            $charged = [];
            try {
                $reply = \mod_aiscience\ai\generator::complete(\mod_aiscience\ai\prompts::markreport(
                    $batch,
                    (string)$a->phases,
                    (string)$instance->yearlevel
                ), $context->id, (int)$USER->id, 200 + 120 * count($batch), 'mark', "mark-a$aid-" . time(), array_keys($logs));
                $data = \mod_aiscience\ai\generator::parse_json($reply) ?: [];
                $charged = \mod_aiscience\ai\lmslabs::$last['charged'] ?? [];
                \mod_aiscience\ai\generator::log_charge($instance, $aid, (int)$USER->id, 'mark');
            } catch (\Throwable $e) {
                $data = ['_error' => $e->getMessage()];
            }
            foreach (array_keys($logs) as $k) {
                $m = $data[$k] ?? null;
                if (is_array($m) && isset($m['mark']) && is_numeric($m['mark'])) {
                    $out[] = ['attemptid' => $aid, 'k' => $k, 'mark' => max(0, min(4, (int)round((float)$m['mark']))),
                        'why' => editor::plain((string)($m['why'] ?? ''), 300), 'charged' => !empty($charged[$k]), 'error' => ''];
                } else {
                    $out[] = ['attemptid' => $aid, 'k' => $k, 'mark' => 0, 'why' => '', 'charged' => !empty($charged[$k]),
                        'error' => is_string($data['_error'] ?? null) ? $data['_error'] : 'The AI reply could not be read.'];
                }
            }
            if (isset($data['_error']) && $data['_error'] === get_string('lmslabs_nocredits', 'mod_aiscience')) {
                $nocredits = true;
            }
        }
        return ['results' => $out, 'credits' => \mod_aiscience\local\credits::balance()];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function aimark_returns() {
        return new external_single_structure([
            'results' => new external_multiple_structure(new external_single_structure([
                'attemptid' => new external_value(PARAM_INT, 'Attempt'),
                'k' => new external_value(PARAM_ALPHANUMEXT, 'Question key'),
                'mark' => new external_value(PARAM_INT, '0-4'),
                'why' => new external_value(PARAM_RAW, 'Reason'), // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                'charged' => new external_value(PARAM_BOOL, 'A credit was used'),
                'error' => new external_value(
                    PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                    'Error, if any',
                ),
            ])),
            'credits' => new external_value(PARAM_INT, 'Credits left'),
        ]);
    }

    // Apply marks.

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function applymarks_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'items' => new external_value(
                PARAM_RAW, // Pipeline-ignore: PARAM_RAW — JSON, decoded and validated.
                'JSON list of {attemptid, claim, evidence, reasoning, feedback, marks}',
            ),
        ]);
    }

    /**
     * Save approved marks: rubric to the gradebook, per-question marks kept for reports.
     *
     * @param int $cmid
     * @param string $items
     * @return array
     */
    public static function applymarks($cmid, $items) {
        global $DB, $USER;
        $p = self::validate_parameters(self::applymarks_parameters(), compact('cmid', 'items'));
        [$cm, $context, $instance] = self::setup($p['cmid'], 'mod/aiscience:grade');
        $list = json_decode($p['items'], true);
        if (!is_array($list) || count($list) > 500) {
            throw editor::problem('The marks could not be read. Reload the page and try again.');
        }
        $saved = 0;
        foreach ($list as $it) {
            $a = $DB->get_record('aiscience_attempt', ['id' => (int)($it['attemptid'] ?? 0), 'aiscienceid' => $instance->id]);
            if (!$a || $a->status === 'inprogress') {
                continue;
            }
            self::require_student_visible($cm, $context, (int)$a->userid);
            $marks = [];
            foreach ((array)($it['marks'] ?? []) as $k => $m) {
                if (!is_array($m) || count($marks) >= 40) {
                    continue;
                }
                $k = substr(clean_param((string)$k, PARAM_ALPHANUMEXT), 0, 64);
                $marks[$k] = ['mark' => max(0, min(4, (int)($m['mark'] ?? 0))), 'max' => 4,
                    'why' => editor::plain((string)($m['why'] ?? ''), 300),
                    'by' => in_array($m['by'] ?? '', ['engine', 'ondevice', 'ai', 'teacher'], true) ? $m['by'] : 'teacher'];
            }
            $DB->set_field('aiscience_attempt', 'marks', json_encode($marks), ['id' => $a->id]);
            manager::grade(
                $instance,
                $a,
                ['claim' => (int)($it['claim'] ?? 0), 'evidence' => (int)($it['evidence'] ?? 0),
                'reasoning' => (int)($it['reasoning'] ?? 0)],
                editor::plain((string)($it['feedback'] ?? ''), 10000),
                (int)$USER->id
            );
            $saved++;
        }
        return ['saved' => $saved];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function applymarks_returns() {
        return new external_single_structure(['saved' => new external_value(PARAM_INT, 'Reports graded')]);
    }
}
