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
 * lib.php - Contains Plagiarism plugin specific functions called by Modules.
 *
 * @since 2.0
 * @package    plagiarism_vericite
 * @subpackage plagiarism
 * @copyright  2010 Dan Marsden http://danmarsden.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.');    ///  It must be included from a Moodle page
}

//get global class
global $CFG;
require_once($CFG->dirroot.'/plagiarism/lib.php');

class plagiarism_plugin_vericite extends plagiarism_plugin {

     public function get_settings() {
        static $plagiarismsettings;
        if (!empty($plagiarismsettings) || $plagiarismsettings === false) {
            return $plagiarismsettings;
        }
        $plagiarismsettings = (array)get_config('plagiarism');
        // Check if enabled.
        if (isset($plagiarismsettings['vericite_use']) && $plagiarismsettings['vericite_use']) {
            // Now check to make sure required settings are set!
            if (empty($plagiarismsettings['vericite_api'])) {
                error("VeriCite API URL not set!");
            }
            if (empty($plagiarismsettings['vericite_accountid'])) {
                error("VeriCite Account Id not set!");
	    }
            if (empty($plagiarismsettings['vericite_secretkey'])) {
                error("VeriCite Secret not set!");
	    }
            return $plagiarismsettings;
        } else {
            return false;
        }
    }

     /**
     * hook to allow plagiarism specific information to be displayed beside a submission 
     * @param array  $linkarraycontains all relevant information for the plugin to generate a link
     * @return string
     * 
     */
    public function get_links($linkarray) {
	global $COURSE;
	$vericite = array();
	$vericite['courseId'] = $COURSE->id;
	$vericite['courseTitle'] = $COURSE->fullname;
	$vericite['cmid'] = $linkarray['cmid'];
        $vericite['userid'] = $linkarray['userid'];
	if(!empty($linkarray['assignment']) && !is_number($linkarray['assignment'])){
		$vericite['assignmentTitle'] = $linkarray['assignment']->name;
	}
	if (!empty($linkarray['content']) && trim($linkarray['content']) != false) {
            $file = new stdclass();
	    $linkarray['content'] = '<html>' . $linkarray['content'] . '</html>';
            $file->filename = "InlineSubmission";
            $file->type = "inline";
            $file->identifier = sha1($linkarray['content']);
            $file->filepath = "";
	    $file->userid = $linkarray['userid'];
	    $file->size = 100;
	    $file->content = $linkarray['content'];
	    $vericite['file'] = $file;
	} else if (!empty($linkarray['file'])) {
	    $file = new stdclass();
            $file->filename = (!empty($linkarray['file']->filename)) ? $linkarray['file']->filename : $linkarray['file']->get_filename();
            $file->type = "file";
	    $file->identifier = $linkarray['file']->get_pathnamehash();
            $file->filepath =  (!empty($linkarray['file']->filepath)) ? $linkarray['file']->filepath : $linkarray['file']->get_filepath();
	    $file->userid = $linkarray['file']->get_userid();
	    $file->size = $linkarray['file']->get_filesize();
	    $vericite['file'] = $file;
        }
	if(!isset($file) || $file->userid !== $vericite['userid'] || $file->size > 52428800){
		return "";
	}
	$output = '';
        //add link/information about this file to $output
      	$results = $this->get_file_results($vericite['cmid'], $vericite['userid'], !empty($linkarray['file']) ? $linkarray['file'] : null, $vericite);
        if (empty($results)) {
            // no results
            return '<br />';
        }

        if (array_key_exists('error', $results)) {
            return $results['error'];
        }
	$rank = plagiarism_vericite_get_css_rank($results['score']);

        $similaritystring = '&nbsp;<span class="' . $rank . '">' . $results['score'] . '%</span>';
        if (!empty($results['reporturl'])) {
            // User gets to see link to similarity report & similarity score
            $output = '<span class="plagiarismreport"><a href="' . $results['reporturl'] . '" target="_blank">';
            $output .= get_string('similarity', 'plagiarism_vericite').':</a>' . $similaritystring . '</span>';
        } else {
            // User only sees similarity score
            $output = '<span class="plagiarismreport">' . get_string('similarity', 'plagiarism_vericite') . $similaritystring . '</span>';
        }
        return "<br/>" . $output . "<br/>";
    }


