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
 * An activity to interface with WebEx.
 *
 * @package   mod_webexactvity
 * @copyright Eric Merrill (merrill@oakland.edu)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_webexactivity;

class webex_meeting {
    private $latesterrors = null;
    private $meetingrecord = null;

    private $webex;

    public function __construct($meeting) {
        global $DB;

        $this->webex = new webex();

        if (is_numeric($meeting)) {
            $this->meetingrecord = $DB->get_record('webexactivity', array('id' => $meeting));
        }
        if (is_object($meeting)) {
            $this->meetingrecord = $meeting;
        }

        if (!$this->meetingrecord) {
            // TODO Throw exception.
            return false;
        }
    }


    // ---------------------------------------------------
    // Support Functions.
    // ---------------------------------------------------
    public static function get_base_url() {
        $host = get_config('webexactivity', 'url');

        if ($host === false) {
            return false;
        }
        $url = 'https://'.$host.'.webex.com/'.$host;

        return $url;
    }

    public function get_meeting_webex_user() {
        global $DB, $USER;

        if (isset($this->meetingrecord->creatorwebexuser) && $this->meetingrecord->creatorwebexuser) {
            $webexuser = $DB->get_record('webexactivity_users', array('id' => $this->meetingrecord->creatorwebexuser));
        } else {
            $webexuser = $this->webex->get_webex_user($USER);
        }

        return $webexuser;
    }

    // ---------------------------------------------------
    // Meeting Functions.
    // ---------------------------------------------------
    public function create_or_update($user) {

        if ($this->meetingrecord->starttime < time()) {
            $this->meetingrecord->starttime = time() + 60;
        }

        $webexuser = $this->webex->get_webex_user($user);

        if (isset($this->meetingrecord->meetingkey) && $this->meetingrecord->meetingkey) {
            $xml = xml_generator::update_training_session($this->meetingrecord);
        } else {
            $xml = xml_generator::create_training_session($this->meetingrecord);
            $this->meetingrecord->creatorwebexuser = $webexuser->id;
        }
        $this->meetingrecord->xml = $xml;
        $response = $this->webex->get_response($xml, $webexuser);

        return $this->process_training_response($response);
    }

    private function process_training_response($response) {
        global $DB;

        if ($response === false) {
            return false;
        }

        if (empty($response)) {
            return true;
        }

        if (isset($response['train:sessionkey']['0']['#'])) {
            $this->meetingrecord->meetingkey = $response['train:sessionkey']['0']['#'];
        }

        if (isset($response['train:additionalInfo']['0']['#']['train:guestToken']['0']['#'])) {
            $this->meetingrecord->guesttoken = $response['train:additionalInfo']['0']['#']['train:guestToken']['0']['#'];
        }

        if (isset($response['train:eventID']['0']['#'])) {
            $this->meetingrecord->eventid = $response['train:eventID']['0']['#'];
        }

        if (isset($response['train:hostKey']['0']['#'])) {
            $this->meetingrecord->hostkey = $response['train:hostKey']['0']['#'];
        }

        if (isset($response['train:attendees']['0']['#'])) {
            $participants = $response['train:attendees']['0']['#']['sess:participants'];
            foreach ($participants as $participant) {
                $participant = $participant['#']['sess:participant']['0']['#'];
                $email = $participant['sess:person']['0']['#']['com:email']['0']['#'];
                if (strcmp($email, 'moodle_dummy@example.com') === 0) {
                    $this->meetingrecord->guestuserid = $participant['sess:contactID']['0']['#'];
                    break;
                }
            }
        }

        if ($DB->update_record('webexactivity', $this->meetingrecord)) {
            return true;
        }

        return false;
    }

    public function delete_training($user) {
        $webexuser = $this->webex->get_webex_user($user);

        if (isset($this->meetingrecord->meetingkey) && $this->meetingrecord->meetingkey) {
            $xml = xml_generator::delete_training_session($this->meetingrecord);
            $response = $this->webex->get_response($xml, $webexuser);

            if ($response === false) {
                return false;
            }
            return true;
        }

        return false;
    }

    public function get_meeting_info() {
        $xml = xml_generator::get_meeting_info($this->meetingrecord->meetingkey);

        if (!($response = $this->webex->get_response($xml))) {
            return false;
        }

        $response = $this->webex->get_response($xml);


        return $response;
    }

    public function get_training_info() {
        global $DB, $USER;

        $webexuser = $this->get_meeting_webex_user();

        $xml = xml_generator::get_training_info($this->meetingrecord->meetingkey);

        if (!($response = $this->webex->get_response($xml, $webexuser))) {
            return false;
        }

        $this->process_training_response($response);

        return $response;
    }

    public function get_meeting_host_url($returnurl = false) {
        $baseurl = self::get_base_url();
        $url = $baseurl.'/m.php?AT=HM&MK='.$this->meetingrecord->meetingkey;
        if ($returnurl) {
            $url .= '&BU='.urlencode($returnurl);
        }

        return $url;
    }

