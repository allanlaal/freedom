<?php

use UnitedPrototype\GoogleAnalytics\Visitor as GAVisitor;
use UnitedPrototype\GoogleAnalytics\Session as GASession;
use UnitedPrototype\GoogleAnalytics\Event as GAEvent;
use UnitedPrototype\GoogleAnalytics\Tracker as GATracker;

class FeedController extends BaseController {

  // download or fetch Facebook feed
  public function getDownloadFeed(){

    // headers
    // header('Content-type: text/calendar;charset=utf-8');
    // header('Content-Disposition: attachment; filename=feed.ics');

    // arguments
    $user_id = isset($_GET["user_id"]) ? $_GET["user_id"] : null;
    $secure_hash = isset($_GET["secure_hash"]) ? $_GET["secure_hash"] : null;
    $access_token = isset($_GET["access_token"]) ? $_GET["access_token"] : null;

    // get user id by access token
    if(is_null($user_id) && isset($access_token)){
      $user_id = $this->get_user_id_by_access_token($access_token);

    // get access token by user id
    }elseif(isset($user_id) && isset($secure_hash)){
      $access_token = $this->get_access_token_by_user_id($user_id, $secure_hash);

    // TODO: log with analytics
    }

    // set access token
    $this->facebook->setAccessToken($access_token);

    // Output
    $header = $this->get_calendar_header();
    $body = $this->get_calendar_body($user_id);
    $footer = "END:VCALENDAR\r\n";
    return $header . $body . $footer;
  }

  private function get_calendar_header(){
    $header = "BEGIN:VCALENDAR\r\n";
    $header .= "VERSION:2.0\r\n";
    $header .= "PRODID:-//Facebook//NONSGML Facebook Events V1.0//EN\r\n";
    $header .= "X-WR-CALNAME:FB Freedom\r\n";
    $header .= "X-PUBLISHED-TTL:PT12H\r\n";
    $header .= "X-ORIGINAL-URL:https://www.facebook.com/events/\r\n";
    $header .= "CALSCALE:GREGORIAN\r\n";
    $header .= "METHOD:PUBLISH\r\n";

    return $header;
  }

  private function get_calendar_body($user_id){

    // get events
    list($events, $failed) = $this->get_events($user_id);

    if($failed){
      return $this->get_instructional_body();
    }else{
      return $this->get_normal_body($events);
    }
  }

  private function get_events($user_id){
    $events = null;
    $error_message = null;
    $failed = false;

    try {
      $data = $this->facebook->api('me?fields=events.limit(100000).fields(description,end_time,id,location,owner,rsvp_status,start_time,name,timezone,updated_time,is_date_only)','GET');
      $events = isset($data["events"]["data"]) ? $data["events"]["data"] : array();

    } catch (Exception $e) {
      $error_message = $e->getMessage();
      $failed = true;
    }

    // Track in GA
    $this->track_download_feed_event($user_id, $error_message);

    return array($events, $failed);
  }

  private function track_download_feed_event($user_id, $error_message){

    // category
    $category = !is_null($error_message) ? "feedDownload - error" : "feedDownload - success";

    // action
    $action = !is_null($error_message) ? "error: " . $error_message : "success";

    // label
    $label = $user_id;

    // visitor
    $visitor = new GAVisitor();
    $visitor->setIpAddress($_SERVER['REMOTE_ADDR']);
    if(isset($_SERVER['HTTP_USER_AGENT'])){
      $visitor->setUserAgent($_SERVER['HTTP_USER_AGENT']);
    }

    // Google Analytics: track event
    $session = new GASession();
    $event = new GAEvent($category, $action, $label);
    $tracker = new GATracker('UA-39209285-1', 'freedom.konscript.com');
    $tracker->trackEvent($event, $session, $visitor);
  }

  private function get_event_dt($event){
    // all day event without time and end
    if($event["is_date_only"]){
      $start_time = $this->date_string_to_time($event['start_time']);
      $end_time = $this->date_string_to_time($event['start_time'], "+1 day");
      $time_type = "VALUE=DATE";

    // specific time
    }else{
      $time_type = "VALUE=DATE-TIME";

      // without end (set end as 3 hours after start)
      if(!isset($event['end_time'])){
        $start_time = $this->date_string_to_time($event['start_time']);
        $end_time = $this->date_string_to_time($event['start_time'], "+3 hours");

      // specific start and end time
      }else{
        $start_time = $this->date_string_to_time($event['start_time']);
        $end_time = $this->date_string_to_time($event['end_time']);
      }
    }

    return array(
      "start" => $time_type . ":" . $start_time,
      "end"   => $time_type . ":" . $end_time
    );
  }

