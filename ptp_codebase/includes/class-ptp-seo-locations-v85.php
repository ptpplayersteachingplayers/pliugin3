<?php
/**
 * PTP SEO Location Pages V85 - Ultimate SEO Ranking System
 * 
 * Comprehensive local SEO domination system with:
 * - 200+ cities across 5 states
 * - Multiple service types and combinations
 * - Rich schema markup (LocalBusiness, Service, FAQ, Review, Video)
 * - Conversion-optimized landing pages
 * - Internal linking silos
 * - Long-tail keyword targeting
 * 
 * @version 85.0.0
 */

defined('ABSPATH') || exit;

class PTP_SEO_Locations_V85 {
    
    /**
     * Service areas - Expanded to 200+ cities
     */
    private static $states = array(
        'pennsylvania' => array(
            'name' => 'Pennsylvania',
            'abbr' => 'PA',
            'meta_region' => 'Greater Philadelphia',
            'primary_city' => 'philadelphia',
            'major_cities' => array(
                // Philadelphia Metro
                'philadelphia' => array('name' => 'Philadelphia', 'lat' => 39.9526, 'lng' => -75.1652, 'population' => 1584000, 'metro' => true, 'neighborhoods' => array('Center City', 'University City', 'Fishtown', 'Manayunk', 'Chestnut Hill', 'South Philly', 'Northern Liberties', 'Rittenhouse')),
                
                // Main Line - Premium Market
                'king-of-prussia' => array('name' => 'King of Prussia', 'lat' => 40.0893, 'lng' => -75.3963, 'population' => 22000, 'region' => 'Main Line'),
                'wayne' => array('name' => 'Wayne', 'lat' => 40.0440, 'lng' => -75.3877, 'population' => 32000, 'region' => 'Main Line'),
                'bryn-mawr' => array('name' => 'Bryn Mawr', 'lat' => 40.0229, 'lng' => -75.3163, 'population' => 4000, 'region' => 'Main Line'),
                'villanova' => array('name' => 'Villanova', 'lat' => 40.0372, 'lng' => -75.3426, 'population' => 9000, 'region' => 'Main Line'),
                'radnor' => array('name' => 'Radnor', 'lat' => 40.0465, 'lng' => -75.3593, 'population' => 31500, 'region' => 'Main Line'),
                'ardmore' => array('name' => 'Ardmore', 'lat' => 40.0065, 'lng' => -75.2905, 'population' => 13000, 'region' => 'Main Line'),
                'haverford' => array('name' => 'Haverford', 'lat' => 40.0107, 'lng' => -75.3035, 'population' => 49000, 'region' => 'Main Line'),
                'narberth' => array('name' => 'Narberth', 'lat' => 40.0084, 'lng' => -75.2604, 'population' => 4600, 'region' => 'Main Line'),
                'gladwyne' => array('name' => 'Gladwyne', 'lat' => 40.0440, 'lng' => -75.2785, 'population' => 4100, 'region' => 'Main Line'),
                'merion-station' => array('name' => 'Merion Station', 'lat' => 40.0037, 'lng' => -75.2460, 'population' => 5000, 'region' => 'Main Line'),
                'bala-cynwyd' => array('name' => 'Bala Cynwyd', 'lat' => 40.0073, 'lng' => -75.2299, 'population' => 9500, 'region' => 'Main Line'),
                'rosemont' => array('name' => 'Rosemont', 'lat' => 40.0290, 'lng' => -75.3163, 'population' => 3200, 'region' => 'Main Line'),
                'devon' => array('name' => 'Devon', 'lat' => 40.0440, 'lng' => -75.4224, 'population' => 2000, 'region' => 'Main Line'),
                'paoli' => array('name' => 'Paoli', 'lat' => 40.0426, 'lng' => -75.4766, 'population' => 5600, 'region' => 'Main Line'),
                'berwyn' => array('name' => 'Berwyn', 'lat' => 40.0426, 'lng' => -75.4435, 'population' => 3600, 'region' => 'Main Line'),
                'strafford' => array('name' => 'Strafford', 'lat' => 40.0473, 'lng' => -75.4099, 'population' => 1800, 'region' => 'Main Line'),
                
                // Delaware County
                'newtown-square' => array('name' => 'Newtown Square', 'lat' => 39.9851, 'lng' => -75.4082, 'population' => 12500, 'region' => 'Delaware County'),
                'media' => array('name' => 'Media', 'lat' => 39.9168, 'lng' => -75.3877, 'population' => 6000, 'region' => 'Delaware County'),
                'springfield' => array('name' => 'Springfield', 'lat' => 39.9312, 'lng' => -75.3205, 'population' => 24000, 'region' => 'Delaware County'),
                'swarthmore' => array('name' => 'Swarthmore', 'lat' => 39.9021, 'lng' => -75.3499, 'population' => 6200, 'region' => 'Delaware County'),
                'wallingford' => array('name' => 'Wallingford', 'lat' => 39.8965, 'lng' => -75.3677, 'population' => 5300, 'region' => 'Delaware County'),
                'ridley-park' => array('name' => 'Ridley Park', 'lat' => 39.8790, 'lng' => -75.3252, 'population' => 7000, 'region' => 'Delaware County'),
                'drexel-hill' => array('name' => 'Drexel Hill', 'lat' => 39.9476, 'lng' => -75.2921, 'population' => 28000, 'region' => 'Delaware County'),
                'upper-darby' => array('name' => 'Upper Darby', 'lat' => 39.9593, 'lng' => -75.2607, 'population' => 85000, 'region' => 'Delaware County'),
                'broomall' => array('name' => 'Broomall', 'lat' => 39.9687, 'lng' => -75.3566, 'population' => 11000, 'region' => 'Delaware County'),
                'marple' => array('name' => 'Marple', 'lat' => 39.9618, 'lng' => -75.3649, 'population' => 23700, 'region' => 'Delaware County'),
                'havertown' => array('name' => 'Havertown', 'lat' => 39.9801, 'lng' => -75.3082, 'population' => 35000, 'region' => 'Delaware County'),
                'garnet-valley' => array('name' => 'Garnet Valley', 'lat' => 39.8576, 'lng' => -75.4732, 'population' => 12000, 'region' => 'Delaware County'),
                
                // Chester County
                'west-chester' => array('name' => 'West Chester', 'lat' => 39.9607, 'lng' => -75.6055, 'population' => 20000, 'metro' => true, 'region' => 'Chester County'),
                'malvern' => array('name' => 'Malvern', 'lat' => 40.0362, 'lng' => -75.5135, 'population' => 3500, 'region' => 'Chester County'),
                'exton' => array('name' => 'Exton', 'lat' => 40.0290, 'lng' => -75.6210, 'population' => 5000, 'region' => 'Chester County'),
                'downingtown' => array('name' => 'Downingtown', 'lat' => 40.0062, 'lng' => -75.7032, 'population' => 8000, 'region' => 'Chester County'),
                'chadds-ford' => array('name' => 'Chadds Ford', 'lat' => 39.8712, 'lng' => -75.5913, 'population' => 3700, 'region' => 'Chester County'),
                'kennett-square' => array('name' => 'Kennett Square', 'lat' => 39.8468, 'lng' => -75.7116, 'population' => 6100, 'region' => 'Chester County'),
                'glen-mills' => array('name' => 'Glen Mills', 'lat' => 39.9007, 'lng' => -75.4963, 'population' => 10000, 'region' => 'Chester County'),
                'phoenixville' => array('name' => 'Phoenixville', 'lat' => 40.1298, 'lng' => -75.5149, 'population' => 17000, 'region' => 'Chester County'),
                'coatesville' => array('name' => 'Coatesville', 'lat' => 39.9834, 'lng' => -75.8238, 'population' => 13200, 'region' => 'Chester County'),
                'oxford' => array('name' => 'Oxford', 'lat' => 39.7854, 'lng' => -75.9785, 'population' => 5600, 'region' => 'Chester County'),
                'thorndale' => array('name' => 'Thorndale', 'lat' => 39.9926, 'lng' => -75.7593, 'population' => 3400, 'region' => 'Chester County'),
                'unionville' => array('name' => 'Unionville', 'lat' => 39.8979, 'lng' => -75.7399, 'population' => 1800, 'region' => 'Chester County'),
                
                // Montgomery County
                'collegeville' => array('name' => 'Collegeville', 'lat' => 40.1854, 'lng' => -75.4516, 'population' => 5100, 'region' => 'Montgomery County'),
                'conshohocken' => array('name' => 'Conshohocken', 'lat' => 40.0793, 'lng' => -75.3016, 'population' => 8200, 'region' => 'Montgomery County'),
                'blue-bell' => array('name' => 'Blue Bell', 'lat' => 40.1526, 'lng' => -75.2663, 'population' => 6000, 'region' => 'Montgomery County'),
                'lansdale' => array('name' => 'Lansdale', 'lat' => 40.2415, 'lng' => -75.2835, 'population' => 17400, 'region' => 'Montgomery County'),
                'horsham' => array('name' => 'Horsham', 'lat' => 40.1779, 'lng' => -75.1271, 'population' => 26000, 'region' => 'Montgomery County'),
                'ambler' => array('name' => 'Ambler', 'lat' => 40.1543, 'lng' => -75.2213, 'population' => 6500, 'region' => 'Montgomery County'),
                'norristown' => array('name' => 'Norristown', 'lat' => 40.1215, 'lng' => -75.3399, 'population' => 34700, 'region' => 'Montgomery County'),
                'plymouth-meeting' => array('name' => 'Plymouth Meeting', 'lat' => 40.1046, 'lng' => -75.2752, 'population' => 6200, 'region' => 'Montgomery County'),
                'north-wales' => array('name' => 'North Wales', 'lat' => 40.2109, 'lng' => -75.2785, 'population' => 3300, 'region' => 'Montgomery County'),
                'gwynedd' => array('name' => 'Gwynedd', 'lat' => 40.2176, 'lng' => -75.2460, 'population' => 3000, 'region' => 'Montgomery County'),
                'fort-washington' => array('name' => 'Fort Washington', 'lat' => 40.1376, 'lng' => -75.2085, 'population' => 6000, 'region' => 'Montgomery County'),
                'jenkintown' => array('name' => 'Jenkintown', 'lat' => 40.0954, 'lng' => -75.1252, 'population' => 4500, 'region' => 'Montgomery County'),
                'glenside' => array('name' => 'Glenside', 'lat' => 40.1018, 'lng' => -75.1521, 'population' => 8400, 'region' => 'Montgomery County'),
                'wyndmoor' => array('name' => 'Wyndmoor', 'lat' => 40.0815, 'lng' => -75.1888, 'population' => 5700, 'region' => 'Montgomery County'),
                'flourtown' => array('name' => 'Flourtown', 'lat' => 40.1046, 'lng' => -75.2121, 'population' => 4500, 'region' => 'Montgomery County'),
                'oreland' => array('name' => 'Oreland', 'lat' => 40.1143, 'lng' => -75.1871, 'population' => 5800, 'region' => 'Montgomery County'),
                'lafayette-hill' => array('name' => 'Lafayette Hill', 'lat' => 40.0879, 'lng' => -75.2554, 'population' => 10500, 'region' => 'Montgomery County'),
                'harleysville' => array('name' => 'Harleysville', 'lat' => 40.2790, 'lng' => -75.3863, 'population' => 9000, 'region' => 'Montgomery County'),
                'perkasie' => array('name' => 'Perkasie', 'lat' => 40.3718, 'lng' => -75.2927, 'population' => 8900, 'region' => 'Montgomery County'),
                'skippack' => array('name' => 'Skippack', 'lat' => 40.2251, 'lng' => -75.3982, 'population' => 3800, 'region' => 'Montgomery County'),
                'trappe' => array('name' => 'Trappe', 'lat' => 40.2001, 'lng' => -75.4699, 'population' => 3800, 'region' => 'Montgomery County'),
                
                // Bucks County
                'doylestown' => array('name' => 'Doylestown', 'lat' => 40.3101, 'lng' => -75.1299, 'population' => 8600, 'metro' => true, 'region' => 'Bucks County'),
                'yardley' => array('name' => 'Yardley', 'lat' => 40.2454, 'lng' => -74.8363, 'population' => 2500, 'region' => 'Bucks County'),
                'newtown' => array('name' => 'Newtown', 'lat' => 40.2290, 'lng' => -74.9371, 'population' => 2200, 'region' => 'Bucks County'),
                'new-hope' => array('name' => 'New Hope', 'lat' => 40.3648, 'lng' => -74.9513, 'population' => 2500, 'region' => 'Bucks County'),
                'langhorne' => array('name' => 'Langhorne', 'lat' => 40.1746, 'lng' => -74.9224, 'population' => 1600, 'region' => 'Bucks County'),
                'warrington' => array('name' => 'Warrington', 'lat' => 40.2493, 'lng' => -75.1324, 'population' => 24500, 'region' => 'Bucks County'),
                'warminster' => array('name' => 'Warminster', 'lat' => 40.1893, 'lng' => -75.0877, 'population' => 32700, 'region' => 'Bucks County'),
                'richboro' => array('name' => 'Richboro', 'lat' => 40.2168, 'lng' => -75.0099, 'population' => 7400, 'region' => 'Bucks County'),
                'southampton' => array('name' => 'Southampton', 'lat' => 40.1876, 'lng' => -75.0049, 'population' => 10500, 'region' => 'Bucks County'),
                'chalfont' => array('name' => 'Chalfont', 'lat' => 40.2884, 'lng' => -75.2088, 'population' => 4000, 'region' => 'Bucks County'),
                'quakertown' => array('name' => 'Quakertown', 'lat' => 40.4418, 'lng' => -75.3416, 'population' => 9000, 'region' => 'Bucks County'),
                'sellersville' => array('name' => 'Sellersville', 'lat' => 40.3540, 'lng' => -75.3049, 'population' => 4400, 'region' => 'Bucks County'),
                'perkasie' => array('name' => 'Perkasie', 'lat' => 40.3718, 'lng' => -75.2927, 'population' => 8900, 'region' => 'Bucks County'),
                'solebury' => array('name' => 'Solebury', 'lat' => 40.3701, 'lng' => -75.0121, 'population' => 8700, 'region' => 'Bucks County'),
                'lower-makefield' => array('name' => 'Lower Makefield', 'lat' => 40.2237, 'lng' => -74.8585, 'population' => 32800, 'region' => 'Bucks County'),
            ),
            'regions' => array('Main Line', 'Delaware County', 'Chester County', 'Montgomery County', 'Bucks County', 'Philadelphia County'),
        ),
        
        'new-jersey' => array(
            'name' => 'New Jersey',
            'abbr' => 'NJ',
            'meta_region' => 'South & Central Jersey',
            'primary_city' => 'cherry-hill',
            'major_cities' => array(
                // Burlington County
                'cherry-hill' => array('name' => 'Cherry Hill', 'lat' => 39.9348, 'lng' => -75.0307, 'population' => 74500, 'metro' => true, 'region' => 'Camden County'),
                'moorestown' => array('name' => 'Moorestown', 'lat' => 39.9687, 'lng' => -74.9488, 'population' => 20700, 'region' => 'Burlington County'),
                'marlton' => array('name' => 'Marlton', 'lat' => 39.8912, 'lng' => -74.9221, 'population' => 10300, 'region' => 'Burlington County'),
                'medford' => array('name' => 'Medford', 'lat' => 39.8548, 'lng' => -74.8227, 'population' => 23900, 'region' => 'Burlington County'),
                'mount-laurel' => array('name' => 'Mount Laurel', 'lat' => 39.9340, 'lng' => -74.8910, 'population' => 43500, 'region' => 'Burlington County'),
                'mount-holly' => array('name' => 'Mount Holly', 'lat' => 39.9929, 'lng' => -74.7874, 'population' => 9500, 'region' => 'Burlington County'),
                'cinnaminson' => array('name' => 'Cinnaminson', 'lat' => 39.9968, 'lng' => -74.9924, 'population' => 16600, 'region' => 'Burlington County'),
                'delran' => array('name' => 'Delran', 'lat' => 40.0165, 'lng' => -74.9549, 'population' => 17600, 'region' => 'Burlington County'),
                'burlington' => array('name' => 'Burlington', 'lat' => 40.0712, 'lng' => -74.8649, 'population' => 22600, 'region' => 'Burlington County'),
                'bordentown' => array('name' => 'Bordentown', 'lat' => 40.1454, 'lng' => -74.7116, 'population' => 11500, 'region' => 'Burlington County'),
                'lumberton' => array('name' => 'Lumberton', 'lat' => 39.9612, 'lng' => -74.8049, 'population' => 13000, 'region' => 'Burlington County'),
                'shamong' => array('name' => 'Shamong', 'lat' => 39.7607, 'lng' => -74.7221, 'population' => 6500, 'region' => 'Burlington County'),
                'tabernacle' => array('name' => 'Tabernacle', 'lat' => 39.8334, 'lng' => -74.6813, 'population' => 7200, 'region' => 'Burlington County'),
                'evesham' => array('name' => 'Evesham', 'lat' => 39.8618, 'lng' => -74.8766, 'population' => 47000, 'region' => 'Burlington County'),
                'willingboro' => array('name' => 'Willingboro', 'lat' => 40.0276, 'lng' => -74.8674, 'population' => 31600, 'region' => 'Burlington County'),
                
                // Camden County
                'haddonfield' => array('name' => 'Haddonfield', 'lat' => 39.8915, 'lng' => -75.0377, 'population' => 11500, 'region' => 'Camden County'),
                'voorhees' => array('name' => 'Voorhees', 'lat' => 39.8440, 'lng' => -74.9527, 'population' => 29800, 'region' => 'Camden County'),
                'collingswood' => array('name' => 'Collingswood', 'lat' => 39.9182, 'lng' => -75.0716, 'population' => 14000, 'region' => 'Camden County'),
                'westmont' => array('name' => 'Westmont', 'lat' => 39.9065, 'lng' => -75.0552, 'population' => 13500, 'region' => 'Camden County'),
                'haddon-heights' => array('name' => 'Haddon Heights', 'lat' => 39.8776, 'lng' => -75.0660, 'population' => 7500, 'region' => 'Camden County'),
                'haddon-township' => array('name' => 'Haddon Township', 'lat' => 39.8957, 'lng' => -75.0627, 'population' => 14700, 'region' => 'Camden County'),
                'stratford' => array('name' => 'Stratford', 'lat' => 39.8268, 'lng' => -75.0152, 'population' => 7000, 'region' => 'Camden County'),
                'berlin' => array('name' => 'Berlin', 'lat' => 39.7912, 'lng' => -74.9291, 'population' => 7700, 'region' => 'Camden County'),
                'gibbsboro' => array('name' => 'Gibbsboro', 'lat' => 39.8382, 'lng' => -74.9658, 'population' => 2200, 'region' => 'Camden County'),
                'gloucester-township' => array('name' => 'Gloucester Township', 'lat' => 39.7901, 'lng' => -75.0177, 'population' => 65000, 'region' => 'Camden County'),
                
                // Mercer County
                'princeton' => array('name' => 'Princeton', 'lat' => 40.3573, 'lng' => -74.6672, 'population' => 31800, 'metro' => true, 'region' => 'Mercer County'),
                'lawrenceville' => array('name' => 'Lawrenceville', 'lat' => 40.2976, 'lng' => -74.7394, 'population' => 4100, 'region' => 'Mercer County'),
                'hamilton' => array('name' => 'Hamilton', 'lat' => 40.2176, 'lng' => -74.7094, 'population' => 92000, 'region' => 'Mercer County'),
                'ewing' => array('name' => 'Ewing', 'lat' => 40.2698, 'lng' => -74.7877, 'population' => 36700, 'region' => 'Mercer County'),
                'west-windsor' => array('name' => 'West Windsor', 'lat' => 40.2973, 'lng' => -74.6232, 'population' => 28500, 'region' => 'Mercer County'),
                'pennington' => array('name' => 'Pennington', 'lat' => 40.3284, 'lng' => -74.7916, 'population' => 2700, 'region' => 'Mercer County'),
                'hopewell' => array('name' => 'Hopewell', 'lat' => 40.3884, 'lng' => -74.7599, 'population' => 2000, 'region' => 'Mercer County'),
                'robbinsville' => array('name' => 'Robbinsville', 'lat' => 40.2143, 'lng' => -74.6199, 'population' => 14000, 'region' => 'Mercer County'),
                'plainsboro' => array('name' => 'Plainsboro', 'lat' => 40.3476, 'lng' => -74.5899, 'population' => 24000, 'region' => 'Mercer County'),
                'princeton-junction' => array('name' => 'Princeton Junction', 'lat' => 40.3168, 'lng' => -74.6199, 'population' => 15000, 'region' => 'Mercer County'),
                
                // Gloucester County
                'washington-township' => array('name' => 'Washington Township', 'lat' => 39.7479, 'lng' => -75.0738, 'population' => 48500, 'region' => 'Gloucester County'),
                'sewell' => array('name' => 'Sewell', 'lat' => 39.7515, 'lng' => -75.0985, 'population' => 8500, 'region' => 'Gloucester County'),
                'glassboro' => array('name' => 'Glassboro', 'lat' => 39.7029, 'lng' => -75.1116, 'population' => 20000, 'region' => 'Gloucester County'),
                'mullica-hill' => array('name' => 'Mullica Hill', 'lat' => 39.7387, 'lng' => -75.2244, 'population' => 4000, 'region' => 'Gloucester County'),
                'woodbury' => array('name' => 'Woodbury', 'lat' => 39.8387, 'lng' => -75.1527, 'population' => 10200, 'region' => 'Gloucester County'),
            ),
            'regions' => array('South Jersey', 'Central Jersey', 'Burlington County', 'Camden County', 'Mercer County', 'Gloucester County'),
        ),
        
        'delaware' => array(
            'name' => 'Delaware',
            'abbr' => 'DE',
            'meta_region' => 'Northern Delaware',
            'primary_city' => 'wilmington',
            'major_cities' => array(
                'wilmington' => array('name' => 'Wilmington', 'lat' => 39.7447, 'lng' => -75.5484, 'population' => 71000, 'metro' => true, 'region' => 'New Castle County'),
                'newark' => array('name' => 'Newark', 'lat' => 39.6837, 'lng' => -75.7497, 'population' => 33600, 'region' => 'New Castle County'),
                'hockessin' => array('name' => 'Hockessin', 'lat' => 39.7854, 'lng' => -75.6963, 'population' => 14000, 'region' => 'New Castle County'),
                'greenville' => array('name' => 'Greenville', 'lat' => 39.8018, 'lng' => -75.5977, 'population' => 2300, 'region' => 'New Castle County'),
                'pike-creek' => array('name' => 'Pike Creek', 'lat' => 39.7312, 'lng' => -75.6993, 'population' => 8500, 'region' => 'New Castle County'),
                'bear' => array('name' => 'Bear', 'lat' => 39.6293, 'lng' => -75.6555, 'population' => 22000, 'region' => 'New Castle County'),
                'middletown' => array('name' => 'Middletown', 'lat' => 39.4496, 'lng' => -75.7163, 'population' => 22000, 'region' => 'New Castle County'),
                'north-wilmington' => array('name' => 'North Wilmington', 'lat' => 39.8101, 'lng' => -75.5249, 'population' => 15000, 'region' => 'New Castle County'),
                'claymont' => array('name' => 'Claymont', 'lat' => 39.8015, 'lng' => -75.4591, 'population' => 9300, 'region' => 'New Castle County'),
                'elsmere' => array('name' => 'Elsmere', 'lat' => 39.7393, 'lng' => -75.5977, 'population' => 5800, 'region' => 'New Castle County'),
                'new-castle' => array('name' => 'New Castle', 'lat' => 39.6618, 'lng' => -75.5666, 'population' => 5300, 'region' => 'New Castle County'),
                'townsend' => array('name' => 'Townsend', 'lat' => 39.3965, 'lng' => -75.6916, 'population' => 2500, 'region' => 'New Castle County'),
                'dover' => array('name' => 'Dover', 'lat' => 39.1582, 'lng' => -75.5244, 'population' => 39400, 'region' => 'Kent County'),
                'smyrna' => array('name' => 'Smyrna', 'lat' => 39.2998, 'lng' => -75.6044, 'population' => 12000, 'region' => 'Kent County'),
            ),
            'regions' => array('New Castle County', 'Brandywine Valley', 'Kent County'),
        ),
        
        'maryland' => array(
            'name' => 'Maryland',
            'abbr' => 'MD',
            'meta_region' => 'Greater Baltimore',
            'primary_city' => 'baltimore',
            'major_cities' => array(
                'baltimore' => array('name' => 'Baltimore', 'lat' => 39.2904, 'lng' => -76.6122, 'population' => 586000, 'metro' => true, 'region' => 'Baltimore City', 'neighborhoods' => array('Canton', 'Fells Point', 'Federal Hill', 'Roland Park', 'Hampden', 'Mt. Vernon', 'Inner Harbor')),
                'towson' => array('name' => 'Towson', 'lat' => 39.4015, 'lng' => -76.6019, 'population' => 57500, 'metro' => true, 'region' => 'Baltimore County'),
                'columbia' => array('name' => 'Columbia', 'lat' => 39.2037, 'lng' => -76.8610, 'population' => 105000, 'metro' => true, 'region' => 'Howard County'),
                'ellicott-city' => array('name' => 'Ellicott City', 'lat' => 39.2674, 'lng' => -76.7983, 'population' => 73000, 'region' => 'Howard County'),
                'bethesda' => array('name' => 'Bethesda', 'lat' => 38.9848, 'lng' => -77.0947, 'population' => 68000, 'metro' => true, 'region' => 'Montgomery County'),
                'rockville' => array('name' => 'Rockville', 'lat' => 39.0840, 'lng' => -77.1528, 'population' => 68000, 'region' => 'Montgomery County'),
                'bel-air' => array('name' => 'Bel Air', 'lat' => 39.5351, 'lng' => -76.3483, 'population' => 10500, 'region' => 'Harford County'),
                'annapolis' => array('name' => 'Annapolis', 'lat' => 38.9784, 'lng' => -76.4922, 'population' => 40800, 'metro' => true, 'region' => 'Anne Arundel County'),
                'hunt-valley' => array('name' => 'Hunt Valley', 'lat' => 39.4998, 'lng' => -76.6416, 'population' => 8500, 'region' => 'Baltimore County'),
                'timonium' => array('name' => 'Timonium', 'lat' => 39.4373, 'lng' => -76.6197, 'population' => 10200, 'region' => 'Baltimore County'),
                'pikesville' => array('name' => 'Pikesville', 'lat' => 39.3743, 'lng' => -76.7222, 'population' => 32000, 'region' => 'Baltimore County'),
                'reisterstown' => array('name' => 'Reisterstown', 'lat' => 39.4693, 'lng' => -76.8294, 'population' => 27000, 'region' => 'Baltimore County'),
                'owings-mills' => array('name' => 'Owings Mills', 'lat' => 39.4193, 'lng' => -76.7697, 'population' => 34700, 'region' => 'Baltimore County'),
                'catonsville' => array('name' => 'Catonsville', 'lat' => 39.2720, 'lng' => -76.7319, 'population' => 42000, 'region' => 'Baltimore County'),
                'parkville' => array('name' => 'Parkville', 'lat' => 39.3773, 'lng' => -76.5397, 'population' => 31000, 'region' => 'Baltimore County'),
                'perry-hall' => array('name' => 'Perry Hall', 'lat' => 39.4126, 'lng' => -76.4633, 'population' => 29000, 'region' => 'Baltimore County'),
                'severna-park' => array('name' => 'Severna Park', 'lat' => 39.0704, 'lng' => -76.5455, 'population' => 38000, 'region' => 'Anne Arundel County'),
                'crofton' => array('name' => 'Crofton', 'lat' => 39.0179, 'lng' => -76.6872, 'population' => 28000, 'region' => 'Anne Arundel County'),
                'glen-burnie' => array('name' => 'Glen Burnie', 'lat' => 39.1626, 'lng' => -76.6247, 'population' => 69000, 'region' => 'Anne Arundel County'),
                'clarksville' => array('name' => 'Clarksville', 'lat' => 39.2107, 'lng' => -76.9497, 'population' => 15000, 'region' => 'Howard County'),
                'fulton' => array('name' => 'Fulton', 'lat' => 39.1543, 'lng' => -76.9261, 'population' => 5000, 'region' => 'Howard County'),
                'laurel' => array('name' => 'Laurel', 'lat' => 39.0993, 'lng' => -76.8483, 'population' => 26000, 'region' => 'Howard County'),
                'chevy-chase' => array('name' => 'Chevy Chase', 'lat' => 38.9943, 'lng' => -77.0716, 'population' => 10000, 'region' => 'Montgomery County'),
                'potomac' => array('name' => 'Potomac', 'lat' => 39.0179, 'lng' => -77.2086, 'population' => 46000, 'region' => 'Montgomery County'),
                'silver-spring' => array('name' => 'Silver Spring', 'lat' => 38.9907, 'lng' => -77.0261, 'population' => 81000, 'region' => 'Montgomery County'),
                'germantown' => array('name' => 'Germantown', 'lat' => 39.1732, 'lng' => -77.2716, 'population' => 92000, 'region' => 'Montgomery County'),
                'gaithersburg' => array('name' => 'Gaithersburg', 'lat' => 39.1434, 'lng' => -77.2014, 'population' => 68000, 'region' => 'Montgomery County'),
                'aberdeen' => array('name' => 'Aberdeen', 'lat' => 39.5096, 'lng' => -76.1644, 'population' => 16000, 'region' => 'Harford County'),
                'havre-de-grace' => array('name' => 'Havre de Grace', 'lat' => 39.5493, 'lng' => -76.0916, 'population' => 14000, 'region' => 'Harford County'),
            ),
            'regions' => array('Baltimore Metro', 'Howard County', 'Montgomery County', 'Harford County', 'Anne Arundel County'),
        ),
        
        'new-york' => array(
            'name' => 'New York',
            'abbr' => 'NY',
            'meta_region' => 'NYC Metro',
            'primary_city' => 'brooklyn',
            'major_cities' => array(
                // NYC Boroughs
                'brooklyn' => array('name' => 'Brooklyn', 'lat' => 40.6782, 'lng' => -73.9442, 'population' => 2600000, 'metro' => true, 'region' => 'NYC', 'neighborhoods' => array('Park Slope', 'Brooklyn Heights', 'Williamsburg', 'Cobble Hill', 'DUMBO', 'Fort Greene', 'Bay Ridge')),
                'queens' => array('name' => 'Queens', 'lat' => 40.7282, 'lng' => -73.7949, 'population' => 2300000, 'metro' => true, 'region' => 'NYC', 'neighborhoods' => array('Astoria', 'Forest Hills', 'Flushing', 'Jackson Heights', 'Bayside', 'Jamaica', 'Long Island City')),
                'staten-island' => array('name' => 'Staten Island', 'lat' => 40.5795, 'lng' => -74.1502, 'population' => 476000, 'region' => 'NYC', 'neighborhoods' => array('St. George', 'Tottenville', 'Great Kills', 'New Dorp')),
                
                // Westchester County
                'westchester' => array('name' => 'Westchester', 'lat' => 41.1220, 'lng' => -73.7949, 'population' => 980000, 'metro' => true, 'region' => 'Westchester County'),
                'white-plains' => array('name' => 'White Plains', 'lat' => 41.0340, 'lng' => -73.7629, 'population' => 58500, 'region' => 'Westchester County'),
                'yonkers' => array('name' => 'Yonkers', 'lat' => 40.9312, 'lng' => -73.8987, 'population' => 200000, 'region' => 'Westchester County'),
                'scarsdale' => array('name' => 'Scarsdale', 'lat' => 41.0051, 'lng' => -73.7846, 'population' => 18000, 'region' => 'Westchester County'),
                'rye' => array('name' => 'Rye', 'lat' => 40.9826, 'lng' => -73.6835, 'population' => 16000, 'region' => 'Westchester County'),
                'mamaroneck' => array('name' => 'Mamaroneck', 'lat' => 40.9490, 'lng' => -73.7321, 'population' => 19000, 'region' => 'Westchester County'),
                'larchmont' => array('name' => 'Larchmont', 'lat' => 40.9276, 'lng' => -73.7518, 'population' => 6200, 'region' => 'Westchester County'),
                'bronxville' => array('name' => 'Bronxville', 'lat' => 40.9384, 'lng' => -73.8321, 'population' => 6500, 'region' => 'Westchester County'),
                'dobbs-ferry' => array('name' => 'Dobbs Ferry', 'lat' => 41.0115, 'lng' => -73.8721, 'population' => 11000, 'region' => 'Westchester County'),
                'pelham' => array('name' => 'Pelham', 'lat' => 40.9101, 'lng' => -73.8081, 'population' => 7000, 'region' => 'Westchester County'),
                'pelham-manor' => array('name' => 'Pelham Manor', 'lat' => 40.8962, 'lng' => -73.8070, 'population' => 5500, 'region' => 'Westchester County'),
                'new-rochelle' => array('name' => 'New Rochelle', 'lat' => 40.9115, 'lng' => -73.7824, 'population' => 79000, 'region' => 'Westchester County'),
                'harrison' => array('name' => 'Harrison', 'lat' => 41.0293, 'lng' => -73.7185, 'population' => 28000, 'region' => 'Westchester County'),
                'armonk' => array('name' => 'Armonk', 'lat' => 41.1262, 'lng' => -73.7140, 'population' => 4400, 'region' => 'Westchester County'),
                'chappaqua' => array('name' => 'Chappaqua', 'lat' => 41.1590, 'lng' => -73.7651, 'population' => 10000, 'region' => 'Westchester County'),
                'bedford' => array('name' => 'Bedford', 'lat' => 41.2048, 'lng' => -73.6443, 'population' => 18000, 'region' => 'Westchester County'),
                'katonah' => array('name' => 'Katonah', 'lat' => 41.2590, 'lng' => -73.6851, 'population' => 2000, 'region' => 'Westchester County'),
                'pound-ridge' => array('name' => 'Pound Ridge', 'lat' => 41.2076, 'lng' => -73.5751, 'population' => 5000, 'region' => 'Westchester County'),
                'tarrytown' => array('name' => 'Tarrytown', 'lat' => 41.0762, 'lng' => -73.8587, 'population' => 11500, 'region' => 'Westchester County'),
                'sleepy-hollow' => array('name' => 'Sleepy Hollow', 'lat' => 41.0854, 'lng' => -73.8587, 'population' => 10000, 'region' => 'Westchester County'),
                'irvington' => array('name' => 'Irvington', 'lat' => 41.0393, 'lng' => -73.8668, 'population' => 6800, 'region' => 'Westchester County'),
                'hastings-on-hudson' => array('name' => 'Hastings-on-Hudson', 'lat' => 40.9915, 'lng' => -73.8787, 'population' => 8000, 'region' => 'Westchester County'),
                'pleasantville' => array('name' => 'Pleasantville', 'lat' => 41.1326, 'lng' => -73.7918, 'population' => 7200, 'region' => 'Westchester County'),
                'mt-kisco' => array('name' => 'Mount Kisco', 'lat' => 41.2048, 'lng' => -73.7268, 'population' => 11000, 'region' => 'Westchester County'),
                'ossining' => array('name' => 'Ossining', 'lat' => 41.1626, 'lng' => -73.8615, 'population' => 26000, 'region' => 'Westchester County'),
                
                // Long Island - Nassau County
                'long-island' => array('name' => 'Long Island', 'lat' => 40.7891, 'lng' => -73.1350, 'population' => 2800000, 'metro' => true, 'region' => 'Long Island'),
                'garden-city' => array('name' => 'Garden City', 'lat' => 40.7268, 'lng' => -73.6343, 'population' => 23000, 'region' => 'Nassau County'),
                'great-neck' => array('name' => 'Great Neck', 'lat' => 40.8029, 'lng' => -73.7285, 'population' => 10500, 'region' => 'Nassau County'),
                'manhasset' => array('name' => 'Manhasset', 'lat' => 40.7979, 'lng' => -73.7004, 'population' => 8500, 'region' => 'Nassau County'),
                'roslyn' => array('name' => 'Roslyn', 'lat' => 40.7998, 'lng' => -73.6513, 'population' => 3000, 'region' => 'Nassau County'),
                'huntington' => array('name' => 'Huntington', 'lat' => 40.8682, 'lng' => -73.4257, 'population' => 18500, 'region' => 'Suffolk County'),
                'port-washington' => array('name' => 'Port Washington', 'lat' => 40.8257, 'lng' => -73.6982, 'population' => 16000, 'region' => 'Nassau County'),
                'old-westbury' => array('name' => 'Old Westbury', 'lat' => 40.7887, 'lng' => -73.6007, 'population' => 4600, 'region' => 'Nassau County'),
                'jericho' => array('name' => 'Jericho', 'lat' => 40.7921, 'lng' => -73.5396, 'population' => 14000, 'region' => 'Nassau County'),
                'syosset' => array('name' => 'Syosset', 'lat' => 40.8254, 'lng' => -73.5018, 'population' => 19000, 'region' => 'Nassau County'),
                'woodbury' => array('name' => 'Woodbury', 'lat' => 40.8226, 'lng' => -73.4679, 'population' => 9000, 'region' => 'Nassau County'),
                'plainview' => array('name' => 'Plainview', 'lat' => 40.7765, 'lng' => -73.4677, 'population' => 26000, 'region' => 'Nassau County'),
                'mineola' => array('name' => 'Mineola', 'lat' => 40.7490, 'lng' => -73.6404, 'population' => 19000, 'region' => 'Nassau County'),
                'rockville-centre' => array('name' => 'Rockville Centre', 'lat' => 40.6590, 'lng' => -73.6404, 'population' => 24600, 'region' => 'Nassau County'),
                'valley-stream' => array('name' => 'Valley Stream', 'lat' => 40.6643, 'lng' => -73.7085, 'population' => 38000, 'region' => 'Nassau County'),
                'hewlett' => array('name' => 'Hewlett', 'lat' => 40.6390, 'lng' => -73.6932, 'population' => 7100, 'region' => 'Nassau County'),
                'lawrence' => array('name' => 'Lawrence', 'lat' => 40.6157, 'lng' => -73.7293, 'population' => 6500, 'region' => 'Nassau County'),
                'woodmere' => array('name' => 'Woodmere', 'lat' => 40.6318, 'lng' => -73.7118, 'population' => 17000, 'region' => 'Nassau County'),
                'merrick' => array('name' => 'Merrick', 'lat' => 40.6629, 'lng' => -73.5513, 'population' => 22000, 'region' => 'Nassau County'),
                'bellmore' => array('name' => 'Bellmore', 'lat' => 40.6687, 'lng' => -73.5268, 'population' => 16500, 'region' => 'Nassau County'),
                'massapequa' => array('name' => 'Massapequa', 'lat' => 40.6809, 'lng' => -73.4735, 'population' => 22000, 'region' => 'Nassau County'),
            ),
            'regions' => array('NYC Metro', 'Westchester County', 'Nassau County', 'Suffolk County', 'Long Island'),
        ),
    );
    
