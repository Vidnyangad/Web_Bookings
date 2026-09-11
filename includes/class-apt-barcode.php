<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class APT_Barcode {
    public static function generate_barcode_file( $pnr ) {
        if ( empty($pnr) ) return false;

        if ( ! class_exists( 'Picqer\Barcode\BarcodeGeneratorPNG' ) ) {
            require_once APT_PLUGIN_DIR . 'vendor/autoload.php';
        }

        $upload_dir = wp_upload_dir();
        $barcode_dir = $upload_dir['basedir'] . '/apt_barcodes';

        if ( !file_exists($barcode_dir) ) {
            wp_mkdir_p($barcode_dir);
            file_put_contents($barcode_dir . '/.htaccess', "deny from all\n");
            file_put_contents($barcode_dir . '/index.php', "<?php\n// Silence is golden.\n");
        }

        $filepath = $barcode_dir . '/barcode_' . $pnr . '.png';

        if ( !file_exists($filepath) ) {
            $generator = new Picqer\Barcode\BarcodeGeneratorPNG();
            // Generate a larger barcode for better scannability (width factor 3, height 60)
            $barcode_data = $generator->getBarcode($pnr, $generator::TYPE_CODE_128, 3, 60);

            // Use GD to add a white background and padding
            $im = @imagecreatefromstring($barcode_data);
            if ($im !== false) {
                $width = imagesx($im);
                $height = imagesy($im);
                $padding = 20;

                $new_width = $width + ($padding * 2);
                $new_height = $height + ($padding * 2);

                $new_im = imagecreatetruecolor($new_width, $new_height);
                $white = imagecolorallocate($new_im, 255, 255, 255);
                imagefill($new_im, 0, 0, $white);

                // Copy the original barcode onto the white canvas
                imagecopy($new_im, $im, $padding, $padding, 0, 0, $width, $height);

                // Save the new image
                imagepng($new_im, $filepath);

                imagedestroy($im);
                imagedestroy($new_im);
            } else {
                // Fallback if GD fails for some reason
                file_put_contents($filepath, $barcode_data);
            }
        }

        return $filepath;
    }
}
