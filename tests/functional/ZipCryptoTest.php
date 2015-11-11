<?php
/**
 * PHPZip
 * <ZipCryptoTest.php description here>
 *
 * @author    A. Grandt <php@grandt.com>
 * @copyright 2015- A. Grandt
 * @license   GNU LGPL 2.1
 */

use PHPZip\Zip\Core\ZipCrypto;

$loader = require '../vendor/autoload.php';

$bi1 = \Brick\Math\BigInteger::of(5);
$bi2 = \Brick\Math\BigInteger::of(2);

echo "bi1: " . $bi1->toInteger() . "\n";
echo "bi2: " . $bi2->toInteger() . "\n";
echo "bi1: " . $bi1->plus(3)->toInteger() . "\n";
echo "bi2: " . $bi1->multipliedBy(3)->toInteger() . "\n";
echo "bi1: " . $bi1->toInteger() . "\n";
echo "bi2: " . $bi2->toInteger() . "\n";
echo "bi2: " . $bi2->negated()->multipliedBy(10)->toInteger() . "\n";
echo "bi2: " . $bi2->negated()->multipliedBy(10)->toBase(16) . "\n";

$password = "TestPassword";
$byte_array = unpack('C*', $password);

for ($i = 1; $i <= sizeof($byte_array); $i++) {
    echo "[" . $i . "]=>" . $byte_array[$i] . "\n";
}

$zc = new ZipCrypto();


$crc = $zc->getCrcTable();
for ($i = 0; $i < sizeof($crc); $i++) {
    echo $crc[$i] . ", ";
    if ($i % 8 == 7) {
        echo "\n";
    }
}

for ($i = 0; $i < 3; $i++) {
    echo "keys[" . $i . "]=" . $zc->getKeys()[$i] . "\n";
}

$zc->initKeys($password);

for ($i = 0; $i < 3; $i++) {
    echo "keys[" . $i . "]=" . $zc->getKeys()[$i] . "\n";
}

echo "PHP_INT_SIZE: " . PHP_INT_SIZE . "\n";
echo "\nTesting: \n";
$a = 591751230;
$b = 134775813;
echo "a..: " . $a . " -> " . dechex($a) . " -> " . decbin($a) . "\n";
echo "b..: " . $b . " -> " . dechex($b) . " -> " . decbin($b) . "\n";
echo "a*b: " . ZipCrypto::int32mul($a, $b) . " -> " . dechex(ZipCrypto::int32mul($a, $b)) . " -> " . decbin(ZipCrypto::int32mul($a, $b)) . "\n";
echo "\nexpected:\n";
echo "a*b: -1408564938 -> ac0b0136 -> " . decbin(0xac0b0136) . "\n";

echo "a*b: " .  bcmul($a, $b) . " -> " . ZipCrypto::bcDecHex(bcmul($a, $b)) . "\n";
echo "a+1: " .  bcadd(-1408564938, 1) . " -> " . ZipCrypto::bcDecHex(bcadd(-1408564938, 1)) . "\n";
echo "a+1: " .  ZipCrypto::int32add(-1408564938, 1) . " -> " .  dechex(ZipCrypto::int32add(-1408564938, 1)) . "\n";

echo substr("123456789", -8) . "\n";
echo substr("1234567", -8) . "\n";
