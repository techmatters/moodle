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
 * Defines the \mod_quiz\structure class.
 *
 * @package   mod_quiz
 * @copyright 2013 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quiz;
use mod_quiz\question\bank\qbank_helper;
use mod_quiz\question\qubaids_for_quiz;

/**
 * Quiz structure class.
 *
 * The structure of the quiz. That is, which questions it is built up
 * from. This is used on the Edit quiz page (edit.php) and also when
 * starting an attempt at the quiz (startattempt.php). Once an attempt
 * has been started, then the attempt holds the specific set of questions
 * that that student should answer, and we no longer use this class.
 *
 * @copyright 2014 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class structure {
    /** @var \mod_quiz\quiz_settings the quiz this is the structure of. */
    protected $quizobj = null;

    /**
     * @var \stdClass[] the questions in this quiz. Contains the row from the questions
     * table, with the data from the quiz_slots table added, and also question_categories.contextid.
     */
    protected $questions = array();

    /** @var \stdClass[] quiz_slots.slot => the quiz_slots rows for this quiz, agumented by sectionid. */
    protected $slotsinorder = array();

    /**
     * @var \stdClass[] currently a dummy. Holds data that will match the
     * quiz_sections, once it exists.
     */
    protected $sections = array();

    /** @var bool caches the results of can_be_edited. */
    protected $canbeedited = null;

    /** @var bool caches the results of can_add_random_question. */
    protected $canaddrandom = null;

    /** @var bool tracks whether tags have been loaded */
    protected $hasloadedtags = false;

    /**
     * @var \stdClass[] the tags for slots. Indexed by slot id.
     */
    protected $slottags = array();

    /**
     * Create an instance of this class representing an empty quiz.
     *
     * @return structure
     */
    public static function create() {
        return new self();
    }

    /**
     * Create an instance of this class representing the structure of a given quiz.
     *
     * @param \mod_quiz\quiz_settings $quizobj the quiz.
     * @return structure
     */
    public static function create_for_quiz($quizobj) {
        $structure = self::create();
        $structure->quizobj = $quizobj;
        $structure->populate_structure();
        return $structure;
    }

    /**
     * Whether there are any questions in the quiz.
     *
     * @return bool true if there is at least one question in the quiz.
     */
    public function has_questions() {
        return !empty($this->questions);
    }

    /**
     * Get the number of questions in the quiz.
     *
     * @return int the number of questions in the quiz.
     */
    public function get_question_count() {
        return count($this->questions);
    }

    /**
     * Get the information about the question with this id.
     *
     * @param int $questionid The question id.
     * @return \stdClass the data from the questions table, augmented with
     * question_category.contextid, and the quiz_slots data for the question in this quiz.
     */
    public function get_question_by_id($questionid) {
        return $this->questions[$questionid];
    }

    /**
     * Get the information about the question in a given slot.
     *
     * @param int $slotnumber the index of the slot in question.
     * @return \stdClass the data from the questions table, augmented with
     * question_category.contextid, and the quiz_slots data for the question in this quiz.
     */
    public function get_question_in_slot($slotnumber) {
        return $this->questions[$this->slotsinorder[$slotnumber]->questionid];
    }

    /**
     * Get the information about the question name in a given slot.
     *
     * @param int $slotnumber the index of the slot in question.
     * @return \stdClass the data from the questions table, augmented with
     */
    public function get_question_name_in_slot($slotnumber) {
        return $this->questions[$this->slotsinorder[$slotnumber]->name];
    }

    /**
     * Get the displayed question number (or 'i') for a given slot.
     *
     * @param int $slotnumber the index of the slot in question.
     * @return string the question number ot display for this slot.
     */
    public function get_displayed_number_for_slot($slotnumber) {
        return $this->slotsinorder[$slotnumber]->displayednumber;
    }

    /**
     * Check whether the question number can be customised.
     *
     * @param int $slotnumber
     * @return bool
     */
    public function can_display_number_be_customised(int $slotnumber): bool {
        if (!$this->is_real_question($slotnumber)) {
            return false;
        }
        $slot = $this->get_slot_by_number($slotnumber);
        if ($slot->section->shufflequestions) {
            return false;
        }
        if (quiz_has_attempts($this->quizobj->get_quizid())) {
            return false;
        }
        return true;
    }

    /**
     * Check whether the question number is customised.
     * @param int $slotid
     * @return bool
     */
    public function is_display_number_customised(int $slotid): bool {
        $slotobj = $this->get_slot_by_id($slotid);
        return $slotobj->displayednumber === $slotobj->displaynumber;
    }

    /**
     * Make slot display number in place editable api call.

     * @param int $slotid
     * @param \context $context
     * @return \core\output\inplace_editable
     */
    public function make_slot_display_number_in_place_editable(int $slotid, \context $context): \core\output\inplace_editable {
        // Check permission of the user to update this item (customise question number).
        $editable = has_capability('mod/quiz:manage', $context);

        $this->populate_structure();
        $slot = $this->get_slot_by_id($slotid);

        // Whether the displaynumber field in quiz_slots table is set and it is not empty or null.
        if ($this->is_display_number_customised($slotid)) {
            $displayvalue = format_string($slot->displaynumber);
            $value = $slot->displaynumber;
        } else {
            $displayednumber = $this->get_displayed_number_for_slot($slot->slot);
            $displayvalue = format_string($displayednumber);
            $value = $displayednumber;
        }
        return new \core\output\inplace_editable('mod_quiz', 'slotdisplaynumber', $slotid,
                $editable, $displayvalue, $value,
                get_string('edit_slotdisplaynumber_hint', 'mod_quiz'),
                get_string('edit_slotdisplaynumber_label', 'mod_quiz', $displayvalue));
    }

    /**
     * Get the page a given slot is on.
     *
     * @param int $slotnumber the index of the slot in question.
     * @return int the page number of the page that slot is on.
     */
    public function get_page_number_for_slot($slotnumber) {
        return $this->slotsinorder[$slotnumber]->page;
    }

    /**
     * Get the slot id of a given slot slot.
     *
     * @param int $slotnumber the index of the slot in question.
     * @return int the page number of the page that slot is on.
     */
    public function get_slot_id_for_slot($slotnumber) {
        return $this->slotsinorder[$slotnumber]->id;
    }

    /**
     * Get the question type in a given slot.
     *
     * @param int $slotnumber the index of the slot in question.
     * @return string the question type (e.g. multichoice).
     */
    public function get_question_type_for_slot($slotnumber) {
        return $this->questions[$this->slotsinorder[$slotnumber]->questionid]->qtype;
    }

    /**
     * Whether it would be possible, given the question types, etc. for the
     * question in the given slot to require that the previous question had been
     * answered before this one is displayed.
     *
     * @param int $slotnumber the index of the slot in question.
     * @return bool can this question require the previous one.
     */
    public function can_question_depend_on_previous_slot($slotnumber) {
        return $slotnumber > 1 && $this->can_finish_during_the_attempt($slotnumber - 1);
    }

    /**
     * Whether it is possible for another question to depend on this one finishing.
     * Note that the answer is not exact, because of random questions, and sometimes
     * questions cannot be depended upon because of quiz options.
     *
     * @param int $slotnumber the index of the slot in question.
     * @return bool can this question finish naturally during the attempt?
     */
    public function can_finish_during_the_attempt($slotnumber) {
        if ($this->quizobj->get_navigation_method() == QUIZ_NAVMETHOD_SEQ) {
            return false;
        }

        if ($this->slotsinorder[$slotnumber]->section->shufflequestions) {
            return false;
        }

        if (in_array($this->get_question_type_for_slot($slotnumber), array('random', 'missingtype'))) {
            return \question_engine::can_questions_finish_during_the_attempt(
                    $this->quizobj->get_quiz()->preferredbehaviour);
        }

        if (isset($this->slotsinorder[$slotnumber]->canfinish)) {
            return $this->slotsinorder[$slotnumber]->canfinish;
        }

        try {
            $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $this->quizobj->get_context());
            $tempslot = $quba->add_question(\question_bank::load_question(
                    $this->slotsinorder[$slotnumber]->questionid));
            $quba->set_preferred_behaviour($this->quizobj->get_quiz()->preferredbehaviour);
            $quba->start_all_questions();

            $this->slotsinorder[$slotnumber]->canfinish = $quba->can_question_finish_during_attempt($tempslot);
            return $this->slotsinorder[$slotnumber]->canfinish;
        } catch (\Exception $e) {
            // If the question fails to start, this should not block editing.
            return false;
        }
    }

    /**
     * Whether it would be possible, given the question types, etc. for the
     * question in the given slot to require that the previous question had been
     * answered before this one is displayed.
     *
     * @param int $slotnumber the index of the slot in question.
     * @return bool can this question require the previous one.
     */
    public function is_question_dependent_on_previous_slot($slotnumber) {
        return $this->slotsinorder[$slotnumber]->requireprevious;
    }

    /**
     * Is a particular question in this attempt a real question, or something like a description.
     *
     * @param int $slotnumber the index of the slot in question.
     * @return bool whether that question is a real question.
     */
    public function is_real_question($slotnumber) {
        return $this->get_question_in_slot($slotnumber)->length != 0;
    }

    /**
     * Does the current user have '...use' capability over the question(s) in a given slot?
     *
     *
     * @param int $slotnumber the index of the slot in question.
     * @return bool true if they have the required capability.
     */
    public function has_use_capability(int $slotnumber): bool {
        $slot = $this->slotsinorder[$slotnumber];
        if (is_numeric($slot->questionid)) {
            // Non-random question.
            return question_has_capability_on($this->get_question_by_id($slot->questionid), 'use');
        } else {
            // Random question.
            $context = \context::instance_by_id($slot->contextid);
            return has_capability('moodle/question:useall', $context);
        }
    }

    /**
     * Get the course id that the quiz belongs to.
     *
     * @return int the course.id for the quiz.
     */
    public function get_courseid() {
        return $this->quizobj->get_courseid();
    }

    /**
     * Get the course module id of the quiz.
     *
     * @return int the course_modules.id for the quiz.
     */
    public function get_cmid() {
        return $this->quizobj->get_cmid();
    }

    /**
     * Get id of the quiz.
     *
     * @return int the quiz.id for the quiz.
     */
    public function get_quizid() {
        return $this->quizobj->get_quizid();
    }

    /**
     * Get the quiz object.
     *
     * @return \stdClass the quiz settings row from the database.
     */
    public function get_quiz() {
        return $this->quizobj->get_quiz();
    }

    /**
     * Quizzes can only be repaginated if they have not been attempted, the
     * questions are not shuffled, and there are two or more questions.
     *
     * @return bool whether this quiz can be repaginated.
     */
    public function can_be_repaginated() {
        return $this->can_be_edited() && $this->get_question_count() >= 2;
    }

    /**
     * Quizzes can only be edited if they have not been attempted.
     *
     * @return bool whether the quiz can be edited.
     */
    public function can_be_edited() {
        if ($this->canbeedited === null) {
            $this->canbeedited = !quiz_has_attempts($this->quizobj->get_quizid());
        }
        return $this->canbeedited;
    }

    /**
     * This quiz can only be edited if they have not been attempted.
     * Throw an exception if this is not the case.
     */
    public function check_can_be_edited() {
        if (!$this->can_be_edited()) {
            $reportlink = quiz_attempt_summary_link_to_reports($this->get_quiz(),
                    $this->quizobj->get_cm(), $this->quizobj->get_context());
            throw new \moodle_exception('cannoteditafterattempts', 'quiz',
                    new \moodle_url('/mod/quiz/edit.php', array('cmid' => $this->get_cmid())), $reportlink);
        }
    }

    /**
     * How many questions are allowed per page in the quiz.
     * This setting controls how frequently extra page-breaks should be inserted
     * automatically when questions are added to the quiz.
     *
     * @return int the number of questions that should be on each page of the
     * quiz by default.
     */
    public function get_questions_per_page() {
        return $this->quizobj->get_quiz()->questionsperpage;
    }

    /**
     * Get quiz slots.
     *
     * @return \stdClass[] the slots in this quiz.
     */
    public function get_slots() {
        return array_column($this->slotsinorder, null, 'id');
    }

    /**
     * Is this slot the first one on its page?
     *
     * @param int $slotnumber the index of the slot in question.
     * @return bool whether this slot the first one on its page.
     */
    public function is_first_slot_on_page($slotnumber) {
        if ($slotnumber == 1) {
            return true;
        }
        return $this->slotsinorder[$slotnumber]->page != $this->slotsinorder[$slotnumber - 1]->page;
    }

    /**
     * Is this slot the last one on its page?
     *
     * @param int $slotnumber the index of the slot in question.
     * @return bool whether this slot the last one on its page.
     */
    public function is_last_slot_on_page($slotnumber) {
        if (!isset($this->slotsinorder[$slotnumber + 1])) {
            return true;
        }
        return $this->slotsinorder[$slotnumber]->page != $this->slotsinorder[$slotnumber + 1]->page;
    }

    /**
     * Is this slot the last one in its section?
     *
     * @param int $slotnumber the index of the slot in question.
     * @return bool whether this slot the last one on its section.
     */
    public function is_last_slot_in_section($slotnumber) {
        return $slotnumber == $this->slotsinorder[$slotnumber]->section->lastslot;
    }

    /**
     * Is this slot the only one in its section?
     *
     * @param int $slotnumber the index of the slot in question.
     * @return bool whether this slot the only one on its section.
     */
    public function is_only_slot_in_section($slotnumber) {
        return $this->slotsinorder[$slotnumber]->section->firstslot ==
                $this->slotsinorder[$slotnumber]->section->lastslot;
    }

    /**
     * Is this slot the last one in the quiz?
     *
     * @param int $slotnumber the index of the slot in question.
     * @return bool whether this slot the last one in the quiz.
     */
    public function is_last_slot_in_quiz($slotnumber) {
        end($this->slotsinorder);
        return $slotnumber == key($this->slotsinorder);
    }

    /**
     * Is this the first section in the quiz?
     *
     * @param \stdClass $section the quiz_sections row.
     * @return bool whether this is first section in the quiz.
     */
    public function is_first_section($section) {
        return $section->firstslot == 1;
    }

    /**
     * Is this the last section in the quiz?
     *
     * @param \stdClass $section the quiz_sections row.
     * @return bool whether this is first section in the quiz.
     */
    public function is_last_section($section) {
        return $section->id == end($this->sections)->id;
    }

    /**
     * Does this section only contain one slot?
     *
     * @param \stdClass $section the quiz_sections row.
     * @return bool whether this section contains only one slot.
     */
    public function is_only_one_slot_in_section($section) {
        return $section->firstslot == $section->lastslot;
    }

    /**
     * Get the final slot in the quiz.
     *
     * @return \stdClass the quiz_slots for for the final slot in the quiz.
     */
    public function get_last_slot() {
        return end($this->slotsinorder);
    }

    /**
     * Get a slot by it's id. Throws an exception if it is missing.
     *
     * @param int $slotid the slot id.
     * @return \stdClass the requested quiz_slots row.
     * @throws \coding_exception
     */
    public function get_slot_by_id($slotid) {
        foreach ($this->slotsinorder as $slot) {
            if ($slot->id == $slotid) {
                return $slot;
            }
        }

        throw new \coding_exception('The \'slotid\' could not be found.');
    }

    /**
     * Get a slot by it's slot number. Throws an exception if it is missing.
     *
     * @param int $slotnumber The slot number
     * @return \stdClass
     * @throws \coding_exception
     */
    public function get_slot_by_number($slotnumber) {
        if (!array_key_exists($slotnumber, $this->slotsinorder)) {
            throw new \coding_exception('The \'slotnumber\' could not be found.');
        }
        return $this->slotsinorder[$slotnumber];
    }

    /**
     * Check whether adding a section heading is possible
     *
     * @param int $pagenumber the number of the page.
     * @return boolean
     */
    public function can_add_section_heading($pagenumber) {
        // There is a default section heading on this page,
        // do not show adding new section heading in the Add menu.
        if ($pagenumber == 1) {
            return false;
        }
        // Get an array of firstslots.
        $firstslots = array();
        foreach ($this->sections as $section) {
            $firstslots[] = $section->firstslot;
        }
        foreach ($this->slotsinorder as $slot) {
            if ($slot->page == $pagenumber) {
                if (in_array($slot->slot, $firstslots)) {
                    return false;
                }
            }
        }
        // Do not show the adding section heading on the last add menu.
        if ($pagenumber == 0) {
            return false;
        }
        return true;
    }

    /**
     * Get all the slots in a section of the quiz.
     *
     * @param int $sectionid the section id.
     * @return int[] slot numbers.
     */
    public function get_slots_in_section($sectionid) {
        $slots = array();
        foreach ($this->slotsinorder as $slot) {
            if ($slot->section->id == $sectionid) {
                $slots[] = $slot->slot;
            }
        }
        return $slots;
    }

    /**
     * Get all the sections of the quiz.
     *
     * @return \stdClass[] the sections in this quiz.
     */
    public function get_sections() {
        return $this->sections;
    }

    /**
     * Get a particular section by id.
     *
     * @return \stdClass the section.
     */
    public function get_section_by_id($sectionid) {
        return $this->sections[$sectionid];
    }

    /**
     * Get the number of questions in the quiz.
     *
     * @return int the number of questions in the quiz.
     */
    public function get_section_count() {
        return count($this->sections);
    }

    /**
     * Get the overall quiz grade formatted for display.
     *
     * @return string the maximum grade for this quiz.
     */
    public function formatted_quiz_grade() {
        return quiz_format_grade($this->get_quiz(), $this->get_quiz()->grade);
    }

    /**
     * Get the maximum mark for a question, formatted for display.
     *
     * @param int $slotnumber the index of the slot in question.
     * @return string the maximum mark for the question in this slot.
     */
    public function formatted_question_grade($slotnumber) {
        return quiz_format_question_grade($this->get_quiz(), $this->slotsinorder[$slotnumber]->maxmark);
    }

    /**
     * Get the number of decimal places for displyaing overall quiz grades or marks.
     *
     * @return int the number of decimal places.
     */
    public function get_decimal_places_for_grades() {
        return $this->get_quiz()->decimalpoints;
    }

    /**
     * Get the number of decimal places for displyaing question marks.
     *
     * @return int the number of decimal places.
     */
    public function get_decimal_places_for_question_marks() {
        return quiz_get_grade_format($this->get_quiz());
    }

    /**
     * Get any warnings to show at the top of the edit page.
     * @return string[] array of strings.
     */
    public function get_edit_page_warnings() {
        $warnings = array();

        if (quiz_has_attempts($this->quizobj->get_quizid())) {
            $reviewlink = quiz_attempt_summary_link_to_reports($this->quizobj->get_quiz(),
                    $this->quizobj->get_cm(), $this->quizobj->get_context());
            $warnings[] = get_string('cannoteditafterattempts', 'quiz', $reviewlink);
        }

        return $warnings;
    }

    /**
     * Get the date information about the current state of the quiz.
     * @return string[] array of two strings. First a short summary, then a longer
     * explanation of the current state, e.g. for a tool-tip.
     */
    public function get_dates_summary() {
        $timenow = time();
        $quiz = $this->quizobj->get_quiz();

        // Exact open and close dates for the tool-tip.
        $dates = array();
        if ($quiz->timeopen > 0) {
            if ($timenow > $quiz->timeopen) {
                $dates[] = get_string('quizopenedon', 'quiz', userdate($quiz->timeopen));
            } else {
                $dates[] = get_string('quizwillopen', 'quiz', userdate($quiz->timeopen));
            }
        }
        if ($quiz->timeclose > 0) {
            if ($timenow > $quiz->timeclose) {
                $dates[] = get_string('quizclosed', 'quiz', userdate($quiz->timeclose));
            } else {
                $dates[] = get_string('quizcloseson', 'quiz', userdate($quiz->timeclose));
            }
        }
        if (empty($dates)) {
            $dates[] = get_string('alwaysavailable', 'quiz');
        }
        $explanation = implode(', ', $dates);

        // Brief summary on the page.
        if ($timenow < $quiz->timeopen) {
            $currentstatus = get_string('quizisclosedwillopen', 'quiz',
                    userdate($quiz->timeopen, get_string('strftimedatetimeshort', 'langconfig')));
        } else if ($quiz->timeclose && $timenow <= $quiz->timeclose) {
            $currentstatus = get_string('quizisopenwillclose', 'quiz',
                    userdate($quiz->timeclose, get_string('strftimedatetimeshort', 'langconfig')));
        } else if ($quiz->timeclose && $timenow > $quiz->timeclose) {
            $currentstatus = get_string('quizisclosed', 'quiz');
        } else {
            $currentstatus = get_string('quizisopen', 'quiz');
        }

        return array($currentstatus, $explanation);
    }

    /**
     * Set up this class with the structure for a given quiz.
     */
    protected function populate_structure() {
        global $DB;

        $slots = qbank_helper::get_question_structure($this->quizobj->get_quizid(), $this->quizobj->get_context());

        $this->questions = [];
        $this->slotsinorder = [];
        foreach ($slots as $slotdata) {
            $this->questions[$slotdata->questionid] = $slotdata;

            $slot = clone($slotdata);
            $slot->quizid = $this->quizobj->get_quizid();
            $this->slotsinorder[$slot->slot] = $slot;
        }

        // Get quiz sections in ascending order of the firstslot.
        $this->sections = $DB->get_records('quiz_sections', ['quizid' => $this->quizobj->get_quizid()], 'firstslot');
        $this->populate_slots_with_sections();
        $this->populate_question_numbers();
    }

    /**
     * Fill in the section ids for each slot.
     */
    public function populate_slots_with_sections() {
        $sections = array_values($this->sections);
        foreach ($sections as $i => $section) {
            if (isset($sections[$i + 1])) {
                $section->lastslot = $sections[$i + 1]->firstslot - 1;
            } else {
                $section->lastslot = count($this->slotsinorder);
            }
            for ($slot = $section->firstslot; $slot <= $section->lastslot; $slot += 1) {
                $this->slotsinorder[$slot]->section = $section;
                if ($section->shufflequestions) {
                    // Hide customised value and disable editing while shuffle checkbox is enabled.
                    $this->slotsinorder[$slot]->displaynumber = null;
                }
            }
        }
    }

    /**
     * Number the questions.
     */
    protected function populate_question_numbers() {
        $number = 1;
        foreach ($this->slotsinorder as $slot) {
            if ($this->questions[$slot->questionid]->length == 0) {
                $slot->displayednumber = get_string('infoshort', 'quiz');
            } else {
                // Whether question numbering is customised or is numeric and automatically incremented.
                if (!empty($slot->displaynumber)) {
                    $slot->displayednumber = $slot->displaynumber;
                } else {
                    $slot->displayednumber = $number;
                }
                $number += 1;
            }
        }
    }

    /**
     * Get the version options to show on the Questions page for a particular question.
     *
     * @param int $slotnumber which slot to get the choices for.
     * @return \stdClass[] other versions of this question. Each object has fields versionid,
     *       version and selected. Array is returned most recent version first.
     */
    public function get_version_choices_for_slot(int $slotnumber): array {
        $slot = $this->get_slot_by_number($slotnumber);

        // Get all the versions which exist.
        $versions = qbank_helper::get_version_options($slot->questionid);
        $latestversion = reset($versions);

        // Format the choices for display.
        $versionoptions = [];
        foreach ($versions as $version) {
            $version->selected = $version->version === $slot->requestedversion;

            if ($version->version === $latestversion->version) {
                $version->versionvalue = get_string('questionversionlatest', 'quiz', $version->version);
            } else {
                $version->versionvalue = get_string('questionversion', 'quiz', $version->version);
            }

            $versionoptions[] = $version;
        }

        // Make a choice for 'Always latest'.
        $alwaysuselatest = new \stdClass();
        $alwaysuselatest->versionid = 0;
        $alwaysuselatest->version = 0;
        $alwaysuselatest->versionvalue = get_string('alwayslatest', 'quiz');
        $alwaysuselatest->selected = $slot->requestedversion === null;
        array_unshift($versionoptions, $alwaysuselatest);

        return $versionoptions;
    }

    /**
     * Move a slot from its current location to a new location.
     *
     * After callig this method, this class will be in an invalid state, and
     * should be discarded if you want to manipulate the structure further.
     *
     * @param int $idmove id of slot to be moved
     * @param int $idmoveafter id of slot to come before slot being moved
     * @param int $page new page number of slot being moved
     * @param bool $insection if the question is moving to a place where a new
     *      section starts, include it in that section.
     * @return void
     */
    public function move_slot($idmove, $idmoveafter, $page) {
        global $DB;

        $this->check_can_be_edited();

        $movingslot = $this->get_slot_by_id($idmove);
        if (empty($movingslot)) {
            throw new \moodle_exception('Bad slot ID ' . $idmove);
        }
        $movingslotnumber = (int) $movingslot->slot;

        // Empty target slot means move slot to first.
        if (empty($idmoveafter)) {
            $moveafterslotnumber = 0;
        } else {
            $moveafterslotnumber = (int) $this->get_slot_by_id($idmoveafter)->slot;
        }

        // If the action came in as moving a slot to itself, normalise this to
        // moving the slot to after the previous slot.
        if ($moveafterslotnumber == $movingslotnumber) {
            $moveafterslotnumber = $moveafterslotnumber - 1;
        }

        $followingslotnumber = $moveafterslotnumber + 1;
        // Prevent checking against non-existance slot when already at the last slot.
        if ($followingslotnumber == $movingslotnumber && !$this->is_last_slot_in_quiz($followingslotnumber)) {
            $followingslotnumber += 1;
        }

        // Check the target page number is OK.
        if ($page == 0 || $page === '') {
            $page = 1;
        }
        if (($moveafterslotnumber > 0 && $page < $this->get_page_number_for_slot($moveafterslotnumber)) ||
                $page < 1) {
            throw new \coding_exception('The target page number is too small.');
        } else if (!$this->is_last_slot_in_quiz($moveafterslotnumber) &&
                $page > $this->get_page_number_for_slot($followingslotnumber)) {
            throw new \coding_exception('The target page number is too large.');
        }

        // Work out how things are being moved.
        $slotreorder = array();
        if ($moveafterslotnumber > $movingslotnumber) {
            // Moving down.
            $slotreorder[$movingslotnumber] = $moveafterslotnumber;
            for ($i = $movingslotnumber; $i < $moveafterslotnumber; $i++) {
                $slotreorder[$i + 1] = $i;
            }

            $headingmoveafter = $movingslotnumber;
            if ($this->is_last_slot_in_quiz($moveafterslotnumber) ||
                    $page == $this->get_page_number_for_slot($moveafterslotnumber + 1)) {
                // We are moving to the start of a section, so that heading needs
                // to be included in the ones that move up.
                $headingmovebefore = $moveafterslotnumber + 1;
            } else {
                $headingmovebefore = $moveafterslotnumber;
            }
            $headingmovedirection = -1;

        } else if ($moveafterslotnumber < $movingslotnumber - 1) {
            // Moving up.
            $slotreorder[$movingslotnumber] = $moveafterslotnumber + 1;
            for ($i = $moveafterslotnumber + 1; $i < $movingslotnumber; $i++) {
                $slotreorder[$i] = $i + 1;
            }

            if ($page == $this->get_page_number_for_slot($moveafterslotnumber + 1)) {
                // Moving to the start of a section, don't move that section.
                $headingmoveafter = $moveafterslotnumber + 1;
            } else {
                // Moving tot the end of the previous section, so move the heading down too.
                $headingmoveafter = $moveafterslotnumber;
            }
            $headingmovebefore = $movingslotnumber + 1;
            $headingmovedirection = 1;
        } else {
            // Staying in the same place, but possibly changing page/section.
            if ($page > $movingslot->page) {
                $headingmoveafter = $movingslotnumber;
                $headingmovebefore = $movingslotnumber + 2;
                $headingmovedirection = -1;
            } else if ($page < $movingslot->page) {
                $headingmoveafter = $movingslotnumber - 1;
                $headingmovebefore = $movingslotnumber + 1;
                $headingmovedirection = 1;
            } else {
                return; // Nothing to do.
            }
        }

        if ($this->is_only_slot_in_section($movingslotnumber)) {
            throw new \coding_exception('You cannot remove the last slot in a section.');
        }

        $trans = $DB->start_delegated_transaction();

        // Slot has moved record new order.
        if ($slotreorder) {
            update_field_with_unique_index('quiz_slots', 'slot', $slotreorder,
                    array('quizid' => $this->get_quizid()));
        }

        // Page has changed. Record it.
        if ($movingslot->page != $page) {
            $DB->set_field('quiz_slots', 'page', $page,
                    array('id' => $movingslot->id));
        }

        // Update section fist slots.
        quiz_update_section_firstslots($this->get_quizid(), $headingmovedirection,
                $headingmoveafter, $headingmovebefore);

        // If any pages are now empty, remove them.
        $emptypages = $DB->get_fieldset_sql("
                SELECT DISTINCT page - 1
                  FROM {quiz_slots} slot
                 WHERE quizid = ?
                   AND page > 1
                   AND NOT EXISTS (SELECT 1 FROM {quiz_slots} WHERE quizid = ? AND page = slot.page - 1)
              ORDER BY page - 1 DESC
                ", array($this->get_quizid(), $this->get_quizid()));

        foreach ($emptypages as $emptypage) {
            $DB->execute("
                    UPDATE {quiz_slots}
                       SET page = page - 1
                     WHERE quizid = ?
                       AND page > ?
                    ", array($this->get_quizid(), $emptypage));
        }

        $trans->allow_commit();

        // Log slot moved event.
        $event = \mod_quiz\event\slot_moved::create([
            'context' => $this->quizobj->get_context(),
            'objectid' => $idmove,
            'other' => [
                'quizid' => $this->quizobj->get_quizid(),
                'previousslotnumber' => $movingslotnumber,
                'afterslotnumber' => $moveafterslotnumber,
                'page' => $page
             ]
        ]);
        $event->trigger();
    }

    /**
     * Refresh page numbering of quiz slots.
     * @param \stdClass[] $slots (optional) array of slot objects.
     * @return \stdClass[] array of slot objects.
     */
    public function refresh_page_numbers($slots = array()) {
        global $DB;
        // Get slots ordered by page then slot.
        if (!count($slots)) {
            $slots = $DB->get_records('quiz_slots', array('quizid' => $this->get_quizid()), 'slot, page');
        }

        // Loop slots. Start Page number at 1 and increment as required.
        $pagenumbers = array('new' => 0, 'old' => 0);

        foreach ($slots as $slot) {
            if ($slot->page !== $pagenumbers['old']) {
                $pagenumbers['old'] = $slot->page;
                ++$pagenumbers['new'];
            }

            if ($pagenumbers['new'] == $slot->page) {
                continue;
            }
            $slot->page = $pagenumbers['new'];
        }

        return $slots;
    }

    /**
     * Refresh page numbering of quiz slots and save to the database.
     * @param \stdClass $quiz the quiz object.
     * @return \stdClass[] array of slot objects.
     */
    public function refresh_page_numbers_and_update_db() {
        global $DB;
        $this->check_can_be_edited();

        $slots = $this->refresh_page_numbers();

        // Record new page order.
        foreach ($slots as $slot) {
            $DB->set_field('quiz_slots', 'page', $slot->page,
                    array('id' => $slot->id));
        }

        return $slots;
    }

    /**
     * Remove a slot from a quiz
     *
     * @param int $slotnumber The number of the slot to be deleted.
     * @throws \coding_exception
     */
    public function remove_slot($slotnumber) {
        global $DB;

        $this->check_can_be_edited();

        if ($this->is_only_slot_in_section($slotnumber) && $this->get_section_count() > 1) {
            throw new \coding_exception('You cannot remove the last slot in a section.');
        }

        $slot = $DB->get_record('quiz_slots', array('quizid' => $this->get_quizid(), 'slot' => $slotnumber));
        if (!$slot) {
            return;
        }
        $maxslot = $DB->get_field_sql('SELECT MAX(slot) FROM {quiz_slots} WHERE quizid = ?', array($this->get_quizid()));

        $trans = $DB->start_delegated_transaction();
        // Delete the reference if its a question.
        $questionreference = $DB->get_record('question_references',
                ['component' => 'mod_quiz', 'questionarea' => 'slot', 'itemid' => $slot->id]);
        if ($questionreference) {
            $DB->delete_records('question_references', ['id' => $questionreference->id]);
        }
        // Delete the set reference if its a random question.
        $questionsetreference = $DB->get_record('question_set_references',
                ['component' => 'mod_quiz', 'questionarea' => 'slot', 'itemid' => $slot->id]);
        if ($questionsetreference) {
            $DB->delete_records('question_set_references',
                ['id' => $questionsetreference->id, 'component' => 'mod_quiz', 'questionarea' => 'slot']);
        }
        $DB->delete_records('quiz_slots', array('id' => $slot->id));
        for ($i = $slot->slot + 1; $i <= $maxslot; $i++) {
            $DB->set_field('quiz_slots', 'slot', $i - 1,
                    array('quizid' => $this->get_quizid(), 'slot' => $i));
            $this->slotsinorder[$i]->slot = $i - 1;
            $this->slotsinorder[$i - 1] = $this->slotsinorder[$i];
            unset($this->slotsinorder[$i]);
        }

        quiz_update_section_firstslots($this->get_quizid(), -1, $slotnumber);
        foreach ($this->sections as $key => $section) {
            if ($section->firstslot > $slotnumber) {
                $this->sections[$key]->firstslot--;
            }
        }
        $this->populate_slots_with_sections();
        $this->populate_question_numbers();
        $this->unset_question($slot->id);

        $this->refresh_page_numbers_and_update_db();

        $trans->allow_commit();

        // Log slot deleted event.
        $event = \mod_quiz\event\slot_deleted::create([
            'context' => $this->quizobj->get_context(),
            'objectid' => $slot->id,
            'other' => [
                'quizid' => $this->get_quizid(),
                'slotnumber' => $slotnumber,
            ]
        ]);
        $event->trigger();
    }

    /**
     * Unset the question object after deletion.
     *
     * @param int $slotid
     */
    public function unset_question($slotid) {
        foreach ($this->questions as $key => $question) {
            if ($question->slotid === $slotid) {
                unset($this->questions[$key]);
            }
        }
    }

    /**
     * Change the max mark for a slot.
     *
     * Saves changes to the question grades in the quiz_slots table and any
     * corresponding question_attempts.
     * It does not update 'sumgrades' in the quiz table.
     *
     * @param \stdClass $slot row from the quiz_slots table.
     * @param float $maxmark the new maxmark.
     * @return bool true if the new grade is different from the old one.
     */
    public function update_slot_maxmark($slot, $maxmark) {
        global $DB;

        if (abs($maxmark - $slot->maxmark) < 1e-7) {
            // Grade has not changed. Nothing to do.
            return false;
        }

        $trans = $DB->start_delegated_transaction();
        $previousmaxmark = $slot->maxmark;
        $slot->maxmark = $maxmark;
        $DB->update_record('quiz_slots', $slot);
        \question_engine::set_max_mark_in_attempts(new qubaids_for_quiz($slot->quizid),
                $slot->slot, $maxmark);
        $trans->allow_commit();

        // Log slot mark updated event.
        // We use $num + 0 as a trick to remove the useless 0 digits from decimals.
        $event = \mod_quiz\event\slot_mark_updated::create([
            'context' => $this->quizobj->get_context(),
            'objectid' => $slot->id,
            'other' => [
                'quizid' => $this->get_quizid(),
                'previousmaxmark' => $previousmaxmark + 0,
                'newmaxmark' => $maxmark + 0
            ]
        ]);
        $event->trigger();

        return true;
    }

    /**
     * Set whether the question in a particular slot requires the previous one.
     * @param int $slotid id of slot.
     * @param bool $requireprevious if true, set this question to require the previous one.
     */
    public function update_question_dependency($slotid, $requireprevious) {
        global $DB;
        $DB->set_field('quiz_slots', 'requireprevious', $requireprevious, array('id' => $slotid));

        // Log slot require previous event.
        $event = \mod_quiz\event\slot_requireprevious_updated::create([
            'context' => $this->quizobj->get_context(),
            'objectid' => $slotid,
            'other' => [
                'quizid' => $this->get_quizid(),
                'requireprevious' => $requireprevious ? 1 : 0
            ]
        ]);
        $event->trigger();
    }

    /**
     * Update the question display number when is set as customised display number or empy string.
     * When the field displaynumber is set to empty string, the automated numbering is used.
     * Log the updated displatnumber field.
     *
     * @param int $slotid id of slot.
     * @param string $displaynumber set to customised string as question number or empty string fo autonumbering.
     */
    public function update_slot_display_number(int $slotid, string $displaynumber): void {
        global $DB;
        $DB->set_field('quiz_slots', 'displaynumber', $displaynumber, ['id' => $slotid]);
        $this->populate_structure();

        // Log slot displaynumber event (customised question number).
        $event = \mod_quiz\event\slot_displaynumber_updated::create([
                'context' => $this->quizobj->get_context(),
                'objectid' => $slotid,
                'other' => [
                        'quizid' => $this->get_quizid(),
                        'displaynumber' => $displaynumber
                ]
        ]);
        $event->trigger();
    }

    /**
     * Add/Remove a pagebreak.
     *
     * Saves changes to the slot page relationship in the quiz_slots table and reorders the paging
     * for subsequent slots.
     *
     * @param int $slotid id of slot which we will add/remove the page break before.
     * @param int $type repaginate::LINK or repaginate::UNLINK.
     * @return \stdClass[] array of slot objects.
     */
    public function update_page_break($slotid, $type) {
        global $DB;

        $this->check_can_be_edited();

        $quizslots = $DB->get_records('quiz_slots', array('quizid' => $this->get_quizid()), 'slot');
        $repaginate = new \mod_quiz\repaginate($this->get_quizid(), $quizslots);
        $repaginate->repaginate_slots($quizslots[$slotid]->slot, $type);
        $slots = $this->refresh_page_numbers_and_update_db();

        if ($type == repaginate::LINK) {
            // Log page break created event.
            $event = \mod_quiz\event\page_break_deleted::create([
                'context' => $this->quizobj->get_context(),
                'objectid' => $slotid,
                'other' => [
                    'quizid' => $this->get_quizid(),
                    'slotnumber' => $quizslots[$slotid]->slot
                ]
            ]);
            $event->trigger();
        } else {
            // Log page deleted created event.
            $event = \mod_quiz\event\page_break_created::create([
                'context' => $this->quizobj->get_context(),
                'objectid' => $slotid,
                'other' => [
                    'quizid' => $this->get_quizid(),
                    'slotnumber' => $quizslots[$slotid]->slot
                ]
            ]);
            $event->trigger();
        }

        return $slots;
    }

    /**
     * Add a section heading on a given page and return the sectionid
     * @param int $pagenumber the number of the page where the section heading begins.
     * @param string|null $heading the heading to add. If not given, a default is used.
     */
    public function add_section_heading($pagenumber, $heading = null) {
        global $DB;
        $section = new \stdClass();
        if ($heading !== null) {
            $section->heading = $heading;
        } else {
            $section->heading = get_string('newsectionheading', 'quiz');
        }
        $section->quizid = $this->get_quizid();
        $slotsonpage = $DB->get_records('quiz_slots', array('quizid' => $this->get_quizid(), 'page' => $pagenumber), 'slot DESC');
        $firstslot = end($slotsonpage);
        $section->firstslot = $firstslot->slot;
        $section->shufflequestions = 0;
        $sectionid = $DB->insert_record('quiz_sections', $section);

        // Log section break created event.
        $event = \mod_quiz\event\section_break_created::create([
            'context' => $this->quizobj->get_context(),
            'objectid' => $sectionid,
            'other' => [
                'quizid' => $this->get_quizid(),
                'firstslotnumber' => $firstslot->slot,
                'firstslotid' => $firstslot->id,
                'title' => $section->heading,
            ]
        ]);
        $event->trigger();

        return $sectionid;
    }

    /**
     * Change the heading for a section.
     * @param int $id the id of the section to change.
     * @param string $newheading the new heading for this section.
     */
    public function set_section_heading($id, $newheading) {
        global $DB;
        $section = $DB->get_record('quiz_sections', array('id' => $id), '*', MUST_EXIST);
        $section->heading = $newheading;
        $DB->update_record('quiz_sections', $section);

        // Log section title updated event.
        $firstslot = $DB->get_record('quiz_slots', array('quizid' => $this->get_quizid(), 'slot' => $section->firstslot));
        $event = \mod_quiz\event\section_title_updated::create([
            'context' => $this->quizobj->get_context(),
            'objectid' => $id,
            'other' => [
                'quizid' => $this->get_quizid(),
                'firstslotid' => $firstslot ? $firstslot->id : null,
                'firstslotnumber' => $firstslot ? $firstslot->slot : null,
                'newtitle' => $newheading
            ]
        ]);
        $event->trigger();
    }

    /**
     * Change the shuffle setting for a section.
     * @param int $id the id of the section to change.
     * @param bool $shuffle whether this section should be shuffled.
     */
    public function set_section_shuffle($id, $shuffle) {
        global $DB;
        $section = $DB->get_record('quiz_sections', array('id' => $id), '*', MUST_EXIST);
        $section->shufflequestions = $shuffle;
        $DB->update_record('quiz_sections', $section);

        // Log section shuffle updated event.
        $firstslot = $DB->get_record('quiz_slots', array('quizid' => $this->get_quizid(), 'slot' => $section->firstslot));
        $event = \mod_quiz\event\section_shuffle_updated::create([
            'context' => $this->quizobj->get_context(),
            'objectid' => $id,
            'other' => [
                'quizid' => $this->get_quizid(),
                'firstslotid' => $firstslot->id,
                'firstslotnumber' => $firstslot->slot,
                'shuffle' => $shuffle
            ]
        ]);
        $event->trigger();
    }

    /**
     * Remove the section heading with the given id
     * @param int $sectionid the section to remove.
     */
    public function remove_section_heading($sectionid) {
        global $DB;
        $section = $DB->get_record('quiz_sections', array('id' => $sectionid), '*', MUST_EXIST);
        if ($section->firstslot == 1) {
            throw new \coding_exception('Cannot remove the first section in a quiz.');
        }
        $DB->delete_records('quiz_sections', array('id' => $sectionid));

        // Log page deleted created event.
        $firstslot = $DB->get_record('quiz_slots', array('quizid' => $this->get_quizid(), 'slot' => $section->firstslot));
        $event = \mod_quiz\event\section_break_deleted::create([
            'context' => $this->quizobj->get_context(),
            'objectid' => $sectionid,
            'other' => [
                'quizid' => $this->get_quizid(),
                'firstslotid' => $firstslot->id,
                'firstslotnumber' => $firstslot->slot
            ]
        ]);
        $event->trigger();
    }

    /**
     * Whether the current user can add random questions to the quiz or not.
     * It is only possible to add a random question if the user has the moodle/question:useall capability
     * on at least one of the contexts related to the one where we are currently editing questions.
     *
     * @return bool
     */
    public function can_add_random_questions() {
        if ($this->canaddrandom === null) {
            $quizcontext = $this->quizobj->get_context();
            $relatedcontexts = new \core_question\local\bank\question_edit_contexts($quizcontext);
            $usablecontexts = $relatedcontexts->having_cap('moodle/question:useall');

            $this->canaddrandom = !empty($usablecontexts);
        }

        return $this->canaddrandom;
    }


    /**
     * Retrieve the list of slot tags for the given slot id.
     *
     * @param  int $slotid The id for the slot
     * @return \stdClass[] The list of slot tag records
     * @deprecated since Moodle 4.0 MDL-71573
     * @todo Final deprecation on Moodle 4.4 MDL-72438
     */
    public function get_slot_tags_for_slot_id($slotid) {
        debugging('Function get_slot_tags_for_slot_id() has been deprecated and the structure
         for this method have been moved to filtercondition in question_set_reference table, please
          use the new structure instead.', DEBUG_DEVELOPER);
        // All the associated code for this method have been removed to get rid of accidental call or errors.
        return [];
    }
}
