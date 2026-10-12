<?php
/**
 * RDR-IT Login Security — Geo IP
 * Lit les fichiers MaxMind GeoLite2 .mmdb en pur PHP.
 * Pas de dépendance Composer — lecteur mmdb minimal intégré.
 *
 * Installation :
 *   1. Télécharger GeoLite2-Country.mmdb et GeoLite2-City.mmdb depuis MaxMind
 *   2. Les placer dans wp-content/rdrit-geoip/
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'RDRIT_GEOIP_DIR', WP_CONTENT_DIR . '/rdrit-geoip/' );

/**
 * Retourne les infos geo d'une IP.
 * Utilise l'API gratuite ip-api.com en fallback si pas de fichier local.
 *
 * @return array{country_code:string, country_name:string, city:string}
 */
function rdrit_geo_lookup( string $ip ): array {
    $default = array( 'country_code' => '', 'country_name' => '', 'city' => '' );

    // Ignorer les IPs locales
    if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
        return $default;
    }

    // Cache transient (1h)
    $key    = 'rdrit_geo_' . md5( $ip );
    $cached = get_transient( $key );
    if ( $cached !== false ) return $cached;

    // Essai avec fichier local mmdb
    $mmdb = RDRIT_GEOIP_DIR . 'GeoLite2-City.mmdb';
    if ( file_exists( $mmdb ) ) {
        $result = rdrit_mmdb_lookup( $mmdb, $ip );
        if ( $result ) {
            set_transient( $key, $result, HOUR_IN_SECONDS );
            return $result;
        }
    }

    // Fallback : ip-api.com (gratuit, 45 req/min, pas de clé nécessaire)
    $response = wp_remote_get(
        'http://ip-api.com/json/' . urlencode( $ip ) . '?fields=countryCode,country,city',
        array( 'timeout' => 3, 'sslverify' => false )
    );

    if ( ! is_wp_error( $response ) ) {
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $body['countryCode'] ) ) {
            $result = array(
                'country_code' => sanitize_text_field( $body['countryCode'] ?? '' ),
                'country_name' => sanitize_text_field( $body['country']     ?? '' ),
                'city'         => sanitize_text_field( $body['city']        ?? '' ),
            );
            set_transient( $key, $result, HOUR_IN_SECONDS );
            return $result;
        }
    }

    set_transient( $key, $default, 5 * MINUTE_IN_SECONDS );
    return $default;
}

/**
 * Lecture minimale d'un fichier .mmdb (MaxMind DB).
 * Supporte uniquement la recherche IP → pays/ville.
 * Format binaire documenté : https://maxmind.github.io/MaxMind-DB/
 */
function rdrit_mmdb_lookup( string $file, string $ip ): ?array {
    try {
        $fh = fopen( $file, 'rb' );
        if ( ! $fh ) return null;

        // Lire les metadata depuis la fin du fichier
        $meta = rdrit_mmdb_read_metadata( $fh );
        if ( ! $meta ) { fclose($fh); return null; }

        // Rechercher l'IP dans l'arbre binaire
        $record = rdrit_mmdb_search( $fh, $meta, $ip );
        fclose( $fh );

        if ( ! $record || ! is_array( $record ) ) return null;

        return array(
            'country_code' => $record['country']['iso_code']        ?? $record['registered_country']['iso_code'] ?? '',
            'country_name' => $record['country']['names']['fr']      ?? $record['country']['names']['en']         ?? '',
            'city'         => $record['city']['names']['fr']         ?? $record['city']['names']['en']            ?? '',
        );
    } catch ( Throwable $e ) {
        return null;
    }
}

function rdrit_mmdb_read_metadata( $fh ): ?array {
    // Chercher le marqueur de metadata depuis la fin
    $marker = "\xab\xcd\xefMaxMind.com";
    fseek( $fh, 0, SEEK_END );
    $size = ftell( $fh );
    $chunk_size = min( 4096, $size );
    fseek( $fh, -$chunk_size, SEEK_END );
    $tail = fread( $fh, $chunk_size );
    $pos  = strrpos( $tail, $marker );
    if ( $pos === false ) return null;

    $meta_offset = $size - $chunk_size + $pos + strlen( $marker );
    fseek( $fh, $meta_offset );
    $meta = rdrit_mmdb_decode_value( $fh );
    return is_array( $meta ) ? $meta : null;
}

