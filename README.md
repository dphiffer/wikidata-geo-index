# Wikidata Geo Index

A SQLite index of all the [Wikidata entities](https://www.wikidata.org/) that have [latitude/longitude coordinates](https://www.wikidata.org/wiki/Property:P625).

## Requirements

* [PHP](https://secure.php.net/) 5.4+
* [PHP Composer](https://getcomposer.org/)
* [Make](https://www.gnu.org/software/make/)
* [SQLite](https://www.sqlite.org/)

## How to generate the index

Warning: this will take a long time (leave it running overnight), and use a lot of disk space (7.8GB for the JSON dump, 833MB for the SQLite db).

```
git clone https://github.com/dphiffer/wikidata-geo-index.git
cd wikidata-geo-index
make
```

This will do the following:

* Uses PHP Composer to install dependencies
* Downloads the [current Wikidata JSON dump](https://www.wikidata.org/wiki/Wikidata:Database_download#JSON_dumps_.28recommended.29)
* Uses [JsonDumpReader](https://github.com/JeroenDeDauw/JsonDumpReader) to parse the JSON file
* Indexes all entities that contain geo coordinates to SQLite

You will end up with a SQLite database called `wikidata.db`.

## How to query the index

Once you create the index, you can use it to find entities in Wikidata by their lat/lng coordinates. The index is saved in SQLite format, so if you know SQL this should seem familiar.

```
$ cd wikidata-geo-index
$ sqlite3 wikidata.db
SQLite version 3.16.0 2016-11-04 19:09:39
Enter ".help" for usage hints.
sqlite> select * from entity where lat > 37.748212 and lat < 37.7725278668 and lng > -122.431025471 and lng < -122.405240263;
Q564339|Central Freeway|Q34442|37.77|-122.421
Q4552246|16th Street Mission Station|Q55488|37.7648|-122.42
Q4796232|Arroyo Dolores|Q618123|37.762638888889|-122.41671111111
...
sqlite> .mode csv
sqlite> .output wikidata_mission.csv
sqlite> select * from entity where lat > 37.748212 and lat < 37.7725278668 and lng > -122.431025471 and lng < -122.405240263;
sqlite> .quit
```

The `.mode` and `.output` SQLite commands will let you generate, for example, a [CSV file](https://gist.github.com/dphiffer/39388701370b26441cb70b665f73ed55) of Wikidata entities that fall in the bounding box of the [Mission District](https://whosonfirst.mapzen.com/spelunker/id/1108830809/) in San Francisco.

## What is in the index?

You get the entity ID, name, [instance_of](https://www.wikidata.org/wiki/Property:P31) (if it exists), latitude, and longitude. There is also a separate table with entity names in 17 languages.

Here is the database schema:

```
sqlite> .schema
CREATE TABLE entity (
		id VARCHAR(255) PRIMARY KEY,
		name VARCHAR(255),
		instance_of VARCHAR(255),
		lat FLOAT,
		lng FLOAT
	);
CREATE TABLE entity_label (
		entity_id VARCHAR(255),
		language VARCHAR(255),
		name VARCHAR(255)
	);
CREATE INDEX entity_coords ON entity
	(id, lat, lng)
;
CREATE INDEX entity_struct ON entity
	(id, instance_of)
;
CREATE INDEX label_language ON entity_label
	(entity_id, language)
;
```

## Can I just have a copy of the SQLite db?

Yeah, sure thing: [https://phiffer.org/etc/wikidata.db.bz2](https://phiffer.org/etc/wikidata.db.bz2)

You'll need [bzip2](http://www.bzip.org/) to unpack the database. It is 314MB compressed.

## Props

Thanks to the [Wikidata Project](https://www.wikidata.org/) and [Jeroen De Dauw](https://github.com/JeroenDeDauw) for maintaining the [JSON Dump Reader](https://github.com/JeroenDeDauw/JsonDumpReader).
