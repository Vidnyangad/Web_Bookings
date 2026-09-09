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
            $barcode_data = $generator->getBarcode($pnr, $generator::TYPE_CODE_128);
            file_put_contents($filepath, $barcode_data);
        }

        return $filepath;
    }
}