    public function get_file_results($cmid, $userid, $file, $vericite=null) {
        global $DB, $USER, $COURSE, $OUTPUT, $CFG;
     	$SCORE_CACHE_MIN = 20;
	$TOKEN_CACHE_MIN = 20;
        $plagiarismsettings = $this->get_settings();
        if (empty($plagiarismsettings)) {
            // VeriCite is not enabled
            return false;
        }
        $plagiarismvalues = $DB->get_records_menu('plagiarism_vericite_config', array('cm'=>$vericite['cmid']), '', 'name,value');
        if (empty($plagiarismvalues['use_vericite'])) {
            // VeriCite not in use for this cm
            return false;
        }

        $modulecontext = context_module::instance($vericite['cmid']);

        // Whether the user has permissions to see all items in the context of this module.
        // this is determined by whether the user is a "course instructor" if they have assignment:grade

	$gradeassignment = has_capability('mod/assign:grade', $modulecontext);
	$viewsimilarityscore = $gradeassignment;
	$viewfullreport = $gradeassignment;

        if ($USER->id == $vericite['userid']) {
            // The user wants to see details on their own report
            if ($plagiarismvalues['plagiarism_show_student_score'] == 1) {
                $viewsimilarityscore = true;
            }
            if ($plagiarismvalues['plagiarism_show_student_report'] == 1) {
                $viewfullreport = true;
            }
        }

        if (!$viewsimilarityscore && !$viewfullreport) {
            // The user has no right to see the requested detail.
            return false;
        }

        $results = array(
                'analyzed' => 0,
                'score' => '',
                'reporturl' => '',
                );

	//first check if we already have looked up the score for this class
	$fileId = $vericite['file']->identifier;
	$score = -1;
	
	$contentScore = $DB->get_records('plagiarism_vericite_files', array('cm'=>$vericite['cmid'], 'userid'=>$userid, 'identifier'=>$fileId), '', 'similarityscore, timeretrieved');
	if(!empty($contentScore) && sizeof($contentScore) == 1){
		foreach($contentScore as $content){
			if(time() - (60 * $SCORE_CACHE_MIN) < $content->timeretrieved){
				//since our reports are dynamic, only use the db as a cache
				//if its too old of a results, don't set the score and just grab a new one
				//from the API
				$score = $content->similarityscore;
			}
		}
	}
	if($score < 0){
		//ok, we couldn't find the score in the cache, try to look it up with the webservice
		$score = plagiarism_vericite_get_scores($plagiarismsettings, $COURSE->id, $cmid, $fileId, $userid);
	}
	if($score < 0){
		//ok can't find the score in the cache or from the service,
		//try submitting the file then re-retreive the score

		$url = plagiarism_vericite_generate_url($plagiarismsettings['vericite_api'], $COURSE->id, $cmid, $userid);
        	$fields = array();
		//full user record needed
		$user = ($userid == $USER->id ? $USER : $DB->get_record('user', array('id'=>$userid)));
		if(isset($user)){
			$fields['userFirstName'] = $user->firstname;
			$fields['userLastName'] = $user->lastname;
			$fields['userEmail'] = $user->email;
		}
		$contentUserGradeAssignment = $gradeassignment = has_capability('mod/assign:grade', $modulecontext, $user);
		$fields['userRole'] = $contentUserGradeAssignment ?  'Instructor' : 'Learner';
                $fields['consumer'] = $plagiarismsettings['vericite_accountid'];
                $fields['consumerSecret'] = $plagiarismsettings['vericite_secretkey'];
		if(isset($vericite['assignmentTitle'])){
			$fields['assignmentTitle'] = $vericite['assignmentTitle'];; 
		}
		$fields['externalContentId'] = $vericite['file']->identifier;
		if (!empty($vericite['file']->type) && $vericite['file']->type == "file"){ 
			$vericite['file']->content = (!empty($file->filepath)) ? file_get_contents($file->filepath) : $file->get_content();	
		}
		//create a tmp file to store data:
		if (!check_dir_exists($CFG->dataroot."/plagiarism/", true, true)) {
        		mkdir($CFG->dataroot."/plagiarism/", 0700);
		}
		$filename = $CFG->dataroot."/plagiarism/".time().$vericite['file']->filename;
		$fh = fopen($filename, 'w');
		fwrite($fh, $vericite['file']->content);
		fclose($fh);
		$fields['fileName'] = $vericite['file']->filename;
		$fields['filedata'] = '@' . $filename;
		try {
                	$c = new curl(array('proxy'=>true));
                	$status = json_decode($c->post($url, $fields));
		} catch (Exception $e) {
        	}
		unlink($filename);

		//now that we submitted the file, let's see if we can get the score:
		$score = plagiarism_vericite_get_scores($plagiarismsettings, $COURSE->id, $cmid, $fileId, $userid);
	}

			
	if($score >= 0){
		//we have successfully found the score and it has been evaluated:
		$results['analyzed'] = 1;
		$results['score'] = $score;
		if($viewfullreport){
			//see if the token already exists
			$conditions = array('cm'=>$vericite['cmid'], 'userid'=>$USER->id);
			if(!$gradeassignment){
				//instructors can view anything in the site, so don't pass in the identifier
				//however, this isn't an instructor, so look it up by the fileid
				$conditions['identifier'] = $fileId;
			}
			$dbTokens = $DB->get_records('plagiarism_vericite_tokens', $conditions);
			foreach($dbTokens as $dbToken){
				//instructors can have multiple tokens, see if any of them aren't expired
				//if it's not an instructor, there should only be one token in the array anyways
				if(time() - (60 * $TOKEN_CACHE_MIN) < $dbToken->timeretrieved){
					//we found an existing token, set token and break out
					$token = $dbToken->token;
					break;
				}	
			}	
			
			if(!isset($token)){
				//didn't find it in cache, get a new token
				$fields = array();
				$fields['consumer'] = $plagiarismsettings['vericite_accountid'];
	            $fields['consumerSecret'] = $plagiarismsettings['vericite_secretkey'];
				if(!$gradeassignment){
					//non instructors can only see their own items
					$fields['externalContentId'] = $fileId;
					$fields['userRole'] = 'Learner';
					//also make sure their token requires the context id, assignment id and user id
					$url = plagiarism_vericite_generate_url($plagiarismsettings['vericite_api'], $COURSE->id, $vericite['cmid'], $USER->id);
				}else{
					//send over the param for role so that instructors can see more details:
					$fields['userRole'] = 'Instructor';
					//instructors only need to pass in the site id since they can view anything in this site
					$url = plagiarism_vericite_generate_url($plagiarismsettings['vericite_api'], $COURSE->id);
				}
				$fields['tokenRequest'] = 'true';
				$c = new curl(array('proxy'=>true));
	                        $status = json_decode($c->post($url, $fields));
				if (! empty ( $status ) && isset ( $status->token )) {
					$token = $status->token;
					
					// store token in db to use again:
					$id = - 1;
					foreach ( $dbTokens as $dbToken ) {
						if ($dbToken->cm == $cmid && $dbToken->userid == $USER->id && ($gradeassignment || $dbToken->identifier == $fileId)) {
							// we found an existing score in the db, update it
							$id = $dbToken->id;
						}
						// this is a matched db item from the query above,
						// so we should update the token id and time no matter what
						$dbToken->token = $token;
						$dbToken->timeretrieved = time ();
						$DB->update_record ( 'plagiarism_vericite_tokens', $dbToken );
					}
					if ($id < 0) { // token doesn't already exist, add it
						$newelement = new object ();
						$newelement->cm = $cmid;
						$newelement->userid = $USER->id;
						if (! $gradeassignment) {
							// not an instructor, so make sure the token set the fileid
							$newelement->identifier = $fileId;
						}
						$newelement->timeretrieved = time ();
						$newelement->token = $token;
						$DB->insert_record ( 'plagiarism_vericite_tokens', $newelement );
					}
				}
			}
			if(isset($token)){
				//create url for user:
				$fields = array();
				$fields['consumer'] = $plagiarismsettings['vericite_accountid'];
				$fields['token'] = $token;
				$fields['externalContentId'] = $fileId;
				$fields['viewReport'] = 'true';
				if($gradeassignment){
					$fields['userRole'] = 'Instructor';
				}else{
					$fields['userRole'] = 'Learner';
				}
				
				$urlParams = "";
				foreach($fields as $key => $value){
					if(!empty($urlParams)){
						$urlParams .= "&";
					}
					$urlParams .= $key . "=" . rawurlencode($value);
				}
				//create a new url that passes in all the information for context, assignment and userId
				$url = plagiarism_vericite_generate_url($plagiarismsettings['vericite_api'], $COURSE->id, $vericite['cmid'], $USER->id);
				$results['reporturl'] = $url . "?" . $urlParams;
			}
		}
	}else{
		return false;
	}
        return $results;
    }

