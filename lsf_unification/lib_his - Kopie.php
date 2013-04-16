<?php
/**
 * Functions that are specific to HIS database, format and helptables containing his-formatted data
 **/
defined('MOODLE_INTERNAL') || die;
require_once($CFG->dirroot . '/local/lsf_unification/class_pg_lite.php');

/**
 * establish_secondary_DB_connection is a required function for the lsf_unification plugin
*/
function establish_secondary_DB_connection() {
	global $pgDB;
	if (!empty($pgDB) && !empty($pgDB->connection)) return;
	$pgDB = new pg_lite();
	if (!($pgDB->connect()===true))
		return false;
	return true;
}

/**
 * close_secondary_DB_connection is a required function for the lsf_unification plugin
 */
function close_secondary_DB_connection() {
	global $pgDB;
	if (empty($pgDB) || empty($pgDB->connection)) return;
	$pgDB->dispose();
}

/**
 * get_teachers_pid returns the pid (personen-id) connected to a specific username
 * @param $username the teachers username
 * @return $pid the teachers pid (personen-id)  
 */
function get_teachers_pid($username, $checkhis=false) {
	global $pgDB;
	$emailcheck = $checkhis?(" OR (login = '".$username."')"):"";
	$q = pg_query($pgDB->connection, "SELECT pid FROM public.learnweb_personal WHERE (zivk = '".$username."')".$emailcheck);
	if ($hislsf_teacher = pg_fetch_object($q)) {
		return $hislsf_teacher->pid;
	} 
	if (!$checkhis) {
		return get_teachers_pid($username, true);
	}
	return null;
	
}

/**
 * creates a list of courses assigned to a teacher
 * get_teachers_course_list is a required function for the lsf_unification plugin
 *
 * @param $username the teachers username
 * @param $longinfo level of detail
 * @param $checkmail not intended for manual setting, just for recursion
 * @return $courselist an array containing objects consisting of veranstid and info
 */
function get_teachers_course_list($username, $longinfo = false) {
	global $pgDB;
	$pid = get_teachers_pid($username);
	if (empty($pid)) return array();
	$q = pg_query($pgDB->connection, "SELECT public.learnweb_veranstaltung.veranstid, public.learnweb_veranstaltung.titel, public.learnweb_veranstaltung.urlveranst, public.learnweb_veranstaltung.semestertxt FROM public.learnweb_personalveranst INNER JOIN public.learnweb_veranstaltung ON public.learnweb_personalveranst.veranstid = public.learnweb_veranstaltung.veranstid WHERE public.learnweb_veranstaltung.sprache = 'de' AND (public.learnweb_personalveranst.pid = ".$pid.") AND (CURRENT_DATE - CAST(public.learnweb_veranstaltung.zeitstempel AS date)) < ".get_config('local_lsf_unification', 'max_import_age')." ORDER BY public.learnweb_veranstaltung.zeitstempel DESC");
	$courselist = array();
	while ($course = pg_fetch_object($q)) {
		if (!course_exists($course->veranstid)) {
			$course2 = new stdClass();
			$course2->veranstid = $course->veranstid;
			$course2->info = utf8_encode($course->titel).($longinfo?("&nbsp;&nbsp;(".$course->semestertxt.((!empty($course->urlveranst))?(", <a href='".$course->urlveranst."'>LINK</a>"):"").")"):"");
			$courselist[$course->veranstid] = $course2;
		}
	}
	return $courselist;
}

/**
 * returns true if a idnumber/veranstid assigned to a specific teacher
 * is_veranstid_valid is a required function for the lsf_unification plugin
 *
 * @param $veranstid idnumber/veranstid
 * @param $username the teachers username
 * @return $is_valid
 */
function is_veranstid_valid($veranstid, $username) {
	$courses = get_teachers_course_list($username);
	return !empty($courses[$veranstid]);
}

/**
 * find_origin_category is NOT a required function for the lsf_unification plugin, it is used internally only
 *
 * @param $quellid
 * @return $origin
 */
