<?php

namespace App\Helpers;

use App\Facility;

class ULSHelper
{
    public static function generateToken($facility = "") {
        if (!isset($_SERVER['REMOTE_ADDR']) || $_SERVER['REMOTE_ADDR'] == "") {
            header('HTTP/1.1 500 Internal Server Error');
            echo 'REMOTE_ADDR not defined.';
            exit;
        }


        $fac = Facility::find($facility);
        if (!$fac->uls_secret) {
            header('HTTP/1.1 500 Internal Server Error');
            echo 'Secret not available for defined facility.';
            exit;
        }

        $tokenRaw = $fac->uls_secret . "-" . $_SERVER['REMOTE_ADDR'];
        $token = sha1($tokenRaw);
        return $token;
    }
}