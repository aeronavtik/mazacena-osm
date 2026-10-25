<?php
/*
 Постобработка выгрузки OpenStreetMap для плагина MazaCena Stores (запускается в GitHub Actions).

 php build.php border border.geojson border-lv.geojson
     Из полигонов, выгруженных osmium, оставляет границу Латвии (ISO3166-1=LV) одним объектом — для osmium extract.

 php build.php features filtered.geojsonseq out/
     Делит объекты на группы и пишет файлы в том же виде, что ответ Overpass API
     ({"osm3s":{...},"elements":[{type,id,lat,lon,tags}]}), чтобы плагин разбирал их тем же кодом:
       food.json   — продуктовые магазины и торговые центры
       other.json  — аптеки и промтовары
       places.json — ориентиры для блока «Kā atrast» (только с названием)
       meta.json   — время выгрузки и число объектов
*/

if( $argc < 4 ) {
	fwrite( STDERR, "Usage: php build.php border|features <input> <output>\n" );
	exit( 1 );
}

list( , $mode, $input, $output ) = $argv;

if( $mode === 'border' ) {
	$data = json_decode( file_get_contents( $input ), true );
	foreach( isset( $data['features'] ) ? $data['features'] : array() as $f ) {
		$p = isset( $f['properties'] ) ? $f['properties'] : array();
		$type = isset( $f['geometry']['type'] ) ? $f['geometry']['type'] : '';
		if( ( isset( $p['ISO3166-1'] ) AND $p['ISO3166-1'] === 'LV' ) AND in_array( $type, array( 'Polygon', 'MultiPolygon' ), true ) ) {
			file_put_contents( $output, json_encode( array( 'type' => 'Feature', 'properties' => new stdClass(), 'geometry' => $f['geometry'] ) ) );
			echo "Border of Latvia: {$type}\n";
			exit( 0 );
		}
	}
	fwrite( STDERR, "Border of Latvia not found\n" );
	exit( 1 );
}

if( $mode !== 'features' ) {
	fwrite( STDERR, "Unknown mode {$mode}\n" );
	exit( 1 );
}

// Теги, которые нужны плагину; остальные отбрасываются, чтобы файлы были меньше
$keep = array( 'name', 'name:lv', 'brand', 'brand:wikidata', 'shop', 'amenity', 'railway', 'tourism', 'addr:street', 'addr:housenumber', 'addr:city', 'addr:place', 'addr:postcode', 'opening_hours', 'phone', 'contact:phone', 'website', 'contact:website' );

$food = array( 'supermarket', 'convenience', 'wholesale', 'general', 'department_store', 'mall' );
$goods = array( 'doityourself', 'hardware', 'furniture', 'variety_store', 'chemist', 'cosmetics', 'perfumery', 'houseware', 'garden_centre', 'interior_decoration', 'household_linen', 'bed' );
$places = array( 'bus_station', 'townhall', 'marketplace', 'hospital', 'post_office', 'place_of_worship' );

// Средняя точка набора координат [lng, lat]
function center_of( $coords ) {
	$n = count( $coords );
	if( !$n ) return null;
	// У замкнутого контура последняя точка повторяет первую
	if( $n > 1 AND $coords[0] == $coords[$n - 1] ) {
		array_pop( $coords );
		$n--;
	}
	$lng = 0;
	$lat = 0;
	foreach( $coords as $c ) {
		$lng += $c[0];
		$lat += $c[1];
	}
	return array( $lat / $n, $lng / $n );
}

function geometry_center( $g ) {
	switch( isset( $g['type'] ) ? $g['type'] : '' ) {
		case 'Point': return array( $g['coordinates'][1], $g['coordinates'][0] );
		case 'LineString': return center_of( $g['coordinates'] );
		case 'Polygon': return center_of( $g['coordinates'][0] );
		case 'MultiPolygon':
			// Внешний контур самой большой части
			$best = array();
			foreach( $g['coordinates'] as $poly ) if( count( $poly[0] ) > count( $best ) ) $best = $poly[0];
			return center_of( $best );
	}
	return null;
}