function find_origin_category($quellid) {
	global $pgDB;
	$origin = $quellid;
	do {
		$quellid = $origin;
		$q = pg_query($pgDB->connection, "SELECT quellid FROM public.learnweb_ueberschrift WHERE ueid = '".$quellid."'");
		if ($hislsf_title = pg_fetch_object($q)) {
			$q2 = pg_query($pgDB->connection, "SELECT quellid FROM public.learnweb_ueberschrift WHERE ueid = '".($hislsf_title->quellid)."'");
			if ($hislsf_title2 = pg_fetch_object($q2)) {
				$origin = $hislsf_title->quellid;
			}
		}
	} while (!empty($origin) && $quellid != $origin);
	return $origin;
}

/**
 * returns the default fullname according to a given veranstid
 * get_default_fullname is a required function for the lsf_unification plugin
 *
 * @param $veranstid idnumber/veranstid
 * @return $fullname
 */
function get_default_fullname($veranstid) {
	global $pgDB;
	$q = pg_query($pgDB->connection, "SELECT titel, semestertxt FROM public.learnweb_veranstaltung WHERE veranstid=".$veranstid." and sprache='de' ORDER BY public.learnweb_veranstaltung.zeitstempel DESC");
	$lsf_course = pg_fetch_object($q);
	$q2 = pg_query($pgDB->connection, "SELECT public.learnweb_personalveranst.vorname, public.learnweb_personalveranst.nachname FROM public.learnweb_personalveranst WHERE public.learnweb_personalveranst.veranstid=".$veranstid." ORDER BY public.learnweb_personalveranst.sort ASC");
	$personen = "";
	while ($person = pg_fetch_object($q2)) {
		$personen .= ", ".trim($person->vorname)." ".trim($person->nachname);
	}
	return utf8_encode(($lsf_course->titel)." ".trim($lsf_course->semestertxt).$personen);
}

/**
 * returns the default shortname according to a given veranstid
 * get_default_shortname is a required function for the lsf_unification plugin
 *
 * @param $veranstid idnumber/veranstid
 * @return $shortname
 */
function get_default_shortname($veranstid) {
	global $pgDB;
	$q = pg_query($pgDB->connection, "SELECT titel, semester FROM public.learnweb_veranstaltung WHERE veranstid=".$veranstid." and sprache='en' ORDER BY public.learnweb_veranstaltung.zeitstempel DESC");
	$lsf_course = pg_fetch_object($q);
	$i = "";
	foreach (explode(" ", $lsf_course->titel) as $word) {
		$i .= strtoupper($word[0]);
	}
	return utf8_encode($i."-".substr($lsf_course->semester,0,4)."_".substr($lsf_course->semester,-1));
}

/**
 * returns the default summary according to a given veranstid
 * get_default_summary is a required function for the lsf_unification plugin
 *
 * @param $veranstid idnumber/veranstid
 * @return $summary
 */
function get_default_summary($veranstid) {
	global $pgDB;
	$q = pg_query($pgDB->connection, "SELECT urlveranst FROM public.learnweb_veranstaltung WHERE veranstid=".$veranstid." and sprache='en' ORDER BY public.learnweb_veranstaltung.zeitstempel DESC");
	$lsf_course = pg_fetch_object($q);
	return utf8_encode("Imported course (".$lsf_course->urlveranst.")");
}

/**
 * returns the default startdate according to a given veranstid
 * get_default_startdate is a required function for the lsf_unification plugin
 *
 * @param $veranstid idnumber/veranstid
 * @return $startdate
 */
function get_default_startdate($veranstid) {
	global $pgDB;
	$q = pg_query($pgDB->connection, "SELECT semester FROM public.learnweb_veranstaltung WHERE veranstid=".$veranstid." ORDER BY public.learnweb_veranstaltung.zeitstempel DESC");
	$semester = (pg_fetch_object($q)->semester)."";
	$year = substr($semester, 0, 4);
	$month = (substr($semester, -1) == "1")?4:10;
	return mktime(0, 0, 0, $month, 1, $year);
}

/**
 * returns if a course is already imported
 * course_exists is a required function for the lsf_unification plugin
 *
 * @param $veranstid idnumber/veranstid
 * @return $is_course_existing
 */
