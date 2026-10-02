<?php
/**
 * Default notification email: a plain HTML shell whose {{placeholders}}
 * Notifier::render() substitutes with escaped values.
 *
 * @package Ticketoo
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!doctype html>
<html lang="{{lang}}">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>{{subject}}</title>
</head>
<body>
	<h1>{{subject}}</h1>
	<p>{{body}}</p>
	<p><a href="{{url}}">{{url}}</a></p>
	<p>{{site_name}}</p>
</body>
</html>