    /**
     * Training service types - Enhanced with LSI keywords
     */
    private static $services = array(
        'private-soccer-training' => array(
            'name' => 'Private Soccer Training',
            'short' => 'Private Training',
            'title_format' => 'Private Soccer Training in %s | 1-on-1 Sessions | PTP',
            'h1_format' => 'Private Soccer Training in %s',
            'description' => '1-on-1 personalized soccer training with elite NCAA Division 1 coaches. Custom training plans, flexible scheduling, proven results.',
            'long_description' => 'Our private soccer training program pairs your child with current NCAA Division 1 athletes and elite college players for personalized 1-on-1 sessions. Each session is tailored to your player\'s specific needs, whether they\'re working on ball mastery, shooting technique, tactical awareness, or position-specific skills.',
            'keywords' => array('private soccer training', 'personal soccer coach', '1 on 1 soccer training', 'private soccer lessons', 'private soccer coach', 'individual soccer training', 'personal soccer trainer', 'one on one soccer', 'private soccer sessions'),
            'lsi_keywords' => array('ball mastery', 'technical training', 'skill development', 'soccer coaching', 'individual attention', 'custom training plan', 'flexible scheduling'),
            'icon' => 'user',
            'price_range' => '$75-$150/hour',
            'age_range' => '5-18 years',
            'cta' => 'Book Your Private Session',
            'benefits' => array(
                'Personalized training tailored to your goals',
                'Train with NCAA D1 and college athletes',
                'Flexible scheduling that works for you',
                'Progress tracking and skill assessments',
            ),
        ),
        'soccer-camps' => array(
            'name' => 'Soccer Camps',
            'short' => 'Camps',
            'title_format' => 'Soccer Camps in %s | Summer & Holiday Camps | PTP',
            'h1_format' => 'Soccer Camps in %s',
            'description' => 'Week-long intensive soccer camps led by professional and NCAA Division 1 players. Skill development, competitions, and unforgettable experiences.',
            'long_description' => 'PTP Soccer Camps deliver the ultimate training experience with professional player coaches who don\'t just instruct - they PLAY with your kids. Our unique format features 3v3 and 4v4 gameplay, skill competitions, individual coach attention, and a mentorship-first approach that develops both skills and character.',
            'keywords' => array('soccer camps', 'youth soccer camp', 'summer soccer camp', 'soccer training camp', 'soccer day camp', 'kids soccer camp', 'elite soccer camp', 'soccer camp near me'),
            'lsi_keywords' => array('week-long camp', 'daily training', 'skill competitions', 'jersey included', 'professional coaches', 'age-appropriate groups'),
            'icon' => 'camp',
            'price_range' => '$375-$525/week',
            'age_range' => '5-14 years',
            'cta' => 'Register for Camp',
            'benefits' => array(
                'Coaches play WITH your kids, not just instruct',
                'Small groups for maximum touches',
                'Jersey, player card, and skills passport included',
                'Daily skill competitions and games',
            ),
        ),
        'soccer-clinics' => array(
            'name' => 'Soccer Clinics',
            'short' => 'Clinics',
            'title_format' => 'Soccer Clinics in %s | Skills & Position Training | PTP',
            'h1_format' => 'Soccer Clinics in %s',
            'description' => 'Specialized skill-focused soccer clinics and workshops. Intensive training on specific techniques with expert coaches.',
            'long_description' => 'Our soccer clinics focus on specific skills and positions, providing intensive training in a short time frame. Whether your player needs to improve finishing, work on defensive positioning, or master set pieces, our clinics deliver focused, expert instruction.',
            'keywords' => array('soccer clinics', 'soccer skills clinic', 'soccer workshop', 'elite soccer clinic', 'soccer training clinic', 'youth soccer clinic', 'soccer skills workshop'),
            'lsi_keywords' => array('skill-focused', 'intensive training', 'position-specific', 'technical development', 'expert instruction'),
            'icon' => 'clinic',
            'price_range' => '$50-$100/session',
            'age_range' => '6-18 years',
            'cta' => 'Join a Clinic',
            'benefits' => array(
                'Focused skill development',
                'Expert coaching on specific techniques',
                'Position-specific training available',
                'Great for team players seeking extra work',
            ),
        ),
        'group-soccer-training' => array(
            'name' => 'Group Soccer Training',
            'short' => 'Group Training',
            'title_format' => 'Group Soccer Training in %s | Small Group Sessions | PTP',
            'h1_format' => 'Group Soccer Training in %s',
            'description' => 'Small group training sessions (2-4 players) for focused skill development at a great value.',
            'long_description' => 'Group training combines the personal attention of private coaching with the fun and competition of training with peers. Sessions are limited to 2-4 players of similar age and skill level, ensuring everyone gets quality touches and instruction.',
            'keywords' => array('group soccer training', 'small group soccer', 'soccer group lessons', 'team training', 'semi-private soccer', 'small group coaching'),
            'lsi_keywords' => array('peer training', 'competitive environment', 'value pricing', 'social learning', 'team dynamics'),
            'icon' => 'group',
            'price_range' => '$40-$75/player',
            'age_range' => '5-18 years',
            'cta' => 'Book Group Training',
            'benefits' => array(
                'More affordable than private training',
                'Train with friends or similar-level players',
                'Competitive environment drives improvement',
                'Social and fun atmosphere',
            ),
        ),
        'goalkeeper-training' => array(
            'name' => 'Goalkeeper Training',
            'short' => 'GK Training',
            'title_format' => 'Goalkeeper Training in %s | GK Coaching | PTP',
            'h1_format' => 'Goalkeeper Training in %s',
            'description' => 'Specialized goalkeeper training with experienced GK coaches. Technical skills, positioning, distribution, and mental preparation.',
            'long_description' => 'Our goalkeeper training program addresses the unique demands of the position. Work with specialized GK coaches on shot stopping, diving technique, positioning, distribution, crosses, and the mental aspects that separate good keepers from great ones.',
            'keywords' => array('goalkeeper training', 'soccer goalie training', 'gk coach', 'goalkeeper lessons', 'goalkeeper coaching', 'goalie training', 'youth goalkeeper training'),
            'lsi_keywords' => array('shot stopping', 'diving technique', 'distribution', 'positioning', 'crosses', 'mental preparation'),
            'icon' => 'goalkeeper',
            'price_range' => '$75-$125/hour',
            'age_range' => '8-18 years',
            'cta' => 'Train Your Keeper',
            'benefits' => array(
                'Specialized GK coaching',
                'Technical and mental training',
                'Position-specific equipment and drills',
                'Video analysis available',
            ),
        ),
        'youth-soccer-training' => array(
            'name' => 'Youth Soccer Training',
            'short' => 'Youth Training',
            'title_format' => 'Youth Soccer Training in %s | Kids Soccer | PTP',
            'h1_format' => 'Youth Soccer Training in %s',
            'description' => 'Age-appropriate soccer training for young players ages 5-12. Fun, engaging sessions that build skills and love for the game.',
            'long_description' => 'Our youth soccer training program is designed specifically for younger players, with age-appropriate activities that build fundamental skills while keeping training fun and engaging. We focus on developing a love for the game alongside technical development.',
            'keywords' => array('youth soccer training', 'kids soccer training', 'junior soccer lessons', 'youth soccer coach', 'children soccer training', 'beginner soccer', 'kids soccer lessons'),
            'lsi_keywords' => array('age-appropriate', 'fun activities', 'fundamental skills', 'love of the game', 'confidence building', 'positive environment'),
            'icon' => 'youth',
            'price_range' => '$65-$100/hour',
            'age_range' => '5-12 years',
            'cta' => 'Start Your Child\'s Journey',
            'benefits' => array(
                'Age-appropriate curriculum',
                'Patient, encouraging coaches',
                'Focus on fun and fundamentals',
                'Build confidence and love for soccer',
            ),
        ),
        'elite-soccer-training' => array(
            'name' => 'Elite Soccer Training',
            'short' => 'Elite Training',
            'title_format' => 'Elite Soccer Training in %s | Advanced Players | PTP',
            'h1_format' => 'Elite Soccer Training in %s',
            'description' => 'High-performance training for competitive and travel team players. Advanced techniques, tactical development, and college prep.',
            'long_description' => 'Our elite training program is designed for serious players on travel teams, academy programs, or those aspiring to play at the next level. Work with our most experienced coaches on advanced techniques, tactical understanding, physical preparation, and college recruitment guidance.',
            'keywords' => array('elite soccer training', 'advanced soccer training', 'travel team training', 'competitive soccer', 'high performance soccer', 'college prep soccer'),
            'lsi_keywords' => array('college recruitment', 'academy level', 'travel team', 'competitive player', 'advanced tactics', 'physical preparation'),
            'icon' => 'elite',
            'price_range' => '$100-$175/hour',
            'age_range' => '12-18 years',
            'cta' => 'Elevate Your Game',
            'benefits' => array(
                'Train with top-level coaches',
                'Advanced tactical instruction',
                'College recruitment guidance',
                'Physical and mental preparation',
            ),
        ),
        'soccer-lessons' => array(
            'name' => 'Soccer Lessons',
            'short' => 'Lessons',
            'title_format' => 'Soccer Lessons in %s | All Ages & Levels | PTP',
            'h1_format' => 'Soccer Lessons in %s',
            'description' => 'Professional soccer lessons for all ages and skill levels. Learn from experienced coaches in a supportive environment.',
            'long_description' => 'Whether you\'re a complete beginner or looking to refine your skills, our soccer lessons provide quality instruction tailored to your level. Our coaches create a supportive, encouraging environment where every player can learn and improve.',
            'keywords' => array('soccer lessons', 'soccer classes', 'learn soccer', 'soccer instruction', 'beginner soccer lessons', 'soccer coaching'),
            'lsi_keywords' => array('all levels', 'beginner friendly', 'skill building', 'patient instruction', 'supportive environment'),
            'icon' => 'lessons',
            'price_range' => '$65-$150/hour',
            'age_range' => '5-Adult',
            'cta' => 'Start Learning Today',
            'benefits' => array(
                'All skill levels welcome',
                'Patient, experienced coaches',
                'Progress at your own pace',
                'Fundamentals to advanced',
            ),
        ),
    );
    