    /* hook to save plagiarism specific settings on a module settings page
     * @param object $data - data from an mform submission.
    */
    public function save_form_elements($data) {
	global $DB;
	if (!$this->get_settings()) {
            return;
        }
	    //array of posible plagiarism config options.
            $plagiarismelements = $this->config_options();
	    //array of posible plagiarism config options.
            //first get existing values
	    $existingelements = $DB->get_records_menu('plagiarism_vericite_config', array('cm'=>$data->coursemodule), '', 'name,id');
	    foreach ($plagiarismelements as $element) {
                $newelement = new object();
                $newelement->cm = $data->coursemodule;
                $newelement->name = $element;
                $newelement->value = (isset($data->$element) ? $data->$element : 0);
		if (isset($existingelements[$element])) { //update
                    $newelement->id = $existingelements[$element];
                    $DB->update_record('plagiarism_vericite_config', $newelement);
                } else { //insert
                    $DB->insert_record('plagiarism_vericite_config', $newelement);
                }

            }
            
         //now save the assignment title and instructrions and files (not a big deal if this fails, so wrap in try catch)
         try {
	         $plagiarismsettings = $this->get_settings();
	         if ($plagiarismsettings) {
		         $fields = array();
		         $fields['consumer'] = $plagiarismsettings['vericite_accountid'];
		         $fields['consumerSecret'] = $plagiarismsettings['vericite_secretkey'];
		         $fields['updateAssignmentDetails'] = 'true';
		         $fields['assignmentTitle'] = $data->name;
		         $fields['assignmentInstructions'] = $data->intro;
		         $url = plagiarism_vericite_generate_url($plagiarismsettings['vericite_api'], $data->course, $data->coursemodule);
		         
		         	$c = new curl(array('proxy'=>true));
		         	$status = json_decode($c->post($url, $fields));
		         
	         }
         } catch (Exception $e) {
         }
    }

