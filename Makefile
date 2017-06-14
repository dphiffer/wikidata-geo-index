all: setup download index

setup:
	composer install

download:
	curl -O https://dumps.wikimedia.org/wikidatawiki/entities/latest-all.json.bz2

index:
	php wikidata.php latest-all.json.bz2

purge:
	rm latest-all.json.bz2
	rm -rf cache/
