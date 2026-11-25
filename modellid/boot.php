<?php
/**
 * Modellide andmebaas broneerimissüsteemi osana.
 *
 * Kood pärineb eraldi pluginast "Sasipea Modellid" ja kasutab SAMU andmeid: postitüüp sam_modell,
 * kategooriad sam_*, väljad _sam_*, seaded sam_settings ja kaitstud piltide kaust uploads/sam-private.
 * Seepärast ei ole andmeid vaja kuhugi üle viia: kõik varem registreeritud modellid ja pildid on kohe olemas.
 * Fail laetakse ainult siis, kui vana plugin ei ole sisse lülitatud (vt SB\ModellidModule).
 */

defined( 'ABSPATH' ) || exit;

define( 'SAM_VERSION', '2.0.0' );
define( 'SAM_EMBEDDED', true );
define( 'SAM_DIR', SB_DIR . 'modellid/' );
define( 'SAM_URL', SB_URL . 'modellid/' );
define( 'SAM_CAP', 'sam_manage_models' );
define( 'SAM_CPT', 'sam_modell' );

require_once SAM_DIR . 'includes/data.php';
require_once SAM_DIR . 'includes/photos.php';
require_once SAM_DIR . 'includes/install.php';
require_once SAM_DIR . 'includes/security.php';
require_once SAM_DIR . 'includes/form.php';
require_once SAM_DIR . 'includes/practicum.php';
require_once SAM_DIR . 'includes/dashboard.php';
require_once SAM_DIR . 'includes/admin.php';
