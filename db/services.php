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

/**
 * Web service definitions.
 *
 * @package    mod_aiscience
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_aiscience_plan' => [
        'classname' => 'mod_aiscience\\external\\studio',
        'methodname' => 'plan',
        'description' => 'Plan a new experiment with AI',
        'type' => 'write',
        'ajax' => true,
    ],
    'mod_aiscience_build' => [
        'classname' => 'mod_aiscience\\external\\studio',
        'methodname' => 'build',
        'description' => 'Approve a plan and build the experiment',
        'type' => 'write',
        'ajax' => true,
    ],
    'mod_aiscience_change' => [
        'classname' => 'mod_aiscience\\external\\studio',
        'methodname' => 'change',
        'description' => 'Ask the AI to change the experiment',
        'type' => 'write',
        'ajax' => true,
    ],
    'mod_aiscience_content' => [
        'classname' => 'mod_aiscience\\external\\studio',
        'methodname' => 'content',
        'description' => 'Wording, slider settings and code a teacher can edit',
        'type' => 'read',
        'ajax' => true,
    ],
    'mod_aiscience_saveedit' => [
        'classname' => 'mod_aiscience\\external\\studio',
        'methodname' => 'saveedit',
        'description' => 'Save teacher edits as a new checked version',
        'type' => 'write',
        'ajax' => true,
    ],
    'mod_aiscience_aimark' => [
        'classname' => 'mod_aiscience\\external\\studio',
        'methodname' => 'aimark',
        'description' => 'Mark answers with AI, 1 credit per answer',
        'type' => 'write',
        'ajax' => true,
    ],
    'mod_aiscience_applymarks' => [
        'classname' => 'mod_aiscience\\external\\studio',
        'methodname' => 'applymarks',
        'description' => 'Save approved marks to the gradebook',
        'type' => 'write',
        'ajax' => true,
    ],
    'mod_aiscience_status' => [
        'classname' => 'mod_aiscience\\external\\studio',
        'methodname' => 'status',
        'description' => 'Experiment versions and pipeline state',
        'type' => 'read',
        'ajax' => true,
    ],
    'mod_aiscience_publish' => [
        'classname' => 'mod_aiscience\\external\\studio',
        'methodname' => 'publish',
        'description' => 'Publish or roll back an experiment version',
        'type' => 'write',
        'ajax' => true,
    ],
    'mod_aiscience_results' => [
        'classname' => 'mod_aiscience\\external\\studio',
        'methodname' => 'results',
        'description' => 'Class results and notebooks',
        'type' => 'read',
        'ajax' => true,
    ],
    'mod_aiscience_grade' => [
        'classname' => 'mod_aiscience\\external\\studio',
        'methodname' => 'grade',
        'description' => 'Save a CER rubric grade',
        'type' => 'write',
        'ajax' => true,
    ],
    'mod_aiscience_suggest' => [
        'classname' => 'mod_aiscience\\external\\studio',
        'methodname' => 'suggest',
        'description' => 'AI-suggested rubric scores',
        'type' => 'read',
        'ajax' => true,
    ],
    'mod_aiscience_experiment' => [
        'classname' => 'mod_aiscience\\external\\student',
        'methodname' => 'experiment',
        'description' => 'Load the sandboxed experiment',
        'type' => 'read',
        'ajax' => true,
    ],
    'mod_aiscience_record' => [
        'classname' => 'mod_aiscience\\external\\student',
        'methodname' => 'record',
        'description' => 'Record student progress, answers and report',
        'type' => 'write',
        'ajax' => true,
    ],
    'mod_aiscience_addnote' => [
        'classname' => 'mod_aiscience\\external\\student',
        'methodname' => 'addnote',
        'description' => 'Add a typed lab notebook note',
        'type' => 'write',
        'ajax' => true,
    ],
    'mod_aiscience_deletenote' => [
        'classname' => 'mod_aiscience\\external\\student',
        'methodname' => 'deletenote',
        'description' => 'Delete one of the student\'s own lab notebook items',
        'type' => 'write',
        'ajax' => true,
    ],
    'mod_aiscience_notes' => [
        'classname' => 'mod_aiscience\\external\\student',
        'methodname' => 'notes',
        'description' => 'The lab notebook of the student',
        'type' => 'read',
        'ajax' => true,
    ],
];