  private function get_normal_body($events){

    // add question mark to event title if rsvp is "unsure"
    function get_event_name($event){
      if($event["rsvp_status"] === "unsure" || $event["rsvp_status"] === "not_replied"){
        return $event["name"] . " [?]";
      }else{
        return $event["name"];
      }
    }

    function get_event_url($event){
      return "http://www.facebook.com/" . $event['id'];
    }

    // event description is dependent on context: whether the "events" is a birthday or a regular event
    function get_event_description($event){
      if($event["rsvp_status"] == "birthday"){
        return "Say congratulation:\n" . get_event_url($event);
      }else{
        // description
        if(!isset($event["description"])){
          $event["description"] = "";
        }
        return $event["description"] . "\n\nGo to event:\n" . get_event_url($event);
      }
    }

    $body = "";
    foreach($events as $event){

      // updated time
      $updated_time = $this->date_string_to_time($event['updated_time']);

      $body .= "BEGIN:VEVENT\r\n";
      $body .= "DTSTAMP:" . $updated_time . "\r\n";
      $body .= "LAST-MODIFIED:" . $updated_time . "\r\n";
      $body .= "CREATED:" . $updated_time . "\r\n";
      $body .= "SEQUENCE:0\r\n";

      // Owner
      $owner = isset($event["owner"]["name"]) ? $event["owner"]["name"] : "Freedom Calendar";
      $body .= "ORGANIZER;CN=" . $this->ical_encode_text($owner) . ":MAILTO:noreply@facebookmail.com\r\n";

      // Datetime start/end
      $event_dt = $this->get_event_dt($event);
      $body .= "DTSTART;" . $event_dt["start"] . "\r\n";
      $body .= "DTEND;" . $event_dt["end"] . "\r\n";

      // if(isset($event["timezone"])){
      //   $body .= "TZID:" . $event["timezone"] . "\r\n";
      // }

      $body .= "UID:e" . $event['id'] . "@facebook.com\r\n";

      // Title
      $body .= "SUMMARY:" . $this->ical_encode_text(get_event_name($event)) . "\r\n";

      // location
      if(isset($event["location"])){
        $body .= "LOCATION:" . $this->ical_encode_text($event["location"]) . "\r\n";
      }

      // URL
      $body .= "URL:" . get_event_url($event) . "/\r\n";

      // Description
      $body .= "DESCRIPTION:" . $this->ical_encode_text(get_event_description($event)) . "\r\n";

      $body .= "CLASS:PUBLIC\r\n";
      $body .= "STATUS:CONFIRMED\r\n";
      $body .= "PARTSTAT:ACCEPTED\r\n";
      $body .= "END:VEVENT\r\n";
    }

    return $body;
  }

  // login expired. Add dummy event to calendar, which describes how to re-enable calendar (re-login)
  private function get_instructional_body(){
    $event = "";
    $event .= "BEGIN:VEVENT\r\n";
    $event .= "DTSTAMP:" . $this->date_string_to_time() . "\r\n";
    $event .= "LAST-MODIFIED:" . $this->date_string_to_time() . "\r\n";
    $event .= "CREATED:" . $this->date_string_to_time() . "\r\n";
    $event .= "SEQUENCE:0\r\n";
    $event .= "DTSTART;VALUE=DATE-TIME:" . $this->date_string_to_time(null, "+24 hours") . "\r\n";
    $event .= "DTEND;VALUE=DATE-TIME:" . $this->date_string_to_time(null, "+27 hours") . "\r\n";
    $event .= "URL:http://freedom.konscript.com\r\n";
    $event .= "SUMMARY:Login expired - go to freedom.konscript.com/renew\r\n";
    $event .= "DESCRIPTION:" . $this->ical_encode_text("Sorry for the inconvenience! Facebook has logged you out, therefore your Facebook events could not be loaded. Please login again here:\n\nhttp://freedom.konscript.com/renew\n\nNote: It can take several hours for your Facebook events to re-appear in your calendar") . "\r\n";
    $event .= "CLASS:PUBLIC\r\n";
    $event .= "STATUS:CONFIRMED\r\n";
    $event .= "END:VEVENT\r\n";

    return $event;
  }

  // splitting ical content into multiple lines - See: http://www.ietf.org/rfc/rfc2445.txt, section 4.1
  private function ical_encode_text($value) {
    $value = trim($value);

    // escape backslashes
    $value = str_replace("\\", "_", $value);

    // escape semicolon
    $value = str_replace(";", "\\;", $value);

    // escape linebreaks
    $value = str_replace("\n", "\\n", $value);

    // escape commas
    $value = str_replace(',', '\\,', $value);

    // insert actual linebreak
    $value = wordwrap($value, 50, " \r\n ");

    return $value;
  }

  // convert timestamp from FB format to iCalendar format
  private function date_string_to_time($date_string = null, $offset = ""){
    $date_obj = new DateTime($date_string);

    // date without time
    if(strlen($date_string) === 10){
      $facebook_format = 'Y-m-d';
      $icalendar_format = 'Ymd';
      $timestamp = strtotime($date_obj->format($facebook_format) . $offset);

    // date with time
    }else{
      $facebook_format = 'Y-m-d H:i:s';
      $icalendar_format = 'Ymd\THis\Z';
      $timestamp = strtotime($date_obj->format($facebook_format) . $offset) - $date_obj->format('Z');
    }

    $date = date($icalendar_format, $timestamp);

    return $date;
  }

  private function get_access_token_by_user_id($user_id, $secure_hash){
    $user = User::where('secure_hash', $secure_hash)->select(array('access_token'))->find($user_id);
    if($user){
      return $user->access_token;
    }
  }

  private function get_user_id_by_access_token($user_access_token){
    $user_id = null;

    if($user_access_token !== null && strlen($user_access_token) > 0){
      $APP_ACCESS_TOKEN_converted = str_replace("\|", "|", Config::get('facebook.appAccessToken')); // HACK: Pagodabox apparently escapes certain characters. Not cool!
      $url = 'https://graph.facebook.com/debug_token?input_token=' . $user_access_token . '&access_token=' . $APP_ACCESS_TOKEN_converted;
      $response = json_decode(file_get_contents($url));

      if(isset($response->data->user_id)){
        $user_id = $response->data->user_id;
      }
    }

    return $user_id;
  }

}