$out = array( 'food' => array(), 'other' => array(), 'places' => array() );
$seen = array();
$lines = 0;

$fh = fopen( $input, 'r' );
while( ( $line = fgets( $fh ) ) !== false ) {
	$line = trim( $line, "\x1e \t\r\n" );
	if( $line === '' ) continue;
	$lines++;

	$f = json_decode( $line, true );
	if( !is_array( $f ) OR empty( $f['id'] ) ) continue;

	$t = isset( $f['properties'] ) ? $f['properties'] : array();
	$shop = isset( $t['shop'] ) ? $t['shop'] : '';
	$amenity = isset( $t['amenity'] ) ? $t['amenity'] : '';
	$named = isset( $t['name'] ) && trim( $t['name'] ) !== '';

	// Узлы-входы и прочие объекты, попавшие в выгрузку как части зданий, отбрасываются: группа определяется по тегам
	if( in_array( $shop, $food, true ) ) $set = 'food';
	elseif( $amenity === 'pharmacy' OR in_array( $shop, $goods, true ) ) $set = 'other';
	elseif( $named AND ( isset( $t['railway'] ) AND in_array( $t['railway'], array( 'station', 'halt' ), true ) OR in_array( $amenity, $places, true ) OR ( isset( $t['tourism'] ) AND $t['tourism'] === 'museum' ) ) ) $set = 'places';
	else continue;

	// id osmium: n123 / w123 / r123 (у мультиполигонов — тоже r)
	$id = (string)$f['id'];
	$types = array( 'n' => 'node', 'w' => 'way', 'r' => 'relation', 'a' => 'way' );
	$prefix = $id[0];
	if( !isset( $types[$prefix] ) OR !ctype_digit( substr( $id, 1 ) ) ) continue;
	if( isset( $seen[$set][$id] ) ) continue;
	$seen[$set][$id] = true;

	$c = geometry_center( isset( $f['geometry'] ) ? $f['geometry'] : array() );
	if( !$c ) continue;

	$tags = array();
	foreach( $keep as $k ) if( isset( $t[$k] ) AND $t[$k] !== '' ) $tags[$k] = (string)$t[$k];

	$out[$set][] = array( 'type' => $types[$prefix], 'id' => (int)substr( $id, 1 ), 'lat' => round( $c[0], 7 ), 'lon' => round( $c[1], 7 ), 'tags' => $tags );
}
fclose( $fh );

$stamp = getenv( 'OSM_TIMESTAMP' ) ? trim( getenv( 'OSM_TIMESTAMP' ) ) : '';
$generated = gmdate( 'Y-m-d\TH:i:s\Z' );
$counts = array();

foreach( $out as $set => $elements ) {
	$counts[$set] = count( $elements );
	file_put_contents( rtrim( $output, '/' ) . "/{$set}.json", json_encode( array(
		'generator' => 'MazaCena OSM export',
		'osm3s'     => array( 'timestamp_osm_base' => $stamp, 'copyright' => 'The data included in this document is from www.openstreetmap.org. The data is made available under ODbL.' ),
		'elements'  => $elements
	), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
}

file_put_contents( rtrim( $output, '/' ) . '/meta.json', json_encode( array( 'generated' => $generated, 'osm_timestamp' => $stamp, 'counts' => $counts, 'license' => '© OpenStreetMap contributors, ODbL' ), JSON_UNESCAPED_SLASHES ) );

echo "Read {$lines} features: food {$counts['food']}, other {$counts['other']}, places {$counts['places']}\n";

// Выгрузка подозрительно мала — не публиковать (плагин продолжит пользоваться предыдущей)
if( $counts['food'] < 500 OR $counts['other'] < 200 ) {
	fwrite( STDERR, "Too few objects, export rejected\n" );
	exit( 1 );
}