    /**
     * hook to add plagiarism specific settings to a module settings page
     * @param object $mform  - Moodle form
     * @param object $context - current context
     */
    public function get_form_elements_module($mform, $context, $modulename = '') {
	global $CFG, $DB;
	$plagiarismsettings = $this->get_settings();
        if (!$plagiarismsettings) {
            return;
        }
	$cmid = optional_param('update', 0, PARAM_INT);
        if (!empty($cmid)) {
            $plagiarismvalues = $DB->get_records_menu('plagiarism_vericite_config', array('cm'=>$cmid), '', 'name,value');
        }
        $plagiarismelements = $this->config_options();
	//add form:
	$mform->addElement('header', 'plagiarismdesc', get_string('pluginname', 'plagiarism_vericite'));
	$ynoptions = array( 0 => get_string('no'), 1 => get_string('yes'));
	$mform->addElement('checkbox', 'use_vericite', get_string("usevericite", "plagiarism_vericite"));
	if(isset($plagiarismvalues['use_vericite'])){
		$mform->setDefault('use_vericite', $plagiarismvalues['use_vericite']);
	}else if(isset($plagiarismsettings['vericite_use_default'])){
		$mform->setDefault('use_vericite', $plagiarismsettings['vericite_use_default']);
	}
	$mform->addElement('checkbox', 'plagiarism_show_student_score', get_string("studentscorevericite", "plagiarism_vericite"));
	$mform->addHelpButton('plagiarism_show_student_score', 'studentscorevericite', 'plagiarism_vericite');
	$mform->disabledIf('plagiarism_show_student_score', 'use_vericite');
	if(isset($plagiarismvalues['plagiarism_show_student_score'])){
                $mform->setDefault('plagiarism_show_student_score', $plagiarismvalues['plagiarism_show_student_score']);
        }else if(isset($plagiarismsettings['vericite_student_score_default'])){
                $mform->setDefault('plagiarism_show_student_score', $plagiarismsettings['vericite_student_score_default']);
        }
	$mform->addElement('checkbox', 'plagiarism_show_student_report', get_string("studentreportvericite", "plagiarism_vericite"));
	$mform->addHelpButton('plagiarism_show_student_report', 'studentreportvericite', 'plagiarism_vericite');
	$mform->disabledIf('plagiarism_show_student_report', 'use_vericite');
	if(isset($plagiarismvalues['plagiarism_show_student_report'])){
                $mform->setDefault('plagiarism_show_student_report', $plagiarismvalues['plagiarism_show_student_report']);
        }else if(isset($plagiarismsettings['vericite_student_score_default'])){
                $mform->setDefault('plagiarism_show_student_report', $plagiarismsettings['vericite_student_report_default']);
        }
    }

    public function config_options() {
        return array('use_vericite', 'plagiarism_show_student_score', 'plagiarism_show_student_report');
    }

