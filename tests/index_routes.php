<?php
// prints the route list from index.php without running the app
$src = file_get_contents(__DIR__ . '/../index.php');
preg_match('/const ROUTES = \[(.*?)\];/s', $src, $m);
preg_match_all("/'([a-z]+)' => \['/", $m[1], $r);
echo json_encode($r[1]);
