<?php
/**
 * PHPZip
 * <ZipCrypto.php description here>
 *
 * @author    A. Grandt <php@grandt.com>
 * @copyright 2015- A. Grandt
 * @license   GNU LGPL 2.1
 */

namespace PHPZip\Zip\Core;


use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use com\grandt\BinStringStatic;

class ZipCrypto {
    private $keys = array(0, 0, 0);

    /** @var $keys0 BigInteger */
    private $keys0 = null;
    /** @var $keys1 BigInteger */
    private $keys1 = null;
    /** @var $keys2 BigInteger */
    private $keys2 = null;


    private $crcTable = null;

    /**
     * ZipCrypto constructor.
     */
    public function __construct() {
        $keys0 = BigInteger::of(0);
        $keys1 = BigInteger::of(0);
        $keys2 = BigInteger::of(0);

        $this->crcTable = $this->initCRCTable();
    }

    private function initCRCTable() {
        $table = array();

        for ($i = 0; $i < 256; $i++) {
            $r = $i;
            for ($j = 0; $j < 8; $j++) {
                if (($r & 1) == 1) {
                    $r = (($r >> 1) & 0x7fffffff) ^ 0xedb88320;
                } else {
                    $r = (($r >> 1) & 0x7fffffff);
                }
            }
            $table[$i] = $r;
        }

        return $table;
    }

    /**
     * @param $bi
     *
     * @return int
     */
    public static function bi2Int32($bi) {
        return self::hex2Int(substr($bi->toBase(16), -8));
    }

    /**
     * @param $result
     *
     * @return int
     */
    public static function hex2Int($result) {
        return BigInteger::parse($result, 16)->toInteger();
    }

    public function initKeys($password) {
        $byte_array = unpack('C*', $password);

        $this->keys0 = BigInteger::of(305419896);
        $this->keys1 = BigInteger::of(591751049);
        $this->keys2 = BigInteger::of(878082192);

        for ($i = 1; $i <= sizeof($byte_array); $i++) {
            $this->updateKeys($byte_array[$i] & 0xff);
        }
    }

    public function updateKeys($charAt) {
        echo "updateKeys($charAt) start: " . $this->keys0 . ", " . $this->keys1 . ", " . $this->keys2 . "\n";

        $this->keys0 = ($this->crc32($this->keys0, $charAt));
        $keys0hex = $this->keys0->toBase(16);

        $this->keys1 =
            $this->keys1
                ->plus(hexdec(substr($keys0hex, -2)))
                ->multipliedBy(134775813)
                ->plus(1);

        $this->keys2 = ($this->crc32($this->keys2, (int)(($this->keys1 >> 24)))) & 0xFFFFFFFF;

        echo "updateKeys($charAt) done.: " . $this->keys0 . ", " . $this->keys1 . ", " . $this->keys2 . "\n";
    }

    /**
     * @param BigInteger $oldCrc
     * @param integer $charAt
     *
     * @return int
     */
    private function crc32($oldCrc, $charAt) {
        echo "crc32($oldCrc, $charAt)\n";

        return ($oldCrc->dividedBy(256, RoundingMode::DOWN)->toInteger() ^ $this->crcTable[($oldCrc->toInteger() ^ $charAt) & 0xff]);
    }

    public function decryptByte() {
        $temp = $this->keys2 | 2;

        return (($temp * ($temp ^ 1)) >> 8) & 0x00ffffff;
    }


    public static function int32mul($a, $b) {
        $bi = BigInteger::of($a)->multipliedBy($b);

        return self::bi2Int32($bi);
        // return self::toInt32(self::bcDecHex(bcmul($a, $b)));
    }

    public static function int32add($a, $b) {
        $bi = BigInteger::of($a)->plus($b);

        return self::bi2Int32($bi);
        // return self::toInt32((int)(bcadd($a, $b)));
    }

    public static function bcDecHex2($dec) {
        $last = bcmod($dec, 16);
        $remain = bcdiv(bcsub($dec, $last), 16);

        if ($remain == 0) {
            return dechex($last);
        } else {
            return self::bcDecHex2($remain) . dechex($last);
        }
    }

    public static function bcDecHex($dec) {
        $base = 16;
        bcscale(0);
        $value = "";
        $digits = unpack('C*', "0123456789abcdef");
        while ($dec > $base - 1) {
            $rest = bcmod($dec, $base);
            $dec = bcdiv($dec, $base);
            $value = $digits[$rest] . $value;
        }
        $value = $digits[intval($dec)] . $value;

        return (string)$value;
    }

    public static function bcDecHex3($dec) {
        return base_convert($dec, 10, 16);
    }

    public static function bcHexDec($hex) {
        $dec = 0;
        $len = strlen($hex);
        for ($i = 1; $i <= $len; $i++) {
            $dec = bcadd($dec, bcmul(strval(hexdec($hex[$i - 1])), bcpow('16', strval($len - $i))));
        }

        return $dec;
    }

    public static function toInt32($value) {
        if (is_string($value)) {
            $value = hexdec(substr($value, -8));
        }
        //return unpack('l', pack('l', $value))[1];
        $value = ($value & 0xFFFFFFFF);

        if ($value & 0x80000000) {
            $value = -((~$value & 0xFFFFFFFF) + 1);
        }

        return $value;
    }

    public function getKeys() {
        return $this->keys;
    }

    public function getCrcTable() {
        return $this->crcTable;
    }
}
