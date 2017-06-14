<?php

require 'vendor/autoload.php';

use Wikibase\JsonDumpReader\JsonDumpFactory;
$factory = new JsonDumpFactory();

if (! $argv[1]) {
	die("Usage: php wikidata.php [latest-all.json.bz2]");
}

$start_time = time();

if (preg_match('/\.bz2$/', $argv[1])) {
	$reader = $factory->newBz2DumpReader($argv[1]);
} else if (preg_match('/\.gz/', $argv[1])) {
	$reader = $factory->newGzDumpReader($argv[1]);
} else if (preg_match('/\.json/', $argv[1])) {
	$reader = $factory->newExtractedDumpReader($argv[1]);
} else {
	die("Unknown file type: $argv[1]");
}
$iterator = $factory->newStringDumpIterator($reader);

$count = 0;
$total = 0;
$queue = array();
$transaction_size = 25000;

if (file_exists('wikidata.db')) {
	rename('wikidata.db', 'wikidata.db.bak');
}

$pdo = new PDO('sqlite:wikidata.db');
$pdo->beginTransaction();

$sth = $pdo->query("
	CREATE TABLE entity (
		id VARCHAR(255) PRIMARY KEY,
		name VARCHAR(255),
		instance_of VARCHAR(255),
		lat FLOAT,
		lng FLOAT
	)
");
if (! $sth) {
	print_r($pdo->errorInfo());
}

$sth = $pdo->query("
	CREATE TABLE entity_label (
		entity_id VARCHAR(255),
		language VARCHAR(255),
		name VARCHAR(255)
	)
");
if (! $sth) {
	print_r($pdo->errorInfo());
}

$sth = $pdo->query("
	CREATE INDEX entity_coords ON entity
	(id, lat, lng)
");
if (! $sth) {
	print_r($pdo->errorInfo());
}

$sth = $pdo->query("
	CREATE INDEX entity_struct ON entity
	(id, instance_of)
");
if (! $sth) {
	print_r($pdo->errorInfo());
}

$sth = $pdo->query("
	CREATE INDEX label_language ON entity_label
	(entity_id, language)
");
if (! $sth) {
	print_r($pdo->errorInfo());
}

$insert_geocoded = $pdo->prepare("
	INSERT INTO entity
	(id, name, instance_of, lat, lng)
	VALUES (?, ?, ?, ?, ?)
");
if (! $insert_geocoded) {
	print_r($pdo->errorInfo());
}

$insert_named = $pdo->prepare("
	INSERT INTO entity
	(id, name, instance_of)
	VALUES (?, ?, ?)
");
if (! $insert_named) {
	print_r($pdo->errorInfo());
}

$insert_label = $pdo->prepare("
	INSERT INTO entity_label
	(entity_id, language, name)
	VALUES (?, ?, ?)
");
if (! $insert_label) {
	print_r($pdo->errorInfo());
}

$languages = array(
	'ar', // Arabic
	'zh', // Chinese
	'en', // English
	'fr', // French
	'ru', // Russian
	'es', // Spanish
	'bn', // Bengali
	'de', // German
	'el', // Greek
	'hi', // Hindi
	'id', // Indonesian
	'it', // Italian
	'ja', // Japanese
	'ko', // Korean
	'pt', // Portuguese
	'tr', // Turkish
	'vi'  // Vietnamese
);

echo "Indexing geodata\n";

foreach ($iterator as $json) {

	$total++;
	$obj = json_decode($json, 'as hash');

	$id = $obj['id'];

	if ($obj['labels']['en']) {
		// Default to English (maybe we should pick based on country?)
		$name = $obj['labels']['en']['value'];
	} else {
		foreach ($obj['labels'] as $language => $details) {
			// Just pick the first language label
			$name = $obj['labels'][$language]['value'];
			break;
		}
	}

	if (! empty($obj['claims']['P31'])) {
		$instance_of = $obj['claims']['P31'][0]['mainsnak']['datavalue']['value']['id'];
	} else {
		$instance_of = '';
	}

	if (! empty($obj['claims']['P625'])) {

		// It has geodata! Index it.

		$count++;

		$coord = $obj['claims']['P625'][0]['mainsnak']['datavalue']['value'];
		$lat = $coord['latitude'];
		$lng = $coord['longitude'];

		$insert_geocoded->execute(array(
			$id, $name, $instance_of, $lat, $lng
		));

		if ($instance_of &&
		    ! in_array($instance_of, $queue)) {
			$queue[] = $instance_of;
		}

		foreach ($obj['labels'] as $language => $details) {
			if (in_array($language, $languages)) {
				$insert_label->execute(array(
					$id, $language, $details['value']
				));
			}
		}

		if ($count % $transaction_size == 0) {
			$pdo->commit();
			$pdo->beginTransaction();
		}

	}
}

echo "Indexed $count records (out of $total)\n";
echo "Saving queued instance names\n";

$count = 0;

foreach ($queue as $id) {

	$count++;

	$cache_file = "cache/$id.json";
	if (! file_exists('cache')) {
		mkdir('cache');
	}

	if (file_exists($cache_file)) {
		$json = file_get_contents($cache_file);
	} else {
		$url = "https://www.wikidata.org/wiki/Special:EntityData/$id.json";
		$json = file_get_contents($url);
		file_put_contents($cache_file, $json);
	}

	$obj = json_decode($json, 'as hash');
	$obj = $obj['entities'][$id];

	$id = $obj['id'];

	if ($obj['labels']['en']) {
		// Default to English (maybe we should pick based on country?)
		$name = $obj['labels']['en']['value'];
	} else if ($obj['labels']) {
		foreach ($obj['labels'] as $language => $details) {
			// Just pick the first language label
			$name = $obj['labels'][$language]['value'];
			break;
		}
	} else {
		$name = 'Untitled entity';
	}

	if (! empty($obj['claims']['P31'])) {
		$instance_of = $obj['claims']['P31'][0]['mainsnak']['datavalue']['value']['id'];
	} else {
		$instance_of = '';
	}

	$insert_named->execute(array(
		$id, $name, $instance_of
	));

	if ($count % $transaction_size == 0) {
		$pdo->commit();
		$pdo->beginTransaction();
	}
}

echo "Indexed $count instance names\n";

$end_time = time();
$elapsed = $end_time - $start_time;

echo "Finished in $elapsed seconds\n";

$pdo->commit();