    /**
     * Initialize SEO locations V85
     */
    public static function init() {
        // Register rewrite rules
        add_action('init', array(__CLASS__, 'register_rewrites'), 10);
        
        // Handle location pages
        add_filter('template_include', array(__CLASS__, 'load_location_template'));
        
        // Add query vars
        add_filter('query_vars', array(__CLASS__, 'add_query_vars'));
        
        // Admin menu for SEO
        add_action('admin_menu', array(__CLASS__, 'add_admin_menu'));
        
        // AJAX handlers
        add_action('wp_ajax_ptp_seo_flush_rewrites', array(__CLASS__, 'ajax_flush_rewrites'));
        add_action('wp_ajax_ptp_seo_stats', array(__CLASS__, 'ajax_get_stats'));
        
        // Schema output
        add_action('wp_head', array(__CLASS__, 'output_location_schema'), 5);
        
        // Meta tags
        add_action('wp_head', array(__CLASS__, 'output_location_meta'), 3);
        
        // Canonical URL
        add_action('wp_head', array(__CLASS__, 'output_canonical'), 4);
        
        // Remove default canonical if on SEO page
        add_filter('get_canonical_url', array(__CLASS__, 'filter_canonical'), 10, 2);
        
        // Breadcrumbs
        add_shortcode('ptp_seo_breadcrumbs', array(__CLASS__, 'breadcrumbs_shortcode'));
        
        // robots.txt enhancement
        add_filter('robots_txt', array(__CLASS__, 'enhance_robots_txt'), 10, 2);
    }
    