function course_exists($veranstid) {
	global $DB;
	if ($DB->record_exists("local_lsf_course",array("veranstid"=>($veranstid)))) {
		if (!($a = $DB->get_records("course",array("idnumber"=>($veranstid))))) {
			$DB->delete_records("local_lsf_course",array("veranstid"=>($veranstid)));
		} else {
			return true;
		}
	}
	return false;
}

/**
 * returns if a shortname is valid
 * is_shortname_valid is a required function for the lsf_unification plugin
 *
 * @param $veranstid idnumber/veranstid
 * @param $shortname shortname
 * @return $is_shortname_valid
 */
function is_shortname_valid($veranstid, $shortname) {
	global $pgDB;
	$q = pg_query($pgDB->connection, "SELECT titel, semester FROM public.learnweb_veranstaltung WHERE veranstid=".$veranstid." and sprache='en' ORDER BY public.learnweb_veranstaltung.zeitstempel DESC");
	$lsf_course = pg_fetch_object($q);
	$string = "-".substr($lsf_course->semester,0,4)."_".substr($lsf_course->semester,-1);
	return (substr($shortname,-strlen($string)) == $string);
}

/**
 * returns if a shortname hint, if it is invalid
 * shortname_hint is a required function for the lsf_unification plugin
 *
 * @param $veranstid idnumber/veranstid
 * @return $hint
 */
function shortname_hint($veranstid) {
	global $pgDB;
	$q = pg_query($pgDB->connection, "SELECT titel, semester FROM public.learnweb_veranstaltung WHERE veranstid=".$veranstid." and sprache = 'en' ORDER BY public.learnweb_veranstaltung.zeitstempel DESC");
	$lsf_course = pg_fetch_object($q);
	$string = "-".substr($lsf_course->semester,0,4)."_".substr($lsf_course->semester,-1);
	return $string;
}

/**
 * enroles teachers to a freshly created course
 * enrole_teachers is a required function for the lsf_unification plugin
 *
 * @param $veranstid idnumber/veranstid
 * @param $courseid id of moodle course
 * @return $warnings
 */
function enrole_teachers($veranstid, $courseid) {
	global $pgDB, $DB, $CFG;
	$warnings = "";
	$q = pg_query($pgDB->connection, "SELECT public.learnweb_personal.* FROM public.learnweb_personal INNER JOIN public.learnweb_personalveranst ON public.learnweb_personalveranst.pid = public.learnweb_personal.pid WHERE public.learnweb_personalveranst.veranstid=".$veranstid);
	while ($lsf_user = pg_fetch_object($q)) {
		unset($teacher);
		if (!empty($lsf_user->zivk)) {
			$teacher = $DB->get_record("user", array("username" => $lsf_user->zivk));
		}
		//if user cannot be found by zivk try to find user by login that is manually set in his
		if (empty($teacher) && !empty($lsf_user->login)) {
			$teacher = $DB->get_record("user", array("username" => $lsf_user->login));
		}
		if (empty($teacher) || !enrol_try_internal_enrol($courseid, $teacher->id, get_config('local_lsf_unification', 'roleid_teacher'))) {
			$warnings = $warnings."\n".get_string('warning_cannot_enrol_other','local_lsf_unification')." (".$lsf_user->zivk.", ".$lsf_user->login." ".$lsf_user->vorname." ".$lsf_user->nachname.")";
		}
	}
	return $warnings;
}

/**
 * sets timestamp for course-import
 * set_course_created is a required function for the lsf_unification plugin
 *
 * @param $veranstid idnumber/veranstid
 * @param $courseid id of moodle course
 */
function set_course_created($veranstid, $courseid) {
	global $DB;
	$courseentry = new stdClass();
	$courseentry->veranstid = $veranstid;
	$courseentry->mdlid = $courseid;
	$courseentry->timestamp = time();
	$DB->insert_record("local_lsf_course",$courseentry);
}


/**
 * returns mapped categories for a specified course
 * get_courses_categories is a required function for the lsf_unification plugin
 *
 * @param $veranstid idnumber/veranstid
 * @return $courselist
 */
