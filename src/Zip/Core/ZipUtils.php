<?php
namespace PHPZip\Zip\Core;

class ZipUtils {
    // Unix file types
    const S_IFIFO  = 0010000; // named pipe (fifo)
    const S_IFCHR  = 0020000; // character special
    const S_IFDIR  = 0040000; // directory
    const S_IFBLK  = 0060000; // block special
    const S_IFREG  = 0100000; // regular
    const S_IFLNK  = 0120000; // symbolic link
    const S_IFSOCK = 0140000; // socket

    // setuid/setgid/sticky bits, the same as for chmod:
    const S_ISUID  = 0004000; // set user id on execution
    const S_ISGID  = 0002000; // set group id on execution
    const S_ISTXT  = 0001000; // sticky bit

    // And of course, the other 12 bits are for the permissions, the same as for chmod:
    // When adding these up, you can also just write the permissions as a single octal number
    // ie. 0755. The leading 0 specifies octal notation.
    const S_IRWXU  = 0000700; // RWX mask for owner
    const S_IRUSR  = 0000400; // R for owner
    const S_IWUSR  = 0000200; // W for owner
    const S_IXUSR  = 0000100; // X for owner
    const S_IRWXG  = 0000070; // RWX mask for group
    const S_IRGRP  = 0000040; // R for group
    const S_IWGRP  = 0000020; // W for group
    const S_IXGRP  = 0000010; // X for group
    const S_IRWXO  = 0000007; // RWX mask for other
    const S_IROTH  = 0000004; // R for other
    const S_IWOTH  = 0000002; // W for other
    const S_IXOTH  = 0000001; // X for other
    const S_ISVTX  = 0001000; // save swapped text even after use

    // File type, sticky and permissions are added up, and shifted 16 bits left BEFORE adding the DOS flags.
    // DOS file type flags, we really only use the S_DOS_D flag.
    const S_DOS_A  = 0000040; // DOS flag for Archive
    const S_DOS_D  = 0000020; // DOS flag for Directory
    const S_DOS_V  = 0000010; // DOS flag for Volume
    const S_DOS_S  = 0000004; // DOS flag for System
    const S_DOS_H  = 0000002; // DOS flag for Hidden
    const S_DOS_R  = 0000001; // DOS flag for Read Only

    /**
     * Calculate the 2 byte dos time used in the zip entries.
     *
     * @author A. Grandt <php@grandt.com>
     *
     * @param int       $timestamp
     *
     * @return string   2-byte encoded DOS Date
     */
    public static function getDosTime($timestamp = 0) {
        $timestamp = (int)$timestamp;
        $oldTZ = @date_default_timezone_get();
        date_default_timezone_set('UTC');

        $date = ($timestamp == 0 ? getdate() : getdate($timestamp));
        date_default_timezone_set($oldTZ);

        if ($date["year"] >= 1980) { // Dos dates start on 1 Jan 1980
            return pack("V", (($date["mday"] + ($date["mon"] << 5) + (($date["year"] - 1980) << 9)) << 16) |
                (($date["seconds"] >> 1) + ($date["minutes"] << 5) + ($date["hours"] << 11)));
        }
        return "\x00\x00\x00\x00";
    }

    /**
     * Join $file to $dir path, and clean up any excess slashes.
     *
     * @author A. Grandt <php@grandt.com>
     * @author Greg Kappatos
     *
     * @param string $dir
     * @param string $file
     *
     * @return string Joined path, with the correct forward slash dir separator.
     */
    public static function pathJoin($dir, $file) {
        return self::getRelativePath(
            $dir . (empty($dir) || empty($file) ? '' : DIRECTORY_SEPARATOR) . $file
        );
    }