function rdrit_mmdb_search( $fh, array $meta, string $ip ): ?array {
    $node_count    = $meta['node_count'];
    $record_size   = $meta['record_size'];
    $ip_version    = $meta['ip_version'];
    $node_byte_size = (int) ( $record_size * 2 / 8 );

    // Convertir l'IP en bits
    if ( $ip_version === 6 ) {
        $packed = inet_pton( $ip );
        if ( $packed === false && strpos( $ip, ':' ) === false ) {
            // IPv4 mappée en IPv6
            $packed = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" . inet_pton( $ip );
        }
    } else {
        $packed = inet_pton( $ip );
        if ( $packed === false ) return null;
    }
    if ( $packed === false ) return null;

    $bits   = '';
    $length = $ip_version === 6 ? 128 : 32;
    for ( $i = 0; $i < strlen( $packed ); $i++ ) {
        $bits .= str_pad( decbin( ord( $packed[$i] ) ), 8, '0', STR_PAD_LEFT );
    }

    $node = 0;
    for ( $i = 0; $i < $length; $i++ ) {
        $bit = (int) $bits[$i];
        fseek( $fh, $node * $node_byte_size );
        $bytes = fread( $fh, $node_byte_size );

        if ( $record_size === 24 ) {
            if ( $bit === 0 ) {
                $node = ( ord($bytes[0]) << 16 ) | ( ord($bytes[1]) << 8 ) | ord($bytes[2]);
            } else {
                $node = ( ord($bytes[3]) << 16 ) | ( ord($bytes[4]) << 8 ) | ord($bytes[5]);
            }
        } elseif ( $record_size === 28 ) {
            if ( $bit === 0 ) {
                $node = ( ( ord($bytes[3]) & 0xf0 ) << 20 ) | ( ord($bytes[0]) << 16 ) | ( ord($bytes[1]) << 8 ) | ord($bytes[2]);
            } else {
                $node = ( ( ord($bytes[3]) & 0x0f ) << 24 ) | ( ord($bytes[4]) << 16 ) | ( ord($bytes[5]) << 8 ) | ord($bytes[6]);
            }
        } elseif ( $record_size === 32 ) {
            if ( $bit === 0 ) {
                $node = unpack( 'N', substr($bytes, 0, 4) )[1];
            } else {
                $node = unpack( 'N', substr($bytes, 4, 4) )[1];
            }
        }

        if ( $node >= $node_count ) {
            // On a atteint un enregistrement de données
            $data_section_start = $node_count * $node_byte_size + 16;
            $record_offset = $data_section_start + ( $node - $node_count - 16 );
            fseek( $fh, $record_offset );
            return rdrit_mmdb_decode_value( $fh );
        }
    }
    return null;
}

function rdrit_mmdb_decode_value( $fh ) {
    $ctrl_byte = ord( fread($fh, 1) );
    $type      = $ctrl_byte >> 5;

    if ( $type === 0 ) { // Extended type
        $type = ord( fread($fh, 1) ) + 7;
    }

    $size = $ctrl_byte & 0x1f;
    if ( $size === 29 ) $size = 29 + ord( fread($fh, 1) );
    elseif ( $size === 30 ) { $d = unpack('n', fread($fh, 2))[1]; $size = 285 + $d; }
    elseif ( $size === 31 ) { $d = unpack('N', "\x00" . fread($fh, 3))[1]; $size = 65821 + $d; }

    switch ( $type ) {
        case 1: // pointer
            $ptr_size = ( $size >> 3 ) & 0x3;
            $val      = $size & 0x7;
            if ( $ptr_size === 0 ) $ptr = ( $val << 8 ) | ord(fread($fh,1));
            elseif ( $ptr_size === 1 ) { $b = fread($fh,2); $ptr = ( $val << 16 ) | unpack('n',$b)[1] + 2048; }
            elseif ( $ptr_size === 2 ) { $b = fread($fh,3); $ptr = ( $val << 24 ) | unpack('N',"\x00".$b)[1] + 526336; }
            else { $b = fread($fh,4); $ptr = unpack('N',$b)[1]; }
            $save = ftell($fh);
            // Chercher l'offset depuis le début de la section de données
            // (simplifié — on suppose qu'on lit depuis le début)
            fseek($fh, $ptr);
            $val = rdrit_mmdb_decode_value($fh);
            fseek($fh, $save);
            return $val;
        case 2: // utf8
            return fread($fh, $size);
        case 3: // double
            return unpack('d', strrev(fread($fh,8)))[1];
        case 4: // bytes
            return fread($fh, $size);
        case 5: // uint16
            return unpack('n', str_pad(fread($fh,$size), 2, "\x00", STR_PAD_LEFT))[1];
        case 6: // uint32
            return unpack('N', str_pad(fread($fh,$size), 4, "\x00", STR_PAD_LEFT))[1];
        case 7: // map
            $map = array();
            for ($i = 0; $i < $size; $i++) {
                $k = rdrit_mmdb_decode_value($fh);
                $v = rdrit_mmdb_decode_value($fh);
                $map[$k] = $v;
            }
            return $map;
        case 8: // int32
            $raw = fread($fh, $size);
            $val = unpack('N', str_pad($raw, 4, "\x00", STR_PAD_LEFT))[1];
            if ($val >= 0x80000000) $val -= 0x100000000;
            return $val;
        case 9: // uint64
            return hexdec(bin2hex(fread($fh,$size)));
        case 10: // uint128
            return bin2hex(fread($fh, $size));
        case 11: // array
            $arr = array();
            for ($i = 0; $i < $size; $i++) $arr[] = rdrit_mmdb_decode_value($fh);
            return $arr;
        case 14: // bool
            return (bool)$size;
        case 15: // float
            return unpack('f', strrev(fread($fh,4)))[1];
        default:
            return null;
    }
}