function get_courses_categories($veranstid, $update_helptables_if_necessary=true) {
	global $pgDB, $DB, $CFG;
	$helpfuntion1 = function($array_el) {
		return $array_el->origin;
	};
	$helpfuntion2 = function($array_el) {
		return $array_el->name;
	};
	$helpfuntion3 = function($array_el) {
		return $array_el->mdlid;
	};
	$q = pg_query($pgDB->connection, "SELECT ueid FROM public.learnweb_ueberschrift WHERE veranstid=".$veranstid."");
	$choices = array();
	while ($hislsf_title = pg_fetch_object($q)) $ueids = (empty($ueids)?"":($ueids.", ")).("".$hislsf_title->ueid."");
	$other_ueids_sql = "SELECT parent FROM ".$CFG->prefix."local_lsf_categoryparenthood WHERE child in (".$ueids.")";
	$origins_sql = "SELECT origin FROM ".$CFG->prefix."local_lsf_category WHERE ueid in (".$other_ueids_sql.") OR ueid in (".$ueids.")";
	$origins = implode(", ", array_map($helpfuntion1, $DB->get_records_sql($origins_sql)));
	if (!get_config('local_lsf_unification', 'subcategories')) {
		$courses_sql = "SELECT mdlid, name FROM (".$CFG->prefix."local_lsf_category JOIN ".$CFG->prefix."course_categories ON ".$CFG->prefix."local_lsf_category.mdlid = ".$CFG->prefix."course_categories.id) WHERE ueid in (".$origins.") ORDER BY sortorder";
		$courses = array_map($helpfuntion2, $DB->get_records_sql($courses_sql));
	} else {
		$courses_sql = "SELECT mdlid, name FROM (".$CFG->prefix."local_lsf_category JOIN ".$CFG->prefix."course_categories ON ".$CFG->prefix."local_lsf_category.mdlid = ".$CFG->prefix."course_categories.id) WHERE ueid in (".$origins.") ORDER BY sortorder";
		$maincourses = implode(", ", array_map($helpfuntion3, $DB->get_records_sql($courses_sql)));
		if (empty($maincourses)) return array(get_config('local_lsf_unification', 'defaultcategory') => get_config('local_lsf_unification', 'max_import_age'));
		$courses_and_subcourses_sql = "SELECT id, name FROM ".$CFG->prefix."course_categories WHERE id in (".$maincourses.") OR parent in (".$maincourses.") ORDER BY sortorder";
		$courses = array_map($helpfuntion2, $DB->get_records_sql($courses_and_subcourses_sql));
	}
	if ($update_helptables_if_necessary && (count($courses) == 0)) {
		insert_missing_helptable_entries(false);
		return get_courses_categories($veranstid, false);
	}
	return $courses;
}




/**
 * updates the helptables
 * insert_missing_helptable_entries is a required function for the lsf_unification plugin
 *
 * @param $veranstid idnumber/veranstid
 * @return $courselist
 */