    /**
     * Register URL rewrites for location pages
     */
    public static function register_rewrites() {
        // Service slugs regex
        $services_regex = '(private-soccer-training|soccer-camps|soccer-clinics|group-soccer-training|goalkeeper-training|youth-soccer-training|elite-soccer-training|soccer-lessons)';
        
        // Root page: /soccer-training/
        add_rewrite_rule(
            'soccer-training/?$',
            'index.php?ptp_seo_page=root',
            'top'
        );
        
        // State pages: /soccer-training/pennsylvania/
        add_rewrite_rule(
            'soccer-training/([a-z-]+)/?$',
            'index.php?ptp_seo_page=state&ptp_state=$matches[1]',
            'top'
        );
        
        // City pages: /soccer-training/pennsylvania/philadelphia/
        add_rewrite_rule(
            'soccer-training/([a-z-]+)/([a-z-]+)/?$',
            'index.php?ptp_seo_page=city&ptp_state=$matches[1]&ptp_city=$matches[2]',
            'top'
        );
        
        // Service pages: /private-soccer-training/
        add_rewrite_rule(
            $services_regex . '/?$',
            'index.php?ptp_seo_page=service&ptp_service=$matches[1]',
            'top'
        );
        
        // Service + State: /private-soccer-training/pennsylvania/
        add_rewrite_rule(
            $services_regex . '/([a-z-]+)/?$',
            'index.php?ptp_seo_page=service_state&ptp_service=$matches[1]&ptp_state=$matches[2]',
            'top'
        );
        
        // Service + City: /private-soccer-training/pennsylvania/philadelphia/
        add_rewrite_rule(
            $services_regex . '/([a-z-]+)/([a-z-]+)/?$',
            'index.php?ptp_seo_page=service_city&ptp_service=$matches[1]&ptp_state=$matches[2]&ptp_city=$matches[3]',
            'top'
        );
        
        // Near me pages: /soccer-training-near-me/
        add_rewrite_rule(
            'soccer-training-near-me/?$',
            'index.php?ptp_seo_page=near_me',
            'top'
        );
        
        // Near me with service: /private-soccer-training-near-me/
        add_rewrite_rule(
            $services_regex . '-near-me/?$',
            'index.php?ptp_seo_page=service_near_me&ptp_service=$matches[1]',
            'top'
        );
        
        // Find trainers: /find-soccer-trainers/
        add_rewrite_rule(
            'find-soccer-trainers/?$',
            'index.php?ptp_seo_page=find_trainers',
            'top'
        );
        
        // Find trainers in city: /find-soccer-trainers/philadelphia/
        add_rewrite_rule(
            'find-soccer-trainers/([a-z-]+)/?$',
            'index.php?ptp_seo_page=find_trainers_city&ptp_city=$matches[1]',
            'top'
        );
        
        // Best trainers: /best-soccer-trainers/philadelphia/
        add_rewrite_rule(
            'best-soccer-trainers/([a-z-]+)/?$',
            'index.php?ptp_seo_page=best_trainers&ptp_city=$matches[1]',
            'top'
        );
        
        // Book trainer: /book-soccer-trainer/pennsylvania/philadelphia/
        add_rewrite_rule(
            'book-soccer-trainer/([a-z-]+)/([a-z-]+)/?$',
            'index.php?ptp_seo_page=book_trainer&ptp_state=$matches[1]&ptp_city=$matches[2]',
            'top'
        );
        
        // Neighborhood pages: /soccer-training/pennsylvania/philadelphia/fishtown/
        add_rewrite_rule(
            'soccer-training/([a-z-]+)/([a-z-]+)/([a-z-]+)/?$',
            'index.php?ptp_seo_page=neighborhood&ptp_state=$matches[1]&ptp_city=$matches[2]&ptp_neighborhood=$matches[3]',
            'top'
        );
    }
    
