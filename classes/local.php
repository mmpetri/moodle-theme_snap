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


namespace theme_snap;

use html_writer;

require_once($CFG->dirroot.'/calendar/lib.php');
require_once($CFG->libdir.'/completionlib.php');
require_once($CFG->libdir.'/coursecatlib.php');
require_once($CFG->dirroot.'/grade/lib.php');
require_once($CFG->dirroot.'/grade/report/user/lib.php');
require_once($CFG->dirroot.'/mod/forum/lib.php');

/**
 * General local snap functions.
 *
 * Added to a class purely for the convenience of auto loading.
 *
 * @package   theme_snap
 * @copyright Copyright (c) 2015 Moodlerooms Inc. (http://www.moodlerooms.com)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local {


    /**
     * If debugging enabled in config then return reason for no grade (useful for json output).
     *
     * @param $warning
     * @return null|object
     */
    public static function skipgradewarning($warning) {
        global $CFG;
        if (!empty($CFG->debugdisplay)) {
            return (object) array ('skipgrade' => $warning);
        } else {
            return null;
        }
    }

    /**
     * Is there a valid grade or feedback inside this grader report table item?
     *
     * @param $item
     * @return bool
     */
    public static function item_has_grade_or_feedback($item) {
        $typekeys = array ('grade', 'feedback');
        foreach ($typekeys as $typekey) {
            if (!empty($item[$typekey]['content'])) {
                // Set grade content to null string if it contents - or a blank space.
                $item[$typekey]['content'] = str_ireplace(array('-', '&nbsp;'), '', $item[$typekey]['content']);
            }
            // Is there an error message in the content (can't check on message as it is localized,
            // so check on the class for gradingerror.
            if (!empty($item[$typekey]['content'])
                && stripos($item[$typekey]['class'], 'gradingerror') === false
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Does this course have any visible feedback for current user?.
     *
     * @param $course
     * @return stdClass | null
     */
    public static function course_feedback($course) {
        global $USER;
        // Get course context.
        $coursecontext = \context_course::instance($course->id);
        // Security check - should they be allowed to see course grade?
        $onlyactive = true;
        if (!is_enrolled($coursecontext, $USER, 'moodle/grade:view', $onlyactive)) {
            return self::skipgradewarning('User not enrolled on course with capability moodle/grade:view');
        }
        // Security check - are they allowed to see the grade report for the course?
        if (!has_capability('gradereport/user:view', $coursecontext)) {
            return self::skipgradewarning('User does not have required course capability gradereport/user:view');
        }
        // See if user can view hidden grades for this course.
        $canviewhidden = has_capability('moodle/grade:viewhidden', $coursecontext);
        // Do not show grade if grade book disabled for students.
        // Note - moodle/grade:viewall is a capability held by teachers and thus used to exclude them from not getting
        // the grade.
        if (empty($course->showgrades) && !has_capability('moodle/grade:viewall', $coursecontext)) {
            return self::skipgradewarning('Course set up to not show gradebook to students');
        }
        // Get course grade_item.
        $courseitem = \grade_item::fetch_course_item($course->id);
        // Get the stored grade.
        $coursegrade = new \grade_grade(array('itemid' => $courseitem->id, 'userid' => $USER->id));
        $coursegrade->grade_item =& $courseitem;
        // Return null if can't view.
        if ($coursegrade->is_hidden() && !$canviewhidden) {
            return self::skipgradewarning('Course grade is hidden from students');
        }
        // Use user grade report to get course total - this is to take hidden grade settings into account.
        $gpr = new \grade_plugin_return(array(
                'type' => 'report',
                'plugin' => 'user',
                'courseid' => $course->id,
                'userid' => $USER->id)
        );
        $report = new \grade_report_user($course->id, $gpr, $coursecontext, $USER->id);
        $report->fill_table();
        $visiblegradefound = false;
        foreach ($report->tabledata as $item) {
            if (self::item_has_grade_or_feedback($item)) {
                $visiblegradefound = true;
                break;
            }
        }
        $feedbackhtml = '';
        if ($visiblegradefound) {
            // Just output - feedback available.
            $url = new \moodle_url('/grade/report/user/index.php', array('id' => $course->id));
            $feedbackstring = get_string('feedbackavailable', 'theme_snap');
            $feedbackhtml = \html_writer::link($url,
                $feedbackstring,
                array('class' => 'coursegrade')
            );
        }
        return (object) array('feedbackhtml' => $feedbackhtml);
    }

    /**
     * Get course completion progress for specific course.
     * NOTE: It is by design that even teachers get course completion progress, this is so that they see exactly the
     * same as a student would in the personal menu.
     *
     * @param $course
     * @return stdClass | null
     */
    public static function course_completion_progress($course) {
        if (!isloggedin() || isguestuser()) {
            return null; // Can't get completion progress for users who aren't logged in.
        }

        // Security check - are they enrolled on course.
        $context = \context_course::instance($course->id);
        if (!is_enrolled($context, null, '', true)) {
            return null;
        }
        $completioninfo = new \completion_info($course);
        $trackcount = 0;
        $compcount = 0;
        if ($completioninfo->is_enabled()) {
            $modinfo = get_fast_modinfo($course);

            foreach ($modinfo->cms as $thismod) {
                if (!$thismod->uservisible) {
                    // Skip when mod is not user visible.
                    continue;
                }
                $completioninfo->get_data($thismod, true);

                if ($completioninfo->is_enabled($thismod) != COMPLETION_TRACKING_NONE) {
                    $trackcount++;
                    $completiondata = $completioninfo->get_data($thismod, true);
                    if ($completiondata->completionstate == COMPLETION_COMPLETE ||
                        $completiondata->completionstate == COMPLETION_COMPLETE_PASS) {
                        $compcount++;
                    }
                }
            }
        }

        $compobj = (object) array('complete' => $compcount, 'total' => $trackcount, 'progresshtml' => '');
        if ($trackcount > 0) {
            $progress = get_string('progresstotal', 'completion', $compobj);
            // TODO - we should be putting our HTML in a renderer.
            $progresspercent = ceil(($compcount/$trackcount)*100);
            $progressinfo = '<div class="completionstatus outoftotal">'.$progress.'<span class="pull-right">'.$progresspercent.'%</span></div>
            <div class="completion-line" style="width:'.$progresspercent.'%"></div>
            ';
            $compobj->progresshtml = $progressinfo;
        }

        return $compobj;
    }

    /**
     * Get information for array of courseids
     *
     * @param $courseids
     * @return bool | array
     */
    public static function courseinfo($courseids) {
        global $DB;
        $courseinfo = array();
        foreach ($courseids as $courseid) {
            $course = $DB->get_record('course', array('id' => $courseid));

            $context = \context_course::instance($courseid);
            if (!is_enrolled($context, null, '', true)) {
                // Skip this course, don't have permission to view.
                continue;
            }

            $courseinfo[$courseid] = (object) array(
                'courseid' => $courseid,
                'progress' => self::course_completion_progress($course),
                'feedback' => self::course_feedback($course)
            );
        }
        return $courseinfo;
    }

    /**
     * Get total participant count for specific courseid.
     *
     * @param $courseid
     * @param $modname the name of the module, used to build a capability check
     * @return int
     */
    public static function course_participant_count($courseid, $modname = null) {
        static $participantcount = array();

        // Incorporate the modname in the static cache index.
        $idx = $courseid . $modname;

        if (!isset($participantcount[$idx])) {
            // Use the modname to determine the best capability.
            switch ($modname) {
                case 'assign':
                    $capability = 'mod/assign:submit';
                    break;
                case 'quiz':
                    $capability = 'mod/quiz:attempt';
                    break;
                case 'choice':
                    $capability = 'mod/choice:choose';
                    break;
                case 'feedback':
                    $capability = 'mod/feedback:complete';
                    break;
                default:
                    // If no modname is specified, assume a count of all users is required
                    $capability = '';
            }

            $context = \context_course::instance($courseid);
            $onlyactive = true;
            $enrolled = count_enrolled_users($context, $capability, null, $onlyactive);
            $participantcount[$idx] = $enrolled;
        }

        return $participantcount[$idx];
    }

    /**
     * Get a user's messages read and unread.
     *
     * @param int $userid
     * @return message[]
     */

    public static function get_user_messages($userid) {
        global $DB;

        $select  = 'm.id, m.useridfrom, m.useridto, m.subject, m.fullmessage, m.fullmessageformat, m.fullmessagehtml, '.
                   'm.smallmessage, m.timecreated, m.notification, m.contexturl, m.contexturlname, '.
                   \user_picture::fields('u', null, 'useridfrom', 'fromuser');

        $records = $DB->get_records_sql("
        (
                SELECT $select, 1 unread
                  FROM {message} m
            INNER JOIN {user} u ON u.id = m.useridfrom
                 WHERE m.useridto = ?
                       AND contexturl IS NULL
        ) UNION ALL (
                SELECT $select, 0 unread
                  FROM {message_read} m
            INNER JOIN {user} u ON u.id = m.useridfrom
                 WHERE m.useridto = ?
                       AND contexturl IS NULL
        )
          ORDER BY timecreated DESC
        ", array($userid, $userid), 0, 5);

        $messages = array();
        foreach ($records as $record) {
            $message = new message($record);
            $message->set_fromuser(\user_picture::unalias($record, null, 'useridfrom', 'fromuser'));

            $messages[] = $message;
        }
        return $messages;
    }

    /**
     * Get message html for current user
     * TODO: This should not be in here - HTML does not belong in this file!
     *
     * @return string
     */
    public static function messages() {
        global $USER, $PAGE;

        $messages = self::get_user_messages($USER->id);
        if (empty($messages)) {
            return '<p>' . get_string('nomessages', 'theme_snap') . '</p>';
        }

        $output = $PAGE->get_renderer('theme_snap', 'core', RENDERER_TARGET_GENERAL);
        $o = '';
        foreach ($messages as $message) {
            $url = new \moodle_url('/message/index.php', array(
                'history' => 0,
                'user1' => $message->useridto,
                'user2' => $message->useridfrom,
            ));

            $fromuser = $message->get_fromuser();
            $userpicture = new \user_picture($fromuser);
            $userpicture->link = false;
            $userpicture->alttext = false;
            $userpicture->size = 100;
            $frompicture = $output->render($userpicture);

            $fromname = format_string(fullname($fromuser));

            $meta = self::relative_time($message->timecreated);
            $unreadclass = '';
            if ($message->unread) {
                $unreadclass = ' snap-unread';
                $meta .= " <span class=snap-unread-marker>".get_string('unread', 'theme_snap')."</span>";
            }

            $info = '<p>'.format_string($message->smallmessage).'</p>';

            $o .= $output->snap_media_object($url, $frompicture, $fromname, $meta, $info, $unreadclass);
        }
        return $o;
    }

    /**
     * Return friendly relative time (e.g. "1 min ago", "1 year ago") in a <time> tag
     * @return string
     */
    public static function relative_time($timeinpast, $relativeto = null) {
        if ($relativeto === null) {
            $relativeto = time();
        }
        $secondsago = $relativeto - $timeinpast;
        $secondsago = self::simpler_time($secondsago);

        $relativetext = format_time($secondsago);
        if ($secondsago != 0) {
            $relativetext = get_string('ago', 'message', $relativetext);
        }
        $datetime = date(\DateTime::W3C, $timeinpast);
        return html_writer::tag('time', $relativetext, array(
            'is' => 'relative-time',
            'datetime' => $datetime)
        );
    }

    /**
     * Reduce the precision of the time e.g. 1 min 10 secs ago -> 1 min ago
     * @return int
     */
    public static function simpler_time($seconds) {
        if ($seconds > 59) {
            return intval(round($seconds / 60)) * 60;
        } else {
            return $seconds;
        }
    }


    /**
     * Return user's upcoming deadlines from the calendar.
     *
     * All deadlines from today, then any from the next 12 months up to the
     * max requested.
     * @param integer $userid
     * @param integer $maxdeadlines
     * @return array
     */
    public static function upcoming_deadlines($userid, $maxdeadlines = 5) {

        $courses = enrol_get_all_users_courses($userid);

        if (empty($courses)) {
            return array();
        }

        $courseids = array_keys($courses);

        $events = self::get_todays_deadlines($courseids);

        if (count($events) < $maxdeadlines) {
            $maxaftercurrentday = $maxdeadlines - count($events);
            $moreevents = self::get_upcoming_deadlines($courseids, $maxaftercurrentday);
            $events = $events + $moreevents;
        }
        foreach ($events as $event) {
            if (isset($courses[$event->courseid])) {
                $course = $courses[$event->courseid];
                $event->coursefullname = $course->fullname;
            }
        }
        return $events;
    }

    /**
     * Return user's deadlines for today from the calendar.
     *
     * @param array $courses ids of all user's courses.
     * @return array
     */
    private static function get_todays_deadlines($courses) {
        // Get all deadlines for today, assume that will never be higher than 100.
        return self::get_upcoming_deadlines($courses, 100, true);
    }

    /**
     * Return user's deadlines from the calendar.
     *
     * Usually called twice, once for all deadlines from today, then any from the next 12 months up to the
     * max requested.
     *
     * Based on the calender function calendar_get_upcoming.
     *
     * @param array $courses ids of all user's courses.
     * @param int $maxevents to return
     * @param bool $todayonly true if only the next 24 hours to be returned
     * @return array
     */
    private static function get_upcoming_deadlines($courses, $maxevents, $todayonly=false) {

        $now = time();

        if ($todayonly === true) {
            $starttime = usergetmidnight($now);
            $daysinfuture = 1;
        } else {
            $starttime = usergetmidnight($now + DAYSECS + 3 * HOURSECS); // Avoid rare DST change issues.
            $daysinfuture = 365;
        }

        $endtime = $starttime + ($daysinfuture * DAYSECS) - 1;

        $userevents = false;
        $groupevents = false;
        $events = calendar_get_events($starttime, $endtime, $userevents, $groupevents, $courses);

        $processed = 0;
        $output = array();
        foreach ($events as $event) {
            if ($event->eventtype === 'course') {
                // Not an activity deadline.
                continue;
            }
            if (!empty($event->modulename)) {
                $modinfo = get_fast_modinfo($event->courseid);
                $mods = $modinfo->get_instances_of($event->modulename);
                if (isset($mods[$event->instance])) {
                    $cminfo = $mods[$event->instance];
                    if (!$cminfo->uservisible) {
                        continue;
                    }
                }
            }

            $output[$event->id] = $event;
            ++$processed;

            if ($processed >= $maxevents) {
                break;
            }
        }

        return $output;
    }




    public static function deadlines() {
        global $USER, $PAGE;

        $events = self::upcoming_deadlines($USER->id);
        if (empty($events)) {
            return '<p>' . get_string('nodeadlines', 'theme_snap') . '</p>';
        }

        $output = $PAGE->get_renderer('theme_snap', 'core', RENDERER_TARGET_GENERAL);
        $o = '';
        foreach ($events as $event) {
            if (!empty($event->modulename)) {
                $modinfo = get_fast_modinfo($event->courseid);
                $cm = $modinfo->instances[$event->modulename][$event->instance];

                $eventtitle = "<small>$event->coursefullname / </small> $event->name";

                $modimageurl = $output->pix_url('icon', $event->modulename);
                $modname = get_string('modulename', $event->modulename);
                $modimage = \html_writer::img($modimageurl, $modname);

                $meta = $output->friendly_datetime($event->timestart);

                $o .= $output->snap_media_object($cm->url, $modimage, $eventtitle, $meta, '');
            }
        }
        return $o;
    }

    public static function graded() {
        global $USER, $PAGE;

        $output = $PAGE->get_renderer('theme_snap', 'core', RENDERER_TARGET_GENERAL);
        $grades = activity::events_graded();

        $o = '';
        foreach ($grades as $grade) {

            $modinfo = get_fast_modinfo($grade->courseid);
            $course = $modinfo->get_course();

            $modtype = $grade->itemmodule;
            $cm = $modinfo->instances[$modtype][$grade->iteminstance];

            $coursecontext = \context_course::instance($grade->courseid);
            $canviewhiddengrade = has_capability('moodle/grade:viewhidden', $coursecontext);

            $url = new \moodle_url('/grade/report/user/index.php', ['id' => $grade->courseid]);
            if (in_array($modtype, ['quiz', 'assign'])
                && (!empty($grade->rawgrade) || !empty($grade->feedback))
            ) {
                // Only use the course module url if the activity was graded in the module, not in the gradebook, etc.
                $url = $cm->url;
            }

            $modimageurl = $output->pix_url('icon', $cm->modname);
            $modname = get_string('modulename', 'mod_'.$cm->modname);
            $modimage = \html_writer::img($modimageurl, $modname);

            $gradetitle = "<small>$course->fullname / </small> $cm->name";

            $releasedon = isset($grade->timemodified) ? $grade->timemodified : $grade->timecreated;
            $meta = get_string('released', 'theme_snap', $output->friendly_datetime($releasedon));

            $grade = new \grade_grade(array('itemid' => $grade->itemid, 'userid' => $USER->id));
            if (!$grade->is_hidden() || $canviewhiddengrade) {
                $o .= $output->snap_media_object($url, $modimage, $gradetitle, $meta, '');
            }
        }

        if (empty($o)) {
            return '<p>'. get_string('nograded', 'theme_snap') . '</p>';
        }
        return $o;
    }

    public static function grading() {
        global $USER, $PAGE;

        $grading = self::all_ungraded($USER->id);

        if (empty($grading)) {
            return '<p>' . get_string('nograding', 'theme_snap') . '</p>';
        }

        $output = $PAGE->get_renderer('theme_snap', 'core', RENDERER_TARGET_GENERAL);
        $out = '';
        foreach ($grading as $ungraded) {
            $modinfo = get_fast_modinfo($ungraded->course);
            $course = $modinfo->get_course();
            $cm = $modinfo->get_cm($ungraded->coursemoduleid);

            $modimageurl = $output->pix_url('icon', $cm->modname);
            $modname = get_string('modulename', 'mod_'.$cm->modname);
            $modimage = \html_writer::img($modimageurl, $modname);

            $ungradedtitle = "<small>$course->fullname / </small> $cm->name";

            $xungraded = get_string('xungraded', 'theme_snap', $ungraded->ungraded);

            $function = '\theme_snap\activity::'.$cm->modname.'_num_submissions';

            $a['completed'] = call_user_func($function, $ungraded->course, $ungraded->instanceid);
            $a['participants'] = (self::course_participant_count($ungraded->course, $cm->modname));
            $xofysubmitted = get_string('xofysubmitted', 'theme_snap', $a);
            $info = '<span class="label label-info">'.$xofysubmitted.', '.$xungraded.'</span>';

            $meta = '';
            if (!empty($ungraded->closetime)) {
                $meta = $output->friendly_datetime($ungraded->closetime);
            }

            $out .= $output->snap_media_object($cm->url, $modimage, $ungradedtitle, $meta, $info);
        }

        return $out;
    }

    public static function all_ungraded($userid) {

        $courses = enrol_get_all_users_courses($userid);

        $capability = 'gradereport/grader:view';
        foreach ($courses as $course) {
            if (has_capability($capability, \context_course::instance($course->id), $userid)) {
                $courseids[] = $course->id;
            }
        }
        if (empty($courseids)) {
            return array();
        }

        $mods = \core_plugin_manager::instance()->get_installed_plugins('mod');
        $mods = array_keys($mods);

        $grading = [];
        foreach ($mods as $mod) {
            $class = '\theme_snap\activity';
            $method = $mod.'_ungraded';
            if (method_exists($class, $method)) {
                $grading = array_merge($grading, call_user_func([$class, $method], $courseids));
            }
        }

        usort($grading, array('self', 'sort_graded'));

        return $grading;
    }

    /**
     * Sort function for ungraded items in the teachers personal menu.
     *
     * Compare on closetime, but fall back to openening time if not present.
     * Finally, sort by unique coursemodule id when the dates match.
     *
     * @return int
     */
    public static function sort_graded($left, $right) {
        if (empty($left->closetime)) {
            $lefttime = $left->opentime;
        } else {
            $lefttime = $left->closetime;
        }

        if (empty($right->closetime)) {
            $righttime = $right->opentime;
        } else {
            $righttime = $right->closetime;
        }

        if ($lefttime === $righttime) {
            if ($left->coursemoduleid === $right->coursemoduleid) {
                return 0;
            } else if ($left->coursemoduleid < $right->coursemoduleid) {
                return -1;
            } else {
                return 1;
            }
        } else if ($lefttime < $righttime) {
            return  -1;
        } else {
            return 1;
        }
    }

    /**
     * get hex color based on hash of course id
     *
     * @return string
     */
    public static function get_course_color($id) {
        return substr(md5($id), 0, 6);
    }

    public static function get_course_firstimage($courseid) {
        $fs      = get_file_storage();
        $context = \context_course::instance($courseid);
        $files   = $fs->get_area_files($context->id, 'course', 'overviewfiles', false, 'filename', false);

        if (count($files) > 0) {
            foreach ($files as $file) {
                if ($file->is_valid_image()) {
                    return $file;
                }
            }
        }

        return false;
    }



    /**
     * Extract first image from html
     *
     * @param string $html (must be well formed)
     * @return array | bool (false)
     */
    public static function extract_first_image($html) {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true); // Required for HTML5.
        $doc->loadHTML($html);
        libxml_clear_errors(); // Required for HTML5.
        $imagetags = $doc->getElementsByTagName('img');
        if ($imagetags->item(0)) {
            $src = $imagetags->item(0)->getAttribute('src');
            $alt = $imagetags->item(0)->getAttribute('alt');
            return array('src' => $src, 'alt' => $alt);
        } else {
            return false;
        }
    }


    /**
     * Make url based on file for theme_snap components only.
     *
     * @param stored_file $file
     * @return \moodle_url | bool
     */
    private static function snap_pluginfile_url($file) {
        if (!$file) {
            return false;
        } else {
            return \moodle_url::make_pluginfile_url(
                $file->get_contextid(),
                $file->get_component(),
                $file->get_filearea(),
                $file->get_timemodified(), // Used as a cache buster.
                $file->get_filepath(),
                $file->get_filename()
            );
        }
    }

    /**
     * Get cover image for context
     *
     * @param $context
     * @return bool|stored_file
     * @throws \coding_exception
     */
    public static function coverimage($context) {
        $contextid = $context->id;
        $fs = get_file_storage();

        $files = $fs->get_area_files($contextid, 'theme_snap', 'coverimage', 0, "itemid, filepath, filename", false);
        if (!$files) {
            return false;
        }
        if (count($files) > 1) {
            throw new \coding_exception('Multiple files found in course coverimage area (context '.$contextid.')');
        }
        return (end($files));
    }

    /**
     * Get processed course cover image.
     *
     * @param $courseid
     * @return stored_file|bool
     */
    public static function course_coverimage($courseid) {
        $context = \context_course::instance($courseid);
        return (self::coverimage($context));
    }

    /**
     * Get cover image url for course.
     *
     * @return bool|moodle_url
     */
    public static function course_coverimage_url($courseid) {
        $file = self::course_coverimage($courseid);
        if (!$file) {
            $file = self::process_coverimage(\context_course::instance($courseid));
        }
        return self::snap_pluginfile_url($file);
    }

    /**
     * Get processed site cover image.
     *
     * @return stored_file|bool
     */
    public static function site_coverimage() {
        $context = \context_system::instance();
        return (self::coverimage($context));
    }

    /**
     * Get cover image url for front page.
     *
     * @return bool|moodle_url
     */
    public static function site_coverimage_url() {
        $file = self::site_coverimage();
        return self::snap_pluginfile_url($file);
    }

    /**
     * Get original site cover image file.
     *
     * @return stored_file | bool (false)
     */
    public static function site_coverimage_original() {
        $theme = \theme_config::load('snap');
        $filename = $theme->settings->poster;
        if ($filename) {
            $syscontextid = \context_system::instance()->id;
            $fullpath = "/$syscontextid/theme_snap/poster/0$filename";
            $fs = get_file_storage();
            return $fs->get_file_by_hash(sha1($fullpath));
        } else {
            return false;
        }
    }


    /**
     * Adds the course cover image to CSS.
     *
     * @param int $courseid
     * @return string The parsed CSS
     */
    public static function course_coverimage_css($courseid) {
        $css = '';
        $coverurl = self::course_coverimage_url($courseid);
        if ($coverurl) {
            $css = "#page-header {background-image: url($coverurl);}";
        }
        return $css;
    }

    /**
     * Adds the site cover image to CSS.
     *
     * @param string $css The CSS to process.
     * @return string The parsed CSS
     */
    public static function site_coverimage_css($css) {
        $tag = '[[setting:poster]]';
        $replacement = '';

        $coverurl = self::site_coverimage_url();
        if ($coverurl) {
            $replacement = "#page-site-index #page-header, #page-login-index #page {background-image: url($coverurl);}";
        }

        $css = str_replace($tag, $replacement, $css);
        return $css;
    }

    /**
     * Copy coverimage file to standard location and name.
     *
     * @param stored_file $file
     * @return stored_file|bool
     */
    public static function process_coverimage($context) {
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            $originalfile = self::site_coverimage_original($context);
            $newfilename = "site-image";
        } else if ($context->contextlevel == CONTEXT_COURSE) {
            $originalfile = self::get_course_firstimage($context->instanceid);
            $newfilename = "course-image";
        } else {
            throw new \coding_exception('Invalid context passed to process_coverimage');
        }

        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'theme_snap', 'coverimage');

        if (!$originalfile) {
            return false;
        }

        $filename = $originalfile->get_filename();
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $newfilename .= '.'.$extension;

        $filespec = array(
            'contextid' => $context->id,
            'component' => 'theme_snap',
            'filearea' => 'coverimage',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $newfilename,
        );

        $newfile = $fs->create_file_from_storedfile($filespec, $originalfile);
        $finfo = $newfile->get_imageinfo();

        if ($finfo['mimetype'] == 'image/jpeg' && $finfo['width'] > 1380) {
            return image::resize($newfile, false, 1280);
        } else {
            return $newfile;
        }
    }

    /**
     * Get page module instance.
     *
     * @param $mod
     * @return mixed
     * @throws \dml_missing_record_exception
     * @throws \dml_multiple_records_exception
     */
    public static function get_page_mod($mod) {
        global $DB;

        $sql = "SELECT * FROM {course_modules} cm
                  JOIN {page} p ON p.id = cm.instance
                WHERE cm.id = ?";
        $page = $DB->get_record_sql($sql, array($mod->id));

        $context = \context_module::instance($mod->id);
        $formatoptions = new \stdClass;
        $formatoptions->noclean = true;
        $formatoptions->overflowdiv = true;
        $formatoptions->context = $context;

        // Make sure we have some summary/extract text for the course page.
        if (!empty($page->intro)) {
            $page->summary = file_rewrite_pluginfile_urls($page->intro,
                'pluginfile.php', $context->id, 'mod_page', 'intro', null);
            $page->summary = format_text($page->summary, $page->introformat, $formatoptions);
        } else {
            $preview = html_to_text($page->content, 0, false);
            $page->summary = shorten_text($preview, 200);
        }

        // Process content.
        $page->content = file_rewrite_pluginfile_urls($page->content,
            'pluginfile.php', $context->id, 'mod_page', 'content', $page->revision);
        $page->content = format_text($page->content, $page->contentformat, $formatoptions);

        return ($page);
    }

    /**
     * Moodle does not provide a helper function to generate limit sql (it's baked into get_records_sql).
     * This function is useful - e.g. improving performance of UNION statements.
     * Note, it will return empty strings for unsupported databases.
     *
     * @param int $from
     * @param int $to
     *
     * @return string
     */
    public static function limit_sql($from, $num) {
        global $DB;
        switch ($DB->get_dbfamily()) {
            case 'mysql' : $sql = "LIMIT $from, $num"; break;
            case 'postgres' : $sql = "LIMIT $num OFFSET $from"; break;
            case 'mssql' : $sql = ''; break; // Not supported.
            case 'oracle' : $sql = ''; break; // Not supported.
            default : $sql = ''; // Not supported.
        }
        return $sql;
    }

    /**
     * Get forumids where current user has accessallgroups capability
     */
    public static function forumids_accessallgroups($forums, $type = 'forum') {
        $forumidsallgroups = [];
        foreach ($forums as $forum) {
            $cm = get_coursemodule_from_instance($type, $forum->id);
            if (intval($cm->groupmode) === SEPARATEGROUPS) {
                $cm_context = \context_module::instance($cm->id);
                $allgroups = has_capability('moodle/site:accessallgroups', $cm_context);
                if ($allgroups) {
                    $forumidsallgroups[] = $forum->id;
                }
            }
        }
        return $forumidsallgroups;
    }

    /**
     * Get user by id.
     * @param $userorid
     * @return array|bool|\BrowserIDUser|false|\flase|\GPRAUser|\LiveUser|mixed|null|object|\stdClass|string|\turnitintooltwo_user|\user
     */
    public static function get_user($userorid) {
        global $USER, $DB;

        if (is_object($userorid)) {
            return $userorid;
        }

        if ($userorid == $USER->id) {
            $user = $USER;
        } else {
            $user = $DB->get_record('user', ['id' => $userorid]);
        }

        return $user;
    }

    /**
     * Some moodle functions don't work correctly with specic userids and this provides a hacky workaround.
     *
     * Temporarily swaps global USER variable.
     * @param $userorid
     * @param bool $swapback
     */
    public static function swap_global_user($userorid, $swapback = false) {
        global $USER;
        static $origuser = null;
        $user = self::get_user($userorid);
        if (!$swapback) {
            if ($origuser === null) {
                $origuser = $USER;
            }
            $USER = $user;
        } else {
            $USER = $origuser;
        }
    }

    /**
     * Get readable forum id arrays and ids of forums with access to all groups.
     * @param $user
     * @throws \coding_exception
     */
    public static function forum_ids($user) {
        // Need to swap USER variable arround for enrol_get_my_courses() to work with specific userid.
        // Also, there is a bug with forum_get_readable_forums which requires this hack.
        // https://tracker.moodle.org/browse/MDL-51243.
        self::swap_global_user($user);

        $courses = enrol_get_my_courses();
        $forumids = [];
        $aforumids = [];
        $forumidsallgroups = [];
        $aforumidsallgroups = [];

        foreach ($courses as $course) {
            $forums = forum_get_readable_forums($user->id, $course->id);
            $forumids = array_merge($forumids, array_keys($forums));
            $forumidsallgroups = array_merge($forumidsallgroups, self::forumids_accessallgroups($forums));

            if (function_exists('hsuforum_get_readable_forums')) {
                $aforums = hsuforum_get_readable_forums($user->id, $course->id);
                $aforumids = array_merge($aforumids, array_keys($aforums));
                $aforumidsallgroups = array_merge($aforumidsallgroups, self::forumids_accessallgroups($aforums, 'hsuforum'));
            }
        }

        self::swap_global_user($user, true);
        return [$forumids, $forumidsallgroups, $aforumids, $aforumidsallgroups];
    }

    /**
     * Get recent forum activity for all accessible forums across all courses.
     * @param bool $userid
     * @return array
     * @throws \coding_exception
     */
    public static function recent_forum_activity($userorid = false) {
        global $CFG, $DB;

        if (file_exists($CFG->dirroot.'/mod/hsuforum')) {
            require_once($CFG->dirroot.'/mod/hsuforum/lib.php');
        }

        $user = self::get_user($userorid);

        // Get all relevant forum ids for SQL in statement.
        list ($forumids, $forumidsallgroups, $aforumids, $aforumidsallgroups) = self::forum_ids($user);

        if (empty($forumids) && empty($aforumids)) {
            return [];
        }

        $sqls = [];
        $params = [];

        // TODO Q & A forums

        $limitsql = self::limit_sql(0, 10); // Note, this is here for performance optimisations only.

        if (!empty($forumids)) {
            list($finsql, $finparams) = $DB->get_in_or_equal($forumids);
            $params = $finparams;
            $params = array_merge($params, [SEPARATEGROUPS, $user->id, SEPARATEGROUPS]);

            $fgpsql = '';
            if (!empty($forumidsallgroups)) {
                list($fgpsql, $fgpparams) = $DB->get_in_or_equal($forumidsallgroups);
                $fgpsql = ' OR f1.id '.$fgpsql;
                $params = array_merge($params, $fgpparams);
            }

            $sqls []= "(SELECT ".$DB->sql_concat("'F'", 'fp1.id')." AS id, 'forum' as type, fp1.id AS postid, fp1.discussion, fp1.parent,
                               fp1.userid, fp1.modified, fp1.subject, fp1.message, cm1.id as cmid,
                               u1.firstnamephonetic, u1.lastnamephonetic, u1.middlename, u1.alternatename, u1.firstname,
                               u1.lastname, u1.picture, u1.imagealt, u1.email
	                      FROM {forum_posts} fp1
	                      JOIN {user} u1 ON u1.id = fp1.userid
                          JOIN {forum_discussions} fd1 ON fd1.id = fp1.discussion
	                      JOIN {forum} f1 ON f1.id = fd1.forum AND f1.id $finsql
	                      JOIN {course_modules} cm1 ON cm1.instance = f1.id
	                      JOIN {modules} m1 on m1.name = 'forum' AND cm1.module = m1.id
	                      LEFT JOIN {groups_members} gm1
                            ON cm1.groupmode = ?
                           AND gm1.groupid = fd1.groupid
                           AND gm1.userid = ?
	                     WHERE cm1.groupmode <> ? OR (gm1.userid IS NOT NULL $fgpsql)
                      ORDER BY fp1.modified DESC
                               $limitsql
                        )
	                     ";
            // TODO - when moodle gets private reply (anonymous) forums, we need to handle this here.
        }

        if (!empty($aforumids)) {
            list($afinsql, $afinparams) = $DB->get_in_or_equal($aforumids);
            $params = array_merge($params, $afinparams);
            $params = array_merge($params, [SEPARATEGROUPS, $user->id, SEPARATEGROUPS]);

            $afgpsql = '';
            if (!empty($aforumidsallgroups)) {
                list($afgpsql, $afgpparams) = $DB->get_in_or_equal($aforumidsallgroups);
                $afgpsql = ' OR f2.id '.$afgpsql;
                $params = array_merge($params, $afgpparams);
            }

            $params = array_merge($params, [$user->id, $user->id]);

            $sqls []= "(SELECT ".$DB->sql_concat("'A'", 'fp2.id')." AS id, 'hsuforum' as type, fp2.id AS postid, fp2.discussion, fp2.parent,
                               fp2.userid,fp2.modified,fp2.subject,fp2.message, cm2.id as cmid,
                               u2.firstnamephonetic, u2.lastnamephonetic, u2.middlename, u2.alternatename, u2.firstname,
                               u2.lastname, u2.picture, u2.imagealt, u2.email
                          FROM {hsuforum_posts} fp2
                          JOIN {user} u2 ON u2.id = fp2.userid
                          JOIN {hsuforum_discussions} fd2 ON fd2.id = fp2.discussion
                          JOIN {hsuforum} f2 ON f2.id = fd2.forum AND f2.id $afinsql
	                      JOIN {course_modules} cm2 on cm2.instance = f2.id
	                      JOIN {modules} m2 on m2.name = 'hsuforum' AND cm2.module = m2.id
	                      LEFT JOIN {groups_members} gm2
	                        ON cm2.groupmode = ?
	                       AND gm2.groupid = fd2.groupid
	                       AND gm2.userid = ?
                         WHERE (cm2.groupmode <> ? OR (gm2.userid IS NOT NULL $afgpsql))
                           AND (fp2.privatereply = 0 OR fp2.privatereply = ? OR fp2.userid = ?)
                      ORDER BY fp2.modified DESC
                               $limitsql
                        )
                         ";
        }

        $sql = '-- Snap sql'. "\n".implode ("\n".' UNION ALL '."\n", $sqls) . "\n".' ORDER BY modified DESC';
        $posts = $DB->get_records_sql($sql, $params, 0, 10);

        $activities = [];
        foreach ($posts as $post) {
            $activities[] = (object)[
                'type' => $post->type,
                'cmid' => $post->cmid,
                'name' => $post->subject,
                'sectionnum' => null,
                'timestamp' => $post->modified,
                'content' => (object) [
                    'id' => $post->postid,
                    'discussion' => $post->discussion,
                    'subject' => $post->subject,
                    'parent' => $post->parent
                ],
                'user' => (object) [
                    'id' => $post->userid,
                    'firstnamephonetic' => $post->firstnamephonetic,
                    'lastnamephonetic' => $post->lastnamephonetic,
                    'middlename' => $post->middlename,
                    'alternatename' => $post->alternatename,
                    'firstname' => $post->firstname,
                    'lastname' => $post->lastname,
                    'picture' => $post->picture,
                    'imagealt' => $post->imagealt,
                    'email' => $post->email
                ]
            ];
        }

        return $activities;
    }

    public static function print_recent_forum_activity() {
        global $PAGE;
        $activities = self::recent_forum_activity();
        if (empty($activities)) {
            return '';
        }
        $activities = array_slice($activities, 0, 10);
        $renderer = $PAGE->get_renderer('theme_snap', 'core', RENDERER_TARGET_GENERAL);
        return $renderer->recent_forum_activity($activities);
    }
}
