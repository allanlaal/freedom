<?php
// header('Content-type: text/calendar;charset=utf-8');
// header('Content-Disposition: attachment; filename="feed.ics"');

header('Content-Type: text/plain; charset=utf-8');

require_once "facebook-sdk/facebook.php";
require_once 'ical.php';
$ical = new ical($_GET["uid"], $_GET["key"], $_GET["access_token"]);
echo $ical->get_feed();
?>