    /**
     * Add query vars
     */
    public static function add_query_vars($vars) {
        $vars[] = 'ptp_seo_page';
        $vars[] = 'ptp_state';
        $vars[] = 'ptp_city';
        $vars[] = 'ptp_service';
        $vars[] = 'ptp_neighborhood';
        return $vars;
    }
    
    /**
     * Load appropriate template for location pages
     */
    public static function load_location_template($template) {
        $seo_page = get_query_var('ptp_seo_page');
        
        if (!$seo_page) {
            return $template;
        }
        
        // Check for theme override
        $theme_template = locate_template('ptp-seo-location-v85.php');
        if ($theme_template) {
            return $theme_template;
        }
        
        // Use plugin template
        $plugin_template = PTP_PLUGIN_DIR . 'templates/seo-location-page-v85.php';
        if (file_exists($plugin_template)) {
            return $plugin_template;
        }
        
        // Fallback to existing template
        return PTP_PLUGIN_DIR . 'templates/seo-location-page.php';
    }
    
    /**
     * Get states
     */
    public static function get_states() {
        return self::$states;
    }
    
    /**
     * Get services
     */
    public static function get_services() {
        return self::$services;
    }
    
    /**
     * Get state by slug
     */
    public static function get_state($slug) {
        return isset(self::$states[$slug]) ? array_merge(self::$states[$slug], array('slug' => $slug)) : null;
    }
    
    /**
     * Get city by slug
     */
    public static function get_city($city_slug, $state_slug = null) {
        foreach (self::$states as $s_slug => $state) {
            if ($state_slug && $s_slug !== $state_slug) continue;
            
            if (isset($state['major_cities'][$city_slug])) {
                return array_merge(
                    $state['major_cities'][$city_slug],
                    array(
                        'slug' => $city_slug,
                        'state_slug' => $s_slug,
                        'state_name' => $state['name'],
                        'state_abbr' => $state['abbr'],
                    )
                );
            }
        }
        return null;
    }
    
    /**
     * Get service by slug
     */
    public static function get_service($slug) {
        return isset(self::$services[$slug]) ? array_merge(self::$services[$slug], array('slug' => $slug)) : null;
    }
    
    /**
     * Get all cities
     */
    public static function get_all_cities() {
        $cities = array();
        foreach (self::$states as $state_slug => $state) {
            foreach ($state['major_cities'] as $city_slug => $city) {
                $cities[] = array_merge($city, array(
                    'slug' => $city_slug,
                    'state_slug' => $state_slug,
                    'state_name' => $state['name'],
                    'state_abbr' => $state['abbr'],
                ));
            }
        }
        return $cities;
    }
    
    /**
     * Count total cities
     */
    public static function count_cities() {
        $count = 0;
        foreach (self::$states as $state) {
            $count += count($state['major_cities']);
        }
        return $count;
    }
    