    /**
     * hook to allow a disclosure to be printed notifying users what will happen with their submission
     * @param int $cmid - course module id
     * @return string
     */
    public function print_disclosure($cmid) {
        global $OUTPUT;
        $plagiarismsettings = (array)get_config('plagiarism');
        //TODO: check if this cmid has plagiarism enabled.
        echo $OUTPUT->box_start('generalbox boxaligncenter', 'intro');
        $formatoptions = new stdClass;
        $formatoptions->noclean = true;
        echo format_text($plagiarismsettings['vericite_student_disclosure'], FORMAT_MOODLE, $formatoptions);
        echo $OUTPUT->box_end();
    }

    /**
     * hook to allow status of submitted files to be updated - called on grading/report pages.
     *
     * @param object $course - full Course object
     * @param object $cm - full cm object
     */
    public function update_status($course, $cm) {
        //called at top of submissions/grading pages - allows printing of admin style links or updating status
    }

    /**
     * called by admin/cron.php 
     *
     */
    public function cron() {
        //do any scheduled task stuff
    }
}

function plagiarism_vericite_get_scores($plagiarismsettings, $courseId, $cmid, $fileId, $userid){
	global $DB;
	$score = -1;
	$c = new curl(array('proxy'=>true));
	$url = plagiarism_vericite_generate_url($plagiarismsettings['vericite_api'], $courseId, $cmid);
	$fields = array();
	$fields['consumer'] = $plagiarismsettings['vericite_accountid'];
	$fields['consumerSecret'] = $plagiarismsettings['vericite_secretkey'];
	$fields['externalContentId'] = $fileId;
	$json = $c->post($url, $fields);
	$scores = json_decode($json);
	//store results in the cache and set $score if you find the appropriate file score
	$apiScores = array();
	if(!empty($scores)){
		foreach($scores as $resultUserId => $resultUserScores){
			foreach($resultUserScores as $resultCMID => $resultCMIDScores){
				foreach($resultCMIDScores as $resultContentId => $resultContentScore){
					$newelement = new object();
					$newelement->cm = $resultCMID;
					$newelement->userid = $resultUserId;
					$newelement->identifier = $resultContentId;
					$newelement->similarityscore = $resultContentScore;
					$newelement->timeretrieved = time();
					$apiScores = array_merge($apiScores, array($newelement));
					if($resultContentId == $fileId && $resultUserId == $userid){
						//we found this file's score, so set it:
						$score = $resultContentScore;
					}
				}
			}
		}
	}	
    	if(!empty($apiScores)){
		//we found some scores, let's update the DB:
		$dbScores = $DB->get_records('plagiarism_vericite_files', array('cm'=>$cmid), '', 'id, cm, userid, identifier, similarityscore, timeretrieved');
		foreach($apiScores as $apiScore){
			$id = -1;
			foreach($dbScores as $dbScore){
				if($dbScore->cm == $apiScore->cm && $dbScore->userid == $apiScore->userid 
					&& $dbScore->identifier == $apiScore->identifier){
					//we found an existing score in the db, update it
					$id = $dbScore->id;
					break;
				}
			}
			if ($id >= 0) { //update
				$apiScore->id = $id;
				$DB->update_record('plagiarism_vericite_files', $apiScore);
			} else { //insert
				$DB->insert_record('plagiarism_vericite_files', $apiScore);
			}
		}
	}

	return $score;
}


function plagiarism_vericite_ends_with($str, $test)
{
    return substr_compare($str, $test, -strlen($test), strlen($test)) === 0;
}

function plagiarism_vericite_generate_url($url, $context, $assignment=null, $user=null){
     if(!plagiarism_vericite_ends_with($url, "/")){
            $url .= "/";
     }
     if(isset($context)){
        $url = $url . $context . "/";
	if(isset($assignment)){
              $url .= $assignment . "/";
              if(isset($user)){
                    $url .= $user . "/";
              }
        }
    }
    return $url;
}

function plagiarism_vericite_get_css_rank ($score) {
    $rank = "none";
    if ($score >  90) { $rank = "1"; }
    else if ($score >  80) { $rank = "2"; }
    else if ($score >  70) { $rank = "3"; }
    else if ($score >  60) { $rank = "4"; }
    else if ($score >  50) { $rank = "5"; }
    else if ($score >  40) { $rank = "6"; }
    else if ($score >  30) { $rank = "7"; }
    else if ($score >  20) { $rank = "8"; }
    else if ($score >  10) { $rank = "9"; }
    else if ($score >=  0) { $rank = "10"; }

    return "rank$rank";
}

