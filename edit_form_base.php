<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Base class for editing question types like this one.
 *
 * @package    qtype
 * @subpackage gapselect
 * @copyright  2011 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();


/**
 * Elements embedded in question text editing form definition.
 *
 * @copyright  2011 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qtype_gapselect_edit_form_base extends question_edit_form {
    const MAX_GROUPS = 8;

    /** @var array of HTML tags allowed in choices / drag boxes. */
    protected $allowedhtmltags = array(
        'sub',
        'sup',
        'b',
        'i',
        'em',
        'strong'
    );

    /** @var string regex to match HTML open tags. */
    private $htmltstarttagsandattributes = '/<\s*\w.*?>/';

    /** @var string regex to match HTML close tags or br. */
    private $htmltclosetags = '~<\s*/\s*\w\s*.*?>|<\s*br\s*>~';

    /** @var string regex to select text like [[cat]] (including the square brackets). */
    private $squarebracketsregex = '/\[\[[^]]*?\]\]/';

    private function get_html_tags($text) {
        $textarray = array();
        foreach ($this->allowedhtmltags as $htmltag) {
            $tagpair = "/<\s*\/?\s*$htmltag\s*.*?>/";
            preg_match_all($tagpair, $text, $textarray);
            if ($textarray[0]) {
                return $textarray[0];
            }
        }

        preg_match_all($this->htmltstarttagsandattributes, $text, $textarray);
        if ($textarray[0]) {
            $tag = htmlspecialchars($textarray[0][0]);
            $allowedtaglist = $this->get_list_of_printable_allowed_tags($this->allowedhtmltags);
            return $tag . ' is not allowed (only ' . $allowedtaglist .
                    ' and corresponsing closing tags are allowed)';
        }

        preg_match_all($this->htmltclosetags, $text, $textarray);
        if ($textarray[0]) {
            $tag = htmlspecialchars($textarray[0][0]);
            $allowedtaglist=$this->get_list_of_printable_allowed_tags($this->allowedhtmltags);
            return $tag . ' is not allowed (only ' . $allowedtaglist .
                    ' and corresponsing closing tags are allowed)';
        }

        return false;
    }

    private function get_list_of_printable_allowed_tags($allowedhtmltags) {
        $allowedtaglist = null;
        foreach ($allowedhtmltags as $htmltag) {
            $allowedtaglist .= htmlspecialchars('<'.$htmltag.'>') . ', ';
        }
        return $allowedtaglist;
    }

    /**
     * definition_inner adds all specific fields to the form.
     * @param object $mform (the form being built).
     */
    protected function definition_inner($mform) {
        global $CFG;

        //add the answer (choice) fields to the form
        $this->definition_answer_choice($mform);

        $this->add_combined_feedback_fields(true);
        $this->add_interactive_settings(true, true);
    }

    protected function definition_answer_choice(&$mform) {
        $mform->addElement('header', 'choicehdr', get_string('choices', 'qtype_gapselect'));

        $mform->addElement('checkbox', 'shuffleanswers', get_string('shuffle', 'qtype_gapselect'));
        $mform->setDefault('shuffleanswers', 0);

        $textboxgroup = array();
        $textboxgroup[] = $mform->createElement('group', 'choices',
                get_string('choicex', 'qtype_gapselect'), $this->choice_group($mform));

        if (isset($this->question->options)) {
            $countanswers = count($this->question->options->answers);
        } else {
            $countanswers = 0;
        }

        if ($this->question->formoptions->repeatelements) {
            $defaultstartnumbers = QUESTION_NUMANS_START * 2;
            $repeatsatstart = max($defaultstartnumbers, QUESTION_NUMANS_START,
                    $countanswers + QUESTION_NUMANS_ADD);
        } else {
            $repeatsatstart = $countanswers;
        }

        $repeatedoptions = $this->repeated_options();
        $mform->setType('answer', PARAM_RAW);
        $this->repeat_elements($textboxgroup, $repeatsatstart, $repeatedoptions,
                'noanswers', 'addanswers', QUESTION_NUMANS_ADD,
                get_string('addmorechoiceblanks', 'qtype_gapselect'));
    }

    protected function choice_group($mform) {
        $options = array();
        for ($i = 1; $i <= self::MAX_GROUPS; $i += 1) {
            $options[$i] = $i;
        }
        $grouparray = array();
        $grouparray[] = $mform->createElement('text', 'answer',
                get_string('answer', 'qtype_gapselect'), array('size'=>30, 'class'=>'tweakcss'));
        $grouparray[] = $mform->createElement('static', '', '', ' ' .
                get_string('group', 'qtype_gapselect').' ');
        $grouparray[] = $mform->createElement('select', 'choicegroup',
                get_string('group', 'qtype_gapselect'), $options);
        return $grouparray;
    }

    protected function repeated_options() {
        $repeatedoptions = array();
        $repeatedoptions['choicegroup']['default'] = '1';
        return $repeatedoptions;
    }

    public function data_preprocessing($question) {
        $question = parent::data_preprocessing($question);
        $question = $this->data_preprocessing_combined_feedback($question, true);
        $question = $this->data_preprocessing_hints($question, true, true);

        $question = $this->data_preprocessing_answers($question, true);
        if (!empty($question->options->answers)) {
            $key = 0;
            foreach ($question->options->answers as $answer) {
                $question = $this->data_preprocessing_choice($question, $answer, $key);
                $key++;
            }
        }

        if (!empty($question->options)) {
            $question->shuffleanswers = $question->options->shuffleanswers;
        }

        return $question;
    }

    protected function data_preprocessing_choice($question, $answer, $key) {
        // See comment in data_preprocessing_answers.
        unset($this->_form->_defaultValues['choices[$key][choicegroup]']);
        $question->choices[$key]['answer'] = $answer->answer;
        $question->choices[$key]['choicegroup'] = $answer->feedback;
        return $question;
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $questiontext = $data['questiontext'];
        $choices = $data['choices'];

        //check the whether the slots are valid
        $errorsinquestiontext = $this->validate_slots($questiontext['text'], $choices);
        if ($errorsinquestiontext) {
            $errors['questiontext'] = $errorsinquestiontext;
        }
        foreach ($choices as $key => $choice) {
            $answer = $choice['answer'];

            //check whether the html-tags are allowed tags
            $validtags = $this->get_html_tags($answer);
            if (is_array($validtags)) {
                continue;
            }
            if ($validtags) {
                $errors['choices['.$key.']'] = $validtags;
            }
        }
        return $errors;
    }

    private function validate_slots($questiontext, $choices) {
        $error = 'Please check the Question text: ';
        if (!$questiontext) {
            return get_string('errorquestiontextblank', 'qtype_gapselect');
        }

        $matches = array();
        preg_match_all($this->squarebracketsregex, $questiontext, $matches);
        $slots = $matches[0];

        if (!$slots) {
            return get_string('errornoslots', 'qtype_gapselect');
        }

        $cleanedslots = array();
        foreach ($slots as $slot) {
            // The 2 is for'[[' and 4 is for '[[]]'.
            $cleanedslots[] = substr($slot, 2, (strlen($slot)-4));
        }
        $slots = $cleanedslots;

        $found = false;
        foreach ($slots as $slot) {
            $found = false;
            foreach ($choices as $key => $choice) {
                if ($slot == $key + 1) {
                    if ($choice['answer'] === '') {
                        return get_string('errorblankchoice', 'qtype_gapselect',
                                html_writer::tag('b', $slot));
                    }
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return get_string('errormissingchoice', 'qtype_gapselect',
                        html_writer::tag('b', $slot));
            }
        }
        return false;
    }

    public function qtype() {
        return '';
    }
}