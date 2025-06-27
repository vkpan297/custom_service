<?php

use core_completion\progress;

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/action/function.php');
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/label/lib.php');
require_once($CFG->dirroot . '/enrol/manual/externallib.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->libdir . '/accesslib.php');
require_once($CFG->dirroot . '/completion/classes/external.php');
require_once($CFG->dirroot . '/blocks/html/block_html.php');
require_once($CFG->dirroot . '/mod/book/lib.php');
require_once($CFG->dirroot . '/user/externallib.php');
require_once($CFG->dirroot . '/course/classes/category.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use moodle_exception;
use core\event\course_module_created;
use core_external\util;

define('QUIZ_REVIEW_IMMEDIATELY_AFTER_ATTEMPT', 4096);
define('QUIZ_REVIEW_IMMEDIATELY_WHETHER_CORRECT', 4096);
define('QUIZ_REVIEW_IMMEDIATELY_MARKS', 4096);
define('QUIZ_REVIEW_IMMEDIATELY_SPECIFIC_FEEDBACK', 4096);
define('QUIZ_REVIEW_IMMEDIATELY_GENERAL_FEEDBACK', 4096);
define('QUIZ_REVIEW_IMMEDIATELY_RIGHT_ANSWER', 4096);
define('QUIZ_REVIEW_IMMEDIATELY_OVERALL_FEEDBACK', 4096);

class local_custom_service_external extends external_api
{
    public static function update_courses_lti_parameters()
    {
        return new external_function_parameters(
            array(
                'courseids' => new external_value(PARAM_TEXT, 'Course Ids')
            )
        );
    }
    public static function update_courses_lti($courseids)
    {
        global $DB, $CFG;
        $lti_updated = [];
        $status = false;
        //print_object($courseids);
        $sql = "SELECT cm.id as moduleid,cm.instance ltiid,cm.section as section,lt.name as ltiname,lt.grade as grade,lt.timecreated,lt.timemodified,c.id as courseid,gd.id as category
            FROM {course} c 
            JOIN {course_modules} cm ON c.id = cm.course 
            JOIN {lti} lt ON cm.instance = lt.id 
            JOIN {grade_categories} gd ON gd.courseid = c.id
            WHERE cm.module =15 AND c.id in (" . $courseids . ")";
        $modules = $DB->get_records_sql($sql);
        $all_module = array();
        $count = 0;
        foreach ($modules as $key => $value) {
            if ($DB->record_exists('grade_items', array('courseid' => $value->courseid, 'categoryid' => $value->category, 'itemtype' => 'mod', 'itemmodule' => 'lti', 'iteminstance' => $value->ltiid))) {
                //$all_module[] = $value;
            } else {
                $new_grade_item = new stdClass();
                $new_grade_item->courseid = $value->courseid;
                $new_grade_item->categoryid = $value->category;
                $new_grade_item->itemname = $value->ltiname;
                $new_grade_item->itemtype = 'mod';
                $new_grade_item->itemmodule = 'lti';
                $new_grade_item->iteminstance = $value->ltiid;
                $new_grade_item->itemnumber = 0;
                $new_grade_item->grademax = $value->grade;
                $new_grade_item->timecreated = $value->timecreated;
                $new_grade_item->timemodified = $value->timemodified;

                $insert_new_gradeitem = $DB->insert_record('grade_items', $new_grade_item);
                $count++;
            }
        }

        $lti_updated = [
            'ids' => $courseids,
            'message' => 'Success',
            'updated' => $count
        ];
        return $lti_updated;
    }
    public static function update_courses_lti_returns()
    {
        return new external_single_structure(
            array(
                'ids' => new external_value(PARAM_TEXT, 'course ids'),
                'message' => new external_value(PARAM_TEXT, 'success message'),
                'updated' => new external_value(PARAM_TEXT, 'Items Updated')
            )
        );
    }

    public static function update_courses_sections_parameters()
    {
        return new external_function_parameters(
            array(
                'courseids' => new external_value(PARAM_TEXT, 'Course Ids')
            )
        );
    }
    public static function update_courses_sections($courseids)
    {
        global $DB, $CFG;
        require_once($CFG->libdir . '/filelib.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $course = $DB->get_record('course', array('id' => $courseids), '*', MUST_EXIST);
        $sections = $DB->get_records('course_sections', array('course' => $courseids));

        $count = 0;

        foreach ($sections as $key => $value) {
            $section = $DB->get_record('course_sections', array('id' => $key), '*', MUST_EXIST);

            $data = new stdClass();
            $data->id = $section->id;
            $data->name = $section->summary;
            $data->availability = '{"op":"&","c":[],"showc":[]}';

            //check if section is empty-then update
            if ($section->name == NULL) {
                $done = course_update_section($course, $section, $data);
            }
            $count++;
        }

        $lti_updated = [
            'ids' => $courseids,
            'message' => 'Success',
            'updated' => $count
        ];
        return $lti_updated;
    }
    public static function update_courses_sections_returns()
    {
        return new external_single_structure(
            array(
                'ids' => new external_value(PARAM_TEXT, 'course ids'),
                'message' => new external_value(PARAM_TEXT, 'success message'),
                'updated' => new external_value(PARAM_TEXT, 'Items Updated')
            )
        );
    }





    public static function unenrol_bulk_users_parameters()
    {
        return new external_function_parameters(
            array(
                'categoryids' => new external_value(PARAM_TEXT, 'Category Ids'),
                'roleid' => new external_value(PARAM_TEXT, 'Role Ids')
            )
        );
    }
    public static function unenrol_bulk_users($categoryids, $roleid)
    {
        // echo $categoryids;
        global $DB, $CFG;
        require_once($CFG->libdir . '/filelib.php');
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/enrol/locallib.php');
        require_once($CFG->dirroot . '/enrol/externallib.php');

        $sql = "DELETE ue FROM mdl_user_enrolments ue
        JOIN mdl_enrol e ON (e.id = ue.enrolid)
        JOIN mdl_course course ON (course.id = e.courseid )
        JOIN mdl_context c ON (c.contextlevel = 50 AND c.instanceid = e.courseid)
        JOIN mdl_role_assignments ra ON (ra.contextid = c.id  AND ra.userid = ue.userid AND ra.roleid=$roleid)
        WHERE course.category IN (?)
        ";
        //echo $categoryids;
        $param = explode(',', $categoryids);
        //print_r($param);
        $result = $DB->execute($sql, $param);
        if ($result) {
            $response = [
                'message' => 'Success'
            ];
        } else {
            $response = [
                'message' => 'Failed'
            ];
        }

        return $response;
    }
    public static function unenrol_bulk_users_returns()
    {
        return new external_single_structure(
            array(
                'message' => new external_value(PARAM_TEXT, 'success message')
            )
        );
    }



    public static function get_activity_Section_course_parameters()
    {
        return new external_function_parameters(
            array(

                'courseid' => new external_value(PARAM_INT, 'Course Ids'),
                'sectionid' => new external_value(PARAM_INT, 'Section Ids')
            )
        );
    }

    public static function get_activity_Section_course($courseid, $sectionid)
    {
        global $DB, $CFG;
        require_once($CFG->libdir . '/filelib.php');
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/user/lib.php');
        $sql = "SELECT cm.*, m.name as modname,c.fullname as coursename,cs.id as idsection, cs.name as sectionname
                FROM mdl_course_modules cm
                JOIN mdl_modules m on m.id = cm.module
                JOIN mdl_course c on c.id = cm.course
                JOIN mdl_course_sections cs on cs.id = cm.section
                WHERE cm.course = $courseid and m.id = cm.module and cm.section=$sectionid";

        $mods = $DB->get_records_sql($sql);
        foreach ($mods as $cm) {
            $modrec = $DB->get_record($cm->modname, array('id' => $cm->instance));
            $response = [
                'id' => $cm->id,
                'course' => $cm->coursename,
                'section' => $cm->sectionname,
                'activity' => $cm->modname . ":" . $modrec->name
            ];
        }
        return $response;
    }


    public static function get_activity_Section_course_returns()
    {
        return new external_single_structure(
            array(
                'id' => new external_value(PARAM_INT, 'ID'),
                'course' => new external_value(PARAM_TEXT, 'Course'),
                'section' => new external_value(PARAM_TEXT, 'Section'),
                'activity' => new external_value(PARAM_TEXT, 'Activity')
            )
        );
    }





    public static function check_login_parameters()
    {
        return new external_function_parameters(
            array(
                'username' => new external_value(PARAM_TEXT, 'User Name'),
                'password' => new external_value(PARAM_TEXT, 'Pass Word')
            )
        );
    }

    public static function check_login($username, $password)
    {
        global $DB, $CFG;
        require_once($CFG->libdir . '/filelib.php');
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/user/lib.php');


        $record = $DB->get_record('user', array('username' => $username));
        // $pass1 = var_dump($record->password);
        if (password_verify($password, $record->password)) {
            $response = [
                'username' => $record->username,
                'message' => 'Success'
            ];
            return $response;
        } else {
            $response = [
                'username' => 'Tai khoan hoac mk sai',
                'message' => 'Failed'
            ];
            return $response;
        }
    }
    public static function check_login_returns()
    {
        return new external_single_structure(
            array(
                'username' => new external_value(PARAM_TEXT, 'user name'),
                'message' => new external_value(PARAM_TEXT, 'success message')

            )
        );
    }


    // get list course user enrol

    public static function get_enrol_user_course_parameters()
    {
        return new external_function_parameters(
            array(
                'userid' => new external_value(PARAM_TEXT, 'user id'),
                'returnusercount' => new external_value(
                    PARAM_BOOL,
                    'Include count of enrolled users for each course? This can add several seconds to the response time'
                        . ' if a user is on several large courses, so set this to false if the value will not be used to'
                        . ' improve performance.',
                    VALUE_DEFAULT,
                    true
                ),
            )
        );
    }

    public static function get_enrol_user_course($userid, $returnusercount = true)
    {
        global $CFG, $USER, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/completionlib.php');

        $sql = "SELECT ue.id, c.id as courseid, c.fullname as coursename, c.shortname as shortname, u.firstname as firstname, u.lastname as lastname, u.id as userid
        FROM mdl_user_enrolments ue
        JOIN mdl_user u on u.id = ue.userid
        JOIN mdl_enrol e on e.id = ue.enrolid
        JOIN mdl_course c on c.id = e.courseid
        WHERE u.id IN ($userid) ";

        // var_dump($sql);
        // die();
        //$userID = explode(',',);
        // var_dump($userID);
        // die();
        $mods = $DB->get_records_sql($sql);
        //var_dump($mods);
        //die();
        foreach ($mods as $cm) {
            // $modrec = $DB->get_record($cm->modname, array('id' => $cm->instance));
            $courseresult = [
                'id' => $cm->id,
                'userid' => $cm->userid,
                'courseid' => $cm->courseid,
                'shortname' => $cm->shortname,
                'coursename' => $cm->coursename,
                'firstname' => $cm->firstname,
                'lastname' => $cm->lastname,

            ];
            $result[] = $courseresult;
        }
        return $result;
    }

    public static function get_enrol_user_course_returns()
    {
        return new external_multiple_structure(
            new external_single_structure(
                array(
                    'id'        => new external_value(PARAM_INT, 'id of course'),
                    'userid'        => new external_value(PARAM_INT, 'id of course'),
                    'courseid'        => new external_value(PARAM_INT, 'id of course'),
                    'shortname' => new external_value(PARAM_RAW, 'short name of course'),
                    'coursename'  => new external_value(PARAM_RAW, 'long name of course'),
                    'firstname' => new external_value(PARAM_RAW, 'short name of course'),
                    'lastname'  => new external_value(PARAM_RAW, 'long name of course')
                )
            )
        );
    }







    // add_role_for_parent

    public static function add_role_for_parent_parameters()
    {
        return new external_function_parameters(
            array(
                'idchild' => new external_value(PARAM_INT, 'id children'),
                'idparent' => new external_value(PARAM_INT, 'id parent'),
            )
        );
    }

    public static function add_role_for_parent($idchild, $idparent)
    {
        global $CFG, $USER, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/completionlib.php');

        $sql = "SELECT * FROM mdl_context WHERE contextlevel = " . CONTEXT_USER . " and instanceid = " . $idchild;

        $rs = $DB->get_record_sql($sql);

        $sql2 = "SELECT id FROM mdl_role WHERE name = 'parent'";

        $rs2 = $DB->get_record_sql($sql2);

        if (isset($rs2->id)) {
            $new_grade_item = new stdClass();
            $new_grade_item->roleid = $rs2->id;
            $new_grade_item->contextid = $rs->id;
            $new_grade_item->userid = $idparent;

            $insert_new_gradeitem = $DB->insert_record('role_assignments', $new_grade_item);
        }
        if ($insert_new_gradeitem) {
            $message = [
                'status' => 200,
                'data' => $insert_new_gradeitem,
            ];
            return $message;
        } else {
            $message = [
                'status' => 400,
                'data' => '',
            ];
            return $message;
        }
    }

    public static function add_role_for_parent_returns()
    {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_INT, 'success message'),
                'data' => new external_value(PARAM_RAW, 'success message')
            )
        );
    }





    // get_info_children_from_laravel

    public static function get_info_children_from_laravel_parameters()
    {
        return new external_function_parameters(
            array(
                'userchildren' => new external_value(PARAM_TEXT, 'user children'),
            )
        );
    }

    public static function get_info_children_from_laravel($userchildren)
    {
        global $CFG, $USER, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/completionlib.php');

        // $sql = "SELECT ue.id, c.id as courseid, c.fullname as coursename, c.shortname as shortname, u.firstname as firstname, u.lastname as lastname, u.id as userid
        // FROM mdl_user_enrolments ue
        // JOIN mdl_user u on u.id = ue.userid
        // JOIN mdl_enrol e on e.id = ue.enrolid
        // JOIN mdl_course c on c.id = e.courseid
        // WHERE u.id IN ($userid) ";


        $sql = "SELECT * FROM mdl_user WHERE username IN $userchildren";
        //var_dump($sql);
        //die();

        $mods = $DB->get_records_sql($sql);



        foreach ($mods as $cm) {
            $courseresult = [
                'id' => $cm->id,
                'username' => $cm->username,
                'firstname' => $cm->firstname,
                'lastname' => $cm->lastname,
            ];
            $result[] = $courseresult;
        }
        return $result;
    }

    public static function get_info_children_from_laravel_returns()
    {
        return new external_multiple_structure(
            new external_single_structure(
                array(
                    'id'        => new external_value(PARAM_INT, 'id of course'),
                    'username'  => new external_value(PARAM_RAW, 'long name of course'),
                    'firstname' => new external_value(PARAM_RAW, 'short name of course'),
                    'lastname'  => new external_value(PARAM_RAW, 'long name of course')
                )
            )
        );
    }




    // get infor calendar

    public static function get_info_calendar_parameters()
    {
        return new external_function_parameters(
            array(
                'userid' => new external_value(PARAM_TEXT, 'user id')
            )
        );
    }

    public static function get_info_calendar($userid)
    {
        global $CFG, $USER, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/completionlib.php');

        $sql = "SELECT 
        e.id,
        u.id as userid,
        u.username,
        u.firstname,
        u.lastname,
        u.email,
        e.timestart,
        e.name, e.description
        FROM mdl_event e
        JOIN mdl_user u ON u.id = e.userid
        WHERE
        e.eventtype = 'user' AND
        e.userid = $userid";

        $mods = $DB->get_records_sql($sql);

        foreach ($mods as $cm) {
            $courseresult = [
                'id' => $cm->id,
                'userid' => $cm->userid,
                'username' => $cm->username,
                'firstname' => $cm->firstname,
                'lastname' => $cm->lastname,
                'email' => $cm->email,
                'timestart' => date('n/j/Y', $cm->timestart),
                'name' => $cm->name,
                'description' => $cm->description,

            ];
            $result[] = $courseresult;
        }
        return $result;
    }

    public static function get_info_calendar_returns()
    {
        return new external_multiple_structure(
            new external_single_structure(
                array(
                    'id'        => new external_value(PARAM_INT, 'id of course'),
                    'userid'        => new external_value(PARAM_INT, 'id of course'),
                    'username'        => new external_value(PARAM_RAW, 'id of course'),
                    'firstname' => new external_value(PARAM_RAW, 'short name of course'),
                    'lastname'  => new external_value(PARAM_RAW, 'long name of course'),
                    'email' => new external_value(PARAM_RAW, 'short name of course'),
                    'timestart'  => new external_value(PARAM_RAW, 'long name of course'),
                    'name'  => new external_value(PARAM_RAW, 'long name of course'),
                    'description'  => new external_value(PARAM_RAW, 'long name of course')
                )
            )
        );
    }

    // get count student in course

    public static function get_total_student_group_parameters()
    {
        return new external_function_parameters(
            array(
                'userid' => new external_value(PARAM_TEXT, 'user id'),
                'courseid' => new external_value(PARAM_TEXT, 'course id', VALUE_DEFAULT, 0),
                'limit' => new external_value(PARAM_INT, 'the number of results to return', VALUE_DEFAULT, 0),
                'offset' => new external_value(PARAM_INT, 'offset the result set by a given amount', VALUE_DEFAULT, 0),
                'roleid' => new external_value(PARAM_INT, 'role id', VALUE_DEFAULT, 0)
            )
        );
    }

    public static function get_total_student_group($userid, $courseid, $limit, $offset, $roleid)
    {
        global $CFG, $USER, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/completionlib.php');
        $result = ['count' => 0, 'datagroup' => []];
        $sql_count = "WITH A as (
            select
            c.id courseid,
            c.fullname coursename,
            g.id as groupid,
            g.name as groupname,
            sum(case when ra.roleid = $roleid then 1 else 0 end) total
            from mdl_course c
            join mdl_context ct on ct.instanceid = c.id
            and ct.contextlevel = 50
            join mdl_role_assignments ra on ra.contextid = ct.id
            JOIN mdl_role r ON ra.roleid = r.id
            join mdl_groups g on g.courseid = c.id
            join mdl_groups_members gm on gm.groupid = g.id
            and gm.userid = ra.userid
            WHERE g.id IN (SELECT groupid FROM mdl_groups_members WHERE userid = $userid)
            group by ra.contextid, c.fullname, g.name
        ),
        getUserCourse as (
            SELECT DISTINCT c.fullname as coursename, c.id
            FROM mdl_user u
            JOIN mdl_role_assignments ra ON u.id = ra.userid
            JOIN mdl_context ctx ON ra.contextid = ctx.id
            JOIN mdl_role r ON ra.roleid = r.id
            JOIN mdl_course c ON ctx.instanceid = c.id
            WHERE u.id = $userid AND (r.shortname = 'editingteacher' OR r.shortname = 'teacher')
        )
        select count(*) AS total_count
        from A
        join getUserCourse on getUserCourse.id = A.courseid";
        if (!empty($courseid)) {
            $sql_count .= " WHERE A.courseid = $courseid";
            // $sql .= " AND c.id = $courseid";
        }

        $mods_count = $DB->get_record_sql($sql_count);
        $result['count'] = $mods_count->total_count;

        $sql = "WITH A as (
            select
            c.id courseid,
            c.fullname coursename,
            g.id as groupid,
            g.name as groupname,
            sum(case when ra.roleid = $roleid then 1 else 0 end) total
            from mdl_course c
            join mdl_context ct on ct.instanceid = c.id
            and ct.contextlevel = 50
            join mdl_role_assignments ra on ra.contextid = ct.id
            JOIN mdl_role r ON ra.roleid = r.id
            join mdl_groups g on g.courseid = c.id
            join mdl_groups_members gm on gm.groupid = g.id
            and gm.userid = ra.userid
            WHERE g.id IN (SELECT groupid FROM mdl_groups_members WHERE userid = $userid)
            group by ra.contextid, c.fullname, g.name
        ),
        getUserCourse as (
            SELECT DISTINCT c.fullname as coursename, c.id
            FROM mdl_user u
            JOIN mdl_role_assignments ra ON u.id = ra.userid
            JOIN mdl_context ctx ON ra.contextid = ctx.id
            JOIN mdl_role r ON ra.roleid = r.id
            JOIN mdl_course c ON ctx.instanceid = c.id
            WHERE u.id = $userid AND (r.shortname = 'editingteacher' OR r.shortname = 'teacher')
        )
        select A.groupid, A.courseid, A.coursename, A.groupname, A.total
        from A
        join getUserCourse on getUserCourse.id = A.courseid";

        if (!empty($courseid)) {
            $sql .= " WHERE A.courseid = $courseid";
            // $sql .= " AND c.id = $courseid";
        }
        if (!empty($limit)) {
            $sql .= " LIMIT $limit OFFSET $offset";
        }

        // $sql .= " GROUP BY c.id, c.fullname, g.id, g.name";
        $mods = $DB->get_records_sql($sql);
        foreach ($mods as $cm) {
            $courseresult = [
                'courseid'   => $cm->courseid,
                'coursename' => $cm->coursename,
                'groupid'    => $cm->groupid,
                'groupname'  => $cm->groupname,
                'total'      => $cm->total,
                'view_url'   => $CFG->wwwroot . '/group/overview.php?grouping=0&id=' . $cm->courseid . '&group=' . $cm->groupid,
                'grade_report_url'   => $CFG->wwwroot . '/grade/report/grader/index.php?id=' . $cm->courseid . '&group=' . $cm->groupid
            ];
            $result['datagroup'][] = $courseresult;
        }
        return $result;
    }

    public static function get_total_student_group_returns()
    {
        return new external_single_structure([
            'count' => new external_value(PARAM_INT, 'count'),
            'datagroup' => new external_multiple_structure(new external_single_structure([
                'courseid'   => new external_value(PARAM_INT, 'id of course'),
                'coursename' => new external_value(PARAM_RAW, 'name of course'),
                'groupid'    => new external_value(PARAM_INT, 'id of group'),
                'groupname'  => new external_value(PARAM_RAW, 'name of group'),
                'total'      => new external_value(PARAM_INT, 'total student'),
                'view_url'      => new external_value(PARAM_RAW, 'view url'),
                'grade_report_url'      => new external_value(PARAM_RAW, 'grade_report_url')
            ])),
        ]);
    }



    //get total active user by x days
    public static function get_total_active_user_parameters()
    {
        return new external_function_parameters(
            array(
                'day' => new external_value(PARAM_TEXT, 'day')
            )
        );
    }
    public static function get_total_active_user($day)
    {
        global $CFG, $USER, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/completionlib.php');
        $limitDay = 7;
        if (!empty($day)) {
            $limitDay = $day;
        }

        $sql = "SELECT
        DATE_FORMAT(FROM_UNIXTIME(firstaccess), '%d/%m/%Y') AS date,
        COUNT(DISTINCT id) AS user_count
        FROM mdl_user
        WHERE FROM_UNIXTIME(firstaccess) >= CURRENT_TIMESTAMP - INTERVAL $limitDay DAY
        GROUP BY DATE_FORMAT(FROM_UNIXTIME(firstaccess), '%d/%m/%Y')
        ORDER BY DATE_FORMAT(FROM_UNIXTIME(firstaccess), '%d/%m/%Y')";

        $mods = $DB->get_records_sql($sql);
        $result = [];
        foreach ($mods as $cm) {
            $courseresult = [
                'date'   => $cm->date,
                'user_count' => $cm->user_count
            ];
            $result[] = $courseresult;
        }
        return $result;
    }
    public static function get_total_active_user_returns()
    {
        return new external_multiple_structure(
            new external_single_structure(
                array(
                    'date'   => new external_value(PARAM_TEXT, 'date'),
                    'user_count' => new external_value(PARAM_INT, 'user_count')
                )
            )
        );
    }


    //get data calendar
    public static function get_data_calendar_parameters()
    {
        return new external_function_parameters(
            array(
                'userid' => new external_value(PARAM_INT, 'userid'),
                'limit' => new external_value(PARAM_INT, 'the number of results to return', VALUE_DEFAULT, 0),
                'offset' => new external_value(PARAM_INT, 'offset the result set by a given amount', VALUE_DEFAULT, 0),
                'type' => new external_value(PARAM_RAW, 'select with type', VALUE_DEFAULT, null, NULL_ALLOWED),
                'nottype' => new external_value(PARAM_RAW, 'select without type', VALUE_DEFAULT, null, NULL_ALLOWED)
            )
        );
    }
    public static function get_data_calendar($userid, $limit, $offset, $type, $nottype)
    {
        global $CFG, $USER, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/completionlib.php');
        $result = ['count' => 0, 'datacalendar' => []];
        $sql_count = "SELECT count(*) AS total_count 
        from mdl_event
        WHERE userid = $userid
        AND DATE(FROM_UNIXTIME(timestart)) >= CURDATE()";
        // AND timestart >= unix_timestamp(now())";
        // AND eventtype != 'group'";
        if (!empty($type)) {
            $sql_count .= " AND eventtype = '$type'";
        }
        if (!empty($nottype)) {
            $sql_count .= " AND eventtype != '$nottype'";
            // var_dump($nottype);die;
        }
        $mods_count = $DB->get_record_sql($sql_count);
        $result['count'] = $mods_count->total_count;

        $sql = "SELECT * from mdl_event 
        WHERE userid = $userid
        AND DATE(FROM_UNIXTIME(timestart)) >= CURDATE()";
        // AND timestart >= unix_timestamp(now())";
        // AND eventtype != 'group'";

        if (!empty($type)) {
            $sql .= " AND eventtype = '$type'";
        }
        if (!empty($nottype)) {
            $sql .= " AND eventtype != '$nottype'";
        }

        $sql .= " ORDER BY timestart";

        if (!empty($limit)) {
            $sql .= " LIMIT $limit OFFSET $offset";
        }
        // var_dump($sql, $nottype, $type);die;
        $mods = $DB->get_records_sql($sql);

        foreach ($mods as $cm) {
            $courseresult = [
                'id'   => $cm->id,
                'name' => $cm->name,
                'description'   => $cm->description,
                'categoryid' => $cm->categoryid,
                'groupid'   => $cm->groupid,
                'userid' => $cm->userid,
                'courseid' => $cm->courseid,
                'eventtype'   => $cm->eventtype,
                'timestart' => $cm->timestart,
                'timeduration'   => $cm->timeduration,
                'timesort' => $cm->timesort,
                'timemodified'   => $cm->timemodified,
                'viewurl' => $CFG->wwwroot . '/calendar/view.php?view=day&course=' . $cm->courseid . '&time=' . $cm->timestart . '#event_' . $cm->id,
                'formattedtime'   => "<a href=\"$CFG->wwwroot/calendar/view.php?view=day&amp;time=$cm->timestart\">" . date('d/m/Y H:i', $cm->timestart) . "</a>"
            ];
            $result['datacalendar'][] = $courseresult;
        }
        return $result;
    }
    public static function get_data_calendar_returns()
    {
        return new external_single_structure([
            'count' => new external_value(PARAM_INT, 'count'),
            'datacalendar' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'id'),
                'name' => new external_value(PARAM_RAW, 'name'),
                'description' => new external_value(PARAM_RAW, 'description'),
                'categoryid' => new external_value(PARAM_INT, 'categoryid'),
                'groupid' => new external_value(PARAM_INT, 'groupid'),
                'userid' => new external_value(PARAM_INT, 'userid'),
                'courseid' => new external_value(PARAM_INT, 'courseid'),
                'eventtype' => new external_value(PARAM_RAW, 'eventtype'),
                'timestart' => new external_value(PARAM_RAW, 'timestart'),
                'timeduration' => new external_value(PARAM_RAW, 'timeduration'),
                'timesort' => new external_value(PARAM_RAW, 'timesort'),
                'timemodified' => new external_value(PARAM_RAW, 'timemodified'),
                'viewurl' => new external_value(PARAM_RAW, 'viewurl'),
                'formattedtime' => new external_value(PARAM_RAW, 'formattedtime')
            ])),
        ]);
    }


    //get data calendar by group
    public static function get_data_calendar_by_group_parameters()
    {
        return new external_function_parameters(
            array(
                'userid' => new external_value(PARAM_INT, 'userid'),
                'limit' => new external_value(PARAM_INT, 'the number of results to return', VALUE_DEFAULT, 0),
                'offset' => new external_value(PARAM_INT, 'offset the result set by a given amount', VALUE_DEFAULT, 0)
            )
        );
    }
    public static function get_data_calendar_by_group($userid, $limit, $offset)
    {
        global $CFG, $USER, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/completionlib.php');
        $result = ['count' => 0, 'datacalendar' => []];
        $sql_count = "SELECT count(*) AS total_count 
        from mdl_event
        WHERE userid = $userid
        -- AND timestart > unix_timestamp(now())
        AND DATE(FROM_UNIXTIME(timestart)) >= CURDATE()
        AND eventtype = 'group'";
        $mods_count = $DB->get_record_sql($sql_count);
        $result['count'] = $mods_count->total_count;

        $sql = "SELECT * from mdl_event 
        WHERE userid = $userid
        -- AND timestart > unix_timestamp(now())
        AND DATE(FROM_UNIXTIME(timestart)) >= CURDATE()
        AND eventtype = 'group'
        ORDER BY timestart";

        if (!empty($limit)) {
            $sql .= " LIMIT $limit OFFSET $offset";
            // $sql .= " AND c.id = $courseid";
        }

        $mods = $DB->get_records_sql($sql);

        foreach ($mods as $cm) {
            $courseresult = [
                'id'   => $cm->id,
                'name' => $cm->name,
                'description'   => $cm->description,
                'categoryid' => $cm->categoryid,
                'groupid'   => $cm->groupid,
                'userid' => $cm->userid,
                'courseid' => $cm->courseid,
                'eventtype'   => $cm->eventtype,
                'timestart' => $cm->timestart,
                'timeduration'   => $cm->timeduration,
                'timesort' => $cm->timesort,
                'timemodified'   => $cm->timemodified,
                'viewurl' => $CFG->wwwroot . '/calendar/view.php?view=day&course=' . $cm->courseid . '&time=' . $cm->timestart . '#event_' . $cm->id,
                'formattedtime'   => "<a href=\"$CFG->wwwroot/calendar/view.php?view=day&amp;time=$cm->timestart\">" . date('d/m/Y H:i', $cm->timestart) . "</a>"
            ];
            $result['datacalendar'][] = $courseresult;
        }
        return $result;
    }
    public static function get_data_calendar_by_group_returns()
    {
        return new external_single_structure([
            'count' => new external_value(PARAM_INT, 'count'),
            'datacalendar' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'id'),
                'name' => new external_value(PARAM_RAW, 'name'),
                'description' => new external_value(PARAM_RAW, 'description'),
                'categoryid' => new external_value(PARAM_INT, 'categoryid'),
                'groupid' => new external_value(PARAM_INT, 'groupid'),
                'userid' => new external_value(PARAM_INT, 'userid'),
                'courseid' => new external_value(PARAM_INT, 'courseid'),
                'eventtype' => new external_value(PARAM_RAW, 'eventtype'),
                'timestart' => new external_value(PARAM_RAW, 'timestart'),
                'timeduration' => new external_value(PARAM_RAW, 'timeduration'),
                'timesort' => new external_value(PARAM_RAW, 'timesort'),
                'timemodified' => new external_value(PARAM_RAW, 'timemodified'),
                'viewurl' => new external_value(PARAM_RAW, 'viewurl'),
                'formattedtime' => new external_value(PARAM_RAW, 'formattedtime')
            ])),
        ]);
    }


    // get teacher course

    public static function get_user_course_parameters()
    {
        return new external_function_parameters(
            array(
                'userid' => new external_value(PARAM_TEXT, 'user id'),
                'courseid' => new external_value(PARAM_TEXT, 'course id', VALUE_DEFAULT, 0),
                'coursename' => new external_value(PARAM_TEXT, 'coursename', VALUE_DEFAULT, 0),
                'search' => new external_value(PARAM_TEXT, 'Search term for course id or course name', VALUE_DEFAULT, ''),
                'limit' => new external_value(PARAM_INT, 'the number of results to return', VALUE_DEFAULT, 0),
                'offset' => new external_value(PARAM_INT, 'offset the result set by a given amount', VALUE_DEFAULT, 0)
            )
        );
    }

    public static function get_user_course($userid, $courseid, $coursename, $search, $limit, $offset)
    {
        global $CFG, $USER, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/completionlib.php');

        $result = ['count' => 0, 'datacalendar' => []];

        $sql_count = "SELECT count(DISTINCT c.id) as total_count
        FROM mdl_user u
        JOIN mdl_role_assignments ra ON u.id = ra.userid
        JOIN mdl_context ctx ON ra.contextid = ctx.id
        JOIN mdl_role r ON ra.roleid = r.id
        JOIN mdl_course c ON ctx.instanceid = c.id
        WHERE u.id = :userid AND (r.shortname = 'editingteacher' OR r.shortname = 'teacher')
        AND c.visible = 1";

        $params = ['userid' => $userid];

        // if (!empty($coursename)) {
        //     $sql_count .= " AND c.fullname LIKE :coursename";
        //     $params['coursename'] = '%' . $coursename . '%';
        // }

        if (!empty($search)) {
            $sql_count .= " AND (c.fullname LIKE :search OR c.id = :searchint)";
            $params['search'] = '%' . $search . '%';
            $params['searchint'] = is_numeric($search) ? (int)$search : -1;
        }

        $mods_count = $DB->get_record_sql($sql_count, $params);
        $result['count'] = $mods_count->total_count;

        $sql = "SELECT DISTINCT c.fullname as coursename, c.id
        FROM mdl_user u
        JOIN mdl_role_assignments ra ON u.id = ra.userid
        JOIN mdl_context ctx ON ra.contextid = ctx.id
        JOIN mdl_role r ON ra.roleid = r.id
        JOIN mdl_course c ON ctx.instanceid = c.id
        WHERE u.id = :userid AND (r.shortname = 'editingteacher' OR r.shortname = 'teacher')
        AND c.visible = 1";

        $params = ['userid' => $userid];

        if (!empty($courseid)) {
            $sql .= " AND c.id = :courseid";
            $params['courseid'] = $courseid;
        }

        // if (!empty($coursename)) {
        //     $sql .= " AND c.fullname LIKE :coursename";
        //     $params['coursename'] = '%' . $coursename . '%';
        // }

        if (!empty($search)) {
            $sql .= " AND (c.fullname LIKE :search OR c.id = :searchint)";
            $params['search'] = '%' . $search . '%';
            $params['searchint'] = is_numeric($search) ? (int)$search : -1;
        }

        if (!empty($limit)) {
            $sql .= " LIMIT $limit OFFSET $offset";
        }

        $mods = $DB->get_records_sql($sql, $params);
        // var_dump($mods);die;
        foreach ($mods as $cm) {
            $courseresult = [
                'courseid'   => $cm->id,
                'coursename' => $cm->coursename,
                'view_url'    => $CFG->wwwroot . '/course/view.php?id=' . $cm->id,
            ];
            $result['datacourse'][] = $courseresult;
        }
        return $result;
    }

    public static function get_user_course_returns()
    {
        return new external_single_structure([
            'count' => new external_value(PARAM_INT, 'count'),
            'datacourse' => new external_multiple_structure(new external_single_structure([
                'courseid' => new external_value(PARAM_INT, 'courseid'),
                'coursename' => new external_value(PARAM_RAW, 'coursename'),
                'view_url' => new external_value(PARAM_RAW, 'view_url')
            ])),
        ]);
    }



    // report dashboard

    public static function get_data_report_parameters()
    {
        return new external_function_parameters(
            array(
                'userid' => new external_value(PARAM_TEXT, 'user id'),
                'courseid' => new external_value(PARAM_TEXT, 'course id', VALUE_DEFAULT, 0),
                'groupid' => new external_value(PARAM_TEXT, 'group id', VALUE_DEFAULT, 0),
                'roleid' => new external_value(PARAM_TEXT, 'roleid', VALUE_DEFAULT, 0)
            )
        );
    }

    // public static function get_data_report($userid, $courseid, $groupid, $roleid)
    // {
    //     global $CFG, $USER, $DB;

    //     require_once($CFG->dirroot . '/course/lib.php');
    //     require_once($CFG->dirroot . '/user/lib.php');
    //     require_once($CFG->libdir . '/completionlib.php');

    //     $result = [];
    //     if (empty($courseid) || empty($groupid)) {
    //         return $result;
    //     }

    //     $sql_1 = "WITH A as (
    //         select
    //         c.id courseid,
    //         c.fullname coursename,
    //         g.id as groupid,
    //         g.name as groupname,
    //         sum(case when ra.roleid = $roleid then 1 else 0 end) total
    //         from mdl_course c
    //         join mdl_context ct on ct.instanceid = c.id
    //         and ct.contextlevel = 50
    //         join mdl_role_assignments ra on ra.contextid = ct.id
    //         JOIN mdl_role r ON ra.roleid = r.id
    //         join mdl_groups g on g.courseid = c.id
    //         join mdl_groups_members gm on gm.groupid = g.id
    //         and gm.userid = ra.userid
    //         WHERE g.id IN (SELECT groupid FROM mdl_groups_members WHERE userid = $userid)
    //         group by ra.contextid, c.fullname, g.name
    //     ),
    //     getUserCourse as (
    //         SELECT DISTINCT c.fullname as coursename, c.id
    //         FROM mdl_user u
    //         JOIN mdl_role_assignments ra ON u.id = ra.userid
    //         JOIN mdl_context ctx ON ra.contextid = ctx.id
    //         JOIN mdl_role r ON ra.roleid = r.id
    //         JOIN mdl_course c ON ctx.instanceid = c.id
    //         WHERE u.id = $userid AND (r.shortname = 'editingteacher' OR r.shortname = 'teacher')
    //     ),
    //     getTotalUserCourseCompletion as (
    //         SELECT COUNT(DISTINCT ue.userid) AS relateduserid, l.courseid
    //         FROM mdl_logstore_standard_log AS l
    //         JOIN mdl_user_enrolments AS ue ON l.relateduserid = ue.userid
    //         JOIN mdl_enrol AS e ON ue.enrolid = e.id
    //         JOIN mdl_role_assignments AS ra ON ue.userid = ra.userid
    //         WHERE l.action = 'completed'
    //           AND l.target = 'course'
    //           AND l.objecttable = 'course_completions'
    //           AND l.courseid = $courseid
    //           AND ue.userid IN (
    //             SELECT gm.userid
    //             FROM mdl_groups_members AS gm
    //             WHERE gm.groupid = $groupid
    //           )
    //           AND ra.roleid = (SELECT id FROM mdl_role WHERE id = $roleid)
    //     )
    //     select A.groupid, 
    //     A.courseid, 
    //     A.coursename, 
    //     A.groupname, 
    //     COALESCE(A.total, 0) AS total, 
    //     COALESCE(getTotalUserCourseCompletion.relateduserid, 0) AS relateduserid, 
    //     COALESCE(ROUND((getTotalUserCourseCompletion.relateduserid * 100.0 / A.total),2), 0) AS percentage
    //     from A
    //     join getUserCourse on getUserCourse.id = A.courseid
    //     left join getTotalUserCourseCompletion on getTotalUserCourseCompletion.courseid = A.courseid
    //     where A.groupid = $groupid and A.courseid = $courseid";

    //     $mods = $DB->get_records_sql($sql_1);
    //     // var_dump($mods);die;
    //     foreach ($mods as $cm) {
    //         $courseresult = [
    //             'groupid'   => $cm->groupid,
    //             'courseid'   => $cm->courseid,
    //             'coursename' => $cm->coursename,
    //             'groupname' => $cm->groupname,
    //             'total' => $cm->total,
    //             'relateduserid' => $cm->relateduserid,
    //             'percentage' => $cm->percentage,
    //             'url' => $CFG->wwwroot,
    //             'total_login_user' => null
    //         ];
    //         $result[] = $courseresult;
    //     }

    //     // $sql_2 = "SELECT COUNT(DISTINCT l.userid) AS total_login_user
    //     // FROM mdl_logstore_standard_log AS l
    //     // JOIN mdl_user_enrolments AS ue ON l.userid = ue.userid
    //     // JOIN mdl_enrol AS e ON ue.enrolid = e.id
    //     // JOIN mdl_groups_members AS gm ON l.userid = gm.userid
    //     // WHERE l.target = 'course'
    //     //   AND l.action = 'viewed'
    //     //   AND l.courseid = $courseid
    //     //   AND gm.groupid = $groupid";
    //     $sql_2 = "SELECT COUNT(DISTINCT l.userid) AS total_login_user
    //     FROM mdl_logstore_standard_log AS l
    //     JOIN mdl_user_enrolments AS ue ON l.userid = ue.userid
    //     JOIN mdl_enrol AS e ON ue.enrolid = e.id
    //     JOIN mdl_groups_members AS gm ON l.userid = gm.userid
    //     JOIN mdl_role_assignments AS ra ON l.userid = ra.userid
    //     JOIN mdl_context AS ctx ON ra.contextid = ctx.id
    //     WHERE l.target = 'course'
    //       AND l.action = 'viewed'
    //       AND l.courseid = $courseid
    //       AND gm.groupid = $groupid
    //     --   AND ctx.contextlevel = 50  -- Make sure the context level corresponds to a course
    //       AND ra.roleid = (SELECT id FROM mdl_role WHERE id = $roleid)";

    //     // if($userid == 108001123){
    //     //     var_dump($sql_2);die;
    //     // }

    //     $mods1 = $DB->get_records_sql($sql_2);
    //     // var_dump($mods);die;
    //     foreach ($mods1 as $cm) {
    //         foreach ($result as &$courseResult) {
    //             $courseResult['total_login_user'] = $cm->total_login_user;
    //         }
    //     }
    //     return $result;
    // }

    public static function get_data_report($userid, $courseid, $groupid, $roleid)
    {
        global $CFG, $DB;

        $result = [];
        if (empty($courseid) || empty($groupid)) {
            return $result;
        }

        $sql = "
            SELECT 
                g.id AS groupid,
                c.id AS courseid,
                c.fullname AS coursename,
                g.name AS groupname,
                COUNT(DISTINCT CASE WHEN ra.roleid = ? THEN ra.userid END) AS total,
                COUNT(DISTINCT CASE 
                    WHEN l.action = 'completed' 
                        AND l.target = 'course' 
                        AND l.objecttable = 'course_completions' 
                    THEN l.relateduserid 
                END) AS relateduserid,
                COUNT(DISTINCT CASE 
                    WHEN l2.action = 'viewed'
                        AND l2.target = 'course'
                    THEN l2.userid 
                END) AS total_login_user
            FROM mdl_course c
            JOIN mdl_context ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
            JOIN mdl_groups g ON g.courseid = c.id
            JOIN mdl_groups_members gm ON gm.groupid = g.id
            JOIN mdl_role_assignments ra ON ra.userid = gm.userid AND ra.contextid = ctx.id
            LEFT JOIN mdl_logstore_standard_log l ON l.relateduserid = gm.userid AND l.courseid = c.id
            LEFT JOIN mdl_logstore_standard_log l2 ON l2.userid = gm.userid AND l2.courseid = c.id
            WHERE g.id IN (SELECT groupid FROM mdl_groups_members WHERE userid = ?)
            AND g.id = ? AND c.id = ?
            AND ra.roleid = ?
            GROUP BY c.id, c.fullname, g.id, g.name
        ";

        $params = [$roleid, $userid, $groupid, $courseid, $roleid];

        $records = $DB->get_records_sql($sql, $params);

        foreach ($records as $cm) {
            $percentage = ($cm->total > 0)
                ? round(($cm->relateduserid * 100.0) / $cm->total, 2)
                : 0;

            $result[] = [
                'groupid' => $cm->groupid,
                'courseid' => $cm->courseid,
                'coursename' => $cm->coursename,
                'groupname' => $cm->groupname,
                'total' => $cm->total,
                'relateduserid' => $cm->relateduserid,
                'percentage' => $percentage,
                'total_login_user' => $cm->total_login_user,
                'url' => $CFG->wwwroot
            ];
        }

        return $result;
    }


    public static function get_data_report_returns()
    {
        return new external_multiple_structure(
            new external_single_structure(
                array(
                    'groupid'   => new external_value(PARAM_INT, 'groupid'),
                    'courseid' => new external_value(PARAM_INT, 'courseid'),
                    'coursename'   => new external_value(PARAM_RAW, 'coursename'),
                    'groupname' => new external_value(PARAM_RAW, 'groupname'),
                    'total'   => new external_value(PARAM_INT, 'total'),
                    'relateduserid' => new external_value(PARAM_INT, 'relateduserid'),
                    'total_login_user' => new external_value(PARAM_INT, 'total_login_user'),
                    'percentage'   => new external_value(PARAM_RAW, 'percentage'),
                    'url' => new external_value(PARAM_RAW, 'url')
                )
            )
        );
    }


    // filter course or user

    public static function filter_parameters()
    {
        return new external_function_parameters(
            array(
                'courseid' => new external_value(PARAM_INT, 'courseid', VALUE_DEFAULT, 0),
                'coursename' => new external_value(PARAM_TEXT, 'coursename', VALUE_DEFAULT, 0),
                'userid' => new external_value(PARAM_INT, 'userid', VALUE_DEFAULT, 0),
                'useremail' => new external_value(PARAM_TEXT, 'useremail', VALUE_DEFAULT, 0),
                'fullname' => new external_value(PARAM_TEXT, 'fullname', VALUE_DEFAULT, 0),
                'type' => new external_value(PARAM_INT, 'type', VALUE_DEFAULT, 0),
                'limit' => new external_value(PARAM_INT, 'the number of results to return', VALUE_DEFAULT, 0),
                'offset' => new external_value(PARAM_INT, 'offset the result set by a given amount', VALUE_DEFAULT, 0)
            )
        );
    }

    public static function filter($courseid, $coursename, $userid, $useremail, $fullname, $type, $limit, $offset)
    {
        global $CFG, $USER, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/completionlib.php');

        // type 1: user, 2: course
        $result = ['count' => 0, 'contentfilter' => []];
        if (empty($type)) {
            return $result;
        }
        if ($type == 1) {
            $sql_1_count = "SELECT count(*) as total_count
                from mdl_user 
                where deleted = 0";

            if (!empty($fullname)) {
                $sql_1_count .= " AND CONCAT_WS(' ', firstname, lastname) LIKE '%$fullname%'";
            }
            if (!empty($useremail)) {
                $sql_1_count .= " and email like '%$useremail%'";
            }

            $mods_count = $DB->get_record_sql($sql_1_count);
            $result['count'] = $mods_count->total_count;

            $sql_1 = "SELECT id, firstname, lastname, email 
            from mdl_user 
            where deleted = 0";

            if (!empty($fullname)) {
                $sql_1 .= " AND CONCAT_WS(' ', firstname, lastname) LIKE '%$fullname%'";
            }
            if (!empty($useremail)) {
                $sql_1 .= " and email like '%$useremail%'";
            }
            if (!empty($limit)) {
                $sql_1 .= " LIMIT $limit OFFSET $offset";
            }

            $mods = $DB->get_records_sql($sql_1);
            // var_dump($mods);die;
            foreach ($mods as $cm) {
                $courseresult = [
                    'id'   => $cm->id,
                    'coursename' => '',
                    'firstname' => $cm->firstname,
                    'lastname'   => $cm->lastname,
                    'email' => $cm->email,
                    'view_url'    => $CFG->wwwroot . '/user/profile.php?id=' . $cm->id,
                ];
                $result['contentfilter'][] = $courseresult;
            }
        }
        if ($type == 2) {
            $sql_2_count = "SELECT count(*) as total_count
            FROM mdl_user u
            JOIN mdl_role_assignments ra ON u.id = ra.userid
            JOIN mdl_context ctx ON ra.contextid = ctx.id
            JOIN mdl_role r ON ra.roleid = r.id
            JOIN mdl_course c ON ctx.instanceid = c.id
            WHERE u.id = $userid AND (r.shortname = 'editingteacher' OR r.shortname = 'teacher')";

            if (!empty($courseid)) {
                $sql_2_count .= " and c.id = $courseid";
            }
            if (!empty($coursename)) {
                $sql_2_count .= " and c.fullname like '%$coursename%'";
            }

            $mods_count = $DB->get_record_sql($sql_2_count);
            $result['count'] = $mods_count->total_count;

            $sql_2 = "SELECT DISTINCT c.fullname, c.id
            FROM mdl_user u
            JOIN mdl_role_assignments ra ON u.id = ra.userid
            JOIN mdl_context ctx ON ra.contextid = ctx.id
            JOIN mdl_role r ON ra.roleid = r.id
            JOIN mdl_course c ON ctx.instanceid = c.id
            WHERE u.id = $userid AND (r.shortname = 'editingteacher' OR r.shortname = 'teacher')";

            if (!empty($courseid)) {
                $sql_2 .= " and c.id = $courseid";
            }
            if (!empty($coursename)) {
                $sql_2 .= " and c.fullname like '%$coursename%'";
            }
            if (!empty($limit)) {
                $sql_2 .= " LIMIT $limit OFFSET $offset";
            }
            $mods1 = $DB->get_records_sql($sql_2);
            // var_dump($mods);die;
            foreach ($mods1 as $cm) {
                $courseresult = [
                    'id'   => $cm->id,
                    'coursename' => $cm->fullname,
                    'firstname' => '',
                    'lastname'   => '',
                    'email' => '',
                    'view_url'    => $CFG->wwwroot . '/course/view.php?id=' . $cm->id,
                ];
                $result['contentfilter'][] = $courseresult;
            }
        }

        return $result;
    }

    public static function filter_returns()
    {
        return new external_single_structure([
            'count' => new external_value(PARAM_INT, 'count'),
            'contentfilter' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'id'),
                'coursename'   => new external_value(PARAM_RAW, 'coursename'),
                'view_url' => new external_value(PARAM_RAW, 'view_url'),
                'firstname' => new external_value(PARAM_RAW, 'firstname'),
                'lastname'   => new external_value(PARAM_RAW, 'lastname'),
                'email' => new external_value(PARAM_RAW, 'email'),
            ])),
        ]);
    }


    public static function lp_parameters()
    {
        return new external_function_parameters(
            array(
                'templateid' => new external_value(PARAM_INT, 'templateid', VALUE_DEFAULT, 0),
                'userid' => new external_value(PARAM_INT, 'userid', VALUE_DEFAULT, 0),
                'courseid' => new external_value(PARAM_INT, 'courseid', VALUE_DEFAULT, 0),
                'competencyid' => new external_value(PARAM_INT, 'competencyid', VALUE_DEFAULT, 0)
            )
        );
    }

    public static function lp($templateid, $userid, $courseid, $competencyid)
    {
        global $CFG, $USER, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/completionlib.php');

        $sql_1 = "SELECT shortname
            from mdl_competency_template 
            where id = $templateid";

        $mods_count = $DB->get_record_sql($sql_1);
        $lb_name = $mods_count->shortname;

        $sql_2_count = "SELECT count(*) as total_count
            FROM mdl_competency_plan cp
            WHERE cp.userid = $userid AND cp.templateid = $templateid";
        $mods_count1 = $DB->get_record_sql($sql_2_count);
        if ($mods_count1->total_count <= 0) {
            $dataObj = new stdClass();
            $dataObj->name = $lb_name;
            $dataObj->descriptionformat = 1;
            $dataObj->userid = $userid;
            $dataObj->templateid = $templateid;
            $dataObj->status = 1;
            $dataObj->duedate = 0;
            $dataObj->timecreated = strtotime(date("Y-m-d H:i:s"));
            $dataObj->usermodified = 0;
            $DB->insert_record('competency_plan', $dataObj);
        }
        // var_dump($lb_name);die;

        $sql_3_count = "SELECT count(*) as total_count
            FROM mdl_competency_usercomp cuc
            WHERE cuc.userid = $userid AND cuc.competencyid = $competencyid";
        $mods_count2 = $DB->get_record_sql($sql_3_count);
        if ($mods_count2->total_count <= 0) {
            $dataObj1 = new stdClass();
            $dataObj1->userid = $userid;
            $dataObj1->competencyid = $competencyid;
            $dataObj1->status = 0;
            $dataObj1->proficiency = 1;
            $dataObj1->grade = 2;
            $dataObj1->timecreated = strtotime(date("Y-m-d H:i:s"));
            $dataObj1->usermodified = 0;
            $DB->insert_record('competency_usercomp', $dataObj1);
        }

        $sql_4_count = "SELECT count(*) as total_count
            FROM mdl_competency_usercompcourse cucc
            WHERE cucc.userid = $userid AND cucc.competencyid = $competencyid AND cucc.courseid = $courseid";
        $mods_count3 = $DB->get_record_sql($sql_4_count);
        if ($mods_count3->total_count <= 0) {
            $dataObj2 = new stdClass();
            $dataObj2->userid = $userid;
            $dataObj2->courseid = $courseid;
            $dataObj2->competencyid = $competencyid;
            $dataObj2->proficiency = 1;
            $dataObj2->grade = 2;
            $dataObj2->timecreated = strtotime(date("Y-m-d H:i:s"));
            $dataObj2->usermodified = 0;
            $DB->insert_record('competency_usercompcourse', $dataObj2);
        }

        return ['success' => true];
    }

    public static function lp_returns()
    {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'success'),
        ]);
    }

    //get teacher course by all role

    public static function get_user_course_by_all_role_parameters()
    {
        return new external_function_parameters(
            array(
                'userid' => new external_value(PARAM_TEXT, 'user id'),
                'courseid' => new external_value(PARAM_TEXT, 'course id', VALUE_DEFAULT, 0),
                'coursename' => new external_value(PARAM_TEXT, 'coursename', VALUE_DEFAULT, 0),
                'limit' => new external_value(PARAM_INT, 'the number of results to return', VALUE_DEFAULT, 0),
                'offset' => new external_value(PARAM_INT, 'offset the result set by a given amount', VALUE_DEFAULT, 0)
            )
        );
    }

    public static function get_user_course_by_all_role($userid, $courseid, $coursename, $limit, $offset)
    {
        global $CFG, $USER, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/completionlib.php');

        $result = ['count' => 0, 'datacalendar' => []];

        $sql_count = "SELECT count(DISTINCT c.id) as total_count
        FROM mdl_user u
        JOIN mdl_role_assignments ra ON u.id = ra.userid
        JOIN mdl_context ctx ON ra.contextid = ctx.id
        JOIN mdl_role r ON ra.roleid = r.id
        JOIN mdl_course c ON ctx.instanceid = c.id
        WHERE u.id = :userid";

        $params = ['userid' => $userid];

        if (!empty($coursename)) {
            $sql_count .= " AND c.fullname LIKE :coursename";
            $params['coursename'] = '%' . $coursename . '%';
        }

        $mods_count = $DB->get_record_sql($sql_count, $params);
        $result['count'] = $mods_count->total_count;

        $sql = "SELECT DISTINCT c.fullname as coursename, c.id
        FROM mdl_user u
        JOIN mdl_role_assignments ra ON u.id = ra.userid
        JOIN mdl_context ctx ON ra.contextid = ctx.id
        JOIN mdl_role r ON ra.roleid = r.id
        JOIN mdl_course c ON ctx.instanceid = c.id
        WHERE u.id = :userid";

        $params = ['userid' => $userid];

        if (!empty($courseid)) {
            $sql .= " AND c.id = :courseid";
            $params['courseid'] = $courseid;
        }

        if (!empty($coursename)) {
            $sql .= " AND c.fullname LIKE :coursename";
            $params['coursename'] = '%' . $coursename . '%';
        }

        if (!empty($limit)) {
            $sql .= " LIMIT $limit OFFSET $offset";
        }

        $mods = $DB->get_records_sql($sql, $params);
        // var_dump($mods);die;
        foreach ($mods as $cm) {
            $courseresult = [
                'courseid'   => $cm->id,
                'coursename' => $cm->coursename,
                'view_url'    => $CFG->wwwroot . '/course/view.php?id=' . $cm->id,
            ];
            $result['datacourse'][] = $courseresult;
        }
        return $result;
    }

    public static function get_user_course_by_all_role_returns()
    {
        return new external_single_structure([
            'count' => new external_value(PARAM_INT, 'count'),
            'datacourse' => new external_multiple_structure(new external_single_structure([
                'courseid' => new external_value(PARAM_INT, 'courseid'),
                'coursename' => new external_value(PARAM_RAW, 'coursename'),
                'view_url' => new external_value(PARAM_RAW, 'view_url')
            ])),
        ]);
    }



    // get teacher course learning

    public static function get_user_course_learning_parameters()
    {
        return new external_function_parameters(
            array(
                'userid' => new external_value(PARAM_TEXT, 'user id'),
                'courseid' => new external_value(PARAM_TEXT, 'course id', VALUE_DEFAULT, 0),
                'coursename' => new external_value(PARAM_TEXT, 'coursename', VALUE_DEFAULT, 0),
                'search' => new external_value(PARAM_TEXT, 'Search term for course id or course name', VALUE_DEFAULT, ''),
                'limit' => new external_value(PARAM_INT, 'the number of results to return', VALUE_DEFAULT, 0),
                'offset' => new external_value(PARAM_INT, 'offset the result set by a given amount', VALUE_DEFAULT, 0)
            )
        );
    }

    public static function get_user_course_learning($userid, $courseid, $coursename, $search, $limit, $offset)
    {
        global $CFG, $USER, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/completionlib.php');

        $result = ['count' => 0, 'datacalendar' => []];

        $sql_count = "SELECT count(DISTINCT c.id) as total_count
        FROM mdl_user u
        JOIN mdl_role_assignments ra ON u.id = ra.userid
        JOIN mdl_context ctx ON ra.contextid = ctx.id
        JOIN mdl_role r ON ra.roleid = r.id
        JOIN mdl_course c ON ctx.instanceid = c.id
        WHERE u.id = :userid AND r.shortname = 'student'";

        $params = ['userid' => $userid];

        // if (!empty($coursename)) {
        //     $sql_count .= " AND c.fullname LIKE :coursename";
        //     $params['coursename'] = '%' . $coursename . '%';
        // }

        if (!empty($search)) {
            $sql_count .= " AND (c.fullname LIKE :search OR c.id = :searchint)";
            $params['search'] = '%' . $search . '%';
            $params['searchint'] = is_numeric($search) ? (int)$search : -1;
        }

        $mods_count = $DB->get_record_sql($sql_count, $params);
        $result['count'] = $mods_count->total_count;

        $sql = "SELECT DISTINCT c.fullname as coursename, c.id
        FROM mdl_user u
        JOIN mdl_role_assignments ra ON u.id = ra.userid
        JOIN mdl_context ctx ON ra.contextid = ctx.id
        JOIN mdl_role r ON ra.roleid = r.id
        JOIN mdl_course c ON ctx.instanceid = c.id
        WHERE u.id = :userid AND r.shortname = 'student'";

        $params = ['userid' => $userid];

        if (!empty($courseid)) {
            $sql .= " AND c.id = :courseid";
            $params['courseid'] = $courseid;
        }

        // if (!empty($coursename)) {
        //     $sql .= " AND c.fullname LIKE :coursename";
        //     $params['coursename'] = '%' . $coursename . '%';
        // }

        if (!empty($search)) {
            $sql .= " AND (c.fullname LIKE :search OR c.id = :searchint)";
            $params['search'] = '%' . $search . '%';
            $params['searchint'] = is_numeric($search) ? (int)$search : -1;
        }

        if (!empty($limit)) {
            $sql .= " LIMIT $limit OFFSET $offset";
        }

        $mods = $DB->get_records_sql($sql, $params);
        // var_dump($mods);die;
        foreach ($mods as $cm) {
            $courseresult = [
                'courseid'   => $cm->id,
                'coursename' => $cm->coursename,
                'view_url'    => $CFG->wwwroot . '/course/view.php?id=' . $cm->id,
            ];
            $result['datacourse'][] = $courseresult;
        }
        return $result;
    }

    public static function get_user_course_learning_returns()
    {
        return new external_single_structure([
            'count' => new external_value(PARAM_INT, 'count'),
            'datacourse' => new external_multiple_structure(new external_single_structure([
                'courseid' => new external_value(PARAM_INT, 'courseid'),
                'coursename' => new external_value(PARAM_RAW, 'coursename'),
                'view_url' => new external_value(PARAM_RAW, 'view_url')
            ])),
        ]);
    }


    // get teacher course learning

    public static function get_user_course_completed_parameters()
    {
        return new external_function_parameters(
            array(
                'userid' => new external_value(PARAM_TEXT, 'user id'),
                'courseid' => new external_value(PARAM_TEXT, 'course id', VALUE_DEFAULT, 0),
                'coursename' => new external_value(PARAM_TEXT, 'coursename', VALUE_DEFAULT, 0),
                'search' => new external_value(PARAM_TEXT, 'Search term for course id or course name', VALUE_DEFAULT, ''),
                'limit' => new external_value(PARAM_INT, 'the number of results to return', VALUE_DEFAULT, 0),
                'offset' => new external_value(PARAM_INT, 'offset the result set by a given amount', VALUE_DEFAULT, 0)
            )
        );
    }

    public static function get_user_course_completed($userid, $courseid, $coursename, $search, $limit, $offset)
    {
        global $CFG, $USER, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/completionlib.php');

        $result = ['count' => 0, 'datacalendar' => []];

        $sql_count = "SELECT count(DISTINCT c.id) as total_count
                FROM mdl_course_completions cc
                JOIN mdl_course c ON cc.course = c.id
                WHERE cc.userid = :userid AND cc.timecompleted IS NOT NULL";

        $params = ['userid' => $userid];

        // if (!empty($coursename)) {
        //     $sql_count .= " AND c.fullname LIKE :coursename";
        //     $params['coursename'] = '%' . $coursename . '%';
        // }

        if (!empty($search)) {
            $sql_count .= " AND (c.fullname LIKE :search OR c.id = :searchint)";
            $params['search'] = '%' . $search . '%';
            $params['searchint'] = is_numeric($search) ? (int)$search : -1;
        }

        $mods_count = $DB->get_record_sql($sql_count, $params);
        $result['count'] = $mods_count->total_count;

        $sql = "SELECT DISTINCT c.fullname as coursename, c.id
                FROM mdl_course_completions cc
                JOIN mdl_course c ON cc.course = c.id
                WHERE cc.userid = :userid AND cc.timecompleted IS NOT NULL";

        $params = ['userid' => $userid];

        if (!empty($courseid)) {
            $sql .= " AND c.id = :courseid";
            $params['courseid'] = $courseid;
        }

        // if (!empty($coursename)) {
        //     $sql .= " AND c.fullname LIKE :coursename";
        //     $params['coursename'] = '%' . $coursename . '%';
        // }

        if (!empty($search)) {
            $sql .= " AND (c.fullname LIKE :search OR c.id = :searchint)";
            $params['search'] = '%' . $search . '%';
            $params['searchint'] = is_numeric($search) ? (int)$search : -1;
        }

        if (!empty($limit)) {
            $sql .= " LIMIT $limit OFFSET $offset";
        }

        $mods = $DB->get_records_sql($sql, $params);
        // var_dump($mods);die;
        foreach ($mods as $cm) {
            $courseresult = [
                'courseid'   => $cm->id,
                'coursename' => $cm->coursename,
                'view_url'    => $CFG->wwwroot . '/course/view.php?id=' . $cm->id,
            ];
            $result['datacourse'][] = $courseresult;
        }
        return $result;
    }

    public static function get_user_course_completed_returns()
    {
        return new external_single_structure([
            'count' => new external_value(PARAM_INT, 'count'),
            'datacourse' => new external_multiple_structure(new external_single_structure([
                'courseid' => new external_value(PARAM_INT, 'courseid'),
                'coursename' => new external_value(PARAM_RAW, 'coursename'),
                'view_url' => new external_value(PARAM_RAW, 'view_url')
            ])),
        ]);
    }



    //new filter (kien)
    public static function new_filter_parameters()
    {
        return new external_function_parameters(
            array(
                'courseid' => new external_value(PARAM_INT, 'courseid', VALUE_DEFAULT, 0),
                'coursename' => new external_value(PARAM_TEXT, 'coursename', VALUE_DEFAULT, 0),
                'groupid' => new external_value(PARAM_INT, 'groupid', VALUE_DEFAULT, 0),
                'groupname' => new external_value(PARAM_TEXT, 'groupname', VALUE_DEFAULT, 0),
                'userid' => new external_value(PARAM_INT, 'userid', VALUE_DEFAULT, 0),
                'useremail' => new external_value(PARAM_TEXT, 'useremail', VALUE_DEFAULT, 0),
                'fullname' => new external_value(PARAM_TEXT, 'fullname', VALUE_DEFAULT, 0),
                'limit' => new external_value(PARAM_INT, 'the number of results to return', VALUE_DEFAULT, 0),
                'offset' => new external_value(PARAM_INT, 'offset the result set by a given amount', VALUE_DEFAULT, 0)
            )
        );
    }

    public static function new_filter($courseid, $coursename, $groupid, $groupname, $userid, $useremail, $fullname, $limit, $offset)
    {
        global $CFG, $USER, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/completionlib.php');

        $result = ['count' => 0, 'contentfilter' => []];

        $sql_count = "SELECT count(DISTINCT u.id) as total_count
            FROM mdl_user u
            JOIN mdl_groups_members gm ON gm.userid = u.id
            JOIN mdl_groups g ON g.id = gm.groupid
            WHERE g.id IN (
                SELECT g.id
                FROM mdl_groups_members gm
                JOIN mdl_groups g ON g.id = gm.groupid
                WHERE gm.userid = :userid
            )";

        $params = ['userid' => $userid];

        if (!empty($courseid)) {
            $sql_count .= " AND g.courseid = :courseid";
            $params['courseid'] = $courseid;
        }
        if (!empty($groupid)) {
            $sql_count .= " AND g.id = :groupid";
            $params['groupid'] = $groupid;
        }
        if (!empty($fullname)) {
            $sql_count .= " AND CONCAT_WS(' ', u.firstname, u.lastname) LIKE :fullname";
            $params['fullname'] = '%' . $fullname . '%';
        }
        if (!empty($useremail)) {
            $sql_count .= " AND u.email LIKE :useremail";
            $params['useremail'] = '%' . $useremail . '%';
        }

        $mods_count = $DB->get_record_sql($sql_count, $params);
        $result['count'] = $mods_count->total_count;

        $sql = "SELECT u.id AS user_id, u.firstname, u.lastname, u.email
            FROM mdl_user u
            JOIN mdl_groups_members gm ON gm.userid = u.id
            JOIN mdl_groups g ON g.id = gm.groupid
            WHERE g.id IN (
                SELECT g.id
                FROM mdl_groups_members gm
                JOIN mdl_groups g ON g.id = gm.groupid
                WHERE gm.userid = $userid
            )";

        if (!empty($courseid)) {
            $sql .= " AND g.courseid = $courseid";
        }
        if (!empty($groupid)) {
            $sql .= " AND g.id = $groupid";
        }
        if (!empty($fullname)) {
            $sql .= " AND CONCAT_WS(' ', u.firstname, u.lastname) LIKE '%$fullname%'";
        }
        if (!empty($useremail)) {
            $sql .= " AND u.email LIKE '%$useremail%'";
        }

        $sql .= " GROUP BY u.id, u.firstname, u.lastname, u.email ORDER BY g.courseid, u.id";

        if (!empty($limit)) {
            $sql .= " LIMIT $limit OFFSET $offset";
        }
        // var_dump($sql, $courseid, $coursename, $groupid, $groupname, $userid, $useremail, $fullname, $limit, $offset, $params);die;
        $mods = $DB->get_records_sql($sql);

        foreach ($mods as $cm) {
            $courseresult = [
                'id' => $cm->user_id,
                'firstname' => $cm->firstname,
                'lastname' => $cm->lastname,
                'email' => $cm->email,
                'view_url' => $CFG->wwwroot . '/user/profile.php?id=' . $cm->user_id,
            ];
            $result['contentfilter'][] = $courseresult;
        }
        return $result;
    }

    public static function new_filter_returns()
    {
        return new external_single_structure([
            'count' => new external_value(PARAM_INT, 'count'),
            'contentfilter' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'id'),
                'view_url' => new external_value(PARAM_RAW, 'view_url'),
                'firstname' => new external_value(PARAM_RAW, 'firstname'),
                'lastname'   => new external_value(PARAM_RAW, 'lastname'),
                'email' => new external_value(PARAM_RAW, 'email'),
            ])),
        ]);
    }


    public static function ai_get_course_content_parameters()
    {
        return new external_function_parameters(
            array(
                'courseid' => new external_value(PARAM_INT, 'courseid', VALUE_DEFAULT, 0),
                'page' => new external_value(PARAM_INT, 'Page number', VALUE_DEFAULT, 1),
                'perpage' => new external_value(PARAM_INT, 'Items per page', VALUE_DEFAULT, 10),
            )
        );
    }

    public static function ai_get_course_content($courseid, $page, $perpage)
    {
        global $DB, $CFG;

        require_once($CFG->libdir . '/completionlib.php');

        // Initialize result structure
        $result = [];
        // If courseid is not provided, fetch all courses and apply pagination
        if ($courseid == 0) {
            $offset = ($page - 1) * $perpage;
            $courses = $DB->get_records('course', null, '', '*', $offset, $perpage);
        } else {
            $courses = [$DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST)];
        }

        foreach ($courses as $course) {
            // Get list of all modules
            $modules = $DB->get_records_sql('SELECT name FROM {modules}');

            $union_queries = [];

            foreach ($modules as $module) {
                $module_name = $module->name;
                $union_queries[] = "SELECT id, '$module_name' AS module_name, name, intro FROM {" . $module_name . "}";
            }

            // Combine all module queries
            $activity_query = implode(' UNION ALL ', $union_queries);

            // Build the main query
            $sql = "
                SELECT 
                    cm.id AS cm_id,
                    cs.id AS section_id,
                    cs.course AS course_id,
                    cs.section AS section_number,
                    cs.name AS section_name,
                    cs.summary AS section_summary,
                    cm.module AS module_id,
                    cm.instance AS instance_id,
                    m.name AS module_name,
                    a.name AS activity_name,
                    a.intro AS activity_intro
                FROM 
                    {course_sections} cs
                JOIN 
                    {course_modules} cm ON cm.section = cs.id
                JOIN 
                    {modules} m ON m.id = cm.module
                LEFT JOIN 
                    ($activity_query) a ON a.id = cm.instance AND a.module_name = m.name
                WHERE 
                    cs.course = :course_id
                    AND cm.deletioninprogress = 0 -- Ensure the module is not in the process of being deleted
                    AND cm.visible = 1 -- Ensure the module is visible
                ORDER BY 
                    cs.section, cm.id;
            ";

            $params = ['course_id' => $course->id];
            $results = $DB->get_records_sql($sql, $params);

            // Process results
            $section_data = [];
            foreach ($results as $record) {

                if ($record->section_number == 0) continue;

                $section_id = $record->section_id;
                if (!isset($section_data[$section_id])) {
                    $section_data[$section_id] = [
                        'id' => $record->section_id,
                        'name' => $record->section_name ?? 'Topic ' . $record->section_number,
                        'visible' => 1, // You may need to adjust this based on your actual data
                        'summary' => $record->section_summary,
                        'summaryformat' => 1, // You may need to adjust this based on your actual data
                        'section' => $record->section_number,
                        'hiddenbynumsections' => 0, // You may need to adjust this based on your actual data
                        'uservisible' => true, // You may need to adjust this based on your actual data
                        'listVideo' => [],
                        'listUrl' => [],
                        'listPDF' => [],
                        'listOther' => []
                    ];
                }

                // Add module data to section
                $module_data = [
                    'id' => $record->cm_id,
                    'url' => $CFG->wwwroot . '/mod/' . $record->module_name . '/view.php?id=' . $record->cm_id,
                    'name' => $record->activity_name,
                    'instance' => $record->instance_id,
                    'contextid' => 0, // Provide a default value or remove this if not used
                    'visible' => 1, // You may need to adjust this based on your actual data
                    'uservisible' => true, // You may need to adjust this based on your actual data
                    'visibleoncoursepage' => 1, // You may need to adjust this based on your actual data
                    'modicon' => $CFG->wwwroot . '/theme/image.php/remui/' . $record->module_name . '/1718852273/monologo',
                    'modname' => $record->module_name,
                    'modplural' => ucfirst($record->module_name) . 's', // You may need to adjust this based on your actual data
                    'contents' => [], // Placeholder for contents
                    'url_type' => ''
                ];

                if ($record->module_name == 'h5pactivity') {
                    $h5p_content = $DB->get_record('h5p', ['id' => $record->instance_id]);
                    if ($h5p_content) {
                        $jsoncontent = json_decode($h5p_content->jsoncontent);
                        if (isset($jsoncontent->interactiveVideo->video->files[0]->path)) {
                            $module_data['url'] = $jsoncontent->interactiveVideo->video->files[0]->path;
                        }
                        if (isset($jsoncontent->params->interactiveVideo->video->files[0]->path)) {
                            $module_data['url'] = $jsoncontent->params->interactiveVideo->video->files[0]->path;
                        }
                    }
                    $module_data['url_type'] = 'streaming';
                    $section_data[$section_id]['listVideo'][] = $module_data;
                } elseif ($record->module_name == 'hvp') {
                    $hvp_content = $DB->get_record('hvp', ['id' => $record->instance_id]);
                    if ($hvp_content) {
                        $jsoncontent = json_decode($hvp_content->json_content);
                        if (isset($jsoncontent->interactiveVideo->video->files[0]->path)) {
                            $module_data['url'] = $jsoncontent->interactiveVideo->video->files[0]->path;
                        }
                        if (isset($jsoncontent->params->interactiveVideo->video->files[0]->path)) {
                            $module_data['url'] = $jsoncontent->params->interactiveVideo->video->files[0]->path;
                        }
                    }
                    $module_data['url_type'] = 'streaming';
                    $section_data[$section_id]['listVideo'][] = $module_data;
                } elseif ($record->module_name == 'checkmatepdf') {
                    $checkmatepdf_content = $DB->get_record('checkmatepdf', ['id' => $record->instance_id]);
                    if ($checkmatepdf_content && $checkmatepdf_content->url) {
                        $module_data['url'] = $checkmatepdf_content->url;
                    }
                    $module_data['url_type'] = 'pdf';
                    $section_data[$section_id]['listPDF'][] = $module_data;
                } elseif ($record->module_name == 'url') {
                    $url_content = $DB->get_record('url', ['id' => $record->instance_id]);
                    if ($url_content) {
                        $module_data['url'] = $url_content->externalurl;
                    }
                    $module_data['url_type'] = 'link'; // Change to link or url
                    $section_data[$section_id]['listUrl'][] = $module_data;
                } else {
                    $section_data[$section_id]['listOther'][] = $module_data; // Default to video nếu không khớp với các trường hợp khác
                }
            }

            // Convert section data to indexed array
            $listSection = array_values($section_data);

            $thumbnail = $CFG->wwwroot . "/pluginfile.php/1/course/overviewfiles/default.jpg"; // Ảnh mặc định

            $context = context_course::instance($course->id);
            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'course', 'overviewfiles', 0, 'sortorder', false);

            foreach ($files as $file) {
                $filename = $file->get_filename();
                if ($filename !== '.') {
                    $thumbnail = moodle_url::make_pluginfile_url(
                        $file->get_contextid(),
                        $file->get_component(),
                        $file->get_filearea(),
                        0, // Bỏ số 0 bằng cách thay đổi giá trị itemid thành 0 nếu không cần thiết
                        $file->get_filepath(),
                        $file->get_filename()
                    )->out();
            
                    // Xóa '/0/' trong đường dẫn nếu tồn tại
                    $thumbnail = str_replace('/0/', '/', $thumbnail);
                    break; // Chỉ lấy file đầu tiên
                }
            }

            if ($course->id == 1) continue;
            // Build the final result structure for each course
            $result[] = [
                'idCourse' => $course->id,
                'name' => $course->fullname, // Adjust field if needed
                'description' => $course->summary, // Adjust field if needed
                'thumbnail' => $thumbnail, // Adjust URL if needed
                'listSection' => $listSection
            ];
        }

        // Return the result
        return $result;
    }

    public static function ai_get_course_content_returns()
    {
        return new external_multiple_structure(
            new external_single_structure([
                'idCourse' => new external_value(PARAM_INT, 'Course ID'),
                'name' => new external_value(PARAM_TEXT, 'Course Name'),
                'description' => new external_value(PARAM_RAW, 'Course Description'),
                'thumbnail' => new external_value(PARAM_RAW, 'Course Thumbnail URL'),
                'listSection' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'Section ID'),
                        'name' => new external_value(PARAM_RAW, 'Section Name'),
                        'visible' => new external_value(PARAM_INT, 'Visible'),
                        'summary' => new external_value(PARAM_RAW, 'Summary'),
                        'summaryformat' => new external_value(PARAM_INT, 'Summary Format'),
                        'section' => new external_value(PARAM_INT, 'Section Number'),
                        'hiddenbynumsections' => new external_value(PARAM_INT, 'Hidden by Num Sections'),
                        'uservisible' => new external_value(PARAM_BOOL, 'User Visible'),
                        'listVideo' => new external_multiple_structure(
                            new external_single_structure([
                                'id' => new external_value(PARAM_INT, 'Module ID'),
                                'url' => new external_value(PARAM_RAW, 'Module URL'),
                                'name' => new external_value(PARAM_RAW, 'Module Name'),
                                'instance' => new external_value(PARAM_INT, 'Instance ID'),
                                'contextid' => new external_value(PARAM_INT, 'Context ID', VALUE_OPTIONAL),
                                'visible' => new external_value(PARAM_INT, 'Visible'),
                                'uservisible' => new external_value(PARAM_BOOL, 'User Visible'),
                                'visibleoncoursepage' => new external_value(PARAM_INT, 'Visible on Course Page'),
                                'modicon' => new external_value(PARAM_RAW, 'Module Icon URL'),
                                'modname' => new external_value(PARAM_RAW, 'Module Type'),
                                'modplural' => new external_value(PARAM_RAW, 'Module Plural'),
                                'url_type' => new external_value(PARAM_RAW, 'Url Type'),
                                'contents' => new external_multiple_structure(
                                    new external_single_structure([
                                        'type' => new external_value(PARAM_RAW, 'Content Type'),
                                        'filename' => new external_value(PARAM_RAW, 'File Name'),
                                        'filepath' => new external_value(PARAM_RAW, 'File Path'),
                                        'filesize' => new external_value(PARAM_INT, 'File Size'),
                                        'fileurl' => new external_value(PARAM_RAW, 'File URL'),
                                        'timecreated' => new external_value(PARAM_INT, 'Time Created'),
                                        'timemodified' => new external_value(PARAM_INT, 'Time Modified'),
                                        'sortorder' => new external_value(PARAM_INT, 'Sort Order'),
                                        'userid' => new external_value(PARAM_INT, 'User ID'),
                                        'author' => new external_value(PARAM_RAW, 'Author'),
                                        'license' => new external_value(PARAM_RAW, 'License')
                                    ])
                                )
                            ])
                        ),
                        'listPDF' => new external_multiple_structure(
                            new external_single_structure([
                                'id' => new external_value(PARAM_INT, 'Module ID'),
                                'url' => new external_value(PARAM_RAW, 'Module URL'),
                                'name' => new external_value(PARAM_RAW, 'Module Name'),
                                'instance' => new external_value(PARAM_INT, 'Instance ID'),
                                'contextid' => new external_value(PARAM_INT, 'Context ID', VALUE_OPTIONAL),
                                'visible' => new external_value(PARAM_INT, 'Visible'),
                                'uservisible' => new external_value(PARAM_BOOL, 'User Visible'),
                                'visibleoncoursepage' => new external_value(PARAM_INT, 'Visible on Course Page'),
                                'modicon' => new external_value(PARAM_RAW, 'Module Icon URL'),
                                'modname' => new external_value(PARAM_RAW, 'Module Type'),
                                'modplural' => new external_value(PARAM_RAW, 'Module Plural'),
                                'url_type' => new external_value(PARAM_RAW, 'Url Type'),
                                'contents' => new external_multiple_structure(
                                    new external_single_structure([
                                        'type' => new external_value(PARAM_RAW, 'Content Type'),
                                        'filename' => new external_value(PARAM_RAW, 'File Name'),
                                        'filepath' => new external_value(PARAM_RAW, 'File Path'),
                                        'filesize' => new external_value(PARAM_INT, 'File Size'),
                                        'fileurl' => new external_value(PARAM_RAW, 'File URL'),
                                        'timecreated' => new external_value(PARAM_INT, 'Time Created'),
                                        'timemodified' => new external_value(PARAM_INT, 'Time Modified'),
                                        'sortorder' => new external_value(PARAM_INT, 'Sort Order'),
                                        'userid' => new external_value(PARAM_INT, 'User ID'),
                                        'author' => new external_value(PARAM_RAW, 'Author'),
                                        'license' => new external_value(PARAM_RAW, 'License')
                                    ])
                                )
                            ])
                        ),
                        'listUrl' => new external_multiple_structure(
                            new external_single_structure([
                                'id' => new external_value(PARAM_INT, 'Module ID'),
                                'url' => new external_value(PARAM_RAW, 'Module URL'),
                                'name' => new external_value(PARAM_RAW, 'Module Name'),
                                'instance' => new external_value(PARAM_INT, 'Instance ID'),
                                'contextid' => new external_value(PARAM_INT, 'Context ID', VALUE_OPTIONAL),
                                'visible' => new external_value(PARAM_INT, 'Visible'),
                                'uservisible' => new external_value(PARAM_BOOL, 'User Visible'),
                                'visibleoncoursepage' => new external_value(PARAM_INT, 'Visible on Course Page'),
                                'modicon' => new external_value(PARAM_RAW, 'Module Icon URL'),
                                'modname' => new external_value(PARAM_RAW, 'Module Type'),
                                'modplural' => new external_value(PARAM_RAW, 'Module Plural'),
                                'url_type' => new external_value(PARAM_RAW, 'Url Type'),
                                'contents' => new external_multiple_structure(
                                    new external_single_structure([
                                        'type' => new external_value(PARAM_RAW, 'Content Type'),
                                        'filename' => new external_value(PARAM_RAW, 'File Name'),
                                        'filepath' => new external_value(PARAM_RAW, 'File Path'),
                                        'filesize' => new external_value(PARAM_INT, 'File Size'),
                                        'fileurl' => new external_value(PARAM_RAW, 'File URL'),
                                        'timecreated' => new external_value(PARAM_INT, 'Time Created'),
                                        'timemodified' => new external_value(PARAM_INT, 'Time Modified'),
                                        'sortorder' => new external_value(PARAM_INT, 'Sort Order'),
                                        'userid' => new external_value(PARAM_INT, 'User ID'),
                                        'author' => new external_value(PARAM_RAW, 'Author'),
                                        'license' => new external_value(PARAM_RAW, 'License')
                                    ])
                                )
                            ])
                        ),
                        'listOther' => new external_multiple_structure(
                            new external_single_structure([
                                'id' => new external_value(PARAM_INT, 'Module ID'),
                                'url' => new external_value(PARAM_RAW, 'Module URL'),
                                'name' => new external_value(PARAM_RAW, 'Module Name'),
                                'instance' => new external_value(PARAM_INT, 'Instance ID'),
                                'contextid' => new external_value(PARAM_INT, 'Context ID', VALUE_OPTIONAL),
                                'visible' => new external_value(PARAM_INT, 'Visible'),
                                'uservisible' => new external_value(PARAM_BOOL, 'User Visible'),
                                'visibleoncoursepage' => new external_value(PARAM_INT, 'Visible on Course Page'),
                                'modicon' => new external_value(PARAM_RAW, 'Module Icon URL'),
                                'modname' => new external_value(PARAM_RAW, 'Module Type'),
                                'modplural' => new external_value(PARAM_RAW, 'Module Plural'),
                                'url_type' => new external_value(PARAM_RAW, 'Url Type'),
                                'contents' => new external_multiple_structure(
                                    new external_single_structure([
                                        'type' => new external_value(PARAM_RAW, 'Content Type'),
                                        'filename' => new external_value(PARAM_RAW, 'File Name'),
                                        'filepath' => new external_value(PARAM_RAW, 'File Path'),
                                        'filesize' => new external_value(PARAM_INT, 'File Size'),
                                        'fileurl' => new external_value(PARAM_RAW, 'File URL'),
                                        'timecreated' => new external_value(PARAM_INT, 'Time Created'),
                                        'timemodified' => new external_value(PARAM_INT, 'Time Modified'),
                                        'sortorder' => new external_value(PARAM_INT, 'Sort Order'),
                                        'userid' => new external_value(PARAM_INT, 'User ID'),
                                        'author' => new external_value(PARAM_RAW, 'Author'),
                                        'license' => new external_value(PARAM_RAW, 'License')
                                    ])
                                )
                            ])
                        ),
                    ])
                )
            ])
        );
    }


    public static function get_course_completed_user_emails_parameters()
    {
        return new external_function_parameters(
            array(
                'courseids' => new external_value(PARAM_TEXT, 'Comma separated list of Course IDs', VALUE_REQUIRED),
                'email' => new external_value(PARAM_TEXT, 'User email', VALUE_REQUIRED),
            )
        );
    }

    public static function get_course_completed_user_emails($courseids, $email)
    {
        global $DB;

        $courseid_array = explode(',', $courseids);

        $courseid_array = array_filter($courseid_array, 'is_numeric');

        if (empty($courseid_array)) {
            return [];
        }

        if (empty($email)) {
            return [];
        }

        $valueEmails = [$email];

        $user = core_user_external::get_users_by_field('email', $valueEmails);

        if (empty($user)) {
            return [];
        }

        $userId = $user[0]['id'];

        $result = [];

        foreach ($courseid_array as $courseid) {
            // $sql = "
            //     SELECT u.email
            //     FROM {course_completions} AS cp
            //     JOIN {course} AS c ON cp.course = c.id
            //     JOIN {user} AS u ON cp.userid = u.id
            //     WHERE c.enablecompletion = 1 
            //     AND c.id = :courseid
            //     AND cp.timecompleted IS NOT NULL
            // ";

            // $params = ['courseid' => $courseid];

            // $emails = $DB->get_fieldset_sql($sql, $params);

            // $result[] = [
            //     'courseid' => $courseid,
            //     'emails' => $emails,
            // ];

            $get_activities_completion_status = core_completion_external::get_activities_completion_status($courseid, $userId);
            if (!empty($get_activities_completion_status['statuses'])) {
                $totalActivity = count($get_activities_completion_status['statuses']);
                $completedActivities = array_filter($get_activities_completion_status['statuses'], function ($status) {
                    return $status['state'] == 1 || $status['state'] == 2;
                });
                $numberActivityCompletion = count($completedActivities);
                $totalActivityDue = $totalActivity - count($completedActivities);
            }
            // Sử dụng try-catch để xử lý trường hợp không có tiêu chí hoàn thành
            try {
                $get_course_completion_status = core_completion_external::get_course_completion_status($courseid, $userId);

                if (!empty($get_course_completion_status['completionstatus']['completions'])) {
                    $completionStatus = $get_course_completion_status['completionstatus'];
                    $totalCompletions = count($completionStatus['completions']);
                    $completedCompletions = array_filter($completionStatus['completions'], function ($completion) {
                        return isset($completion['complete']) && $completion['complete'] === true;
                    });
    
                    $completedCount = count($completedCompletions);
    
                    $hasOtherType = array_reduce($completionStatus['completions'], function ($carry, $completion) {
                        return $carry || (int)$completion['type'] !== 4;
                    }, false);

                    if ($hasOtherType) {
                        $completionPercentage = $totalActivity > 0
                            ? round(($numberActivityCompletion / $totalActivity) * 100, 2)
                            : 0;
                    } else {
                        $completionPercentage = $totalCompletions > 0
                            ? round(($completedCount / $totalCompletions) * 100, 2)
                            : 0;
                    }
                }else{
                    $completionPercentage = $totalActivity > 0
                        ? round(($numberActivityCompletion / $totalActivity) * 100, 2)
                        : 0;
                }
            } catch (moodle_exception $e) {
                $completionPercentage = $totalActivity > 0
                    ? round(($numberActivityCompletion / $totalActivity) * 100, 2)
                    : 0;
            }

            if (isset($completionPercentage) && $completionPercentage == 100) {
                $result[] = [
                    'courseid' => $courseid,
                    'emails' => [$email],
                ];

            }
        }

        return $result;
    }


    public static function get_course_completed_user_emails_returns()
    {
        return new external_multiple_structure(
            new external_single_structure(
                array(
                    'courseid' => new external_value(PARAM_INT, 'Course ID'),
                    'emails' => new external_multiple_structure(
                        new external_value(PARAM_TEXT, 'User Email')
                    )
                )
            )
        );
    }


    // Functionset for get_sections() *********************************************************************************************.

    /**
     * Parameter description for get_sections().
     *
     * @return external_function_parameters.
     */
    public static function get_sections_parameters()
    {
        return new external_function_parameters(
            array(
                'courseid' => new external_value(PARAM_INT, 'id of course'),
                'sectionnumbers' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'sectionnumber (position of section)'),
                    'List of sectionnumbers. Wrong numbers will be ignored.
                                If list of sectionnumbers and list of sectionids are empty
                                then return infos of all sections of the given course.',
                    VALUE_DEFAULT,
                    array()
                ),
                'sectionids' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'id of section'),
                    'List of sectionids. Wrong ids will be ignored.
                                If list of sectionnumbers and list of sectionids are empty
                                then return infos of all sections of the given course.',
                    VALUE_DEFAULT,
                    array()
                )
            )
        );
    }

    /**
     * Get sectioninfos.
     *
     * This function returns sectioninfos.
     *
     * @param int $courseid Courseid of the belonging course.
     * @param array $sectionnumbers Array of sectionnumbers (int, optional).
     * @param array $sectionids Array of section ids (int, optional).
     * @return array Array with array for each section.
     */
    public static function get_sections($courseid, $sectionnumbers = [], $sectionids = [])
    {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/format/lib.php');

        // Validate parameters passed from web service.
        $params = self::validate_parameters(self::get_sections_parameters(), array(
            'courseid' => $courseid,
            'sectionnumbers' => $sectionnumbers,
            'sectionids' => $sectionids
        ));

        if (! ($course = $DB->get_record('course', array('id' => $params['courseid'])))) {
            throw new moodle_exception('invalidcourseid', 'local_custom_service', '', $courseid);
        }

        require_login($course);
        require_capability('moodle/course:view', context_course::instance($courseid));

        $courseformat = course_get_format($course);
        // Test if courseformat allows sections.
        if (!$courseformat->uses_sections()) {
            throw new moodle_exception('courseformatwithoutsections', 'local_custom_service', '', $courseformat);
        }

        $lastsectionnumber = $courseformat->get_last_section_number();
        $coursesections = get_fast_modinfo($course)->get_section_info_all();

        // Collect mentioned sectionnumbers in $secnums.
        $secnums = array();
        // Test if $sectionnumbers are part of $coursesections and collect secnums. Inapt numbers will be ignored.
        if (!empty($sectionnumbers)) {
            foreach ($sectionnumbers as $num) {
                if ($num >= 0 and $num <= $lastsectionnumber) {
                    $secnums[] = $num;
                }
            }
        }
        // Test if $sectionids are part of $coursesections and collect secnums. Inapt ids will be ignored.
        if (!empty($sectionids)) {
            foreach ($coursesections as $section) {
                $coursesecids[] = $section->id;
            }
            foreach ($sectionids as $id) {
                if ($pos = array_search($id, $coursesecids)) {
                    $secnums[] = $pos;
                }
            }
        }
        // Collect all sectionnumbers, if paramters are empty.
        if (empty($sectionnumbers) and empty($sectionids)) {
            $secnums = range(0, $lastsectionnumber);
        }
        $secnums = array_unique($secnums, SORT_NUMERIC);
        sort($secnums, SORT_NUMERIC);

        // Arrange the requested informations.
        $sectionsinfo = array();
        foreach ($coursesections as $section) {
            if (in_array($section->section, $secnums)) {
                // Collect sectionformatoptions.
                $sectionformatoptions = $courseformat->get_format_options($section);
                $formatoptionslist = array();
                foreach ($sectionformatoptions as $key => $value) {
                    $formatoptionslist[] = array(
                        'name' => $key,
                        'value' => $value
                    );
                }
                // Write sectioninfo to returned array.
                $sectionsinfo[] = array(
                    'sectionnum' => $section->section,
                    'id' => $section->id,
                    'name' => format_string(get_section_name($course, $section)),
                    'summary' => $section->summary,
                    'summaryformat' => $section->summaryformat,
                    'visible' => $section->visible,
                    'uservisible' => $section->uservisible,
                    'availability' => $section->availability,
                    'highlight' => $course->marker == $section->section ? 1 : 0,
                    'sequence' => $section->sequence,
                    'courseformat' => $course->format,
                    'sectionformatoptions' => $formatoptionslist,
                );
            }
        }

        return $sectionsinfo;
    }

    /**
     * Parameter description for get_sections().
     *
     * @return external_description
     */
    public static function get_sections_returns()
    {
        return new external_multiple_structure(
            new external_single_structure(
                array(
                    'sectionnum'  => new external_value(PARAM_INT, 'sectionnumber (position of section)'),
                    'id' => new external_value(PARAM_INT, 'section id'),
                    'name' => new external_value(PARAM_TEXT, 'section name'),
                    'summary' => new external_value(PARAM_RAW, 'Section description'),
                    'summaryformat' => new external_format_value('summary'),
                    'visible' => new external_value(PARAM_INT, 'is the section visible', VALUE_OPTIONAL),
                    'uservisible' => new external_value(
                        PARAM_BOOL,
                        'Is the section visible for the user?',
                        VALUE_OPTIONAL
                    ),
                    'availability' => new external_value(PARAM_RAW, 'Availability information.', VALUE_OPTIONAL),
                    'highlighted' => new external_value(
                        PARAM_BOOL,
                        'Is the section marked as highlighted?',
                        VALUE_OPTIONAL
                    ),
                    'sequence' => new external_value(PARAM_TEXT, 'sequence of module ids in the section'),
                    'courseformat' => new external_value(
                        PARAM_PLUGIN,
                        'course format: weeks, topics, social, site,..'
                    ),
                    'sectionformatoptions' => new external_multiple_structure(
                        new external_single_structure(
                            array(
                                'name' => new external_value(PARAM_ALPHANUMEXT, 'section format option name'),
                                'value' => new external_value(PARAM_RAW, 'section format option value')
                            )
                        ),
                        'additional section format options for particular course format',
                        VALUE_OPTIONAL
                    )
                )
            )
        );
    }


    // Functionset for update_sections() ******************************************************************************************.

    /**
     * Parameter description for update_sections().
     *
     * @return external_function_parameters.
     */
    // public static function update_sections_parameters()
    // {
    //     return new external_function_parameters(
    //         array(
    //             'courseid' => new external_value(PARAM_INT, 'id of course'),
    //             'sections' => new external_multiple_structure(
    //                 new external_single_structure(
    //                     array(
    //                         'type' => new external_value(
    //                             PARAM_TEXT,
    //                             'num/id: identify section by sectionnumber or id. Default to num',
    //                             VALUE_DEFAULT,
    //                             'num'
    //                         ),
    //                         'section' => new external_value(PARAM_INT, 'depending on type: sectionnumber or sectionid'),
    //                         'name' => new external_value(PARAM_TEXT, 'new name of the section', VALUE_OPTIONAL),
    //                         'summary' => new external_value(PARAM_RAW, 'summary', VALUE_OPTIONAL),
    //                         'summaryformat' => new external_format_value('summary', VALUE_OPTIONAL),
    //                         'visible' => new external_value(PARAM_INT, '1: available to student, 0: not available', VALUE_OPTIONAL),
    //                         'highlight' => new external_value(PARAM_INT, '1: highlight, 0: remove highlight', VALUE_OPTIONAL),
    //                         'sectionformatoptions' => new external_multiple_structure(
    //                             new external_single_structure(
    //                                 array(
    //                                     'name' => new external_value(PARAM_TEXT, 'section format option name'),
    //                                     'value' => new external_value(PARAM_RAW, 'section format option value')
    //                                 )
    //                             ),
    //                             'additional options for particular course format',
    //                             VALUE_OPTIONAL
    //                         ),
    //                     )
    //                 ),
    //                 'sections to update',
    //                 VALUE_DEFAULT,
    //                 array()
    //             ),
    //         )
    //     );
    // }

    public static function update_sections_parameters()
    {
        return new external_function_parameters(
            array(
                'jsonRequest' => new external_value(PARAM_TEXT, 'data base64 encode', VALUE_OPTIONAL),
            )
        );
    }

    /**
     * Update sections.
     *
     * This function updates settings of sections.
     *
     * @param int $courseid Courseid of the belonging course.
     * @param array $sections Array of array with settings for each section to be updated.
     * @return array Array with warnings.
     */
    public static function update_sections($jsonRequest)
    {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/format/lib.php');

        $json_decode = base64_decode($jsonRequest);
        $json = json_decode($json_decode, true);

        $courseid = $json['courseid'];
        $sections = $json['sections'];

        // Validate parameters passed from web service.
        // $params = self::validate_parameters(self::update_sections_parameters(), array(
        //     'courseid' => $courseid,
        //     'sections' => $sections
        // ));
        $sectionSummary = base64_decode($sections['0']['summary']);
        $sections['0']['summary'] = $sectionSummary;

        if (! ($course = $DB->get_record('course', array('id' => $courseid)))) {
            throw new moodle_exception('invalidcourseid', 'local_custom_service', '', $courseid);
        }

        require_login($course);
        require_capability('moodle/course:update', context_course::instance($courseid));

        $courseformat = course_get_format($course);
        // Test if courseformat allows sections.
        if (!$courseformat->uses_sections()) {
            throw new moodle_exception('courseformatwithoutsections', 'local_custom_service', '', $courseformat);
        }

        $coursesections = get_fast_modinfo($course)->get_section_info_all();
        $warnings = array();

        foreach ($sections as $sectiondata) {
            // Catch any exception while updating course and return as warning to user.
            try {
                // Get the section that belongs to $secname['sectionnumber'].
                $found = 0;
                foreach ($coursesections as $key => $cs) {
                    
                    if ($sectiondata['type'] == 'id' and $sectiondata['section'] == $cs->id) {
                        $found = 1;
                    } else if ($sectiondata['section'] == $key) {
                        $found = 1;
                    }
                    if ($found == 1) {
                        $section = $cs;
                        break;
                    }
                }

                // Section with the desired number/id not found.
                if ($found == 0) {
                    throw new moodle_exception('sectionnotfound', 'local_custom_service', '', $sectiondata['section']);
                }

                // Sectiondata has mostly the right struture to insert it in course_update_section.
                // Just unset some keys "type", "section", "highlight" and "sectionformatoptions".
                $data = $sectiondata;
                foreach (['type', 'section', 'highlight', 'sectionformatoptions'] as $unset) {
                    unset($data[$unset]);
                }

                // Set or unset marker if neccessary.
                if (isset($sectiondata['highlight'])) {
                    require_capability('moodle/course:setcurrentsection', context_course::instance($courseid));
                    if ($sectiondata['highlight'] == 1  and $course->marker != strval($section->section)) {
                        course_set_marker($courseid, strval($section->section));
                    } else if ($sectiondata['highlight'] == 0 and $course->marker == $section->section) {
                        course_set_marker($courseid, "0");
                    }
                }

                // Add sectionformatoptions with data['name'] = 'value'.
                if (!empty($sectiondata['sectionformatoptions'])) {
                    foreach ($sectiondata['sectionformatoptions'] as $option) {
                        if (isset($option['name']) && isset($option['value'])) {
                            $data[$option['name']] = $option['value'];
                        }
                    }
                }

                // Update remaining sectionsettings.
                course_update_section($section->course, $section, $data);
            } catch (Exception $e) {
                $warning = array();
                $warning['sectionnumber'] = $sectionnumber;
                $warning['sectionid'] = $section->id;
                if ($e instanceof moodle_exception) {
                    $warning['warningcode'] = $e->errorcode;
                } else {
                    $warning['warningcode'] = $e->getCode();
                }
                $warning['message'] = $e->getMessage();
                $warnings[] = $warning;
            }
        }

        $result = array();
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Parameter description for update_sections().
     *
     * @return external_description
     */
    public static function update_sections_returns()
    {
        return new external_single_structure(
            array(
                'warnings' => new external_warnings()
            )
        );
    }


    // Functionset for delete_sections() ******************************************************************************************.

    /**
     * Parameter description for delete_sections().
     *
     * @return external_function_parameters.
     */
    public static function delete_sections_parameters()
    {
        return new external_function_parameters(
            array(
                'courseid' => new external_value(PARAM_INT, 'id of course'),
                'coursesectionnumbers' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'sectionnumber (position of section)'),
                    'List of sectionnumbers. Wrong numbers will be ignored.
                                If list of sectionnumbers and list of sectionids are empty
                                then delete all sections despite of the first.',
                    VALUE_DEFAULT,
                    array()
                ),
                'coursesectionids' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'id of section'),
                    'List of sectionids. Wrong ids will be ignored.
                                If list of sectionnumbers and list of sectionids are empty
                                then delete all sections despite of the first.',
                    VALUE_DEFAULT,
                    array()
                )
            )
        );
    }

    /**
     * Delete sections.
     *
     * This function deletes given sections.
     *
     * @param int $courseid Courseid of the belonging course.
     * @param array $coursesectionnumbers Array of coursesectionnumbers (int, optional).
     * @param array $coursesectionids Array of section ids (int, optional).
     * @return array Array with results.
     */
    public static function delete_sections($courseid, $coursesectionnumbers = [], $coursesectionids = [])
    {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/format/lib.php');

        // Validate parameters passed from web service.
        $params = self::validate_parameters(self::delete_sections_parameters(), array(
            'courseid' => $courseid,
            'coursesectionnumbers' => $coursesectionnumbers,
            'coursesectionids' => $coursesectionids
        ));

        if (! ($course = $DB->get_record('course', array('id' => $params['courseid'])))) {
            throw new moodle_exception('invalidcourseid', 'local_custom_service', '', $courseid);
        }

        require_login($course);
        require_capability('moodle/course:update', context_course::instance($courseid));

        $courseformat = course_get_format($course);
        // Test if courseformat allows sections.
        if (!$courseformat->uses_sections()) {
            throw new moodle_exception('courseformatwithoutsections', 'local_custom_service', '', $courseformat);
        }

        $lastsectionnumber = $courseformat->get_last_section_number();
        $coursesections = get_fast_modinfo($course)->get_section_info_all();
        // Collect mentioned coursesectionnumbers in $secnums.
        $secnums = array();
        // Test if $coursesectionnumbers are part of $coursesections and collect secnums. Inapt numbers will be ignored.
        if (!empty($coursesectionnumbers)) {
            foreach ($coursesectionnumbers as $num) {
                if ($num >= 0 and $num <= $lastsectionnumber) {
                    $secnums[] = $num;
                }
            }
        }
        // Test if $coursesectionids are part of $coursesections and collect secnums. Inapt ids will be ignored.
        if (!empty($coursesectionids)) {
            foreach ($coursesections as $section) {
                $coursesecids[] = $section->id;
            }
            foreach ($coursesectionids as $id) {
                if ($pos = array_search($id, $coursesecids)) {
                    $secnums[] = $pos;
                }
            }
        }

        // Collect all coursesectionnumbers, if paramters are empty.
        if (empty($coursesectionnumbers) and empty($coursesectionids)) {
            $secnums = range(1, $lastsectionnumber);
        }
        $secnums = array_unique($secnums, SORT_NUMERIC);
        sort($secnums, SORT_NUMERIC);
        $results = array();
        // Delete desired sections. Saver to start at the end of the course.
        foreach (array_reverse($coursesections) as $section) {
            if (in_array($section->section, $secnums)) {
                $results[] = array(
                    'id' => $section->id,
                    'number' => $section->section,
                    'name' => format_string(get_section_name($course, $section)),
                    'deleted' => $courseformat->delete_section($section, true)
                );
            }
        }

        return $results;
    }

    /**
     * Parameter description for delete_sections().
     *
     * @return external_description
     */
    public static function delete_sections_returns()
    {
        return new external_multiple_structure(
            new external_single_structure(
                array(
                    'id' => new external_value(PARAM_INT, 'section id'),
                    'number' => new external_value(PARAM_INT, 'position of the section'),
                    'name' => new external_value(PARAM_TEXT, 'sectionname'),
                    'deleted' => new external_value(PARAM_BOOL, 'deleted (true/false)'),
                )
            )
        );
    }


    // Functionset for create_sections() ******************************************************************************************.

    /**
     * Parameter description for create_sections().
     *
     * @return external_function_parameters.
     */
    public static function create_sections_parameters()
    {
        return new external_function_parameters(
            array(
                'courseid' => new external_value(PARAM_INT, 'id of course'),
                'position' => new external_value(PARAM_INT, 'Insert section at position; 0 means at the end.'),
                'number' => new external_value(PARAM_INT, 'Number of sections to create. Default is 1.', VALUE_DEFAULT, 1)
            )
        );
    }

    /**
     * Create $number sections at $position.
     *
     * This function creates $number new sections at position $position.
     * If $position = 0 the sections are appended to the end of the course.
     *
     * @param int $courseid Courseid of the belonging course.
     * @param int $position Position the section is created at.
     * @param int $number Number of section to create.
     * @return array Array of arrays with sectionid and sectionnumber for each created section.
     */
    public static function create_sections($courseid, $position, $number)
    {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        // Validate parameters passed from web service.
        $params = self::validate_parameters(self::create_sections_parameters(), array(
            'courseid' => $courseid,
            'position' => $position,
            'number' => $number
        ));

        if (! ($course = $DB->get_record('course', array('id' => $params['courseid'])))) {
            throw new moodle_exception('invalidcourseid', 'local_custom_service', '', $courseid);
        }

        require_login($course);
        require_capability('moodle/course:update', context_course::instance($courseid));

        $courseformat = course_get_format($course);

        // Test if courseformat allows sections.
        if (!$courseformat->uses_sections()) {
            throw new moodle_exception('courseformatwithoutsections', 'local_custom_service', '', $courseformat);
        }

        $lastsectionnumber = $courseformat->get_last_section_number();
        $maxsections = $courseformat->get_max_sections();

        // Test if the desired number of section is lower than maxsections of the courseformat.
        $desirednumsections = $lastsectionnumber + $number;
        if ($desirednumsections > $maxsections) {
            throw new moodle_exception(
                'toomanysections',
                'local_custom_service',
                '',
                array('max' => $maxsections, 'desired' => $desirednumsections)
            );
        }

        if ($position > 0) {
            // Inserting sections at any position except in the very end requires capability to move sections.
            require_capability('moodle/course:movesections', context_course::instance($course->id));
        }

        $return = array();
        for ($i = 1; $i <= max($number, 1); $i++) {
            $section = course_create_section($course, $position);
            // If more then one section is created, the sectionnumber for already created ones will increase.
            $return[] = array('sectionid' => $section->id, 'sectionnumber' => $section->section + $number - $i);
        }

        return  $return;
    }

    /**
     * Parameter description for create_sections().
     *
     * @return external_description
     */
    public static function create_sections_returns()
    {
        return new external_multiple_structure(
            new external_single_structure(
                array(
                    'sectionid' => new external_value(PARAM_INT, 'section id'),
                    'sectionnumber'  => new external_value(PARAM_INT, 'position of the section'),
                )
            )
        );
    }


    // Functionset for get_user_enrol_course() ******************************************************************************************.

    /**
     * Parameter description for get_user_enrol_course().
     *
     * @return external_function_parameters.
     */
    public static function get_user_enrol_course_parameters()
    {
        return new external_function_parameters(
            array(
                'courseid' => new external_value(PARAM_INT, 'courseid', VALUE_DEFAULT, 0),
                'coursename' => new external_value(PARAM_TEXT, 'coursename', VALUE_DEFAULT, 0),
                'userid' => new external_value(PARAM_INT, 'userid', VALUE_DEFAULT, 0),
                'useremail' => new external_value(PARAM_TEXT, 'useremail', VALUE_DEFAULT, 0),
                'role' => new external_value(PARAM_TEXT, 'role', VALUE_DEFAULT, 'all'),
                'fullname' => new external_value(PARAM_TEXT, 'fullname', VALUE_DEFAULT, 0),
                'limit' => new external_value(PARAM_INT, 'the number of results to return', VALUE_DEFAULT, 0),
                'offset' => new external_value(PARAM_INT, 'offset the result set by a given amount', VALUE_DEFAULT, 0)
            )
        );
    }

    /**
     * Create $number sections at $position.
     *
     * This function creates $number new sections at position $position.
     * If $position = 0 the sections are appended to the end of the course.
     *
     * @param int $courseid Courseid of the belonging course.
     * @param int $position Position the section is created at.
     * @param int $number Number of section to create.
     * @return array Array of arrays with sectionid and sectionnumber for each created section.
     */
    public static function get_user_enrol_course($courseid, $coursename, $userid, $useremail, $role, $fullname, $limit, $offset)
    {
        global $CFG, $USER, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/completionlib.php');

        // Count total courses
        $sql_2_count = "SELECT COUNT(DISTINCT c.id) as total_count
        FROM mdl_user u
        JOIN mdl_role_assignments ra ON u.id = ra.userid
        JOIN mdl_context ctx ON ra.contextid = ctx.id
        JOIN mdl_role r ON ra.roleid = r.id
        JOIN mdl_course c ON ctx.instanceid = c.id
        WHERE u.id = $userid";

        if ($role == 'student') {
            $sql_2_count .= " and r.shortname = 'student'";
        }

        if ($role == 'teacher') {
            $sql_2_count .= " and (r.shortname = 'editingteacher' OR r.shortname = 'teacher')";
        }

        if (!empty($courseid)) {
            $sql_2_count .= " and c.id = $courseid";
        }
        if (!empty($coursename)) {
            $sql_2_count .= " and c.fullname like '%$coursename%'";
        }

        $mods_count = $DB->get_record_sql($sql_2_count);
        $result['count'] = $mods_count->total_count;

        // Get course details
        $sql_2 = "SELECT DISTINCT c.fullname, c.id, c.summary, f.filename AS course_image, f.contextid AS f_contextid,
                IFNULL(FROM_UNIXTIME(l.timeaccess, '%d-%m-%Y %H:%i:%s'), 'N/A') AS last_access_time,
                cat.id AS categoryid, cat.name AS categoryname
        FROM mdl_user u
        JOIN mdl_role_assignments ra ON u.id = ra.userid
        JOIN mdl_context ctx ON ra.contextid = ctx.id
        JOIN mdl_role r ON ra.roleid = r.id
        JOIN mdl_course c ON ctx.instanceid = c.id
        LEFT JOIN mdl_files f ON f.contextid = ctx.id AND f.component = 'course' AND f.filearea = 'overviewfiles' AND f.filename <> '.'
        LEFT JOIN mdl_user_lastaccess l ON l.courseid = c.id AND l.userid = u.id
        JOIN mdl_course_categories cat ON c.category = cat.id
        WHERE u.id = $userid";

        if ($role == 'student') {
            $sql_2 .= " and r.shortname = 'student'";
        }

        if ($role == 'teacher') {
            $sql_2 .= " and (r.shortname = 'editingteacher' OR r.shortname = 'teacher')";
        }

        if (!empty($courseid)) {
            $sql_2 .= " and c.id = $courseid";
        }

        if (!empty($coursename)) {
            $sql_2 .= " and c.fullname like '%$coursename%'";
        }

        $sql_2 .= " ORDER BY l.timeaccess DESC";

        if (!empty($limit)) {
            $sql_2 .= " LIMIT $limit OFFSET $offset";
        }

        $mods1 = $DB->get_records_sql($sql_2);
        $result['contentfilter'] = [];
        if (!empty($mods1)) {
            foreach ($mods1 as $cm) {
                $courseresult = [
                    'id' => $cm->id,
                    'coursename' => $cm->fullname,
                    'summary' => $cm->summary,
                    'course_image' => $cm->course_image ? $CFG->wwwroot . '/pluginfile.php/' . $cm->f_contextid . '/course/overviewfiles/' . $cm->course_image : '',
                    'last_access_time' => $cm->last_access_time,
                    'categoryid' => $cm->categoryid,
                    'categoryname' => $cm->categoryname,
                    'firstname' => '',
                    'lastname' => '',
                    'email' => '',
                    'view_url' => $CFG->wwwroot . '/course/view.php?id=' . $cm->id,
                ];
                $result['contentfilter'][] = $courseresult;
            }
        }

        return $result;
    }

    /**
     * Parameter description for get_course_image().
     *
     * @return external_description
     */
    public static function get_user_enrol_course_returns()
    {
        return new external_single_structure([
            'count' => new external_value(PARAM_INT, 'count'),
            'contentfilter' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'id'),
                'coursename' => new external_value(PARAM_RAW, 'coursename'),
                'course_image' => new external_value(PARAM_RAW, 'course_image'),
                'last_access_time' => new external_value(PARAM_RAW, 'last_access_time'),
                'summary' => new external_value(PARAM_RAW, 'summary'),
                'view_url' => new external_value(PARAM_RAW, 'view_url'),
                'firstname' => new external_value(PARAM_RAW, 'firstname'),
                'lastname' => new external_value(PARAM_RAW, 'lastname'),
                'email' => new external_value(PARAM_RAW, 'email'),
                'categoryid' => new external_value(PARAM_INT, 'Category ID'),
                'categoryname' => new external_value(PARAM_RAW, 'Category Name'),
            ])),
        ]);
        
    }

    // Functionset for get_data_student_by_teacher() ******************************************************************************************.

    /**
     * Parameter description for get_data_student_by_teacher().
     *
     * @return external_function_parameters.
     */
    public static function get_data_student_by_teacher_parameters()
    {
        return new external_function_parameters(
            array(
                'courseid' => new external_value(PARAM_INT, 'courseid', VALUE_DEFAULT, 0),
                'coursename' => new external_value(PARAM_TEXT, 'coursename', VALUE_DEFAULT, 0),
                'userid' => new external_value(PARAM_INT, 'userid', VALUE_DEFAULT, 0),
                'role' => new external_value(PARAM_TEXT, 'role', VALUE_DEFAULT, 'all'),
                'limit' => new external_value(PARAM_INT, 'the number of results to return', VALUE_DEFAULT, 0),
                'offset' => new external_value(PARAM_INT, 'offset the result set by a given amount', VALUE_DEFAULT, 0)
            )
        );
    }

    /**
     * Create $number sections at $position.
     *
     * This function creates $number new sections at position $position.
     * If $position = 0 the sections are appended to the end of the course.
     *
     * @param int $courseid Courseid of the belonging course.
     * @param int $position Position the section is created at.
     * @param int $number Number of section to create.
     * @return array Array of arrays with sectionid and sectionnumber for each created section.
     */
    public static function get_data_student_by_teacher($courseid, $coursename, $userid, $role, $limit, $offset)
    {
        global $CFG, $USER, $DB;
    
        // Câu SQL lấy thông tin group của giáo viên
        $query = "SELECT DISTINCT g.id as groupid, c.fullname, c.id, c.summary, f.filename AS course_image, f.contextid AS f_contextid, g.name as groupname
                    FROM mdl_user u
                    JOIN mdl_role_assignments ra ON u.id = ra.userid
                    JOIN mdl_context ctx ON ra.contextid = ctx.id
                    JOIN mdl_role r ON ra.roleid = r.id
                    JOIN mdl_course c ON ctx.instanceid = c.id
                    LEFT JOIN mdl_files f ON f.contextid = ctx.id AND f.component = 'course' AND f.filearea = 'overviewfiles' AND f.filename <> '.'
                    JOIN mdl_groups g ON g.courseid = c.id
                    JOIN mdl_groups_members gm ON gm.groupid = g.id AND gm.userid = u.id
                    WHERE u.id = :userid";
    
        if ($role == 'student') {
            $query .= " AND r.shortname = 'student'";
        }
        if ($role == 'teacher') {
            $query .= " AND (r.shortname = 'editingteacher' OR r.shortname = 'teacher')";
        }
        if (!empty($courseid)) {
            $query .= " AND c.id = :courseid";
        }
        if (!empty($coursename)) {
            $query .= " AND c.fullname LIKE :coursename";
        }
    
        if (!empty($limit)) {
            $query .= " LIMIT :limit OFFSET :offset";
        }
    
        $params = array(
            'userid' => $userid,
            'courseid' => $courseid,
            'coursename' => '%' . $coursename . '%',
        );
    
        if (!empty($limit)) {
            $params['limit'] = $limit;
            $params['offset'] = $offset;
        }
    
        $mods = $DB->get_records_sql($query, $params);
    
        // Lấy danh sách groupids từ kết quả và chuyển thành mảng chỉ số, đảm bảo kiểu dữ liệu là integer
        $groupids = array_values(array_map(function($mod) {
            return intval($mod->groupid);
        }, $mods));

        if (empty($groupids)) {
            return [
                'count' => 0,
                'student_data' => []
            ];
        }
    
        // Gọi hàm để lấy thông tin học sinh từ các group
        $students = self::get_students_by_groups($groupids);
    
        // Kiểm tra kết quả truy vấn
        // var_dump($students, $groupids); die;
    
        // Chuẩn bị kết quả trả về
        $result['count'] = count($students);
        $result['student_data'] = [];
    
        foreach ($students as $student) {
            $result['student_data'][] = [
                'userid' => $student->userid,
                'fullname' => $student->firstname . ' ' . $student->lastname,
                'email' => $student->email,
                'groupid' => $student->groupid,
                'groupname' => $student->groupname,
                'courseid' => $student->courseid,          // Thêm courseid
                'coursename' => $student->coursename,      // Thêm coursename
            ];
        }
    
        return $result;
    }
    
    public static function get_students_by_groups($groupids)
    {
        global $DB;
    
        if (empty($groupids)) {
            return [];
        }
    
        list($insql, $params) = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'groupid');
    
        $groupids_string = implode(',', $groupids);
    
        $sql = "
            SELECT 
                ROW_NUMBER() OVER (ORDER BY g.id, u.firstname, u.lastname) AS row_number,
                u.id AS userid, 
                u.firstname, 
                u.lastname, 
                u.email, 
                g.id AS groupid, 
                g.name AS groupname,
                c.id AS courseid,
                c.fullname AS coursename
            FROM mdl_user u
            JOIN mdl_groups_members gm ON gm.userid = u.id
            JOIN mdl_groups g ON gm.groupid = g.id
            JOIN mdl_course c ON g.courseid = c.id
            JOIN mdl_context ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
            JOIN mdl_role_assignments ra ON ra.userid = u.id AND ra.contextid = ctx.id
            JOIN mdl_role r ON ra.roleid = r.id
            WHERE g.id IN ($groupids_string)
            AND r.shortname = 'student'
            ORDER BY g.id, u.firstname, u.lastname
        ";

        // var_dump($sql);die;
    
        return $DB->get_records_sql($sql);
    }
    
    /**
     * Parameter description for get_course_image().
     *
     * @return external_description
     */
    public static function get_data_student_by_teacher_returns()
    {
        return new external_single_structure([
            'count' => new external_value(PARAM_INT, 'count'),
            'student_data' => new external_multiple_structure(new external_single_structure([
                'userid' => new external_value(PARAM_INT, 'userid'),
                'fullname' => new external_value(PARAM_RAW, 'fullname'),
                'email' => new external_value(PARAM_RAW, 'email'),
                'groupid' => new external_value(PARAM_INT, 'groupid'),
                'groupname' => new external_value(PARAM_TEXT, 'groupname'),
                'courseid' => new external_value(PARAM_INT, 'courseid'),             // Thêm courseid
                'coursename' => new external_value(PARAM_TEXT, 'coursename'),        // Thêm coursename
            ])),
        ]);
    }




    // Functionset for add_image_course() ******************************************************************************************.

    /**
     * Parameter description for add_image_course().
     *
     * @return external_function_parameters.
     */
    public static function add_image_course_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'ID của khóa học'),
            'filecontent' => new external_value(PARAM_RAW, 'Nội dung file dưới dạng base64'),
            'filename' => new external_value(PARAM_FILE, 'Tên của file')
        ]);
    }
    
    public static function add_image_course($courseid, $filecontent, $filename) {
        global $DB, $USER;
    
        // Xác thực tham số từ body
        $params = self::validate_parameters(self::add_image_course_parameters(), [
            'courseid' => $courseid,
            'filecontent' => $filecontent,
            'filename' => $filename
        ]);
    
        // Kiểm tra khóa học tồn tại hay không
        if (!$course = $DB->get_record('course', ['id' => $courseid])) {
            throw new moodle_exception('invalidcourse', 'error', '', $courseid);
        }
    
        // Lấy context của khóa học
        $context = context_course::instance($courseid);
    
        // Kiểm tra quyền của người dùng hiện tại có thể upload ảnh cho khóa học không
        require_capability('moodle/course:update', $context);
    
        // Chuyển nội dung file từ base64 thành dữ liệu thực
        $decodedcontent = base64_decode($filecontent);
        if ($decodedcontent === false) {
            throw new moodle_exception('invalidfilecontent', 'error');
        }
    
        // **Bước 1: Upload vào khu vực draft**
        // Tạo một draft itemid
        $draftItemId = file_get_unused_draft_itemid();
    
        // Định nghĩa tệp cần upload vào draft
        $draftFileInfo = [
            'contextid' => context_user::instance($USER->id)->id, // Context của người dùng
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftItemId,
            'filepath' => '/',
            'filename' => $filename,
            'author' => 'Admin User',
            'license' => 'unknown'
        ];
    
        // Lấy hệ thống file
        $fs = get_file_storage();
    
        // Tạo file mới trong khu vực draft từ nội dung đã giải mã
        $draftFile = $fs->create_file_from_string($draftFileInfo, $decodedcontent);
    
        if (!$draftFile) {
            throw new moodle_exception('fileuploaddraftfailed', 'error');
        }
    
        // **Bước 2: Chuyển file từ draft sang overviewfiles**
        $finalFileInfo = [
            'contextid' => $context->id,       // Context của khóa học
            'component' => 'course',           // Thành phần là khóa học
            'filearea' => 'overviewfiles',     // Khu vực file overviewfiles cho ảnh khóa học
            'itemid' => 0,                     // Itemid cho khóa học
            'filepath' => '/',                 // Đường dẫn tệp (gốc)
            'filename' => $filename,            // Tên tệp
            'author' => 'Admin User',
            'license' => 'unknown'
        ];
    
        // Xóa các file hiện tại trong overviewfiles để tránh trùng lặp
        $fs->delete_area_files($context->id, 'course', 'overviewfiles', 0);
    
        // Sao chép file từ draft sang overviewfiles
        $finalFile = $fs->create_file_from_storedfile($finalFileInfo, $draftFile);
    
        if (!$finalFile) {
            throw new moodle_exception('fileuploadfinalfailed', 'error');
        }
    
        // **Bước 3: Xóa file trong draft sau khi đã chuyển thành công**
        $draftFile->delete(); // Sử dụng phương thức delete() trên đối tượng stored_file
    
        // Lấy URL của file đã upload
        $fileurl = moodle_url::make_pluginfile_url(
            $finalFile->get_contextid(),
            $finalFile->get_component(),
            $finalFile->get_filearea(),
            $finalFile->get_itemid(),
            $finalFile->get_filepath(),
            $finalFile->get_filename()
        )->out();
    
        // **Bước 4: Cập Nhật Trường 'summary' của Khóa Học để Hiển Thị Hình Ảnh**
        // Điều này sẽ giúp hình ảnh hiển thị trên dashboard và các vị trí khác nếu cần.
        // Bạn có thể tuỳ chỉnh cách thêm hình ảnh vào 'summary' theo nhu cầu.
        // $existing_summary = $course->summary;
        // $new_summary = '<img src="' . $fileurl . '" alt="Course Image" />' . $existing_summary;
        
        // // Cập nhật trường 'summary' với định dạng HTML
        // $DB->set_field('course', 'summary', $new_summary, ['id' => $courseid]);
        // $DB->set_field('course', 'summaryformat', FORMAT_HTML, ['id' => $courseid]);
        // $DB->set_field('course', 'timemodified', time(), ['id' => $courseid]);
    
        // **Bước 5: Trả Về Kết Quả**
        return [
            'status' => true,
            'message' => 'File uploaded and course summary updated successfully',
            'filename' => $finalFile->get_filename(),
            'filepath' => $finalFile->get_filepath(),
            'url' => $fileurl
        ];
    }
    
    
    public static function add_image_course_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Trạng thái upload thành công hay không'),
            'message' => new external_value(PARAM_TEXT, 'Thông báo kết quả'),
            'filename' => new external_value(PARAM_FILE, 'Tên file đã upload'),
            'filepath' => new external_value(PARAM_PATH, 'Đường dẫn file trong Moodle'),
            'url' => new external_value(PARAM_URL, 'URL truy cập file đã upload')
        ]);
    }
    

    // Functionset for create_activity_label() ******************************************************************************************.

    /**
     * Parameter description for create_activity_label().
     *
     * @return external_function_parameters.
     */
    public static function create_activity_label_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'ID của khóa học'),
            'content' => new external_value(PARAM_RAW, 'Nội dung của activity'),
            'name' => new external_value(PARAM_TEXT, 'Tên của activity'),
            'module' => new external_value(PARAM_TEXT, 'Loại module: label hoặc url'),
            'section' => new external_value(PARAM_INT, 'Số thứ tự của section để thêm activity', VALUE_DEFAULT, 0),
            'display' => new external_value(PARAM_INT, 'Cách hiển thị cho URL (nếu là URL): 0=automatic, 1=embed, 5=open, etc.', VALUE_DEFAULT, 0),
            'visible' => new external_value(PARAM_INT, 'visible: 1 | 0', VALUE_DEFAULT, 1),
            'description' => new external_value(PARAM_RAW, 'Mô tả của activity', VALUE_DEFAULT, ''),
            'cmsh5ptoolid' => new external_value(PARAM_RAW, 'ID CMS H5P Tool', VALUE_OPTIONAL),
        ]);
    }
    

    /**
     * Function to create a label activity in a course.
     *
     * @param int $courseid
     * @param string $content
     * @param string $name
     * @param int $section Số thứ tự của section để thêm label. Nếu 0, thêm vào section cuối cùng.
     * @return array
     * @throws moodle_exception
     */
    public static function create_activity_label($courseid, $content, $name, $module, $section = 0, $display = 0, $visible, $description = '', $cmsh5ptoolid = '') {
        global $DB, $USER;
    
        // Validate the parameters.
        $params = self::validate_parameters(self::create_activity_label_parameters(), [
            'courseid' => $courseid,
            'content' => $content,
            'name' => $name,
            'module' => $module,
            'section' => $section,
            'display' => $display,
            'visible' => $visible
        ]);

    
        // Tải thông tin khóa học.
        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
    
        // Kiểm tra quyền của người dùng.
        $context = context_course::instance($course->id);
        require_login($course);
        if (!has_capability('moodle/course:manageactivities', $context)) {
            throw new moodle_exception('nopermissions', 'error', '', 'manage activities');
        }
    
        // Lấy thông tin format của khóa học để xác định section.
        $format = course_get_format($course);
        // if ($params['section'] > 0) {
        //     $sectionnumber = $params['section'];
        //     $section_obj = $format->get_section($sectionnumber);
        //     if (!$section_obj) {
        //         throw new moodle_exception('invalidsection', 'error');
        //     }
        // } else {
        //     $sectionnumber = $format->get_last_section_number();
        // }
        $sectionnumber = $params['section'];
    
        // Lấy module id dựa trên loại module (label hoặc url).
        if ($params['module'] == 'label') {
            $modulename = 'label';
            $module = $DB->get_record('modules', ['name' => 'label'], '*', MUST_EXIST);
        } elseif ($params['module'] == 'url') {
            $modulename = 'url';
            $module = $DB->get_record('modules', ['name' => 'url'], '*', MUST_EXIST);
        } elseif ($params['module'] == 'resource') {
            $modulename = 'resource';
            $module = $DB->get_record('modules', ['name' => 'resource'], '*', MUST_EXIST);
        } elseif ($params['module'] == 'assign') {
            $modulename = 'assign';
            $module = $DB->get_record('modules', ['name' => 'assign'], '*', MUST_EXIST);
        } elseif ($params['module'] == 'page') {
            $modulename = 'page';
            $module = $DB->get_record('modules', ['name' => 'page'], '*', MUST_EXIST);
        } elseif ($params['module'] == 'forum') {
            $modulename = 'forum';
            $module = $DB->get_record('modules', ['name' => 'forum'], '*', MUST_EXIST);
        } elseif ($params['module'] == 'cmsh5ptool') {
            $modulename = 'cmsh5ptool';
            $module = $DB->get_record('modules', ['name' => 'cmsh5ptool'], '*', MUST_EXIST);
        } else {
            throw new moodle_exception('invalidmodule', 'error', '', 'Invalid module type');
        }
        $moduleid = $module->id;
    
        // Chuẩn bị đối tượng moduleinfo.
        $moduleinfo = new stdClass();
        $moduleinfo->modulename = $modulename;
        $moduleinfo->module = $moduleid;
        $moduleinfo->section = $sectionnumber;
        $moduleinfo->name = $params['name'];
    
        // Xử lý nội dung cho label hoặc url.
        if ($params['module'] == 'label') {
            if (plugin_supports('mod', 'label', FEATURE_MOD_INTRO, true)) {
                $editor = 'introeditor';
                $draftid = file_get_unused_draft_itemid();
                $moduleinfo->$editor = array(
                    'text' => $params['content'],
                    'format' => FORMAT_HTML,
                    'itemid' => $draftid
                );
            } else {
                $moduleinfo->intro = $params['content'];
                $moduleinfo->introformat = FORMAT_HTML;
            }
        } elseif ($params['module'] == 'url') {
            $moduleinfo->externalurl = $params['content'];
            $moduleinfo->intro = $description;
            $moduleinfo->introformat = FORMAT_HTML;
            $moduleinfo->display = $params['display'];
        } elseif ($params['module'] == 'resource') {
            $moduleinfo->intro = $params['content'];
            $moduleinfo->introformat = FORMAT_HTML;
            $moduleinfo->display = $params['display'];
            $moduleinfo->revision = 1;
        } elseif ($params['module'] == 'assign') {
            $moduleinfo->intro = $params['content'];
            $moduleinfo->introformat = FORMAT_HTML;
            $moduleinfo->display = $params['display'];
            $moduleinfo->alwaysshowdescription = 1;
            $moduleinfo->nosubmissions = 0;
            $moduleinfo->submissiondrafts = 0;
            $moduleinfo->sendnotifications = 0;
            $moduleinfo->sendlatenotifications = 0;
            $moduleinfo->duedate = time() + (7 * 24 * 60 * 60);
            $moduleinfo->allowsubmissionsfromdate = time();
            $moduleinfo->grade = 100;
            // $moduleinfo->timemodified = 1732264957;
            $moduleinfo->requiresubmissionstatement = 0;
            $moduleinfo->completionsubmit = 1;
            $moduleinfo->cutoffdate = 0;
            $moduleinfo->gradingduedate = time() + (14 * 24 * 60 * 60);
            $moduleinfo->teamsubmission = 0;
            $moduleinfo->requireallteammemberssubmit = 0;
            $moduleinfo->teamsubmissiongroupingid = 0;
            $moduleinfo->blindmarking = 0;
            $moduleinfo->hidegrader = 0;
            $moduleinfo->revealidentities = 0;
            $moduleinfo->attemptreopenmethod = 'none';
            $moduleinfo->maxattempts = -1;
            $moduleinfo->markingworkflow = 0;
            $moduleinfo->markingallocation = 0;
            $moduleinfo->sendstudentnotifications = 1;
            $moduleinfo->preventsubmissionnotingroup = 0;
            $moduleinfo->activity = '';
            $moduleinfo->activityformat = 1;
            $moduleinfo->timelimit = 0;
            $moduleinfo->submissionattachments = 0;
            $moduleinfo->assignsubmission_onlinetext_enabled = 1; //Online text
            $moduleinfo->assignsubmission_file_enabled = 1; //File submissions
            $moduleinfo->assignsubmission_file_filetypes = '*';
            $moduleinfo->gradepass = 0;
        } elseif ($params['module'] == 'page') {
            $moduleinfo->intro = $description;
            $moduleinfo->introformat = FORMAT_HTML;
            $moduleinfo->content = $params['content'];
            $moduleinfo->contentformat = FORMAT_HTML;
            $moduleinfo->display = $params['display'];
            $moduleinfo->revision = 1;
        } elseif ($params['module'] == 'forum') {
            $moduleinfo->type = 'general';
            $moduleinfo->intro = $description;
            $moduleinfo->introformat = FORMAT_HTML;
            // $moduleinfo->duedate = FORMAT_HTML;
            // $moduleinfo->cutoffdate = FORMAT_HTML;
            // $moduleinfo->assessed = FORMAT_HTML;
            // $moduleinfo->assesstimestart = FORMAT_HTML;
            // $moduleinfo->assesstimefinish = FORMAT_HTML;
            $moduleinfo->scale = 100;
            // $moduleinfo->grade_forum = FORMAT_HTML;
            // $moduleinfo->grade_forum_notify = FORMAT_HTML;
            $moduleinfo->maxbytes = 512000;
            $moduleinfo->maxattachments = 9;
            // $moduleinfo->forcesubscribe = FORMAT_HTML;
            $moduleinfo->trackingtype = 1;
            // $moduleinfo->rsstype = FORMAT_HTML;
            // $moduleinfo->rssarticles = FORMAT_HTML;
            $moduleinfo->timemodified = time();
            // $moduleinfo->warnafter = FORMAT_HTML;
            // $moduleinfo->blockafter = FORMAT_HTML;
            // $moduleinfo->blockperiod = FORMAT_HTML;
            // $moduleinfo->completiondiscussions = FORMAT_HTML;
            // $moduleinfo->completionreplies = FORMAT_HTML;
            // $moduleinfo->completionposts = FORMAT_HTML;
            // $moduleinfo->displaywordcount = FORMAT_HTML;
            // $moduleinfo->lockdiscussionafter = FORMAT_HTML;
        } elseif ($params['module'] == 'cmsh5ptool') {
            $moduleinfo->externalurl = $params['content'];
            $moduleinfo->intro = $description;
            $moduleinfo->introformat = FORMAT_HTML;
            $moduleinfo->display = $params['display'];
            $moduleinfo->cms_h5p_tool_id = $cmsh5ptoolid;
        }
    
        $moduleinfo->visible = $params['visible'];
        $moduleinfo->groupmode = 0;
        $moduleinfo->groupingid = 0;
        $moduleinfo->completion = 2;
        $moduleinfo->completionview = 1;
        $moduleinfo->completionexpected = 0;
        // Tạo module thông qua add_moduleinfo.
        try {
            $created_moduleinfo = add_moduleinfo($moduleinfo, $course);
        } catch (moodle_exception $e) {
            debugging('Lỗi khi tạo activity: ' . $e->getMessage(), DEBUG_DEVELOPER);
            throw new moodle_exception('errorcreatingactivity', 'local_yourplugin', '', $e->getMessage());
        }
    
        if (!$created_moduleinfo || empty($created_moduleinfo->instance)) {
            debugging('add_moduleinfo trả về kết quả không hợp lệ.', DEBUG_DEVELOPER);
            throw new moodle_exception('errorcreatingactivity', 'local_yourplugin');
        }
    
        return [
            'cmid' => $created_moduleinfo->coursemodule,
            'instanceid' => $created_moduleinfo->instance,
            'name' => $created_moduleinfo->name,
            'content' => $params['module'] == 'label' ? $created_moduleinfo->intro : $moduleinfo->externalurl,
            'section' => $sectionnumber
        ];
    }

    /**
     * Return description for create_activity_label().
     *
     * @return external_single_structure.
     */
    public static function create_activity_label_returns() {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module ID của label'),
            'instanceid' => new external_value(PARAM_INT, 'Instance ID của label'),
            'name' => new external_value(PARAM_TEXT, 'Tên của label'),
            'content' => new external_value(PARAM_RAW, 'Nội dung của label'),
            'section' => new external_value(PARAM_INT, 'Số thứ tự của section mà label được thêm vào')
        ]);
    }

    public static function update_activity_parameters() {
        return new external_function_parameters([
            'coursemoduleid' => new external_value(PARAM_INT, 'ID của course module cần cập nhật'),
            'courseid' => new external_value(PARAM_INT, 'ID của khóa học'),
            'name' => new external_value(PARAM_TEXT, 'Tên của activity'),
            'content' => new external_value(PARAM_RAW, 'Nội dung của activity', VALUE_OPTIONAL),
            'visible' => new external_value(PARAM_INT, 'Hiển thị: 1 hoặc 0', VALUE_DEFAULT, 1),
            'module' => new external_value(PARAM_TEXT, 'Loại module: label hoặc url'),
            'groupmode' => new external_value(PARAM_INT, 'Chế độ nhóm: 0 hoặc 1', VALUE_OPTIONAL),
            'groupingid' => new external_value(PARAM_INT, 'ID của grouping', VALUE_OPTIONAL),
            'completion' => new external_value(PARAM_INT, 'Thiết lập hoàn thành', VALUE_OPTIONAL),
            'completionview' => new external_value(PARAM_INT, 'Yêu cầu hoàn thành khi xem', VALUE_OPTIONAL),
            'completiongradeitemnumber' => new external_value(PARAM_INT, 'Số của grade item', VALUE_OPTIONAL),
            'completionexpected' => new external_value(PARAM_INT, 'Ngày hoàn thành dự kiến', VALUE_OPTIONAL),
            'availability' => new external_value(PARAM_RAW, 'Điều kiện khả dụng dưới dạng JSON', VALUE_OPTIONAL),
            'showdescription' => new external_value(PARAM_INT, 'Hiển thị mô tả', VALUE_OPTIONAL)
        ]);
    }
    
    
    public static function update_activity($coursemoduleid, $courseid, $name, $content = null, $visible = 1, $module, $groupmode = null, $groupingid = null, $completion = null, $completionview = null, $completiongradeitemnumber = null, $completionexpected = null, $availability = null, $showdescription = 0) {
        global $DB, $USER, $CFG;
    
        // Validate parameters.
        $params = self::validate_parameters(self::update_activity_parameters(), [
            'coursemoduleid' => $coursemoduleid,
            'courseid' => $courseid,
            'name' => $name,
            'content' => $content,
            'visible' => $visible,
            'module' => $module,
            'groupmode' => $groupmode,
            'groupingid' => $groupingid,
            'completion' => $completion,
            'completionview' => $completionview,
            'completiongradeitemnumber' => $completiongradeitemnumber,
            'completionexpected' => $completionexpected,
            'availability' => $availability,
            'showdescription' => $showdescription
        ]);
    
        // Load course and module information.
        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $cm = get_coursemodule_from_id('', $params['coursemoduleid'], 0, false, MUST_EXIST);
    
        // Check user capabilities.
        $context = context_module::instance($cm->id);
        require_login($course);
        if (!has_capability('moodle/course:manageactivities', $context)) {
            throw new moodle_exception('nopermissions', 'error', '', 'manage activities');
        }

        if ($params['module'] == 'label') {
            $modulename = 'label';
        } elseif ($params['module'] == 'url') {
            $modulename = 'url';
        }
    
        // Load module info.
        $moduleinfo = new stdClass();
        $moduleinfo->id = $cm->instance;
        $moduleinfo->modulename = $modulename;
        $moduleinfo->name = $params['name'];
        $moduleinfo->visible = $params['visible'];
        $moduleinfo->groupmode = $params['groupmode'] ?? $cm->groupmode;
        $moduleinfo->groupingid = $params['groupingid'] ?? $cm->groupingid;
        $moduleinfo->completion = $params['completion'];
        $moduleinfo->completionview = $params['completionview'];
        $moduleinfo->completiongradeitemnumber = $params['completiongradeitemnumber'];
        $moduleinfo->completionexpected = $params['completionexpected'];
        $moduleinfo->availability = $params['availability'];
        $moduleinfo->showdescription = $params['showdescription'];
        $moduleinfo->coursemodule = $params['coursemoduleid'];
    
        if ($params['module'] == 'label') {
            if (plugin_supports('mod', 'label', FEATURE_MOD_INTRO, true)) {
                $editor = 'introeditor';
                $draftid = file_get_unused_draft_itemid();
                $moduleinfo->$editor = array(
                    'text' => $params['content'],
                    'format' => FORMAT_HTML,
                    'itemid' => $draftid
                );
            } else {
                $moduleinfo->intro = $params['content'];
                $moduleinfo->introformat = FORMAT_HTML;
            }
        } elseif ($params['module'] == 'url') {
            if (plugin_supports('mod', 'url', FEATURE_MOD_INTRO, true)) {
                $editor = 'introeditor';
                $draftid = file_get_unused_draft_itemid();
                $moduleinfo->$editor = array(
                    'text' => $params['content'],
                    'format' => FORMAT_HTML,
                    'itemid' => $draftid
                );
            } else {
                $moduleinfo->intro = $params['content'];
                $moduleinfo->introformat = FORMAT_HTML;
            }
        
            $moduleinfo->externalurl = $params['content'];
        }
    
        // Call update_moduleinfo to update the activity.
        list($cm, $moduleinfo) = update_moduleinfo($cm, $moduleinfo, $course);
    
        // Return success or error message.
        return array('status' => 'success', 'message' => 'Activity updated successfully');
    }
    
    
    public static function update_activity_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Status of the update'),
            'message' => new external_value(PARAM_TEXT, 'Message describing the outcome')
        ]);
    }

    //enrolled course
    public static function get_enrolled_courses_parameters()
    {
        return new external_function_parameters(
            array(
                'user_emails' => new external_multiple_structure(
                    new external_value(PARAM_TEXT, 'User email')
                ),
                'courseid' => new external_value(PARAM_INT, 'Optional course ID to filter', VALUE_DEFAULT, null)
            )
        );
    }

    // public static function get_enrolled_courses($user_emails)
    // {
    //     global $DB;

    //     $user_emails_array = $user_emails;

    //     if (empty($user_emails_array)) {
    //         throw new invalid_parameter_exception('User emails cannot be empty');
    //     }

    //     // Kiểm tra nếu json_decode thất bại, vì vậy ta cần làm sạch chuỗi theo cách thủ công
    //     $emailsString = implode("','", $user_emails_array);
    //     $emailsString = "'" . $emailsString . "'";
        
    //     // // Lấy danh sách khóa học mà người dùng đã enroll
    //     $sql = "SELECT 
    //         c.id as course_id,
    //         c.fullname as course_name,
    //         COUNT(DISTINCT CASE WHEN r.archetype = 'student' THEN u.id END) AS total_students,
    //         COUNT(DISTINCT CASE WHEN r.archetype IN ('editingteacher','teacher') THEN u.id END) AS total_teachers
    //         FROM mdl_user u
    //         JOIN mdl_user_enrolments uem ON uem.userid = u.id
    //         JOIN mdl_enrol e ON e.id = uem.enrolid
    //         JOIN mdl_course c ON e.courseid = c.id
    //         JOIN mdl_role_assignments ra ON ra.userid = u.id
    //         JOIN mdl_role r ON ra.roleid = r.id
    //         WHERE u.email IN ($emailsString)
    //         AND (c.id, c.timemodified) IN (
    //                 SELECT 
    //                     id, MAX(timemodified) AS max_timemodified
    //                 FROM 
    //                     mdl_course
    //                 GROUP BY 
    //                     id
    //             )
    //         GROUP BY c.fullname, c.id
    //         ORDER BY course_id ASC";
        
    //     $enrolled_courses = $DB->get_records_sql($sql);
    //     // Tổ chức lại dữ liệu
    //     $courses_enrolled = [];
    //     $course_ids = [];
    //     foreach ($enrolled_courses as $course) {
    //         $groupQuery = "SELECT g.id AS group_id, g.name AS group_name
    //             FROM mdl_groups g
    //             WHERE g.courseid = :course_id";

    //         $groups = $DB->get_records_sql($groupQuery, ['course_id' => $course->course_id]);
                    
    //         $group_list = [];
    //         foreach ($groups as $group) {
    //             $studentsQuery = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
    //                 FROM mdl_user u
    //                 JOIN mdl_role_assignments ra ON ra.userid = u.id
    //                 JOIN mdl_role r ON ra.roleid = r.id
    //                 JOIN mdl_groups_members gm ON gm.userid = u.id
    //                 JOIN mdl_groups g ON g.id = gm.groupid
    //                 WHERE g.id = :group_id AND r.archetype = 'student' AND u.email IN ($emailsString)";

    //             $students = $DB->get_records_sql($studentsQuery, ['group_id' => $group->group_id]);

    //             $student_list = [];
    //             foreach ($students as $student) {
    //                 $student_list[] = [
    //                     'id' => $student->id,
    //                     'firstname' => $student->firstname,
    //                     'lastname' => $student->lastname,
    //                     'email' => $student->email
    //                 ];
    //             }

    //             // Lấy giáo viên trong nhóm
    //             $teachersQuery = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
    //                 FROM mdl_user u
    //                 JOIN mdl_role_assignments ra ON ra.userid = u.id
    //                 JOIN mdl_role r ON ra.roleid = r.id
    //                 JOIN mdl_groups_members gm ON gm.userid = u.id
    //                 JOIN mdl_groups g ON g.id = gm.groupid
    //                 WHERE g.id = :group_id AND r.archetype IN ('editingteacher','teacher') AND u.email IN ($emailsString)";

    //             $teachers = $DB->get_records_sql($teachersQuery, ['group_id' => $group->group_id]);

    //             $teacher_list = [];
    //             foreach ($teachers as $teacher) {
    //                 $teacher_list[] = [
    //                     'id' => $teacher->id,
    //                     'firstname' => $teacher->firstname,
    //                     'lastname' => $teacher->lastname,
    //                     'email' => $teacher->email
    //                 ];
    //             }
    //             // Lấy participants từ student_list và teacher_list
    //             $participants = array_merge(
    //                 array_map(fn($s) => array_merge($s, ['role' => 'student']), $student_list),
    //                 array_map(fn($t) => array_merge($t, ['role' => 'teacher']), $teacher_list)
    //             );
    //             $group_list[] = [
    //                 'group_id' => $group->group_id,
    //                 'group_name' => $group->group_name,
    //                 'students' => $student_list,
    //                 'teachers' => $teacher_list,
    //                 'participants' => $participants
    //             ];
    //         }

    //         $studentsQuery = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
    //             FROM mdl_user u
    //             JOIN mdl_role_assignments ra ON ra.userid = u.id
    //             JOIN mdl_role r ON ra.roleid = r.id
    //             JOIN mdl_context ctx ON ra.contextid = ctx.id
    //             JOIN mdl_course c ON ctx.instanceid = c.id AND ctx.contextlevel = 50
    //             WHERE u.email in ($emailsString)
    //             AND r.archetype = 'student'
    //             AND c.id = :course_id
    //         ";

    //         $students = $DB->get_records_sql($studentsQuery, ['course_id' => $course->course_id]);
                                
    //         $student_list = [];
    //         foreach ($students as $student) {
    //             $student_list[] = [
    //                 'id' => $student->id,
    //                 'firstname' => $student->firstname,
    //                 'lastname' => $student->lastname,
    //                 'email' => $student->email
    //             ];
    //         }

    //         $teachersQuery = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
    //             FROM mdl_user u
    //             JOIN mdl_role_assignments ra ON ra.userid = u.id
    //             JOIN mdl_role r ON ra.roleid = r.id
    //             JOIN mdl_context ctx ON ra.contextid = ctx.id
    //             JOIN mdl_course c ON ctx.instanceid = c.id AND ctx.contextlevel = 50
    //             WHERE u.email in ($emailsString)
    //             AND r.archetype IN ('editingteacher','teacher')
    //             AND c.id = :course_id
    //         ";

    //         $teachers = $DB->get_records_sql($teachersQuery, ['course_id' => $course->course_id]);
                                
    //         $teacher_list = [];
    //         foreach ($teachers as $teacher) {
    //             $teacher_list[] = [
    //                 'id' => $teacher->id,
    //                 'firstname' => $teacher->firstname,
    //                 'lastname' => $teacher->lastname,
    //                 'email' => $teacher->email
    //             ];
    //         }

    //         $participants = array_merge(
    //             array_map(fn($s) => array_merge($s, ['role' => 'student']), $student_list),
    //             array_map(fn($t) => array_merge($t, ['role' => 'teacher']), $teacher_list)
    //         );

    //         $courses_enrolled[] = [
    //             'course_id' => $course->course_id,
    //             'course_name' => $course->course_name,
    //             'total_students' => $course->total_students,
    //             'total_teachers' => $course->total_teachers,
    //             'groups' => $group_list,
    //             'students' => $student_list,
    //             'teachers' => $teacher_list,
    //             'participants' => $participants
    //         ];
    //         $course_ids[] = $course->course_id;
    //     }

    //     $remainingCourses = [];
    //     if (!empty($course_ids)) {
    //         // Chuyển mảng course_ids thành chuỗi để sử dụng trong câu truy vấn
    //         $course_ids_str = implode(',', $course_ids);

    //         // Truy vấn các khóa học còn lại không nằm trong danh sách đã lấy
    //         $queryRemainingCourses = "
    //         SELECT DISTINCT c.id as course_id, c.fullname as course_name
    //         FROM mdl_course c
    //         WHERE c.id NOT IN ($course_ids_str)
    //         AND c.id != 1
    //         AND (c.id, c.timemodified) IN (
    //             SELECT 
    //                 id, MAX(timemodified) AS max_timemodified
    //             FROM 
    //                 mdl_course
    //             GROUP BY 
    //                 id
    //         )
    //         ORDER BY course_id ASC
    //         ";

    //         $resultRemainingCourses = $DB->get_records_sql($queryRemainingCourses);
    //     } else {
    //         // Nếu không có khóa học nào trong danh sách enrolled_courses
    //         $queryRemainingCourses = "
    //         SELECT DISTINCT c.id as course_id, c.fullname as course_name
    //         FROM mdl_course c
    //         WHERE c.id != 1
    //         AND (c.id, c.timemodified) IN (
    //             SELECT 
    //                 id, MAX(timemodified) AS max_timemodified
    //             FROM 
    //                 mdl_course
    //             GROUP BY 
    //                 id
    //         )
    //         ORDER BY course_id ASC
    //         ";
        
    //         $resultRemainingCourses = $DB->get_records_sql($queryRemainingCourses);
    //     }

    //     foreach ($resultRemainingCourses as $course) {
    //         $groupQuery = "SELECT g.id AS group_id, g.name AS group_name
    //             FROM mdl_groups g
    //             WHERE g.courseid = :course_id";
    
    //         $groups = $DB->get_records_sql($groupQuery, ['course_id' => $course->course_id]);
    
    //         $group_list = [];
    //         foreach ($groups as $group) {
    //             $group_list[] = [
    //                 'group_id' => $group->group_id,
    //                 'group_name' => $group->group_name
    //             ];
    //         }

    //         $remainingCourses[] = [
    //             'course_id' => $course->course_id,
    //             'course_name' => $course->course_name,
    //             'groups' => $group_list
    //         ];
    //     }

    //     return [
    //         'enrolled_courses' => $courses_enrolled,
    //         'remaining_courses' => $remainingCourses
    //     ];
    // }

    public static function unique_users_by_id(array $users): array {
        $unique = [];
        foreach ($users as $u) {
            $uid = is_array($u) ? $u['id'] : $u->user_id; // hỗ trợ cả array và object
            if (!isset($unique[$uid])) {
                $unique[$uid] = $u;
            }
        }
        return array_values($unique);
    }

    public static function get_enrolled_courses($user_emails, $courseid = null)
    {
        global $DB;

        $user_emails_array = $user_emails;

        if (empty($user_emails_array)) {
            throw new invalid_parameter_exception('User emails cannot be empty');
        }

        // Kiểm tra nếu json_decode thất bại, vì vậy ta cần làm sạch chuỗi theo cách thủ công
        // $emailsString = implode("','", $user_emails_array);
        // $emailsString = "'" . $emailsString . "'";

        $placeholders = implode(',', array_fill(0, count($user_emails_array), '?'));
        $params = $user_emails_array;

        $course_filter = '';
        if (!empty($courseid)) {
            $course_filter = ' AND c.id = ?';
            $params[] = $courseid;
        }

        // 1. Lấy danh sách course enrolled của user emails (và tổng số student/teacher theo course)
        // Sử dụng :emails là mảng parameter được Moodle DB tự bind chuẩn
        $sql_courses = "
            SELECT 
                c.id AS course_id,
                c.fullname AS course_name,
                COUNT(DISTINCT CASE WHEN r.archetype = 'student' THEN u.id END) AS total_students,
                COUNT(DISTINCT CASE WHEN r.archetype IN ('editingteacher','teacher') THEN u.id END) AS total_teachers
            FROM mdl_user u
            JOIN mdl_user_enrolments uem ON uem.userid = u.id
            JOIN mdl_enrol e ON e.id = uem.enrolid
            JOIN mdl_course c ON e.courseid = c.id
            JOIN mdl_role_assignments ra ON ra.userid = u.id
            JOIN mdl_role r ON ra.roleid = r.id
            WHERE u.email IN ($placeholders)
            AND c.visible = 1
            $course_filter
            GROUP BY c.id, c.fullname
            ORDER BY c.id ASC
        ";
        
        $enrolled_courses = $DB->get_records_sql($sql_courses, $params);
        if (!$enrolled_courses) {
            $enrolled_courses = [];
        }

        $course_ids = array_map(fn($c) => $c->course_id, $enrolled_courses);
        if (empty($course_ids)) {
            $course_ids = [0]; // tránh lỗi IN ()
        }
        // 2. Lấy tất cả groups của các khóa học này trong 1 query duy nhất
        $sql_groups = "
            SELECT g.id AS group_id, g.courseid AS course_id, g.name AS group_name
            FROM mdl_groups g
            WHERE g.courseid IN (" . implode(',', array_map('intval', $course_ids)) . ")
        ";
        $groups = $DB->get_records_sql($sql_groups);

        // Gom groups theo course_id
        $groups_by_course = [];
        foreach ($groups as $g) {
            $groups_by_course[$g->course_id][] = $g;
        }

        // 3. Lấy tất cả thành viên groups (student + teacher) trong 1 query duy nhất
        $sql_group_members = "
            SELECT 
                CONCAT(g.courseid, '_', u.id, '_', r.id, '_', g.id) AS unique_key,
                g.courseid AS course_id,
                g.id AS group_id,
                u.id AS user_id,
                u.firstname,
                u.lastname,
                u.email,
                r.archetype,
                uem.timecreated AS enroll_time
            FROM mdl_groups_members gm
            JOIN mdl_groups g ON g.id = gm.groupid
            JOIN mdl_user u ON u.id = gm.userid
            JOIN mdl_role_assignments ra ON ra.userid = u.id
            JOIN mdl_role r ON ra.roleid = r.id
            JOIN mdl_user_enrolments uem ON uem.userid = u.id
            JOIN mdl_enrol e ON e.id = uem.enrolid AND e.courseid = g.courseid
            WHERE g.courseid IN (" . implode(',', array_map('intval', $course_ids)) . ")
            AND u.email IN ($placeholders)
            AND r.archetype IN ('student','editingteacher','teacher')
        ";
        $group_members = $DB->get_records_sql($sql_group_members, $params);


        // Gom thành viên nhóm theo course_id => group_id
        $members_by_course_group = [];
        foreach ($group_members as $m) {
            $members_by_course_group[$m->course_id][$m->group_id][] = $m;
        }

        // 4. Lấy thành viên theo course (không theo group)
        $sql_course_members = "
            SELECT 
                CONCAT(c.id, '_', u.id, '_', r.id) AS unique_key,
                c.id AS course_id,
                u.id AS user_id,
                u.firstname,
                u.lastname,
                u.email,
                r.archetype,
                uem.timecreated AS enroll_time
            FROM mdl_user u
            JOIN mdl_role_assignments ra ON ra.userid = u.id
            JOIN mdl_role r ON ra.roleid = r.id
            JOIN mdl_context ctx ON ra.contextid = ctx.id AND ctx.contextlevel = 50
            JOIN mdl_course c ON ctx.instanceid = c.id
            JOIN mdl_user_enrolments uem ON uem.userid = u.id
            JOIN mdl_enrol e ON e.id = uem.enrolid AND e.courseid = c.id
            WHERE c.id IN (" . implode(',', array_map('intval', $course_ids)) . ")
            AND c.visible = 1
            AND u.email IN ($placeholders)
            AND r.archetype IN ('student','editingteacher','teacher')
        ";
        $course_members = $DB->get_records_sql($sql_course_members, $params);

        // var_dump($course_members);die;
        // Gom thành viên theo course_id và role
        $members_by_course_role = [];
        foreach ($course_members as $m) {
            $members_by_course_role[$m->course_id][$m->archetype][] = $m;
        }
        // 5. Build kết quả
        $courses_enrolled = [];

        foreach ($enrolled_courses as $course) {
            $course_id = $course->course_id;

            // Lấy groups trong course
            $group_list = [];
            if (isset($groups_by_course[$course_id])) {
                foreach ($groups_by_course[$course_id] as $group) {
                    $student_list = [];
                    $teacher_list = [];

                    // Lấy member theo group và phân loại role
                    if (isset($members_by_course_group[$course_id][$group->group_id])) {
                        foreach ($members_by_course_group[$course_id][$group->group_id] as $member) {
                            $user_info = [
                                'id' => $member->user_id,
                                'firstname' => $member->firstname,
                                'lastname' => $member->lastname,
                                'email' => $member->email,
                                'enroll_time' => $member->enroll_time,
                                'enroll_date' => date('Y-m-d H:i:s', $member->enroll_time)
                            ];
                            if ($member->archetype === 'student') {
                                $student_list[] = $user_info;
                            } else {
                                $teacher_list[] = $user_info;
                            }
                        }
                    }

                    $participants = array_merge(
                        array_map(fn($s) => array_merge($s, ['role' => 'student']), $student_list),
                        array_map(fn($t) => array_merge($t, ['role' => 'teacher']), $teacher_list)
                    );

                    $teacher_list = self::unique_users_by_id($teacher_list);
                    $student_list = self::unique_users_by_id($student_list);
                    $participants = self::unique_users_by_id($participants);

                    $group_list[] = [
                        'group_id' => $group->group_id,
                        'group_name' => $group->group_name,
                        'students' => $student_list,
                        'teachers' => $teacher_list,
                        'participants' => $participants,
                    ];
                }
            }

            // Thành viên course (không theo group)
            $student_list = $members_by_course_role[$course_id]['student'] ?? [];
            $teacher_list = array_merge(
                $members_by_course_role[$course_id]['teacher'] ?? [],
                $members_by_course_role[$course_id]['editingteacher'] ?? []
            );

            // Map lại định dạng
            $map_user = fn($user) => [
                'id' => $user->user_id,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'email' => $user->email,
                'enroll_time' => $user->enroll_time,
                'enroll_date' => date('Y-m-d H:i:s', $user->enroll_time)
            ];

            $student_list = array_map($map_user, $student_list);
            $teacher_list = array_map($map_user, $teacher_list);

            $participants = array_merge(
                array_map(fn($s) => array_merge($s, ['role' => 'student']), $student_list),
                array_map(fn($t) => array_merge($t, ['role' => 'teacher']), $teacher_list)
            );

            $student_list = self::unique_users_by_id($student_list);
            $teacher_list = self::unique_users_by_id($teacher_list);
            $participants = self::unique_users_by_id($participants);

            $courses_enrolled[] = [
                'course_id' => $course->course_id,
                'course_name' => $course->course_name,
                'total_students' => $course->total_students,
                'total_teachers' => $course->total_teachers,
                'groups' => $group_list,
                'students' => $student_list,
                'teachers' => $teacher_list,
                'participants' => $participants,
            ];
        }

        // 6. Lấy các khóa học còn lại (không enroll user)
        $sql_remaining = "
            SELECT c.id AS course_id, c.fullname AS course_name
            FROM mdl_course c
            WHERE c.id != 1
            AND c.visible = 1
            AND c.id NOT IN (" . implode(',', array_map('intval', $course_ids)) . ")
            ORDER BY c.id ASC
        ";
        $remaining_courses = $DB->get_records_sql($sql_remaining);

        // Lấy groups cho các khóa học còn lại
        $remaining_course_ids = array_map(fn($c) => $c->course_id, $remaining_courses);
        $remaining_groups = [];
        if (!empty($remaining_course_ids)) {
            $sql_rem_groups = "
                SELECT g.id AS group_id, g.courseid AS course_id, g.name AS group_name
                FROM mdl_groups g
                WHERE g.courseid IN (" . implode(',', array_map('intval', $remaining_course_ids)) . ")
            ";
            $remaining_groups = $DB->get_records_sql($sql_rem_groups);
        }

        // Gom groups theo course_id
        $remaining_groups_by_course = [];
        foreach ($remaining_groups as $g) {
            $remaining_groups_by_course[$g->course_id][] = $g;
        }

        $remainingCourses = [];
        foreach ($remaining_courses as $course) {
            $group_list = [];
            if (isset($remaining_groups_by_course[$course->course_id])) {
                foreach ($remaining_groups_by_course[$course->course_id] as $group) {
                    $group_list[] = [
                        'group_id' => $group->group_id,
                        'group_name' => $group->group_name,
                    ];
                }
            }

            $remainingCourses[] = [
                'course_id' => $course->course_id,
                'course_name' => $course->course_name,
                'groups' => $group_list,
            ];
        }

        return [
            'enrolled_courses' => $courses_enrolled,
            'remaining_courses' => $remainingCourses
        ];
    }

    public static function get_enrolled_courses_returns()
    {
        return new external_single_structure(
            array(
                'enrolled_courses' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'course_id' => new external_value(PARAM_INT, 'Course ID'),
                            'course_name' => new external_value(PARAM_TEXT, 'Course name'),
                            'total_students' => new external_value(PARAM_INT, 'Total number of students'),
                            'total_teachers' => new external_value(PARAM_INT, 'Total number of teachers'),
                            'groups' => new external_multiple_structure(
                                new external_single_structure(
                                    array(
                                        'group_id' => new external_value(PARAM_INT, 'Group ID'),
                                        'group_name' => new external_value(PARAM_TEXT, 'Group name'),
                                        'students' => new external_multiple_structure(
                                            new external_single_structure(
                                                array(
                                                    'id' => new external_value(PARAM_INT, 'Student ID'),
                                                    'firstname' => new external_value(PARAM_TEXT, 'First name'),
                                                    'lastname' => new external_value(PARAM_TEXT, 'Last name'),
                                                    'email' => new external_value(PARAM_TEXT, 'Email'),
                                                    'enroll_time' => new external_value(PARAM_RAW, 'Enroll Time'),
                                                    'enroll_date' => new external_value(PARAM_TEXT, 'Enroll Date')
                                                )
                                            )
                                        ),
                                        'teachers' => new external_multiple_structure(
                                            new external_single_structure(
                                                array(
                                                    'id' => new external_value(PARAM_INT, 'Teacher ID'),
                                                    'firstname' => new external_value(PARAM_TEXT, 'First name'),
                                                    'lastname' => new external_value(PARAM_TEXT, 'Last name'),
                                                    'email' => new external_value(PARAM_TEXT, 'Email'),
                                                    'enroll_time' => new external_value(PARAM_RAW, 'Enroll Time'),
                                                    'enroll_date' => new external_value(PARAM_TEXT, 'Enroll Date')
                                                )
                                            )
                                        ),
                                        'participants' => new external_multiple_structure(
                                            new external_single_structure(
                                                array(
                                                    'id' => new external_value(PARAM_INT, 'Participant ID'),
                                                    'firstname' => new external_value(PARAM_TEXT, 'First name'),
                                                    'lastname' => new external_value(PARAM_TEXT, 'Last name'),
                                                    'email' => new external_value(PARAM_TEXT, 'Email'),
                                                    'role' => new external_value(PARAM_TEXT, 'Role (student or teacher)'),
                                                    'enroll_time' => new external_value(PARAM_RAW, 'Enroll Time'),
                                                    'enroll_date' => new external_value(PARAM_TEXT, 'Enroll Date')
                                                )
                                            )
                                        )
                                    )
                                )
                            ),
                            'students' => new external_multiple_structure(
                                new external_single_structure(
                                    array(
                                        'id' => new external_value(PARAM_INT, 'Student ID'),
                                        'firstname' => new external_value(PARAM_TEXT, 'First name'),
                                        'lastname' => new external_value(PARAM_TEXT, 'Last name'),
                                        'email' => new external_value(PARAM_TEXT, 'Email'),
                                        'enroll_time' => new external_value(PARAM_RAW, 'Enroll Time'),
                                        'enroll_date' => new external_value(PARAM_TEXT, 'Enroll Date')
                                    )
                                )
                            ),
                            'teachers' => new external_multiple_structure(
                                new external_single_structure(
                                    array(
                                        'id' => new external_value(PARAM_INT, 'Teacher ID'),
                                        'firstname' => new external_value(PARAM_TEXT, 'First name'),
                                        'lastname' => new external_value(PARAM_TEXT, 'Last name'),
                                        'email' => new external_value(PARAM_TEXT, 'Email'),
                                        'enroll_time' => new external_value(PARAM_RAW, 'Enroll Time'),
                                        'enroll_date' => new external_value(PARAM_TEXT, 'Enroll Date')
                                    )
                                )
                            ),
                            'participants' => new external_multiple_structure(
                                new external_single_structure(
                                    array(
                                        'id' => new external_value(PARAM_INT, 'Participant ID'),
                                        'firstname' => new external_value(PARAM_TEXT, 'First name'),
                                        'lastname' => new external_value(PARAM_TEXT, 'Last name'),
                                        'email' => new external_value(PARAM_TEXT, 'Email'),
                                        'role' => new external_value(PARAM_TEXT, 'Role (student or teacher)'),
                                        'enroll_time' => new external_value(PARAM_RAW, 'Enroll Time'),
                                        'enroll_date' => new external_value(PARAM_TEXT, 'Enroll Date')
                                    )
                                )
                            )
                        )
                    )
                ),
                'remaining_courses' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'course_id' => new external_value(PARAM_INT, 'Remaining course ID'),
                            'course_name' => new external_value(PARAM_TEXT, 'Remaining course name'),
                            'groups' => new external_multiple_structure(
                                new external_single_structure(
                                    array(
                                        'group_id' => new external_value(PARAM_INT, 'Group ID'),
                                        'group_name' => new external_value(PARAM_TEXT, 'Group name')
                                    )
                                )
                            )
                        )
                    )
                )
            )
        );
    }

    // Functionset for get_user_enrol_course_by_teacher() ******************************************************************************************.

    /**
     * Parameter description for get_user_enrol_course_by_teacher().
     *
     * @return external_function_parameters.
     */
    public static function get_user_enrol_course_by_teacher_parameters()
    {
        return new external_function_parameters(
            array(
                'courseid' => new external_value(PARAM_INT, 'courseid', VALUE_DEFAULT, 0),
                'coursename' => new external_value(PARAM_TEXT, 'coursename', VALUE_DEFAULT, 0),
                'userid' => new external_value(PARAM_INT, 'userid', VALUE_DEFAULT, 0),
                'useremail' => new external_value(PARAM_TEXT, 'useremail', VALUE_DEFAULT, 0),
                'role' => new external_value(PARAM_TEXT, 'role', VALUE_DEFAULT, 'all'),
                'fullname' => new external_value(PARAM_TEXT, 'fullname', VALUE_DEFAULT, 0),
                'limit' => new external_value(PARAM_INT, 'the number of results to return', VALUE_DEFAULT, 0),
                'offset' => new external_value(PARAM_INT, 'offset the result set by a given amount', VALUE_DEFAULT, 0)
            )
        );
    }

    /**
     * Create $number sections at $position.
     *
     * This function creates $number new sections at position $position.
     * If $position = 0 the sections are appended to the end of the course.
     *
     * @param int $courseid Courseid of the belonging course.
     * @param int $position Position the section is created at.
     * @param int $number Number of section to create.
     * @return array Array of arrays with sectionid and sectionnumber for each created section.
     */
    public static function get_user_enrol_course_by_teacher($courseid, $coursename, $userid, $useremail, $role, $fullname, $limit, $offset)
    {
        global $CFG, $USER, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/completionlib.php');

        // Count total courses
        $sql_2_count = "SELECT COUNT(DISTINCT c.id) as total_count
        FROM mdl_user u
        JOIN mdl_role_assignments ra ON u.id = ra.userid
        JOIN mdl_context ctx ON ra.contextid = ctx.id
        JOIN mdl_role r ON ra.roleid = r.id
        JOIN mdl_course c ON ctx.instanceid = c.id
        WHERE u.id = $userid
        AND c.visible = 1";

        if ($role == 'student') {
            $sql_2_count .= " and r.shortname = 'student'";
        }

        if ($role == 'teacher') {
            $sql_2_count .= " and (r.shortname = 'editingteacher' OR r.shortname = 'teacher')";
        }

        if (!empty($courseid)) {
            $sql_2_count .= " and c.id = $courseid";
        }
        if (!empty($coursename)) {
            $sql_2_count .= " and c.fullname like '%$coursename%'";
        }

        $mods_count = $DB->get_record_sql($sql_2_count);
        $result['count'] = $mods_count->total_count;

        // Get course details
        $sql_2 = "SELECT DISTINCT c.fullname, c.id, c.summary, f.filename AS course_image, f.contextid AS f_contextid,
                IFNULL(FROM_UNIXTIME(l.timeaccess, '%d-%m-%Y %H:%i:%s'), 'N/A') AS last_access_time,
                cat.id AS categoryid, cat.name AS categoryname
        FROM mdl_user u
        JOIN mdl_role_assignments ra ON u.id = ra.userid
        JOIN mdl_context ctx ON ra.contextid = ctx.id
        JOIN mdl_role r ON ra.roleid = r.id
        JOIN mdl_course c ON ctx.instanceid = c.id
        LEFT JOIN mdl_files f ON f.contextid = ctx.id AND f.component = 'course' AND f.filearea = 'overviewfiles' AND f.filename <> '.'
        LEFT JOIN mdl_user_lastaccess l ON l.courseid = c.id AND l.userid = u.id
        JOIN mdl_course_categories cat ON c.category = cat.id
        WHERE u.id = $userid
        AND c.visible = 1";

        if ($role == 'student') {
            $sql_2 .= " and r.shortname = 'student'";
        }

        if ($role == 'teacher') {
            $sql_2 .= " and (r.shortname = 'editingteacher' OR r.shortname = 'teacher')";
        }

        if (!empty($courseid)) {
            $sql_2 .= " and c.id = $courseid";
        }

        if (!empty($coursename)) {
            $sql_2 .= " and c.fullname like '%$coursename%'";
        }

        $sql_2 .= " ORDER BY l.timeaccess DESC";

        if (!empty($limit)) {
            $sql_2 .= " LIMIT $limit OFFSET $offset";
        }

        $mods1 = $DB->get_records_sql($sql_2);
        $result['contentfilter'] = [];

        if (!empty($mods1)) {
            foreach ($mods1 as $cm) {

                $userGroupData = self::get_data_student_by_teacher($cm->id, 0, $userid, 'teacher', 0, 0);

                $resultUserGroupData = [];

                $totalActivities = self::get_total_activities_in_course($cm->id);

                $totalActivitiesAllStudent = 0;

                $totalActivitiesCompletionStudent = 0;

                foreach ($userGroupData['student_data'] as $student) {
                    $totalActivitiesAllStudent += $totalActivities;
                    $completedActivities = self::get_user_completed_activities($student['userid'], $cm->id);
                    $student['completed_activities'] = $completedActivities;
                    $totalActivitiesCompletionStudent += $completedActivities;
                    $resultUserGroupData[] = $student;
                }

                $courseresult = [
                    'id' => $cm->id,
                    'coursename' => $cm->fullname,
                    'summary' => $cm->summary,
                    'course_image' => $cm->course_image ? $CFG->wwwroot . '/pluginfile.php/' . $cm->f_contextid . '/course/overviewfiles/' . $cm->course_image : '',
                    'last_access_time' => $cm->last_access_time,
                    'categoryid' => $cm->categoryid,
                    'categoryname' => $cm->categoryname,
                    'firstname' => '',
                    'lastname' => '',
                    'email' => '',
                    'view_url' => $CFG->wwwroot . '/course/view.php?id=' . $cm->id,
                    'group_data' => $resultUserGroupData,
                    'total_activities' => $totalActivities,
                    'total_activities_all_student' => $totalActivitiesAllStudent,
                    'total_activities_completion' => $totalActivitiesCompletionStudent
                ];
                $result['contentfilter'][] = $courseresult;
            }
        }

        return $result;
    }

    /**
     * Parameter description for get_course_image().
     *
     * @return external_description
     */
    public static function get_user_enrol_course_by_teacher_returns()
    {
        return new external_single_structure([
            'count' => new external_value(PARAM_INT, 'count'),
            'contentfilter' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'id'),
                'coursename' => new external_value(PARAM_RAW, 'coursename'),
                'course_image' => new external_value(PARAM_RAW, 'course_image'),
                'last_access_time' => new external_value(PARAM_RAW, 'last_access_time'),
                'summary' => new external_value(PARAM_RAW, 'summary'),
                'view_url' => new external_value(PARAM_RAW, 'view_url'),
                'firstname' => new external_value(PARAM_RAW, 'firstname'),
                'lastname' => new external_value(PARAM_RAW, 'lastname'),
                'email' => new external_value(PARAM_RAW, 'email'),
                'categoryid' => new external_value(PARAM_INT, 'Category ID'),
                'categoryname' => new external_value(PARAM_RAW, 'Category Name'),
                'group_data' => new external_multiple_structure(new external_single_structure([
                    'userid' => new external_value(PARAM_INT, 'userid'),
                    'fullname' => new external_value(PARAM_RAW, 'fullname'),
                    'email' => new external_value(PARAM_RAW, 'email'),
                    'groupid' => new external_value(PARAM_INT, 'groupid'),
                    'groupname' => new external_value(PARAM_TEXT, 'groupname'),
                    'courseid' => new external_value(PARAM_INT, 'courseid'),             // Thêm courseid
                    'coursename' => new external_value(PARAM_TEXT, 'coursename'),   
                    'completed_activities' => new external_value(PARAM_INT, 'completed_activities'),     // Thêm coursename
                ])),
                'total_activities' => new external_value(PARAM_INT, 'Total activities in course'),
                'total_activities_all_student' => new external_value(PARAM_INT, 'Total activities in course by all student'),
                'total_activities_completion' => new external_value(PARAM_INT, 'Total activities completion'),
            ])),
        ]);
        
    }

    public static function get_user_completed_activities($userid, $courseid)
    {
        global $DB;

        $completion = new completion_info(get_course($courseid));
        $activities = $completion->get_activities();
        $completedCount = 0;

        foreach ($activities as $activity) {
            $status = $completion->get_data($activity, true, $userid);
            if ($status->completionstate == COMPLETION_COMPLETE) {
                $completedCount++;
            }
        }

        return $completedCount;
    }

    public static function get_total_activities_in_course($courseid)
    {
        global $DB;

        // Đếm số hoạt động trong khóa học
        $completion = new completion_info(get_course($courseid));
        $activities = $completion->get_activities();

        return count($activities);
    }



    /**
     * Parameter description for create_activity_label().
     *
     * @return external_function_parameters.
     */
    public static function create_activity_quiz_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'ID của khóa học'),
            'name' => new external_value(PARAM_TEXT, 'Tên của activity'),
            'section' => new external_value(PARAM_INT, 'Số thứ tự của section để thêm activity', VALUE_DEFAULT, 0),
            'description' => new external_value(PARAM_RAW, 'mô tả quiz', VALUE_DEFAULT, ''),
            // 'completioncmid' => new external_value(PARAM_INT, 'ID của activity cần hoàn thành (null nếu không có)', VALUE_DEFAULT, null, VALUE_OPTIONAL),
        ]);
    }
    

    /**
     * Function to create a quiz activity in a course.
     */
    public static function create_activity_quiz($courseid, $name, $section = 0, $description = '') {
        global $DB, $USER;
    
        // Validate the parameters.
        $params = self::validate_parameters(self::create_activity_quiz_parameters(), [
            'courseid' => $courseid,
            'name' => $name,
            'section' => $section,
            // 'completioncmid' => $completioncmid
        ]);

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    
        $module = $DB->get_record('modules', ['name' => 'quiz'], '*', MUST_EXIST);
        $moduleid = $module->id;
        //create an object with all of the neccesary information to build a quiz
        $myQuiz = new stdClass();
        $myQuiz->modulename='quiz';
        $myQuiz->module = $moduleid;
        $myQuiz->name = $name;
        $myQuiz->introformat = FORMAT_HTML;
        $myQuiz->quizpassword = '';
        $myQuiz->course = $courseid;
        $myQuiz->section = $section;
        $myQuiz->timeopen = 0;
        $myQuiz->timeclose = 0;
        $myQuiz->timelimit = 0;
        $myQuiz->gradepass = 10;
        $myQuiz->grade = 10;
        $myQuiz->sumgrades = 10;
        $myQuiz->gradeperiod = 0;
        $myQuiz->attempts = 1;
        $myQuiz->preferredbehaviour = 'deferredfeedback';
        $myQuiz->attemptonlast = 0;
        $myQuiz->shufflequestions = 0;
        $myQuiz->grademethod = 1;
        $myQuiz->questiondecimalpoints = 2;
        $myQuiz->visible = 1;
        $myQuiz->questionsperpage = 1;
        $myQuiz->introeditor = array('text' => 'A matching quiz','format' => 1);

        //all of the review options
        $myQuiz->attemptduring=1;
        $myQuiz->correctnessduring=1;
        $myQuiz->marksduring=1;
        $myQuiz->specificfeedbackduring=1;
        $myQuiz->generalfeedbackduring=1;
        $myQuiz->rightanswerduring=1;
        $myQuiz->overallfeedbackduring=1;

        $myQuiz->attemptimmediately=1;
        $myQuiz->correctnessimmediately=1;
        $myQuiz->marksimmediately=1;
        $myQuiz->specificfeedbackimmediately=1;
        $myQuiz->generalfeedbackimmediately=1;
        $myQuiz->rightanswerimmediately=1;
        $myQuiz->overallfeedbackimmediately=1;

        $myQuiz->marksopen=1;

        $myQuiz->attemptclosed=1;
        $myQuiz->correctnessclosed=1;
        $myQuiz->marksclosed=1;
        $myQuiz->specificfeedbackclosed=1;
        $myQuiz->generalfeedbackclosed=1;
        $myQuiz->rightanswerclosed=1;
        $myQuiz->overallfeedbackclosed=1;

        // Thiết lập các biến điều kiện
        // $timeopen = time() + 3600; // Mở quiz sau 1 giờ từ thời điểm hiện tại.
        // $timeclose = time() + 86400; // Đóng quiz sau 1 ngày từ thời điểm hiện tại.

        // $gradeitemid = 5; // ID của mục điểm (grade item ID).
        // $min = 10; // Điểm cần đạt tối thiểu.
        // $max = 50; // Điểm tối đa cần đạt.

        // $timeopen = null; // Mở quiz sau 1 giờ từ thời điểm hiện tại.
        // $timeclose = null; // Đóng quiz sau 1 ngày từ thời điểm hiện tại.

        // $gradeitemid = null; // ID của mục điểm (grade item ID).
        // $min = null; // Điểm cần đạt tối thiểu.
        // $max = null; // Điểm tối đa cần đạt.

        // $completioncmid = 78; // ID của activity cần hoàn thành.
        // $completioncmid = -1; // cần hoàn thành hoạt động trước
        // $completioncmid = isset($params['completioncmid']) ? $params['completioncmid'] : null;

        // Gọi function để lấy JSON availability
        // $myQuiz->availability = self::generate_availability_conditions(
        //     $timeopen, 
        //     $timeclose, 
        //     $gradeitemid, 
        //     $min, 
        //     $max, 
        //     $completioncmid
        // );

        //completion: Cấu hình chế độ hoàn thành:

        // 0: Không theo dõi hoàn thành.
        // 1: Được đánh dấu là hoàn thành thủ công bởi người dùng.
        // 2: Được đánh dấu là hoàn thành tự động khi đáp ứng các điều kiện.
        $myQuiz->completion = 2; // 2: Được đánh dấu là hoàn thành khi đáp ứng các điều kiện.
        $myQuiz->completionview = 1; // Hoạt động được hoàn thành khi người dùng xem nó.
        // $myQuiz->completionexpected = time() + 7 * 24 * 60 * 60; // Thời gian mong đợi hoàn thành (ở đây là 7 ngày sau).
        // $myQuiz->completionusegrade = 1; // Được đánh dấu là hoàn thành khi đạt điểm yêu cầu.

        // $myQuiz2 = create_module($myQuiz);
        if (plugin_supports('mod', 'quiz', FEATURE_MOD_INTRO, true)) {
            $editor = 'introeditor';
            $draftid = file_get_unused_draft_itemid();
            $myQuiz->$editor = array(
                'text' => $description,
                'format' => FORMAT_HTML,
                'itemid' => $draftid
            );
        } else {
            $myQuiz->intro = $description;
        }
        try {
            $created_moduleinfo = add_moduleinfo($myQuiz, $course);
        } catch (moodle_exception $e) {
            debugging('Lỗi khi tạo activity: ' . $e->getMessage(), DEBUG_DEVELOPER);
            throw new moodle_exception('errorcreatingactivity', 'local_yourplugin', '', $e->getMessage());
        }

        return [
            'modulename' => $created_moduleinfo->modulename,
            'cmid' => $created_moduleinfo->coursemodule,
            'instanceid' => $created_moduleinfo->instance,
            'name' => $created_moduleinfo->name,
            'course' => $created_moduleinfo->course,
            'section' => $created_moduleinfo->section
        ];
    }


    /**
     * Generate availability conditions for a Moodle activity.
     *
     * @param int|null $timeopen Timestamp when the activity is available.
     * @param int|null $timeclose Timestamp when the activity is no longer available.
     * @param int|null $gradeitemid Grade item ID for grade condition.
     * @param float|null $min Minimum grade required.
     * @param float|null $max Maximum grade allowed.
     * @param int|null $completioncmid Completion condition based on activity ID.
     * @return string JSON string of availability conditions.
     */
    public static function generate_availability_conditions($timeopen = null, $timeclose = null, $gradeitemid = null, $min = null, $max = null, $completioncmids = null) {
        $conditions = [];
        $showc = [];

        // Điều kiện Restrict Access theo thời gian
        if ($timeopen !== null && $timeclose !== null) {
            $conditions[] = [
                "type" => "date",
                "d" => ">=",
                "t" => $timeopen
            ];
            $showc[] = true;
            
            $conditions[] = [
                "type" => "date",
                "d" => "<",
                "t" => $timeclose
            ];
            $showc[] = true;
        }

        // Điều kiện Restrict Access theo điểm (Grade condition)
        if ($gradeitemid !== null && $min !== null && $max !== null) {
            $conditions[] = [
                "type" => "grade",
                "id" => $gradeitemid,
                "min" => $min,
                "max" => $max
            ];
            $showc[] = true;
        }

        foreach ($completioncmids as $completioncmid) {
            $conditions[] = [
                'type' => 'completion',
                'cm' => $completioncmid,
                'e' => 1 // 1 = đã hoàn thành
            ];
            $showc[] = true;
        }
        // Tạo JSON availability
        $availability = [
            "op" => "&", // Điều kiện 'và' (AND)
            "c" => $conditions,
            "showc" => $showc
        ];

        return json_encode($availability);
    }

    /**
     * Return description for create_activity_label().
     *
     * @return external_single_structure.
     */
    public static function create_activity_quiz_returns() {
        return new external_single_structure([
            'modulename' => new external_value(PARAM_TEXT, 'Module name'),
            'cmid' => new external_value(PARAM_INT, 'Course module ID của label'),
            'instanceid' => new external_value(PARAM_INT, 'Instance ID của label'),
            'name' => new external_value(PARAM_TEXT, 'Tên của label'),
            'course' => new external_value(PARAM_INT, 'Course id'),
            'section' => new external_value(PARAM_INT, 'Số thứ tự của section mà label được thêm vào')
        ]);
    }

    public static function get_user_data_certificate($page = 0, $per_page = 10, $user_id = 0) {
        global $DB, $OUTPUT;
    
        // Kiểm tra và gán giá trị mặc định cho page và per_page nếu không có giá trị
        if ($page <= 0) {
            $page = 1; // Mặc định là trang 1
        }
        
        if ($per_page <= 0) {
            $per_page = 9999; // Mặc định là lấy toàn bộ dữ liệu
        }
    
        // Tính toán offset
        $offset = ($page - 1) * $per_page;
    
        // Truy vấn SQL (thêm điều kiện nếu không phân trang)
        $sql = "SELECT
                mdl_course_modules.id,
                mdl_course_modules_completion.userid,
                mdl_course_modules_completion.timemodified,
                mdl_customcert.name 
                FROM
                mdl_course_modules_completion
                JOIN mdl_course_modules ON mdl_course_modules_completion.coursemoduleid = mdl_course_modules.id
                JOIN mdl_modules ON mdl_course_modules.module = mdl_modules.id
                JOIN mdl_customcert ON mdl_course_modules.instance = mdl_customcert.id 
                WHERE
                mdl_course_modules_completion.userid = $user_id 
                AND mdl_course_modules_completion.completionstate = 1 
                AND mdl_modules.name = 'customcert'";
    
        // Nếu có phân trang, thêm LIMIT
        if ($per_page != 9999) {
            $sql .= " LIMIT $offset, $per_page";
        }
    
        $count_sql = "SELECT
                    COUNT(*)
                FROM
                mdl_course_modules_completion
                JOIN mdl_course_modules ON mdl_course_modules_completion.coursemoduleid = mdl_course_modules.id
                JOIN mdl_modules ON mdl_course_modules.module = mdl_modules.id
                JOIN mdl_customcert ON mdl_course_modules.instance = mdl_customcert.id 
                WHERE
                mdl_course_modules_completion.userid = $user_id 
                AND mdl_course_modules_completion.completionstate = 1 
                AND mdl_modules.name = 'customcert'";
    
        // Thực hiện truy vấn và đếm tổng số bản ghi
        $res = $DB->get_records_sql($sql);
        $total_count = $DB->get_field_sql($count_sql);
    
        // Chuyển đổi dữ liệu thành mảng
        $users = array();
        foreach ($res as $v) {
            $users[] = array(
                'user_id' => $v->userid,
                'module_id' => $v->id,
                'cer_name' => $v->name,
                'cer_link' => '/mod/customcert/view.php?id=' . $v->id . '&downloadown=1',
                'icon_url' => $OUTPUT->image_url('monologo', 'mod_customcert')->out(),
                'time' => date('l, j F Y, g:i A', $v->timemodified),
            );
        }
    
        // Tính tổng số trang nếu có phân trang
        $result = [
            'users' => $users,
            'total_page' => ceil($total_count / $per_page),
        ];
    
        return $result;
    }

    public static function get_user_data_certificate_parameters() {
        return new external_function_parameters(
            array(
                'page' => new external_value(PARAM_INT, 'Page number', VALUE_DEFAULT, 0),
                'per_page' => new external_value(PARAM_INT, 'Number of users per page', VALUE_DEFAULT, 10),
                'user_id' => new external_value(PARAM_INT, 'User id', VALUE_DEFAULT, 0),
            )
        );
    }

    // Định nghĩa kiểu dữ liệu trả về
    public static function get_user_data_certificate_returns() {
        return new external_single_structure(
            array(
                'total_page' => new external_value(PARAM_INT, 'Tổng số page'),
                'users' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'user_id' => new external_value(PARAM_INT, 'ID người dùng'),
                            'module_id' => new external_value(PARAM_INT, 'ID module'),
                            'cer_name' => new external_value(PARAM_TEXT, 'Tên chứng chỉ'),
                            'cer_link' => new external_value(PARAM_TEXT, 'Liên kết chứng chỉ'),
                            'icon_url' => new external_value(PARAM_TEXT, 'Icon url'),
                            'time' => new external_value(PARAM_TEXT, 'Thời gian trao'),
                        )
                    )
                )
            )
        );
    }

    // Functionset for move_section() *********************************************************************************************.

    /**
     * Parameter description for move_section().
     *
     * @return external_function_parameters.
     */
    public static function move_section_parameters() {
        return new external_function_parameters(
            array(
                'courseid' => new external_value(PARAM_INT, 'id of course'),
                'sectionnumber' => new external_value(PARAM_INT, 'number of section'),
                'position' => new external_value(PARAM_INT, 'Move section to position. For position > sectionnumber
                    move to the end of the course.'),
            )
        );
    }

    /**
     * Move a section
     *
     * This function moves a section to position $position.
     *
     * @param int $courseid Courseid of the belonging course.
     * @param int $sectionnumber The sectionnumber of the section to be moved.
     * @param int $position Position the section is moved to.
     * @return null.
     */
    public static function move_section($courseid, $sectionnumber, $position) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/format/lib.php');

        // Validate parameters passed from web service.
        $params = self::validate_parameters(self::move_section_parameters(), array(
            'courseid' => $courseid,
            'sectionnumber' => $sectionnumber,
            'position' => $position));

        if (! ($course = $DB->get_record('course', array('id' => $params['courseid'])))) {
            throw new moodle_exception('invalidcourseid', 'local_custom_service', '', $courseid);
        }

        require_login($course);
        require_capability('moodle/course:update', context_course::instance($courseid));
        require_capability('moodle/course:movesections', context_course::instance($course->id));

        $courseformat = course_get_format($course);
        // Test if courseformat allows sections.
        if (!$courseformat->uses_sections()) {
            throw new moodle_exception('courseformatwithoutsections', 'local_custom_service', '', $courseformat);
        }

        $lastsectionnumber = $courseformat->get_last_section_number();
        // Test if section with $sectionnumber exist.
        if ($sectionnumber < 0 or $sectionnumber > $lastsectionnumber) {
            throw new moodle_exception('invalidsectionnumber', 'local_custom_service', '',
                array('sectionnumber' => $sectionumber, 'lastsectionnumber' => $lastsectionnumber));
        }

        // Move section.
        if (!move_section_to($course, $sectionnumber, $position)) {
            throw new moodle_exception('movesectionerror', 'local_custom_service');
        }

        return  null;
    }

    /**
     * Parameter description for move_section().
     *
     * @return external_description
     */
    public static function move_section_returns() {
        return null;
    }


    // Function set for move_activity() *********************************************************************************************.

    /**
     * Parameter description for move_activity_to_section().
     *
     * @return external_function_parameters
     */
    public static function move_activity_to_section_parameters() {
        return new external_function_parameters(
            array(
                'courseid' => new external_value(PARAM_INT, 'id of course'),
                'moduleid' => new external_value(PARAM_INT, 'id of the module (activity)'),
                'newsection' => new external_value(PARAM_INT, 'id of the section to move the activity to')
            )
        );
    }

    /**
     * Move an activity to a different section.
     *
     * This function moves an activity from its current section to a new section.
     *
     * @param int $courseid The ID of the course.
     * @param int $moduleid The ID of the module (activity).
     * @param int $newsection The ID of the section to move the activity to.
     * @return null.
     */
    public static function move_activity_to_section($courseid, $moduleid, $newsection) {
        global $DB, $USER;

        // Validate parameters passed from web service.
        $params = self::validate_parameters(self::move_activity_to_section_parameters(), array(
            'courseid' => $courseid,
            'moduleid' => $moduleid,
            'newsection' => $newsection
        ));

        // Ensure course exists.
        if (!($course = $DB->get_record('course', array('id' => $params['courseid'])))) {
            throw new moodle_exception('invalidcourseid', 'local_custom_service', '', $courseid);
        }

        // Ensure module exists.
        if (!($module = $DB->get_record('course_modules', array('id' => $params['moduleid'])))) {
            throw new moodle_exception('invalidmoduleid', 'local_custom_service', '', $moduleid);
        }

        // Ensure module belongs to the course.
        if ($module->course != $course->id) {
            throw new moodle_exception('modulenotincourse', 'local_custom_service');
        }

        // Check if section exists.
        $section = $DB->get_record('course_sections', array('course' => $courseid, 'section' => $newsection));
        if (!$section) {
            throw new moodle_exception('invalidsection', 'local_custom_service');
        }

        // Ensure module is not already in the section.
        if ($module->section == $section->id) {
            throw new moodle_exception('modulerealread', 'local_custom_service');
        }

        // Get the section to which we want to move the activity
        $section = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $newsection]);

        // Use the existing moveto_module function to move the module to the new section
        $modvisible = moveto_module($module, $section);

        // Rebuild course cache to reflect changes
        rebuild_course_cache($courseid, true);

        return null;
    }


    /**
     * Return description for move_activity_to_section().
     *
     * @return external_description
     */
    public static function move_activity_to_section_returns() {
        return null;
    }

    /**
     * Parameter description for move_activity_before_after().
     *
     * @return external_function_parameters
     */
    public static function move_activity_before_after_parameters() {
        return new external_function_parameters(
            array(
                'courseid' => new external_value(PARAM_INT, 'id of course'),
                'moduleid' => new external_value(PARAM_INT, 'id of the module (activity)'),
                'newsection' => new external_value(PARAM_INT, 'id of the section to move the activity to'),
                'targetmoduleid' => new external_value(PARAM_INT, 'ID of the target module (activity) before or after which the module will be moved'),
            )
        );
    }

    /**
     * Move an activity before or after another activity in the same section.
     *
     * This function moves an activity before or after another activity.
     *
     * @param int $courseid The ID of the course.
     * @param int $moduleid The ID of the module (activity) to move.
     * @param int $targetmoduleid The ID of the target module (activity) before or after which the module will be moved.
     * @param int $section The ID of the section.
     * @return null.
     */
    public static function move_activity_before_after($courseid, $moduleid, $newsection, $targetmoduleid) {
        global $DB;

        // Validate parameters passed from web service.
        $params = self::validate_parameters(self::move_activity_before_after_parameters(), array(
            'courseid' => $courseid,
            'moduleid' => $moduleid,
            'newsection' => $newsection,
            'targetmoduleid' => $targetmoduleid,
        ));

        // Ensure course exists.
        if (!($course = $DB->get_record('course', array('id' => $params['courseid'])))) {
            throw new moodle_exception('invalidcourseid', 'local_custom_service', '', $courseid);
        }

        // Ensure module exists.
        if (!($module = $DB->get_record('course_modules', array('id' => $params['moduleid'])))) {
            throw new moodle_exception('invalidmoduleid', 'local_custom_service', '', $moduleid);
        }

        // Ensure module belongs to the course.
        if ($module->course != $course->id) {
            throw new moodle_exception('modulenotincourse', 'local_custom_service');
        }

        // Check if section exists.
        $section = $DB->get_record('course_sections', array('course' => $courseid, 'section' => $newsection));
        if (!$section) {
            throw new moodle_exception('invalidsection', 'local_custom_service');
        }

        $targetmodule = $DB->get_record('course_modules', array('id' => $params['targetmoduleid']));
        if (!$targetmodule) {
            throw new moodle_exception('invalidtargetmodule', 'local_custom_service');
        }

        // Get the section to which we want to move the activity
        $section = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $newsection]);

        // Use the existing moveto_module function to move the module to the new section
        $modvisible = moveto_module($module, $section, $targetmodule);

        // Rebuild course cache to reflect changes
        rebuild_course_cache($courseid, true);

        return null;
    }

    /**
     * Return description for move_activity_before_after().
     *
     * @return external_description
     */
    public static function move_activity_before_after_returns() {
        return null;
    }

    //update_activity_quiz
    public static function update_activity_quiz_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID của quiz cần cập nhật'),
            'fields' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_TEXT, 'Tên quiz', VALUE_OPTIONAL),
                    'intro' => new external_value(PARAM_RAW, 'Mô tả quiz', VALUE_OPTIONAL),
                    'timelimit' => new external_value(PARAM_INT, 'Thời gian làm bài (giây)', VALUE_OPTIONAL),
                    'timeopen' => new external_value(PARAM_INT, 'Thời gian mở', VALUE_OPTIONAL),
                    'timeclose' => new external_value(PARAM_INT, 'Thời gian đóng', VALUE_OPTIONAL),
                    'attempts' => new external_value(PARAM_INT, 'Số lần làm bài tối đa', VALUE_OPTIONAL),
                    'grade' => new external_value(PARAM_INT, 'Điểm', VALUE_OPTIONAL),
                    'gradepass' => new external_value(PARAM_INT, 'Điểm để qua', VALUE_OPTIONAL),
                    'sumgrades' => new external_value(PARAM_INT, 'Tổng điểm', VALUE_OPTIONAL),
                    'grademethod' => new external_value(PARAM_INT, 'Phương pháp chấm điểm', VALUE_OPTIONAL),
                    'shufflequestions' => new external_value(PARAM_INT, 'Xáo trộn câu hỏi', VALUE_OPTIONAL),
                    'questionsperpage' => new external_value(PARAM_INT, 'Số câu hỏi trên mỗi trang', VALUE_OPTIONAL),
                    'section' => new external_value(PARAM_INT, 'ID của section chứa quiz', VALUE_OPTIONAL),
                    'visible' => new external_value(PARAM_INT, 'Trạng thái hiển thị của quiz (0 = ẩn, 1 = hiện)', VALUE_OPTIONAL),
                    'preferredbehaviour' => new external_value(PARAM_TEXT, 'Hành vi câu hỏi', VALUE_OPTIONAL),
                    'attemptimmediately' => new external_value(PARAM_INT, 'Bài làm', VALUE_OPTIONAL),
                    'correctnessimmediately' => new external_value(PARAM_INT, 'Nếu đúng', VALUE_OPTIONAL),
                    'marksimmediately' => new external_value(PARAM_INT, 'Điểm', VALUE_OPTIONAL),
                    'specificfeedbackimmediately' => new external_value(PARAM_INT, 'Phản hồi cụ thể', VALUE_OPTIONAL),
                    'generalfeedbackimmediately' => new external_value(PARAM_INT, 'Phản hồi chung', VALUE_OPTIONAL),
                    'rightanswerimmediately' => new external_value(PARAM_INT, 'Câu trả lời đúng', VALUE_OPTIONAL),
                    'overallfeedbackimmediately' => new external_value(PARAM_INT, 'Phản hồi chung', VALUE_OPTIONAL),
                    'completion' => new external_value(PARAM_INT, 'Completion tracking', VALUE_OPTIONAL),
                    'completionview' => new external_value(PARAM_INT, 'Require view', VALUE_OPTIONAL),
                    'completionexpected' => new external_value(PARAM_INT, 'Expect completed on', VALUE_OPTIONAL),
                    'completionpassgrade' => new external_value(PARAM_INT, 'Grade Pass', VALUE_OPTIONAL),
                    'completionminattempts' => new external_value(PARAM_INT, 'Min Attempts', VALUE_OPTIONAL),
                    'showdescription' => new external_value(PARAM_INT, 'Show Description', VALUE_OPTIONAL),
                    // Restrict access parameters
                    'availability' => new external_single_structure([
                        'timeopen' => new external_value(PARAM_INT, 'Thời gian mở quiz (timestamp)', VALUE_OPTIONAL),
                        'timeclose' => new external_value(PARAM_INT, 'Thời gian đóng quiz (timestamp)', VALUE_OPTIONAL),
                        'gradeitemid' => new external_value(PARAM_INT, 'ID của mục điểm (grade item)', VALUE_OPTIONAL),
                        'min' => new external_value(PARAM_FLOAT, 'Điểm tối thiểu', VALUE_OPTIONAL),
                        'max' => new external_value(PARAM_FLOAT, 'Điểm tối đa', VALUE_OPTIONAL),
                        'completioncmid' => new external_multiple_structure(
                            new external_value(PARAM_INT, 'ID của activity cần hoàn thành'),
                            'Danh sách ID của các activity cần hoàn thành',
                            VALUE_OPTIONAL
                        )
                    ], 'Restrict access settings', VALUE_OPTIONAL)
                ]),
                'Danh sách các trường cần cập nhật',
                VALUE_DEFAULT,
                []
            )
        ]);
    }
    

    /**
     * Function to create a quiz activity in a course.
     */
    public static function update_activity_quiz($cmid, $fields) {
        global $DB;
    
        // Validate parameters
        $params = self::validate_parameters(self::update_activity_quiz_parameters(), [
            'cmid' => $cmid,
            'fields' => $fields
        ]);
    
        // Retrieve course module and course details
        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    
        // Check if quiz exists
        if (!$DB->record_exists('quiz', ['id' => $cm->instance])) {
            throw new moodle_exception('invalidquizid', 'mod_quiz', '', $cm->instance);
        }
    
        // Get quiz record
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
    
        // Update quiz fields
        foreach ($params['fields'] as $field_data) {
            foreach ($field_data as $field => $value) {
                if ($field !== 'availability' && property_exists($quiz, $field)) {
                    $quiz->{$field} = $value;
                }
            }
        }
    
        // Update review settings
        $attemptimmediately = 65536;
        $correctnessimmediately = 0;
        $marksimmediately = 0;
        $specificfeedbackimmediately = 0;
        $generalfeedbackimmediately = 0;
        $rightanswerimmediately = 0;
        $overallfeedbackimmediately = 0;
        $completionminattempts = 0;

        if(!empty($params['fields'][0]['completionminattempts'])){
            $completionminattempts = $params['fields'][0]['completionminattempts'];
        }
        if(!empty($params['fields'][0]['attemptimmediately'])){
            $attemptimmediately |= QUIZ_REVIEW_IMMEDIATELY_AFTER_ATTEMPT;
        }
        if(!empty($params['fields'][0]['correctnessimmediately'])){
            $correctnessimmediately |= QUIZ_REVIEW_IMMEDIATELY_WHETHER_CORRECT;
        }
        if(!empty($params['fields'][0]['marksimmediately'])){
            $marksimmediately |= QUIZ_REVIEW_IMMEDIATELY_MARKS;
        }
        if(!empty($params['fields'][0]['specificfeedbackimmediately'])){
            $specificfeedbackimmediately |= QUIZ_REVIEW_IMMEDIATELY_SPECIFIC_FEEDBACK;
        }
        if(!empty($params['fields'][0]['generalfeedbackimmediately'])){
            $generalfeedbackimmediately |= QUIZ_REVIEW_IMMEDIATELY_GENERAL_FEEDBACK;
        }
        if(!empty($params['fields'][0]['rightanswerimmediately'])){
            $rightanswerimmediately |= QUIZ_REVIEW_IMMEDIATELY_RIGHT_ANSWER;
        }
        if(!empty($params['fields'][0]['overallfeedbackimmediately'])){
            $overallfeedbackimmediately |= QUIZ_REVIEW_IMMEDIATELY_OVERALL_FEEDBACK;
        }

        $quiz->reviewattempt = $attemptimmediately;
        $quiz->reviewcorrectness = $correctnessimmediately;
        $quiz->reviewgeneralfeedback = $generalfeedbackimmediately;
        $quiz->reviewmarks = $marksimmediately;
        $quiz->reviewmaxmarks = $marksimmediately;
        $quiz->reviewoverallfeedback = $overallfeedbackimmediately;
        $quiz->reviewrightanswer = $rightanswerimmediately;
        $quiz->reviewspecificfeedback = $specificfeedbackimmediately;
        $quiz->completionminattempts = $completionminattempts;
        
        $DB->update_record('quiz', $quiz);
    
        // Update availability if provided
        if (!empty($params['fields'][0]['availability'])) {
            $availability_params = $params['fields'][0]['availability'];
            $completioncmids = $availability_params['completioncmid'] ?? [];
    
            if (!is_array($completioncmids)) {
                $completioncmids = [$completioncmids];
            }
    
            $availability_json = self::generate_availability_conditions(
                $availability_params['timeopen'] ?? null,
                $availability_params['timeclose'] ?? null,
                $availability_params['gradeitemid'] ?? null,
                $availability_params['min'] ?? null,
                $availability_params['max'] ?? null,
                $completioncmids
            );
    
            $cm->availability = $availability_json;
            $DB->update_record('course_modules', $cm);
        }else{
            $cm->availability = '';
            $DB->update_record('course_modules', $cm);
        }
    
        // Update section and visibility
        if (!empty($params['fields'][0]['section'])) {
            $section = $DB->get_record('course_sections', [
                'course' => $cm->course, 
                'section' => $params['fields'][0]['section']
            ], '*', MUST_EXIST);
    
            if ($section->id != $cm->section) {
                self::move_activity_to_section($cm->course, $cmid, $params['fields'][0]['section']);
            }
        }
    
        if (isset($params['fields'][0]['visible'])) {
            $cm->visible = $params['fields'][0]['visible'];
        }
    
        $completion = 0;
        $completionview = 0;
        $completionpassgrade = 0;
        $completiongradeitemnumber = Null;
        

        if(!empty($params['fields'][0]['completion'])){
            $completion = $params['fields'][0]['completion'] ?? 0;
            $completionview = $params['fields'][0]['completionview'] ?? 0;
            $completionpassgrade = $params['fields'][0]['completionpassgrade'] ?? 0;
            if(!empty($params['fields'][0]['completionpassgrade'])){
                $completiongradeitemnumber = 0;
            }
        }
        // Update completion settings
        $cm->completion = $completion;
        $cm->completionview = $completionview;
        $cm->completionexpected = $params['fields'][0]['completionexpected'] ?? 0;
        $cm->completionpassgrade = $completionpassgrade;
        $cm->completiongradeitemnumber = $completiongradeitemnumber;

        $cm->showdescription = $params['fields'][0]['showdescription'] ?? 0;
        
        $DB->update_record('course_modules', $cm);

        $grade_item = $DB->get_record('grade_items', [
            'itemmodule' => 'quiz',
            'iteminstance' => $quiz->id
        ]);

        if ($grade_item) {
            $grade_item->gradepass = $params['fields'][0]['gradepass']; // Giá trị gradepass cần cập nhật
            $DB->update_record('grade_items', $grade_item);
        } else {
            throw new moodle_exception('invalidgradeitem', 'error', '', $quiz->id);
        }
    
        // Rebuild course cache
        rebuild_course_cache($cm->course, true);
    
        return [
            'status' => 'success',
            'message' => 'Quiz updated successfully',
            'quizid' => $quiz->id,
            'cmid' => $cmid
        ];
    }

    public static function get_moduleid_from_cmid($cmid, $modulename) {
        global $DB;
    
        // Truy vấn để lấy quizid từ cmid
        $sql = "SELECT m.name AS modulename, cm.instance AS moduleid
            FROM {course_modules} cm
            JOIN {modules} m ON m.id = cm.module
            WHERE cm.id = :cmid AND m.name = :modulename";

        $module = $DB->get_record_sql($sql, [
            'cmid' => $cmid,
            'modulename' => $modulename
        ], MUST_EXIST);

        return $module->moduleid;
    }

    /**
     * Return description for update_activity_quiz().
     *
     * @return external_single_structure.
     */
    public static function update_activity_quiz_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Kết quả của thao tác'),
            'message' => new external_value(PARAM_TEXT, 'Thông báo kết quả'),
            'quizid' => new external_value(PARAM_INT, 'ID của quiz đã cập nhật'),
            'cmid' => new external_value(PARAM_INT, 'Course module ID của quiz')
        ]);
    }


    //update_activity_url
    public static function update_activity_url_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID của url cần cập nhật'),
            'fields' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_TEXT, 'Tên url', VALUE_OPTIONAL),
                    'intro' => new external_value(PARAM_RAW, 'Mô tả url', VALUE_OPTIONAL),
                    'introformat' => new external_value(PARAM_INT, 'introformat', VALUE_OPTIONAL),
                    'externalurl' => new external_value(PARAM_TEXT, 'externalurl', VALUE_OPTIONAL),
                    'display' => new external_value(PARAM_INT, 'display', VALUE_OPTIONAL),
                    'section' => new external_value(PARAM_INT, 'ID của section chứa quiz', VALUE_OPTIONAL),
                    'visible' => new external_value(PARAM_INT, 'Trạng thái hiển thị của quiz (0 = ẩn, 1 = hiện)', VALUE_OPTIONAL),
                    'completion' => new external_value(PARAM_INT, 'Completion tracking', VALUE_OPTIONAL),
                    'completionview' => new external_value(PARAM_INT, 'Require view', VALUE_OPTIONAL),
                    'completionexpected' => new external_value(PARAM_INT, 'Expect completed on', VALUE_OPTIONAL),
                    'showdescription' => new external_value(PARAM_INT, 'Show Description', VALUE_OPTIONAL),
                    // Restrict access parameters
                    'availability' => new external_single_structure([
                        'timeopen' => new external_value(PARAM_INT, 'Thời gian mở quiz (timestamp)', VALUE_OPTIONAL),
                        'timeclose' => new external_value(PARAM_INT, 'Thời gian đóng quiz (timestamp)', VALUE_OPTIONAL),
                        'gradeitemid' => new external_value(PARAM_INT, 'ID của mục điểm (grade item)', VALUE_OPTIONAL),
                        'min' => new external_value(PARAM_FLOAT, 'Điểm tối thiểu', VALUE_OPTIONAL),
                        'max' => new external_value(PARAM_FLOAT, 'Điểm tối đa', VALUE_OPTIONAL),
                        'completioncmid' => new external_multiple_structure(
                            new external_value(PARAM_INT, 'ID của activity cần hoàn thành'),
                            'Danh sách ID của các activity cần hoàn thành',
                            VALUE_OPTIONAL
                        )
                    ], 'Restrict access settings', VALUE_OPTIONAL)
                ]),
                'Danh sách các trường cần cập nhật',
                VALUE_DEFAULT,
                []
            )
        ]);
    }
    

    /**
     * Function to create a quiz activity in a course.
     */
    public static function update_activity_url($cmid, $fields) {
        global $DB;
    
        // Validate parameters
        $params = self::validate_parameters(self::update_activity_url_parameters(), [
            'cmid' => $cmid,
            'fields' => $fields
        ]);
        
        // Lấy urlid từ cmid
        $urlid = self::get_moduleid_from_cmid($cmid, 'url');
        
        // Kiểm tra url có tồn tại không
        if (!$DB->record_exists('url', ['id' => $urlid])) {
            throw new moodle_exception('invalidurlid', 'mod_url', '', $urlid);
        }
    
        // Lấy thông tin url hiện tại
        $url = $DB->get_record('url', ['id' => $urlid], '*', MUST_EXIST);
    
        // Cập nhật các trường được cung cấp
        foreach ($params['fields'] as $field_data) {
            foreach ($field_data as $field => $value) {
                if (isset($value) && $field !== 'availability' && property_exists($url, $field)) {
                    $url->{$field} = $value;
                }
            }
        }
        // Cập nhật url
        $result = $DB->update_record('url', $url);
        // Xử lý restrict access (availability)
        if (!empty($params['fields'][0]['availability'])) {
            $availability_params = $params['fields'][0]['availability'];
            $completioncmids = $availability_params['completioncmid'] ?? [];

            if (!is_array($completioncmids)) {
                $completioncmids = [$completioncmids];
            }
            $availability_json = self::generate_availability_conditions(
                $availability_params['timeopen'] ?? null,
                $availability_params['timeclose'] ?? null,
                $availability_params['gradeitemid'] ?? null,
                $availability_params['min'] ?? null,
                $availability_params['max'] ?? null,
                $completioncmids
            );
            // var_dump($availability_json, $completioncmids);die;
            // Cập nhật availability trong bảng course_modules
            $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
            $cm->availability = $availability_json;
        
            // Cập nhật lại course_modules
            $DB->update_record('course_modules', $cm);
        }
        
        // Xử lý section và visible nếu được truyền
        $cm1 = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);

        $section = $DB->get_record('course_sections', array('course' => $cm1->course, 'section' => $params['fields'][0]['section']));
        // $old_section_id = $cm1->section;
        // $new_section_id = $params['fields'][0]['section'] ?? null;
        // var_dump($section->id, $cm1->section);die;
        if (isset($params['fields'][0]['section']) && $section->id != $cm1->section) {
            // Cập nhật section mới
            self::move_activity_to_section($cm1->course, $cmid, $params['fields'][0]['section']);
        }

        if (isset($params['fields'][0]['visible'])) {
            $cm1->visible = $params['fields'][0]['visible'];
        }

        $completion = 0;
        $completionview = 0;
        $completionexpected = 0;
        if (!empty($params['fields'][0]['completion'])) {
            if($params['fields'][0]['completion'] == 1){
                $completion = $params['fields'][0]['completion'];
                $completionexpected = $params['fields'][0]['completionexpected'] ?? 0;
            }

            if($params['fields'][0]['completion'] == 2){
                $completion = $params['fields'][0]['completion'];
                $completionview = $params['fields'][0]['completionview'] ?? 0;
                $completionexpected = $params['fields'][0]['completionexpected'] ?? 0;
            }
        }
        $cm1->completion = $completion;
        $cm1->completionview = $completionview;
        $cm1->completionexpected = $completionexpected;

        $cm1->showdescription = $params['fields'][0]['showdescription'] ?? 0;
        // var_dump($cm1);die;
        $DB->update_record('course_modules', $cm1);

        rebuild_course_cache($cm1->course, true);
    
        return [
            'status' => 'success',
            'message' => 'Url updated successfully',
            'urlid' => $urlid,
            'cmid' => $cmid
        ];
    }

    /**
     * Return description for update_activity_url().
     *
     * @return external_single_structure.
     */
    public static function update_activity_url_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Kết quả của thao tác'),
            'message' => new external_value(PARAM_TEXT, 'Thông báo kết quả'),
            'urlid' => new external_value(PARAM_INT, 'ID của url đã cập nhật'),
            'cmid' => new external_value(PARAM_INT, 'Course module ID của url')
        ]);
    }

    //get detail module
    public static function get_detail_module_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID của quiz cần cập nhật'),
            'modulename' => new external_value(PARAM_TEXT, 'Tên module'),
        ]);
    }
    

    /**
     * Function to create a quiz activity in a course.
     */
    public static function get_detail_module($cmid, $modulename) {
        global $DB;
    
        // Validate parameters
        $params = self::validate_parameters(self::get_detail_module_parameters(), [
            'cmid' => $cmid,
            'modulename' => $modulename
        ]);
    
        // Retrieve course module and course details
        $cm = get_coursemodule_from_id('', $params['cmid'], 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    
        // Check if quiz exists
        if (!$DB->record_exists($params['modulename'], ['id' => $cm->instance])) {
            throw new moodle_exception('invalidmoduleid', 'error', '', $cm->instance);
        }
    
        // Get quiz record
        $module = $DB->get_record($params['modulename'], ['id' => $cm->instance], '*', MUST_EXIST);
    
        $plugin_config = [];
        if ($params['modulename'] === 'assign') {
            // Retrieve all plugin configuration for the assignment
            $plugin_config = $DB->get_records('assign_plugin_config', ['assignment' => $cm->instance]);
        }

        // Nếu là resource thì lấy file URLs
        $file_urls = [];
        if ($params['modulename'] === 'resource') {
            $context = context_module::instance($cm->id);
            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'itemid, filepath, filename', false);

            foreach ($files as $file) {
                $file_urls[] = moodle_url::make_pluginfile_url(
                    $file->get_contextid(),
                    $file->get_component(),
                    $file->get_filearea(),
                    $file->get_itemid(),
                    $file->get_filepath(),
                    $file->get_filename()
                )->out(false);
            }
        }

        return [
            'status' => 'success',
            'data' => json_encode($module),
            'plugin_config' => $plugin_config,
            'file_urls' => $file_urls
        ];
    }

    /**
     * Return description for get_detail_module().
     *
     * @return external_single_structure.
     */
    public static function get_detail_module_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Kết quả của thao tác'),
            'data' => new external_value(PARAM_RAW, 'Dữ liệu chi tiết của module (có thể thay đổi tùy module)'),
            'plugin_config' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'ID của cấu hình plugin'),
                    'assignment' => new external_value(PARAM_INT, 'ID của assignment'),
                    'plugin' => new external_value(PARAM_TEXT, 'Tên plugin'),
                    'subtype' => new external_value(PARAM_TEXT, 'Loại plugin'),
                    'name' => new external_value(PARAM_TEXT, 'Tên cấu hình'),
                    'value' => new external_value(PARAM_RAW, 'Giá trị cấu hình')
                ])
            ),
            'file_urls' => new external_multiple_structure(
                new external_value(PARAM_URL, 'URL của file đã upload'),
                'Danh sách URL file',
                VALUE_DEFAULT,
                []
            )
        ]);
    }

    // Functionset for add_file_resource() ******************************************************************************************.

    public static function add_file_resource_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'ID của activity'),
            'component' => new external_value(PARAM_TEXT, 'component ex: mod_assign, mod_resource'),
            'filearea' => new external_value(PARAM_TEXT, 'filearea ex: introattachment(mod_assign), content(mod_resource)'),
            'files' => new external_multiple_structure( // Nhận danh sách file
                new external_single_structure([
                    'filecontent' => new external_value(PARAM_RAW, 'Nội dung file dưới dạng base64'),
                    'filename' => new external_value(PARAM_FILE, 'Tên của file')
                ])
            )
        ]);
    }
    

    public static function add_file_resource($cmid, $component, $filearea, $files) {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::add_file_resource_parameters(), [
            'cmid' => $cmid,
            'component' => $component,
            'filearea' => $filearea,
            'files' => $files
        ]);

        // Check if the activity exists.
        if (!$cm = $DB->get_record('course_modules', ['id' => $cmid])) {
            throw new moodle_exception('invalidcoursemodule', 'error', '', $cmid);
        }

        // Get the module context.
        $context = context_module::instance($cmid);

        // Get the file_storage instance.
        $fs = get_file_storage();

        $fs->delete_area_files($context->id, $component, $filearea, 0);

        // Array to store uploaded files' information.
        $uploadedFiles = [];

        // Loop through the files to process each file.
        foreach ($files as $file) {
            $decodedcontent = base64_decode($file['filecontent']);
            if ($decodedcontent === false) {
                throw new moodle_exception('invalidfilecontent', 'error');
            }

            // Check if the file already exists in the content area.
            $existingFile = $fs->get_file(
                $context->id, 
                $component, 
                $filearea, 
                0, 
                '/', 
                $file['filename']
            );

            if ($existingFile) {
                // Skip if the file already exists.
                continue;
            }

            // Create file in the content area.
            $finalFileInfo = [
                'contextid' => $context->id,
                'component' => $component,
                'filearea' => $filearea,
                'itemid' => 0,
                'filepath' => '/',
                'filename' => $file['filename']
            ];

            $finalFile = $fs->create_file_from_string($finalFileInfo, $decodedcontent);
            if (!$finalFile) {
                throw new moodle_exception('fileuploadfinalfailed', 'error');
            }

            // Save the uploaded file information.
            $uploadedFiles[] = [
                'filename' => $finalFile->get_filename(),
                'filepath' => $finalFile->get_filepath(),
                'url' => moodle_url::make_pluginfile_url(
                    $finalFile->get_contextid(),
                    $finalFile->get_component(),
                    $finalFile->get_filearea(),
                    $finalFile->get_itemid(),
                    $finalFile->get_filepath(),
                    $finalFile->get_filename()
                )->out()
            ];
        }

        // Return the upload result.
        return [
            'status' => true,
            'message' => count($uploadedFiles) . ' files uploaded successfully',
            'files' => $uploadedFiles
        ];
    }

    public static function add_file_resource_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Trạng thái upload thành công hay không'),
            'message' => new external_value(PARAM_TEXT, 'Thông báo kết quả'),
            'files' => new external_multiple_structure( // Danh sách file đã upload
                new external_single_structure([
                    'filename' => new external_value(PARAM_FILE, 'Tên file đã upload'),
                    'filepath' => new external_value(PARAM_PATH, 'Đường dẫn file trong Moodle'),
                    'url' => new external_value(PARAM_URL, 'URL truy cập file đã upload')
                ])
            )
        ]);
    }

    public static function update_activity_resource_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID của url cần cập nhật'),
            'fields' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_TEXT, 'Tên url', VALUE_OPTIONAL),
                    'intro' => new external_value(PARAM_RAW, 'Mô tả url', VALUE_OPTIONAL),
                    'introformat' => new external_value(PARAM_INT, 'introformat', VALUE_OPTIONAL),
                    'display' => new external_value(PARAM_INT, 'display', VALUE_OPTIONAL),
                    'section' => new external_value(PARAM_INT, 'ID của section chứa quiz', VALUE_OPTIONAL),
                    'visible' => new external_value(PARAM_INT, 'Trạng thái hiển thị của quiz (0 = ẩn, 1 = hiện)', VALUE_OPTIONAL),
                    'completion' => new external_value(PARAM_INT, 'Completion tracking', VALUE_OPTIONAL),
                    'completionview' => new external_value(PARAM_INT, 'Require view', VALUE_OPTIONAL),
                    'completionexpected' => new external_value(PARAM_INT, 'Expect completed on', VALUE_OPTIONAL),
                    'showdescription' => new external_value(PARAM_INT, 'Show Description', VALUE_OPTIONAL),
                    // Restrict access parameters
                    'availability' => new external_single_structure([
                        'timeopen' => new external_value(PARAM_INT, 'Thời gian mở quiz (timestamp)', VALUE_OPTIONAL),
                        'timeclose' => new external_value(PARAM_INT, 'Thời gian đóng quiz (timestamp)', VALUE_OPTIONAL),
                        'gradeitemid' => new external_value(PARAM_INT, 'ID của mục điểm (grade item)', VALUE_OPTIONAL),
                        'min' => new external_value(PARAM_FLOAT, 'Điểm tối thiểu', VALUE_OPTIONAL),
                        'max' => new external_value(PARAM_FLOAT, 'Điểm tối đa', VALUE_OPTIONAL),
                        'completioncmid' => new external_multiple_structure(
                            new external_value(PARAM_INT, 'ID của activity cần hoàn thành'),
                            'Danh sách ID của các activity cần hoàn thành',
                            VALUE_OPTIONAL
                        )
                    ], 'Restrict access settings', VALUE_OPTIONAL)
                ]),
                'Danh sách các trường cần cập nhật',
                VALUE_DEFAULT,
                []
            )
        ]);
    }
    

    /**
     * Function to create a quiz activity in a course.
     */
    public static function update_activity_resource($cmid, $fields) {
        global $DB;
    
        // Validate parameters
        $params = self::validate_parameters(self::update_activity_resource_parameters(), [
            'cmid' => $cmid,
            'fields' => $fields
        ]);
        
        // Lấy resourceid từ cmid
        $resourceid = self::get_moduleid_from_cmid($cmid, 'resource');
        
        // Kiểm tra resource có tồn tại không
        if (!$DB->record_exists('resource', ['id' => $resourceid])) {
            throw new moodle_exception('invalidresourceid', 'mod_resource', '', $resourceid);
        }
    
        // Lấy thông tin resource hiện tại
        $resource = $DB->get_record('resource', ['id' => $resourceid], '*', MUST_EXIST);
    
        // Cập nhật các trường được cung cấp
        foreach ($params['fields'] as $field_data) {
            foreach ($field_data as $field => $value) {
                if (isset($value) && $field !== 'availability' && property_exists($resource, $field)) {
                    $resource->{$field} = $value;
                }
            }
        }
        // Cập nhật resource
        $result = $DB->update_record('resource', $resource);
        // Xử lý restrict access (availability)
        if (!empty($params['fields'][0]['availability'])) {
            $availability_params = $params['fields'][0]['availability'];
            $completioncmids = $availability_params['completioncmid'] ?? [];

            if (!is_array($completioncmids)) {
                $completioncmids = [$completioncmids];
            }
            $availability_json = self::generate_availability_conditions(
                $availability_params['timeopen'] ?? null,
                $availability_params['timeclose'] ?? null,
                $availability_params['gradeitemid'] ?? null,
                $availability_params['min'] ?? null,
                $availability_params['max'] ?? null,
                $completioncmids
            );
            // var_dump($availability_json, $completioncmids);die;
            // Cập nhật availability trong bảng course_modules
            $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
            $cm->availability = $availability_json;
        
            // Cập nhật lại course_modules
            $DB->update_record('course_modules', $cm);
        }
        
        // Xử lý section và visible nếu được truyền
        $cm1 = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);

        $section = $DB->get_record('course_sections', array('course' => $cm1->course, 'section' => $params['fields'][0]['section']));
        // $old_section_id = $cm1->section;
        // $new_section_id = $params['fields'][0]['section'] ?? null;
        // var_dump($section->id, $cm1->section);die;
        if (isset($params['fields'][0]['section']) && $section->id != $cm1->section) {
            // Cập nhật section mới
            self::move_activity_to_section($cm1->course, $cmid, $params['fields'][0]['section']);
        }

        if (isset($params['fields'][0]['visible'])) {
            $cm1->visible = $params['fields'][0]['visible'];
        }

        $completion = 0;
        $completionview = 0;
        $completionexpected = 0;
        if (!empty($params['fields'][0]['completion'])) {
            if($params['fields'][0]['completion'] == 1){
                $completion = $params['fields'][0]['completion'];
                $completionexpected = $params['fields'][0]['completionexpected'] ?? 0;
            }

            if($params['fields'][0]['completion'] == 2){
                $completion = $params['fields'][0]['completion'];
                $completionview = $params['fields'][0]['completionview'] ?? 0;
                $completionexpected = $params['fields'][0]['completionexpected'] ?? 0;
            }
        }
        $cm1->completion = $completion;
        $cm1->completionview = $completionview;
        $cm1->completionexpected = $completionexpected;

        $cm1->showdescription = $params['fields'][0]['showdescription'] ?? 0;
        // var_dump($cm1);die;
        $DB->update_record('course_modules', $cm1);

        rebuild_course_cache($cm1->course, true);
    
        return [
            'status' => 'success',
            'message' => 'resource updated successfully',
            'resourceid' => $resourceid,
            'cmid' => $cmid
        ];
    }

    /**
     * Return description for update_activity_resource().
     *
     * @return external_single_structure.
     */
    public static function update_activity_resource_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Kết quả của thao tác'),
            'message' => new external_value(PARAM_TEXT, 'Thông báo kết quả'),
            'resourceid' => new external_value(PARAM_INT, 'ID của resource đã cập nhật'),
            'cmid' => new external_value(PARAM_INT, 'Course module ID của resource')
        ]);
    }

    public static function update_activity_assign_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID của url cần cập nhật'),
            'fields' => new external_multiple_structure(
                new external_single_structure([
                    'courseid' => new external_value(PARAM_INT, 'courseid', VALUE_OPTIONAL),
                    'name' => new external_value(PARAM_TEXT, 'Tên url', VALUE_OPTIONAL),
                    'intro' => new external_value(PARAM_RAW, 'Mô tả url', VALUE_OPTIONAL),
                    'introformat' => new external_value(PARAM_INT, 'introformat', VALUE_OPTIONAL),
                    'display' => new external_value(PARAM_INT, 'display', VALUE_OPTIONAL),
                    'section' => new external_value(PARAM_INT, 'ID của section chứa quiz', VALUE_OPTIONAL),
                    'visible' => new external_value(PARAM_INT, 'Trạng thái hiển thị của quiz (0 = ẩn, 1 = hiện)', VALUE_OPTIONAL),
                    'grade' => new external_value(PARAM_INT, 'Điểm', VALUE_OPTIONAL),
                    'gradepass' => new external_value(PARAM_INT, 'Điểm để qua', VALUE_OPTIONAL),
                    'completion' => new external_value(PARAM_INT, 'Completion tracking', VALUE_OPTIONAL),
                    'completionview' => new external_value(PARAM_INT, 'Require view', VALUE_OPTIONAL),
                    'completionsubmit' => new external_value(PARAM_INT, 'Require submit', VALUE_OPTIONAL),
                    'completionexpected' => new external_value(PARAM_INT, 'Expect completed on', VALUE_OPTIONAL),
                    'completionpassgrade' => new external_value(PARAM_INT, 'Require grade pass', VALUE_OPTIONAL),
                    'showdescription' => new external_value(PARAM_INT, 'Show Description', VALUE_OPTIONAL),
                    'assignsubmissiononlinetextenabled' => new external_value(PARAM_INT, 'Submission Type Online', VALUE_OPTIONAL),
                    'assignsubmissionfileenabled' => new external_value(PARAM_INT, 'Submission Type File', VALUE_OPTIONAL),
                    'assignsubmissionfilefiletypes' => new external_value(PARAM_TEXT, 'Submission File Type List', VALUE_OPTIONAL),
                    // Restrict access parameters
                    'availability' => new external_single_structure([
                        'timeopen' => new external_value(PARAM_INT, 'Thời gian mở quiz (timestamp)', VALUE_OPTIONAL),
                        'timeclose' => new external_value(PARAM_INT, 'Thời gian đóng quiz (timestamp)', VALUE_OPTIONAL),
                        'gradeitemid' => new external_value(PARAM_INT, 'ID của mục điểm (grade item)', VALUE_OPTIONAL),
                        'min' => new external_value(PARAM_FLOAT, 'Điểm tối thiểu', VALUE_OPTIONAL),
                        'max' => new external_value(PARAM_FLOAT, 'Điểm tối đa', VALUE_OPTIONAL),
                        'completioncmid' => new external_multiple_structure(
                            new external_value(PARAM_INT, 'ID của activity cần hoàn thành'),
                            'Danh sách ID của các activity cần hoàn thành',
                            VALUE_OPTIONAL
                        )
                    ], 'Restrict access settings', VALUE_OPTIONAL)
                ]),
                'Danh sách các trường cần cập nhật',
                VALUE_DEFAULT,
                []
            )
        ]);
    }
    

    /**
     * Function to create a quiz activity in a course.
     */
    public static function update_activity_assign($cmid, $fields) {
        global $DB;

        // var_dump($cmid, $fields);die;
        // Validate parameters
        $params = self::validate_parameters(self::update_activity_assign_parameters(), [
            'cmid' => $cmid,
            'fields' => $fields
        ]);

        $course = $DB->get_record('course', ['id' => $params['fields'][0]['courseid']], '*', MUST_EXIST);
        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        
        // Check user capabilities.
        $context = context_module::instance($cm->id);
        require_login($course);
        if (!has_capability('moodle/course:manageactivities', $context)) {
            throw new moodle_exception('nopermissions', 'error', '', 'manage activities');
        }

        $assignid = self::get_moduleid_from_cmid($cmid, 'assign');
        
        // Kiểm tra assign có tồn tại không
        if (!$DB->record_exists('assign', ['id' => $assignid])) {
            throw new moodle_exception('invalidassignid', 'mod_assign', '', $assignid);
        }
    
        // Lấy thông tin assign hiện tại
        $assign = $DB->get_record('assign', ['id' => $assignid], '*', MUST_EXIST);

        $moduleinfo = new stdClass();

        $completion = 0;
        $completionview = 0;
        $completionpassgrade = 0;
        $completiongradeitemnumber = Null;
        $completionsubmit = 0;
        if(!empty($params['fields'][0]['completion'])){
            $completion = $params['fields'][0]['completion'] ?? 0;
            $completionview = $params['fields'][0]['completionview'] ?? 0;
            $completionpassgrade = $params['fields'][0]['completionpassgrade'] ?? 0;
            $completionsubmit = $params['fields'][0]['completionsubmit'] ?? 0;
            if(!empty($params['fields'][0]['completionpassgrade'])){
                $completiongradeitemnumber = 0;
            }
        }

        $moduleinfo->id = $cm->instance;
        $moduleinfo->modulename = 'assign';
        $moduleinfo->coursemodule = $cmid;
        $moduleinfo->section = $params['fields'][0]['section'];
        $moduleinfo->name = $params['fields'][0]['name'];
        if (plugin_supports('mod', 'assign', FEATURE_MOD_INTRO, true)) {
            $editor = 'introeditor';
            $draftid = file_get_unused_draft_itemid();
            $moduleinfo->$editor = array(
                'text' => $params['fields'][0]['intro'],
                'format' => FORMAT_HTML,
                'itemid' => $draftid
            );
        } else {
            $moduleinfo->intro = $params['fields'][0]['intro'];
            $moduleinfo->introformat = FORMAT_HTML;
        }
        $moduleinfo->display = $params['fields'][0]['display'];
        $moduleinfo->alwaysshowdescription = $assign->alwaysshowdescription;
        $moduleinfo->nosubmissions = $assign->nosubmissions;
        $moduleinfo->submissiondrafts = $assign->submissiondrafts;
        $moduleinfo->sendnotifications = $assign->sendnotifications;
        $moduleinfo->sendlatenotifications = $assign->sendlatenotifications;
        $moduleinfo->duedate = $assign->duedate;
        $moduleinfo->allowsubmissionsfromdate = $assign->allowsubmissionsfromdate;
        $moduleinfo->grade = $params['fields'][0]['grade'];
        // $moduleinfo->timemodified = 1732264957;
        $moduleinfo->requiresubmissionstatement = $assign->requiresubmissionstatement;
        $moduleinfo->completionsubmit = $completionsubmit;
        $moduleinfo->cutoffdate = $assign->cutoffdate;
        $moduleinfo->gradingduedate = $assign->gradingduedate;
        $moduleinfo->teamsubmission = $assign->teamsubmission;
        $moduleinfo->requireallteammemberssubmit = $assign->requireallteammemberssubmit;
        $moduleinfo->teamsubmissiongroupingid = $assign->teamsubmissiongroupingid;
        $moduleinfo->blindmarking = $assign->blindmarking;
        $moduleinfo->hidegrader = $assign->hidegrader;
        $moduleinfo->revealidentities = $assign->revealidentities;
        $moduleinfo->attemptreopenmethod = $assign->attemptreopenmethod;
        $moduleinfo->maxattempts = $assign->maxattempts;
        $moduleinfo->markingworkflow = $assign->markingworkflow;
        $moduleinfo->markingallocation = $assign->markingallocation;
        $moduleinfo->sendstudentnotifications = $assign->sendstudentnotifications;
        $moduleinfo->preventsubmissionnotingroup = $assign->preventsubmissionnotingroup;
        $moduleinfo->activity = '';
        $moduleinfo->activityformat = 1;
        $moduleinfo->timelimit = 0;
        $moduleinfo->submissionattachments = 0;
        $moduleinfo->assignsubmission_onlinetext_enabled = $params['fields'][0]['assignsubmissiononlinetextenabled']; //Online text
        $moduleinfo->assignsubmission_file_enabled = $params['fields'][0]['assignsubmissionfileenabled']; //File submissions
        $moduleinfo->assignsubmission_file_filetypes = $params['fields'][0]['assignsubmissionfilefiletypes'];
        $moduleinfo->gradepass = $params['fields'][0]['gradepass'];
        $moduleinfo->visible = $params['fields'][0]['visible'];
        if (!empty($params['fields'][0]['availability'])) {
            $availability_params = $params['fields'][0]['availability'];
            $completioncmids = $availability_params['completioncmid'] ?? [];

            if (!is_array($completioncmids)) {
                $completioncmids = [$completioncmids];
            }
            $availability_json = self::generate_availability_conditions(
                $availability_params['timeopen'] ?? null,
                $availability_params['timeclose'] ?? null,
                $availability_params['gradeitemid'] ?? null,
                $availability_params['min'] ?? null,
                $availability_params['max'] ?? null,
                $completioncmids
            );
            $moduleinfo->availability = $availability_json;
        }

        // Update completion settings
        $cm->completion = $completion;
        $cm->completionview = $completionview;
        // $cm->completionexpected = 0;
        $cm->completionpassgrade = $completionpassgrade;
        $cm->completiongradeitemnumber = $completiongradeitemnumber;
        // $cm->showdescription = 1;

        $DB->update_record('course_modules', $cm);

        $DB->update_record('assign', $moduleinfo);
        // Call update_moduleinfo to update the activity.
        $update_moduleinfo = update_moduleinfo($cm, $moduleinfo, $course);
    
        return [
            'status' => 'success',
            'message' => 'assign updated successfully',
            'assignid' => $assignid,
            'cmid' => $cmid
        ];
    }

    /**
     * Return description for update_activity_assign().
     *
     * @return external_single_structure.
     */
    public static function update_activity_assign_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Kết quả của thao tác'),
            'message' => new external_value(PARAM_TEXT, 'Thông báo kết quả'),
            'assignid' => new external_value(PARAM_INT, 'ID của assign đã cập nhật'),
            'cmid' => new external_value(PARAM_INT, 'Course module ID của resource')
        ]);
    }

    public static function get_uploaded_files_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'ID của activity cần lấy danh sách file'),
            'component' => new external_value(PARAM_TEXT, 'Component (module) cần lấy file, ví dụ: mod_resource, mod_assign'),
            'filearea' => new external_value(PARAM_TEXT, 'File area cần lấy file, ví dụ: content, introattachment')
        ]);
    }
    
    public static function get_uploaded_files($cmid, $component, $filearea) {
        global $DB;
    
        // Validate parameters.
        $params = self::validate_parameters(
            self::get_uploaded_files_parameters(),
            [
                'cmid' => $cmid,
                'component' => $component,
                'filearea' => $filearea
            ]
        );
    
        // Check if the activity exists.
        if (!$cm = $DB->get_record('course_modules', ['id' => $cmid])) {
            throw new moodle_exception('invalidcoursemodule', 'error', '', $cmid);
        }
    
        // Get the module context.
        $context = context_module::instance($cmid);
    
        // Get the file_storage instance.
        $fs = get_file_storage();
    
        // Get files from the specified area of this activity.
        $files = $fs->get_area_files(
            $context->id,
            $component, // Dynamically provided component.
            $filearea,  // Dynamically provided file area.
            0,          // Item ID (usually 0 unless specific).
            'filename', // Sort by filename.
            false       // Exclude directories.
        );
    
        // Prepare the response.
        $fileList = [];
        foreach ($files as $file) {
            $fileList[] = [
                'filename' => $file->get_filename(),
                'filepath' => $file->get_filepath(),
                'filesize' => $file->get_filesize(),
                'mimetype' => $file->get_mimetype(),
                'url' => moodle_url::make_pluginfile_url(
                    $file->get_contextid(),
                    $file->get_component(),
                    $file->get_filearea(),
                    $file->get_itemid(),
                    $file->get_filepath(),
                    $file->get_filename()
                )->out()
            ];
        }
    
        return [
            'status' => true,
            'message' => count($fileList) . ' files found',
            'files' => $fileList
        ];
    }
    
    public static function get_uploaded_files_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Trạng thái thành công hay không'),
            'message' => new external_value(PARAM_TEXT, 'Thông báo kết quả'),
            'files' => new external_multiple_structure(
                new external_single_structure([
                    'filename' => new external_value(PARAM_FILE, 'Tên file'),
                    'filepath' => new external_value(PARAM_TEXT, 'Đường dẫn file trong Moodle'),
                    'filesize' => new external_value(PARAM_INT, 'Kích thước file'),
                    'mimetype' => new external_value(PARAM_TEXT, 'Loại MIME của file'),
                    'url' => new external_value(PARAM_URL, 'URL truy cập file')
                ])
            )
        ]);
    }


    // create course detail by json

    public static function create_course_with_json_parameters()
    {
        return new external_function_parameters(
            array(
                'fullname' => new external_value(PARAM_TEXT, 'Course fullname', VALUE_DEFAULT, ''),
                'shortname' => new external_value(PARAM_TEXT, 'Course shortname', VALUE_DEFAULT, ''),
                'categoryid' => new external_value(PARAM_TEXT, 'Category ID', VALUE_DEFAULT, '0'),
                'step' => new external_value(PARAM_INT, 'Course creation step 1: General information', VALUE_DEFAULT, 0),
                'summary' => new external_value(PARAM_RAW, 'Course summary', VALUE_DEFAULT, ''),
                'format' => new external_value(PARAM_TEXT, 'Course format', VALUE_DEFAULT, ''),
                'numsection' => new external_value(PARAM_TEXT, 'Number of sections', VALUE_DEFAULT, ''),
                'topicdetail' => new external_value(PARAM_RAW, 'List of sections with activities as JSON string', VALUE_DEFAULT, ''),
                'activitydetail' => new external_value(PARAM_RAW, 'List of activity as JSON string', VALUE_DEFAULT, '[]'),
                'useridlms' => new external_value(PARAM_TEXT, 'User ID Moodle', VALUE_DEFAULT, ''),
            )
        );
    }
    public static function create_course_with_json(
        $fullname,
        $shortname,
        $categoryid,
        $step,
        $summary,
        $format,
        $numsection,
        $topicdetail,
        $activitydetail = [],
        $useridlms
    ) {
        global $DB;

        $dataJson = [
            'fullname' => $fullname,
            'shortname' => $shortname,
            'categoryid' => $categoryid,
            'summary' => $summary,
            'format' => $format,
            'numsection' => $numsection,
            'useridlms' => $useridlms,
            'step' => $step,
        ];

        $transaction = $DB->start_delegated_transaction();

        try {
            if (empty($dataJson['step'])) {
                throw new Exception('Course Step is required.');
            }

            if($step == 1){
                if (empty($useridlms)) {
                    throw new Exception('You don\'t have an account on plearn yet.');
                }
                $dataJson['topicdetail'] = $topicdetail;
                $courseCreationStep1 = create_course_with_json_step_1($dataJson);
                if(!$courseCreationStep1['status']){
                    throw new Exception($courseCreationStep1['message']);
                }
            }

            if($step == 2){
                if (empty($useridlms)) {
                    throw new Exception('You don\'t have an account on plearn yet.');
                }
                $dataJson['activitydetail'] = $activitydetail;
                $courseCreationStep2 = create_course_with_json_step_2($dataJson);

                if(!$courseCreationStep2['status']){
                    throw new Exception($courseCreationStep2['message']);
                }
            }

            $existing_course = $DB->get_record('course', ['shortname' => $dataJson['shortname']]);

            $courseId = $existing_course->id;
            // Commit transaction khi mọi thứ thành công
            $transaction->allow_commit();

            return [
                'status' => true,
                'message' => 'Course created successfully.',
                'data' => [
                    'id' => $courseId
                ]
            ];

        } catch (Exception $e) {
            if (!empty($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            
            return [
                'status' => false,
                'message' => $e->getMessage(),
                'data' => []
            ];
        }
    }
    
    public static function create_course_with_json_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Trạng thái thành công hay không'),
            'message' => new external_value(PARAM_TEXT, 'Thông báo kết quả'),
            'data' => new external_single_structure([
                'id' => new external_value(PARAM_INT, 'ID của khóa học', VALUE_DEFAULT, 0)  // Giả sử trả về 0 nếu không có id
            ])
        ]);
    }

    public static function enrol_user_to_course($user_id, $course_id, $role_id) {
        try {
            $enrolment = [
                'roleid' => $role_id, // ID của vai trò, ví dụ: 5 (student), 3 (teacher)
                'userid' => $user_id, // ID của người dùng
                'courseid' => $course_id // ID của khóa học
            ];
    
            // Gọi hàm API để thực hiện ghi danh
            $result = \enrol_manual_external::enrol_users([$enrolment]);
    
            return [
                'status' => true,
                'message' => 'User enrolled successfully.',
                'data' => $result
            ];
        } catch (Exception $e) {
            return [
                'status' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    // create activity quiz and question
    public static function create_quiz_and_question_parameters()
    {
        return new external_function_parameters(
            array(
                'fullname' => new external_value(PARAM_TEXT, 'Course fullname', VALUE_DEFAULT, ''),
                'shortname' => new external_value(PARAM_TEXT, 'Course shortname', VALUE_DEFAULT, ''),
                'step' => new external_value(PARAM_INT, 'Course creation step 1: General information', VALUE_DEFAULT, 0),
                'activities' => new external_value(PARAM_RAW, 'List of activity quiz with question as JSON string', VALUE_DEFAULT, '[]'),
                'useridlms' => new external_value(PARAM_TEXT, 'User ID Moodle', VALUE_DEFAULT, '[]'),
            )
        );
    }
    public static function create_quiz_and_question(
        $fullname,
        $shortname,
        $step,
        $activities,
        $useridlms
    ) {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        try {
            if (empty($useridlms)) {
                throw new Exception('You don\'t have an account on plearn yet.');
            }
            
            if (empty($shortname)) {
                throw new Exception('Course Shortname is required.');
            }

            if (empty($activities)) {
                throw new Exception('Activities is required.');
            }
        
            $decoded_data = json_decode($activities, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON for activities: ' . json_last_error_msg());
            }

            $activity_keys = ['name', 'section'];
            $question_base_keys = ['question_name', 'question_text', 'question_type', 'correct_answer']; // Content is not required by default

            // Loop through each topic and check for the required keys and values
            foreach ($decoded_data as $activity) {
                // Check keys for topic
                if (!empty(array_diff($activity_keys, array_keys($activity)))) {
                    throw new Exception('Activity is missing required fields.');
                }

                // Check if the 'name' field in topic is not empty
                if (empty($activity['name'])) {
                    throw new Exception('Quiz name cannot be empty.');
                }

                if (empty($activity['section'])) {
                    throw new Exception('Quiz section cannot be empty.');
                }

                // Check keys and values for each activity inside the topic
                if (!empty($activity['questions'])) {
                    foreach ($activity['questions'] as $question) {
                        // Merge base activity keys and conditional content key
                        $required_question_keys = $question_base_keys;

                        if (!empty(array_diff($required_question_keys, array_keys($question)))) {
                            throw new Exception('Question is missing required fields for name: ' . ($question['question_name'] ?? 'unknown'));
                        }

                        if (empty($question['question_name'])) {
                            throw new Exception('Question name cannot be empty.');
                        }

                        if ($question['question_type'] != 'ddwtos') {
                            if (empty($question['question_text'])) {
                                throw new Exception('Question text cannot be empty.');
                            }
                        }

                        if ($question['question_type'] == 'ddwtos') {
                            if(empty($question['ddwtos_texts'])){
                                throw new Exception('Question text cannot be empty for question type ddwtos.');
                            }

                            if(empty($question['ddwtos_answers'])){
                                throw new Exception('Question answers cannot be empty for question type ddwtos.');
                            }
                        }

                        if (empty($question['question_type'])) {
                            throw new Exception('Question type cannot be empty.');
                        }

                        if ($question['question_type'] == 'truefalse' && empty($question['correct_answer'])) {
                            throw new Exception('Correct answer cannot be empty for question type truefalse.');
                        }

                        if ($question['question_type'] == 'multichoice') {
                            if(empty($question['question_single'])){
                                throw new Exception('Question Single cannot be empty for question type multichoice.');
                            }

                            if(empty($question['question_noanswers'])){
                                throw new Exception('Question noanswers cannot be empty for question type multichoice.');
                            }

                            if(empty($question['answers'])){
                                throw new Exception('Question answers cannot be empty for question type multichoice.');
                            }
                        }
                    }
                }
            }

            $existing_course = $DB->get_record('course', ['shortname' => $shortname]);

            $courseId = $existing_course->id;

            if (!$existing_course) {
                throw new Exception('A course with the shortname "' . $shortname . '" does not exists.');
            }

            $context_course = context_course::instance($courseId);

            if (!$context_course) {
                throw new Exception('No context found for the given courseId.');
            }

            $context_course_id = $context_course->id;

            $existing_question_category_course = $DB->get_record_sql(
                "SELECT * 
                 FROM {question_categories} 
                 WHERE contextid = :contextid 
                   AND name != :name",
                ['contextid' => $context_course_id, 'name' => 'top']
            );

            if(!$existing_question_category_course){
                $topCategoryCourse = question_get_top_category($context_course_id, $create = true);
            
                $category = new stdClass();
                $contextname = $context_course->get_context_name(false, true);
                // Max length of name field is 255.
                $category->name = shorten_text(get_string('defaultfor', 'question', $contextname), 255);
                $category->info = get_string('defaultinfofor', 'question', $contextname);
                $category->contextid = $context_course_id;
                $category->parent = $topCategoryCourse->id;
                // By default, all categories get this number, and are sorted alphabetically.
                $category->sortorder = 999;
                $category->stamp = make_unique_id_code();
                $category->id = $DB->insert_record('question_categories', $category);

                $questionCategory = $category->id;
            } else {
                $questionCategory = $existing_question_category_course->id;
            }

            $form_category = $questionCategory . ',' . $context_course_id;

            $dataJson = [
                'question_category' => $questionCategory,
                'question_createdby' => $useridlms,
                'question_contextid' => $context_course_id,
                'form_category' => $form_category,
                'form_courseid' => $courseId,
            ];

            // $sections = core_course_external::get_course_contents($courseId);

            // $cmids = [];

            // foreach ($decoded_data as $key => $activity) {
            //     $activity_section = $activity['section'] ?? 1;
            //     // Duyệt qua danh sách sections để tìm section có section = $activity['topic']
            //     foreach ($sections as $section) {
            //         if ($section['section'] == $activity_section) {
            //             if (!empty($section['modules'])) { // Kiểm tra nếu có modules
            //                 foreach ($section['modules'] as $module) {
            //                     if($module['modname'] == 'quiz'){
            //                         $cmids[] = $module['id']; // Lưu ID vào danh sách
            //                     }
            //                 }
            //             }
            //             break;
            //         }
            //     }
            // }

            // $cmids = array_unique($cmids); // Loại bỏ các ID trùng lặp
            // $cmids = array_values($cmids); // Reset key array để tránh lỗi index không liên tục

            // if (!empty($cmids)) {
            //     try {
            //         core_course_external::delete_modules($cmids);
            //     } catch (Exception $e) {
            //         throw new Exception('Sections data is not a valid array.');
            //     }
            // }

            // Thêm chủ đề và hoạt động vào khóa học
            foreach ($decoded_data as $key => $activity) {
                $activity_name = $activity['name'];
                $activity_description = $activity['description'] ?? '';
                $activity_section = $activity['section'] ?? 1;
                $slot = 1;
                $quiz_action = self::create_activity_quiz($courseId, $activity_name, $activity_section, $activity_description);

                $cmid = $quiz_action['cmid'];
                $instanceid = $quiz_action['instanceid'];

                // $cmid = 16;
                // $instanceid = 4;

                $context_module = context_module::instance($cmid);

                if (!$context_module) {
                    throw new Exception('No context found for the given cmid.');
                }

                $context_module_id = $context_module->id;

                foreach ($activity['questions'] as $question) {

                    $correctanswer = 1; // true
                    if($question['correct_answer'] == 'False'){
                        $correctanswer = 0; // true
                    }

                    $dataJson['question_qtype'] = $question['question_type'];
                    $dataJson['form_name'] = $question['question_name'];
                    $dataJson['form_questiontext'] = $question['question_text'];
                    $dataJson['form_cmid'] = $cmid;
                    $dataJson['form_returnurl'] = '/mod/quiz/edit.php?cmid='.$cmid.'&cat='.$questionCategory.'%2C'.$context_course_id.'&addonpage=0';
                    
                    if($question['question_type'] == 'truefalse'){
                        $dataJson['form_correctanswer'] = $correctanswer;
                    }

                    if($question['question_type'] == 'multichoice'){

                        $dataJson['form_single'] = 0; // muiltiple answer

                        if($question['question_single'] == 'oneanswer'){
                            $dataJson['form_single'] = 1;
                        }

                        $dataJson['form_noanswers'] = $question['question_noanswers'];

                        // $dataJson['form_noanswers'] = count($question['answers']);

                        $dataJson['form_answer'] = array_map(function ($item) {
                            return [
                                "text" => $item['answer_text'] ?? "",
                                "format" => 1
                            ];
                        }, $question['answers']);

                        $dataJson['form_fraction'] = array_map(function ($item) {
                            return $item['fraction']; // Đảm bảo định dạng "1.0", "0.0"
                        }, $question['answers']);

                        $dataJson['form_feedback'] = array_map(function ($item) {
                            return [
                                "text" => "",
                                "format" => 1
                            ];
                        }, $question['answers']);
                    }

                    if($question['question_type'] == 'match'){
                        $dataJson['form_noanswers'] = $question['question_noanswers'];

                        // $dataJson['form_noanswers'] = count($question['matches']);

                        $dataJson['form_subquestions'] = array_map(function ($item) {
                            return [
                                "text" => $item['subquestion'] ?? "",
                                "format" => 1
                            ];
                        }, $question['matches']);

                        $dataJson['form_subanswers'] = array_map(function ($item) {
                            return $item['subanswer']; // Đảm bảo định dạng "1.0", "0.0"
                        }, $question['matches']);
                    }

                    if($question['question_type'] == 'ddwtos'){
                        $dataJson['form_noanswers'] = $question['question_noanswers'];

                        // $dataJson['form_noanswers'] = count($question['matches']);
                        $count_ddwtos_texts = count($question['ddwtos_texts']);
                        $count_ddwtos_answers = count($question['ddwtos_answers']);

                        if($count_ddwtos_texts != $count_ddwtos_answers){
                            throw new Exception('The number of question texts and answers for the ddwtos type do not match. Please try again.');
                        }

                        // Nối các text từ ddwtos_texts
                        $questionTexts = array_map(function ($item) {
                            return $item['text'];
                        }, $question['ddwtos_texts']); // Lấy danh sách các text

                        // Tạo chuỗi HTML với các text nối bằng <br>
                        $questionTextHtml = "<p>" . implode("<br>", $questionTexts) . "</p>";

                        // Gán giá trị vào $form->questiontext
                        $dataJson['form_ddwtos_texts'] = [
                            'text' => $questionTextHtml,
                            'format' => "1",
                        ];

                        $dataJson['form_ddwtos_answers'] = array_map(function ($item) {
                            return [
                                "answer" => $item['drag_item'] ?? "",
                                "choicegroup" => 1
                            ];
                        }, $question['ddwtos_answers']);
                    }

                    //step 1: tạo question trong question bank

                    $action_save_question = save_question_type_truefalse($dataJson);

                    if(isset($action_save_question->errorcode)){
                        throw new Exception($action_save_question->message);
                    }

                    $question_data = $DB->get_record_sql(
                        'SELECT * FROM {question} 
                         ORDER BY id DESC 
                         LIMIT 1'
                    );

                    //step 2: insert mdl_quiz_slots -> slotid

                    $slot_data = new stdClass();
                    $slot_data->quizid = $instanceid; // ID của quiz (tạo từ bước 1)
                    $slot_data->page = $slot; // ID câu hỏi trong question bank
                    $slot_data->slot = $slot; // Số thứ tự câu hỏi trong quiz, có thể cần phải thay đổi nếu có nhiều câu hỏi
                    $slot_data->maxmark = 1;
                    $slot_data->requireprevious = 0;
                    // Insert vào bảng mdl_quiz_slots
                    $slotid = $DB->insert_record('quiz_slots', $slot_data);

                    $slot++;
                    //step 3: insert mdl_question_references -> itemid = slotid, questionbankentryid = questionbankentryid ở bảng mdl_question_versions dựa vào questionid
                    $question_version_data = $DB->get_record('question_versions', ['questionid' => $question_data->id]);
                    
                    if ($question_version_data) {
                        $reference_data = new stdClass();
                        $reference_data->usingcontextid = $context_module_id;
                        $reference_data->component = 'mod_quiz';
                        $reference_data->questionarea = 'slot';
                        $reference_data->itemid = $slotid; // Lấy slotid từ bước 2
                        $reference_data->questionbankentryid = $question_version_data->questionbankentryid; // Lấy questionbankentryid từ question_versions
                        $reference_data->version = 1;

                        // Insert vào bảng mdl_question_references
                        $DB->insert_record('question_references', $reference_data);
                    }
                }
            }

            // // Commit transaction khi mọi thứ thành công
            $transaction->allow_commit();

            return [
                'status' => true,
                'message' => 'Course created successfully.',
                'data' => [
                    'id' => $courseId
                ]
            ];

        } catch (Exception $e) {
            if (!empty($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }

            return [
                'status' => false,
                'message' => $e->getMessage(),
                'data' => []
            ];
        }
    }
    
    public static function create_quiz_and_question_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Trạng thái thành công hay không'),
            'message' => new external_value(PARAM_TEXT, 'Thông báo kết quả'),
            'data' => new external_single_structure([
                'id' => new external_value(PARAM_INT, 'ID của khóa học', VALUE_DEFAULT, 0)  // Giả sử trả về 0 nếu không có id
            ])
        ]);
    }

    // get activity complete
    public static function get_activity_complete_parameters()
    {
        return new external_function_parameters(
            array(
                'userid' => new external_value(PARAM_INT, 'User ID Moodle', VALUE_DEFAULT, 0),
                'page' => new external_value(PARAM_INT, 'Page', VALUE_DEFAULT, 0),
                'perpage' => new external_value(PARAM_INT, 'Perpage', VALUE_DEFAULT, 0),
                'role' => new external_value(PARAM_TEXT, 'role', VALUE_DEFAULT, 'all'),
            )
        );
    }

    public static function get_activity_complete(
        $userid,
        $page,
        $perpage,
        $role
    ) {
        global $DB, $OUTPUT;
    
        // Kiểm tra userid có hợp lệ không
        if (empty($userid)) {
            return [
                'status' => false,
                'message' => 'Invalid user ID.',
                'data' => []
            ];
        }
    
        // Xử lý trường hợp page và perpage không được cung cấp
        $limitClause = '';
        if (!empty($perpage)) {
            $offset = (int) $page * (int) $perpage;
            $limitClause = "LIMIT {$perpage} OFFSET {$offset}";
        }
    
        // Tạo điều kiện cho role
        $roleCondition = '';
        if ($role === 'student') {
            $roleCondition = "AND r.shortname = 'student'";
        } elseif ($role === 'teacher') {
            $roleCondition = "AND (r.shortname = 'editingteacher' OR r.shortname = 'teacher')";
        }

        // $enrolledCourses = enrol_get_users_courses($userid);

        // var_dump($enrolledCourses);die;
    
        // Truy vấn lấy danh sách activity đã hoàn thành
        $sql = "
            SELECT 
                DISTINCT cm.id AS activity_id,
                cmc.timemodified AS completion_time,
                cm.course AS course_id,
                c.fullname AS course_name,
                cc.name AS category_name,
                cm.instance AS activity_instance_id,
                m.name AS activity_type,
                cmc.userid AS user_id,
                cm.visible AS visible,
                cm.completion AS completion
            FROM 
                {course_modules_completion} cmc
            JOIN 
                {course_modules} cm ON cm.id = cmc.coursemoduleid
            JOIN 
                {course} c ON c.id = cm.course
            JOIN 
                {course_categories} cc ON cc.id = c.category
            JOIN 
                {modules} m ON m.id = cm.module
            JOIN 
                {role_assignments} ra ON ra.userid = cmc.userid
            JOIN 
                {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50
            JOIN 
                {role} r ON ra.roleid = r.id
            JOIN 
                {user_enrolments} ue ON ue.userid = cmc.userid
            JOIN 
                {enrol} e ON e.id = ue.enrolid AND e.courseid = cm.course -- Lọc khóa học người dùng tham gia
            WHERE 
                cmc.userid = :userid
                AND cmc.completionstate IN (1, 2)
                AND cm.deletioninprogress != 1
                AND cm.completion != :completion_disabled
                AND m.name != 'vedubotleanbothoctap'
                AND c.visible = 1
                $roleCondition
            ORDER BY 
                cmc.timemodified DESC
            $limitClause";
    
        $params = [
            'userid' => $userid,
            'completionstate' => COMPLETION_COMPLETE,
            'completion_disabled' => COMPLETION_DISABLED,
        ];

        if (!empty($perpage)) {
            $params['perpage'] = $perpage;
            $params['offset'] = $offset;
        }
    
        $activities = $DB->get_records_sql($sql, $params);
    
        // Định dạng dữ liệu trả về
        $completedActivities = [];
        foreach ($activities as $activity) {
            $tablename = $activity->activity_type; // Module name (e.g., 'assign', 'quiz')
            $activityName = $DB->get_field($tablename, 'name', ['id' => $activity->activity_instance_id]);
    
            $activityUrl = (new moodle_url('/mod/' . $activity->activity_type . '/view.php', ['id' => $activity->activity_id]))->out();
            $viewUrl = (new moodle_url('/course/view.php', ['id' => $activity->course_id]))->out();
            $activityImage = $OUTPUT->image_url('monologo', 'mod_' . $activity->activity_type)->out();
    
            $completedActivities[] = [
                'course_id' => $activity->course_id,
                'coursename' => $activity->course_name,
                'view_url' => $viewUrl,
                'categoryname' => $activity->category_name,
                'cmid' => $activity->activity_id,
                'modname' => $activity->activity_type,
                'activity_image' => $activityImage,
                'instance' => $activity->activity_instance_id,
                'activity_name' => $activityName,
                'timecompleted' => !empty($activity->completion_time) ? date('d-m-Y H:i:s', $activity->completion_time) : null,
                'activity_url' => $activityUrl,
            ];
        }
    
        // Đếm tổng số activity hoàn thành nếu phân trang
        $countSql = "
            SELECT COUNT(DISTINCT cm.id)
            FROM 
                {course_modules_completion} cmc
            JOIN 
                {course_modules} cm ON cm.id = cmc.coursemoduleid
            JOIN 
                {modules} m ON m.id = cm.module
            JOIN 
                {role_assignments} ra ON ra.userid = cmc.userid
            JOIN 
                {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50
            JOIN 
                {course} c ON c.id = ctx.instanceid
            JOIN 
                {role} r ON ra.roleid = r.id
            JOIN 
                {user_enrolments} ue ON ue.userid = cmc.userid
            JOIN 
                {enrol} e ON e.id = ue.enrolid AND e.courseid = cm.course -- Lọc khóa học người dùng tham gia
            WHERE 
                cmc.userid = :userid
                AND cmc.completionstate IN (1, 2)
                AND cm.deletioninprogress != 1
                AND cm.completion != :completion_disabled
                AND m.name != 'vedubotleanbothoctap'
                AND c.visible = 1
                $roleCondition";
    
        $totalActivities = $DB->count_records_sql($countSql, $params);

        // $totalActivities = count($completedActivities);
    
        $totalPages = ($perpage > 0) ? ceil($totalActivities / $perpage) : 1;
        $currentPage = (int)$page;
    
        return [
            'status' => true,
            'message' => 'Completed activities retrieved successfully.',
            'data' => [
                'totalActivities' => $totalActivities,
                'totalPages' => $totalPages,
                'currentPage' => $currentPage,
                'activities' => $completedActivities
            ]
        ];
    }
    
    public static function get_activity_complete_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Trạng thái thành công hay không'),
            'message' => new external_value(PARAM_TEXT, 'Thông báo kết quả'),
            'data' => new external_single_structure([
                'totalActivities' => new external_value(PARAM_INT, 'Tổng số activity đã hoàn thành'),
                'totalPages' => new external_value(PARAM_INT, 'Tổng số trang'),
                'currentPage' => new external_value(PARAM_INT, 'Trang hiện tại'),
                'activities' => new external_multiple_structure(
                    new external_single_structure([
                        'course_id' => new external_value(PARAM_INT, 'ID của khóa học'),
                        'coursename' => new external_value(PARAM_TEXT, 'Tên khóa học'),
                        'view_url' => new external_value(PARAM_TEXT, 'url khóa học'),
                        'categoryname' => new external_value(PARAM_TEXT, 'Tên danh mục'),
                        'cmid' => new external_value(PARAM_INT, 'ID module activity'),
                        'modname' => new external_value(PARAM_TEXT, 'Loại activity'),
                        'activity_image' => new external_value(PARAM_TEXT, 'Image activity'),
                        'instance' => new external_value(PARAM_INT, 'ID của activity'),
                        'activity_name' => new external_value(PARAM_TEXT, 'Tên activity'),
                        'timecompleted' => new external_value(PARAM_TEXT, 'Thời gian hoàn thành'),
                        'activity_url' => new external_value(PARAM_TEXT, 'url activity')
                    ])
                )
            ])
        ]);
    }

    // get activity due
    public static function get_activity_due_parameters()
    {
        return new external_function_parameters(
            array(
                'userid' => new external_value(PARAM_INT, 'User ID Moodle', VALUE_DEFAULT, 0),
                'page' => new external_value(PARAM_INT, 'Page', VALUE_DEFAULT, 0),
                'perpage' => new external_value(PARAM_INT, 'Perpage', VALUE_DEFAULT, 0),
                'role' => new external_value(PARAM_TEXT, 'role', VALUE_DEFAULT, 'all'),
            )
        );
    }

    public static function get_activity_due(
        $userid,
        $page,
        $perpage,
        $role
    ) {
        global $DB, $OUTPUT;

        // Kiểm tra userid có hợp lệ không
        if (empty($userid)) {
            return [
                'status' => false,
                'message' => 'Invalid user ID.',
                'data' => []
            ];
        }

        // Xử lý phân trang
        $limitClause = '';
        if (!empty($perpage)) {
            $offset = (int) $page * (int) $perpage;
            $limitClause = "LIMIT {$perpage} OFFSET {$offset}";
        }

        // Tạo điều kiện cho role
        $roleCondition = '';
        if ($role === 'student') {
            $roleCondition = "AND r.shortname = 'student'";
        } elseif ($role === 'teacher') {
            $roleCondition = "AND (r.shortname = 'editingteacher' OR r.shortname = 'teacher')";
        }

        $sql = "
            SELECT 
                DISTINCT cm.id AS activity_id,
                cmc.timemodified AS completion_time,
                cm.course AS course_id,
                c.fullname AS course_name,
                cc.name AS category_name,
                cm.instance AS activity_instance_id,
                m.name AS activity_type,
                cmc.userid AS user_id,
                cm.visible AS visible,
                cm.completion AS completion
            FROM 
                {course_modules} cm
            JOIN 
                {course} c ON c.id = cm.course
            JOIN 
                {course_categories} cc ON cc.id = c.category
            JOIN 
                {modules} m ON m.id = cm.module
            LEFT JOIN 
                {course_modules_completion} cmc ON cm.id = cmc.coursemoduleid AND cmc.userid = :userid2
            JOIN 
                {role_assignments} ra ON ra.userid = :userid1 -- Liên kết với bảng role_assignments
            JOIN 
                {role} r ON ra.roleid = r.id -- Liên kết với bảng role
            JOIN 
                {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 -- Lọc theo khóa học
            JOIN 
                {user_enrolments} ue ON ue.userid = :userid3
            JOIN 
                {enrol} e ON e.id = ue.enrolid AND e.courseid = cm.course -- Lọc khóa học người dùng tham gia
            WHERE 
                cm.deletioninprogress != 1
                AND cm.completion != :completion_disabled
                AND (cmc.completionstate IS NULL OR cmc.completionstate = :completionstate OR cmc.coursemoduleid IS NULL) -- Điều kiện lọc các hoạt động chưa có hoàn thành
                AND ue.userid = :userid -- Điều kiện khóa học người dùng tham gia
                AND m.name != 'vedubotleanbothoctap'
                AND c.visible = 1
                $roleCondition
            $limitClause";

        $params = [
            'userid' => $userid,
            'userid1' => $userid,
            'userid2' => $userid,
            'userid3' => $userid,
            'completionstate' => COMPLETION_INCOMPLETE,
            'completion_disabled' => COMPLETION_DISABLED,
        ];

        if (!empty($perpage)) {
            $params['perpage'] = $perpage;
            $params['offset'] = $offset;
        }

        $activities = $DB->get_records_sql($sql, $params);

        // Định dạng dữ liệu trả về
        $duedActivities = [];
        foreach ($activities as $activity) {
            // Lấy tên activity từ bảng module tương ứng
            $tablename = $activity->activity_type; // Module name (e.g., 'assign', 'quiz')
            $activityName = $DB->get_field($tablename, 'name', ['id' => $activity->activity_instance_id]);

            // Tạo URL dẫn đến activity
            $activityUrl = (new moodle_url('/mod/' . $activity->activity_type . '/view.php', ['id' => $activity->activity_id]))->out();

            // Tạo URL xem khóa học
            $viewUrl = (new moodle_url('/course/view.php', ['id' => $activity->course_id]))->out();

            // Lấy ảnh đại diện activity (có thể cần sửa đổi tùy thuộc vào cách lưu trữ hình ảnh)
            $activityImage = $OUTPUT->image_url('monologo', 'mod_' . $activity->activity_type)->out(); // Biểu tượng mặc định

            // Định dạng dữ liệu
            $duedActivities[] = [
                'course_id' => $activity->course_id,
                'coursename' => $activity->course_name,
                'view_url' => $viewUrl,
                'categoryname' => $activity->category_name,
                'cmid' => $activity->activity_id,
                'modname' => $activity->activity_type,
                'activity_image' => $activityImage,
                'instance' => $activity->activity_instance_id,
                'activity_name' => $activityName,
                'timecompleted' => !empty($activity->completion_time) ? date('d-m-Y H:i:s', $activity->completion_time) : null,
                'activity_url' => $activityUrl,
            ];
        }

        // Đếm tổng số activity due
        $countSql = "
            SELECT COUNT(DISTINCT cm.id)
            FROM 
                {course_modules} cm
            LEFT JOIN 
                {course_modules_completion} cmc ON cm.id = cmc.coursemoduleid AND cmc.userid = :userid1
            JOIN 
                {role_assignments} ra ON ra.userid = :userid2 -- Liên kết với bảng role_assignments
            JOIN 
                {modules} m ON m.id = cm.module
            JOIN 
                {role} r ON ra.roleid = r.id -- Liên kết với bảng role
            JOIN 
                {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 -- Lọc theo khóa học
            JOIN 
                {course} c ON c.id = ctx.instanceid
            JOIN 
                {user_enrolments} ue ON ue.userid = :userid3
            JOIN 
                {enrol} e ON e.id = ue.enrolid AND e.courseid = cm.course -- Lọc khóa học người dùng tham gia
            WHERE 
                cm.deletioninprogress != 1
                AND cm.completion != :completion_disabled
                AND (cmc.completionstate IS NULL OR cmc.completionstate = :completionstate OR cmc.coursemoduleid IS NULL) -- Điều kiện lọc các hoạt động chưa có hoàn thành
                AND ue.userid = :userid -- Điều kiện khóa học người dùng tham gia
                AND m.name != 'vedubotleanbothoctap'
                AND c.visible = 1
                $roleCondition
        ";
        $totalActivities = $DB->count_records_sql($countSql, $params);

        // Tính toán số trang (kiểm tra perpage)
        $totalPages = ($perpage > 0) ? ceil($totalActivities / $perpage) : 1; // Nếu $perpage <= 0 thì số trang mặc định là 1
        $currentPage = (int)$page; // Trang hiện tại

        return [
            'status' => true,
            'message' => 'Due activities retrieved successfully.',
            'data' => [
                'totalActivities' => $totalActivities,
                'totalPages' => $totalPages,
                'currentPage' => $currentPage,
                'activities' => $duedActivities
            ]
        ];
    }

    public static function get_activity_due_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Trạng thái thành công hay không'),
            'message' => new external_value(PARAM_TEXT, 'Thông báo kết quả'),
            'data' => new external_single_structure([
                'totalActivities' => new external_value(PARAM_INT, 'Tổng số activity cần làm'),
                'totalPages' => new external_value(PARAM_INT, 'Tổng số trang'),
                'currentPage' => new external_value(PARAM_INT, 'Trang hiện tại'),
                'activities' => new external_multiple_structure(
                    new external_single_structure([
                        'course_id' => new external_value(PARAM_INT, 'ID của khóa học'),
                        'coursename' => new external_value(PARAM_TEXT, 'Tên khóa học'),
                        'view_url' => new external_value(PARAM_TEXT, 'URL của khóa học'),
                        'categoryname' => new external_value(PARAM_TEXT, 'Tên danh mục'),
                        'cmid' => new external_value(PARAM_INT, 'ID của module activity'),
                        'modname' => new external_value(PARAM_TEXT, 'Loại activity'),
                        'activity_image' => new external_value(PARAM_TEXT, 'Hình ảnh của activity'),
                        'instance' => new external_value(PARAM_INT, 'ID của activity'),
                        'activity_name' => new external_value(PARAM_TEXT, 'Tên của activity'),
                        'timecompleted' => new external_value(PARAM_TEXT, 'Thời gian hoàn thành'),
                        'activity_url' => new external_value(PARAM_TEXT, 'URL của activity')
                    ])
                )
            ])
        ]);
    }

    // get data Basic course information
    public static function get_data_basic_course_information_parameters()
    {
        return new external_function_parameters(
            array(
                'userid' => new external_value(PARAM_INT, 'User ID Moodle', VALUE_DEFAULT, 0),
                'role' => new external_value(PARAM_TEXT, 'role', VALUE_DEFAULT, 'all'),
                'limit' => new external_value(PARAM_INT, 'the number of results to return', VALUE_DEFAULT, 0),
                'offset' => new external_value(PARAM_INT, 'offset the result set by a given amount', VALUE_DEFAULT, 0),
                'courseid' => new external_value(PARAM_INT, 'courseid', VALUE_DEFAULT, 0),
                'coursename' => new external_value(PARAM_TEXT, 'coursename', VALUE_DEFAULT, 0)
            )
        );
    }

    public static function get_data_basic_course_information($userid, $role, $limit, $offset, $courseid, $coursename) {
        global $DB, $OUTPUT;
    
        // Kiểm tra userid có hợp lệ không
        if (empty($userid)) {
            return [
                'status' => false,
                'message' => 'Invalid user ID.',
                'data' => []
            ];
        }
    
        $total_course_enrolled = $DB->count_records('user_enrolments', ['userid' => $userid]);
    
        // $enrolledCourses = enrol_get_users_courses($userid);

        $enrolledCoursesSql = "SELECT DISTINCT c.fullname, c.id, c.summary, f.filename AS course_image, f.contextid AS f_contextid,
                IFNULL(FROM_UNIXTIME(l.timeaccess, '%d-%m-%Y %H:%i:%s'), 'N/A') AS last_access_time,
                cat.id AS categoryid, cat.name AS categoryname
        FROM mdl_user u
        JOIN mdl_role_assignments ra ON u.id = ra.userid
        JOIN mdl_context ctx ON ra.contextid = ctx.id
        JOIN mdl_role r ON ra.roleid = r.id
        JOIN mdl_course c ON ctx.instanceid = c.id
        LEFT JOIN mdl_files f ON f.contextid = ctx.id AND f.component = 'course' AND f.filearea = 'overviewfiles' AND f.filename <> '.'
        LEFT JOIN mdl_user_lastaccess l ON l.courseid = c.id AND l.userid = u.id
        JOIN mdl_course_categories cat ON c.category = cat.id
        WHERE u.id = $userid
        AND c.visible = 1";

        if ($role == 'student') {
            $enrolledCoursesSql .= " and r.shortname = 'student'";
        }

        if ($role == 'teacher') {
            $enrolledCoursesSql .= " and (r.shortname = 'editingteacher' OR r.shortname = 'teacher')";
        }

        if (!empty($courseid)) {
            $enrolledCoursesSql .= " and c.id = $courseid";
        }

        if (!empty($coursename)) {
            $enrolledCoursesSql .= " and c.fullname like '%$coursename%'";
        }

        $enrolledCoursesSql .= " ORDER BY l.timeaccess DESC";

        if (!empty($limit)) {
            $enrolledCoursesSql .= " LIMIT $limit OFFSET $offset";
        }

        $enrolledCoursesQuery = $DB->get_records_sql($enrolledCoursesSql);

        $countCourseCompleted = 0;
        $countActivityCompleted = 0;
        $countTotalActivityDue = 0;

        $courseDetails = [];

        if (!empty($enrolledCoursesQuery)) {
            foreach ($enrolledCoursesQuery as $course) {
                $courseId = $course->id;
                $courseData = [
                    'id' => $course->id,
                    'coursename' => $course->fullname,
                    'summary' => $course->summary,
                    'course_image' => (new moodle_url($course->course_image ? $CFG->wwwroot . '/pluginfile.php/' . $course->f_contextid . '/course/overviewfiles/' . $course->course_image : ''))->out(),
                    'last_access_time' => $course->last_access_time,
                    'categoryid' => $course->categoryid,
                    'categoryname' => $course->categoryname,
                    'view_url' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(),
                ];

                // Lấy trạng thái hoàn thành các activity trong khóa học
                $get_activities_completion_status = core_completion_external::get_activities_completion_status($courseId, $userid);
                if (!empty($get_activities_completion_status['statuses'])) {
                    $totalActivity = count($get_activities_completion_status['statuses']);
                    $completedActivities = array_filter($get_activities_completion_status['statuses'], function ($status) {
                        return $status['state'] == 1 || $status['state'] == 2;
                    });
                    $numberActivityCompletion = count($completedActivities);
                    $totalActivityDue = $totalActivity - count($completedActivities);
        
                    $countTotalActivityDue += $totalActivityDue;
                    $countActivityCompleted += $numberActivityCompletion;
                }
                $courseData['total_activity_completion'] = (int) $numberActivityCompletion;
                $courseData['total_activity_due'] = (int) $totalActivityDue;
                $courseData['total_activity'] = (int) $totalActivity;
                // Sử dụng try-catch để xử lý trường hợp không có tiêu chí hoàn thành
                try {
                    $get_course_completion_status = core_completion_external::get_course_completion_status($courseId, $userid);

                    if (!empty($get_course_completion_status['completionstatus']['completions'])) {
                        $completionStatus = $get_course_completion_status['completionstatus'];
                        $totalCompletions = count($completionStatus['completions']);
                        $completedCompletions = array_filter($completionStatus['completions'], function ($completion) {
                            return isset($completion['complete']) && $completion['complete'] === true;
                        });
        
                        $completedCount = count($completedCompletions);
        
                        $hasOtherType = array_reduce($completionStatus['completions'], function ($carry, $completion) {
                            return $carry || (int)$completion['type'] !== 4;
                        }, false);

                        if ($hasOtherType) {
                            $completionPercentage = $totalActivity > 0
                                ? round(($numberActivityCompletion / $totalActivity) * 100, 2)
                                : 0;
                        } else {
                            $completionPercentage = $totalCompletions > 0
                                ? round(($completedCount / $totalCompletions) * 100, 2)
                                : 0;
                        }
                    }else{
                        $completionPercentage = $totalActivity > 0
                            ? round(($numberActivityCompletion / $totalActivity) * 100, 2)
                            : 0;
                    }
                } catch (moodle_exception $e) {
                    $completionPercentage = $totalActivity > 0
                        ? round(($numberActivityCompletion / $totalActivity) * 100, 2)
                        : 0;
                    $courseData['completionPercentage'] = (int) $completionPercentage;
                    // $courseDetails[] = $courseData;
                }

                $courseData['completionPercentage'] = (int) $completionPercentage;

                if (isset($completionPercentage) && $completionPercentage == 100) {
                    $countCourseCompleted++;
                }

                $courseDetails[] = $courseData;
            }
        }

        // Tính tổng số trang và trang hiện tại
        $totalCoursesSql = "SELECT COUNT(DISTINCT c.id) as total_count
        FROM mdl_user u
        JOIN mdl_role_assignments ra ON u.id = ra.userid
        JOIN mdl_context ctx ON ra.contextid = ctx.id
        JOIN mdl_role r ON ra.roleid = r.id
        JOIN mdl_course c ON ctx.instanceid = c.id
        WHERE u.id = $userid
        AND c.visible = 1";

        if ($role == 'student') {
            $totalCoursesSql .= " and r.shortname = 'student'";
        }

        if ($role == 'teacher') {
            $totalCoursesSql .= " and (r.shortname = 'editingteacher' OR r.shortname = 'teacher')";
        }

        if (!empty($courseid)) {
            $totalCoursesSql .= " and c.id = $courseid";
        }
        if (!empty($coursename)) {
            $totalCoursesSql .= " and c.fullname like '%$coursename%'";
        }

        $mods_count = $DB->get_record_sql($totalCoursesSql);
        $countTotalCourses = $mods_count->total_count;

        $totalpage = 1;
        $currentpage = 1;

        if (!empty($limit)) {
            $totalpage = ($limit > 0) ? ceil($countTotalCourses / $limit) : 1; 
            $currentpage = ($offset / $limit) + 1; // Trang hiện tại
        }

        $dataReturn = [
            'status' => true,
            'message' => 'successfully',
            'data' => [
                'totalpage' => (int) $totalpage,
                'currentpage' => (int) $currentpage,
                'total_course_enrolled' => (int) $countTotalCourses,
                // 'total_course_enrolled' => (int) $total_course_enrolled,
                // 'total_course_enrolled' => count($courseDetails),
                'total_course_completed' => (int) $countCourseCompleted,
                'total_activity_completed' => (int) $countActivityCompleted,
                'total_activity_due' => (int) $countTotalActivityDue,
                'course_details' => $courseDetails
            ]
        ];

        return $dataReturn;
    }

    public static function get_data_basic_course_information_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Trạng thái thành công hay không'),
            'message' => new external_value(PARAM_TEXT, 'Thông báo kết quả'),
            'data' => new external_single_structure([
                'totalpage' => new external_value(PARAM_INT, 'Tổng số trang'),
                'currentpage' => new external_value(PARAM_INT, 'Trang hiện tại'),
                'total_course_enrolled' => new external_value(PARAM_INT, 'Tổng số khóa học đã tham gia'),
                'total_course_completed' => new external_value(PARAM_INT, 'Tổng số khóa học đã hoàn thành'),
                'total_activity_completed' => new external_value(PARAM_INT, 'Tổng số activity đã hoàn thành'),
                'total_activity_due' => new external_value(PARAM_INT, 'Tổng số activity cần hoàn thành'),
                'course_details' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_TEXT, 'ID khóa học'),
                        'coursename' => new external_value(PARAM_TEXT, 'Tên khóa học'),
                        'summary' => new external_value(PARAM_RAW, 'Mô tả khóa học'),
                        'course_image' => new external_value(PARAM_RAW, 'Ảnh khóa học'),
                        'last_access_time' => new external_value(PARAM_TEXT, 'Thời gian truy cập cuối cùng'),
                        'categoryid' => new external_value(PARAM_TEXT, 'ID danh mục'),
                        'categoryname' => new external_value(PARAM_TEXT, 'Tên danh mục'),
                        'view_url' => new external_value(PARAM_TEXT, 'URL khóa học'),
                        'total_activity_completion' => new external_value(PARAM_INT, 'Tổng số hoạt động chưa hoàn thành'),
                        'total_activity_due' => new external_value(PARAM_INT, 'Tổng số hoạt động cần hoàn thành'),
                        'total_activity' => new external_value(PARAM_INT, 'Tổng số hoạt động'),
                        'completionPercentage' => new external_value(PARAM_INT, 'Phần trăm hoàn thành khóa học')
                    ])
                )
            ])
        ]);
    }

    // get data Basic course information for checkmate
    public static function get_data_basic_course_information_checkmate_parameters()
    {
        return new external_function_parameters(
            array(
                'useremail' => new external_value(PARAM_TEXT, 'User Email Moodle', VALUE_DEFAULT, ''),
                'role' => new external_value(PARAM_TEXT, 'role', VALUE_DEFAULT, 'all'),
                'limit' => new external_value(PARAM_INT, 'the number of results to return', VALUE_DEFAULT, 0),
                'offset' => new external_value(PARAM_INT, 'offset the result set by a given amount', VALUE_DEFAULT, 0),
                'courseid' => new external_value(PARAM_INT, 'courseid', VALUE_DEFAULT, 0),
                'coursename' => new external_value(PARAM_TEXT, 'coursename', VALUE_DEFAULT, 0)
            )
        );
    }

    public static function get_data_basic_course_information_checkmate($useremail, $role, $limit, $offset, $courseid, $coursename) {
        global $DB, $OUTPUT;

        if (empty($useremail)) {
            return [
                'status' => false,
                'message' => 'Invalid user.',
                'data' => [
                    'totalpage' => 0,
                    'currentpage' => 1,
                    'courses' => []
                ]
            ];
        }

        $user = $DB->get_record('user', ['email' => $useremail]);

        if (!$user) {
            return [
                'status' => false,
                'message' => 'Invalid user.',
                'data' => [
                    'totalpage' => 0,
                    'currentpage' => 1,
                    'courses' => []
                ]
            ];
        }
    
        // Kiểm tra userid có hợp lệ không
        // if (empty($userid)) {
        //     return [
        //         'status' => false,
        //         'message' => 'Invalid user ID.',
        //         'data' => []
        //     ];
        // }
        $userid = $user->id;
    
        $total_course_enrolled = $DB->count_records('user_enrolments', ['userid' => $userid]);
    
        // $enrolledCourses = enrol_get_users_courses($userid);

        $enrolledCoursesSql = "SELECT DISTINCT c.fullname, c.id, c.summary, f.filename AS course_image, f.contextid AS f_contextid,
                IFNULL(FROM_UNIXTIME(l.timeaccess, '%d-%m-%Y %H:%i:%s'), 'N/A') AS last_access_time,
                IFNULL(FROM_UNIXTIME(c.enddate, '%d-%m-%Y'), 'N/A') AS course_enddate,
                cat.id AS categoryid, cat.name AS categoryname
        FROM mdl_user u
        JOIN mdl_role_assignments ra ON u.id = ra.userid
        JOIN mdl_context ctx ON ra.contextid = ctx.id
        JOIN mdl_role r ON ra.roleid = r.id
        JOIN mdl_course c ON ctx.instanceid = c.id
        LEFT JOIN mdl_files f ON f.contextid = ctx.id AND f.component = 'course' AND f.filearea = 'overviewfiles' AND f.filename <> '.'
        LEFT JOIN mdl_user_lastaccess l ON l.courseid = c.id AND l.userid = u.id
        JOIN mdl_course_categories cat ON c.category = cat.id
        WHERE u.id = $userid";

        if ($role == 'student') {
            $enrolledCoursesSql .= " and r.shortname = 'student'";
        }

        if ($role == 'teacher') {
            $enrolledCoursesSql .= " and (r.shortname = 'editingteacher' OR r.shortname = 'teacher')";
        }

        if (!empty($courseid)) {
            $enrolledCoursesSql .= " and c.id = $courseid";
        }

        if (!empty($coursename)) {
            $enrolledCoursesSql .= " and c.fullname like '%$coursename%'";
        }

        $enrolledCoursesSql .= " ORDER BY l.timeaccess DESC";

        if (!empty($limit)) {
            $enrolledCoursesSql .= " LIMIT $limit OFFSET $offset";
        }

        $enrolledCoursesQuery = $DB->get_records_sql($enrolledCoursesSql);

        $countCourseCompleted = 0;
        $countActivityCompleted = 0;
        $countTotalActivityDue = 0;

        $courseDetails = [];

        if (!empty($enrolledCoursesQuery)) {
            foreach ($enrolledCoursesQuery as $course) {
                $courseId = $course->id;
                $courseData = [
                    'id' => $course->id,
                    'coursename' => $course->fullname,
                    'summary' => $course->summary,
                    'course_image' => (new moodle_url($course->course_image ? $CFG->wwwroot . '/pluginfile.php/' . $course->f_contextid . '/course/overviewfiles/' . $course->course_image : ''))->out(),
                    'last_access_time' => $course->last_access_time,
                    'course_enddate' => $course->course_enddate,
                    'categoryid' => $course->categoryid,
                    'categoryname' => $course->categoryname,
                    'view_url' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(),
                ];

                // Lấy trạng thái hoàn thành các activity trong khóa học
                $get_activities_completion_status = core_completion_external::get_activities_completion_status($courseId, $userid);
                if (!empty($get_activities_completion_status['statuses'])) {
                    $totalActivity = count($get_activities_completion_status['statuses']);
                    $completedActivities = array_filter($get_activities_completion_status['statuses'], function ($status) {
                        return $status['state'] == 1 || $status['state'] == 2;
                    });
                    $numberActivityCompletion = count($completedActivities);
                    $totalActivityDue = $totalActivity - count($completedActivities);
        
                    $countTotalActivityDue += $totalActivityDue;
                    $countActivityCompleted += $numberActivityCompletion;
                }
                $courseData['total_activity_completion'] = (int) $numberActivityCompletion;
                $courseData['total_activity_due'] = (int) $totalActivityDue;
                $courseData['total_activity'] = (int) $totalActivity;
                // Sử dụng try-catch để xử lý trường hợp không có tiêu chí hoàn thành
                try {
                    $get_course_completion_status = core_completion_external::get_course_completion_status($courseId, $userid);

                    if (!empty($get_course_completion_status['completionstatus']['completions'])) {
                        $completionStatus = $get_course_completion_status['completionstatus'];
                        $totalCompletions = count($completionStatus['completions']);
                        $completedCompletions = array_filter($completionStatus['completions'], function ($completion) {
                            return isset($completion['complete']) && $completion['complete'] === true;
                        });
        
                        $completedCount = count($completedCompletions);
        
                        $hasOtherType = array_reduce($completionStatus['completions'], function ($carry, $completion) {
                            return $carry || (int)$completion['type'] !== 4;
                        }, false);

                        if ($hasOtherType) {
                            $completionPercentage = $totalActivity > 0
                                ? round(($numberActivityCompletion / $totalActivity) * 100, 2)
                                : 0;
                        } else {
                            $completionPercentage = $totalCompletions > 0
                                ? round(($completedCount / $totalCompletions) * 100, 2)
                                : 0;
                        }
                    }else{
                        $completionPercentage = $totalActivity > 0
                            ? round(($numberActivityCompletion / $totalActivity) * 100, 2)
                            : 0;
                    }
                } catch (moodle_exception $e) {
                    $completionPercentage = $totalActivity > 0
                        ? round(($numberActivityCompletion / $totalActivity) * 100, 2)
                        : 0;
                    $courseData['completionPercentage'] = (int) $completionPercentage;
                    // $courseDetails[] = $courseData;
                }

                $courseData['completionPercentage'] = (int) $completionPercentage;

                if (isset($completionPercentage) && $completionPercentage == 100) {
                    $countCourseCompleted++;
                }

                $courseDetails[] = $courseData;
            }
        }

        // Tính tổng số trang và trang hiện tại
        $totalCoursesSql = "SELECT COUNT(DISTINCT c.id) as total_count
        FROM mdl_user u
        JOIN mdl_role_assignments ra ON u.id = ra.userid
        JOIN mdl_context ctx ON ra.contextid = ctx.id
        JOIN mdl_role r ON ra.roleid = r.id
        JOIN mdl_course c ON ctx.instanceid = c.id
        WHERE u.id = $userid";

        if ($role == 'student') {
            $totalCoursesSql .= " and r.shortname = 'student'";
        }

        if ($role == 'teacher') {
            $totalCoursesSql .= " and (r.shortname = 'editingteacher' OR r.shortname = 'teacher')";
        }

        if (!empty($courseid)) {
            $totalCoursesSql .= " and c.id = $courseid";
        }

        if (!empty($coursename)) {
            $totalCoursesSql .= " and c.fullname like '%$coursename%'";
        }

        $mods_count = $DB->get_record_sql($totalCoursesSql);
        $countTotalCourses = $mods_count->total_count;

        $totalpage = 1;
        $currentpage = 1;

        if (!empty($limit)) {
            $totalpage = ($limit > 0) ? ceil($countTotalCourses / $limit) : 1; 
            $currentpage = ($offset / $limit) + 1; // Trang hiện tại
        }

        $dataReturn = [
            'status' => true,
            'message' => 'successfully',
            'data' => [
                'totalpage' => (int) $totalpage,
                'currentpage' => (int) $currentpage,
                // 'total_course_enrolled' => (int) $total_course_enrolled,
                // // 'total_course_enrolled' => count($courseDetails),
                // 'total_course_completed' => (int) $countCourseCompleted,
                // 'total_activity_completed' => (int) $countActivityCompleted,
                // 'total_activity_due' => (int) $countTotalActivityDue,
                'courses' => $courseDetails
            ]
        ];

        return $dataReturn;
    }

    public static function get_data_basic_course_information_checkmate_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Trạng thái thành công hay không'),
            'message' => new external_value(PARAM_TEXT, 'Thông báo kết quả'),
            'data' => new external_single_structure([
                'totalpage' => new external_value(PARAM_INT, 'Tổng số trang'),
                'currentpage' => new external_value(PARAM_INT, 'Trang hiện tại'),
                // 'total_course_enrolled' => new external_value(PARAM_INT, 'Tổng số khóa học đã tham gia'),
                // 'total_course_completed' => new external_value(PARAM_INT, 'Tổng số khóa học đã hoàn thành'),
                // 'total_activity_completed' => new external_value(PARAM_INT, 'Tổng số activity đã hoàn thành'),
                // 'total_activity_due' => new external_value(PARAM_INT, 'Tổng số activity cần hoàn thành'),
                'courses' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_TEXT, 'ID khóa học'),
                        'coursename' => new external_value(PARAM_TEXT, 'Tên khóa học'),
                        'summary' => new external_value(PARAM_RAW, 'Mô tả khóa học'),
                        'course_image' => new external_value(PARAM_RAW, 'Ảnh khóa học'),
                        'last_access_time' => new external_value(PARAM_TEXT, 'Thời gian truy cập cuối cùng'),
                        'course_enddate' => new external_value(PARAM_TEXT, 'Thời gian kết thúc khóa học'),
                        'categoryid' => new external_value(PARAM_TEXT, 'ID danh mục'),
                        'categoryname' => new external_value(PARAM_TEXT, 'Tên danh mục'),
                        'view_url' => new external_value(PARAM_TEXT, 'URL khóa học'),
                        'total_activity_completion' => new external_value(PARAM_INT, 'Tổng số hoạt động chưa hoàn thành'),
                        'total_activity_due' => new external_value(PARAM_INT, 'Tổng số hoạt động cần hoàn thành'),
                        'total_activity' => new external_value(PARAM_INT, 'Tổng số hoạt động'),
                        'completionPercentage' => new external_value(PARAM_INT, 'Phần trăm hoàn thành khóa học')
                    ])
                )
            ])
        ]);
    }


    public static function get_content_course_checkmate_parameters()
    {
        return new external_function_parameters(
            array(
                'useremail' => new external_value(PARAM_TEXT, 'User Email Moodle', VALUE_DEFAULT, ''),
                'courseid' => new external_value(PARAM_INT, 'courseid', VALUE_DEFAULT, 0),
                // 'page' => new external_value(PARAM_INT, 'Page number', VALUE_DEFAULT, 1),
                // 'perpage' => new external_value(PARAM_INT, 'Items per page', VALUE_DEFAULT, 10),
            )
        );
    }

    public static function get_content_course_checkmate($useremail, $courseid)
    {
        global $DB, $CFG;

        require_once($CFG->libdir . '/completionlib.php');

        if (empty($courseid)) {
            return [
                'status' => false,
                'message' => 'Invalid course id.',
                'data' => [
                    'course' => self::get_empty_course_structure(),
                    'topics' => []
                ]
            ];
        }

        if (empty($useremail)) {
            return [
                'status' => false,
                'message' => 'Invalid user.',
                'data' => [
                    'course' => self::get_empty_course_structure(),
                    'topics' => []
                ]
            ];
        }

        // Lấy thông tin user từ email
        $user = $DB->get_record('user', ['email' => $useremail]);
        if (!$user) {
            return [
                'status' => false,
                'message' => 'Invalid user.',
                'data' => [
                    'course' => self::get_empty_course_structure(),
                    'topics' => []
                ]
            ];
        }
        $userid = $user->id;

        // Lấy thông tin khóa học
        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname, summary, idnumber');
        
        if (!$course) {
            return [
                'status' => false,
                'message' => 'Course not found.',
                'data' => [
                    'course' => self::get_empty_course_structure(),
                    'topics' => []
                ]
            ];
        }

        $course_info = self::get_data_basic_course_information_checkmate($useremail, 'all', 0, 0, $courseid, 0);
        
        // Lấy danh sách sections của course
        $sections = core_course_external::get_course_contents($courseid);

        $result = [];

        foreach ($sections as $section) {
            if ($section['section'] == 0) continue; // Bỏ qua section 0 (General)

            $sectionid = $section['id'];
            $sectionname = $section['name'];

            $activities = [];
            $total_activity = count($section['modules']); // Tổng số activity trong section
            $total_activity_completion = 0; // Đếm số activity đã hoàn thành

            foreach ($section['modules'] as $module) {
                // Lấy trạng thái hoàn thành của activity
                $completion = $DB->get_record('course_modules_completion', [
                    'coursemoduleid' => $module['id'],
                    'userid' => $userid
                ]);

                $moduleDetail = self::get_detail_module($module['id'], $module['modname']);

                $moduleDetailDecode = json_decode($moduleDetail['data']);

                // Kiểm tra trạng thái hoàn thành (1 hoặc 2)
                $is_completed = ($completion && in_array($completion->completionstate, [1, 2])) ? true : false;
            
                if ($is_completed) {
                    $total_activity_completion++;
                }

                // Xử lý availability (điều kiện hoạt động)
                $availability = [];
                if (!empty($module['availability'])) {
                    // Chuyển đổi availability thành mảng từ chuỗi JSON
                    $availability_data = json_decode($module['availability'], true);
                    
                    if ($availability_data && isset($availability_data['c'])) {
                        foreach ($availability_data['c'] as $condition) {
                            if ($condition['type'] === 'completion' && isset($condition['cm'])) {

                                try {
                                    $required_module = core_course_external::get_course_module($condition['cm']);
                                    $availability[] = [
                                        'id' => $required_module['cm']->id,
                                        'name' => $required_module['cm']->name ?? 'Unknown',
                                        'modname' => $required_module['cm']->modname ?? 'Unknown'
                                    ];
                                } catch (Exception $e) {
                                    // Nếu module không tồn tại, ghi log và bỏ qua
                                    debugging("Module in availability not found: CMID = {$condition['cm']}. Error: " . $e->getMessage());
                                    // var_dump("Module in availability not found: CMID = {$condition['cm']}. Error: " . $e->getMessage());die;
                                }
                            }
                        }
                    }
                }

                try {
                    $detailActivity = core_course_external::get_course_module($module['id']);
                } catch (Exception $e) {
                    debugging("Module not found for ID = {$module['id']}. Error: " . $e->getMessage());
                    // var_dump("Module not found for ID = {$module['id']}. Error: " . $e->getMessage());die;
                    continue; // Bỏ qua module không tồn tại
                }
                
                $detailModule = json_decode(self::get_detail_module($module['id'], $module['modname'])['data']);

                $activities[] = [
                    'id' => $module['id'],
                    'name' => $module['name'],
                    'description' => $moduleDetailDecode->intro,
                    'modname' => $module['modname'],
                    'completed' => $is_completed,
                    'availability' => $availability, // Thêm thông tin về availability
                    'visible' => $detailActivity['cm']->visible,
                    'completiongradeitemnumber' => $detailActivity['cm']->completiongradeitemnumber,
                    'completionview' => $detailActivity['cm']->completionview,
                    'completionexpected' => $detailActivity['cm']->completionexpected,
                    'completionpassgrade' => $detailActivity['cm']->completionpassgrade,
                    'completionminattempts' => $detailModule->completionminattempts,
                    'grade' => $detailActivity['cm']->grade ?? 0,
                    'gradepass' => $detailActivity['cm']->gradepass ?? 0,
                ];
                

                // if(isset($detailActivity['cm']->grade)){
                //     $activities[]['grade'] = $detailActivity['cm']->grade;
                // }

                // if(isset($detailActivity['cm']->gradepass)){
                //     $activities[]['gradepass'] = $detailActivity['cm']->gradepass;
                // }
            }

            // Tính phần trăm hoàn thành
            $completion_percentage = ($total_activity > 0) ? round(($total_activity_completion / $total_activity) * 100, 2) : 0;

            $result[] = [
                'id' => $sectionid,
                'name' => $sectionname,
                'total_activity' => $total_activity,
                'total_activity_completion' => $total_activity_completion,
                'completion_percentage' => $completion_percentage,
                'activities' => $activities
            ];
        }

        $dataResponse = [
            'status' => true,
            'message' => 'Data retrieved successfully.',
            'data' => [
                'course' => $course_info['data']['courses'][0],
                'topics' => $result
            ]
        ];
        // var_dump('<pre>');
        // var_dump($dataResponse);die;

        return $dataResponse;
    }

    public static function get_content_course_checkmate_returns()
    {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Trạng thái thành công hay không'),
            'message' => new external_value(PARAM_TEXT, 'Thông báo kết quả'),
            'data' => new external_single_structure([
                'course' => new external_single_structure([
                    'id' => new external_value(PARAM_TEXT, 'ID khóa học'),
                    'coursename' => new external_value(PARAM_TEXT, 'Tên khóa học'),
                    'summary' => new external_value(PARAM_RAW, 'Mô tả khóa học'),
                    'course_image' => new external_value(PARAM_RAW, 'Ảnh khóa học'),
                    'last_access_time' => new external_value(PARAM_TEXT, 'Thời gian truy cập cuối cùng'),
                    'course_enddate' => new external_value(PARAM_TEXT, 'Thời gian kết thúc khóa học'),
                    'categoryid' => new external_value(PARAM_TEXT, 'ID danh mục'),
                    'categoryname' => new external_value(PARAM_TEXT, 'Tên danh mục'),
                    'view_url' => new external_value(PARAM_TEXT, 'URL khóa học'),
                    'total_activity_completion' => new external_value(PARAM_INT, 'Tổng số hoạt động chưa hoàn thành'),
                    'total_activity_due' => new external_value(PARAM_INT, 'Tổng số hoạt động cần hoàn thành'),
                    'total_activity' => new external_value(PARAM_INT, 'Tổng số hoạt động'),
                    'completionPercentage' => new external_value(PARAM_INT, 'Phần trăm hoàn thành khóa học')
                ], 'Thông tin khóa học', VALUE_OPTIONAL),
                'topics' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'Section ID'),
                        'name' => new external_value(PARAM_RAW, 'Section Name'),
                        'total_activity' => new external_value(PARAM_INT, 'Total activities in section'),
                        'total_activity_completion' => new external_value(PARAM_INT, 'Total completed activities in section'),
                        'completion_percentage' => new external_value(PARAM_FLOAT, 'Completion percentage'),
                        'activities' => new external_multiple_structure(
                            new external_single_structure([
                                'id' => new external_value(PARAM_INT, 'Activity ID'),
                                'name' => new external_value(PARAM_RAW, 'Activity Name'),
                                'description' => new external_value(PARAM_RAW, 'Activity description'),
                                'modname' => new external_value(PARAM_RAW, 'Activity Type'),
                                'completed' => new external_value(PARAM_BOOL, 'Activity Completion Status'),
                                'availability' => new external_multiple_structure(
                                    new external_single_structure([
                                        'id' => new external_value(PARAM_INT, 'Required Activity ID'),
                                        'name' => new external_value(PARAM_RAW, 'Required Activity Name'),
                                        'modname' => new external_value(PARAM_RAW, 'Required Activity Type')
                                    ])
                                ),
                                'visible' => new external_value(PARAM_RAW, 'Trạng thái hiển thị của activity'),
                                'completiongradeitemnumber' => new external_value(PARAM_RAW, 'Yêu cầu điểm số để hoàn thành', VALUE_OPTIONAL),
                                'completionview' => new external_value(PARAM_RAW, 'Yêu cầu xem activity để hoàn thành', VALUE_OPTIONAL),
                                'completionexpected' => new external_value(PARAM_RAW, 'Ngày mong đợi hoàn thành', VALUE_OPTIONAL),
                                'completionpassgrade' => new external_value(PARAM_RAW, 'Yêu cầu đạt điểm đạt chuẩn để hoàn thành', VALUE_OPTIONAL),
                                'completionminattempts' => new external_value(PARAM_RAW, 'Yêu cầu đạt điểm nộp bài để hoàn thành', VALUE_OPTIONAL),
                                'grade' => new external_value(PARAM_RAW, 'Điểm của học viên', VALUE_OPTIONAL),
                                'gradepass' => new external_value(PARAM_RAW, 'Điểm tối thiểu để qua', VALUE_OPTIONAL),
                            ])
                        )
                    ]), 'Danh sách chủ đề', VALUE_OPTIONAL
                )
            ])
        ]);
    }

    private static function get_empty_course_structure() {
        return [
            'id' => '',
            'coursename' => '',
            'summary' => '',
            'course_image' => '',
            'last_access_time' => '',
            'course_enddate' => '',
            'categoryid' => '',
            'categoryname' => '',
            'view_url' => '',
            'total_activity_completion' => 0,
            'total_activity_due' => 0,
            'total_activity' => 0,
            'completionPercentage' => 0
        ];
    }
    
    // block html(text)
    public static function create_content_block_html_parameters()
    {
        return new external_function_parameters (
            array(
                'shortname' => new external_value(PARAM_TEXT, 'Course shortname', VALUE_REQUIRED, '', NULL_NOT_ALLOWED),
                // 'url_images' => new external_multiple_structure(new external_value(PARAM_RAW, 'content html block',
                //         VALUE_REQUIRED, '', NULL_NOT_ALLOWED), 'Array of content html block'),
                'slide_convert' => new external_value(PARAM_RAW, 'Slide Content', VALUE_REQUIRED, '', NULL_NOT_ALLOWED),
            )
        );
    }

    public static function create_content_block_html($shortname, $slide_convert)
    {
        global $DB, $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $params = self::validate_parameters(
            self::create_content_block_html_parameters(),
            ['slide_convert' => $slide_convert, 'shortname' => $shortname]
        );

        $transaction = $DB->start_delegated_transaction();
        
        try {
            $slide_convert_decode = json_decode($slide_convert, true);
            $totalSlides = count($slide_convert_decode);
            $sliderWidth = $totalSlides * 100;

            $existing_course = $DB->get_record('course', ['shortname' => $shortname]);
            if (!$existing_course) {
                throw new Exception('A course with the shortname "' . $shortname . '" does not exist.');
            }

            $courseId = $existing_course->id;
            $context_course = context_course::instance($courseId);
            if (!$context_course) {
                throw new Exception('No context found for the given courseId.');
            }

            $context_course_id = $context_course->id;

            $html = '<div style="position: relative; width: 100%; max-width: 1200px; height: 600px; margin: 0 auto; overflow: hidden;">';
            $html .= '<div class="slider" style="display: flex; width: ' . $sliderWidth . '%; height: 100%; transition: transform 0.5s ease-in-out;">';

            foreach ($slide_convert_decode as $value) {
                $image_url = $value['image_url'];
                $audio_url = $value['audio_url'];
                $html .= '<div style="width: ' . (100 / $totalSlides) . '%; height: 100%; position: relative;">';
                $html .= '<img src="' . $image_url . '" alt="Slide" style="width: 100%; height: 100%; object-fit: cover; object-position: center;">';
                $html .= '<audio class="slide-audio">';
                $html .= '<source src="' . $audio_url . '" type="audio/mpeg">';
                $html .= '</audio>';
                $html .= '</div>';
            }
            
            $html .= '</div>';

            // Navigation buttons
            $html .= '<button class="prev" style="position: absolute; top: 50%; left: 20px; transform: translateY(-50%); background: rgba(0,0,0,0.5); color: white; width: 40px; height: 40px; border: none; border-radius: 50%; cursor: pointer;">←</button>';
            $html .= '<button class="next" style="position: absolute; top: 50%; right: 20px; transform: translateY(-50%); background: rgba(0,0,0,0.5); color: white; width: 40px; height: 40px; border: none; border-radius: 50%; cursor: pointer;">→</button>';

            // Dots navigation
            $html .= '<div class="dots" style="position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%); display: flex; gap: 10px;">';
            for ($i = 0; $i < $totalSlides; $i++) {
                $activeClass = ($i == 0) ? ' active' : '';
                $html .= '<span class="dot' . $activeClass . '" style="width: 12px; height: 12px; border-radius: 50%; background: rgba(255,255,255,0.5); border: 2px solid white;"></span>';
            }
            $html .= '</div>';

            // Audio control button
            $html .= '<button id="audioControl" class="audio-button" style="position: absolute; bottom: 20px; left: 20px; background: white; border: 2px solid #00BFB3; border-radius: 20px; padding: 10px 20px; font-size: 16px; cursor: pointer;">Play Audio</button>';
            $html .= '</div>';
            $html .= '  
                <script>
                    document.addEventListener("DOMContentLoaded", function() {
                        let currentSlide = 0;
                        const slider = document.querySelector(".slider");
                        const slides = document.querySelectorAll(".slider > div");
                        const dots = document.querySelectorAll(".dot");
                        const prevBtn = document.querySelector(".prev");
                        const nextBtn = document.querySelector(".next");
                        const slideCount = slides.length;
                        const audioPlayers = document.querySelectorAll(".slide-audio");
                        const audioButton = document.getElementById("audioControl");
                        let isPlaying = false;
                
                        function updateSlider() {
                            slider.style.transform = `translateX(-${(currentSlide * 100) / slideCount}%)`;
                            dots.forEach((dot, index) => {
                                dot.style.background = index === currentSlide ? "white" : "rgba(255, 255, 255, 0.5)";
                            });
                            // Pause all audio players
                            audioPlayers.forEach(player => player.pause());
                            if (isPlaying) {
                                audioPlayers[currentSlide].play();
                            }
                        }
                
                        function toggleAudio() {
                            const currentAudio = audioPlayers[currentSlide];
                            if (isPlaying) {
                                currentAudio.pause();
                                audioButton.textContent = "Play Audio";
                            } else {
                                currentAudio.play();
                                audioButton.textContent = "Pause Audio";
                            }
                            isPlaying = !isPlaying;
                        }
                
                        nextBtn.addEventListener("click", function() {
                            currentSlide = (currentSlide + 1) % slideCount;
                            updateSlider();
                        });
                
                        prevBtn.addEventListener("click", function() {
                            currentSlide = (currentSlide - 1 + slideCount) % slideCount;
                            updateSlider();
                        });
                
                        dots.forEach((dot, index) => {
                            dot.addEventListener("click", function() {
                                currentSlide = index;
                                updateSlider();
                            });
                        });
                
                        [prevBtn, nextBtn].forEach(btn => {
                            btn.addEventListener("mouseover", function() {
                                this.style.background = "rgba(0, 0, 0, 0.8)";
                            });
                            btn.addEventListener("mouseout", function() {
                                this.style.background = "rgba(0, 0, 0, 0.5)";
                            });
                        });

                        audioButton.addEventListener("click", toggleAudio);
                        audioButton.addEventListener("mouseover", function() {
                            this.style.backgroundColor = "#f8f8f8";
                        });
                        audioButton.addEventListener("mouseout", function() {
                            this.style.backgroundColor = "white";
                        });

                        updateSlider();
                    });
                </script>
            ';

            $output = (object) [
                'text' => $html,
                'title' => "",
                'classes' => "",
                'format' => FORMAT_HTML,
            ];

            $blockinstance = (object) [
                'blockname' => 'html',
                'parentcontextid' => $context_course_id,
                'showinsubcontexts' => 0,
                'pagetypepattern' => 'course-view-*',
                'defaultregion' => 'side-pre',
                'defaultweight' => 0,
                'configdata' => base64_encode(serialize($output)),
                'timecreated' => time(),
                'timemodified' => time(),
            ];

            $blockinstance->id = $DB->insert_record('block_instances', $blockinstance);
            $transaction->allow_commit();

            return ['status' => true, 'message' => 'Data retrieved successfully.'];
        } catch (Exception $e) {
            if (!empty($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            return ['status' => false, 'message' => $e->getMessage()];
        }
    }

    public static function create_content_block_html_returns()
    {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Trạng thái thành công hay không'),
            'message' => new external_value(PARAM_TEXT, 'Thông báo kết quả')
        ]);
    }

    public static function create_activity_book_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'ID của khóa học'),
            'name' => new external_value(PARAM_TEXT, 'Tên của activity'),
            'section' => new external_value(PARAM_INT, 'Số thứ tự của section để thêm activity', VALUE_DEFAULT, 0),
            'description' => new external_value(PARAM_RAW, 'mô tả book', VALUE_DEFAULT, ''),
            'chapters' => new external_value(PARAM_RAW, 'book chapter content', VALUE_DEFAULT, ''),
            'slideCode' => new external_value(PARAM_TEXT, 'Code content slide', VALUE_DEFAULT, ''),
        ]);
    }

    public static function create_activity_book($courseid, $name, $section = 0, $description = '', $chapters = '', $slideCode = '') {
        global $DB, $USER;

        // Validate the parameters.
        $params = self::validate_parameters(self::create_activity_book_parameters(), [
            'courseid' => $courseid,
            'name' => $name,
            'section' => $section,
            // 'completioncmid' => $completioncmid
        ]);
        
        $transaction = $DB->start_delegated_transaction();

        try {

            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    
            $module = $DB->get_record('modules', ['name' => 'book'], '*', MUST_EXIST);
            $moduleid = $module->id;
            //create an object with all of the neccesary information to build a quiz
            $myBook = new stdClass();
            $myBook->modulename='book';
            $myBook->module = $moduleid;
            $myBook->name = $name;
            $myBook->introformat = FORMAT_HTML;
            $myBook->course = $courseid;
            $myBook->section = $section;
            $myBook->numbering = 1;
            $myBook->navstyle = 1;
            $myBook->customtitles = 0;
            $myBook->revision = 0;
            $myBook->visible = 1;
            
            $myBook->completion = 2; 
            $myBook->completionview = 1;

            if (plugin_supports('mod', 'book', FEATURE_MOD_INTRO, true)) {
                $editor = 'introeditor';
                $draftid = file_get_unused_draft_itemid();
                $myBook->$editor = array(
                    'text' => $description,
                    'format' => FORMAT_HTML,
                    'itemid' => $draftid
                );
            } else {
                $myBook->intro = $description;
            }
            try {
                $created_moduleinfo = add_moduleinfo($myBook, $course);
            } catch (moodle_exception $e) {
                debugging('Lỗi khi tạo activity: ' . $e->getMessage(), DEBUG_DEVELOPER);
                throw new moodle_exception('errorcreatingactivity', 'local_yourplugin', '', $e->getMessage());
            }

            $decode_chapters = json_decode($chapters, true);

            if($slideCode){
                $postdata = array(
                    'code' => $slideCode,
                );
            
                $get_data_slide_by_code = get_data_slide_by_code($postdata);
                
                if($get_data_slide_by_code['status']){
                    foreach ($get_data_slide_by_code['data'] as $key => $value) {
                        try {
                            $title = 'Chapter ' . ($value['slideIndex'] + 1);
                            $content = stripslashes($value['html']);
                    
                            $dataChapter = new stdClass();
                            $dataChapter->bookid = $created_moduleinfo->instance;
                            $dataChapter->pagenum = $key;
                            $dataChapter->subchapter = 0;
                            $dataChapter->title = $title;
                            $dataChapter->content = $content;
                            $dataChapter->contentformat = FORMAT_HTML;
                            $dataChapter->hidden = 0;
                            $dataChapter->timecreated = time();
                            $dataChapter->timemodified = time();
                    
                            $id = $DB->insert_record('book_chapters', $dataChapter);
                    
                            if (!$id) {
                                throw new Exception("Insert failed at index $key");
                            }
                        } catch (Exception $e) {
                            throw new Exception("Error: " . $e->getMessage() . "<br>");
                        }
                    }
                }
            }else{
                if($decode_chapters){
                    foreach($decode_chapters as $key => $chapter){
                        $title = $chapter['title'];
                        $content = $chapter['content'];
                        $subchapter = $chapter['subchapter'];
    
                        $isSubchapter = 0;
    
                        if($subchapter == "true"){
                            $isSubchapter = 1;
                        }
    
                        $dataChapter = new stdClass();
                        $dataChapter->bookid = $created_moduleinfo->instance;
                        $dataChapter->pagenum = $key;
                        $dataChapter->subchapter = $isSubchapter;
                        $dataChapter->title = $title;
                        $dataChapter->content = $content;
                        $dataChapter->contentformat = FORMAT_HTML;
                        $dataChapter->hidden = 0;
                        $dataChapter->timecreated = time();
                        $dataChapter->timemodified = time();
    
                        $id = $DB->insert_record('book_chapters', $dataChapter);
                    }
                }
            }

            $transaction->allow_commit();

            return [
                'modulename' => $created_moduleinfo->modulename,
                'cmid' => $created_moduleinfo->coursemodule,
                'instanceid' => $created_moduleinfo->instance,
                'name' => $created_moduleinfo->name,
                'course' => $created_moduleinfo->course,
                'section' => $created_moduleinfo->section
            ];
        } catch (Exception $e) {
            if (!empty($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            return [
                'modulename' => $created_moduleinfo->modulename,
                'cmid' => $created_moduleinfo->coursemodule,
                'instanceid' => $created_moduleinfo->instance,
                'name' => $created_moduleinfo->name,
                'course' => $created_moduleinfo->course,
                'section' => $created_moduleinfo->section
            ];
        }
    }

    public static function create_activity_book_returns() {
        return new external_single_structure([
            'modulename' => new external_value(PARAM_TEXT, 'Module name'),
            'cmid' => new external_value(PARAM_INT, 'Course module ID của label'),
            'instanceid' => new external_value(PARAM_INT, 'Instance ID của label'),
            'name' => new external_value(PARAM_TEXT, 'Tên của label'),
            'course' => new external_value(PARAM_INT, 'Course id'),
            'section' => new external_value(PARAM_INT, 'Số thứ tự của section mà label được thêm vào')
        ]);
    }

    public static function get_course_by_category_ids_parameters()
    {
        return new external_function_parameters(
            array(
                'categoryids' => new external_value(PARAM_TEXT, 'Category Ids'),
                'userid' => new external_value(PARAM_INT, 'User id', VALUE_DEFAULT, 0),
                'perpage' => new external_value(PARAM_INT, 'the number of results to return', VALUE_DEFAULT, 0),
                'page' => new external_value(PARAM_INT, 'offset the result set by a given amount', VALUE_DEFAULT, 0)
            )
        );
    }
    public static function get_course_by_category_ids($categoryids, $userid, $perpage, $page)
    {
        global $DB, $CFG;

        $params = self::validate_parameters(self::get_course_by_category_ids_parameters(), [
            'categoryids' => $categoryids,
            'perpage' => $perpage,
            'page' => $page
        ]);

        $categoryIdsArray = explode(',', $params['categoryids']);
        $courses = [];

        $enrolledCourses = $userid ? enrol_get_users_courses($userid, true, ['id']) : [];
        $enrolledCourseIds = array_column($enrolledCourses, 'id'); // Lấy danh sách ID đã tham gia

        foreach ($categoryIdsArray as $categoryId) {
            $get_courses_by_field = core_course_external::get_courses_by_field('category', $categoryId);
            if (!empty($get_courses_by_field['courses'])) {
                foreach ($get_courses_by_field['courses'] as $course) {

                    if (in_array($course['id'], $enrolledCourseIds)) {
                        continue;
                    }

                    // Thêm field view_url vào từng course
                    $course['view_url'] = (new moodle_url('/course/view.php', ['id' => $course['id']]))->out();
                    
                    $course['course_image'] = '';

                    // Kiểm tra nếu overviewfiles có dữ liệu
                    if (!empty($course['overviewfiles'])) {
                        foreach ($course['overviewfiles'] as $file) {
                            if (!empty($file['fileurl']) && strpos($file['mimetype'], 'image') !== false) {
                                // Thay thế 'webservice' trong URL để hiển thị trực tiếp
                                $course['course_image'] = str_replace('/webservice', '', $file['fileurl']);
                                break; // Lấy ảnh đầu tiên
                            }
                        }
                    }

                    $courses[] = $course;
                }
            }
        }

        $totalCourses = count($courses);

        // Nếu perpage = 0 hoặc page = 0, lấy toàn bộ danh sách
        if ($perpage == 0 || $page == 0) {
            $pagedCourses = $courses;
            $totalPages = 1;
            $currentPage = 1;
        } else {
            $totalPages = ceil($totalCourses / $perpage);
            
            // Đảm bảo page >= 1
            $page = max(1, $page);

            // Tính offset
            $offset = ($page - 1) * $perpage;

            // Cắt danh sách theo pagination
            $pagedCourses = array_slice($courses, $offset, $perpage);
            $currentPage = $page;
        }

        $dataReturn = [
            'status' => true,
            'message' => 'Data retrieved successfully.',
            'data' => [
                'totalpage' => $totalPages,
                'currentpage' => $currentPage,
                'courses' => $pagedCourses
            ]
        ];

        return $dataReturn;
    }

    public static function get_course_by_category_ids_returns()
    {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Trạng thái thành công hay không'),
            'message' => new external_value(PARAM_TEXT, 'Thông báo kết quả'),
            'data' => new external_single_structure([
                'totalpage' => new external_value(PARAM_INT, 'Tổng số trang'),
                'currentpage' => new external_value(PARAM_INT, 'Trang hiện tại'),
                'courses' => new external_multiple_structure(self::get_course_structure(false), 'Course')
            ])
        ]);
    }

    public static function check_enrolled_user_course_by_courseids_parameters()
    {
        return new external_function_parameters(
            array(
                'courseids' => new external_value(PARAM_TEXT, 'Course Ids'),
                'userid' => new external_value(PARAM_INT, 'User id', VALUE_DEFAULT, 0),
                'perpage' => new external_value(PARAM_INT, 'the number of results to return', VALUE_DEFAULT, 0),
                'page' => new external_value(PARAM_INT, 'offset the result set by a given amount', VALUE_DEFAULT, 0)
            )
        );
    }
    public static function check_enrolled_user_course_by_courseids($courseids, $userid, $perpage, $page)
    {
        global $DB, $CFG;

        $params = self::validate_parameters(self::check_enrolled_user_course_by_courseids_parameters(), [
            'courseids' => $courseids,
            'userid' => $userid
        ]);

        $courseIdsArray = explode(',', $params['courseids']);
        $courses = [];

        foreach ($courseIdsArray as $courseId) {
            $get_courses_by_field = core_course_external::get_courses_by_field('id', $courseId);
            if (!empty($get_courses_by_field['courses'])) {
                foreach ($get_courses_by_field['courses'] as $course) {
                    $enrolledCourses = $userid ? enrol_get_users_courses($userid, true, ['id']) : [];
                    $enrolledCourseIds = array_column($enrolledCourses, 'id'); // Lấy danh sách ID đã tham gia

                    if (in_array($course['id'], $enrolledCourseIds)) {
                        continue;
                    }

                    // Thêm field view_url vào từng course
                    $course['view_url'] = (new moodle_url('/course/view.php', ['id' => $course['id']]))->out();
                    
                    $course['course_image'] = '';

                    // Kiểm tra nếu overviewfiles có dữ liệu
                    if (!empty($course['overviewfiles'])) {
                        foreach ($course['overviewfiles'] as $file) {
                            if (!empty($file['fileurl']) && strpos($file['mimetype'], 'image') !== false) {
                                // Thay thế 'webservice' trong URL để hiển thị trực tiếp
                                $course['course_image'] = str_replace('/webservice', '', $file['fileurl']);
                                break; // Lấy ảnh đầu tiên
                            }
                        }
                    }

                    $courses[] = $course;
                }
            }
        }

        $totalCourses = count($courses);

        // Nếu perpage = 0 hoặc page = 0, lấy toàn bộ danh sách
        if ($perpage == 0 || $page == 0) {
            $pagedCourses = $courses;
            $totalPages = 1;
            $currentPage = 1;
        } else {
            $totalPages = ceil($totalCourses / $perpage);
            
            // Đảm bảo page >= 1
            $page = max(1, $page);

            // Tính offset
            $offset = ($page - 1) * $perpage;

            // Cắt danh sách theo pagination
            $pagedCourses = array_slice($courses, $offset, $perpage);
            $currentPage = $page;
        }

        $dataReturn = [
            'status' => true,
            'message' => 'Data retrieved successfully.',
            'data' => [
                'totalpage' => $totalPages,
                'currentpage' => $currentPage,
                'courses' => $pagedCourses
            ]
        ];

        return $dataReturn;
    }

    public static function check_enrolled_user_course_by_courseids_returns()
    {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Trạng thái thành công hay không'),
            'message' => new external_value(PARAM_TEXT, 'Thông báo kết quả'),
            'data' => new external_single_structure([
                'totalpage' => new external_value(PARAM_INT, 'Tổng số trang'),
                'currentpage' => new external_value(PARAM_INT, 'Trang hiện tại'),
                'courses' => new external_multiple_structure(self::get_course_structure(false), 'Course')
            ])
        ]);
    }

    public static function get_course_structure($onlypublicdata = true) {
        $coursestructure = array(
            'id' => new external_value(PARAM_INT, 'course id'),
            'fullname' => new external_value(PARAM_RAW, 'course full name'),
            'displayname' => new external_value(PARAM_RAW, 'course display name'),
            'shortname' => new external_value(PARAM_RAW, 'course short name'),
            'courseimage' => new external_value(PARAM_URL, 'Course image', VALUE_OPTIONAL),
            'categoryid' => new external_value(PARAM_INT, 'category id'),
            'categoryname' => new external_value(PARAM_RAW, 'category name'),
            'sortorder' => new external_value(PARAM_INT, 'Sort order in the category', VALUE_OPTIONAL),
            'summary' => new external_value(PARAM_RAW, 'summary'),
            'summaryformat' => new external_format_value('summary'),
            'summaryfiles' => new external_files('summary files in the summary field', VALUE_OPTIONAL),
            'overviewfiles' => new external_files('additional overview files attached to this course'),
            'showactivitydates' => new external_value(PARAM_BOOL, 'Whether the activity dates are shown or not'),
            'showcompletionconditions' => new external_value(PARAM_BOOL,
                'Whether the activity completion conditions are shown or not'),
            'contacts' => new external_multiple_structure(
                new external_single_structure(
                    array(
                        'id' => new external_value(PARAM_INT, 'contact user id'),
                        'fullname'  => new external_value(PARAM_NOTAGS, 'contact user fullname'),
                    )
                ),
                'contact users'
            ),
            'enrollmentmethods' => new external_multiple_structure(
                new external_value(PARAM_PLUGIN, 'enrollment method'),
                'enrollment methods list'
            ),
            'customfields' => new external_multiple_structure(
                new external_single_structure(
                    array(
                        'name' => new external_value(PARAM_RAW, 'The name of the custom field'),
                        'shortname' => new external_value(PARAM_RAW,
                            'The shortname of the custom field - to be able to build the field class in the code'),
                        'type'  => new external_value(PARAM_ALPHANUMEXT,
                            'The type of the custom field - text field, checkbox...'),
                        'valueraw' => new external_value(PARAM_RAW, 'The raw value of the custom field'),
                        'value' => new external_value(PARAM_RAW, 'The value of the custom field'),
                    )
                ), 'Custom fields', VALUE_OPTIONAL),
            'view_url' => new external_value(PARAM_TEXT, 'URL khóa học'),
            'course_image' => new external_value(PARAM_RAW, 'Ảnh khóa học'),
        );

        if (!$onlypublicdata) {
            $extra = array(
                'idnumber' => new external_value(PARAM_RAW, 'Id number', VALUE_OPTIONAL),
                'format' => new external_value(PARAM_PLUGIN, 'Course format: weeks, topics, social, site,..', VALUE_OPTIONAL),
                'showgrades' => new external_value(PARAM_INT, '1 if grades are shown, otherwise 0', VALUE_OPTIONAL),
                'newsitems' => new external_value(PARAM_INT, 'Number of recent items appearing on the course page', VALUE_OPTIONAL),
                'startdate' => new external_value(PARAM_INT, 'Timestamp when the course start', VALUE_OPTIONAL),
                'enddate' => new external_value(PARAM_INT, 'Timestamp when the course end', VALUE_OPTIONAL),
                'maxbytes' => new external_value(PARAM_INT, 'Largest size of file that can be uploaded into', VALUE_OPTIONAL),
                'showreports' => new external_value(PARAM_INT, 'Are activity report shown (yes = 1, no =0)', VALUE_OPTIONAL),
                'visible' => new external_value(PARAM_INT, '1: available to student, 0:not available', VALUE_OPTIONAL),
                'groupmode' => new external_value(PARAM_INT, 'no group, separate, visible', VALUE_OPTIONAL),
                'groupmodeforce' => new external_value(PARAM_INT, '1: yes, 0: no', VALUE_OPTIONAL),
                'defaultgroupingid' => new external_value(PARAM_INT, 'default grouping id', VALUE_OPTIONAL),
                'enablecompletion' => new external_value(PARAM_INT, 'Completion enabled? 1: yes 0: no', VALUE_OPTIONAL),
                'completionnotify' => new external_value(PARAM_INT, '1: yes 0: no', VALUE_OPTIONAL),
                'lang' => new external_value(PARAM_SAFEDIR, 'Forced course language', VALUE_OPTIONAL),
                'theme' => new external_value(PARAM_PLUGIN, 'Fame of the forced theme', VALUE_OPTIONAL),
                'marker' => new external_value(PARAM_INT, 'Current course marker', VALUE_OPTIONAL),
                'legacyfiles' => new external_value(PARAM_INT, 'If legacy files are enabled', VALUE_OPTIONAL),
                'calendartype' => new external_value(PARAM_PLUGIN, 'Calendar type', VALUE_OPTIONAL),
                'timecreated' => new external_value(PARAM_INT, 'Time when the course was created', VALUE_OPTIONAL),
                'timemodified' => new external_value(PARAM_INT, 'Last time  the course was updated', VALUE_OPTIONAL),
                'requested' => new external_value(PARAM_INT, 'If is a requested course', VALUE_OPTIONAL),
                'cacherev' => new external_value(PARAM_INT, 'Cache revision number', VALUE_OPTIONAL),
                'filters' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'filter'  => new external_value(PARAM_PLUGIN, 'Filter plugin name'),
                            'localstate' => new external_value(PARAM_INT, 'Filter state: 1 for on, -1 for off, 0 if inherit'),
                            'inheritedstate' => new external_value(PARAM_INT, '1 or 0 to use when localstate is set to inherit'),
                        )
                    ),
                    'Course filters', VALUE_OPTIONAL
                ),
                'courseformatoptions' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'name' => new external_value(PARAM_RAW, 'Course format option name.'),
                            'value' => new external_value(PARAM_RAW, 'Course format option value.'),
                        )
                    ),
                    'Additional options for particular course format.', VALUE_OPTIONAL
                ),
            );
            $coursestructure = array_merge($coursestructure, $extra);
        }
        return new external_single_structure($coursestructure);
    }

    public static function save_definitions_custom_service_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course Module ID'),
            'useridlms' => new external_value(PARAM_TEXT, 'User ID Moodle', VALUE_DEFAULT, ''),
            'json_convert' => new external_value(PARAM_RAW, 'Json Data Definitions', VALUE_DEFAULT, ''),
        ]);
    }

    public static function save_definitions_custom_service($cmid, $useridlms, $json_convert) {
        global $DB, $USER;

        // Validate the parameters.
        $params = self::validate_parameters(self::save_definitions_custom_service_parameters(), [
            'cmid' => $cmid,
            'useridlms' => $useridlms,
            'json_convert' => $json_convert,
        ]);

        $transaction = $DB->start_delegated_transaction();

        try {
            $jsonData = json_decode($params['json_convert'], true);

            // Lấy Course Module
            $cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);

            // Kiểm tra quyền user
            $context = context_module::instance($cm->id);

            // Định nghĩa khu vực chấm điểm
            $gradingarea = [
                'cmid' => $cmid,
                'contextid' => $context->id,
                'component' => 'mod_assign',
                'areaname'  => 'submissions',
                'activemethod' => 'rubric',
                'definitions' => []
            ];

            // Định nghĩa rubric
            $rubricdefinition = [
                'method' => 'rubric',
                'name' => $jsonData['name'],
                'description' => $jsonData['description'],
                'descriptionformat' => 0,
                'status' => 20,
                'copiedfromid' => 0,
                'timecreated' => time(),
                'usercreated' => $useridlms,
                'timemodified' => time(),
                'usermodified' => $useridlms,
                'timecopied' => 0,
                'guide' => [
                    'guide_criteria' => []
                ],
                'rubric' => [
                    'rubric_criteria' => []
                ]
            ];

            // Xử lý từng tiêu chí rubric
            foreach ($jsonData['criteria'] as $criteriaIndex => $criteria) {
                $rubriclevels = [];

                foreach ($criteria['levels'] as $levelIndex => $level) {
                    $rubriclevels[] = [
                        'score' => (double)$level['score'],
                        'definition' => $level['definition'],
                        'definitionformat' => 0
                    ];
                }

                $rubricdefinition['rubric']['rubric_criteria'][] = [
                    'sortorder' => $criteriaIndex + 1,
                    'description' => $criteria['description'],
                    'descriptionformat' => 0,
                    'levels' => $rubriclevels
                ];
            }

            // Thêm định nghĩa rubric vào khu vực grading
            $gradingarea['definitions'][] = $rubricdefinition;

            // Định nghĩa toàn bộ API request
            $areas = ['areas' => [$gradingarea]];
            
            // Gọi API của Moodle để lưu
            $results = core_grading_external::save_definitions($areas['areas']);

            $transaction->allow_commit();

            return [
                'status' => "true",
                'cmid' => $cmid,
                'message' => 'Rubric created successfully',
            ];
        } catch (Exception $e) {
            if (!empty($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            return [
                'status' => "false",
                'cmid' => $cmid,
                'message' => $e->getMessage(),
            ];
        }
    }

    public static function save_definitions_custom_service_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'status'),
            'cmid' => new external_value(PARAM_INT, 'Course module ID của label'),
            'message' => new external_value(PARAM_TEXT, 'message')
        ]);
    }

    /**
     * Parameter description for reorder_category().
     *
     * @return external_function_parameters
     */
    public static function reorder_category_parameters() {
        return new external_function_parameters(
            array(
                'categoryid' => new external_value(PARAM_INT, 'id of the category to reorder'),
                'afterid' => new external_value(PARAM_INT, 'id of the category to place this category after (0 for first position)'),
            )
        );
    }

    /**
     * Change the order of a category within its parent
     *
     * @param int $categoryid ID of the category to be reordered
     * @param int $afterid ID of the category to place this category after (0 for first position)
     * @return null
     */
    public static function reorder_category($categoryid, $afterid) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        
        // Validate parameters passed from web service
        $params = self::validate_parameters(self::reorder_category_parameters(), array(
            'categoryid' => $categoryid,
            'afterid' => $afterid
        ));

        // Get the category to move
        $category = $DB->get_record('course_categories', array('id' => $params['categoryid']), '*', MUST_EXIST);
        
        // Check permissions
        $categorycontext = context_coursecat::instance($category->id);
        require_capability('moodle/category:manage', $categorycontext);

        // Get the coursecat object
        $coursecat = core_course_category::get($category->id);

        if ($params['afterid'] == 0) {
            // Move to the first position
            // First get the parent category's first child's sortorder
            $firstcategory = $DB->get_records_select('course_categories', 
                'parent = ? ORDER BY sortorder ASC', 
                array($category->parent), 
                'sortorder ASC', 
                'id, sortorder', 
                0, 1);
                
            if ($firstcategory) {
                $firstcategory = reset($firstcategory);
                // If the category is already the first one, do nothing
                if ($firstcategory->id == $category->id) {
                    return null;
                }
                
                // Set the sortorder to be less than the first category
                $newsortorder = $firstcategory->sortorder - 1;
                if ($newsortorder < 1) {
                    // If we would go below 1, we need to reorder all categories
                    fix_course_sortorder();
                    return null;
                }
                
                $DB->set_field('course_categories', 'sortorder', $newsortorder, array('id' => $category->id));
            }
        } else {
            // Get the reference category
            $aftercat = $DB->get_record('course_categories', array('id' => $params['afterid']), '*', MUST_EXIST);
            
            // Make sure they're in the same parent
            if ($category->parent != $aftercat->parent) {
                throw new moodle_exception('categoriesnotsameparent', 'local_custom_service');
            }

            // If the category is already after the specified category, do nothing
            try {
                $nextcategory = $DB->get_records_select(
                    'course_categories',
                    'parent = ? AND sortorder > ?',
                    array($aftercat->parent, $aftercat->sortorder),
                    'sortorder ASC',
                    'id, sortorder',
                    0, 1
                );
            } catch (Exception $e) {
                debugging($e->getMessage());
            }
            
            if ($nextcategory) {
                $nextcategory = reset($nextcategory);
                if ($nextcategory->id == $category->id) {
                    return null;
                }
            }
            
            // Get all categories with the same parent sorted by sortorder
            $categories = $DB->get_records('course_categories', 
                array('parent' => $category->parent), 
                'sortorder ASC', 
                'id, sortorder');
                
            // Remove the category we're moving from the list
            unset($categories[$category->id]);
            
            // Rebuild the sortorder
            $newsortorder = array();
            $position = 0;
            
            foreach ($categories as $cat) {
                $newsortorder[$cat->id] = ++$position;
                
                // Insert our category after the specified category
                if ($cat->id == $aftercat->id) {
                    $newsortorder[$category->id] = ++$position;
                }
            }
            
            // If the aftercat was not found (shouldn't happen), add the category at the end
            if (!isset($newsortorder[$category->id])) {
                $newsortorder[$category->id] = ++$position;
            }
            // Update all sortorders
            foreach ($newsortorder as $catid => $sortorder) {
                $DB->set_field('course_categories', 'sortorder', $sortorder, array('id' => $catid));
            }
        }
        
        // Fix course sortorder
        fix_course_sortorder();
        
        // Purge caches
        cache_helper::purge_by_event('changesincoursecat');
        
        return null;
    }

    /**
     * Parameter description for reorder_category().
     *
     * @return external_description
     */
    public static function reorder_category_returns() {
        return null;
    }


    // api mới cần test (chưa có trong service)

    /**
     * Parameter description for move_category().
     *
     * @return external_function_parameters
     */
    public static function move_category_parameters() {
        return new external_function_parameters(
            array(
                'categoryid' => new external_value(PARAM_INT, 'id of the category to move'),
                'parentid' => new external_value(PARAM_INT, 'id of the parent category to move to'),
            )
        );
    }

    /**
     * Move a category to a new parent
     *
     * @param int $categoryid ID of the category to be moved
     * @param int $parentid ID of the parent category to move to
     * @return null
     */
    public static function move_category($categoryid, $parentid) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        // Validate parameters passed from web service
        $params = self::validate_parameters(self::move_category_parameters(), array(
            'categoryid' => $categoryid,
            'parentid' => $parentid
        ));

        // Get the category
        $category = $DB->get_record('course_categories', array('id' => $params['categoryid']), '*', MUST_EXIST);
        
        // Get the parent category
        if ($params['parentid'] != 0) {
            $parent = $DB->get_record('course_categories', array('id' => $params['parentid']), '*', MUST_EXIST);
        }

        // Check permissions
        $categorycontext = context_coursecat::instance($category->id);
        require_capability('moodle/category:manage', $categorycontext);

        // Move the category
        $coursecat = core_course_category::get($category->id);
        $newparentcat = core_course_category::get($params['parentid']);
        
        if (!$coursecat->change_parent($newparentcat)) {
            throw new moodle_exception('movecategoryerror', 'local_custom_service');
        }

        return null;
    }

    /**
     * Parameter description for move_category().
     *
     * @return external_description
     */
    public static function move_category_returns() {
        return null;
    }

    /**
     * Parameter description for reorder_course().
     *
     * @return external_function_parameters
     */
    public static function reorder_course_parameters() {
        return new external_function_parameters(
            array(
                'courseid' => new external_value(PARAM_INT, 'id of the course to reorder'),
                'afterid' => new external_value(PARAM_INT, 'id of the course to place this course after (0 for first position)'),
            )
        );
    }

    /**
     * Change the order of a course within its category
     *
     * @param int $courseid ID of the course to be reordered
     * @param int $afterid ID of the course to place this course after (0 for first position)
     * @return null
     */
    public static function reorder_course($courseid, $afterid) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        // Validate parameters passed from web service
        $params = self::validate_parameters(self::reorder_course_parameters(), array(
            'courseid' => $courseid,
            'afterid' => $afterid
        ));

        // Get the course to move
        $course = $DB->get_record('course', array('id' => $params['courseid']), '*', MUST_EXIST);
        
        // Check permissions
        $coursecontext = context_course::instance($course->id);
        $categorycontext = context_coursecat::instance($course->category);
        require_capability('moodle/course:update', $coursecontext);
        require_capability('moodle/category:manage', $categorycontext);

        if ($params['afterid'] == 0) {
            // Move to the first position in the category
            // First get the category's first course's sortorder
            $firstcourse = $DB->get_records_select('course', 
                'category = ? ORDER BY sortorder ASC', 
                array($course->category), 
                'sortorder ASC', 
                'id, sortorder', 
                0, 1);
                
            if ($firstcourse) {
                $firstcourse = reset($firstcourse);
                // If the course is already the first one, do nothing
                if ($firstcourse->id == $course->id) {
                    return null;
                }
                
                // Set the sortorder to be less than the first course
                $newsortorder = $firstcourse->sortorder - 1;
                if ($newsortorder < 1) {
                    // If we would go below 1, we need to reorder all courses
                    fix_course_sortorder();
                    return null;
                }
                
                $DB->set_field('course', 'sortorder', $newsortorder, array('id' => $course->id));
            }
        } else {
            // Get the reference course
            $aftercourse = $DB->get_record('course', array('id' => $params['afterid']), '*', MUST_EXIST);
            
            // Make sure they're in the same category
            if ($course->category != $aftercourse->category) {
                throw new moodle_exception('coursesnotsamecategory', 'local_custom_service');
            }
            
            // If the course is already after the specified course, do nothing
            $nextcourse = $DB->get_records_select('course', 
                'category = ? AND sortorder > ? ORDER BY sortorder ASC', 
                array($aftercourse->category, $aftercourse->sortorder), 
                'sortorder ASC', 
                'id, sortorder', 
                0, 1);
                
            if ($nextcourse) {
                $nextcourse = reset($nextcourse);
                if ($nextcourse->id == $course->id) {
                    return null;
                }
            }
            
            // Get all courses in the same category sorted by sortorder
            $courses = $DB->get_records('course', 
                array('category' => $course->category), 
                'sortorder ASC', 
                'id, sortorder');
                
            // Remove the course we're moving from the list
            unset($courses[$course->id]);
            
            // Rebuild the sortorder
            $newsortorder = array();
            $position = 0;
            
            foreach ($courses as $c) {
                $newsortorder[$c->id] = ++$position;
                
                // Insert our course after the specified course
                if ($c->id == $aftercourse->id) {
                    $newsortorder[$course->id] = ++$position;
                }
            }
            
            // If the aftercourse was not found (shouldn't happen), add the course at the end
            if (!isset($newsortorder[$course->id])) {
                $newsortorder[$course->id] = ++$position;
            }
            
            // Update all sortorders
            foreach ($newsortorder as $cid => $sortorder) {
                $DB->set_field('course', 'sortorder', $sortorder, array('id' => $cid));
            }
        }
        
        // Fix course sortorder
        fix_course_sortorder();
        
        // Purge caches
        cache_helper::purge_by_event('changesincourse');
        
        return null;
    }

    /**
     * Parameter description for reorder_course().
     *
     * @return external_description
     */
    public static function reorder_course_returns() {
        return null;
    }

    /**
     * Parameter description for move_course().
     *
     * @return external_function_parameters
     */
    public static function move_course_parameters() {
        return new external_function_parameters(
            array(
                'courseid' => new external_value(PARAM_INT, 'id of the course to move'),
                'categoryid' => new external_value(PARAM_INT, 'id of the category to move the course to'),
            )
        );
    }

    /**
     * Move a course to a different category
     *
     * @param int $courseid ID of the course to be moved
     * @param int $categoryid ID of the category to move the course to
     * @return null
     */
    public static function move_course($courseid, $categoryid) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        // Validate parameters passed from web service
        $params = self::validate_parameters(self::move_course_parameters(), array(
            'courseid' => $courseid,
            'categoryid' => $categoryid
        ));

        // Get the course
        $course = $DB->get_record('course', array('id' => $params['courseid']), '*', MUST_EXIST);
        
        // Get the category
        $category = $DB->get_record('course_categories', array('id' => $params['categoryid']), '*', MUST_EXIST);

        // Check permissions
        $coursecontext = context_course::instance($course->id);
        require_capability('moodle/course:update', $coursecontext);
        require_capability('moodle/course:changecategory', $coursecontext);

        // Move the course
        $courses = array($course->id);
        if (!move_courses($courses, $params['categoryid'])) {
            throw new moodle_exception('movecourseerror', 'local_custom_service');
        }

        return null;
    }

    /**
     * Parameter description for move_course().
     *
     * @return external_description
     */
    public static function move_course_returns() {
        return null;
    }

    // api mới cần test (chưa có trong service)

    //get student info by emails
    public static function get_user_info_by_emails_parameters()
    {
        return new external_function_parameters(
            array(
                'user_emails' => new external_multiple_structure(
                    new external_value(PARAM_TEXT, 'User email')
                )
            )
        );
    }

    public static function get_user_info_by_emails($user_emails)
    {
        global $DB;

        // Validate parameters
        $params = self::validate_parameters(
            self::get_user_info_by_emails_parameters(),
            array('user_emails' => $user_emails)
        );

        if (empty($params['user_emails'])) {
            throw new invalid_parameter_exception('User emails cannot be empty');
        }

        // Query users theo emails
        // list($inSql, $inParams) = $DB->get_in_or_equal($params['user_emails'], SQL_PARAMS_NAMED);

        // $users = $DB->get_records_select('user', "email $inSql", $inParams, '', 'id, username, firstname, lastname, email');

        // // Trả về dưới dạng mảng
        // $result = [];
        // foreach ($users as $user) {
        //     $result[] = [
        //         'id' => $user->id,
        //         'username' => $user->username,
        //         'firstname' => $user->firstname,
        //         'lastname' => $user->lastname,
        //         'email' => $user->email,
        //     ];
        // }

        $allUsers = [];
        $chunks = array_chunk($params['user_emails'], 1000); // batch size <= 1000

        foreach ($chunks as $chunk) {
            list($inSql, $inParams) = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $users = $DB->get_records_select('user', "email $inSql", $inParams, '', 'id, username, firstname, lastname, email');

            foreach ($users as $user) {
                $allUsers[] = [
                    'id' => $user->id,
                    'username' => $user->username,
                    'firstname' => $user->firstname,
                    'lastname' => $user->lastname,
                    'email' => $user->email,
                    'count_email' => count($params['user_emails'])
                ];
            }
        }

        return $allUsers;
    }

    public static function get_user_info_by_emails_returns()
    {
        return new external_multiple_structure(
            new external_single_structure(
                array(
                    'id' => new external_value(PARAM_INT, 'User ID'),
                    'username' => new external_value(PARAM_RAW, 'Username'),
                    'firstname' => new external_value(PARAM_NOTAGS, 'First name'),
                    'lastname' => new external_value(PARAM_NOTAGS, 'Last name'),
                    'email' => new external_value(PARAM_EMAIL, 'Email'),
                    'count_email' => new external_value(PARAM_INT, 'Count Email'),
                )
            )
        );
    }

    // 1️⃣ API lấy embed URL của H5P activity
    public static function get_h5p_embed_url_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course Module ID (cmid) of the H5P activity'),
            'modtype' => new external_value(PARAM_TEXT, 'Type of H5P module: hvp or h5pactivity')
        ]);
    }

    // 2️⃣ Hàm xử lý
    public static function get_h5p_embed_url($cmid, $modtype) {
        global $DB, $CFG;

        $params = self::validate_parameters(self::get_h5p_embed_url_parameters(), [
            'cmid' => $cmid,
            'modtype' => $modtype
        ]);

        $modtype = trim(strtolower($params['modtype']));
        if (!in_array($modtype, ['h5pactivity', 'hvp'])) {
            throw new moodle_exception('invalidmodtype', 'error', '', null, 'modtype must be "h5pactivity" or "hvp"');
        }

        // Lấy course module đúng theo modtype
        $cm = get_coursemodule_from_id($modtype, $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);

        // Tạo URL nhúng & script theo modtype
        if ($modtype === 'h5pactivity') {
            // Lấy file .h5p từ file_storage
            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'mod_h5pactivity', 'package', 0, 'itemid, filepath, filename', false);

            if (empty($files)) {
                throw new moodle_exception('nofilefound', 'error', '', null, 'No .h5p file found in h5pactivity');
            }

            $file = reset($files);
            $filename = $file->get_filename();

            $fileurl = moodle_url::make_pluginfile_url(
                $context->id,
                'mod_h5pactivity',
                'package',
                0,
                '/',
                $filename
            );

            $encodedurl = urlencode($fileurl);
            $embedurl = $CFG->wwwroot . "/h5p/embed.php?url={$encodedurl}&component=mod_h5pactivity";
            $resizer_script = $CFG->wwwroot . "/h5p/h5plib/v127/joubel/core/js/h5p-resizer.js";

        } else { // modtype === 'hvp'
            $embedurl = $CFG->wwwroot . "/mod/hvp/embed.php?id={$cm->id}";
            $resizer_script = $CFG->wwwroot . "/mod/hvp/library/js/h5p-resizer.js";
        }

        return [
            'status' => true,
            'embed_url' => $embedurl,
            'resizer_script' => $resizer_script
        ];
    }

    // 3️⃣ Kiểu dữ liệu trả về
    public static function get_h5p_embed_url_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Status'),
            'embed_url' => new external_value(PARAM_URL, 'Embed URL'),
            'resizer_script' => new external_value(PARAM_URL, 'H5P resizer script URL')
        ]);
    }

    // ⚙️ 1. Thêm tham số modtype
    public static function get_h5p_result_parameters() {
        return new external_function_parameters([
            'email' => new external_value(PARAM_TEXT, 'User email'),
            'cmid' => new external_value(PARAM_INT, 'Course Module ID'),
            'modtype' => new external_value(PARAM_TEXT, 'Loại module: h5pactivity hoặc hvp')
        ]);
    }

    public static function get_h5p_result($email, $cmid, $modtype) {
        global $DB;

        $params = self::validate_parameters(self::get_h5p_result_parameters(), [
            'email' => $email,
            'cmid' => $cmid,
            'modtype' => $modtype
        ]);

        // 1. Lấy user theo email
        $user = $DB->get_record('user', ['email' => $params['email']], '*', IGNORE_MISSING);
        if (!$user) {
            return ['status' => false, 'message' => 'Không tìm thấy người dùng với email này', 'attempts' => []];
        }

        // 2. Kiểm tra loại hoạt động
        $modtype = $params['modtype'];
        $cmid = $params['cmid'];

        if ($modtype === 'h5pactivity') {
            // 🔁 Code hiện tại giữ nguyên
            $cm = get_coursemodule_from_id('h5pactivity', $cmid, 0, false, MUST_EXIST);
            $h5pactivityid = $cm->instance;

            $attempts = $DB->get_records('h5pactivity_attempts', [
                'h5pactivityid' => $h5pactivityid,
                'userid' => $user->id
            ], 'timecreated ASC');

            if (!$attempts) {
                return ['status' => false, 'message' => 'Người dùng chưa làm bài', 'attempts' => []];
            }

            $resultList = [];
            foreach ($attempts as $attempt) {
                $resultList[] = [
                    'attemptid' => $attempt->id,
                    'attempt' => $attempt->attempt,
                    'score' => $attempt->rawscore,
                    'maxscore' => $attempt->maxscore,
                    'duration' => $attempt->duration,
                    'timecreated' => $attempt->timecreated,
                    'timemodified' => $attempt->timemodified
                ];
            }

            return [
                'status' => true,
                'message' => 'Lấy tất cả kết quả thành công',
                'attempts' => $resultList
            ];

        } elseif ($modtype === 'hvp') {
            $cm = get_coursemodule_from_id('hvp', $cmid, 0, false, MUST_EXIST);
            $hvpinstanceid = $cm->instance;
        
            $results = $DB->get_records('hvp_xapi_results', [
                'content_id' => $hvpinstanceid,
                'user_id' => $user->id
            ], 'id ASC');
        
            if (!$results) {
                return ['status' => false, 'message' => 'Người dùng chưa làm bài', 'attempts' => []];
            }
        
            // Nhóm theo parent_id (attempts)
            $groupedAttempts = [];
            foreach ($results as $res) {
                // Nếu là compound (parent_id = NULL)
                if ($res->parent_id === null) {
                    $groupedAttempts[$res->id]['parent'] = $res;
                    $groupedAttempts[$res->id]['children'] = [];
                } else {
                    // Nếu là con, tìm parent_id
                    if (!isset($groupedAttempts[$res->parent_id])) {
                        $groupedAttempts[$res->parent_id]['parent'] = null;
                        $groupedAttempts[$res->parent_id]['children'] = [];
                    }
                    $groupedAttempts[$res->parent_id]['children'][] = $res;
                }
            }
        
            $resultList = [];
            $attemptIndex = 1;
            foreach ($groupedAttempts as $group) {
                $score = 0;
                $maxscore = 0;
        
                foreach ($group['children'] as $child) {
                    $score += isset($child->raw_score) ? (float)$child->raw_score : 0;
                    $maxscore += isset($child->max_score) ? (float)$child->max_score : 0;
                }
        
                // Nếu có parent compound, ưu tiên lấy điểm từ đó
                if ($group['parent']) {
                    $attemptid = $group['parent']->id;
                    $score = isset($group['parent']->raw_score) ? (float)$group['parent']->raw_score : $score;
                    $maxscore = isset($group['parent']->max_score) ? (float)$group['parent']->max_score : $maxscore;
                } else {
                    $attemptid = $group['children'][0]->id; // fallback
                }
        
                $resultList[] = [
                    'attemptid' => $attemptid,
                    'attempt' => $attemptIndex++,
                    'score' => $score,
                    'maxscore' => $maxscore,
                    'duration' => 0,
                    'timecreated' => 0,
                    'timemodified' => 0
                ];
            }

            return [
                'status' => true,
                'message' => 'Lấy tất cả kết quả thành công',
                'attempts' => $resultList
            ];
        } else {
            return ['status' => false, 'message' => 'Loại hoạt động không được hỗ trợ', 'attempts' => []];
        }
    }

    public static function get_h5p_result_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Trạng thái'),
            'message' => new external_value(PARAM_TEXT, 'Thông báo'),
            'attempts' => new external_multiple_structure(
                new external_single_structure([
                    'attemptid' => new external_value(PARAM_INT, 'ID của attempt'),
                    'attempt' => new external_value(PARAM_INT, 'Số lần làm'),
                    'score' => new external_value(PARAM_FLOAT, 'Điểm đạt được'),
                    'maxscore' => new external_value(PARAM_FLOAT, 'Điểm tối đa'),
                    'duration' => new external_value(PARAM_INT, 'Thời gian làm bài (giây)'),
                    'timecreated' => new external_value(PARAM_INT, 'Thời gian tạo attempt'),
                    'timemodified' => new external_value(PARAM_INT, 'Thời gian cập nhật kết quả')
                ])
            )
        ]);
    }

    public static function submit_h5p_result_parameters() {
        return new external_function_parameters([
            'cmid'     => new external_value(PARAM_INT, 'Course module ID of H5P activity'),
            'email'    => new external_value(PARAM_TEXT, 'Email of the user submitting'),
            'score'    => new external_value(PARAM_FLOAT, 'Raw score'),
            'maxscore' => new external_value(PARAM_FLOAT, 'Maximum score'),
            'duration' => new external_value(PARAM_INT, 'Duration in seconds'),
            'completion' => new external_value(PARAM_BOOL, 'Completed or not'),
            'success'    => new external_value(PARAM_BOOL, 'Success or not')
        ]);
    }

    public static function submit_h5p_result($cmid, $email, $score, $maxscore, $duration, $completion, $success) {
        global $DB;

        $params = self::validate_parameters(self::submit_h5p_result_parameters(), compact('cmid', 'email', 'score', 'maxscore', 'duration', 'completion', 'success'));

        // Lấy user từ email
        $user = $DB->get_record('user', ['email' => $params['email']], '*', MUST_EXIST);

        // Lấy course module và activity
        $cm = get_coursemodule_from_id('h5pactivity', $params['cmid'], 0, false, MUST_EXIST);
        $activity = $DB->get_record('h5pactivity', ['id' => $cm->instance], '*', MUST_EXIST);

        // Tính scaled score (scaled từ 0.0 đến 1.0)
        $scaled = ($params['maxscore'] > 0) ? round($params['score'] / $params['maxscore'], 5) : 0;

        // Kiểm tra lần attempt gần nhất của user
        $lastattempt = $DB->get_record_sql("
            SELECT * FROM {h5pactivity_attempts}
            WHERE h5pactivityid = :h5pactivityid AND userid = :userid
            ORDER BY attempt DESC
            LIMIT 1
        ", [
            'h5pactivityid' => $activity->id,
            'userid' => $user->id
        ]);

        $newattempt = (object)[
            'h5pactivityid' => $activity->id,
            'userid'        => $user->id,
            'timecreated'   => time(),
            'timemodified'  => time(),
            'attempt'       => $lastattempt ? $lastattempt->attempt + 1 : 1,
            'rawscore'      => $params['score'],
            'maxscore'      => $params['maxscore'],
            'scaled'        => $scaled,
            'duration'      => $params['duration'],
            'completion'    => $params['completion'] ? 1 : 0,
            'success'       => $params['success'] ? 1 : 0
        ];

        $DB->insert_record('h5pactivity_attempts', $newattempt);

        return [
            'status' => true,
            'message' => 'Kết quả H5P đã được ghi nhận thành công.'
        ];
    }

    public static function submit_h5p_result_returns() {
        return new external_single_structure([
            'status'  => new external_value(PARAM_BOOL, 'Trạng thái ghi thành công hay không'),
            'message' => new external_value(PARAM_TEXT, 'Thông báo')
        ]);
    }

    public static function submit_hvp_result_parameters() {
        return new external_function_parameters([
            'cmid'     => new external_value(PARAM_INT, 'Course module ID of H5P activity'),
            'email'    => new external_value(PARAM_TEXT, 'Email of the user submitting'),
            'score'    => new external_value(PARAM_FLOAT, 'Raw score'),
            'maxscore' => new external_value(PARAM_FLOAT, 'Maximum score'),
            'duration' => new external_value(PARAM_INT, 'Duration in seconds'),
            'completion' => new external_value(PARAM_BOOL, 'Completed or not'),
            'success'    => new external_value(PARAM_BOOL, 'Success or not'),
            'interaction_type' => new external_value(PARAM_TEXT, 'Type of interaction', VALUE_OPTIONAL),
            'description' => new external_value(PARAM_RAW, 'Question or activity description', VALUE_OPTIONAL),
            'correct_responses_pattern' => new external_value(PARAM_RAW, 'Correct answer(s)', VALUE_OPTIONAL),
            'response' => new external_value(PARAM_RAW, 'Learner\'s response', VALUE_OPTIONAL),
            'additionals' => new external_value(PARAM_RAW, 'Additional data (JSON or other)', VALUE_OPTIONAL),
        ]);
    }

    public static function submit_hvp_result($cmid, $email, $score, $maxscore, $duration, $completion, $success) {
        global $DB;

        $params = self::validate_parameters(self::submit_hvp_result_parameters(), compact('cmid', 'email', 'score', 'maxscore', 'duration', 'completion', 'success'));

        // Lấy user từ email
        $user = $DB->get_record('user', ['email' => $params['email']], '*', MUST_EXIST);

        // Lấy course module và activity
        $cm = get_coursemodule_from_id('hvp', $params['cmid'], 0, false, MUST_EXIST);
        $hvp = $DB->get_record('hvp', ['id' => $cm->instance], '*', MUST_EXIST);

        $result = (object)[
            'content_id' => $cm->instance,
            'user_id'    => $user->id,
            'interaction_type' => $params['interaction_type'] ?? '',
            'description' => $params['description'] ?? '',
            'correct_responses_pattern' => $params['correct_responses_pattern'] ?? '',
            'response' => $params['response'] ?? '',
            'additionals' => $params['additionals'] ?? '',
            'raw_score' => $params['score'],
            'max_score' => $params['maxscore']
        ];
    
        $DB->insert_record('hvp_xapi_results', $result);

        return [
            'status' => true,
            'message' => 'Kết quả H5P đã được ghi nhận thành công.'
        ];
    }

    public static function submit_hvp_result_returns() {
        return new external_single_structure([
            'status'  => new external_value(PARAM_BOOL, 'Trạng thái ghi thành công hay không'),
            'message' => new external_value(PARAM_TEXT, 'Thông báo')
        ]);
    }

    //update_activity_cmsh5ptool
    public static function update_activity_cmsh5ptool_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID của url cần cập nhật'),
            'fields' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_TEXT, 'Tên url', VALUE_OPTIONAL),
                    'intro' => new external_value(PARAM_RAW, 'Mô tả url', VALUE_OPTIONAL),
                    'introformat' => new external_value(PARAM_INT, 'introformat', VALUE_OPTIONAL),
                    'cms_h5p_tool_id' => new external_value(PARAM_TEXT, 'CMS H5P Tool ID', VALUE_OPTIONAL),
                    'display' => new external_value(PARAM_INT, 'display', VALUE_OPTIONAL),
                    'section' => new external_value(PARAM_INT, 'ID của section chứa quiz', VALUE_OPTIONAL),
                    'visible' => new external_value(PARAM_INT, 'Trạng thái hiển thị của quiz (0 = ẩn, 1 = hiện)', VALUE_OPTIONAL),
                    'completion' => new external_value(PARAM_INT, 'Completion tracking', VALUE_OPTIONAL),
                    'completionview' => new external_value(PARAM_INT, 'Require view', VALUE_OPTIONAL),
                    'completionexpected' => new external_value(PARAM_INT, 'Expect completed on', VALUE_OPTIONAL),
                    'showdescription' => new external_value(PARAM_INT, 'Show Description', VALUE_OPTIONAL),
                    // Restrict access parameters
                    'availability' => new external_single_structure([
                        'timeopen' => new external_value(PARAM_INT, 'Thời gian mở quiz (timestamp)', VALUE_OPTIONAL),
                        'timeclose' => new external_value(PARAM_INT, 'Thời gian đóng quiz (timestamp)', VALUE_OPTIONAL),
                        'gradeitemid' => new external_value(PARAM_INT, 'ID của mục điểm (grade item)', VALUE_OPTIONAL),
                        'min' => new external_value(PARAM_FLOAT, 'Điểm tối thiểu', VALUE_OPTIONAL),
                        'max' => new external_value(PARAM_FLOAT, 'Điểm tối đa', VALUE_OPTIONAL),
                        'completioncmid' => new external_multiple_structure(
                            new external_value(PARAM_INT, 'ID của activity cần hoàn thành'),
                            'Danh sách ID của các activity cần hoàn thành',
                            VALUE_OPTIONAL
                        )
                    ], 'Restrict access settings', VALUE_OPTIONAL)
                ]),
                'Danh sách các trường cần cập nhật',
                VALUE_DEFAULT,
                []
            )
        ]);
    }
    

    /**
     * Function to create a quiz activity in a course.
     */
    public static function update_activity_cmsh5ptool($cmid, $fields) {
        global $DB;
    
        // Validate parameters
        $params = self::validate_parameters(self::update_activity_cmsh5ptool_parameters(), [
            'cmid' => $cmid,
            'fields' => $fields
        ]);
        
        // Lấy cmsh5ptoolid từ cmid
        $cmsh5ptoolid = self::get_moduleid_from_cmid($cmid, 'cmsh5ptool');
        
        // Kiểm tra cmsh5ptool có tồn tại không
        if (!$DB->record_exists('cmsh5ptool', ['id' => $cmsh5ptoolid])) {
            throw new moodle_exception('invalidcmsh5ptoolid', 'mdl_cmsh5ptool', '', $cmsh5ptoolid);
        }
    
        // Lấy thông tin cmsh5ptool hiện tại
        $cmsh5ptool = $DB->get_record('cmsh5ptool', ['id' => $cmsh5ptoolid], '*', MUST_EXIST);
    
        // Cập nhật các trường được cung cấp
        foreach ($params['fields'] as $field_data) {
            foreach ($field_data as $field => $value) {
                if (isset($value) && $field !== 'availability' && property_exists($cmsh5ptool, $field)) {
                    $cmsh5ptool->{$field} = $value;
                }
            }
        }
        // Cập nhật cmsh5ptool
        $result = $DB->update_record('cmsh5ptool', $cmsh5ptool);
        // Xử lý restrict access (availability)
        if (!empty($params['fields'][0]['availability'])) {
            $availability_params = $params['fields'][0]['availability'];
            $completioncmids = $availability_params['completioncmid'] ?? [];

            if (!is_array($completioncmids)) {
                $completioncmids = [$completioncmids];
            }
            $availability_json = self::generate_availability_conditions(
                $availability_params['timeopen'] ?? null,
                $availability_params['timeclose'] ?? null,
                $availability_params['gradeitemid'] ?? null,
                $availability_params['min'] ?? null,
                $availability_params['max'] ?? null,
                $completioncmids
            );
            // var_dump($availability_json, $completioncmids);die;
            // Cập nhật availability trong bảng course_modules
            $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
            $cm->availability = $availability_json;
        
            // Cập nhật lại course_modules
            $DB->update_record('course_modules', $cm);
        }
        
        // Xử lý section và visible nếu được truyền
        $cm1 = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);

        $section = $DB->get_record('course_sections', array('course' => $cm1->course, 'section' => $params['fields'][0]['section']));
        // $old_section_id = $cm1->section;
        // $new_section_id = $params['fields'][0]['section'] ?? null;
        // var_dump($section->id, $cm1->section);die;
        if (isset($params['fields'][0]['section']) && $section->id != $cm1->section) {
            // Cập nhật section mới
            self::move_activity_to_section($cm1->course, $cmid, $params['fields'][0]['section']);
        }

        if (isset($params['fields'][0]['visible'])) {
            $cm1->visible = $params['fields'][0]['visible'];
        }

        $completion = 0;
        $completionview = 0;
        $completionexpected = 0;
        if (!empty($params['fields'][0]['completion'])) {
            if($params['fields'][0]['completion'] == 1){
                $completion = $params['fields'][0]['completion'];
                $completionexpected = $params['fields'][0]['completionexpected'] ?? 0;
            }

            if($params['fields'][0]['completion'] == 2){
                $completion = $params['fields'][0]['completion'];
                $completionview = $params['fields'][0]['completionview'] ?? 0;
                $completionexpected = $params['fields'][0]['completionexpected'] ?? 0;
            }
        }
        $cm1->completion = $completion;
        $cm1->completionview = $completionview;
        $cm1->completionexpected = $completionexpected;

        $cm1->showdescription = $params['fields'][0]['showdescription'] ?? 0;
        // var_dump($cm1);die;
        $DB->update_record('course_modules', $cm1);

        rebuild_course_cache($cm1->course, true);
    
        return [
            'status' => 'success',
            'message' => 'cmsh5ptool updated successfully',
            'cmsh5ptoolid' => $cmsh5ptoolid,
            'cmid' => $cmid
        ];
    }

    /**
     * Return description for update_activity_cmsh5ptool().
     *
     * @return external_single_structure.
     */
    public static function update_activity_cmsh5ptool_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Kết quả của thao tác'),
            'message' => new external_value(PARAM_TEXT, 'Thông báo kết quả'),
            'cmsh5ptoolid' => new external_value(PARAM_INT, 'ID của cmsh5ptool đã cập nhật'),
            'cmid' => new external_value(PARAM_INT, 'Course module ID của cmsh5ptool')
        ]);
    }


    //update_activity_hvp
    public static function update_activity_hvp_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID của url cần cập nhật'),
            'fields' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_TEXT, 'Tên url', VALUE_OPTIONAL),
                    'intro' => new external_value(PARAM_RAW, 'Mô tả url', VALUE_OPTIONAL),
                    'introformat' => new external_value(PARAM_INT, 'introformat', VALUE_OPTIONAL),
                    'display' => new external_value(PARAM_INT, 'display', VALUE_OPTIONAL),
                    'section' => new external_value(PARAM_INT, 'ID của section chứa quiz', VALUE_OPTIONAL),
                    'visible' => new external_value(PARAM_INT, 'Trạng thái hiển thị của quiz (0 = ẩn, 1 = hiện)', VALUE_OPTIONAL),
                    'completion' => new external_value(PARAM_INT, 'Completion tracking', VALUE_OPTIONAL),
                    'completionview' => new external_value(PARAM_INT, 'Require view', VALUE_OPTIONAL),
                    'completionexpected' => new external_value(PARAM_INT, 'Expect completed on', VALUE_OPTIONAL),
                    'showdescription' => new external_value(PARAM_INT, 'Show Description', VALUE_OPTIONAL),
                    'availability' => new external_single_structure([
                        'timeopen' => new external_value(PARAM_INT, 'Thời gian mở quiz (timestamp)', VALUE_OPTIONAL),
                        'timeclose' => new external_value(PARAM_INT, 'Thời gian đóng quiz (timestamp)', VALUE_OPTIONAL),
                        'gradeitemid' => new external_value(PARAM_INT, 'ID của mục điểm (grade item)', VALUE_OPTIONAL),
                        'min' => new external_value(PARAM_FLOAT, 'Điểm tối thiểu', VALUE_OPTIONAL),
                        'max' => new external_value(PARAM_FLOAT, 'Điểm tối đa', VALUE_OPTIONAL),
                        'completioncmid' => new external_multiple_structure(
                            new external_value(PARAM_INT, 'ID của activity cần hoàn thành'),
                            'Danh sách ID của các activity cần hoàn thành',
                            VALUE_OPTIONAL
                        )
                    ], 'Restrict access settings', VALUE_OPTIONAL)
                ]),
                'Danh sách các trường cần cập nhật',
                VALUE_DEFAULT,
                []
            )
        ]);
    }
    

    /**
     * Function to create a quiz activity in a course.
     */
    public static function update_activity_hvp($cmid, $fields) {
        global $DB;
    
        $params = self::validate_parameters(self::update_activity_hvp_parameters(), [
            'cmid' => $cmid,
            'fields' => $fields
        ]);
    
        $hvpid = self::get_moduleid_from_cmid($cmid, 'hvp');
    
        if (!$DB->record_exists('hvp', ['id' => $hvpid])) {
            throw new moodle_exception('invalidhvpid', 'mdl_hvp', '', $hvpid);
        }
    
        $hvp = $DB->get_record('hvp', ['id' => $hvpid], '*', MUST_EXIST);
    
        foreach ($params['fields'] as $field_data) {
            foreach ($field_data as $field => $value) {
                if (isset($value) && $field !== 'availability' && property_exists($hvp, $field)) {
                    $hvp->{$field} = $value;
                }
            }
        }
    
        $result = $DB->update_record('hvp', $hvp);
    
        if (!empty($params['fields'][0]['availability'])) {
            $availability_params = $params['fields'][0]['availability'];
            $completioncmids = $availability_params['completioncmid'] ?? [];
    
            if (!is_array($completioncmids)) {
                $completioncmids = [$completioncmids];
            }
    
            $availability_json = self::generate_availability_conditions(
                $availability_params['timeopen'] ?? null,
                $availability_params['timeclose'] ?? null,
                $availability_params['gradeitemid'] ?? null,
                $availability_params['min'] ?? null,
                $availability_params['max'] ?? null,
                $completioncmids
            );
    
            $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
            $cm->availability = $availability_json;
            $DB->update_record('course_modules', $cm);
        }
    
        $cm1 = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $section = $DB->get_record('course_sections', ['course' => $cm1->course, 'section' => $params['fields'][0]['section']]);
    
        if (isset($params['fields'][0]['section']) && $section->id != $cm1->section) {
            self::move_activity_to_section($cm1->course, $cmid, $params['fields'][0]['section']);
        }
    
        if (isset($params['fields'][0]['visible'])) {
            $cm1->visible = $params['fields'][0]['visible'];
        }
    
        $completion = 0;
        $completionview = 0;
        $completionexpected = 0;
        if (!empty($params['fields'][0]['completion'])) {
            if ($params['fields'][0]['completion'] == 1) {
                $completion = $params['fields'][0]['completion'];
                $completionexpected = $params['fields'][0]['completionexpected'] ?? 0;
            }
    
            if ($params['fields'][0]['completion'] == 2) {
                $completion = $params['fields'][0]['completion'];
                $completionview = $params['fields'][0]['completionview'] ?? 0;
                $completionexpected = $params['fields'][0]['completionexpected'] ?? 0;
            }
        }
    
        $cm1->completion = $completion;
        $cm1->completionview = $completionview;
        $cm1->completionexpected = $completionexpected;
        $cm1->showdescription = $params['fields'][0]['showdescription'] ?? 0;
    
        $DB->update_record('course_modules', $cm1);
        rebuild_course_cache($cm1->course, true);
    
        return [
            'status' => 'success',
            'message' => 'hvp updated successfully',
            'hvpid' => $hvpid,
            'cmid' => $cmid
        ];
    }

    /**
     * Return description for update_activity_hvp().
     *
     * @return external_single_structure.
     */
    public static function update_activity_hvp_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Kết quả của thao tác'),
            'message' => new external_value(PARAM_TEXT, 'Thông báo kết quả'),
            'hvpid' => new external_value(PARAM_INT, 'ID của hvp đã cập nhật'),
            'cmid' => new external_value(PARAM_INT, 'Course module ID của hvp')
        ]);
    }
}