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
 * Activity settings form: the teacher brief.
 *
 * @package    mod_aiscience
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * Teacher brief and activity settings.
 */
class mod_aiscience_mod_form extends moodleform_mod {
    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), ['size' => 64]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $this->standard_intro_elements();

        // Start point.
        $mform->addElement('header', 'startheader', get_string('startfrom', 'aiscience'));
        $mform->setExpanded('startheader');
        $options = ['' => get_string('startfromscratch', 'aiscience')] + \mod_aiscience\local\library::options();
        $mform->addElement('select', 'librarykey', get_string('librarypick', 'aiscience'), $options);
        $mform->addHelpButton('librarykey', 'librarypick', 'aiscience');

        // Teacher brief.
        $mform->addElement('header', 'briefheader', get_string('brief', 'aiscience'));
        $mform->setExpanded('briefheader');
        $mform->addElement('textarea', 'question', get_string('question', 'aiscience'), ['rows' => 2, 'cols' => 60]);
        $mform->setType('question', PARAM_TEXT);
        $mform->addHelpButton('question', 'question', 'aiscience');

        $years = ['Foundation' => 'Foundation'];
        for ($y = 1; $y <= 12; $y++) {
            $years["Year $y"] = get_string('yearn', 'aiscience', $y);
        }
        $mform->addElement('select', 'yearlevel', get_string('yearlevel', 'aiscience'), $years);
        $mform->setDefault('yearlevel', 'Year 8');
        $lengths = ['30 min' => '30 min', '45 min' => '45 min', '60 min' => '60 min', '2 x 60 min' => '2 × 60 min'];
        $mform->addElement('select', 'lessonlength', get_string('lessonlength', 'aiscience'), $lengths);
        $mform->setDefault('lessonlength', '60 min');

        $mform->addElement('textarea', 'curriculum', get_string('curriculum', 'aiscience'), ['rows' => 4, 'cols' => 60]);
        $mform->setType('curriculum', PARAM_TEXT);
        $mform->addHelpButton('curriculum', 'curriculum', 'aiscience');
        $mform->addElement(
            'filemanager',
            'curriculumfile',
            get_string('curriculumfile', 'aiscience'),
            null,
            ['subdirs' => 0, 'maxfiles' => 3, 'accepted_types' => ['.pdf', '.doc', '.docx', '.txt', '.csv']]
        );

        $mform->addElement('text', 'ivariable', get_string('ivariable', 'aiscience'), ['size' => 50]);
        $mform->setType('ivariable', PARAM_TEXT);
        $mform->addElement('text', 'dvariable', get_string('dvariable', 'aiscience'), ['size' => 50]);
        $mform->setType('dvariable', PARAM_TEXT);

        $sources = [];
        foreach (['manual', 'photo', 'databot', 'vernier', 'pasco', 'microbit'] as $s) {
            $sources[] = $mform->createElement('advcheckbox', $s, '', get_string('source_' . $s, 'aiscience'));
        }
        $mform->addGroup($sources, 'datasources', get_string('datasources', 'aiscience'), '<br>', true);
        $mform->addHelpButton('datasources', 'datasources', 'aiscience');
        $mform->setDefault('datasources[manual]', 1);

        $mform->addElement('text', 'equipment', get_string('equipment', 'aiscience'), ['size' => 60]);
        $mform->setType('equipment', PARAM_TEXT);
        $mform->addElement('text', 'safety', get_string('safety', 'aiscience'), ['size' => 60]);
        $mform->setType('safety', PARAM_TEXT);

        $supports = [];
        foreach (['starters', 'eald', 'extension'] as $s) {
            $supports[] = $mform->createElement('advcheckbox', $s, '', get_string('support_' . $s, 'aiscience'));
        }
        $mform->addGroup($supports, 'supports', get_string('supports', 'aiscience'), '<br>', true);
        $mform->setDefault('supports[starters]', 1);

        $mform->addElement('textarea', 'extra', get_string('extra', 'aiscience'), ['rows' => 2, 'cols' => 60]);
        $mform->setType('extra', PARAM_TEXT);

        // Class data and AI help.
        $mform->addElement('header', 'classheader', get_string('classsettings', 'aiscience'));
        $mform->addElement('advcheckbox', 'pooldata', get_string('pooldata', 'aiscience'));
        $mform->setDefault('pooldata', 1);
        $mform->addElement('advcheckbox', 'aisuggest', get_string('aisuggest', 'aiscience'));
        $mform->setDefault('aisuggest', 1);
        $mform->addHelpButton('aisuggest', 'aisuggest', 'aiscience');

        $this->standard_grading_coursemodule_elements();
        $mform->setDefault('grade', 12);
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Prepare stored values for the form.
     *
     * @param array $defaultvalues
     */
    public function data_preprocessing(&$defaultvalues) {
        foreach (['datasources', 'supports'] as $multi) {
            if (isset($defaultvalues[$multi]) && is_string($defaultvalues[$multi])) {
                $list = array_filter(explode(',', $defaultvalues[$multi]));
                $defaultvalues[$multi] = array_fill_keys($list, 1);
            }
        }
        $draftitemid = file_get_submitted_draft_itemid('curriculumfile');
        file_prepare_draft_area(
            $draftitemid,
            $this->context ? $this->context->id : null,
            'mod_aiscience',
            'curriculum',
            0,
            ['subdirs' => 0, 'maxfiles' => 3]
        );
        $defaultvalues['curriculumfile'] = $draftitemid;
    }

    /**
     * Validation: need a question or a library start.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (empty($data['librarykey']) && trim($data['question'] ?? '') === '') {
            $errors['question'] = get_string('questionrequired', 'aiscience');
        }
        return $errors;
    }

    /**
     * Custom completion rules.
     *
     * @return array
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $suffix = $this->get_suffix();
        $mform->addElement('advcheckbox', 'completionsubmit' . $suffix, '', get_string('completionsubmit', 'aiscience'));
        $mform->addElement('advcheckbox', 'completiondata' . $suffix, '', get_string('completiondata', 'aiscience'));
        return ['completionsubmit' . $suffix, 'completiondata' . $suffix];
    }

    /**
     * Whether a custom completion rule is on.
     *
     * @param array $data
     * @return bool
     */
    public function completion_rule_enabled($data) {
        $suffix = $this->get_suffix();
        return !empty($data['completionsubmit' . $suffix]) || !empty($data['completiondata' . $suffix]);
    }
}
