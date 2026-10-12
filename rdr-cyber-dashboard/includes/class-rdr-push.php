<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * RDR_Push — Web Push Notifications via VAPID (empty-payload)
 *
 * Protocole : RFC 8030 + RFC 7519 (JWT) + RFC 8292 (VAPID)
 * Chiffrement du payload : désactivé (empty payload, le SW récupère les données via fetch)
 *
 * Prérequis : PHP OpenSSL + courbe P-256 (prime256v1)
 */
class RDR_Push {

    public static function init() : void {
        /* rien à enregistrer côté serveur : les AJAX sont dans le fichier principal */
    }

    /* ------------------------------------------------------------------ */
    /* Disponibilité                                                         */
    /* ------------------------------------------------------------------ */
    public static function is_available() : bool {
        if ( ! extension_loaded('openssl') ) return false;
        if ( ! get_option('rdr_cyber_vapid_public','') ) return false;
        if ( ! get_option('rdr_cyber_vapid_private','') ) return false;
        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Clé publique base64url (pour le JS)                                  */
    /* ------------------------------------------------------------------ */
    public static function get_public_key_base64() : string {
        return get_option('rdr_cyber_vapid_public','');
    }

    /* ------------------------------------------------------------------ */
    /* Génération des clés VAPID (EC P-256)                                 */
    /* ------------------------------------------------------------------ */
    public static function generate_vapid_keys() : ?array {
        if ( ! extension_loaded('openssl') ) return null;

        $key = openssl_pkey_new(array(
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ));
        if ( ! $key ) return null;

        $details = openssl_pkey_get_details($key);
        if ( ! $details || ! isset($details['ec']) ) return null;

        /* Coordonnées brutes de la clé publique non-compressée (04 || x || y) */
        $x = $details['ec']['x'];
        $y = $details['ec']['y'];
        /* Padding à 32 octets */
        $x = str_pad($x, 32, "\x00", STR_PAD_LEFT);
        $y = str_pad($y, 32, "\x00", STR_PAD_LEFT);
        $pub_raw = "\x04" . $x . $y; /* 65 octets */

        /* Clé privée brute */
        $d = $details['ec']['d'];
        $d = str_pad($d, 32, "\x00", STR_PAD_LEFT);

        return array(
            'public'  => self::base64url_encode($pub_raw),
            'private' => self::base64url_encode($d),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Envoi vers tous les abonnés (empty payload)                          */
    /* ------------------------------------------------------------------ */
    public static function send_all( array $data = array() ) : int {
        if ( ! self::is_available() ) return 0;
        $subs = RDR_Database::get_push_subscriptions();
        if ( ! $subs ) return 0;

        /* On stocke le payload en option temporaire (le SW ira le chercher) */
        if ($data) update_option('rdr_cyber_push_last_payload', $data, false);

        $sent = 0;
        $expired = array();
        foreach ($subs as $sub) {
            $result = self::send_one($sub['endpoint'], $sub['p256dh'], $sub['auth']);
            if ($result === 'ok')      $sent++;
            elseif ($result === 'gone') $expired[] = $sub['endpoint'];
        }
        /* Nettoyage des abonnements expirés (410 Gone) */
        foreach ($expired as $ep) RDR_Database::delete_push_subscription($ep);
        return $sent;
    }

    /* ------------------------------------------------------------------ */
    /* Envoi d'une notification vers un endpoint (empty payload)           */
    /* ------------------------------------------------------------------ */
    private static function send_one( string $endpoint, string $p256dh, string $auth ) : string {
        $vapid_public  = get_option('rdr_cyber_vapid_public','');
        $vapid_private = get_option('rdr_cyber_vapid_private','');
        if (!$vapid_public || !$vapid_private) return 'error';

        /* Audience = origine de l'endpoint */
        $parts   = wp_parse_url($endpoint);
        $audience = $parts['scheme'].'://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'');

        $jwt = self::build_vapid_jwt($audience, $vapid_public, $vapid_private);
        if (!$jwt) return 'error';

        $headers = array(
            'Authorization'  => 'vapid t='.$jwt.',k='.$vapid_public,
            'Content-Length' => '0',
            'Content-Type'   => 'application/octet-stream',
            'TTL'            => '86400',
            'Urgency'        => 'normal',
        );

        $response = wp_remote_post($endpoint, array(
            'headers'   => $headers,
            'body'      => '',
            'timeout'   => 10,
            'sslverify' => true,
        ));

        if (is_wp_error($response)) {
            error_log('[RDR Push] wp_error: '.$response->get_error_message());
            return 'error';
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code === 201 || $code === 200 || $code === 202) return 'ok';
        if ($code === 410 || $code === 404) return 'gone';
        error_log('[RDR Push] HTTP '.$code.' for '.substr($endpoint,0,60));
        return 'error';
    }

    /* ------------------------------------------------------------------ */
    /* Construction du JWT VAPID (ES256)                                    */
    /* ------------------------------------------------------------------ */
    private static function build_vapid_jwt( string $audience, string $pub_b64, string $priv_b64 ) : ?string {
        $header  = self::base64url_encode(json_encode(array('typ'=>'JWT','alg'=>'ES256')));
        $payload = self::base64url_encode(json_encode(array(
            'aud' => $audience,
            'exp' => time() + 43200, /* 12h */
            'sub' => 'mailto:'.get_option('admin_email'),
        )));
        $signing_input = $header.'.'.$payload;

        /* Reconstruit la clé privée EC depuis les octets bruts */
        $priv_raw = self::base64url_decode($priv_b64);
        $pub_raw  = self::base64url_decode($pub_b64);
        if (strlen($priv_raw) !== 32 || strlen($pub_raw) !== 65) return null;

        /* Encode en DER PKCS#8 pour openssl_sign */
        $der = self::ec_private_key_to_der($priv_raw, $pub_raw);
        if (!$der) return null;

        $pkey = openssl_pkey_get_private($der);
        if (!$pkey) return null;

        $sig_der = '';
        if (!openssl_sign($signing_input, $sig_der, $pkey, OPENSSL_ALGO_SHA256)) return null;

        /* Convertit la signature DER ASN.1 en format brut IEEE P1363 (r || s, 64 octets) */
        $sig_raw = self::der_to_raw_ec_sig($sig_der);
        if (!$sig_raw) return null;

        return $signing_input.'.'.self::base64url_encode($sig_raw);
    }

    /* ------------------------------------------------------------------ */
    /* Encode une clé EC privée brute en PEM PKCS#8 DER                    */
    /* ------------------------------------------------------------------ */
    private static function ec_private_key_to_der( string $d, string $pub_uncompressed ) : ?string {
        /*
         * ECPrivateKey (RFC 5915) encapsulé dans PKCS#8 PrivateKeyInfo (RFC 5958)
         * OID prime256v1 = 1.2.840.10045.3.1.7
         * OID id-ecPublicKey = 1.2.840.10045.2.1
         */
        /* ECPrivateKey ::= SEQUENCE {
         *   version INTEGER (1),
         *   privateKey OCTET STRING,
         *   [0] OID OPTIONAL,
         *   [1] BIT STRING OPTIONAL }
         */
        $oid_ec_pubkey   = "\x2a\x86\x48\xce\x3d\x02\x01";           /* 1.2.840.10045.2.1  */
        $oid_prime256v1  = "\x2a\x86\x48\xce\x3d\x03\x01\x07";       /* 1.2.840.10045.3.1.7 */

        /* AlgorithmIdentifier */
        $alg_id = self::der_seq(
            self::der_oid($oid_ec_pubkey) .
            self::der_oid($oid_prime256v1)
        );

        /* ECPrivateKey */
        $ec_priv = self::der_seq(
            self::der_int_byte(1) .
            self::der_octet($d) .
            "\xa1" . self::der_len(strlen($pub_uncompressed)+3+1) .
                self::der_bitstring($pub_uncompressed)
        );

        /* PrivateKeyInfo */
        $pki = self::der_seq(
            self::der_int_byte(0) .
            $alg_id .
            self::der_octet($ec_priv)
        );

        $pem = "-----BEGIN PRIVATE KEY-----\n".
               chunk_split(base64_encode($pki),64,"\n").
               "-----END PRIVATE KEY-----\n";

        return $pem;
    }

    /* Petits helpers DER ------------------------------------------------ */
    private static function der_len( int $len ) : string {
        if ($len < 128) return chr($len);
        if ($len < 256) return "\x81".chr($len);
        return "\x82".chr(($len>>8)&0xff).chr($len&0xff);
    }
    private static function der_seq( string $data ) : string {
        return "\x30".self::der_len(strlen($data)).$data;
    }
    private static function der_oid( string $oid_bytes ) : string {
        return "\x06".self::der_len(strlen($oid_bytes)).$oid_bytes;
    }
    private static function der_octet( string $data ) : string {
        return "\x04".self::der_len(strlen($data)).$data;
    }
    private static function der_int_byte( int $v ) : string {
        return "\x02\x01".chr($v);
    }
    private static function der_bitstring( string $data ) : string {
        return "\x03".self::der_len(strlen($data)+1)."\x00".$data;
    }

    /* ------------------------------------------------------------------ */
    /* Convertit signature DER ASN.1 → R||S (32+32 octets)                 */
    /* ------------------------------------------------------------------ */
    private static function der_to_raw_ec_sig( string $der ) : ?string {
        $offset = 0;
        /* SEQUENCE */
        if (ord($der[$offset++]) !== 0x30) return null;
        /* longueur */
        $len = ord($der[$offset++]);
        if ($len & 0x80) { $nb = $len & 0x7f; $offset += $nb; }
        /* INTEGER r */
        if (ord($der[$offset++]) !== 0x02) return null;
        $rlen = ord($der[$offset++]); $r = substr($der,$offset,$rlen); $offset += $rlen;
        /* INTEGER s */
        if (ord($der[$offset++]) !== 0x02) return null;
        $slen = ord($der[$offset++]); $s = substr($der,$offset,$slen);
        /* Strip leading 0x00 (sign byte) et pad à 32 */
        $r = ltrim($r,"\x00"); $r = str_pad($r,32,"\x00",STR_PAD_LEFT);
        $s = ltrim($s,"\x00"); $s = str_pad($s,32,"\x00",STR_PAD_LEFT);
        if (strlen($r)!==32||strlen($s)!==32) return null;
        return $r.$s;
    }

    /* ------------------------------------------------------------------ */
    /* Base64URL                                                             */
    /* ------------------------------------------------------------------ */
    public static function base64url_encode( string $data ) : string {
        return rtrim(strtr(base64_encode($data),'+/','-_'),'=');
    }
    public static function base64url_decode( string $data ) : string {
        return base64_decode(strtr($data,'-_','+/'));
    }
}