    public function get_meeting_join_url($returnurl = false, $user = false) {
        $baseurl = self::get_base_url();

        // Known user link.
        if ($user) {
            $url = $baseurl.'/m.php?AT=JM&MK='.$this->meetingrecord->meetingkey;
            $url .= '&AE='.$user->email.'&AN='.$user->firstname.'%20'.$user->lastname;
            if ($returnurl) {
                $url .= '&BU='.urlencode($returnurl);
            }

            return $url;
        }

        // External Link.
        if ((!isset($this->meetingrecord->eventid) || $this->meetingrecord->eventid === null) ||
                (!isset($this->meetingrecord->guestuserid) || $this->meetingrecord->guestuserid === null)) {
            $this->add_dummy_participant();
            $this->get_training_info();
        }

        $url = $baseurl.'/k2/j.php?ED='.$this->meetingrecord->eventid.'&UID='.$this->meetingrecord->guestuserid;

        return $url;
    }

    public function meeting_is_available($host = false) {
        if ($host) {
            if ($this->meetingrecord->status == WEBEXACTIVITY_STATUS_IN_PROGRESS) {
                return true;
            }

            $grace = get_config('webexactivity', 'meetingclosegrace');
            $endtime = $this->meetingrecord->starttime + ($this->meetingrecord->duration * 60) + ($grace * 60);

            if (time() > $endtime) {
                return false;
            }

            return true;
        } else {
            if ($this->meetingrecord->status == WEBEXACTIVITY_STATUS_IN_PROGRESS) {
                return true;
            } else {
                $grace = get_config('webexactivity', 'meetingclosegrace');

                $starttime = $this->meetingrecord->starttime - (20 * 60);
                if (time() < $starttime) {
                    return false;
                }

                $endtime = $this->meetingrecord->starttime + ($this->meetingrecord->duration * 60) + ($grace * 60);
                if (time() > $endtime) {
                    return false;
                }

                return true;
            }
        }

        // Should never get here.
        return false;
    }

    public function add_webexuser_host($webexuser) {
        global $DB;

        $creator = $this->get_meeting_webex_user();
        if ($webexuser->webexid === $creator->webexid) {
            return true;
        }

        $moodleuser = $DB->get_record('user', array('id' => $webexuser->moodleuserid));
        $user = new \stdClass();
        $user->webexid = $webexuser->webexid;
        $user->email = $moodleuser->email;
        $user->firstname = $moodleuser->firstname;
        $user->lastname = $moodleuser->lastname;

        $data = new \stdClass();
        $data->meetingkey = $this->meetingrecord->meetingkey;
        $data->hostusers = array($user);

        $xml = xml_generator::update_training_session($data);

        if (!($response = $this->webex->get_response($xml, $creator))) {
            return false;
        }

        return true;
    }

    // Unused.
    public function add_hosts($users) {
        $webexuser = $this->get_meeting_webex_user();

        $meeting = clone $this->meetingrecord;
        $meeting->hostusers = $users;

        $xml = xml_generator::update_training_session($meeting);

        $this->meetingrecord->xml = $xml;
        $response = $this->webex->get_response($xml, $webexuser);

        if ($response === false) {
            return false;
        }
    }

    public function add_dummy_participant() {
        $creator = $this->get_meeting_webex_user();

        $data = new \stdClass();
        $data->meetingkey = $this->meetingrecord->meetingkey;
        $data->dummyparticipant = true;

        $xml = xml_generator::update_training_session($data);

        if (!($response = $this->webex->get_response($xml, $creator))) {
            return false;
        }

        return true;
    }

    // ---------------------------------------------------
    // Recording Functions.
    // ---------------------------------------------------
    public function retrieve_recordings() {
        global $DB;

        $this->meetingrecord->laststatuscheck = time();
        $DB->update_record('webexactivity', $this->meetingrecord);

        if (!$this->meetingrecord->meetingkey) {
            return;
        }

        $params = new \stdClass();
        $params->meetingkey = $this->meetingrecord->meetingkey;

        $xml = xml_generator::list_recordings($params);

        if (!($response = $this->webex->get_response($xml))) {
            return false;
        }

        $recordings = $response['ep:recording'];

        foreach ($recordings as $recording) {
            $recording = $recording['#'];

            $rec = new \stdClass();
            $rec->webexid = $this->meetingrecord->id;
            $rec->meetingkey = $this->meetingrecord->meetingkey;
            $rec->recordingid = $recording['ep:recordingID'][0]['#'];
            $rec->hostid = $recording['ep:hostWebExID'][0]['#'];
            $rec->name = $recording['ep:name'][0]['#'];
            $rec->timecreated = strtotime($recording['ep:createTime'][0]['#']);
            $rec->streamurl = $recording['ep:streamURL'][0]['#'];
            $rec->fileurl = $recording['ep:fileURL'][0]['#'];
            $rec->duration = $recording['ep:duration'][0]['#'];

            if (!$DB->get_record('webexactivity_recording', array('recordingid' => $rec->recordingid))) {
                $DB->insert_record('webexactivity_recording', $rec);
            }
        }
    }

}