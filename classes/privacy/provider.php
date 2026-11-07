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

namespace mod_aiscience\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider: attempts, reports, notebooks and the AI provider.
 *
 * @package    mod_aiscience
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe stored data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('aiscience_attempt', [
            'userid' => 'privacy:metadata:aiscience_attempt:userid',
            'prediction' => 'privacy:metadata:aiscience_attempt:prediction',
            'answers' => 'privacy:metadata:aiscience_attempt:answers',
            'report' => 'privacy:metadata:aiscience_attempt:report',
            'grade' => 'privacy:metadata:aiscience_attempt:grade',
            'feedback' => 'privacy:metadata:aiscience_attempt:feedback',
            'marks' => 'privacy:metadata:aiscience_attempt:marks',
            'timesubmitted' => 'privacy:metadata:aiscience_attempt:timesubmitted',
        ], 'privacy:metadata:aiscience_attempt');
        $collection->add_database_table('aiscience_note', [
            'userid' => 'privacy:metadata:aiscience_note:userid',
            'content' => 'privacy:metadata:aiscience_note:content',
            'timecreated' => 'privacy:metadata:aiscience_note:timecreated',
        ], 'privacy:metadata:aiscience_note');
        $collection->add_database_table('aiscience_version', [
            'createdby' => 'privacy:metadata:aiscience_version:createdby',
        ], 'privacy:metadata:aiscience_version');
        $collection->add_database_table('aiscience_credit', [
            'userid' => 'privacy:metadata:aiscience_credit:userid',
            'timecreated' => 'privacy:metadata:aiscience_credit:timecreated',
        ], 'privacy:metadata:aiscience_credit');
        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:aiscience_note');
        $collection->add_external_location_link('aiprovider', [
            'brief' => 'privacy:metadata:aiprovider:brief',
            'report' => 'privacy:metadata:aiprovider:report',
        ], 'privacy:metadata:aiprovider');
        return $collection;
    }

    /**
     * Contexts holding a user's data.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $sql = "SELECT c.id
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :ctx
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'aiscience'
                  JOIN {aiscience} s ON s.id = cm.instance
                 WHERE EXISTS (SELECT 1 FROM {aiscience_attempt} a WHERE a.aiscienceid = s.id AND a.userid = :u1)
                    OR EXISTS (SELECT 1 FROM {aiscience_version} v WHERE v.aiscienceid = s.id AND v.createdby = :u2)";
        $list = new contextlist();
        $list->add_from_sql($sql, ['ctx' => CONTEXT_MODULE, 'u1' => $userid, 'u2' => $userid]);
        return $list;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $params = ['cmid' => $context->instanceid];
        $userlist->add_from_sql('userid', "SELECT a.userid FROM {aiscience_attempt} a
            JOIN {course_modules} cm ON cm.instance = a.aiscienceid
            JOIN {modules} m ON m.id = cm.module AND m.name = 'aiscience' WHERE cm.id = :cmid", $params);
        $userlist->add_from_sql('createdby', "SELECT v.createdby FROM {aiscience_version} v
            JOIN {course_modules} cm ON cm.instance = v.aiscienceid
            JOIN {modules} m ON m.id = cm.module AND m.name = 'aiscience' WHERE cm.id = :cmid", $params);
    }

    /**
     * Export a user's data.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('aiscience', $context->instanceid);
            if (!$cm) {
                continue;
            }
            $attempts = $DB->get_records('aiscience_attempt', ['aiscienceid' => $cm->instance, 'userid' => $userid]);
            if (!$attempts) {
                continue;
            }
            $data = helper::get_context_data($context, $contextlist->get_user());
            $data->attempts = [];
            foreach ($attempts as $a) {
                $notes = [];
                foreach ($DB->get_records('aiscience_note', ['attemptid' => $a->id]) as $n) {
                    $notes[] = ['kind' => $n->kind, 'content' => $n->content, 'stage' => $n->stagelabel,
                        'experimenttime' => $n->exptime, 'time' => transform::datetime($n->timecreated)];
                    writer::with_context($context)->export_area_files(['notes', $n->id], 'mod_aiscience', 'note', $n->id);
                }
                $data->attempts[] = ['status' => $a->status, 'prediction' => $a->prediction,
                    'answers' => json_decode((string)$a->answers, true), 'report' => $a->report, 'grade' => $a->grade,
                    'feedback' => $a->feedback, 'submitted' => $a->timesubmitted ? transform::datetime($a->timesubmitted) : '',
                    'notebook' => $notes];
            }
            writer::with_context($context)->export_data([], $data);
        }
    }

    /**
     * Delete everyone's data in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('aiscience', $context->instanceid);
        if (!$cm) {
            return;
        }
        $ids = $DB->get_fieldset_select('aiscience_attempt', 'id', 'aiscienceid = ?', [$cm->instance]);
        self::delete_attempts($context, $ids);
    }

    /**
     * Delete one user's data.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $cm = get_coursemodule_from_id('aiscience', $context->instanceid);
            if (!$cm) {
                continue;
            }
            $ids = $DB->get_fieldset_select(
                'aiscience_attempt',
                'id',
                'aiscienceid = ? AND userid = ?',
                [$cm->instance, $userid]
            );
            self::delete_attempts($context, $ids);
            // Credit log rows stay for billing but no longer name the teacher.
            $DB->set_field('aiscience_credit', 'userid', 0, ['aiscienceid' => $cm->instance, 'userid' => $userid]);
        }
    }

    /**
     * Delete several users' data.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();
        $cm = get_coursemodule_from_id('aiscience', $context->instanceid);
        if (!$cm || !$userlist->get_userids()) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userlist->get_userids());
        $ids = $DB->get_fieldset_select(
            'aiscience_attempt',
            'id',
            "aiscienceid = ? AND userid $insql",
            array_merge([$cm->instance], $params)
        );
        self::delete_attempts($context, $ids);
    }

    /**
     * Delete attempts, notes and files.
     *
     * @param \context $context
     * @param array $ids
     */
    protected static function delete_attempts(\context $context, array $ids): void {
        global $DB;
        if (!$ids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($ids);
        $fs = get_file_storage();
        foreach ($DB->get_fieldset_select('aiscience_note', 'id', "attemptid $insql", $params) as $noteid) {
            $fs->delete_area_files($context->id, 'mod_aiscience', 'note', $noteid);
        }
        $DB->delete_records_select('aiscience_note', "attemptid $insql", $params);
        $DB->delete_records_select('aiscience_attempt', "id $insql", $params);
    }
}
