<?php

require('Sources/RNA.php');

$conn = RNA::getConnection([
	'driver' => 'mysql',
	'user' => 'root',
	'password' => 'root',
	'host' => 'localhost',
	'database' => 'ual_db'
]);
$photos = $conn->find('photos', [
	'as' => 'Photo', 
	'group' => 'filename',
	'conditions' => array(
		'category_id <' => 1
	),
	'order' => 'created DESC',
]);
foreach($photos as $photo) {
	pr($photo['Photo']['dir'].$photo['Photo']['filename']);
}
exit;