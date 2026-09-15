<?php
/**
 * Protected storage for print PDFs (they contain customers' personal data).
 *
 * Files live in a folder with an unguessable name, denied to direct web access
 * (.htaccess, honored by Apache/LiteSpeed), one random subfolder per order.
 * Downloads only go through Altegena_Print_Admin::download() with a capability
 * check. Set ALTEGENA_PRINT_DIR in wp-config.php to store outside the web root.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Altegena_Print_Storage
{
    const SECRET_OPTION = 'altegena_print_storage_secret';
    const ORDER_DIR_META = '_altegena_print_dir';
    const FILE_PATTERN = '/^[A-Za-z0-9._-]+\.pdf$/';

    public static function base_dir()
    {
        if (defined('ALTEGENA_PRINT_DIR') && ALTEGENA_PRINT_DIR) {
            $dir = ALTEGENA_PRINT_DIR;
        } else {
            $secret = get_option(self::SECRET_OPTION);
            if (!is_string($secret) || !preg_match('/^[a-z0-9]{16}$/', $secret)) {
                $secret = strtolower(wp_generate_password(16, false, false));
                update_option(self::SECRET_OPTION, $secret, false);
            }
            $uploads = wp_upload_dir(null, false);
            $dir = $uploads['basedir'] . '/altegena-print-' . $secret;
        }
        return untrailingslashit(apply_filters('altegena_print_storage_dir', $dir));
    }

    /**
     * @return string|WP_Error
     */
    private static function ensure_dir($dir)
    {
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return new WP_Error('storage_unwritable', sprintf('Baskı klasörü oluşturulamadı: %s', $dir));
        }

        $guards = array(
            '.htaccess' => "# Altegena print PDFs are never served directly; downloads go through wp-admin.\n"
                . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n",
            'index.php' => "<?php\n// Silence is golden.\n",
        );
        foreach ($guards as $name => $content) {
            if (!file_exists($dir . '/' . $name)) {
                @file_put_contents($dir . '/' . $name, $content);
            }
        }
        return $dir;
    }

    /**
     * Absolute folder of an order's PDFs; created (with its random name) when $create is true.
     *
     * @return string|WP_Error
     */
    public static function order_dir($order, $create = true)
    {
        $name = (string) $order->get_meta(self::ORDER_DIR_META);
        if (!preg_match('/^\d+-[a-z0-9]{16}$/', $name)) {
            if (!$create) {
                return new WP_Error('storage_missing', 'Bu sipariş için baskı klasörü yok.');
            }
            $name = $order->get_id() . '-' . strtolower(wp_generate_password(16, false, false));
            $order->update_meta_data(self::ORDER_DIR_META, $name);
            $order->save_meta_data();
        }

        $base = self::base_dir();
        if (!$create) {
            return $base . '/' . $name;
        }
        $ensured = self::ensure_dir($base);
        if (is_wp_error($ensured)) {
            return $ensured;
        }
        return self::ensure_dir($base . '/' . $name);
    }

    /**
     * Write a file atomically (temp file + rename).
     *
     * @return string|WP_Error absolute path
     */
    public static function write($order, $filename, $bytes)
    {
        if (!preg_match(self::FILE_PATTERN, $filename)) {
            return new WP_Error('storage_bad_name', 'Geçersiz dosya adı.');
        }
        $dir = self::order_dir($order);
        if (is_wp_error($dir)) {
            return $dir;
        }

        $target = $dir . '/' . $filename;
        $temp = $dir . '/.' . $filename . '.tmp';
        if (@file_put_contents($temp, $bytes) !== strlen($bytes) || !@rename($temp, $target)) {
            @unlink($temp);
            return new WP_Error('storage_write_failed', sprintf('PDF dosyası yazılamadı: %s', $filename));
        }
        return $target;
    }

    /** Absolute path of a stored file, or null when missing or outside the order folder. */
    public static function path($order, $filename)
    {
        if (!is_string($filename) || !preg_match(self::FILE_PATTERN, $filename)) {
            return null;
        }
        $dir = self::order_dir($order, false);
        if (is_wp_error($dir)) {
            return null;
        }
        $real_dir = realpath($dir);
        $real = realpath($dir . '/' . $filename);
        if ($real_dir === false || $real === false || strpos($real, $real_dir . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) {
            return null;
        }
        return $real;
    }

    /**
     * Whether files in the storage folder can be fetched over HTTP (e.g. nginx ignores
     * .htaccess). Probed with a loopback request and cached for 12 hours; a failed
     * probe request counts as not accessible.
     */
    public static function is_publicly_accessible()
    {
        $cached = get_transient('altegena_print_storage_public');
        if ($cached !== false) {
            return $cached === 'yes';
        }

        $public = false;
        $base = self::base_dir();
        $uploads = wp_upload_dir(null, false);
        if (strpos($base, $uploads['basedir'] . '/') === 0 && !is_wp_error(self::ensure_dir($base))) {
            $probe = $base . '/altegena-probe.pdf';
            if (!file_exists($probe)) {
                @file_put_contents($probe, "%PDF-1.4\n% access probe\n");
            }
            $response = wp_remote_get($uploads['baseurl'] . substr($probe, strlen($uploads['basedir'])), array(
                'timeout' => 5,
                'redirection' => 0,
                'sslverify' => false,
            ));
            $public = !is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200;
        }

        set_transient('altegena_print_storage_public', $public ? 'yes' : 'no', 12 * HOUR_IN_SECONDS);
        return $public;
    }

    public static function delete_file($order, $filename)
    {
        $path = self::path($order, $filename);
        if ($path) {
            @unlink($path);
        }
    }

    /** Remove an order's PDF folder (only the files this class creates). */
    public static function delete_order($order)
    {
        $dir = self::order_dir($order, false);
        if (is_wp_error($dir) || !is_dir($dir)) {
            return;
        }
        foreach ((array) glob($dir . '/*.pdf') as $file) {
            @unlink($file);
        }
        foreach ((array) glob($dir . '/.*.pdf.tmp') as $file) {
            @unlink($file);
        }
        @unlink($dir . '/.htaccess');
        @unlink($dir . '/index.php');
        @rmdir($dir);
    }
}