function insert_missing_helptable_entries($debugoutput=false) {
	$a = 0;
	global $pgDB, $DB;
	$list1 = "";
	$list2 = "";
	$records1 = $DB->get_recordset('local_lsf_category', null, '', 'ueid');
	$records2 = $DB->get_recordset('local_lsf_categoryparenthood', null, '', 'child, parent');
	$records1_unique = array();
	$records2_unique = array();
	foreach ($records1 as $record1) $records1_unique[$record1->ueid]=true;
	foreach ($records2 as $record2) $records2_unique[$record2->child][$record2->parent]=true;

	$q_main = pg_query($pgDB->connection, "SELECT ueid, uebergeord, uebergeord, quellid, txt, zeitstempel FROM public.learnweb_ueberschrift");
	while ($hislsf_title = pg_fetch_object($q_main)) {
		if (!isset($records1_unique[$hislsf_title->ueid])) {
			// create match-table-entry if not existing
			$entry = new stdClass();
			$entry->ueid = $hislsf_title->ueid;
			$entry->parent = empty($hislsf_title->uebergeord)?($hislsf_title->ueid):($hislsf_title->uebergeord);
			$entry->origin = find_origin_category($hislsf_title->ueid);
			$entry->mdlid = 0;
			$entry->timestamp = strtotime($hislsf_title->zeitstempel);
			$entry->txt = utf8_encode($hislsf_title->txt);
			//die("<pre>".print_r($entry,true));
			if ($debugoutput) echo "!";
			try {
				$DB->insert_record("local_lsf_category", $entry, true);
				$records1_unique[$hislsf_title->ueid] = true;
				if ($debugoutput) echo "x";
			} catch(Exception $e) {
				try {
					$entry->txt = utf8_encode(delete_bad_chars($hislsf_title->txt));
					$DB->insert_record("local_lsf_category", $entry, true);
					$records1_unique[$hislsf_title->ueid] = true;
					if ($debugoutput) echo "x";
				} catch(Exception $e) {
					if ($debugoutput) print("<pre>FEHLER1 ".print_r($e,true)."".print_r($DB->get_last_error(),true));
				}
			}
			if ($debugoutput) echo "<br>";
			$a++;
		}
		if (!isset($records2_unique[$hislsf_title->ueid][$hislsf_title->uebergeord])) {
			// create parenthood-table-entry if not existing
			$child = $hislsf_title->ueid;
			$ueid = $hislsf_title->ueid;
			$parent = $hislsf_title->ueid;
			$fullname = "";
			$distance = 0;
			if ($debugoutput) echo "?"; //((
			do {
				$ueid = $parent;
				$distance++;
				$q2 = pg_query($pgDB->connection, "SELECT ueid, uebergeord, txt FROM public.learnweb_ueberschrift WHERE ueid = '".$ueid."'");
				if (($hislsf_title2 = pg_fetch_object($q2)) && ($hislsf_title2->uebergeord != $ueid)) {
					$parent = $hislsf_title2->uebergeord;
					$fullname = ($hislsf_title2->txt).(empty($fullname)?"":("/".$fullname));
					if (!empty($parent) && !isset($records2_unique[$child][$parent])) {
						$entry = new stdClass();
						$entry->child = $child;
						$entry->parent = $parent;
						$entry->distance = $distance;
						try {
							$DB->insert_record("local_lsf_categoryparenthood", $entry, true);
							$records2_unique[$child][$parent] = true;
							if ($debugoutput) echo "x";
						} catch(Exception $e) {
							if ($debugoutput) print("<pre>FEHLER2 ".print_r($e,true)."".print_r($DB->get_last_error(),true));
						}
					}
				}
			} while (!empty($parent) && ($ueid != $parent));
			$entry = $DB->get_record('local_lsf_category', array("ueid"=>$hislsf_title->ueid));
			$entry->txt2 = utf8_encode($fullname);
			try {
				$DB->update_record('local_lsf_category', $entry, true);
			} catch(Exception $e) {
				try {
					$entry->txt2 = delete_bad_chars($entry->txt2);
					$DB->update_record('local_lsf_category', $entry, true);
				} catch(Exception $e) {
					if ($debugoutput) print("<pre>FEHLER2 ".print_r($e,true)."".print_r($DB->get_last_error(),true));
				}
			}
			if ($debugoutput) echo "<br>"; //((
		}
		if ($debugoutput && (($a % 80) == 0)) {
			echo "<br>"; $a++;
		}
	}
}


/**
 * delete_bad_chars is NOT a required function for the lsf_unification plugin, it is used internally only
 *
 * @param $str
 * @return $str
 */
function delete_bad_chars($str) {
	return strtr(utf8_encode($str), array(
			"\xc2\x96" => "",	// EN DASH
			"\xc2\x97" => "",	// EM DASH
			"\xc2\x84" => ""	// DOUBLE LOW-9 QUOTATION MARK
	));
}

/**
 * returns a list of (newest copies of) children to a parents (and the parent's copies)
 */