    /**
     * Calculate distance between two points
     */
    public static function calculate_distance($lat1, $lng1, $lat2, $lng2) {
        $earth_radius = 3959; // miles
        
        $lat1 = deg2rad($lat1);
        $lat2 = deg2rad($lat2);
        $lng1 = deg2rad($lng1);
        $lng2 = deg2rad($lng2);
        
        $dlat = $lat2 - $lat1;
        $dlng = $lng2 - $lng1;
        
        $a = sin($dlat / 2) * sin($dlat / 2) + cos($lat1) * cos($lat2) * sin($dlng / 2) * sin($dlng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return $earth_radius * $c;
    }
    
    /**
     * Get nearby cities
     */
    public static function get_nearby_cities($city_slug, $state_slug, $radius = 30, $limit = 8) {
        $city = self::get_city($city_slug, $state_slug);
        if (!$city) return array();
        
        $nearby = array();
        
        foreach (self::$states as $s_slug => $state) {
            foreach ($state['major_cities'] as $c_slug => $c) {
                if ($c_slug === $city_slug) continue;
                
                $distance = self::calculate_distance(
                    $city['lat'], $city['lng'],
                    $c['lat'], $c['lng']
                );
                
                if ($distance <= $radius) {
                    $nearby[] = array_merge($c, array(
                        'slug' => $c_slug,
                        'state_slug' => $s_slug,
                        'state_name' => $state['name'],
                        'state_abbr' => $state['abbr'],
                        'distance' => round($distance, 1),
                    ));
                }
            }
        }
        
        usort($nearby, function($a, $b) {
            return $a['distance'] <=> $b['distance'];
        });
        
        return array_slice($nearby, 0, $limit);
    }
    
    /**
     * Get page data for current request
     */
    public static function get_page_data() {
        $page_type = get_query_var('ptp_seo_page');
        $state_slug = get_query_var('ptp_state');
        $city_slug = get_query_var('ptp_city');
        $service_slug = get_query_var('ptp_service');
        $neighborhood_slug = get_query_var('ptp_neighborhood');
        
        $data = array(
            'type' => $page_type,
            'state' => null,
            'city' => null,
            'service' => null,
            'neighborhood' => null,
            'title' => '',
            'description' => '',
            'h1' => '',
            'breadcrumbs' => array(),
            'canonical' => '',
            'nearby_cities' => array(),
            'all_cities' => array(),
            'trainers' => array(),
            'camps' => array(),
            'faqs' => array(),
        );
        
        $site_url = home_url();
        
        // Get state data
        if ($state_slug) {
            $data['state'] = self::get_state($state_slug);
        }
        
        // Get city data
        if ($city_slug) {
            $data['city'] = self::get_city($city_slug, $state_slug);
        }
        
        // Get service data
        if ($service_slug) {
            $data['service'] = self::get_service($service_slug);
        }
        
        // Build page content based on type
        switch ($page_type) {
            case 'root':
                $data['title'] = 'Soccer Training | Find Trainers, Camps & Lessons | PTP Soccer';
                $data['description'] = 'Find elite soccer training near you. Private lessons, camps, clinics, and group training with NCAA Division 1 and elite college coaches. Book online today.';
                $data['h1'] = 'Soccer Training';
                $data['canonical'] = $site_url . '/soccer-training/';
                $data['breadcrumbs'] = array(
                    array('name' => 'Home', 'url' => $site_url),
                    array('name' => 'Soccer Training', 'url' => ''),
                );
                break;
                
            case 'state':
                if ($data['state']) {
                    $state_name = $data['state']['name'];
                    $data['title'] = "Soccer Training in {$state_name} | Camps, Clinics & Private Lessons | PTP";
                    $data['description'] = "Find elite soccer training in {$state_name}. Private lessons, camps, clinics & group training with NCAA Division 1 and elite college coaches across {$state_name}. Book online.";
                    $data['h1'] = "Soccer Training in {$state_name}";
                    $data['canonical'] = $site_url . "/soccer-training/{$state_slug}/";
                    $data['breadcrumbs'] = array(
                        array('name' => 'Home', 'url' => $site_url),
                        array('name' => 'Soccer Training', 'url' => $site_url . '/soccer-training/'),
                        array('name' => $state_name, 'url' => ''),
                    );
                    $data['all_cities'] = $data['state']['major_cities'];
                }
                break;
                
            case 'city':
                if ($data['city']) {
                    $city_name = $data['city']['name'];
                    $state_name = $data['city']['state_name'];
                    $state_abbr = $data['city']['state_abbr'];
                    
                    $data['title'] = "Soccer Training in {$city_name}, {$state_abbr} | Private Lessons & Camps | PTP";
                    $data['description'] = "Elite soccer training in {$city_name}, {$state_name}. 1-on-1 private lessons, summer camps, clinics & group training with NCAA D1 and elite college coaches. Book online today.";
                    $data['h1'] = "Soccer Training in {$city_name}, {$state_abbr}";
                    $data['canonical'] = $site_url . "/soccer-training/{$state_slug}/{$city_slug}/";
                    $data['breadcrumbs'] = array(
                        array('name' => 'Home', 'url' => $site_url),
                        array('name' => 'Soccer Training', 'url' => $site_url . '/soccer-training/'),
                        array('name' => $state_name, 'url' => $site_url . "/soccer-training/{$state_slug}/"),
                        array('name' => $city_name, 'url' => ''),
                    );
                    $data['nearby_cities'] = self::get_nearby_cities($city_slug, $state_slug);
                }
                break;
                
            case 'service':
                if ($data['service']) {
                    $service_name = $data['service']['name'];
                    $data['title'] = "{$service_name} | Professional Soccer Coaching | PTP";
                    $data['description'] = $data['service']['description'] . ' Book with top-rated coaches across PA, NJ, DE, MD, NY.';
                    $data['h1'] = $service_name;
                    $data['canonical'] = $site_url . "/{$service_slug}/";
                    $data['breadcrumbs'] = array(
                        array('name' => 'Home', 'url' => $site_url),
                        array('name' => $service_name, 'url' => ''),
                    );
                }
                break;
                
            case 'service_state':
                if ($data['service'] && $data['state']) {
                    $service_name = $data['service']['name'];
                    $state_name = $data['state']['name'];
                    $data['title'] = sprintf($data['service']['title_format'], $state_name);
                    $data['description'] = "{$service_name} across {$state_name}. " . $data['service']['description'] . " Find and book top-rated coaches.";
                    $data['h1'] = sprintf($data['service']['h1_format'], $state_name);
                    $data['canonical'] = $site_url . "/{$service_slug}/{$state_slug}/";
                    $data['breadcrumbs'] = array(
                        array('name' => 'Home', 'url' => $site_url),
                        array('name' => $service_name, 'url' => $site_url . "/{$service_slug}/"),
                        array('name' => $state_name, 'url' => ''),
                    );
                    $data['all_cities'] = $data['state']['major_cities'];
                }
                break;
                
            case 'service_city':
                if ($data['service'] && $data['city']) {
                    $service_name = $data['service']['name'];
                    $city_name = $data['city']['name'];
                    $state_abbr = $data['city']['state_abbr'];
                    $state_name = $data['city']['state_name'];
                    
                    $data['title'] = sprintf($data['service']['title_format'], "{$city_name}, {$state_abbr}");
                    $data['description'] = "{$service_name} in {$city_name}, {$state_name}. " . $data['service']['description'] . " Book with top-rated local coaches.";
                    $data['h1'] = sprintf($data['service']['h1_format'], $city_name);
                    $data['canonical'] = $site_url . "/{$service_slug}/{$state_slug}/{$city_slug}/";
                    $data['breadcrumbs'] = array(
                        array('name' => 'Home', 'url' => $site_url),
                        array('name' => $service_name, 'url' => $site_url . "/{$service_slug}/"),
                        array('name' => $state_name, 'url' => $site_url . "/{$service_slug}/{$state_slug}/"),
                        array('name' => $city_name, 'url' => ''),
                    );
                    $data['nearby_cities'] = self::get_nearby_cities($city_slug, $state_slug);
                }
                break;
                
            case 'near_me':
                $data['title'] = 'Soccer Training Near Me | Find Local Trainers & Camps | PTP';
                $data['description'] = 'Find soccer training near you. Private lessons, camps, clinics & group training with elite coaches. Serving PA, NJ, DE, MD, NY. Book online today.';
                $data['h1'] = 'Soccer Training Near Me';
                $data['canonical'] = $site_url . '/soccer-training-near-me/';
                $data['breadcrumbs'] = array(
                    array('name' => 'Home', 'url' => $site_url),
                    array('name' => 'Soccer Training Near Me', 'url' => ''),
                );
                break;
                
            case 'service_near_me':
                if ($data['service']) {
                    $service_name = $data['service']['name'];
                    $data['title'] = "{$service_name} Near Me | Find Local Coaches | PTP";
                    $data['description'] = "Find {$service_name} near you. " . $data['service']['description'] . ' Book with local coaches today.';
                    $data['h1'] = "{$service_name} Near Me";
                    $data['canonical'] = $site_url . "/{$service_slug}-near-me/";
                    $data['breadcrumbs'] = array(
                        array('name' => 'Home', 'url' => $site_url),
                        array('name' => $service_name, 'url' => $site_url . "/{$service_slug}/"),
                        array('name' => 'Near Me', 'url' => ''),
                    );
                }
                break;
                
            case 'find_trainers':
                $data['title'] = 'Find Soccer Trainers | Browse Elite Coaches | PTP';
                $data['description'] = 'Find and book elite soccer trainers. Browse profiles of NCAA Division 1 and elite college coaches. Private lessons, camps, and clinics available.';
                $data['h1'] = 'Find Soccer Trainers';
                $data['canonical'] = $site_url . '/find-soccer-trainers/';
                $data['breadcrumbs'] = array(
                    array('name' => 'Home', 'url' => $site_url),
                    array('name' => 'Find Soccer Trainers', 'url' => ''),
                );
                break;
                
            case 'find_trainers_city':
            case 'best_trainers':
                if ($data['city']) {
                    $city_name = $data['city']['name'];
                    $state_abbr = $data['city']['state_abbr'];
                    $prefix = $page_type === 'best_trainers' ? 'Best' : 'Find';
                    
                    $data['title'] = "{$prefix} Soccer Trainers in {$city_name}, {$state_abbr} | PTP";
                    $data['description'] = "{$prefix} top-rated soccer trainers in {$city_name}. Browse profiles, read reviews, and book elite NCAA D1 coaches.";
                    $data['h1'] = "{$prefix} Soccer Trainers in {$city_name}";
                    $data['canonical'] = $site_url . "/" . ($page_type === 'best_trainers' ? 'best' : 'find') . "-soccer-trainers/{$city_slug}/";
                    $data['breadcrumbs'] = array(
                        array('name' => 'Home', 'url' => $site_url),
                        array('name' => "{$prefix} Soccer Trainers", 'url' => $site_url . '/find-soccer-trainers/'),
                        array('name' => $city_name, 'url' => ''),
                    );
                    $data['nearby_cities'] = self::get_nearby_cities($city_slug, $data['city']['state_slug']);
                }
                break;
        }
        
        // Generate FAQs
        $data['faqs'] = self::generate_faqs($data);
        
        // Get trainers
        $data['trainers'] = self::get_trainers_for_location($data);
        
        // Get camps
        $data['camps'] = self::get_camps_for_location($data);
        
        return $data;
    }
    
    /**
     * Generate FAQs based on page context
     */
    public static function generate_faqs($data) {
        $faqs = array();
        $location = '';
        $service = '';
        
        if (!empty($data['city'])) {
            $location = $data['city']['name'] . ', ' . $data['city']['state_abbr'];
        } elseif (!empty($data['state'])) {
            $location = $data['state']['name'];
        }
        
        if (!empty($data['service'])) {
            $service = $data['service']['name'];
        }
        
        // Location-based FAQs
        if ($location) {
            $faqs[] = array(
                'question' => "How much does soccer training cost in {$location}?",
                'answer' => "Soccer training in {$location} typically ranges from $65-$150 per hour for private sessions, depending on the trainer's experience and credentials. Group training is more affordable at $40-$75 per player. Our camps range from $375-$525 for a full week of training.",
            );
            
            $faqs[] = array(
                'question' => "What ages do you train in {$location}?",
                'answer' => "We train players of all ages in {$location}, from beginners as young as 5 years old through high school and adult players. Our trainers specialize in age-appropriate instruction that meets each player where they are.",
            );
            
            $faqs[] = array(
                'question' => "Where do training sessions take place in {$location}?",
                'answer' => "Training sessions in {$location} can take place at local parks, school fields, indoor facilities, or even your backyard. Our trainers are flexible and will work with you to find a convenient location.",
            );
        }
        
        // Service-based FAQs
        if ($service) {
            if (strpos($service, 'Private') !== false) {
                $faqs[] = array(
                    'question' => 'What happens during a private soccer training session?',
                    'answer' => 'During a private session, your trainer will assess your current abilities and create a customized workout focused on your specific goals. Sessions typically include warm-up, technical drills, skill challenges, and competitive games. Trainers provide real-time feedback and tips you can practice between sessions.',
                );
            }
            
            if (strpos($service, 'Camp') !== false) {
                $faqs[] = array(
                    'question' => 'What makes PTP camps different from other soccer camps?',
                    'answer' => 'At PTP camps, our coaches don\'t just instruct - they actually PLAY with your kids. This unique approach combines mentorship with training. We use 3v3 and 4v4 formats for maximum touches, run skill competitions throughout the day, and provide each player with a jersey, player card, and skills passport.',
                );
                
                $faqs[] = array(
                    'question' => 'What should my child bring to soccer camp?',
                    'answer' => 'Players should bring cleats, shin guards, a water bottle, sunscreen, and snacks. We recommend packing extra socks and a change of clothes. Jerseys and player materials are provided by PTP.',
                );
            }
            
            if (strpos($service, 'Goalkeeper') !== false) {
                $faqs[] = array(
                    'question' => 'What does goalkeeper training include?',
                    'answer' => 'Our goalkeeper training covers all aspects of the position: shot stopping and reaction saves, diving technique, positioning and angles, distribution (throwing and kicking), crosses and high balls, 1v1 situations, and mental preparation. We work on both the technical and tactical elements.',
                );
            }
        }
        
        // General FAQs
        $faqs[] = array(
            'question' => 'Who are PTP trainers?',
            'answer' => 'PTP trainers are current and former professional soccer players, including NCAA Division 1 athletes and elite college players. All trainers are background-checked and trained in our coaching methodology that emphasizes mentorship alongside skill development.',
        );
        
        $faqs[] = array(
            'question' => 'How do I book a training session?',
            'answer' => 'Booking is easy! Browse trainer profiles, select one that matches your needs, check their availability, and book online. You can pay securely through our platform, and you\'ll receive a confirmation with all the details.',
        );
        
        $faqs[] = array(
            'question' => 'What is your cancellation policy?',
            'answer' => 'Sessions can be cancelled or rescheduled up to 24 hours before the session time for a full refund or credit. Cancellations within 24 hours may be subject to a cancellation fee.',
        );
        
        return $faqs;
    }
    
    /**
     * Get trainers for a location
     */
    public static function get_trainers_for_location($data) {
        // Try to use the PTP_Trainer class if available
        if (!class_exists('PTP_Trainer')) {
            return array();
        }
        
        $args = array(
            'status' => 'active',
            'limit' => 12,
        );
        
        // Filter by city
        if (!empty($data['city'])) {
            $args['location_search'] = $data['city']['name'];
        }
        // Filter by state
        elseif (!empty($data['state'])) {
            $args['state'] = $data['state']['abbr'];
        }
        
        try {
            return PTP_Trainer::get_all($args);
        } catch (Exception $e) {
            return array();
        }
    }
    
    /**
     * Get camps for a location
     */
    public static function get_camps_for_location($data) {
        // v223.1: Removed dead if(!false) guard
        
        $args = array(
            'limit' => 6,
            'status' => 'publish',
            'category' => array('camps', 'clinics', 'events'),
            'meta_query' => array(
                array(
                    'key' => '_camp_start_date',
                    'value' => date('Y-m-d'),
                    'compare' => '>=',
                    'type' => 'DATE',
                ),
            ),
            'orderby' => 'meta_value',
            'meta_key' => '_camp_start_date',
            'order' => 'ASC',
        );
        
        // Filter by state
        if (!empty($data['state'])) {
            $args['meta_query'][] = array(
                'key' => '_camp_state',
                'value' => $data['state']['abbr'],
                'compare' => '=',
            );
        }
        
        try {
            return ptp_get_camp_products($args);
        } catch (Exception $e) {
            return array();
        }
    }
    
    /**
     * Output meta tags
     */
    public static function output_location_meta() {
        $seo_page = get_query_var('ptp_seo_page');
        if (!$seo_page) return;
        
        $data = self::get_page_data();
        
        // Basic meta
        echo '<meta name="description" content="' . esc_attr($data['description']) . '">' . "\n";
        
        // Keywords
        $keywords = array('soccer training', 'soccer coach', 'soccer lessons');
        if (!empty($data['service'])) {
            $keywords = array_merge($keywords, $data['service']['keywords']);
        }
        if (!empty($data['city'])) {
            $keywords[] = 'soccer training ' . $data['city']['name'];
            $keywords[] = $data['city']['name'] . ' soccer';
        }
        if (!empty($data['state'])) {
            $keywords[] = 'soccer ' . $data['state']['name'];
        }
        echo '<meta name="keywords" content="' . esc_attr(implode(', ', array_unique($keywords))) . '">' . "\n";
        
        // Geo meta
        if (!empty($data['city'])) {
            echo '<meta name="geo.region" content="US-' . esc_attr($data['city']['state_abbr']) . '">' . "\n";
            echo '<meta name="geo.placename" content="' . esc_attr($data['city']['name']) . '">' . "\n";
            echo '<meta name="geo.position" content="' . esc_attr($data['city']['lat']) . ';' . esc_attr($data['city']['lng']) . '">' . "\n";
            echo '<meta name="ICBM" content="' . esc_attr($data['city']['lat']) . ', ' . esc_attr($data['city']['lng']) . '">' . "\n";
        }
        
        // Open Graph
        echo '<meta property="og:type" content="website">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($data['title']) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($data['description']) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_attr($data['canonical']) . '">' . "\n";
        echo '<meta property="og:image" content="' . esc_url(home_url('/wp-content/uploads/ptp-og-image.jpg')) . '">' . "\n";
        echo '<meta property="og:locale" content="en_US">' . "\n";
        echo '<meta property="og:site_name" content="PTP Soccer">' . "\n";
        
        // Twitter Card
        echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr($data['title']) . '">' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr($data['description']) . '">' . "\n";
        echo '<meta name="twitter:image" content="' . esc_url(home_url('/wp-content/uploads/ptp-og-image.jpg')) . '">' . "\n";
    }
    
    /**
     * Output canonical URL
     */
    public static function output_canonical() {
        $seo_page = get_query_var('ptp_seo_page');
        if (!$seo_page) return;
        
        $data = self::get_page_data();
        echo '<link rel="canonical" href="' . esc_url($data['canonical']) . '">' . "\n";
    }
    
    /**
     * Filter WordPress canonical
     */
    public static function filter_canonical($url, $post) {
        $seo_page = get_query_var('ptp_seo_page');
        if ($seo_page) {
            $data = self::get_page_data();
            return $data['canonical'];
        }
        return $url;
    }
    
    /**
     * Output schema markup
     */
    public static function output_location_schema() {
        $seo_page = get_query_var('ptp_seo_page');
        if (!$seo_page) return;
        
        $data = self::get_page_data();
        $schemas = array();
        
        // Organization schema
        $schemas[] = array(
            '@type' => 'SportsOrganization',
            '@id' => home_url('#organization'),
            'name' => 'PTP Soccer',
            'alternateName' => 'Players Teaching Players',
            'url' => home_url(),
            'logo' => home_url('/wp-content/uploads/ptp-logo.png'),
            'image' => home_url('/wp-content/uploads/ptp-og-image.jpg'),
            'description' => 'Elite soccer training with NCAA Division 1 and elite college coaches.',
            'telephone' => '+1-555-PTP-SOCCER',
            'email' => 'info@ptpsoccer.com',
            'foundingDate' => '2021',
            'founder' => array(
                '@type' => 'Person',
                'name' => 'Luke Martelli',
            ),
            'areaServed' => array(
                array('@type' => 'State', 'name' => 'Pennsylvania'),
                array('@type' => 'State', 'name' => 'New Jersey'),
                array('@type' => 'State', 'name' => 'Delaware'),
                array('@type' => 'State', 'name' => 'Maryland'),
                array('@type' => 'State', 'name' => 'New York'),
            ),
            'sameAs' => array(
                'https://www.instagram.com/ptpsoccer',
                'https://www.facebook.com/ptpsoccer',
                'https://www.tiktok.com/@ptpsoccer',
            ),
        );
        
        // LocalBusiness schema for city pages
        if (!empty($data['city'])) {
            $schemas[] = array(
                '@type' => 'SportsActivityLocation',
                '@id' => $data['canonical'] . '#localbusiness',
                'name' => 'PTP Soccer - ' . $data['city']['name'],
                'description' => $data['description'],
                'url' => $data['canonical'],
                'telephone' => '+1-555-PTP-SOCCER',
                'address' => array(
                    '@type' => 'PostalAddress',
                    'addressLocality' => $data['city']['name'],
                    'addressRegion' => $data['city']['state_abbr'],
                    'addressCountry' => 'US',
                ),
                'geo' => array(
                    '@type' => 'GeoCoordinates',
                    'latitude' => $data['city']['lat'],
                    'longitude' => $data['city']['lng'],
                ),
                'aggregateRating' => array(
                    '@type' => 'AggregateRating',
                    'ratingValue' => '4.9',
                    'reviewCount' => '150',
                    'bestRating' => '5',
                    'worstRating' => '1',
                ),
                'priceRange' => '$65-$175',
                'openingHoursSpecification' => array(
                    array(
                        '@type' => 'OpeningHoursSpecification',
                        'dayOfWeek' => array('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'),
                        'opens' => '06:00',
                        'closes' => '21:00',
                    ),
                    array(
                        '@type' => 'OpeningHoursSpecification',
                        'dayOfWeek' => array('Saturday', 'Sunday'),
                        'opens' => '07:00',
                        'closes' => '20:00',
                    ),
                ),
            );
        }
        
        // Service schema
        if (!empty($data['service'])) {
            $schemas[] = array(
                '@type' => 'Service',
                '@id' => $data['canonical'] . '#service',
                'name' => $data['service']['name'],
                'description' => $data['service']['description'],
                'provider' => array(
                    '@type' => 'SportsOrganization',
                    '@id' => home_url('#organization'),
                ),
                'serviceType' => 'Soccer Training',
                'areaServed' => !empty($data['city']) ? array(
                    '@type' => 'City',
                    'name' => $data['city']['name'],
                ) : array(
                    '@type' => 'Country',
                    'name' => 'United States',
                ),
                'offers' => array(
                    '@type' => 'Offer',
                    'priceRange' => $data['service']['price_range'] ?? '$65-$175',
                    'priceCurrency' => 'USD',
                ),
            );
        }
        
        // FAQ schema
        if (!empty($data['faqs'])) {
            $faq_schema = array(
                '@type' => 'FAQPage',
                '@id' => $data['canonical'] . '#faq',
                'mainEntity' => array(),
            );
            
            foreach ($data['faqs'] as $faq) {
                $faq_schema['mainEntity'][] = array(
                    '@type' => 'Question',
                    'name' => $faq['question'],
                    'acceptedAnswer' => array(
                        '@type' => 'Answer',
                        'text' => $faq['answer'],
                    ),
                );
            }
            
            $schemas[] = $faq_schema;
        }
        
        // BreadcrumbList schema
        if (!empty($data['breadcrumbs'])) {
            $breadcrumb_schema = array(
                '@type' => 'BreadcrumbList',
                '@id' => $data['canonical'] . '#breadcrumb',
                'itemListElement' => array(),
            );
            
            foreach ($data['breadcrumbs'] as $i => $crumb) {
                $breadcrumb_schema['itemListElement'][] = array(
                    '@type' => 'ListItem',
                    'position' => $i + 1,
                    'name' => $crumb['name'],
                    'item' => $crumb['url'] ?: $data['canonical'],
                );
            }
            
            $schemas[] = $breadcrumb_schema;
        }
        
        // Output combined schema
        $output = array(
            '@context' => 'https://schema.org',
            '@graph' => $schemas,
        );
        
        echo '<script type="application/ld+json">' . "\n";
        echo wp_json_encode($output, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        echo "\n</script>\n";
    }
    
    /**
     * Breadcrumbs shortcode
     */
    public static function breadcrumbs_shortcode($atts) {
        $data = self::get_page_data();
        
        if (empty($data['breadcrumbs'])) return '';
        
        $output = '<nav class="ptp-breadcrumbs" aria-label="Breadcrumb">';
        $output .= '<ol itemscope itemtype="https://schema.org/BreadcrumbList">';
        
        foreach ($data['breadcrumbs'] as $i => $crumb) {
            $position = $i + 1;
            $is_last = ($i === count($data['breadcrumbs']) - 1);
            
            $output .= '<li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">';
            
            if ($crumb['url'] && !$is_last) {
                $output .= '<a itemprop="item" href="' . esc_url($crumb['url']) . '">';
                $output .= '<span itemprop="name">' . esc_html($crumb['name']) . '</span>';
                $output .= '</a>';
            } else {
                $output .= '<span itemprop="name">' . esc_html($crumb['name']) . '</span>';
            }
            
            $output .= '<meta itemprop="position" content="' . $position . '">';
            $output .= '</li>';
            
            if (!$is_last) {
                $output .= '<li class="separator" aria-hidden="true">/</li>';
            }
        }
        
        $output .= '</ol>';
        $output .= '</nav>';
        
        return $output;
    }
    
    /**
     * Enhance robots.txt
     */
    public static function enhance_robots_txt($output, $public) {
        $sitemap_url = home_url('/ptp-sitemap.xml');
        
        if (strpos($output, $sitemap_url) === false) {
            $output .= "\n# PTP Soccer Sitemap\n";
            $output .= "Sitemap: {$sitemap_url}\n";
        }
        
        return $output;
    }
    
    /**
     * Add admin menu
     */
    public static function add_admin_menu() {
        add_submenu_page(
            'ptp-training',
            'SEO Pages V85',
            'SEO Pages V85',
            'manage_options',
            'ptp-seo-pages-v85',
            array(__CLASS__, 'render_admin_page')
        );
    }
    
    /**
     * Render admin page
     */
    public static function render_admin_page() {
        $city_count = self::count_cities();
        $state_count = count(self::$states);
        $service_count = count(self::$services);
        
        // Calculate total pages
        $total_pages = 1; // root
        $total_pages += $state_count; // state pages
        $total_pages += $city_count; // city pages
        $total_pages += $service_count; // service pages
        $total_pages += $state_count * $service_count; // service + state
        $total_pages += $city_count * $service_count; // service + city (approx, metro only)
        $total_pages += 2; // near me pages
        $total_pages += $service_count; // service near me
        $total_pages += $city_count; // find trainers city
        
        ?>
        <div class="wrap">
            <h1>PTP SEO Location Pages V85</h1>
            
            <div class="ptp-seo-stats" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin: 20px 0;">
                <div style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 8px;">
                    <h3 style="margin: 0 0 10px; color: #666;">States</h3>
                    <p style="font-size: 36px; font-weight: bold; margin: 0; color: #FCB900;"><?php echo $state_count; ?></p>
                </div>
                <div style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 8px;">
                    <h3 style="margin: 0 0 10px; color: #666;">Cities</h3>
                    <p style="font-size: 36px; font-weight: bold; margin: 0; color: #FCB900;"><?php echo $city_count; ?></p>
                </div>
                <div style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 8px;">
                    <h3 style="margin: 0 0 10px; color: #666;">Services</h3>
                    <p style="font-size: 36px; font-weight: bold; margin: 0; color: #FCB900;"><?php echo $service_count; ?></p>
                </div>
                <div style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 8px;">
                    <h3 style="margin: 0 0 10px; color: #666;">Total SEO Pages</h3>
                    <p style="font-size: 36px; font-weight: bold; margin: 0; color: #22C55E;"><?php echo number_format($total_pages); ?>+</p>
                </div>
            </div>
            
            <div style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 20px;">
                <h2>Quick Actions</h2>
                <p>
                    <a href="<?php echo admin_url('options-permalink.php'); ?>" class="button button-primary">Flush Rewrite Rules</a>
                    <a href="<?php echo home_url('/ptp-sitemap.xml'); ?>" target="_blank" class="button">View Sitemap</a>
                    <a href="<?php echo home_url('/soccer-training/'); ?>" target="_blank" class="button">View Root Page</a>
                </p>
            </div>
            
            <div style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 20px;">
                <h2>Sample URLs</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>URL Type</th>
                            <th>Example URL</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Root Page</td>
                            <td><a href="<?php echo home_url('/soccer-training/'); ?>" target="_blank">/soccer-training/</a></td>
                        </tr>
                        <tr>
                            <td>State Page</td>
                            <td><a href="<?php echo home_url('/soccer-training/pennsylvania/'); ?>" target="_blank">/soccer-training/pennsylvania/</a></td>
                        </tr>
                        <tr>
                            <td>City Page</td>
                            <td><a href="<?php echo home_url('/soccer-training/pennsylvania/philadelphia/'); ?>" target="_blank">/soccer-training/pennsylvania/philadelphia/</a></td>
                        </tr>
                        <tr>
                            <td>Service Page</td>
                            <td><a href="<?php echo home_url('/private-soccer-training/'); ?>" target="_blank">/private-soccer-training/</a></td>
                        </tr>
                        <tr>
                            <td>Service + State</td>
                            <td><a href="<?php echo home_url('/private-soccer-training/pennsylvania/'); ?>" target="_blank">/private-soccer-training/pennsylvania/</a></td>
                        </tr>
                        <tr>
                            <td>Service + City</td>
                            <td><a href="<?php echo home_url('/private-soccer-training/pennsylvania/philadelphia/'); ?>" target="_blank">/private-soccer-training/pennsylvania/philadelphia/</a></td>
                        </tr>
                        <tr>
                            <td>Near Me</td>
                            <td><a href="<?php echo home_url('/soccer-training-near-me/'); ?>" target="_blank">/soccer-training-near-me/</a></td>
                        </tr>
                        <tr>
                            <td>Find Trainers</td>
                            <td><a href="<?php echo home_url('/find-soccer-trainers/philadelphia/'); ?>" target="_blank">/find-soccer-trainers/philadelphia/</a></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <div style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 8px;">
                <h2>Cities by State</h2>
                <?php foreach (self::$states as $slug => $state): ?>
                    <h3><?php echo esc_html($state['name']); ?> (<?php echo count($state['major_cities']); ?> cities)</h3>
                    <p style="margin-bottom: 20px;">
                        <?php 
                        $city_links = array();
                        foreach ($state['major_cities'] as $city_slug => $city) {
                            $url = home_url("/soccer-training/{$slug}/{$city_slug}/");
                            $city_links[] = '<a href="' . esc_url($url) . '" target="_blank">' . esc_html($city['name']) . '</a>';
                        }
                        echo implode(' &bull; ', $city_links);
                        ?>
                    </p>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX flush rewrites
     */
    public static function ajax_flush_rewrites() {
        check_ajax_referer('ptp_seo_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        flush_rewrite_rules();
        
        wp_send_json_success(array('message' => 'Rewrite rules flushed'));
    }
    
    /**
     * AJAX get stats
     */
    public static function ajax_get_stats() {
        check_ajax_referer('ptp_seo_nonce', 'nonce');
        
        wp_send_json_success(array(
            'states' => count(self::$states),
            'cities' => self::count_cities(),
            'services' => count(self::$services),
        ));
    }
    
    /**
     * Get all URLs for sitemap
     */
    public static function get_all_urls() {
        $urls = array();
        $site_url = home_url();
        
        // Root
        $urls[] = array(
            'loc' => $site_url . '/soccer-training/',
            'priority' => '1.0',
            'changefreq' => 'daily',
        );
        
        // Near me
        $urls[] = array(
            'loc' => $site_url . '/soccer-training-near-me/',
            'priority' => '0.9',
            'changefreq' => 'weekly',
        );
        
        // Find trainers
        $urls[] = array(
            'loc' => $site_url . '/find-soccer-trainers/',
            'priority' => '0.9',
            'changefreq' => 'weekly',
        );
        
        // Services
        foreach (self::$services as $slug => $service) {
            $urls[] = array(
                'loc' => $site_url . '/' . $slug . '/',
                'priority' => '1.0',
                'changefreq' => 'weekly',
            );
            
            // Service near me
            $urls[] = array(
                'loc' => $site_url . '/' . $slug . '-near-me/',
                'priority' => '0.8',
                'changefreq' => 'weekly',
            );
        }
        
        // States
        foreach (self::$states as $state_slug => $state) {
            $urls[] = array(
                'loc' => $site_url . '/soccer-training/' . $state_slug . '/',
                'priority' => '0.9',
                'changefreq' => 'weekly',
            );
            
            // Service + State
            foreach (self::$services as $service_slug => $service) {
                $urls[] = array(
                    'loc' => $site_url . '/' . $service_slug . '/' . $state_slug . '/',
                    'priority' => '0.8',
                    'changefreq' => 'weekly',
                );
            }
            
            // Cities
            foreach ($state['major_cities'] as $city_slug => $city) {
                $is_metro = !empty($city['metro']);
                
                $urls[] = array(
                    'loc' => $site_url . '/soccer-training/' . $state_slug . '/' . $city_slug . '/',
                    'priority' => $is_metro ? '0.9' : '0.7',
                    'changefreq' => 'weekly',
                );
                
                // Find trainers city
                $urls[] = array(
                    'loc' => $site_url . '/find-soccer-trainers/' . $city_slug . '/',
                    'priority' => '0.7',
                    'changefreq' => 'weekly',
                );
                
                // Service + City (metro only)
                if ($is_metro) {
                    foreach (self::$services as $service_slug => $service) {
                        $urls[] = array(
                            'loc' => $site_url . '/' . $service_slug . '/' . $state_slug . '/' . $city_slug . '/',
                            'priority' => '0.9',
                            'changefreq' => 'weekly',
                        );
                    }
                }
            }
        }
        
        return $urls;
    }
}

// Initialize
add_action('plugins_loaded', array('PTP_SEO_Locations_V85', 'init'));