    /**
     * Clean up a path, removing any unnecessary elements such as /./, // or redundant ../ segments.
     * If the path starts with a "/", it is deemed an absolute path and any /../ in the beginning is stripped off.
     * The returned path will not end in a "/".
     *
     * Sometimes, when a path is generated from multiple fragments,
     *  you can get something like "../data/html/../images/image.jpeg"
     * This will normalize that example path to "../data/images/image.jpeg"
     *
     * @author A. Grandt <php@grandt.com>
     *
     * @param string $path The path to clean up
     *
     * @return string the clean path
     */
    public static function getRelativePath($path) {
        $path = preg_replace("#/+\.?/+#", "/", str_replace("\\", "/", $path));
        $dirs = explode("/", rtrim(preg_replace('#^(?:\./)+#', '', $path), '/'));

        $offset = 0;
        $sub = 0;
        $subOffset = 0;
        $root = "";

        if (empty($dirs[0])) {
            $root = "/";
            $dirs = array_splice($dirs, 1);
        } else if (preg_match("#[A-Za-z]:#", $dirs[0])) {
            $root = strtoupper($dirs[0]) . "/";
            $dirs = array_splice($dirs, 1);
        }

        $newDirs = array();
        foreach ($dirs as $dir) {
            if ($dir !== "..") {
                $subOffset--;
                $newDirs[++$offset] = $dir;
            } else {
                $subOffset++;
                if (--$offset < 0) {
                    $offset = 0;
                    if ($subOffset > $sub) {
                        $sub++;
                    }
                }
            }
        }

        if (empty($root)) {
            $root = str_repeat("../", $sub);
        }

        return $root . implode("/", array_slice($newDirs, 0, $offset));
    }

    /**
     * Create the file permissions for a file or directory, for use in the extFileAttr parameters.
     *
     * @author A. Grandt <php@grandt.com>
     *
     * @param int   $owner Unix permissions for owner (octal from 00 to 07)
     * @param int   $group Unix permissions for group (octal from 00 to 07)
     * @param int   $other Unix permissions for others (octal from 00 to 07)
     * @param bool  $isFile
     *
     * @return string EXTERNAL_REF field.
     */
    public static function generateExtAttr($owner = 07, $group = 05, $other = 05, $isFile = true) {
        $fp = $isFile ? self::S_IFREG : self::S_IFDIR;
        $fp |= (($owner & 07) << 6) | (($group & 07) << 3) | ($other & 07);

        return ($fp << 16) | ($isFile ? self::S_DOS_A : self::S_DOS_D);
    }

    /**
     * Get the file permissions for a file or directory, for use in the extFileAttr parameters.
     *
     * @author A. Grandt <php@grandt.com>
     *
     * @param string $filename
     *
     * @return string|bool external ref field, or false if the file is not found.
     */
    public static function getFileExtAttr($filename) {
        if (file_exists($filename)) {
            $fp = fileperms($filename) << 16;
            return $fp | (is_dir($filename) ? self::S_DOS_D : self::S_DOS_A);
        }

        return false;
    }

    public static function testBit($data, $bit) {
        $bv = 1 << $bit;
        return ($data & $bv) == $bv;
    }

    public static function setBit(&$data, $bit, $value = true) {
        if ($value) {
            $data |= (1 << $bit);
        } else {
            self::clrBit($data, $bit);
        }

    }

    public static function clrBit(&$data, $bit) {
        $data &= ~(1 << $bit);
    }

    /**
     * Initialize the vars that'll allow us to override mbstring.func_overload, if needed.
     *
     * @author A. Grandt <php@grandt.com>
     *
     * @return bool true if mbstring.func_overload is enabled.
     */
    public static function isMBStringOveridden() {
        static $has_mb_overload = null;
        if ($has_mb_overload ===  null) {
            $has_mbstring = extension_loaded('mbstring');
            $has_mb_shadow = (int) ini_get('mbstring.func_overload');
            $has_mb_overload = $has_mbstring && ($has_mb_shadow & 2);
        }
        return $has_mb_overload;
    }

    /**
     * Wrapper of strlen to escapt bmstring.func_overload.
     *
     * @author A. Grandt <php@grandt.com>
     *
     * @param string $string
     *
     * @return int byte length of the string parsed.
     */
    public static function bin_strlen($string) {
        if (self::isMBStringOveridden()) {
            return mb_strlen($string,'latin1');
        } else {
            return strlen($string);
        }
    }
}