function get_newest_sublevels($origins) {
	global $DB, $CFG;
	$helpfuntion1 = function($array_el) {
		return $array_el->ueid;
	};
	$origins_sql = "SELECT ueid FROM ".$CFG->prefix."local_lsf_category WHERE origin in (".$origins.")";
	$copies = implode(", ", array_map($helpfuntion1, $DB->get_records_sql($origins_sql)));

	$sublevels_sql = "SELECT * FROM (SELECT max(ueid) as max_ueid, origin FROM ".$CFG->prefix."local_lsf_category WHERE parent in (".$copies.") AND ueid not in (".$origins.") GROUP BY origin) AS a JOIN ".$CFG->prefix."local_lsf_category ON a.max_ueid = ".$CFG->prefix."local_lsf_category.ueid WHERE ".$CFG->prefix."local_lsf_category.timestamp >= (".(time() - 2 * 365 * 24 * 60 * 60).") ORDER BY txt";
	return $DB->get_records_sql($sublevels_sql);
}

/**
 * returns if a category has children
 */
function has_sublevels($origins) {
	global $CFG, $DB;
	$sublevels_sql = "SELECT id FROM ".$CFG->prefix."local_lsf_category WHERE parent in (".$origins.") AND ueid not in (".$origins.")";
	return (count($DB->get_records_sql($sublevels_sql)) > 0);
}

/**
 * returns the newest copy to a given id
 */
function get_newest_element($id) {
	global $CFG, $DB;
	$origins = $DB->get_record("local_lsf_category", array("ueid"=>$id), "origin")->origin;
	$sublevels_sql = "SELECT max(ueid) as max_ueid, origin FROM ".$CFG->prefix."local_lsf_category WHERE origin in (".$origins.")";
	$ueid = array_shift($DB->get_records_sql($sublevels_sql))->max_ueid;
	return $DB->get_record("local_lsf_category", array("ueid"=>$ueid));
}

/**
 * returns the parent of the newest copy to the given id
 */
function get_newest_parent($id) {
	global $CFG, $DB;
	$parent = get_newest_element($id)->parent;
	return $DB->get_record("local_lsf_category", array("ueid"=>$parent));
}

/**
 * returns the moodle-id given to a lsf-id
 */
function get_mdlid($id) {
	global $CFG, $DB;
	$origin = $DB->get_record("local_lsf_category", array("ueid"=>$id), "origin")->origin;
	$mdlid = $DB->get_record("local_lsf_category", array("ueid"=>$origin), "mdlid")->mdlid;
	return $mdlid;
}

/**
 * returns the moodle-name given to a lsf-id
 */
function get_mdlname($id) {
	global $CFG, $DB;
	$origin = $DB->get_record("local_lsf_category", array("ueid"=>$id), "origin")->origin;
	$mdlid = $DB->get_record("local_lsf_category", array("ueid"=>$origin), "mdlid")->mdlid;
	$cat = $DB->get_record("course_categories", array("id"=>$mdlid), "name");
	return $cat->name;
}

/**
 * sets a category-mapping
 */
function set_cat_mapping($ueid, $mdlid) {
	global $DB;
	$obj = $DB->get_record("local_lsf_category",array("ueid"=>$ueid));
	$obj->mdlid = $mdlid;
	$DB->update_record("local_lsf_category", $obj);
}

/**
 * returns a list of the topmost elements in the lsf-category hierarchy
 */
function get_his_toplevel_originids() {
	global $DB, $CFG;
	$helpfuntion1 = function($array_el) {
		return $array_el->origin;
	};
	$origins_sql = "SELECT origin FROM ".$CFG->prefix."local_lsf_category WHERE ueid = origin AND parent = ueid";
	return array_map($helpfuntion1, $DB->get_records_sql($origins_sql));
}

/**
 * returns a list of the topmost elements in the mdl-category hierarchy
 */
function get_mdl_toplevels() {
	global $DB, $CFG;
	$maincategories_sql = "SELECT id, name FROM ".$CFG->prefix."course_categories WHERE parent=0 ORDER BY sortorder";
	return $DB->get_records_sql($maincategories_sql);
}

/**
 * returns a list of children to a given parent.id in the mdl-category hierarchy
 */
function get_mdl_sublevels($mainid) {
	global $DB, $CFG;
	$subcats_sql = "SELECT id, name, path FROM ".$CFG->prefix."course_categories WHERE path LIKE '/".$mainid."/%' OR id=".$mainid." ORDER BY sortorder";
	return $DB->get_records_sql($subcats_sql);
}