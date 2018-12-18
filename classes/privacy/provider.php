<?php

namespace plagiarism_vericite\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\writer;

class provider implements
    // This plugin does store personal user data.
    \core_privacy\local\metadata\provider,
    \core_plagiarism\privacy\plagiarism_provider {

    // This trait must be included to provide the relevant polyfill for the metadata provider.
    use \core_privacy\local\legacy_polyfill;
    // This trait must be included to provide the relevant polyfill for the plagirism provider.
    use \core_plagiarism\privacy\legacy_polyfill;

    public static function get_metadata(collection $collection) : collection {

        $collection->link_subsystem(
            'core_files',
            'privacy:metadata:core_files'
        );

        $collection->add_database_table(
            'plagiarism_vericite_files',
            [
                'id' => 'privacy:metadata:plagiarism_vericite_files:id',
                'cm' => 'privacy:metadata:plagiarism_vericite_files:cm',
                'userid' => 'privacy:metadata:plagiarism_vericite_files:userid',
                'identifier' => 'privacy:metadata:plagiarism_vericite_files:identifier',
                'similarityscore' => 'privacy:metadata:plagiarism_vericite_files:similarityscore',
                'preliminary' => 'privacy:metadata:plagiarism_vericite_files:preliminary',
                'timeretrieved' => 'privacy:metadata:plagiarism_vericite_files:timeretrieved',
                'data' => 'privacy:metadata:plagiarism_vericite_files:data',
                'status' => 'privacy:metadata:plagiarism_vericite_files:status',
                'attempts' => 'privacy:metadata:plagiarism_vericite_files:attempts',
                'timesubmitted' => 'privacy:metadata:plagiarism_vericite_files:timesubmitted',

            ],
            'privacy:metadata:plagiarism_vericite_files'
        );

        return $collection;
    }

    public static function get_contexts_for_userid($userid) : contextlist {
        $params = ['modulename' => 'assign',
            'contextlevel' => CONTEXT_MODULE,
            'userid' => $userid];
        $sql = "SELECT ctx.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON cm.module = m.id AND m.name = :modulename
                  JOIN {assign} a ON cm.instance = a.id
                  JOIN {context} ctx ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
             LEFT JOIN {plagiarism_vericite_files} tf ON cm.instance = cm
                 WHERE tf.userid = :userid";
        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, $params);
        return $contextlist;
    }

    public static function _export_plagiarism_user_data($userid, \context $context, array $subcontext, array $linkarray) {
        global $DB;
        if (empty($userid)) {
            return;
        }
        $user = $DB->get_record('user', array('id' => $userid));
        $params = ['userid' => $user->id];
        $sql = "SELECT *
                  FROM {plagiarism_vericite_files}
                 WHERE userid = :userid";
        $submissions = $DB->get_records_sql($sql, $params);
        foreach ($submissions as $submission) {
            $context = \context_module::instance($submission->cm);
            self::export_plagiarism_vericite_data_for_user((array)$submission, $context, $user);
        }
    }

    protected static function export_plagiarism_vericite_data_for_user(array $submissiondata, \context_module $context, \stdClass $user) {
        // Fetch the generic module data.
        $contextdata = helper::get_context_data($context, $user);
        // Merge with module data and write it.
        $contextdata = (object)array_merge((array)$contextdata, $submissiondata);
        writer::with_context($context)->export_data([], $contextdata);
        // Write generic module intro files.
        helper::export_context_files($context, $user);
    }

    public static function _delete_plagiarism_for_context(\context $context) {
        global $DB;
        if (empty($context)) {
            return;
        }
        if (!$context instanceof \context_module) {
            return;
        }
        // Delete all submissions.
        $DB->delete_records('plagiarism_vericite_files', ['cm' => $context->instanceid]);
    }

    public static function _delete_plagiarism_for_user($userid, \context $context) {
        global $DB;
        $DB->delete_records('plagiarism_vericite_files', ['userid' => $userid]);
    }
}